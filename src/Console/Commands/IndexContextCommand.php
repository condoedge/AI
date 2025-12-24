<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Illuminate\Console\Command;
use Condoedge\Ai\Services\SemanticContextSelector;
use Condoedge\Ai\Services\ScopeSemanticMatcher;

/**
 * Command to index all context for semantic matching
 *
 * This command creates vector embeddings for:
 * - Entity descriptions and aliases
 * - Relationships and their descriptions
 * - Properties and their descriptions
 * - Scope examples and concepts
 *
 * Usage:
 *   php artisan ai:index-context
 *   php artisan ai:index-context --scopes-only   # Only index scopes
 *   php artisan ai:index-context --all           # Index everything
 */
class IndexContextCommand extends Command
{
    protected $signature = 'ai:index-context
                            {--scopes-only : Only index scopes}
                            {--all : Index all context (entities, relationships, scopes)}
                            {--force : Force re-indexing even if already indexed}';

    protected $description = 'Index entity context for semantic matching (reduces token usage)';

    public function handle(
        SemanticContextSelector $contextSelector,
        ScopeSemanticMatcher $scopeMatcher
    ): int {
        $this->info('Indexing context for semantic matching...');
        $this->newLine();

        $entityConfigs = config('entities', []);

        if (empty($entityConfigs)) {
            $this->error('No entity configurations found. Run php artisan ai:discover first.');
            return self::FAILURE;
        }

        $scopesOnly = $this->option('scopes-only');
        $indexAll = $this->option('all') || !$scopesOnly;

        // Count what we're indexing
        $this->displayStats($entityConfigs);
        $this->newLine();

        $results = [];

        // Index scopes (always, unless explicitly skipping)
        $this->output->write('Indexing scopes... ');
        try {
            $scopeResult = $scopeMatcher->indexScopes($entityConfigs);
            $this->info('Done!');
            $results['scopes'] = $scopeResult;
        } catch (\Exception $e) {
            $this->error('Failed: ' . $e->getMessage());
            $results['scopes'] = ['error' => $e->getMessage()];
        }

        // Index full context (entities, relationships, properties)
        if ($indexAll) {
            $this->output->write('Indexing entities & relationships... ');
            try {
                $contextResult = $contextSelector->indexContext($entityConfigs);
                $this->info('Done!');
                $results['context'] = $contextResult;
            } catch (\Exception $e) {
                $this->error('Failed: ' . $e->getMessage());
                $results['context'] = ['error' => $e->getMessage()];
            }
        }

        $this->newLine();
        $this->displayResults($results);

        $this->newLine();
        $this->info('Context indexing complete! Semantic context selection is now enabled.');
        $this->line('The system will now only include relevant context in prompts, reducing token usage.');

        return self::SUCCESS;
    }

    private function displayStats(array $entityConfigs): void
    {
        $entityCount = count($entityConfigs);
        $relationshipCount = 0;
        $scopeCount = 0;
        $propertyCount = 0;

        foreach ($entityConfigs as $config) {
            $relationshipCount += count($config['relationships'] ?? []);
            $propertyCount += count($config['graph']['properties'] ?? []);

            $scopes = $config['metadata']['scopes'] ?? [];
            foreach ($scopes as $scopeName => $scopeConfig) {
                if (!is_numeric($scopeName)) {
                    $scopeCount++;
                }
            }
        }

        $this->info("Found context to index:");
        $this->table(
            ['Type', 'Count'],
            [
                ['Entities', $entityCount],
                ['Relationships', $relationshipCount],
                ['Properties', $propertyCount],
                ['Scopes', $scopeCount],
            ]
        );
    }

    private function displayResults(array $results): void
    {
        $this->info('Indexing Results:');

        $rows = [];

        if (isset($results['scopes'])) {
            $scopes = $results['scopes'];
            $rows[] = [
                'Scopes',
                $scopes['collection'] ?? 'scope_examples',
                $scopes['indexed'] ?? 0,
                count($scopes['errors'] ?? []),
            ];
        }

        if (isset($results['context'])) {
            $context = $results['context'];
            $rows[] = [
                'Context',
                $context['collection'] ?? 'context_index',
                $context['indexed'] ?? 0,
                count($context['errors'] ?? []),
            ];
        }

        $this->table(
            ['Type', 'Collection', 'Points', 'Errors'],
            $rows
        );

        // Show errors if any
        foreach ($results as $type => $result) {
            if (!empty($result['errors'])) {
                $this->newLine();
                $this->warn("Errors in {$type}:");
                foreach ($result['errors'] as $error) {
                    $this->line("  - {$error}");
                }
            }
        }
    }
}
