<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat\Exporter;

use Condoedge\Ai\Services\Chat\Exporter\ExportConversationMdService;
use Condoedge\Ai\Services\Chat\Exporter\ExportConversationServiceInterface;
use Condoedge\Ai\Services\Chat\Exporter\ExporterConversationFactory;
use Orchestra\Testbench\TestCase;

/**
 * A fake exporter whose constructor REQUIRES a dependency, so it can only be
 * instantiated through the container (app()->make), never via `new $class()`.
 */
class FakeDiExporter implements ExportConversationServiceInterface
{
    public function __construct(public FakeDiDependency $dependency)
    {
    }

    public function export($conversation)
    {
        return 'fake-export';
    }

    public function getMimeType(): string
    {
        return 'application/x-fake';
    }

    public function getFileExtension(): string
    {
        return 'fake';
    }
}

class FakeDiDependency
{
    public string $marker = 'injected';
}

class ExporterConversationFactoryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    /** @test */
    public function it_resolves_exporters_through_the_container_so_they_receive_dependency_injection(): void
    {
        $factory = new ExporterConversationFactory();
        $factory->registerExporter('fake', FakeDiExporter::class);

        // If the factory used `new $class()` this would fatal: FakeDiExporter
        // requires a FakeDiDependency the container must auto-wire.
        $exporter = $factory->make('fake');

        $this->assertInstanceOf(FakeDiExporter::class, $exporter);
        $this->assertInstanceOf(FakeDiDependency::class, $exporter->dependency);
        $this->assertEquals('injected', $exporter->dependency->marker);
    }

    /** @test */
    public function it_returns_the_exact_instance_bound_in_the_container(): void
    {
        $bound = new FakeDiExporter(new FakeDiDependency());
        $this->app->instance(FakeDiExporter::class, $bound);

        $factory = new ExporterConversationFactory();
        $factory->registerExporter('fake', FakeDiExporter::class);

        $resolved = $factory->make('fake');

        $this->assertSame($bound, $resolved);
    }

    /** @test */
    public function it_throws_for_unsupported_types(): void
    {
        $factory = new ExporterConversationFactory();

        $this->expectException(\InvalidArgumentException::class);

        $factory->make('nope');
    }
}
