<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Services\Discovery\AiConfigEnhancer;
use Condoedge\Ai\Services\Discovery\CodeExplorer;
use Condoedge\Ai\Services\Discovery\DataExplorer;
use Condoedge\Ai\Services\Discovery\LangExplorer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Post-ingestion AI enhancement command.
 *
 * Runs AFTER discover + ingest + index. Uses the full pipeline to:
 * 1. Gather code+data context for each entity
 * 2. Generate test questions using LLM
 * 3. Run questions through ai:test-question
 * 4. Feed results back to LLM for improvement
 * 5. Repeat N times for best config quality
 *
 * Writes config after EACH entity finishes (crash-resilient).
 */
class EnhanceConfigCommand extends Command
{
    protected $signature = 'ai:enhance
                            {--model= : Specific model class to enhance}
                            {--iterations=3 : Number of improvement rounds}
                            {--user=1 : User ID for test questions}
                            {--dry-run : Preview without writing}';

    protected $description = 'Enhance entity configs using AI with iterative test-question feedback loop';

    public function handle(): int
    {
        $configPath = config_path('entities.php');

        if (!File::exists($configPath)) {
            $this->error('config/entities.php not found. Run ai:discover first.');
            return self::FAILURE;
        }

        $configurations = include $configPath;

        if (empty($configurations)) {
            $this->error('No entities found in config/entities.php.');
            return self::FAILURE;
        }

        $userId = $this->option('user');
        $userClass = config('auth.providers.users.model', 'App\\Models\\User');
        $user = $userClass::find($userId);

        if (!$user) {
            $this->error("User {$userId} not found. Use --user=ID.");
            return self::FAILURE;
        }

        auth()->login($user);

        // Detect split structure for proper write-back
        $splitDir = config_path('ai-entities');
        $useSplitFiles = File::isDirectory($splitDir);

        // Build model→category-file map if using split structure
        $modelFileMap = [];
        if ($useSplitFiles) {
            foreach (File::glob($splitDir . '/*.php') as $file) {
                $fileEntities = include $file;
                foreach (array_keys($fileEntities) as $modelClass) {
                    $modelFileMap[$modelClass] = $file;
                }
            }
        }

        $specificModel = $this->option('model');
        if ($specificModel) {
            if (!isset($configurations[$specificModel])) {
                $this->error("Model {$specificModel} not found in config.");
                $this->line('Available models:');
                foreach (array_keys($configurations) as $m) {
                    $this->line("  - {$m}");
                }
                return self::FAILURE;
            }
            $configurations = [$specificModel => $configurations[$specificModel]];
        }

        $iterations = (int) $this->option('iterations');
        $isDryRun = (bool) $this->option('dry-run');

        $this->info("AI Enhancement with {$iterations}-round feedback loop");
        $this->info('Entities: ' . count($configurations));
        $this->info("User: {$user->name} (ID: {$user->id})");
        if ($useSplitFiles) {
            $this->comment('Using split config files in config/ai-entities/');
        }
        if ($isDryRun) {
            $this->comment('DRY RUN — no files will be written');
        }
        $this->newLine();

        // Backup ONCE before any writes
        if (!$isDryRun && $useSplitFiles) {
            $this->backupConfigs($splitDir, $modelFileMap, $configurations);
        }

        $enhancer = app(AiConfigEnhancer::class);
        $codeExplorer = new CodeExplorer();
        $dataExplorer = new DataExplorer();
        $langExplorer = new LangExplorer();

        $enhanced = 0;
        $errors = [];

        foreach ($configurations as $modelClass => $config) {
            $label = class_basename($modelClass);
            $this->info("Enhancing: {$label}");

            try {
                $codeContext = $codeExplorer->explore($modelClass, base_path());

                foreach ($config['graph']['relationships'] ?? [] as $rel) {
                    $relatedModel = $rel['related_model'] ?? null;
                    if ($relatedModel && class_exists($relatedModel)) {
                        $codeContext['related_models'][$relatedModel] =
                            $codeExplorer->readRelatedModel($relatedModel);
                    }
                }

                $dataContext = $dataExplorer->explore($modelClass);
                $langContext = $langExplorer->explore($codeContext['forms'] ?? [], base_path());

                $testRunner = function (string $question) use ($userId): string {
                    $this->line("    Testing: <fg=gray>{$question}</>");

                    $buffer = new BufferedOutput();

                    try {
                        $exitCode = Artisan::call('ai:test-question', [
                            'question' => $question,
                            '--user' => $userId,
                            '--debug' => true,
                        ], $buffer);

                        $output = $buffer->fetch();

                        if ($exitCode !== 0) {
                            return "PIPELINE ERROR (exit code {$exitCode}):\n{$output}";
                        }

                        return $output;
                    } catch (\Throwable $e) {
                        return "ERROR: {$e->getMessage()}";
                    }
                };

                $enhancedConfig = $enhancer->enhanceIteratively(
                    $modelClass,
                    $config,
                    $codeContext,
                    $dataContext,
                    $testRunner,
                    $iterations,
                    $langContext
                );

                // Write immediately after each entity (crash-resilient)
                if (!$isDryRun) {
                    $this->writeEntityConfig($modelClass, $enhancedConfig, $useSplitFiles, $modelFileMap, $configPath);
                    $this->line("  <fg=green>Written:</> {$label}");
                } else {
                    $this->line("  <fg=cyan>DRY RUN:</> {$label} metadata:");
                    $this->line(json_encode($enhancedConfig['metadata'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }

                $enhanced++;
            } catch (\Throwable $e) {
                $this->line("  <fg=red>Failed:</> {$label}: {$e->getMessage()}");
                $errors[$modelClass] = $e->getMessage();
            }

            $this->newLine();
        }

        $this->newLine();
        $this->info("Enhanced: {$enhanced} entities");
        if (!empty($errors)) {
            $this->warn('Errors: ' . count($errors));
            foreach ($errors as $model => $error) {
                $this->line("  <fg=red>x</> " . class_basename($model) . ": {$error}");
            }
        }

        if (!$isDryRun && $enhanced > 0) {
            $this->newLine();
            $this->comment('Next steps:');
            $this->line('  1. Review the enhanced config files');
            $this->line('  2. Re-index: php artisan ai:index-context && php artisan ai:index-scopes');
            $this->line('  3. Validate: php artisan ai:test-question "your question" --user=' . $userId);
        }

        return empty($errors) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Write a single entity's enhanced config back to its file immediately.
     */
    private function writeEntityConfig(
        string $modelClass,
        array $enhancedConfig,
        bool $useSplitFiles,
        array $modelFileMap,
        string $flatConfigPath
    ): void {
        if ($useSplitFiles) {
            $file = $modelFileMap[$modelClass] ?? null;
            if (!$file) {
                $this->warn("  No file mapping for {$modelClass}, skipping write");
                return;
            }

            // Load the full category file, update this entity, write back
            $fileConfigs = include $file;
            $fileConfigs[$modelClass] = $enhancedConfig;

            File::put($file, $this->generateConfigFileContent($fileConfigs));
        } else {
            $allConfigurations = include $flatConfigPath;
            $allConfigurations[$modelClass] = $enhancedConfig;

            File::put($flatConfigPath, $this->generateConfigFileContent($allConfigurations));
        }
    }

    /**
     * Backup config files before any modifications.
     */
    private function backupConfigs(string $splitDir, array $modelFileMap, array $configurations): void
    {
        $timestamp = date('Y-m-d_His');
        $backupDir = config_path("ai-entities-backups/{$timestamp}");

        if (!File::isDirectory($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        // Backup all files that contain entities being enhanced
        $backedUp = [];
        foreach (array_keys($configurations) as $modelClass) {
            $file = $modelFileMap[$modelClass] ?? null;
            if ($file && !in_array($file, $backedUp) && File::exists($file)) {
                File::copy($file, $backupDir . '/' . basename($file));
                $backedUp[] = $file;
            }
        }

        // Backup flat entities.php if it exists and no split files
        $flatConfig = config_path('entities.php');
        if (File::exists($flatConfig) && empty($backedUp)) {
            File::copy($flatConfig, $backupDir . '/entities.php');
            $backedUp[] = $flatConfig;
        }

        $this->comment("Backup: " . count($backedUp) . " file(s) -> config/ai-entities-backups/{$timestamp}/");
    }

    /**
     * Generate valid PHP config file content from an array.
     *
     * Uses short array syntax [] and proper indentation.
     * Handles strings with special characters (parentheses, quotes, etc.) safely.
     */
    private function generateConfigFileContent(array $configurations): string
    {
        $date = date('Y-m-d H:i:s');

        $arrayCode = $this->exportArray($configurations, 0);

        return <<<PHP
<?php

// Entity Configuration
// Enhanced by: php artisan ai:enhance
// Enhanced at: {$date}

return {$arrayCode};

PHP;
    }

    /**
     * Export a PHP array to string with short array syntax.
     * Unlike var_export + regex, this handles strings with special characters safely.
     */
    private function exportArray(mixed $value, int $indent): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return var_export($value, true);
        }

        if (!is_array($value)) {
            return var_export($value, true);
        }

        if (empty($value)) {
            return '[]';
        }

        $isSequential = array_is_list($value);
        $pad = str_repeat('    ', $indent + 1);
        $closePad = str_repeat('    ', $indent);
        $items = [];

        foreach ($value as $key => $val) {
            $exported = $this->exportArray($val, $indent + 1);

            if ($isSequential) {
                $items[] = "{$pad}{$exported}";
            } else {
                $keyStr = var_export($key, true);
                $items[] = "{$pad}{$keyStr} => {$exported}";
            }
        }

        return "[\n" . implode(",\n", $items) . ",\n{$closePad}]";
    }
}
