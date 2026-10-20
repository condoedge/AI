<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Console\Commands\Concerns\FlushesGraphSchemaCache;
use Illuminate\Console\Command;
use Condoedge\Ai\Services\SemanticIndexer;

/**
 * IndexSemanticCommand
 *
 * Builds the vector store index for semantic entity matching.
 * Embeds entity names, aliases and descriptions to enable
 * fuzzy/semantic search instead of hardcoded string matching.
 *
 * Usage:
 * ```bash
 * # Rebuild the index
 * php artisan ai:index-semantic --rebuild
 *
 * # Check index status
 * php artisan ai:index-semantic --check
 * ```
 *
 * Collection created:
 * - semantic_entities: Entity names, aliases, descriptions
 *
 * @package Condoedge\Ai\Console\Commands
 */
class IndexSemanticCommand extends Command
{
    use FlushesGraphSchemaCache;

    /**
     * Command signature
     *
     * @var string
     */
    protected $signature = 'ai:index-semantic
                            {--rebuild : Rebuild the index (deletes existing)}
                            {--entities : Index entities (the only index; kept for compatibility)}
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
        if ($this->option('check')) {
            return $this->checkIndexes($indexer);
        }

        $rebuild = $this->option('rebuild');

        $this->info('🔍 Building semantic indexes...');
        $this->newLine();

        $this->line('📦 Indexing entities...');
        try {
            $result = $indexer->indexEntities(rebuild: $rebuild);

            $this->info("  ✓ Indexed {$result['total_entities']} entities");
            $this->line("    Total points: {$result['total_points']}");
            $this->line("    Inserted: {$result['inserted']}");
        } catch (\Exception $e) {
            $this->error("  ✗ Failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
        $this->newLine();

        $this->info('✅ Indexing complete!');
        $this->newLine();

        $this->line('<comment>Next steps:</comment>');
        $this->line('  1. Test semantic matching:');
        $this->line('     php artisan ai:eval --ask="Show all clients"   (dev: requires condoedge/ai-eval)');
        $this->newLine();
        $this->line('  2. Check status anytime:');
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
