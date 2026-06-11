<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Services\BatchDataIngestionService;
use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Services\Files\PhysicalFileIndexer;
use Illuminate\Console\Command;

/**
 * IngestEntitiesCommand
 *
 * Bulk ingest all existing Nodeable entities into Neo4j and Qdrant.
 * Useful for initial setup or when migrating existing data.
 *
 * Usage:
 *   php artisan ai:ingest                      # Ingest all entities
 *   php artisan ai:ingest --model=Customer     # Ingest specific model
 *   php artisan ai:ingest --fresh              # Clear stores first
 *   php artisan ai:ingest --chunk=100          # Batch size
 *
 * @package Condoedge\Ai\Console\Commands
 */
class IngestEntitiesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:ingest
                            {--model= : Specific model class to ingest (e.g., App\\Models\\Customer)}
                            {--from= : Skip models until this one (e.g., InboundEmail or App\\Models\\Messaging\\InboundEmail)}
                            {--fresh : Clear all data from stores before ingesting}
                            {--chunk=2000 : Batch size for processing}
                            {--min-id= : Only ingest rows with primary key >= this value (manual range restriction)}
                            {--max-id= : Only ingest rows with primary key < this value (manual range restriction)}
                            {--dry-run : Show what would be ingested without actually ingesting}
                            {--docs : Index physical documentation files from configured paths}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bulk ingest all Nodeable entities into Neo4j and Qdrant';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private BatchDataIngestionService $ingestionService,
        private GraphStoreInterface $graphStore,
        private VectorStoreInterface $vectorStore
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
                || $this->confirm('⚠️  This will DELETE all data from Neo4j and Qdrant. Continue?', false);

            if (!$proceed) {
                $this->warn('Ingestion cancelled.');
                return self::SUCCESS;
            }

            if (!$this->input->isInteractive()) {
                $this->warn('Non-interactive --fresh: clearing stores without prompting.');
            }

            $this->clearStores($models);
        }

        $chunkSize = (int) $this->option('chunk');

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

        // Collections are created automatically by DataIngestionService on first use.
        $progressBar = $this->output->createProgressBar($total);
        $progressBar->setFormat('  [%bar%] %current%/%max% (%percent:3s%%) %message%');
        $progressBar->setMessage('Ingesting...');
        $progressBar->start();

        $ingested = 0;
        $partial = 0;
        $failed = 0;
        $errors = [];

        $query->chunk($chunkSize, function ($entities) use (&$ingested, &$partial, &$failed, &$errors, $progressBar) {
            try {
                $results = $this->ingestionService->ingestBatch($entities->all());

                $ingested += $results['succeeded'];
                $partial += $results['partially_succeeded'] ?? 0;
                $failed += $results['failed'];

                if (!empty($results['errors'])) {
                    foreach ($results['errors'] as $error) {
                        $errors[] = $error['message'] ?? 'Unknown error';
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
     * Find all Nodeable models in the application
     *
     * @return array<string> Model class names
     */
    private function findNodeableModels(): array
    {
        return array_keys(config('entities'));
    }

    /**
     * Clear all data from Neo4j and Qdrant stores
     *
     * @param array $models Models being ingested (used to determine collections)
     * @return void
     */
    private function clearStores(array $models): void
    {
        $this->warn('Clearing stores...');

        $this->line('  Clearing Neo4j (batched)...');
        try {
            // A single `MATCH (n) DETACH DELETE n` holds the WHOLE graph's delete set in ONE
            // transaction — on a real dataset the server heap fills, GC thrashes, and the query
            // never returns (observed: a 6h hang on the first full CondoEdge wipe). query() runs
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

        $this->line('  Clearing Qdrant collections...');
        try {
            $collections = $this->vectorStore->listCollections();

            foreach ($collections as $collection) {
                try {
                    $this->vectorStore->deleteAll($collection);
                    $this->line("    <fg=green>✓</> Cleared: {$collection}");
                } catch (\Throwable $e) {
                    $this->error("    ✗ Failed to clear {$collection}: {$e->getMessage()}");
                }
            }
        } catch (\Throwable $e) {
            $this->error("    ✗ Failed to list collections: {$e->getMessage()}");
        }

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
        $collection = config('ai.file_context.physical_collection', 'documentation_chunks');
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

        return $results['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
