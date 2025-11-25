<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Illuminate\Console\Command;
use Condoedge\Ai\Services\SemanticIndexer;

/**
 * IndexSemanticCommand
 *
 * Builds vector store indexes for semantic matching.
 * Creates collections for entities, scopes, and templates with embeddings
 * to enable fuzzy/semantic search instead of hardcoded string matching.
 *
 * Usage:
 * ```bash
 * # Rebuild all indexes
 * php artisan ai:index-semantic --rebuild
 *
 * # Index specific types
 * php artisan ai:index-semantic --entities
 * php artisan ai:index-semantic --scopes
 * php artisan ai:index-semantic --templates
 *
 * # Check index status
 * php artisan ai:index-semantic --check
 * ```
 *
 * Collections Created:
 * - semantic_entities: Entity names, aliases, descriptions
 * - semantic_scopes: Scope names, descriptions, concepts
 * - semantic_templates: Query template descriptions
 *
 * @package Condoedge\Ai\Console\Commands
 */
class IndexSemanticCommand extends Command
{
    /**
     * Command signature
     *
     * @var string
     */
    protected $signature = 'ai:index-semantic
                            {--rebuild : Rebuild all indexes (deletes existing)}
                            {--entities : Index entities only}
                            {--scopes : Index scopes only}
                            {--templates : Index templates only}
                            {--check : Check index status}';

    /**
     * Command description
     *
     * @var string
     */
    protected $description = 'Build semantic indexes for fuzzy matching';

    /**
     * Execute the command
     *
     * @param SemanticIndexer $indexer Semantic indexer service
     * @return int Exit code
     */
    public function handle(SemanticIndexer $indexer): int
    {
        // Check mode
        if ($this->option('check')) {
            return $this->checkIndexes($indexer);
        }

        $rebuild = $this->option('rebuild');
        $entities = $this->option('entities');
        $scopes = $this->option('scopes');
        $templates = $this->option('templates');

        // If no specific type selected, do all
        $indexAll = !$entities && !$scopes && !$templates;

        $this->info('🔍 Building semantic indexes...');
        $this->newLine();

        $results = [];

        // Index entities
        if ($indexAll || $entities) {
            $this->line('📦 Indexing entities...');
            try {
                $result = $indexer->indexEntities(rebuild: $rebuild);
                $results['entities'] = $result;

                $this->info("  ✓ Indexed {$result['total_entities']} entities");
                $this->line("    Total points: {$result['total_points']}");
                $this->line("    Inserted: {$result['inserted']}");
            } catch (\Exception $e) {
                $this->error("  ✗ Failed: {$e->getMessage()}");
                return Command::FAILURE;
            }
            $this->newLine();
        }

        // Index scopes
        if ($indexAll || $scopes) {
            $this->line('🎯 Indexing scopes...');
            try {
                $result = $indexer->indexScopes(rebuild: $rebuild);
                $results['scopes'] = $result;

                $this->info("  ✓ Indexed {$result['total_scopes']} scopes");
                $this->line("    Total points: {$result['total_points']}");
                $this->line("    Inserted: {$result['inserted']}");
            } catch (\Exception $e) {
                $this->error("  ✗ Failed: {$e->getMessage()}");
                return Command::FAILURE;
            }
            $this->newLine();
        }

        // Index templates
        if ($indexAll || $templates) {
            $this->line('📋 Indexing templates...');
            try {
                // Load templates from config
                $templateConfig = config('ai.query_generation.templates', []);

                if (empty($templateConfig)) {
                    $this->warn('  ⚠ No templates found in config');
                } else {
                    $result = $indexer->indexTemplates($templateConfig, rebuild: $rebuild);
                    $results['templates'] = $result;

                    $this->info("  ✓ Indexed {$result['total_templates']} templates");
                    $this->line("    Total points: {$result['total_points']}");
                    $this->line("    Inserted: {$result['inserted']}");
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Failed: {$e->getMessage()}");
                return Command::FAILURE;
            }
            $this->newLine();
        }

        // Summary
        $this->info('✅ Indexing complete!');
        $this->newLine();

        // Display next steps
        $this->line('<comment>Next steps:</comment>');
        $this->line('  1. Test semantic matching:');
        $this->line('     php artisan ai:test-semantic "Show all clients"');
        $this->newLine();
        $this->line('  2. Enable in .env:');
        $this->line('     AI_SEMANTIC_MATCHING=true');
        $this->newLine();
        $this->line('  3. Check status anytime:');
        $this->line('     php artisan ai:index-semantic --check');

        return Command::SUCCESS;
    }

    /**
     * Check index status
     *
     * @param SemanticIndexer $indexer Semantic indexer service
     * @return int Exit code
     */
    private function checkIndexes(SemanticIndexer $indexer): int
    {
        $this->info('🔍 Checking semantic index status...');
        $this->newLine();

        $status = $indexer->checkCollections();

        $rows = [];
        foreach ($status as $type => $exists) {
            $collection = \Condoedge\Ai\Services\SemanticIndexer::getCollectionNames()[$type];
            $rows[] = [
                ucfirst($type),
                $collection,
                $exists ? '<info>✓ Exists</info>' : '<error>✗ Missing</error>',
            ];
        }

        $this->table(['Type', 'Collection', 'Status'], $rows);

        // Check configuration
        $this->newLine();
        $this->line('<comment>Configuration:</comment>');

        $config = config('ai.semantic_matching');
        $enabled = $config['enabled'] ?? false;
        $fallback = $config['fallback_to_exact'] ?? false;

        $this->line('  Semantic matching: ' . ($enabled ? '<info>Enabled</info>' : '<error>Disabled</error>'));
        $this->line('  Fallback to exact: ' . ($fallback ? '<info>Yes</info>' : '<comment>No</comment>'));

        $this->newLine();
        $this->line('<comment>Thresholds:</comment>');
        $thresholds = $config['thresholds'] ?? [];
        foreach ($thresholds as $key => $value) {
            $this->line("  " . str_replace('_', ' ', ucfirst($key)) . ": {$value}");
        }

        // Recommendations
        $missing = array_filter($status, fn($exists) => !$exists);

        if (!empty($missing)) {
            $this->newLine();
            $this->warn('⚠ Some indexes are missing. Run:');
            $this->line('  php artisan ai:index-semantic --rebuild');
        } else {
            $this->newLine();
            $this->info('✅ All indexes are ready!');
        }

        return Command::SUCCESS;
    }
}
