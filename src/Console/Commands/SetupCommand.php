<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Illuminate\Console\Command;

/**
 * SetupCommand
 *
 * Runs the full AI pipeline setup: ingest, sync, and index.
 * Run ai:discover separately first to generate entity configurations.
 *
 * Usage:
 *   php artisan ai:setup
 *   php artisan ai:setup --discover        # Also run ai:discover before ingestion
 *   php artisan ai:setup --fresh           # Clear all stores before ingestion
 *   php artisan ai:setup --skip-ingest     # Skip data ingestion (useful for re-indexing only)
 *   php artisan ai:setup --dry-run         # Show what would be executed without running
 *
 * @package Condoedge\Ai\Console\Commands
 */
class SetupCommand extends Command
{
    protected $signature = 'ai:setup
        {--discover : Also run ai:discover before ingestion (overwrites existing config)}
        {--fresh : Clear all stores before ingestion}
        {--skip-ingest : Skip data ingestion (useful for re-indexing only)}
        {--chunk= : Batch size passed through to ai:ingest}
        {--dry-run : Show what would be executed without running}';

    protected $description = 'Run the full AI pipeline setup: ingest → sync → index. Run ai:discover separately first.';

    public function handle(): int
    {
        $steps = $this->buildSteps();

        if ($this->option('dry-run')) {
            $this->info('Dry run — would execute:');
            foreach ($steps as $i => $step) {
                $this->line("  " . ($i + 1) . ". {$step['label']}");
                $this->line("     Command: {$step['command']} " . implode(' ', array_map(fn($k, $v) => is_bool($v) ? $k : "{$k}={$v}", array_keys($step['args'] ?? []), $step['args'] ?? [])));
            }
            return self::SUCCESS;
        }

        $totalSteps = count($steps);
        $this->info("Running AI setup ({$totalSteps} steps)...");
        $this->newLine();

        foreach ($steps as $i => $step) {
            $stepNum = $i + 1;
            $this->info("[{$stepNum}/{$totalSteps}] {$step['label']}");

            if (isset($step['check']) && !$step['check']()) {
                $this->warn("  Skipped: {$step['skip_reason']}");
                $this->newLine();
                continue;
            }

            $exitCode = $this->call($step['command'], $step['args'] ?? []);
            if ($exitCode !== self::SUCCESS) {
                $this->error("  Failed. Stopping setup.");
                $this->newLine();
                $this->line("Fix the issue and re-run: php artisan ai:setup" . ($this->option('skip-ingest') ? '' : ' --skip-ingest'));
                return self::FAILURE;
            }
            $this->info("  Done");
            $this->newLine();
        }

        $this->newLine();
        $this->info('AI setup complete.');

        if (config('ai.debug')) {
            $this->line('Debug mode is ON — check logs for detailed pipeline traces.');
        }

        return self::SUCCESS;
    }

    private function buildSteps(): array
    {
        $steps = [];

        if ($this->option('discover')) {
            $steps[] = [
                'label' => 'Discover entity configurations',
                'command' => 'ai:discover',
            ];
        }

        $steps[] = [
            'label' => 'Validate configuration',
            'command' => 'ai:validate-config',
        ];

        if (!$this->option('skip-ingest')) {
            $ingestArgs = [];
            if ($this->option('fresh')) {
                $ingestArgs['--fresh'] = true;
            }
            if ($this->option('chunk')) {
                $ingestArgs['--chunk'] = $this->option('chunk');
            }

            $steps[] = [
                'label' => 'Ingest entities into Neo4j + Qdrant',
                'command' => 'ai:ingest',
                'args' => $ingestArgs,
                'check' => fn() => !empty(config('entities', [])),
                'skip_reason' => 'No entities configured. Run ai:discover first.',
            ];

            $steps[] = [
                'label' => 'Sync relationships in Neo4j',
                'command' => 'ai:sync-relationships',
            ];

            // The physical doc corpus (file_context.physical_paths -> documentation_chunks)
            // is a separate ingestion path from ai:process-files (DB attachments ->
            // file_chunks); without this step a "successful" setup still leaves every
            // corpus-grounded question unanswerable.
            $steps[] = [
                'label' => 'Ingest physical documentation corpus',
                'command' => 'ai:ingest',
                'args' => array_merge(['--docs' => true], $this->option('fresh') ? ['--fresh' => true] : []),
                'check' => fn() => !empty(config('ai.file_context.physical_paths', [])),
                'skip_reason' => 'No file_context.physical_paths configured.',
            ];
        }

        $steps[] = [
            'label' => 'Index semantic context',
            'command' => 'ai:index-context',
        ];

        $steps[] = [
            'label' => 'Index semantic matching',
            'command' => 'ai:index-semantic',
        ];

        $steps[] = [
            'label' => 'Index scope examples',
            'command' => 'ai:index-scopes',
        ];

        $steps[] = [
            'label' => 'Process files',
            'command' => 'ai:process-files',
        ];

        return $steps;
    }
}
