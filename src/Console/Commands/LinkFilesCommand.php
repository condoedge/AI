<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Contracts\ChunkLinkStoreInterface;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\FileOwnerResolverInterface;
use Condoedge\Ai\Services\FileProcessor;
use Condoedge\Ai\Console\Commands\Concerns\SkippedWhenAiDisabled;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * ai:files:link — write the owner of every UPLOADED file onto its stored chunks.
 *
 * A chunk stored by the file processor carries the record its file is filed on
 * (`owner`, `ancestors`, `dedupe` — {@see \Condoedge\Ai\Support\FileLink}). This
 * command sets the same keys on chunks stored BEFORE they existed, and can be
 * run again at any time (after a bulk import that moved files between records):
 *
 *  - payload only: no vector is read or written, no point added or removed;
 *  - idempotent: a chunk that already carries exactly its keys is not written;
 *  - one owner lookup per file, never per chunk;
 *  - the uploads collection only — it refuses the project collection, and a
 *    project file found among the uploads is left alone.
 *
 * A file whose row no longer exists has nothing to resolve an owner from: its
 * chunks are keyed as a file of no record (the access check still hides them
 * from everyone) and it is reported.
 */
class LinkFilesCommand extends Command
{
    use SkippedWhenAiDisabled;

    protected $signature = 'ai:files:link
                            {--collection= : The uploads collection (default: ai.file_processing.collection)}
                            {--file=* : Link only these file ids}
                            {--dry-run : Report what would change, write nothing}
                            {--no-index : Do not create the payload indexes of the link keys}';

    protected $description = 'Link the stored chunks of uploaded files to the record each file is filed on (payload only)';

    /** @var \Closure(int|string): ?object|null */
    private ?\Closure $fileLoader = null;

    public function __construct(
        private readonly ChunkStoreInterface $chunkStore,
        private readonly FileOwnerResolverInterface $ownerResolver
    ) {
        parent::__construct();
    }

    /**
     * Replace how a File row is read by id (default: the configured file model,
     * unscoped — a soft-deleted file is still a file).
     *
     * @param \Closure(int|string): ?object $loader
     */
    public function useFileLoader(\Closure $loader): void
    {
        $this->fileLoader = $loader;
    }

    public function handle(): int
    {
        $collection = trim((string) ($this->option('collection') ?: config('ai.file_processing.collection', 'file_chunks')));
        $dryRun = (bool) $this->option('dry-run');

        if ($collection === '' || FileProcessor::isProjectFile(null, $collection)) {
            $this->error("\"{$collection}\" is the collection of PROJECT files: they are global and are never linked to a record.");

            return self::FAILURE;
        }
        if (!$this->chunkStore instanceof ChunkLinkStoreInterface) {
            $this->error('The chunk store cannot update stored chunks in place (no ChunkLinkStoreInterface).');

            return self::FAILURE;
        }

        $fileIds = $this->requestedFileIds();
        if ($fileIds === []) {
            $fileIds = $this->chunkStore->storedFileIds($collection);
        }

        $report = ['files' => 0, 'linked' => 0, 'ownerless' => 0, 'missing' => 0, 'project' => 0, 'points' => 0, 'changed' => 0];
        $missing = [];
        $owners = [];

        foreach ($fileIds as $fileId) {
            if (FileProcessor::isProjectFile($fileId, null)) {
                $report['project']++;
                continue;
            }

            $file = $this->loadFile($fileId);
            $owner = ['owner' => null, 'ancestors' => []];
            if ($file === null) {
                $report['missing']++;
                $missing[] = $fileId;
            } else {
                $owner = $this->ownerResolver->ownerOf($file);
                $owner = [
                    'owner' => is_string($owner['owner'] ?? null) && $owner['owner'] !== '' ? $owner['owner'] : null,
                    'ancestors' => array_values(array_filter((array) ($owner['ancestors'] ?? []), 'is_string')),
                ];
                $report[$owner['owner'] !== null ? 'linked' : 'ownerless']++;
            }

            $result = $this->chunkStore->linkFileChunks($fileId, $owner, $collection, $dryRun);
            $report['files']++;
            $report['points'] += $result['points'];
            $report['changed'] += $result['changed'];
            $owners[$owner['owner'] ?? '(none)'] = ($owners[$owner['owner'] ?? '(none)'] ?? 0) + 1;
        }

        if (!$dryRun && !$this->option('no-index') && $report['files'] > 0) {
            $this->chunkStore->ensureLinkIndexes($collection);
        }

        $this->table(['', $dryRun ? "{$collection} (dry run)" : $collection], [
            ['Files', $report['files']],
            ['Linked to a record', $report['linked']],
            ['Without an owner', $report['ownerless']],
            ['File row no longer exists', $report['missing']],
            ['Project files skipped', $report['project']],
            ['Points', $report['points']],
            [$dryRun ? 'Points that would be written' : 'Points written', $report['changed']],
        ]);

        if ($this->output->isVerbose()) {
            ksort($owners);
            foreach ($owners as $owner => $count) {
                $this->line(sprintf('  %-24s %d file(s)', $owner, $count));
            }
        }
        if ($missing !== []) {
            $this->warn('No File row for: ' . implode(', ', array_map('strval', array_slice($missing, 0, 50)))
                . (count($missing) > 50 ? ' …' : ''));
        }

        return self::SUCCESS;
    }

    /** @return list<int|string> */
    private function requestedFileIds(): array
    {
        $ids = [];
        foreach ((array) $this->option('file') as $value) {
            foreach (explode(',', (string) $value) as $id) {
                $id = trim($id);
                if ($id !== '') {
                    $ids[] = ctype_digit($id) ? (int) $id : $id;
                }
            }
        }

        return array_values(array_unique($ids, SORT_REGULAR));
    }

    private function loadFile(int|string $fileId): ?object
    {
        if ($this->fileLoader !== null) {
            return ($this->fileLoader)($fileId);
        }

        $class = config('ai.file_context.file_model');
        if (!is_string($class) || !class_exists($class)) {
            $class = class_exists(\Condoedge\Utils\Facades\FileModel::class) ? \Condoedge\Utils\Facades\FileModel::getClass() : null;
        }
        if (!is_string($class) || !is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var Model $model */
        $model = new $class();
        $row = $model->newQueryWithoutScopes()->toBase()->where($model->getKeyName(), $fileId)->first();

        return $row === null ? null : (object) (array) $row;
    }
}
