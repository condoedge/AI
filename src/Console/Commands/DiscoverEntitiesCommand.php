<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * DiscoverEntitiesCommand
 *
 * Discovers Nodeable entities and generates config/entities.php configuration.
 * This is the ONLY way to generate entity configuration - there is no runtime
 * auto-discovery. Run this command during deployment or after model changes.
 *
 * Usage:
 *   php artisan ai:discover
 *   php artisan ai:discover --model=App\\Models\\Customer
 *   php artisan ai:discover --force  (overwrite existing config)
 *   php artisan ai:discover --dry-run (preview without writing)
 *
 * @package Condoedge\Ai\Console\Commands
 */
class DiscoverEntitiesCommand extends Command
{
    protected $signature = 'ai:discover
                            {--model= : Specific model class to discover}
                            {--force : Overwrite existing configuration}
                            {--dry-run : Show what would be generated without writing}';

    protected $description = 'Discover Nodeable entities and generate config/entities.php';

    public function __construct(
        private EntityAutoDiscovery $discovery
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Discovering Nodeable entities...');
        $this->newLine();

        // Run discovery through the service (single source of truth)
        $result = $this->discovery->discoverAll(
            specificModel: $this->option('model')
        );

        $configurations = $result['configurations'];
        $errors = $result['errors'];
        $stats = $result['stats'];
        $inheritance = $result['inheritance'] ?? [];

        // Display stats
        $this->info("Found {$stats['total_models_found']} Nodeable model(s)");

        if ($stats['child_models_merged'] > 0) {
            $this->comment("  ({$stats['child_models_merged']} child models merged into parents)");
        }

        $this->newLine();

        // Display discovered models
        foreach ($configurations as $modelClass => $config) {
            $this->line("Discovered: <fg=cyan>{$modelClass}</>");
            $this->displayConfig($config);

            // Show merged children if any
            if (isset($inheritance[$modelClass])) {
                foreach ($inheritance[$modelClass]['children'] as $child) {
                    $this->comment("    Merged: " . class_basename($child) . " (child model)");
                }
            }
        }

        // Display errors
        foreach ($errors as $modelClass => $error) {
            $this->error("  Error in {$modelClass}: {$error}");
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

        // Write config file
        $configPath = config_path('entities.php');

        if (File::exists($configPath) && !$this->option('force')) {
            if (!$this->confirm('config/entities.php already exists. Merge with existing config?', true)) {
                $this->warn('Discovery cancelled.');
                return self::SUCCESS;
            }

            $existingConfig = include $configPath;
            // Deep merge: discovered values fill in gaps, but user customizations are preserved
            $configurations = $this->mergeConfigurations($existingConfig, $configurations);
        }

        $content = $this->generateConfigFileContent($configurations);
        File::put($configPath, $content);

        $this->newLine();
        $this->info("Configuration written to config/entities.php");
        $this->info("Discovered {$stats['configurations_generated']} entities");

        if (!empty($errors)) {
            $this->newLine();
            $this->warn(count($errors) . " errors occurred:");
            foreach ($errors as $model => $error) {
                $this->line("  <fg=red>x</> {$model}: {$error}");
            }
        }

        $this->newLine();
        $this->comment('Next steps:');
        $this->line('  1. Review config/entities.php');
        $this->line('  2. Customize as needed (labels, properties, relationships)');
        $this->line('  3. Run php artisan ai:ingest to populate stores');

        return self::SUCCESS;
    }

    private function displayConfig(array $config): void
    {
        if (!empty($config['graph']['label'])) {
            $this->line("    Graph: <fg=green>{$config['graph']['label']}</>");
        }

        if (!empty($config['vector']['collection'])) {
            $this->line("    Vector: <fg=green>{$config['vector']['collection']}</>");
        }

        if (!empty($config['metadata']['aliases'])) {
            $aliases = implode(', ', array_slice($config['metadata']['aliases'], 0, 5));
            $more = count($config['metadata']['aliases']) > 5
                ? ' (+' . (count($config['metadata']['aliases']) - 5) . ' more)'
                : '';
            $this->line("    Aliases: <fg=green>{$aliases}{$more}</>");
        }

        if (!empty($config['metadata']['scopes'])) {
            $scopeCount = count($config['metadata']['scopes']);
            $this->line("    Scopes: <fg=green>{$scopeCount} discovered</>");
        }

        if (!empty($config['metadata']['child_models'])) {
            $children = array_map('class_basename', $config['metadata']['child_models']);
            $this->line("    Children: <fg=yellow>" . implode(', ', $children) . "</>");
        }

        // Display security team_path
        if (!empty($config['security']['team_path'])) {
            $teamPath = $config['security']['team_path'];
            if (is_array($teamPath)) {
                $teamPathDisplay = "[rel: {$teamPath['rel']}, target: {$teamPath['target']}]";
            } else {
                $teamPathDisplay = $teamPath;
            }
            $this->line("    Team Path: <fg=magenta>{$teamPathDisplay}</>");
        }
    }

    /**
     * Merge configurations preserving user customizations
     *
     * Discovery fills in gaps, but user-defined values take precedence.
     * This ensures custom descriptions, team_path overrides, etc. are preserved.
     *
     * @param array $existing Existing user configuration
     * @param array $discovered Newly discovered configuration
     * @return array Merged configuration
     */
    private function mergeConfigurations(array $existing, array $discovered): array
    {
        $merged = $existing;

        foreach ($discovered as $modelClass => $config) {
            if (!isset($merged[$modelClass])) {
                // New model - add entirely
                $merged[$modelClass] = $config;
                continue;
            }

            // Existing model - deep merge, preserving user values
            $merged[$modelClass] = $this->deepMergePreserveExisting(
                $merged[$modelClass],
                $config
            );
        }

        return $merged;
    }

    /**
     * Deep merge where existing (user) values take precedence
     *
     * - If existing has a value, keep it (user customization)
     * - If existing is null/empty but discovered has value, use discovered
     * - Arrays are recursively merged
     *
     * @param array $existing Existing user config
     * @param array $discovered Discovered config
     * @return array Merged config
     */
    private function deepMergePreserveExisting(array $existing, array $discovered): array
    {
        foreach ($discovered as $key => $value) {
            // Key doesn't exist in existing - add it
            if (!array_key_exists($key, $existing)) {
                $existing[$key] = $value;
                continue;
            }

            // Both are arrays - recurse
            if (is_array($value) && is_array($existing[$key])) {
                $existing[$key] = $this->deepMergePreserveExisting($existing[$key], $value);
                continue;
            }

            // Existing has null/empty but discovered has value - use discovered
            if (empty($existing[$key]) && !empty($value)) {
                $existing[$key] = $value;
            }
            // Otherwise keep existing (user customization preserved)
        }

        return $existing;
    }

    private function generateConfigFileContent(array $configurations): string
    {
        $export = var_export($configurations, true);

        // Clean up var_export formatting
        $export = preg_replace('/=>\s+\n\s+array \(/', '=> [', $export);
        $export = preg_replace('/array \(/', '[', $export);
        $export = str_replace(')', ']', $export);
        $export = preg_replace('/\s+\]/', ']', $export);

        $lines = explode("\n", $export);
        $formatted = [];

        foreach ($lines as $line) {
            $indent = strlen($line) - strlen(ltrim($line));
            $formatted[] = str_repeat(' ', $indent) . ltrim($line);
        }

        $export = implode("\n", $formatted);
        $date = date('Y-m-d H:i:s');

        return <<<PHP
<?php

declare(strict_types=1);

/**
 * Entity Configuration
 *
 * Generated by: php artisan ai:discover
 * Generated at: {$date}
 *
 * This is the SOURCE OF TRUTH for entity configuration.
 * There is no runtime auto-discovery - this file must exist.
 *
 * To regenerate: php artisan ai:discover --force
 * To customize: Edit this file directly or use nodeableConfig() on models
 */

return {$export};

PHP;
    }
}
