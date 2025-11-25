<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
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
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:sync-relationships
                            {--model= : Specific model class to sync (e.g., App\\Models\\User)}
                            {--chunk=100 : Batch size for processing}
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
        private DataIngestionServiceInterface $ingestionService
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
        $this->info('🔗 Relationship Synchronization');
        $this->newLine();

        // Get models to sync
        $models = $this->option('model')
            ? [$this->option('model')]
            : $this->findNodeableModels();

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

        // Process each model
        foreach ($models as $modelClass) {
            $result = $this->syncModelRelationships($modelClass, $chunkSize);

            $totalCreated += $result['created'];
            $totalSkipped += $result['skipped'];
            $totalFailed += $result['failed'];
        }

        // Summary
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

        // Get total count
        $total = $modelClass::count();

        if ($total === 0) {
            $this->comment("  ⊘ No entities found");
            return ['created' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $this->line("  Found {$total} entities");

        $created = 0;
        $skipped = 0;
        $failed = 0;

        // Progress bar
        $progressBar = $this->output->createProgressBar($total);
        $progressBar->setFormat('  [%bar%] %current%/%max% (%percent:3s%%) Created: %message%');
        $progressBar->setMessage('0');
        $progressBar->start();

        // Process in chunks
        $modelClass::chunk($chunkSize, function ($entities) use (
            &$created,
            &$skipped,
            &$failed,
            $progressBar
        ) {
            if ($this->option('dry-run')) {
                // In dry-run, just count without syncing
                $progressBar->advance(count($entities));
                return;
            }

            $result = $this->ingestionService->syncRelationships($entities->all());

            $created += $result['relationships_created'];
            $skipped += $result['relationships_skipped'];
            $failed += $result['relationships_failed'];

            $progressBar->setMessage((string) $created);
            $progressBar->advance(count($entities));
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

            // Check if class implements Nodeable
            if ($reflection->implementsInterface(Nodeable::class)) {
                $models[] = $className;
            }
        }

        return $models;
    }
}
