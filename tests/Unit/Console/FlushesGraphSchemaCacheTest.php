<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Console;

use Condoedge\Ai\Console\Commands\Concerns\FlushesGraphSchemaCache;
use Condoedge\Ai\Console\Commands\IndexContextCommand;
use Condoedge\Ai\Console\Commands\IndexScopesCommand;
use Condoedge\Ai\Console\Commands\IndexSemanticCommand;
use Condoedge\Ai\Console\Commands\IngestEntitiesCommand;
use Condoedge\Ai\Console\Commands\ProcessFilesCommand;
use Condoedge\Ai\Console\Commands\SetupCommand;
use Condoedge\Ai\Console\Commands\SyncRelationshipsCommand;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

/**
 * Task 5.3 — the graph-changing commands flush the cached schema when they finish.
 */
class FlushesGraphSchemaCacheTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Booting artisan resolves every registered ai:* command (and its providers).
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
    }

    public static function graphChangingCommands(): array
    {
        return [
            'ai:ingest' => [IngestEntitiesCommand::class],
            'ai:sync-relationships' => [SyncRelationshipsCommand::class],
            'ai:setup' => [SetupCommand::class],
            'ai:index-context' => [IndexContextCommand::class],
            'ai:index-scopes' => [IndexScopesCommand::class],
            'ai:index-semantic' => [IndexSemanticCommand::class],
            'ai:process-files' => [ProcessFilesCommand::class],
        ];
    }

    /** @dataProvider graphChangingCommands */
    public function test_command_flushes_the_schema_cache(string $class): void
    {
        $this->assertContains(FlushesGraphSchemaCache::class, class_uses($class));
    }

    public function test_the_cache_is_flushed_after_the_command_runs_even_when_it_fails(): void
    {
        config(['ai.graph.neo4j.database' => 'neo4j']);
        $this->app[Kernel::class]->registerCommand(new class extends Command {
            use FlushesGraphSchemaCache;

            protected $signature = 'ai:test-flush-probe';

            public function handle(): int
            {
                return self::FAILURE;
            }
        });

        Cache::forever('ai:schema:v1:neo4j', ['labels' => ['Stale']]);

        $this->artisan('ai:test-flush-probe')->assertExitCode(1);

        $this->assertFalse(Cache::has('ai:schema:v1:neo4j'));
    }
}
