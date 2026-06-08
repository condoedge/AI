<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FilenameExtractor;
use Condoedge\Ai\Services\FileSearchService;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;

/**
 * Isolation tests for FileContextProvider sub-searches (Task B2).
 *
 * A failure in the filename sub-search must NOT abort the whole method — content
 * results should still be returned (and vice versa).
 */
class FileContextProviderIsolationTest extends TestCase
{
    private FileContextProvider $provider;
    private MockInterface $searchService;
    private MockInterface $accessResolver;
    private MockInterface $filenameExtractor;

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.file_context.min_relevance_score', 0.4);
        $app['config']->set('ai.file_context.max_references', 5);
        $app['config']->set('ai.file_context.snippet_length', 200);
        $app['config']->set('ai.file_context.security_enabled', true);
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->searchService = Mockery::mock(FileSearchService::class);
        $this->accessResolver = Mockery::mock(FileAccessResolverInterface::class);
        $this->filenameExtractor = Mockery::mock(FilenameExtractor::class);

        $this->accessResolver->shouldReceive('shouldEnforceSecurity')->byDefault()->andReturn(true);
        $this->accessResolver->shouldReceive('isPhysicalFile')
            ->byDefault()
            ->andReturnUsing(fn ($id) => is_string($id) && str_starts_with($id, 'physical:'));

        $this->provider = new FileContextProvider(
            $this->searchService,
            $this->accessResolver,
            $this->filenameExtractor
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function content_results_survive_a_filename_search_failure(): void
    {
        $user = (object) ['id' => 1];

        // A filename is extracted, so the filename sub-search runs...
        $this->filenameExtractor->shouldReceive('extract')->once()->andReturn(['report.pdf']);

        // ...and it throws (e.g. missing payload index bubbling up).
        $this->searchService
            ->shouldReceive('searchByFilename')
            ->once()
            ->andThrow(new \RuntimeException('filename search exploded'));

        // Content search still returns a usable result.
        $contentChunk = new FileChunk(
            fileId: 7,
            fileName: 'analysis.docx',
            content: 'Relevant analysis content.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 26,
            metadata: []
        );

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 7,
                    'score' => 0.9,
                    'chunk_count' => 1,
                    'best_chunk' => $contentChunk,
                    'chunks' => [$contentChunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->with([7], $user)
            ->andReturn([7]);

        $result = $this->provider->searchRelevantFiles('What is in report.pdf?', $user);

        // The filename failure was swallowed; content results came through.
        $this->assertCount(1, $result);
        $this->assertEquals(7, $result[0]['file_id']);
        $this->assertEquals('content', $result[0]['match_type']);
    }

    /** @test */
    public function filename_results_survive_a_content_search_failure(): void
    {
        $user = (object) ['id' => 1];

        $this->filenameExtractor->shouldReceive('extract')->once()->andReturn(['bariloche.txt']);

        $filenameChunk = new FileChunk(
            fileId: 3,
            fileName: 'bariloche.txt',
            content: 'Trip details.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 13,
            metadata: []
        );

        $this->searchService
            ->shouldReceive('searchByFilename')
            ->once()
            ->andReturn([
                [
                    'file_id' => 3,
                    'score' => 1.0,
                    'chunk_count' => 1,
                    'best_chunk' => $filenameChunk,
                    'chunks' => [$filenameChunk],
                ],
            ]);

        // Content search blows up but must not wipe the filename hit.
        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andThrow(new \RuntimeException('qdrant content search down'));

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->with([3], $user)
            ->andReturn([3]);

        $result = $this->provider->searchRelevantFiles('Check bariloche.txt', $user);

        $this->assertCount(1, $result);
        $this->assertEquals(3, $result[0]['file_id']);
        $this->assertEquals('filename', $result[0]['match_type']);
    }
}
