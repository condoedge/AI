<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Services\BatchDataIngestionService;
use Condoedge\Ai\Console\Commands\Concerns\FlushesGraphSchemaCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * SyncRelationshipsCommand
 *
 * Reconcile missing relationships that couldn't be created during bulk ingestion
 * because target nodes didn't exist yet (e.g., Users ingested before Persons).
 *
 * This command:
 * - Finds all Nodeable entities in your database
 * - Checks configured relationships against Neo4j
 * - Creates missing relationships (skips existing ones)
 * - Provides detailed summary of created/skipped/failed relationships
 *
 * Usage:
 *   php artisan ai:sync-relationships                          # Sync all entities
 *   php artisan ai:sync-relationships --model=User             # Sync specific model
 *   php artisan ai:sync-relationships --chunk=100              # Batch size
 *   php artisan ai:sync-relationships --dry-run                # Preview without syncing
 *
 * @package Condoedge\Ai\Console\Commands
 */
class SyncRelationshipsCommand extends Command
{
    use FlushesGraphSchemaCache;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:sync-relationships
                            {--model= : Specific model class to sync (e.g., App\\Models\\User)}
                            {--from= : Skip models until this one (e.g., InboundEmail or App\\Models\\Messaging\\InboundEmail)}
                            {--chunk=2000 : Batch size for processing}
                            {--dry-run : Show what would be synced without actually syncing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize missing relationships in Neo4j (reconcile after bulk ingestion)';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private BatchDataIngestionService $ingestionService
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
        // Same memory hygiene as ai:ingest — this command walks every row of every model:
        // raise the CLI limit, and detach the per-query retention (QueryExecuted collectors
        // hold every query + bindings for the process lifetime; gc cannot reclaim it).
        @ini_set('memory_limit', (string) config('ai.ingestion.memory_limit', '2G'));
        try {
            \Illuminate\Support\Facades\DB::connection()->disableQueryLog();
            \Illuminate\Support\Facades\DB::connection()->unsetEventDispatcher();
        } catch (\Throwable $e) {
            // No default DB connection — nothing to detach.
        }

        $this->info('🔗 Relationship Synchronization');
        $this->newLine();

        $models = $this->option('model')
            ? [$this->option('model')]
            : $this->findNodeableModels();
    
        if ($from = $this->option('from')) {
            $models = $this->skipModelsUntil($models, $from);
        }

        if (empty($models)) {
            $this->warn('No Nodeable models found.');
            $this->comment('Ensure your models implement Nodeable and use HasNodeableConfig trait.');
            return self::FAILURE;
        }

        $this->info('Found ' . count($models) . ' Nodeable model(s)');
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('[DRY RUN MODE]');
            $this->newLine();
        }

        $chunkSize = (int) $this->option('chunk');
        $totalCreated = 0;
        $totalSkipped = 0;
        $totalFailed = 0;

        foreach ($models as $modelClass) {
            $result = $this->syncModelRelationships($modelClass, $chunkSize);

            $totalCreated += $result['created'];
            $totalSkipped += $result['skipped'];
            $totalFailed += $result['failed'];
        }

        $this->newLine();
        $this->info('✓ Synchronization Complete');
        $this->line("  Created: {$totalCreated}");
        $this->line("  Skipped (already exist): {$totalSkipped}");

        if ($totalFailed > 0) {
            $this->warn("  Failed: {$totalFailed}");
            $this->comment('  Check logs for details: storage/logs/laravel.log');
        }

        return self::SUCCESS;
    }

    /**
     * Sync relationships for a specific model
     *
     * @param string $modelClass
     * @param int $chunkSize
     * @return array Summary with created/skipped/failed counts
     */
    private function syncModelRelationships(string $modelClass, int $chunkSize): array
    {
        $this->line("Processing: {$modelClass}");

        if (!class_exists($modelClass)) {
            $this->error("  ✗ Model class not found: {$modelClass}");
            return ['created' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $model = new $modelClass();

        if (!$model instanceof Nodeable) {
            $this->error("  ✗ Model does not implement Nodeable interface");
            return ['created' => 0, 'skipped' => 0, 'failed' => 0];
        }

        // Check if model has table (some models like pivots might not)
        if (!$model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            $this->warn("  ⊘ Table does not exist: {$model->getTable()}");
            return ['created' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $total = $modelClass::count();

        if ($total === 0) {
            $this->comment("  ⊘ No entities found");
            return ['created' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $this->line("  Found {$total} entities");

        $created = 0;
        $skipped = 0;
        $failed = 0;

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->setFormat('  [%bar%] %current%/%max% (%percent:3s%%) %message%');
        $progressBar->setMessage('Created: 0');
        $progressBar->start();

        // Column-pruned loading: relationship sync reads only the key + FK/morph columns
        // per row, but a full hydration drags every column along — InboundEmail's complete
        // email bodies OOM'd a 2000-row chunk before a single relationship was checked.
        $query = $modelClass::query();
        $columns = $this->relationshipColumns($model);
        if ($columns !== null) {
            $query->select($columns);
        }

        $query->chunk($chunkSize, function ($entities) use (
            &$created,
            &$skipped,
            &$failed,
            $progressBar
        ) {
            if ($this->option('dry-run')) {
                // Dry-run only counts; it never writes.
                $progressBar->advance(count($entities));
                return;
            }

            $result = $this->ingestionService->syncRelationships($entities->all());

            $created += $result['relationships_created'];
            $skipped += $result['relationships_skipped'];
            $failed += $result['relationships_failed'];

            $mem = round(memory_get_usage(true) / 1048576);
            $peak = round(memory_get_peak_usage(true) / 1048576);
            $progressBar->setMessage("Created: {$created} | Mem: {$mem}MB (peak {$peak}MB)");
            $progressBar->advance(count($entities));

            // Entities + relations form reference cycles; collect deterministically per chunk.
            gc_collect_cycles();
        });

        $progressBar->finish();
        $this->newLine();

        $this->line("  ✓ Created: {$created}, Skipped: {$skipped}" . ($failed > 0 ? ", Failed: {$failed}" : ''));
        $this->newLine();

        return [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * The only columns relationship sync reads per row: the primary key, each
     * relationship's foreign key, the morph-type column for polymorphic targets, and any
     * relationship-property source fields. The list is intersected with the table's real
     * columns (one cheap schema query) so a convention-derived morph column that does not
     * exist can never break the SELECT. Returns null (full row) when the graph config or
     * schema cannot be read — correctness over optimization.
     *
     * Trade-off: models whose $appends accessors read non-selected attributes will see
     * null there during toArray(); the sync service's per-entity try/catch keeps any such
     * failure contained to that entity.
     */
    private function relationshipColumns(Nodeable $model): ?array
    {
        try {
            $columns = [$model->getKeyName()];

            foreach ($model->getGraphConfig()->relationships as $rel) {
                $columns[] = $rel->foreignKey;
                if ($rel->isPolymorphic()) {
                    $columns[] = $rel->morphTypeColumn();
                }
                foreach ($rel->properties as $sourceField) {
                    if (is_string($sourceField)) {
                        $columns[] = $sourceField;
                    }
                }
            }

            $existing = array_flip(
                $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable())
            );
            $columns = array_values(array_filter(
                array_unique($columns),
                static fn ($column) => isset($existing[$column])
            ));

            // The key must survive the intersection or chunk() ordering breaks — bail to full rows.
            return in_array($model->getKeyName(), $columns, true) ? $columns : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Find all Nodeable models in the application
     *
     * @return array Array of model class names
     */
    private function findNodeableModels(): array
    {
        $models = [];
        $appPath = app_path('Models');

        if (!File::exists($appPath)) {
            return $models;
        }

        $finder = new Finder();
        $finder->files()->in($appPath)->name('*.php');

        $namespace = app()->getNamespace();

        foreach ($finder as $file) {
            $relativePath = str_replace('/', '\\', $file->getRelativePathname());
            $className = $namespace . 'Models\\' . str_replace('.php', '', $relativePath);

            if (!class_exists($className)) {
                continue;
            }

            $reflection = new \ReflectionClass($className);

            if ($reflection->implementsInterface(Nodeable::class)) {
                $models[] = $className;
            }
        }

        return $models;
    }

    private function skipModelsUntil(array $models, string $from): array
    {
        $skipping = true;
        $filtered = [];

        foreach ($models as $model) {
            if ($skipping) {
                if (str_contains($model, $from)) {
                    $skipping = false;
                    $filtered[] = $model;
                }
            } else {
                $filtered[] = $model;
            }
        }

        return $filtered;
    }
}
