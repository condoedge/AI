<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

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
                            {--fresh : Clear all data from stores before ingesting}
                            {--chunk=100 : Batch size for processing}
                            {--dry-run : Show what would be ingested without actually ingesting}';

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
        $this->info('🚀 Bulk Entity Ingestion');
        $this->newLine();

        // Get models to ingest
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

        // Fresh mode - clear stores
        if ($this->option('fresh') && !$this->option('dry-run')) {
            if ($this->confirm('⚠️  This will DELETE all data from Neo4j and Qdrant. Continue?', false)) {
                $this->warn('Clearing stores...');
                // TODO: Implement store clearing
                $this->comment('  Note: Store clearing not yet implemented');
            } else {
                $this->warn('Ingestion cancelled.');
                return self::SUCCESS;
            }
        }

        $chunkSize = (int) $this->option('chunk');
        $totalIngested = 0;
        $totalFailed = 0;
        $errors = [];

        // Process each model
        foreach ($models as $modelClass) {
            $result = $this->ingestModel($modelClass, $chunkSize);

            $totalIngested += $result['ingested'];
            $totalFailed += $result['failed'];

            if (!empty($result['errors'])) {
                $errors[$modelClass] = $result['errors'];
            }
        }

        $this->newLine();
        $this->newLine();

        // Summary
        if ($this->option('dry-run')) {
            $this->info("✓ DRY RUN - Would ingest {$totalIngested} entities");
        } else {
            $this->info("✓ Successfully ingested: {$totalIngested}");

            if ($totalFailed > 0) {
                $this->error("✗ Failed: {$totalFailed}");
            }
        }

        // Show errors
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
     * @return array{ingested: int, failed: int, errors: array}
     */
    private function ingestModel(string $modelClass, int $chunkSize): array
    {
        $this->line("Processing: <fg=cyan>{$modelClass}</>");

        if (!class_exists($modelClass)) {
            $this->error("  ✗ Class not found: {$modelClass}");
            return ['ingested' => 0, 'failed' => 0, 'errors' => ["Class not found"]];
        }

        // Check if model implements Nodeable
        if (!in_array(Nodeable::class, class_implements($modelClass) ?: [])) {
            $this->error("  ✗ Model does not implement Nodeable interface");
            return ['ingested' => 0, 'failed' => 0, 'errors' => ["Not a Nodeable model"]];
        }

        // Get total count
        try {
            $total = $modelClass::count();
        } catch (\Throwable $e) {
            $this->error("  ✗ Failed to count entities: {$e->getMessage()}");
            return ['ingested' => 0, 'failed' => 0, 'errors' => [$e->getMessage()]];
        }

        if ($total === 0) {
            $this->comment("  ⊘ No entities found");
            return ['ingested' => 0, 'failed' => 0, 'errors' => []];
        }

        $this->line("  Found {$total} entities");

        if ($this->option('dry-run')) {
            $this->comment("  ⊘ Dry run - skipping ingestion");
            return ['ingested' => $total, 'failed' => 0, 'errors' => []];
        }

        // Process in chunks with progress bar
        // Note: Collections are automatically created by DataIngestionService on first use
        $progressBar = $this->output->createProgressBar($total);
        $progressBar->setFormat('  [%bar%] %current%/%max% (%percent:3s%%) %message%');
        $progressBar->setMessage('Ingesting...');
        $progressBar->start();

        $ingested = 0;
        $failed = 0;
        $errors = [];

        $modelClass::chunk($chunkSize, function ($entities) use (&$ingested, &$failed, &$errors, $progressBar) {
            try {
                // Use batch ingestion for efficiency
                $results = $this->ingestionService->ingestBatch($entities->all());

                $ingested += $results['succeeded'];
                $failed += $results['failed'];

                if (!empty($results['errors'])) {
                    foreach ($results['errors'] as $error) {
                        $errors[] = $error['message'] ?? 'Unknown error';
                    }
                }

                $progressBar->advance($entities->count());
                $progressBar->setMessage("Ingested: {$ingested}, Failed: {$failed}");
            } catch (\Throwable $e) {
                $failed += $entities->count();
                $errors[] = "Batch error: {$e->getMessage()}";
                $progressBar->advance($entities->count());
            }
        });

        $progressBar->finish();
        $this->newLine();

        if ($failed > 0) {
            $this->line("  <fg=yellow>⚠</> Ingested: {$ingested}, Failed: {$failed}");
        } else {
            $this->line("  <fg=green>✓</> Ingested: {$ingested}");
        }

        return [
            'ingested' => $ingested,
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
}
