<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Services\BatchDataIngestionService;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Services\Files\PhysicalFileIndexer;
use Condoedge\Ai\Console\Commands\Concerns\FlushesGraphSchemaCache;
use Illuminate\Console\Command;

/**
 * IngestEntitiesCommand
 *
 * Bulk ingest all existing Nodeable entities into the graph (Neo4j). Records are not
 * embedded: Qdrant holds the file chunks and the semantic indexes, not the entities.
 * Useful for initial setup or when migrating existing data.
 *
 * Usage:
 *   php artisan ai:ingest                      # Ingest all entities
 *   php artisan ai:ingest --model=Customer     # Ingest specific model
 *   php artisan ai:ingest --fresh              # Clear the graph first (vector collections are not touched)
 *   php artisan ai:ingest --chunk=100          # Batch size
 *
 * @package Condoedge\Ai\Console\Commands
 */
class IngestEntitiesCommand extends Command
{
    use FlushesGraphSchemaCache;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:ingest
                            {--model= : Specific model class to ingest (e.g., App\\Models\\Customer)}
                            {--from= : Skip models until this one (e.g., InboundEmail or App\\Models\\Messaging\\InboundEmail)}
                            {--fresh : Clear the graph before ingesting (vector collections are not touched)}
                            {--chunk=2000 : Batch size for processing}
                            {--min-id= : Only ingest rows with primary key >= this value (manual range restriction)}
                            {--max-id= : Only ingest rows with primary key < this value (manual range restriction)}
                            {--dry-run : Show what would be ingested without actually ingesting}
                            {--docs : Index physical documentation files from configured paths (unchanged files are skipped; --fresh re-embeds all)}
                            {--prune : With --docs: remove the stored documentation files no longer on disk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bulk ingest all Nodeable entities into the graph (Neo4j)';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private BatchDataIngestionService $ingestionService,
        private GraphStoreInterface $graphStore
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        // Bulk ingestion needs more than the typical 512M CLI default (chunks of Eloquent
        // models + embedding vectors + store payloads), and every observed OOM happened on
        // runs where the operator's `-d memory_limit=` flag was missing — so the command
        // raises its own limit instead of depending on the invocation.
        @ini_set('memory_limit', (string) config('ai.ingestion.memory_limit', '2G'));

        // Memory hygiene for a long-running process: anything listening to QueryExecuted
        // (debug toolbars and similar collectors) retains every query + bindings for the
        // whole run — tens of KB per ingested row that gc_collect_cycles() can never
        // reclaim because it is referenced, not garbage. Detach the framework query log
        // and the DB connection's event dispatcher inside THIS process only; Eloquent
        // model events (auto_sync) live on the model dispatcher and are unaffected.
        try {
            \Illuminate\Support\Facades\DB::connection()->disableQueryLog();
            \Illuminate\Support\Facades\DB::connection()->unsetEventDispatcher();
        } catch (\Throwable $e) {
            // No default DB connection (docs-only setups) — nothing to detach.
        }

        if ($this->option('docs')) {
            return $this->handleDocsIngestion();
        }

        $this->info('Bulk Entity Ingestion');
        $this->newLine();

        $models = $this->option('model')
            ? [$this->option('model')]
            : $this->findNodeableModels();

        if (empty($models)) {
            $this->warn('No Nodeable models found.');
            $this->comment('Run "php artisan ai:discover" first to configure entities.');
            return self::FAILURE;
        }

        $this->info('Found ' . count($models) . ' Nodeable model(s)');
        $this->newLine();

        if ($this->option('fresh') && !$this->option('dry-run')) {
            // In a non-interactive run (spawned by ai:setup, CI, cron) there is no TTY to
            // prompt on — confirm() would silently pick the default (no) and "succeed"
            // without wiping OR ingesting. An explicit --fresh flag IS the consent there.
            $proceed = !$this->input->isInteractive()
                || $this->confirm('⚠️  This will DELETE all data from the graph (Neo4j). Continue?', false);

            if (!$proceed) {
                $this->warn('Ingestion cancelled.');
                return self::SUCCESS;
            }

            if (!$this->input->isInteractive()) {
                $this->warn('Non-interactive --fresh: clearing the graph without prompting.');
            }

            $this->clearStores($models);
        }

        $chunkSize = (int) $this->option('chunk');

        if (!$this->option('dry-run')) {
            $this->ensureIdIndexes($this->labelsOf($models));
        }

        // Handle --from flag: skip models until we reach the specified one (inclusive)
        $fromModel = $this->option('from');
        if ($fromModel !== null) {
            $remaining = [];
            $skipping = true;
            foreach ($models as $modelClass) {
                if ($skipping && (class_basename($modelClass) === $fromModel || $modelClass === $fromModel)) {
                    $skipping = false;
                }
                if ($skipping) {
                    $this->comment("Skipping: {$modelClass} (--from)");
                    continue;
                }
                $remaining[] = $modelClass;
            }
            $models = $remaining;
        }

        $totalIngested = 0;
        $totalPartial = 0;
        $totalFailed = 0;
        $errors = [];

        foreach ($models as $modelClass) {
            $result = $this->ingestModel($modelClass, $chunkSize);

            $totalIngested += $result['ingested'];
            $totalPartial += $result['partial'];
            $totalFailed += $result['failed'];

            if (!empty($result['errors'])) {
                $errors[$modelClass] = $result['errors'];
            }
        }

        $this->newLine();
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->info("✓ DRY RUN - Would ingest {$totalIngested} entities");
        } else {
            $this->info("✓ Successfully ingested: {$totalIngested}");

            if ($totalPartial > 0) {
                $this->warn("~ Partially ingested: {$totalPartial}");
            }

            if ($totalFailed > 0) {
                $this->error("✗ Failed: {$totalFailed}");
            }
        }

        if (!empty($errors)) {
            $this->newLine();
            $this->error('Errors encountered:');
            foreach ($errors as $model => $modelErrors) {
                $this->line("  <fg=red>{$model}:</>");
                foreach (array_slice($modelErrors, 0, 5) as $error) {
                    $this->line("    - {$error}");
                }
                if (count($modelErrors) > 5) {
                    $this->line("    ... and " . (count($modelErrors) - 5) . " more");
                }
            }
        }

        $this->newLine();
        $this->comment('Ingestion complete!');

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Index (label, id) before writing: nodes are MERGEd and relationships MATCHed by id, and without the
     * index every write scans its label, so the ingest slows down as the graph grows.
     *
     * @param string[] $labels
     */
    private function ensureIdIndexes(array $labels): void
    {
        foreach (array_unique($labels) as $label) {
            $name = 'ai_' . strtolower((string) preg_replace('/[^A-Za-z0-9_]/', '_', $label)) . '_id';
            $safeLabel = \Condoedge\Ai\GraphStore\CypherSanitizer::escapeLabel($label);

            try {
                $this->graphStore->query("CREATE INDEX {$name} IF NOT EXISTS FOR (n:{$safeLabel}) ON (n.id)");
            } catch (\Throwable $e) {
                $this->warn("  Could not index {$label}.id: {$e->getMessage()}");
            }
        }
    }

    /** @return string[] */
    private function labelsOf(array $models): array
    {
        $labels = [];
        foreach ($models as $modelClass) {
            try {
                $labels[] = (new $modelClass)->getGraphConfig()->label;
            } catch (\Throwable $e) {
                // An unconfigured model is reported where it is ingested.
            }
        }

        return $labels;
    }

    /**
     * The entity's graph.with eager-loads (relations its graph.computed accessors read).
     * A config error here is not fatal: the per-entity mapping reports it.
     *
     * @return string[]
     */
    private function graphEagerLoads(string $modelClass): array
    {
        try {
            return (new $modelClass)->getGraphConfig()->with;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Ingest all entities for a specific model
     *
     * @param string $modelClass Model class name
     * @param int $chunkSize Batch size
     * @return array{ingested: int, partial: int, failed: int, errors: array}
     */
    private function ingestModel(string $modelClass, int $chunkSize): array
    {
        $this->line("Processing: <fg=cyan>{$modelClass}</>");

        if (!class_exists($modelClass)) {
            $this->error("  ✗ Class not found: {$modelClass}");
            return ['ingested' => 0, 'partial' => 0, 'failed' => 0, 'errors' => ["Class not found"]];
        }

        if (!in_array(Nodeable::class, class_implements($modelClass) ?: [])) {
            $this->error("  ✗ Model does not implement Nodeable interface");
            return ['ingested' => 0, 'partial' => 0, 'failed' => 0, 'errors' => ["Not a Nodeable model"]];
        }

        // Segmentation window (--min-id/--max-id): restrict to a primary-key range so a
        // parent can split one large model across several bounded child processes.
        $keyName = (new $modelClass)->getKeyName();
        $query = $modelClass::query()
            ->when($this->option('min-id') !== null, fn ($q) => $q->where($keyName, '>=', $this->option('min-id')))
            ->when($this->option('max-id') !== null, fn ($q) => $q->where($keyName, '<', $this->option('max-id')));

        // graph.with: eager-load the relations graph.computed accessors read, once per chunk.
        $with = $this->graphEagerLoads($modelClass);
        if ($with !== []) {
            $query->with($with);
        }

        try {
            $total = (clone $query)->count();
        } catch (\Throwable $e) {
            $this->error("  ✗ Failed to count entities: {$e->getMessage()}");
            return ['ingested' => 0, 'partial' => 0, 'failed' => 0, 'errors' => [$e->getMessage()]];
        }

        if ($total === 0) {
            $this->comment("  ⊘ No entities found");
            return ['ingested' => 0, 'partial' => 0, 'failed' => 0, 'errors' => []];
        }

        $this->line("  Found {$total} entities");

        if ($this->option('dry-run')) {
            $this->comment("  ⊘ Dry run - skipping ingestion");
            return ['ingested' => $total, 'partial' => 0, 'failed' => 0, 'errors' => []];
        }

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->setFormat('  [%bar%] %current%/%max% (%percent:3s%%) %message%');
        $progressBar->setMessage('Ingesting...');
        $progressBar->start();

        $ingested = 0;
        $partial = 0;
        $failed = 0;
        $errors = [];

        // By key, not OFFSET: a row deleted meanwhile would make OFFSET skip another, and each page rescans the table.
        $query->chunkById($chunkSize, function ($entities) use (&$ingested, &$partial, &$failed, &$errors, $progressBar) {
            try {
                $results = $this->ingestionService->ingestBatch($entities->all());

                $ingested += $results['succeeded'];
                $partial += $results['partially_succeeded'] ?? 0;
                $failed += $results['failed'];

                if (!empty($results['errors'])) {
                    foreach ($results['errors'] as $error) {
                        $errors[] = self::formatBatchError($error);
                    }
                }

                $progressBar->advance($entities->count());
                // Memory telemetry: a steadily climbing Mem across chunks means something
                // process-wide is retaining per-row data (e.g. a debug query collector) —
                // chunk-local work alone keeps this flat.
                $mem = round(memory_get_usage(true) / 1048576);
                $peak = round(memory_get_peak_usage(true) / 1048576);
                $progressBar->setMessage("Ingested: {$ingested}, Partial: {$partial}, Failed: {$failed} | Mem: {$mem}MB (peak {$peak}MB)");
            } catch (\Throwable $e) {
                $failed += $entities->count();
                $errors[] = "Batch error: {$e->getMessage()}";
                $progressBar->advance($entities->count());
            }

            // Eloquent models + their relations form reference cycles; collect them
            // deterministically each chunk instead of waiting for GC root-buffer pressure.
            gc_collect_cycles();
        });

        $progressBar->finish();
        $this->newLine();

        if ($failed > 0) {
            $this->line("  <fg=yellow>⚠</> Ingested: {$ingested}, Partial: {$partial}, Failed: {$failed}");
        } elseif ($partial > 0) {
            $this->line("  <fg=yellow>~</> Ingested: {$ingested}, Partial: {$partial}, Failed: {$failed}");
        } else {
            $this->line("  <fg=green>✓</> Ingested: {$ingested}");
        }

        return [
            'ingested' => $ingested,
            'partial' => $partial,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Render one ingestBatch() error entry. BatchDataIngestionService reports plain strings;
     * array-shaped entries (other implementations) use their 'message', else their JSON.
     *
     * @internal public only for unit testing.
     */
    public static function formatBatchError(mixed $error): string
    {
        return is_array($error)
            ? (string) ($error['message'] ?? json_encode($error))
            : (string) $error;
    }

    /**
     * Find all Nodeable models in the application
     *
     * @return array<string> Model class names
     */
    private function findNodeableModels(): array
    {
        return array_keys(config('entities'));
    }

    /**
     * Clear the graph (what the ingest rebuilds). The vector store is left alone.
     *
     * @param array $models Models being ingested (used to determine collections)
     * @return void
     */
    private function clearStores(array $models): void
    {
        $this->warn('Clearing the graph...');

        $this->line('  Clearing Neo4j (batched)...');
        try {
            // A single `MATCH (n) DETACH DELETE n` holds the WHOLE graph's delete set in ONE
            // transaction — on a real dataset the server heap fills, GC thrashes, and the query
            // never returns (observed: a 6h hang on a host's first full wipe). query() runs
            // each call as its own auto-commit transaction, so loop small bounded batches
            // instead: relationships first (a node batch can still drag an unbounded number of
            // rels via DETACH), then nodes. Each iteration commits, so progress is real and
            // memory stays flat regardless of graph size.
            $totalRels = 0;
            do {
                $result = $this->graphStore->query(
                    'MATCH ()-[r]->() WITH r LIMIT 50000 DELETE r RETURN count(r) AS deleted'
                );
                $deleted = (int) ($result[0]['deleted'] ?? 0);
                $totalRels += $deleted;
                if ($deleted > 0) {
                    $this->line("    … {$totalRels} relationships deleted");
                }
            } while ($deleted > 0);

            $totalNodes = 0;
            do {
                // DETACH DELETE in the node phase too: auto_sync app traffic can create new
                // relationships while the wipe runs; a plain DELETE would then error out.
                $result = $this->graphStore->query(
                    'MATCH (n) WITH n LIMIT 50000 DETACH DELETE n RETURN count(n) AS deleted'
                );
                $deleted = (int) ($result[0]['deleted'] ?? 0);
                $totalNodes += $deleted;
                if ($deleted > 0) {
                    $this->line("    … {$totalNodes} nodes deleted");
                }
            } while ($deleted > 0);

            $this->line("    <fg=green>✓</> Neo4j cleared ({$totalNodes} nodes, {$totalRels} relationships)");
        } catch (\Throwable $e) {
            $this->error("    ✗ Failed to clear Neo4j: {$e->getMessage()}");
        }

        // The vector store is NOT cleared. The ingest writes the graph only (2026-10-08), so
        // nothing here would rebuild a collection it emptied: the uploaded-file chunks and
        // their document maps, the context / scope / question indexes each have their own
        // writer (file processing, ai:index-context, ai:index-semantic, ai:index-scopes).

        $this->newLine();
    }

    /**
     * Handle physical documentation file ingestion.
     *
     * Uses PhysicalFileIndexer to discover and create file objects,
     * then processes them with FileProcessor into the configured collection.
     *
     * @return int
     */
    protected function handleDocsIngestion(): int
    {
        // PROJECT files are global and must never land among the UPLOADED files
        // (linked to a record, searched under an ACL and a scope). Only
        // configuration tells the two collections apart, so it is checked here:
        // an empty name falls back to the chunk store's bound collection — the
        // uploads — and an equal name IS the uploads.
        $physical = config('ai.file_context.physical_collection', 'documentation_chunks');
        $uploads = config('ai.file_processing.collection', 'file_chunks');
        if (!is_string($physical) || trim($physical) === '' || $physical === $uploads) {
            $this->error(sprintf(
                'ai.file_context.physical_collection is %s — the collection of UPLOADED files (ai.file_processing.collection = "%s"). '
                . 'Project files are global and need a collection of their own (default: documentation_chunks). Nothing was indexed.',
                is_string($physical) && trim($physical) !== '' ? '"' . $physical . '"' : 'empty',
                is_string($uploads) ? $uploads : ''
            ));

            return self::FAILURE;
        }

        $this->info('Discovering physical documentation files...');

        $indexer = app(PhysicalFileIndexer::class);
        $fileObjects = $indexer->createFileObjects();

        if (empty($fileObjects)) {
            $this->warn('No files found matching configured patterns.');
            $this->line('Configure patterns in config/ai.php: file_context.physical_paths');
            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d files to index.', count($fileObjects)));

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->table(['ID', 'Name', 'Path'], array_map(fn($f) => [
                $f->id,
                $f->name,
                $f->path,
            ], $fileObjects));
            return self::SUCCESS;
        }

        $fileProcessor = app(FileProcessorInterface::class);
        $collection = $physical;
        $force = $this->option('fresh');

        $bar = $this->output->createProgressBar(count($fileObjects));
        $bar->setFormat('  [%bar%] %current%/%max% (%percent:3s%%) %message%');
        $bar->setMessage('Processing...');
        $bar->start();

        $results = ['success' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($fileObjects as $fileObject) {
            try {
                $result = $fileProcessor->processFile($fileObject, [
                    'collection' => $collection,
                    'force' => $force,
                    'skip_unchanged' => true,
                ]);

                if ($result->failed()) {
                    $results['failed']++;
                } elseif ($result->success && $result->chunksCreated === 0) {
                    // Successful but no chunks created means it was skipped
                    // (e.g., already processed)
                    $results['skipped']++;
                } else {
                    $results['success']++;
                }
            } catch (\Throwable $e) {
                $results['failed']++;
                $this->newLine();
                $this->error("Failed to index {$fileObject->name}: {$e->getMessage()}");
            }

            $bar->advance();
            $bar->setMessage("Success: {$results['success']}, Failed: {$results['failed']}");
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Indexing complete:');
        $this->line("  Success: {$results['success']}");

        if ($results['skipped'] > 0) {
            $this->line("  Skipped: {$results['skipped']}");
        }

        if ($results['failed'] > 0) {
            $this->warn("  Failed: {$results['failed']}");
        }

        if ($this->option('prune')) {
            $this->line('  Pruned: ' . $this->pruneMissingDocs($fileObjects, $collection));
        }

        return $results['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Remove the stored project files (`physical:` ids) whose file is no longer among the
     * configured paths. Only reached when the paths matched files: a pattern that matches
     * nothing returns before this, so a misconfiguration never empties the collection.
     */
    private function pruneMissingDocs(array $fileObjects, string $collection): int
    {
        $store = app(ChunkStoreInterface::class);
        if (!method_exists($store, 'storedFileIds')) {
            $this->warn('  The chunk store cannot list its files: nothing pruned.');

            return 0;
        }

        $onDisk = array_flip(array_map(static fn ($file) => (string) $file->id, $fileObjects));
        $pruned = 0;
        foreach ($store->storedFileIds($collection) as $fileId) {
            if (str_starts_with((string) $fileId, 'physical:') && !isset($onDisk[(string) $fileId])) {
                $store->deleteFileChunks($fileId, $collection);
                $pruned++;
            }
        }

        return $pruned;
    }
}
