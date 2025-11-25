<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Contracts\FileProcessorInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ProcessFilesCommand
 *
 * Batch process all existing files for semantic search.
 * Extracts text content, chunks it, generates embeddings, and stores in Qdrant.
 *
 * This command is useful for:
 * - Initial setup when you have existing files
 * - Reprocessing files after configuration changes
 * - Migrating to the AI package with existing file library
 *
 * Usage:
 *   php artisan ai:process-files                    # Process all unprocessed files
 *   php artisan ai:process-files --model=File       # Process specific model
 *   php artisan ai:process-files --force            # Reprocess all files
 *   php artisan ai:process-files --chunk=50         # Batch size
 *   php artisan ai:process-files --dry-run          # Preview without processing
 *
 * @package Condoedge\Ai\Console\Commands
 */
class ProcessFilesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:process-files
                            {--model=Condoedge\\File\\Models\\File : File model class to process}
                            {--force : Reprocess all files, even if already processed}
                            {--chunk=50 : Batch size for processing}
                            {--types= : Comma-separated list of file extensions to process (e.g., pdf,docx,txt)}
                            {--dry-run : Show what would be processed without actually processing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Batch process files for semantic search (extract, chunk, embed, store)';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private FileProcessorInterface $fileProcessor
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
        $this->info('📄 Batch File Processing');
        $this->newLine();

        // Check if file processing is enabled
        if (!config('ai.file_processing.enabled', true)) {
            $this->error('File processing is disabled in config/ai.php');
            $this->comment('Set AI_FILE_PROCESSING_ENABLED=true in your .env file');
            return self::FAILURE;
        }

        $modelClass = $this->option('model');

        // Validate model class
        if (!class_exists($modelClass)) {
            $this->error("Model class not found: {$modelClass}");
            return self::FAILURE;
        }

        // Check if table exists
        $model = new $modelClass();
        if (!$model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            $this->error("Table does not exist: {$model->getTable()}");
            return self::FAILURE;
        }

        // Get supported file types
        $supportedTypes = $this->fileProcessor->getSupportedFileTypes();
        $this->info('Supported file types: ' . implode(', ', $supportedTypes));
        $this->newLine();

        // Filter by file types if specified
        $filterTypes = $this->option('types')
            ? explode(',', $this->option('types'))
            : null;

        if ($filterTypes) {
            $invalidTypes = array_diff($filterTypes, $supportedTypes);
            if (!empty($invalidTypes)) {
                $this->warn('Unsupported file types will be skipped: ' . implode(', ', $invalidTypes));
            }
        }

        // Build query
        $query = $modelClass::query();

        // Filter by extension if types specified
        if ($filterTypes) {
            $query->where(function ($q) use ($filterTypes) {
                foreach ($filterTypes as $type) {
                    $q->orWhere('name', 'LIKE', "%.{$type}");
                }
            });
        }

        // If not forcing, only get unprocessed files
        if (!$this->option('force')) {
            // Assuming there's a processed_at column or similar
            // You might need to adjust this based on your File model
            if ($model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'processed_at')) {
                $query->whereNull('processed_at');
            }
        }

        $total = $query->count();

        if ($total === 0) {
            $this->warn('No files found to process.');
            if (!$this->option('force')) {
                $this->comment('Tip: Use --force to reprocess already processed files');
            }
            return self::SUCCESS;
        }

        $this->info("Found {$total} file(s) to process");
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('[DRY RUN MODE]');
            $this->displayDryRunSummary($query, $filterTypes);
            return self::SUCCESS;
        }

        // Confirm if large number of files
        if ($total > 100 && !$this->confirm("Process {$total} files? This may take a while.", true)) {
            $this->warn('Processing cancelled.');
            return self::SUCCESS;
        }

        $chunkSize = (int) $this->option('chunk');
        $processed = 0;
        $failed = 0;
        $skipped = 0;

        // Progress bar
        $progressBar = $this->output->createProgressBar($total);
        $progressBar->setFormat('  [%bar%] %current%/%max% (%percent:3s%%) Processed: %message%');
        $progressBar->setMessage('0');
        $progressBar->start();

        // Process in chunks
        $query->chunk($chunkSize, function ($files) use (
            &$processed,
            &$failed,
            &$skipped,
            $progressBar,
            $filterTypes,
            $supportedTypes
        ) {
            foreach ($files as $file) {
                try {
                    // Check if file type is supported
                    $extension = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));

                    if ($filterTypes && !in_array($extension, $filterTypes)) {
                        $skipped++;
                        $progressBar->advance();
                        continue;
                    }

                    if (!in_array($extension, $supportedTypes)) {
                        $skipped++;
                        $progressBar->advance();
                        continue;
                    }

                    // Check if file exists on disk
                    if (method_exists($file, 'existsOnDisk') && !$file->existsOnDisk()) {
                        $skipped++;
                        $progressBar->advance();
                        continue;
                    }

                    // Process file
                    $result = $this->option('force')
                        ? $this->fileProcessor->reprocessFile($file)
                        : $this->fileProcessor->processFile($file);

                    if ($result->succeeded()) {
                        $processed++;
                        $progressBar->setMessage((string) $processed);
                    } else {
                        $failed++;
                    }

                } catch (\Throwable $e) {
                    $failed++;
                    \Log::error('File processing failed', [
                        'file_id' => $file->id,
                        'file_name' => $file->name,
                        'error' => $e->getMessage(),
                    ]);
                }

                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine();
        $this->newLine();

        // Summary
        $this->info('✓ Processing Complete');
        $this->line("  Processed: {$processed}");

        if ($skipped > 0) {
            $this->line("  Skipped: {$skipped}");
        }

        if ($failed > 0) {
            $this->warn("  Failed: {$failed}");
            $this->comment('  Check logs for details: storage/logs/laravel.log');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Display dry-run summary
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array|null $filterTypes
     * @return void
     */
    private function displayDryRunSummary($query, $filterTypes): void
    {
        $this->newLine();

        // Group by extension
        $filesByType = DB::table($query->getModel()->getTable())
            ->selectRaw('LOWER(SUBSTRING_INDEX(name, ".", -1)) as extension, COUNT(*) as count')
            ->whereIn('id', $query->pluck('id'))
            ->groupBy('extension')
            ->orderByDesc('count')
            ->get();

        $this->table(
            ['File Type', 'Count'],
            $filesByType->map(function ($row) use ($filterTypes) {
                $marker = $filterTypes && !in_array($row->extension, $filterTypes) ? ' (filtered out)' : '';
                return [
                    $row->extension . $marker,
                    $row->count
                ];
            })
        );

        $this->newLine();
        $this->comment('Run without --dry-run to process files');
    }
}
