<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Illuminate\Console\Command;
use Condoedge\Ai\Services\ScopeSemanticMatcher;

/**
 * Command to index scope examples for semantic matching
 *
 * This command creates vector embeddings for all scope examples and concepts,
 * enabling semantic matching when users ask questions.
 *
 * Usage:
 *   php artisan ai:index-scopes
 *   php artisan ai:index-scopes --force    # Re-index even if already indexed
 */
class IndexScopesCommand extends Command
{
    protected $signature = 'ai:index-scopes
                            {--force : Force re-indexing even if collection exists}';

    protected $description = 'Index scope examples and concepts for semantic matching';

    public function handle(ScopeSemanticMatcher $matcher): int
    {
        $this->info('Indexing scope examples for semantic matching...');
        $this->newLine();

        // Load entity configs
        $entityConfigs = config('entities', []);

        if (empty($entityConfigs)) {
            $this->error('No entity configurations found. Run php artisan ai:discover first.');
            return self::FAILURE;
        }

        // Count scopes
        $totalScopes = 0;
        $totalExamples = 0;

        foreach ($entityConfigs as $entityName => $config) {
            $scopes = $config['metadata']['scopes'] ?? [];
            foreach ($scopes as $scopeName => $scopeConfig) {
                if (is_numeric($scopeName)) {
                    $this->warn("  Skipping malformed scope (numeric key) in {$entityName}");
                    continue;
                }
                $totalScopes++;
                $totalExamples += count($scopeConfig['examples'] ?? []);
                if (!empty($scopeConfig['concept'])) {
                    $totalExamples++;
                }
            }
        }

        $this->info("Found {$totalScopes} scopes with {$totalExamples} examples/concepts to index");
        $this->newLine();

        // Index scopes
        $this->output->write('Indexing... ');

        try {
            $result = $matcher->indexScopes($entityConfigs);

            $this->info('Done!');
            $this->newLine();

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Collection', $result['collection']],
                    ['Points indexed', $result['indexed']],
                    ['Errors', count($result['errors'])],
                ]
            );

            if (!empty($result['errors'])) {
                $this->newLine();
                $this->warn('Errors:');
                foreach ($result['errors'] as $error) {
                    $this->line("  - {$error}");
                }
            }

            $this->newLine();
            $this->info('Scope indexing complete! Semantic matching is now enabled.');

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to index scopes: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
