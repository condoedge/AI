<?php

namespace Condoedge\Ai\Tests;

use Condoedge\Ai\AiServiceProvider;

/**
 * Base Test Case
 *
 * Provides common functionality for all tests
 */
abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Get package providers
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
        ];
    }

    /**
     * Define environment setup
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Set up in-memory SQLite database for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Set up basic test configuration
        $app['config']->set('ai.file_processing.enabled', true);
        $app['config']->set('ai.file_processing.queue', false);
        $app['config']->set('ai.file_processing.collection', 'test_file_chunks');

        $app['config']->set('ai.vector.default', 'qdrant');
        $app['config']->set('ai.vector.qdrant', [
            'host' => env('QDRANT_HOST', 'localhost'),
            'port' => env('QDRANT_PORT', 6333),
            'api_key' => env('QDRANT_API_KEY', null),
        ]);

        $app['config']->set('ai.graph.default', 'neo4j');
        $app['config']->set('ai.graph.neo4j', [
            'enabled' => true,
            'uri' => env('NEO4J_URI', 'bolt://localhost:7687'),
            'username' => env('NEO4J_USERNAME', 'neo4j'),
            'password' => env('NEO4J_PASSWORD', 'password'),
            'database' => env('NEO4J_DATABASE', 'neo4j'),
        ]);
    }

    /**
     * Assert array has keys
     */
    protected function assertArrayHasKeys(array $keys, array $array, string $message = ''): void
    {
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $array, $message ?: "Array missing key: {$key}");
        }
    }

    /**
     * Create a temporary test collection/database name
     */
    protected function getTestCollectionName(string $prefix = 'test'): string
    {
        return $prefix . '_' . uniqid() . '_' . time();
    }
}
