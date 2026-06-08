<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat\UseCases;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\Exporter\ExportConversationServiceInterface;
use Condoedge\Ai\Services\Chat\Exporter\ExporterConversationFactory;
use Condoedge\Ai\Services\Chat\Results\ExportResult;
use Condoedge\Ai\Services\Chat\UseCases\ExportConversationUseCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;
use Mockery;

class ExportConversationUseCaseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Don't load the full AI service provider to avoid API key requirements
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../../database/migrations');
    }

    private function fakeExporter(string $content, string $mime, string $extension): ExportConversationServiceInterface
    {
        return new class ($content, $mime, $extension) implements ExportConversationServiceInterface {
            public function __construct(
                private string $content,
                private string $mime,
                private string $extension,
            ) {
            }

            public function export($conversation)
            {
                return $this->content;
            }

            public function getMimeType(): string
            {
                return $this->mime;
            }

            public function getFileExtension(): string
            {
                return $this->extension;
            }
        };
    }

    private function useCaseWithExporter(ExportConversationServiceInterface $exporter, string $format = 'md'): ExportConversationUseCase
    {
        $factory = Mockery::mock(ExporterConversationFactory::class);
        $factory->shouldReceive('make')->with($format)->andReturn($exporter);

        return new ExportConversationUseCase($factory);
    }

    /** @test */
    public function it_returns_an_export_result_with_the_exporters_real_mime_and_extension(): void
    {
        $user = (object) ['id' => 7];
        $conversation = AiConversation::create(['user_id' => 7, 'title' => 'My Chat']);

        $exporter = $this->fakeExporter('BODY-CONTENT', 'application/pdf', 'pdf');
        $useCase = $this->useCaseWithExporter($exporter, 'pdf');

        $result = $useCase->execute($conversation->id, 'pdf', $user);

        $this->assertInstanceOf(ExportResult::class, $result);
        $this->assertEquals('BODY-CONTENT', $result->content);
        $this->assertEquals('application/pdf', $result->mime);
        $this->assertEquals("conversation-{$conversation->id}.pdf", $result->filename);
    }

    /** @test */
    public function it_uses_the_markdown_mime_and_extension_for_the_md_exporter(): void
    {
        $user = (object) ['id' => 7];
        $conversation = AiConversation::create(['user_id' => 7, 'title' => 'My Chat']);

        $exporter = $this->fakeExporter('# My Chat', 'text/markdown', 'md');
        $useCase = $this->useCaseWithExporter($exporter, 'md');

        $result = $useCase->execute($conversation->id, 'md', $user);

        $this->assertEquals('text/markdown', $result->mime);
        $this->assertEquals("conversation-{$conversation->id}.md", $result->filename);
    }

    /** @test */
    public function it_throws_when_the_conversation_is_not_owned_by_the_user(): void
    {
        $owner = (object) ['id' => 7];
        $intruder = (object) ['id' => 99];
        $conversation = AiConversation::create(['user_id' => $owner->id, 'title' => 'Private']);

        $exporter = $this->fakeExporter('x', 'text/markdown', 'md');
        $useCase = $this->useCaseWithExporter($exporter);

        $this->expectException(ModelNotFoundException::class);

        $useCase->execute($conversation->id, 'md', $intruder);
    }

    /** @test */
    public function it_throws_when_the_conversation_does_not_exist(): void
    {
        $user = (object) ['id' => 7];

        $exporter = $this->fakeExporter('x', 'text/markdown', 'md');
        $useCase = $this->useCaseWithExporter($exporter);

        $this->expectException(ModelNotFoundException::class);

        $useCase->execute(999999, 'md', $user);
    }
}
