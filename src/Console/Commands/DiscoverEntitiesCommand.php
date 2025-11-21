<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * DiscoverEntitiesCommand
 *
 * Discovers Nodeable entities and generates config/entities.php configuration.
 * Scans all models implementing Nodeable interface, runs auto-discovery,
 * and writes results to config file for review and editing.
 *
 * Usage:
 *   php artisan ai:discover
 *   php artisan ai:discover --model=App\\Models\\Customer
 *   php artisan ai:discover --force  (overwrite existing config)
 *
 * @package Condoedge\Ai\Console\Commands
 */
class DiscoverEntitiesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:discover
                            {--model= : Specific model class to discover}
                            {--force : Overwrite existing configuration}
                            {--dry-run : Show what would be generated without writing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Discover Nodeable entities and generate config/entities.php';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private EntityAutoDiscovery $discovery
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
        $this->info('🔍 Discovering Nodeable entities...');
        $this->newLine();

        // Get models to discover
        $models = $this->option('model')
            ? [$this->option('model')]
            : $this->findNodeableModels();

        if (empty($models)) {
            $this->warn('No Nodeable models found.');
            return self::SUCCESS;
        }

        $this->info('Found ' . count($models) . ' Nodeable model(s)');
        $this->newLine();

        // Discover each model
        $configurations = [];
        $errors = [];

        foreach ($models as $modelClass) {
            try {
                $this->line("Discovering: <fg=cyan>{$modelClass}</>");

                $config = $this->discovery->discover($modelClass);

                // Only include if has graph or vector config
                if (!empty($config['graph']) || !empty($config['vector'])) {
                    $configurations[$modelClass] = $config;
                    $this->info("  ✓ Discovered successfully");
                } else {
                    $this->comment("  ⊘ Skipped (no configuration found)");
                }
            } catch (\Throwable $e) {
                $errors[$modelClass] = $e->getMessage();
                $this->error("  ✗ Error: {$e->getMessage()}");
            }
        }

        $this->newLine();

        if (empty($configurations)) {
            $this->warn('No configurations to write.');
            return self::SUCCESS;
        }

        // Dry run - just show what would be generated
        if ($this->option('dry-run')) {
            $this->info('DRY RUN - Configuration that would be generated:');
            $this->newLine();
            $this->line($this->generateConfigFileContent($configurations));
            return self::SUCCESS;
        }

        // Check if config file exists
        $configPath = config_path('entities.php');

        if (File::exists($configPath) && !$this->option('force')) {
            if (!$this->confirm('config/entities.php already exists. Merge with existing config?', true)) {
                $this->warn('Discovery cancelled.');
                return self::SUCCESS;
            }

            // Merge with existing config
            $existingConfig = include $configPath;
            $configurations = array_merge($existingConfig, $configurations);
        }

        // Write config file
        $content = $this->generateConfigFileContent($configurations);
        File::put($configPath, $content);

        $this->newLine();
        $this->info("✓ Configuration written to config/entities.php");
        $this->info("✓ Discovered " . count($configurations) . " entities");

        if (!empty($errors)) {
            $this->newLine();
            $this->warn("⚠ {" . count($errors) . "} errors occurred:");
            foreach ($errors as $model => $error) {
                $this->line("  <fg=red>✗</> {$model}: {$error}");
            }
        }

        $this->newLine();
        $this->comment('Next steps:');
        $this->line('  1. Review config/entities.php');
        $this->line('  2. Customize as needed (labels, properties, relationships)');
        $this->line('  3. Re-run ai:discover to update configurations');

        return self::SUCCESS;
    }

    /**
     * Find all Nodeable models in the application
     *
     * @return array<string> Model class names
     */
    private function findNodeableModels(): array
    {
        $models = [];

        // Search in app/Models directory
        $modelsPath = app_path('Models');

        if (!File::isDirectory($modelsPath)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($modelsPath)->name('*.php');

        foreach ($finder as $file) {
            $namespace = $this->getNamespaceFromFile($file->getPathname());
            $class = $namespace . '\\' . $file->getBasename('.php');

            if (class_exists($class) && in_array(Nodeable::class, class_implements($class) ?: [])) {
                $models[] = $class;
            }
        }

        return $models;
    }

    /**
     * Extract namespace from PHP file
     *
     * @param string $filePath Path to PHP file
     * @return string Namespace
     */
    private function getNamespaceFromFile(string $filePath): string
    {
        $contents = File::get($filePath);

        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            return $matches[1];
        }

        return 'App\\Models';
    }

    /**
     * Generate config file content
     *
     * @param array $configurations Entity configurations
     * @return string PHP config file content
     */
    private function generateConfigFileContent(array $configurations): string
    {
        $export = var_export($configurations, true);

        // Clean up var_export formatting
        $export = preg_replace('/=>\s+\n\s+array \(/', '=> [', $export);
        $export = preg_replace('/array \(/', '[', $export);
        $export = str_replace(')', ']', $export);
        $export = preg_replace('/\s+\]/', ']', $export);

        // Improve array formatting
        $lines = explode("\n", $export);
        $formatted = [];

        foreach ($lines as $line) {
            // Add proper indentation
            $indent = strlen($line) - strlen(ltrim($line));
            $formatted[] = str_repeat(' ', $indent) . ltrim($line);
        }

        $export = implode("\n", $formatted);

        return <<<PHP
<?php

declare(strict_types=1);

/**
 * Entity Configuration
 *
 * This file was auto-generated by: php artisan ai:discover
 * Generated at: {date('Y-m-d H:i:s')}
 *
 * IMPORTANT: This is a static configuration file.
 * - Review and customize as needed
 * - Add manual overrides for fine-tuning
 * - Re-run ai:discover to regenerate (will merge with existing)
 *
 * Configuration priority (highest to lowest):
 * 1. nodeableConfig() method on model
 * 2. This file (config/entities.php)
 * 3. Runtime auto-discovery (fallback only)
 */

return {$export};

PHP;
    }
}
