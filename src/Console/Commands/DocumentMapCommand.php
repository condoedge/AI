<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Services\Files\DocumentMapBuilder;
use Condoedge\Ai\Support\DocumentMap;
use Illuminate\Console\Command;

/**
 * DocumentMapCommand
 *
 * Builds the document map (a verified gist per passage, and the scope of the
 * document's parts — see DocumentMapBuilder) outside the ingest.
 *
 * This command is how the map gets built when:
 * - the queue is `sync` (FileProcessor then dispatches nothing: about 2.4 s per
 *   passage would block the upload);
 * - documents were ingested before the map existed (--backfill);
 * - a queued build left something: passages never attempted, a scope verdict
 *   still owed (it resumes: what was attempted for the current text is skipped).
 *
 * WHAT A RUN PAYS FOR. A passage is attempted once per text: one that carries a
 * gist, or the record of a failed attempt, is skipped. `--retry-failed` attempts
 * the failed ones again; `--force` rebuilds everything; `--rescope` judges the
 * parts of a divided document again without touching a gist.
 *
 * WHICH DOCUMENTS. The host's own documents (the physical collection). Files
 * uploaded by users are mapped only when the host switched that on
 * (ai.file_context.document_map.uploads, AI_DOCUMENT_MAP_UPLOADS): a map makes a
 * document's text weigh on what an answer is built on.
 *
 * EXIT CODE. Failure when a file that was built reported an error, or ended
 * without a usable map or with its scope verdict still owed — and when a file
 * that was skipped has no usable map. A usable map with a few passages left
 * without a gist is a success; the line says how many.
 *
 * Usage:
 *   php artisan ai:documents:map --backfill                 # every file with something left to do
 *   php artisan ai:documents:map --backfill --dry-run       # list files and their state, no LLM call
 *   php artisan ai:documents:map --backfill --retry-failed  # …and attempt the failed passages again
 *   php artisan ai:documents:map --file=physical:docs/a.md  # one file
 *   php artisan ai:documents:map --file=physical:docs/a.md --force    # rebuild fresh entries too
 *   php artisan ai:documents:map --file=physical:docs/a.md --rescope  # judge its parts again
 *
 * @package Condoedge\Ai\Console\Commands
 */
class DocumentMapCommand extends Command
{
    /**
     * Points read when listing the files of a collection.
     *
     * VectorStoreInterface::scroll() returns ONE page (it takes no offset), so
     * the listing asks for the whole collection at once, up to this many points;
     * files beyond it are reported as not listed rather than silently missed.
     */
    private const SCROLL_CAP = 20000;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:documents:map
                            {--file= : one file id}
                            {--collection= : chunk collection (default: the physical docs collection, and the uploads collection when uploads are switched on)}
                            {--backfill : every file of the collection with something left to do}
                            {--retry-failed : attempt again the passages recorded as failed}
                            {--rescope : judge the parts of divided documents again (no gist is rebuilt)}
                            {--force : rebuild fresh entries too}
                            {--dry-run : list files and their state, no LLM call}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build the document map (passage gists and part scope) of ingested documents';

    /** @var array<string, array{calls: int, input: int, output: int, kinds: array<string, bool>}> usage per model */
    private array $spent = [];

    /** Whether an in-place progress line is on screen and must be cleared before the next line. */
    private bool $progressOpen = false;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $file = $this->option('file');
        $file = is_string($file) && trim($file) !== '' ? trim($file) : null;
        $backfill = (bool) $this->option('backfill');
        $force = (bool) $this->option('force');
        $retryFailed = (bool) $this->option('retry-failed');
        $rescope = (bool) $this->option('rescope');
        $dryRun = (bool) $this->option('dry-run');

        if ($file === null && !$backfill) {
            $this->error('Nothing to do: pass --file=<id> for one file, or --backfill for every file with something left to do.');

            return self::FAILURE;
        }

        $collections = $this->collections();
        if ($collections === null) {
            return self::FAILURE;
        }

        if (!config('ai.file_context.document_map.enabled', true)) {
            $this->warn('The document map is switched off (AI_DOCUMENT_MAP=false): what is built now is not read until it is switched on.');
        }

        $chunkStore = app(ChunkStoreInterface::class);
        $vectorStore = app(VectorStoreInterface::class);

        $targets = $this->targets($chunkStore, $vectorStore, $collections, $file);

        if ($targets === []) {
            $this->warn($file !== null ? "No chunks found for file {$file}." : 'No ingested file found.');

            return self::SUCCESS;
        }

        $todo = static fn (array $target): bool => $force || $rescope
            || $target['pending'] > 0
            || $target['scope_pending']
            || ($retryFailed && $target['failed'] > 0);

        if ($dryRun) {
            $this->warn('[DRY RUN MODE] no LLM call, nothing written');
            foreach ($targets as $target) {
                $this->line(sprintf(
                    '  %s  %s  %s  %s',
                    $target['collection'],
                    $target['file'],
                    $this->counts($target),
                    $this->status($target, $force, $rescope, $retryFailed)
                ));
            }
            $this->comment(count(array_filter($targets, $todo)) . ' of ' . count($targets) . ' file(s) would be built. Run without --dry-run to build.');

            return self::SUCCESS;
        }

        $builder = null;
        $started = microtime(true);
        $built = 0;
        $failed = 0;
        $unusable = 0;

        foreach ($targets as $target) {
            $label = (string) $target['file'];

            if (!$todo($target)) {
                $this->line("  {$label}  " . $this->status($target, false, false, false) . ' (' . $this->counts($target) . '), skipped');
                if (!$target['usable']) {
                    $unusable++;
                }
                continue;
            }

            $builder ??= app(DocumentMapBuilder::class);
            $report = $builder->build($target['file'], $target['collection'], [
                'force' => $force,
                'retry_failed' => $retryFailed,
                'rescope' => $rescope,
                'progress' => fn (int $done, int $total) => $this->progress($label, $done, $total),
            ]);

            $this->finishProgress();
            $built++;

            if (!empty($report['busy'])) {
                $this->warn("  {$label}  another build of this file is running (a queue worker?): skipped, run again later");
                $failed++;
                continue;
            }

            $this->line($this->fileLine($label, $report));
            foreach (array_slice($report['errors'], 0, 5) as $error) {
                $this->warn("      {$error}");
            }
            if (count($report['errors']) > 5) {
                $this->warn('      … ' . (count($report['errors']) - 5) . ' more');
            }
            foreach ($report['scope_verdicts'] as $part => $verdict) {
                if ($verdict['scope'] === 'out') {
                    $this->line("      out of scope: {$part} ({$verdict['chunks']} chunks) — {$verdict['why']}");
                }
            }

            $this->addSpent($report['usage']);
            if (!$report['usable']) {
                $unusable++;
            }
            if ($report['errors'] !== [] || !$report['usable'] || $report['scope_pending']) {
                $failed++;
            }
        }

        $this->newLine();
        $this->summary($built, $failed, $unusable, microtime(true) - $started);

        return $failed > 0 || $unusable > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The files to look at, with the state of their map.
     *
     * @param list<string> $collections
     * @return list<array{collection: string, file: int|string, chunks: int, mapped: int, failed: int, pending: int, usable: bool, scope_pending: bool}>
     */
    private function targets(ChunkStoreInterface $chunkStore, VectorStoreInterface $vectorStore, array $collections, ?string $file): array
    {
        $targets = [];
        $application = config('ai.project.description', '');
        $application = is_string($application) ? $application : '';

        foreach ($collections as $collection) {
            // The chunk store creates a collection it is asked about: a map
            // command must not leave empty collections behind.
            if (!$this->collectionExists($vectorStore, $collection)) {
                $this->line("  {$collection}: no such collection, skipped");
                continue;
            }

            $ids = $file !== null ? $this->fileIdForms($file) : $this->fileIds($vectorStore, $collection);
            $found = false;

            foreach ($ids as $id) {
                if ($file !== null && $found) {
                    break; // the other form of the same id
                }

                try {
                    $chunks = $chunkStore->getFileChunks($id, $collection);
                } catch (\Throwable $e) {
                    $this->warn("  {$collection}: could not read file {$id}: {$e->getMessage()}");
                    continue;
                }
                if ($chunks === []) {
                    continue;
                }
                $found = true;

                $state = DocumentMap::state($chunks);
                $targets[] = [
                    'collection' => $collection,
                    'file' => $id,
                    'chunks' => $state['content'],
                    'mapped' => $state['mapped'],
                    'failed' => $state['failed'],
                    'pending' => $state['pending'],
                    'usable' => DocumentMap::isUsable($chunks),
                    'scope_pending' => $state['pending'] === 0 && DocumentMap::scopePending($chunks, $application),
                ];
            }
        }

        return $targets;
    }

    /** `12 chunks, 11 mapped, 1 failed` */
    private function counts(array $target): string
    {
        return sprintf('%d chunks, %d mapped', $target['chunks'], $target['mapped'])
            . ($target['failed'] > 0 ? ", {$target['failed']} failed" : '');
    }

    /** What a run would do with the file, or why it leaves it. */
    private function status(array $target, bool $force, bool $rescope, bool $retryFailed): string
    {
        if ($force) {
            return 'to rebuild (--force)';
        }
        if ($target['pending'] > 0) {
            return "to build ({$target['pending']} pending)";
        }
        if ($retryFailed && $target['failed'] > 0) {
            return "to retry ({$target['failed']} failed)";
        }
        if ($target['scope_pending']) {
            return 'scope pending';
        }
        if ($rescope) {
            return 'to judge again (--rescope)';
        }
        if (!$target['usable']) {
            return "not usable: {$target['failed']} of {$target['chunks']} passages without a gist (--retry-failed)";
        }

        return 'complete' . ($target['failed'] > 0 ? ", {$target['failed']} without a gist (--retry-failed)" : '');
    }

    /**
     * The collections to work on: the one asked for, or those whose documents are
     * mapped — the host's own (physical docs), and the uploaded files when the
     * host switched that on. Null (after an error line) when the one asked for is
     * the uploads collection while uploads are switched off.
     *
     * @return list<string>|null
     */
    private function collections(): ?array
    {
        $uploads = config('ai.file_processing.collection', 'file_chunks');
        $uploads = is_string($uploads) && trim($uploads) !== '' ? trim($uploads) : null;
        $uploadsOn = (bool) config('ai.file_context.document_map.uploads', false);

        $asked = $this->option('collection');
        if (is_string($asked) && trim($asked) !== '') {
            if (trim($asked) === $uploads && !$uploadsOn) {
                $this->error("Mapping uploaded files ({$uploads}) is switched off. Set AI_DOCUMENT_MAP_UPLOADS=true (ai.file_context.document_map.uploads) to map them.");

                return null;
            }

            return [trim($asked)];
        }

        $collections = [config('ai.file_context.physical_collection', 'documentation_chunks')];
        if ($uploadsOn) {
            $collections[] = $uploads;
        }

        return array_values(array_unique(array_filter(
            $collections,
            static fn ($name): bool => is_string($name) && trim($name) !== ''
        )));
    }

    private function collectionExists(VectorStoreInterface $vectorStore, string $collection): bool
    {
        try {
            return $vectorStore->collectionExists($collection);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The distinct file ids of a collection, in the order the store returns them.
     * Only `file_id` is read from each point.
     *
     * @return list<int|string>
     */
    private function fileIds(VectorStoreInterface $vectorStore, string $collection): array
    {
        try {
            $total = $vectorStore->count($collection);
            if ($total <= 0) {
                return [];
            }
            if ($total > self::SCROLL_CAP) {
                $this->warn("  {$collection}: {$total} points, only the first " . self::SCROLL_CAP . ' are listed (use --file for the others).');
            }
            $points = $vectorStore->scroll($collection, [], min($total, self::SCROLL_CAP), true);
        } catch (\Throwable $e) {
            $this->warn("  {$collection}: could not list files: {$e->getMessage()}");

            return [];
        }

        $ids = [];
        foreach ($points as $point) {
            $id = is_array($point) ? ($point['payload']['file_id'] ?? null) : null;
            if (is_int($id) || (is_string($id) && $id !== '')) {
                $ids[(string) $id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * The forms a file id given on the command line can be stored under: the
     * vector store matches integers and strings as distinct values, and the ids
     * of model-backed files are integers.
     *
     * @return list<int|string>
     */
    private function fileIdForms(string $file): array
    {
        return ctype_digit($file) ? [(int) $file, $file] : [$file];
    }

    /**
     * `<file> 37/128 gists…` — rewritten in place on a terminal, every 25th
     * passage in a log.
     */
    private function progress(string $label, int $done, int $total): void
    {
        $line = "  {$label} {$done}/{$total} gists…";

        if ($this->output->isDecorated()) {
            $this->output->write("\r\033[2K" . $line);
            $this->progressOpen = true;
        } elseif ($done % 25 === 0 && $done < $total) {
            $this->line($line);
        }
    }

    private function finishProgress(): void
    {
        if ($this->progressOpen) {
            $this->output->write("\r\033[2K");
            $this->progressOpen = false;
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private function fileLine(string $label, array $report): string
    {
        $scope = 'no scope';
        if (is_array($report['scope'])) {
            $counts = array_count_values($report['scope']);
            $scope = 'scope: in ' . ($counts['in'] ?? 0) . ', out ' . ($counts['out'] ?? 0);
        } elseif (is_string($report['scope_note'] ?? null)) {
            $scope .= " ({$report['scope_note']})";
        }
        if ($report['scope_pending']) {
            $scope .= ' — verdict still owed, run again';
        }

        $without = $report['chunks'] - $report['mapped'] - $report['pending'];

        // Taken over from an identical text mapped before (no call): shown only when it happened.
        $reused = (int) ($report['gists_reused'] ?? 0) + (int) ($report['failures_reused'] ?? 0);

        return sprintf(
            '  %s %d/%d gists — built %d, kept %d,%s rejected %d, failed %d · %s · %.1f s',
            $label,
            $report['mapped'],
            $report['chunks'],
            $report['gists_built'],
            $report['gists_fresh'],
            $reused > 0 ? " reused {$reused} (same text)," : '',
            $report['gists_rejected'],
            $report['gists_failed'],
            $scope,
            $report['ms'] / 1000
        )
            . ($report['pending'] > 0 ? " · {$report['pending']} passages left, run again" : '')
            . (!$report['usable'] && $report['pending'] === 0 && $report['chunks'] > 0
                ? " · NOT USABLE: {$without} of {$report['chunks']} passages without a gist (--retry-failed)"
                : '');
    }

    /**
     * @param array<string, array{calls: int, input: int, output: int, model: string}> $usage per kind of call
     */
    private function addSpent(array $usage): void
    {
        foreach ($usage as $kind => $row) {
            if (($row['calls'] ?? 0) === 0) {
                continue;
            }
            $model = ($row['model'] ?? '') !== '' ? $row['model'] : 'unknown model';
            $this->spent[$model] ??= ['calls' => 0, 'input' => 0, 'output' => 0, 'kinds' => []];
            $this->spent[$model]['calls'] += $row['calls'];
            $this->spent[$model]['input'] += $row['input'];
            $this->spent[$model]['output'] += $row['output'];
            $this->spent[$model]['kinds'][$kind] = true;
        }
    }

    /**
     * Calls and tokens per model. Tokens only: the package holds no price table,
     * and a guessed price would be read as a bill.
     */
    private function summary(int $built, int $failed, int $unusable, float $seconds): void
    {
        $this->info(sprintf('Document map: %d file(s) built in %.1f s', $built, $seconds));

        foreach ($this->spent as $model => $row) {
            $this->line(sprintf(
                '  %s (%s): %s calls, %s input + %s output tokens',
                $model,
                implode(', ', array_keys($row['kinds'])),
                number_format($row['calls']),
                number_format($row['input']),
                number_format($row['output'])
            ));
        }
        if ($this->spent !== []) {
            $this->comment('  Cost: tokens only (the package has no price table).');
        }

        if ($failed > 0) {
            $this->warn("  {$failed} file(s) are not done; run again to complete them (what was attempted is skipped).");
        }
        if ($unusable > 0) {
            $this->warn("  {$unusable} file(s) have no usable map (too many passages without a gist): they keep the plain search. --retry-failed attempts those passages again.");
        }
    }
}
