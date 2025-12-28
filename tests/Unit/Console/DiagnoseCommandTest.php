<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Console;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class DiagnoseCommandTest extends TestCase
{
    /**
     * Define environment setup for these tests.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Set up minimal API keys for testing (command should work even with placeholder keys)
        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('ai.embedding.default', 'openai');
        $app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
    }

    public function setUp(): void
    {
        parent::setUp();

        // Mock the GraphStoreInterface to avoid needing Neo4j
        $graphStore = Mockery::mock(GraphStoreInterface::class)->shouldIgnoreMissing();
        $graphStore->shouldReceive('getSchema')->andReturn(['labels' => ['TestLabel']]);
        $this->app->instance(GraphStoreInterface::class, $graphStore);

        // Mock the VectorStoreInterface to avoid needing Qdrant
        $vectorStore = Mockery::mock(VectorStoreInterface::class)->shouldIgnoreMissing();
        $vectorStore->shouldReceive('listCollections')->andReturn(['test_collection']);
        $this->app->instance(VectorStoreInterface::class, $vectorStore);
    }

    /** @test */
    public function it_checks_neo4j_connectivity(): void
    {
        // Test that the command checks and reports Neo4j status
        $this->artisan('ai:diagnose')
            ->expectsOutputToContain('Neo4j')
            ->assertExitCode(1); // Will fail because entities.php doesn't exist in test env
    }

    /** @test */
    public function it_checks_qdrant_connectivity(): void
    {
        // Test that the command checks and reports Qdrant status
        $this->artisan('ai:diagnose')
            ->expectsOutputToContain('Qdrant')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_checks_llm_api_key(): void
    {
        // Test that the command checks and reports LLM status
        $this->artisan('ai:diagnose')
            ->expectsOutputToContain('LLM')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_checks_entities_config_exists(): void
    {
        // Test that the command checks and reports entities.php status
        $this->artisan('ai:diagnose')
            ->expectsOutputToContain('entities.php')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_passes_when_all_checks_succeed(): void
    {
        // Create a temporary entities.php that will be found
        $entitiesPath = config_path('entities.php');
        $entitiesDir = dirname($entitiesPath);

        if (!is_dir($entitiesDir)) {
            mkdir($entitiesDir, 0755, true);
        }

        file_put_contents($entitiesPath, '<?php return [];');

        try {
            $this->artisan('ai:diagnose')
                ->expectsOutputToContain('All checks passed')
                ->assertSuccessful();
        } finally {
            // Clean up
            if (file_exists($entitiesPath)) {
                unlink($entitiesPath);
            }
        }
    }

    /** @test */
    public function it_reports_failure_when_neo4j_connection_fails(): void
    {
        $graphStore = Mockery::mock(GraphStoreInterface::class);
        $graphStore->shouldReceive('getSchema')
            ->andThrow(new \RuntimeException('Connection refused'));
        $this->app->instance(GraphStoreInterface::class, $graphStore);

        $this->artisan('ai:diagnose')
            ->expectsOutputToContain('Connection failed')
            ->assertExitCode(1);
    }
}
