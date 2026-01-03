<?php

namespace Condoedge\Ai\Tests\Unit\Models\Plugins;

use Condoedge\Ai\Models\Plugins\FileProcessingPlugin;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
use Condoedge\Ai\Facades\AI;
use Mockery;
use Orchestra\Testbench\TestCase;

class FileProcessingPluginTest extends TestCase
{
    protected $aiMock;

    /**
     * Don't load the full AI service provider to avoid boot issues
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Set up in-memory SQLite database for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function setUp(): void
    {
        parent::setUp();

        // Register a mock for the AI facade binding
        $this->aiMock = Mockery::mock(\Condoedge\Ai\Services\AiManager::class);
        $this->app->instance('ai', $this->aiMock);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_skips_neo4j_for_non_nodeable_files()
    {
        // Non-Nodeable file from external package
        $file = new class {
            public $id = 123;
            public $name = 'report.pdf';
            public $path = '/tmp/report.pdf';
        };

        // AI facade should NOT be called
        $this->aiMock->shouldReceive('ingest')->never();
        $this->aiMock->shouldReceive('sync')->never();
        $this->aiMock->shouldReceive('remove')->never();

        $plugin = new FileProcessingPlugin(\stdClass::class);

        $method = new \ReflectionMethod($plugin, 'syncToNeo4j');
        $method->setAccessible(true);
        $method->invoke($plugin, $file, 'create');

        // No exception = success
        $this->assertTrue(true);
    }

    /** @test */
    public function it_syncs_to_neo4j_for_nodeable_files()
    {
        $file = Mockery::mock(Nodeable::class);
        $file->id = 456;
        $file->shouldReceive('getId')->andReturn(456);
        $file->shouldReceive('toArray')->andReturn(['id' => 456]);
        $file->shouldReceive('getGraphConfig')->andReturn(
            new GraphConfig('File', ['id', 'name'], [])
        );
        $file->shouldReceive('getVectorConfig')->andReturn(
            new VectorConfig('files', ['name'], ['id'])
        );

        $this->aiMock->shouldReceive('ingest')->once()->with($file)->andReturn([
            'graph_stored' => true,
            'vector_stored' => true,
            'errors' => [],
        ]);

        $plugin = new FileProcessingPlugin(\stdClass::class);

        $method = new \ReflectionMethod($plugin, 'syncToNeo4j');
        $method->setAccessible(true);
        $method->invoke($plugin, $file, 'create');

        $this->assertTrue(true);
    }
}
