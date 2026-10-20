<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FilenameExtractor;
use Condoedge\Ai\Services\FileSearchService;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;

class FileContextProviderTest extends TestCase
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
        // Default config setup
        $app['config']->set('ai.file_context.min_relevance_score', 0.7);
        $app['config']->set('ai.file_context.max_references', 5);
        $app['config']->set('ai.file_context.snippet_length', 200);
        $app['config']->set('ai.file_context.physical_collection', 'documentation_chunks');
        $app['config']->set('ai.file_processing.collection', 'file_chunks');
        $app['config']->set('ai.file_context.security_enabled', true);
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->searchService = Mockery::mock(FileSearchService::class);
        $this->accessResolver = Mockery::mock(FileAccessResolverInterface::class);
        $this->filenameExtractor = Mockery::mock(FilenameExtractor::class);

        // Default security behavior: allow pass-through (security check passes)
        // Individual tests can override this with specific expectations
        $this->accessResolver->shouldReceive('shouldEnforceSecurity')
            ->byDefault()
            ->andReturn(true);

        // Default isPhysicalFile behavior (can be overridden in individual tests)
        $this->accessResolver->shouldReceive('isPhysicalFile')
            ->byDefault()
            ->andReturnUsing(function ($fileId) {
                return is_string($fileId) && str_starts_with($fileId, 'physical:');
            });

        // Default filename extractor behavior: no filenames detected
        $this->filenameExtractor->shouldReceive('extract')
            ->byDefault()
            ->andReturn([]);

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

    /**
     * Create a simple object that mimics FileChunk with any file ID type.
     * This is needed because FileChunk::fileId is readonly int, but physical
     * files use string IDs like 'physical:/path/to/file.md'
     */
    private function createMockChunk(
        int|string $fileId,
        string $fileName,
        string $content,
        int $chunkIndex = 0
    ): object {
        return new class($fileId, $fileName, $content, $chunkIndex) {
            public function __construct(
                public readonly int|string $fileId,
                public readonly string $fileName,
                public readonly string $content,
                public readonly int $chunkIndex
            ) {}
        };
    }

    // =========================================================================
    // searchRelevantFiles tests
    // =========================================================================

    /** @test */
    public function it_returns_file_references_structure(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'document.pdf',
            content: 'This is some relevant content from the document.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 50,
            metadata: []
        );

        // Mock search service to return results
        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.85,
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        // Mock access resolver to allow all files
        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles('What is in the document?', $user);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('file_id', $result[0]);
        $this->assertArrayHasKey('filename', $result[0]);
        $this->assertArrayHasKey('snippet', $result[0]);
        $this->assertArrayHasKey('relevance', $result[0]);
        $this->assertArrayHasKey('chunk_index', $result[0]);
        $this->assertArrayHasKey('source', $result[0]);
    }

    /** @test */
    public function get_file_context_min_score_override_admits_borderline_documents(): void
    {
        // The recall safety net rescues a relevant document that lands just under the
        // confident relevance threshold by re-querying with a lower min_score. This
        // verifies getFileContext forwards that override (the mechanism the net relies
        // on): the default confident threshold (0.7 here) cuts a 0.36 document, while an
        // explicit lower floor admits it so the answer can ground on the real source.
        $user = new \stdClass();
        $user->id = 1;

        $chunk = $this->createMockChunk(7, 'declaration.pdf', 'Rules about modifying load-bearing walls in the co-ownership.');

        $this->searchService->shouldReceive('searchByContent')->andReturn([
            ['file_id' => 7, 'score' => 0.36, 'chunk_count' => 1, 'best_chunk' => $chunk, 'chunks' => [$chunk]],
        ]);
        $this->accessResolver->shouldReceive('filterAccessibleFileIds')->andReturn([7]);

        // Confident threshold (0.7) cuts the borderline 0.36 document -> nothing to ground on.
        $default = $this->provider->getFileContext('Can I tear down a wall?', $user);
        $this->assertSame(0, $default['file_count']);

        // The recall override (lower floor) admits it -> the real document grounds the answer.
        $rescued = $this->provider->getFileContext('Can I tear down a wall?', $user, ['min_score' => 0.3]);
        $this->assertSame(1, $rescued['file_count']);
        $this->assertSame(7, $rescued['relevant_files'][0]['file_id']);
    }

    /** @test */
    public function it_filters_by_access_when_security_enabled(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $chunk1 = new FileChunk(
            fileId: 1,
            fileName: 'allowed.pdf',
            content: 'Allowed content',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 15,
            metadata: []
        );

        $chunk2 = new FileChunk(
            fileId: 2,
            fileName: 'denied.pdf',
            content: 'Denied content',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 14,
            metadata: []
        );

        // Mock search service returns both files
        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.9,
                    'chunk_count' => 1,
                    'best_chunk' => $chunk1,
                    'chunks' => [$chunk1],
                ],
                [
                    'file_id' => 2,
                    'score' => 0.85,
                    'chunk_count' => 1,
                    'best_chunk' => $chunk2,
                    'chunks' => [$chunk2],
                ],
            ]);

        // Mock access resolver to only allow file 1
        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->with([1, 2], $user)
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles('search query', $user);

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['file_id']);
        $this->assertEquals('allowed.pdf', $result[0]['filename']);
    }

    /** @test */
    public function it_passes_physical_files_through_security(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        // Use mock chunks for physical files (which can have string IDs)
        $physicalChunk = $this->createMockChunk(
            fileId: 'physical:/docs/readme.md',
            fileName: 'readme.md',
            content: 'Documentation content from physical file'
        );

        $dbChunk = new FileChunk(
            fileId: 1,
            fileName: 'secret.pdf',
            content: 'Secret database file',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 20,
            metadata: []
        );

        // Mock search service returns both physical and db files
        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 'physical:/docs/readme.md',
                    'score' => 0.88,
                    'chunk_count' => 1,
                    'best_chunk' => $physicalChunk,
                    'chunks' => [$physicalChunk],
                ],
                [
                    'file_id' => 1,
                    'score' => 0.82,
                    'chunk_count' => 1,
                    'best_chunk' => $dbChunk,
                    'chunks' => [$dbChunk],
                ],
            ]);

        // Mock access resolver - physical files pass, db file denied
        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->with(['physical:/docs/readme.md', 1], $user)
            ->andReturn(['physical:/docs/readme.md']);

        $result = $this->provider->searchRelevantFiles('search query', $user);

        $this->assertCount(1, $result);
        $this->assertEquals('physical:/docs/readme.md', $result[0]['file_id']);
        $this->assertEquals('physical', $result[0]['source']);
    }

    /** @test */
    public function it_filters_by_minimum_relevance_score(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $highScoreChunk = new FileChunk(
            fileId: 1,
            fileName: 'relevant.pdf',
            content: 'Highly relevant content',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 23,
            metadata: []
        );

        $lowScoreChunk = new FileChunk(
            fileId: 2,
            fileName: 'irrelevant.pdf',
            content: 'Low relevance content',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 21,
            metadata: []
        );

        // Mock search service returns both high and low score results
        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.85, // Above threshold (0.7)
                    'chunk_count' => 1,
                    'best_chunk' => $highScoreChunk,
                    'chunks' => [$highScoreChunk],
                ],
                [
                    'file_id' => 2,
                    'score' => 0.5, // Below threshold (0.7)
                    'chunk_count' => 1,
                    'best_chunk' => $lowScoreChunk,
                    'chunks' => [$lowScoreChunk],
                ],
            ]);

        // Mock access resolver allows both
        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1, 2]);

        $result = $this->provider->searchRelevantFiles('search query', $user);

        // Only the high-score result should be returned
        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['file_id']);
        $this->assertEquals(0.85, $result[0]['relevance']);
    }

    /** @test */
    public function it_limits_results_to_max_references(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        // Create 10 chunks (more than max_references = 5)
        $searchResults = [];
        $fileIds = [];
        for ($i = 1; $i <= 10; $i++) {
            $chunk = new FileChunk(
                fileId: $i,
                fileName: "file{$i}.pdf",
                content: "Content {$i}",
                embedding: [],
                chunkIndex: 0,
                totalChunks: 1,
                startPosition: 0,
                endPosition: 10,
                metadata: []
            );
            $searchResults[] = [
                'file_id' => $i,
                'score' => 0.9 - ($i * 0.01), // Decreasing scores
                'chunk_count' => 1,
                'best_chunk' => $chunk,
                'chunks' => [$chunk],
            ];
            $fileIds[] = $i;
        }

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn($searchResults);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn($fileIds);

        $result = $this->provider->searchRelevantFiles('search query', $user);

        // Should only return max_references (5) results
        $this->assertCount(5, $result);
    }

    /** @test */
    public function it_sorts_results_by_relevance_score_descending(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $lowChunk = new FileChunk(
            fileId: 1,
            fileName: 'low.pdf',
            content: 'Low score content',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 17,
            metadata: []
        );

        $highChunk = new FileChunk(
            fileId: 2,
            fileName: 'high.pdf',
            content: 'High score content',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 18,
            metadata: []
        );

        $midChunk = new FileChunk(
            fileId: 3,
            fileName: 'mid.pdf',
            content: 'Mid score content',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 17,
            metadata: []
        );

        // Return results out of order
        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.75,
                    'chunk_count' => 1,
                    'best_chunk' => $lowChunk,
                    'chunks' => [$lowChunk],
                ],
                [
                    'file_id' => 2,
                    'score' => 0.95,
                    'chunk_count' => 1,
                    'best_chunk' => $highChunk,
                    'chunks' => [$highChunk],
                ],
                [
                    'file_id' => 3,
                    'score' => 0.85,
                    'chunk_count' => 1,
                    'best_chunk' => $midChunk,
                    'chunks' => [$midChunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1, 2, 3]);

        $result = $this->provider->searchRelevantFiles('search query', $user);

        // Should be sorted by score descending
        $this->assertEquals(2, $result[0]['file_id']); // 0.95
        $this->assertEquals(3, $result[1]['file_id']); // 0.85
        $this->assertEquals(1, $result[2]['file_id']); // 0.75
    }

    /** @test */
    public function it_truncates_snippets_to_configured_length(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $longContent = str_repeat('A', 500); // 500 chars, config is 200

        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'long.pdf',
            content: $longContent,
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 500,
            metadata: []
        );

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.9,
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles('search query', $user);

        $this->assertCount(1, $result);
        // Snippet should be truncated to snippet_length (200) + ellipsis
        $this->assertLessThanOrEqual(203, strlen($result[0]['snippet'])); // 200 + "..."
    }

    /** @test */
    public function it_returns_empty_array_when_no_results(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([]);

        // When search returns empty, filterAccessibleFileIds should not be called
        // (early return optimization)
        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->never();

        $result = $this->provider->searchRelevantFiles('no results query', $user);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // buildFileReference tests
    // =========================================================================

    /** @test */
    public function it_builds_file_reference_with_all_required_fields(): void
    {
        $reference = $this->provider->buildFileReference(
            refNumber: 1,
            fileId: 123,
            fileName: 'report.pdf',
            snippet: 'This is a snippet of content.',
            relevanceScore: 0.89,
            chunkIndex: 2,
            source: 'database'
        );

        $this->assertArrayHasKey('ref_number', $reference);
        $this->assertArrayHasKey('file_id', $reference);
        $this->assertArrayHasKey('filename', $reference);
        $this->assertArrayHasKey('snippet', $reference);
        $this->assertArrayHasKey('relevance', $reference);
        $this->assertArrayHasKey('chunk_index', $reference);
        $this->assertArrayHasKey('source', $reference);

        $this->assertEquals(1, $reference['ref_number']);
        $this->assertEquals(123, $reference['file_id']);
        $this->assertEquals('report.pdf', $reference['filename']);
        $this->assertEquals('This is a snippet of content.', $reference['snippet']);
        $this->assertEquals(0.89, $reference['relevance']);
        $this->assertEquals(2, $reference['chunk_index']);
        $this->assertEquals('database', $reference['source']);
    }

    /** @test */
    public function it_builds_file_reference_for_physical_file(): void
    {
        $reference = $this->provider->buildFileReference(
            refNumber: 1,
            fileId: 'physical:/docs/api.md',
            fileName: 'api.md',
            snippet: 'API documentation snippet',
            relevanceScore: 0.92,
            chunkIndex: 0,
            source: 'physical'
        );

        $this->assertEquals('physical:/docs/api.md', $reference['file_id']);
        $this->assertEquals('physical', $reference['source']);
    }

    // =========================================================================
    // getFileContext tests
    // =========================================================================

    /** @test */
    public function it_gets_file_context_for_prompt_building(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        // Use mock for physical chunk
        $physicalChunk = $this->createMockChunk(
            fileId: 'physical:/docs/guide.md',
            fileName: 'guide.md',
            content: 'Documentation guide content'
        );

        $dbChunk = new FileChunk(
            fileId: 1,
            fileName: 'data.pdf',
            content: 'Database file content',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 21,
            metadata: []
        );

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 'physical:/docs/guide.md',
                    'score' => 0.9,
                    'chunk_count' => 1,
                    'best_chunk' => $physicalChunk,
                    'chunks' => [$physicalChunk],
                ],
                [
                    'file_id' => 1,
                    'score' => 0.85,
                    'chunk_count' => 1,
                    'best_chunk' => $dbChunk,
                    'chunks' => [$dbChunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn(['physical:/docs/guide.md', 1]);

        $context = $this->provider->getFileContext('What is in the guide?', $user);

        $this->assertArrayHasKey('relevant_files', $context);
        $this->assertArrayHasKey('file_count', $context);
        $this->assertArrayHasKey('has_physical', $context);
        $this->assertArrayHasKey('has_database', $context);

        $this->assertCount(2, $context['relevant_files']);
        $this->assertEquals(2, $context['file_count']);
        $this->assertTrue($context['has_physical']);
        $this->assertTrue($context['has_database']);
    }

    /** @test */
    public function it_returns_correct_flags_for_physical_only_context(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $physicalChunk = $this->createMockChunk(
            fileId: 'physical:/docs/readme.md',
            fileName: 'readme.md',
            content: 'Physical file only'
        );

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 'physical:/docs/readme.md',
                    'score' => 0.88,
                    'chunk_count' => 1,
                    'best_chunk' => $physicalChunk,
                    'chunks' => [$physicalChunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn(['physical:/docs/readme.md']);

        $context = $this->provider->getFileContext('readme content', $user);

        $this->assertTrue($context['has_physical']);
        $this->assertFalse($context['has_database']);
    }

    /** @test */
    public function it_returns_correct_flags_for_database_only_context(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $dbChunk = new FileChunk(
            fileId: 1,
            fileName: 'report.pdf',
            content: 'Database file only',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 18,
            metadata: []
        );

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.82,
                    'chunk_count' => 1,
                    'best_chunk' => $dbChunk,
                    'chunks' => [$dbChunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $context = $this->provider->getFileContext('report content', $user);

        $this->assertFalse($context['has_physical']);
        $this->assertTrue($context['has_database']);
    }

    /** @test */
    public function it_returns_empty_context_when_no_files_found(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([]);

        // When no results, filterAccessibleFileIds is not called
        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->never();

        $context = $this->provider->getFileContext('no results', $user);

        $this->assertEmpty($context['relevant_files']);
        $this->assertEquals(0, $context['file_count']);
        $this->assertFalse($context['has_physical']);
        $this->assertFalse($context['has_database']);
    }

    // =========================================================================
    // Edge cases and integration tests
    // =========================================================================

    /** @test */
    public function test_throws_exception_when_security_enabled_and_user_null(): void
    {
        $this->accessResolver
            ->shouldReceive('shouldEnforceSecurity')
            ->andReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User required for file context retrieval');

        $this->provider->searchRelevantFiles('search query', null);
    }

    /** @test */
    public function it_handles_null_user_gracefully_when_security_disabled(): void
    {
        // When security is disabled, null user should be allowed
        $this->accessResolver
            ->shouldReceive('shouldEnforceSecurity')
            ->andReturn(false);

        $chunk = $this->createMockChunk(
            fileId: 'physical:/docs/public.md',
            fileName: 'public.md',
            content: 'Public documentation'
        );

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 'physical:/docs/public.md',
                    'score' => 0.9,
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        // Even with null user and security disabled, physical files should pass through
        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->with(['physical:/docs/public.md'], null)
            ->andReturn(['physical:/docs/public.md']);

        $result = $this->provider->searchRelevantFiles('public docs', null);

        $this->assertCount(1, $result);
        $this->assertEquals('physical:/docs/public.md', $result[0]['file_id']);
    }

    /** @test */
    public function it_correctly_identifies_source_as_physical(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $chunk = $this->createMockChunk(
            fileId: 'physical:/docs/test.md',
            fileName: 'test.md',
            content: 'Physical file content'
        );

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 'physical:/docs/test.md',
                    'score' => 0.88,
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn(['physical:/docs/test.md']);

        $result = $this->provider->searchRelevantFiles('test', $user);

        $this->assertEquals('physical', $result[0]['source']);
    }

    /** @test */
    public function it_correctly_identifies_source_as_database(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $chunk = new FileChunk(
            fileId: 42,
            fileName: 'database_file.pdf',
            content: 'Database file content',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 21,
            metadata: []
        );

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 42,
                    'score' => 0.88,
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([42]);

        $result = $this->provider->searchRelevantFiles('test', $user);

        $this->assertEquals('database', $result[0]['source']);
    }

    // =========================================================================
    // Combined search tests (filename + content)
    // =========================================================================

    /** @test */
    public function test_search_finds_file_by_explicit_filename_reference(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'bariloche.txt',
            content: 'Content about Bariloche region in Argentina.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 44,
            metadata: []
        );

        // Mock filename extractor to detect "bariloche.txt"
        $this->filenameExtractor
            ->shouldReceive('extract')
            ->with('Check bariloche.txt')
            ->once()
            ->andReturn(['bariloche.txt']);

        // Mock searchByFilename to return the file
        $this->searchService
            ->shouldReceive('searchByFilename')
            ->with('bariloche.txt', Mockery::any())
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 1.0, // Exact match
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        // Content search returns empty (no semantic matches)
        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles('Check bariloche.txt', $user);

        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['file_id']);
        $this->assertEquals('bariloche.txt', $result[0]['filename']);
        $this->assertEquals('filename', $result[0]['match_type']);
        $this->assertEquals(1.0, $result[0]['relevance']);
    }

    /** @test */
    public function test_search_combines_filename_and_content_results(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $filenameChunk = new FileChunk(
            fileId: 1,
            fileName: 'report.pdf',
            content: 'Financial report content.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 25,
            metadata: []
        );

        $contentChunk = new FileChunk(
            fileId: 2,
            fileName: 'analysis.docx',
            content: 'Financial analysis document.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 28,
            metadata: []
        );

        // Mock filename extractor to detect "report.pdf"
        $this->filenameExtractor
            ->shouldReceive('extract')
            ->once()
            ->andReturn(['report.pdf']);

        // Mock searchByFilename
        $this->searchService
            ->shouldReceive('searchByFilename')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.85, // Partial filename match
                    'chunk_count' => 1,
                    'best_chunk' => $filenameChunk,
                    'chunks' => [$filenameChunk],
                ],
            ]);

        // Mock searchByContent returns different file
        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 2,
                    'score' => 0.9,
                    'chunk_count' => 1,
                    'best_chunk' => $contentChunk,
                    'chunks' => [$contentChunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1, 2]);

        $result = $this->provider->searchRelevantFiles('What is in report.pdf about finances?', $user);

        // Both results should be present
        $this->assertCount(2, $result);

        // Content result has higher score so comes first
        $this->assertEquals(2, $result[0]['file_id']);
        $this->assertEquals('content', $result[0]['match_type']);

        // Filename result comes second
        $this->assertEquals(1, $result[1]['file_id']);
        $this->assertEquals('filename', $result[1]['match_type']);
    }

    /** @test */
    public function test_search_deduplicates_when_same_file_in_both_results(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'data.csv',
            content: 'Sales data for Q4.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 18,
            metadata: []
        );

        // Mock filename extractor
        $this->filenameExtractor
            ->shouldReceive('extract')
            ->once()
            ->andReturn(['data.csv']);

        // Both searches return the same file
        $this->searchService
            ->shouldReceive('searchByFilename')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 1.0, // Exact filename match
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.8, // Content match score
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles('Show me data.csv', $user);

        // Should only have one result (deduplicated)
        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['file_id']);
        // Filename match should win (added first with higher priority)
        $this->assertEquals('filename', $result[0]['match_type']);
        // Should have the filename match score (1.0), not content score (0.8)
        $this->assertEquals(1.0, $result[0]['relevance']);
    }

    /** @test */
    public function test_partial_filename_match_uses_lower_threshold(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'quarterly_report.pdf',
            content: 'Quarterly financial report.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 27,
            metadata: []
        );

        $this->filenameExtractor
            ->shouldReceive('extract')
            ->once()
            ->andReturn(['report.pdf']);

        // Partial match with score 0.85 (above 0.3 threshold)
        $this->searchService
            ->shouldReceive('searchByFilename')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.85, // Partial match, above 0.3 threshold
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles('Find report.pdf', $user);

        // Should be included (0.85 > 0.3 partial threshold)
        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['file_id']);
        $this->assertEquals('filename', $result[0]['match_type']);
    }

    /** @test */
    public function test_exact_filename_match_always_included(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'exact_match.txt',
            content: 'Exact match file content.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 25,
            metadata: []
        );

        $this->filenameExtractor
            ->shouldReceive('extract')
            ->once()
            ->andReturn(['exact_match.txt']);

        // Exact match with score 1.0
        $this->searchService
            ->shouldReceive('searchByFilename')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 1.0, // Exact match
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles('Show exact_match.txt', $user);

        // Exact match (1.0) is always included regardless of any threshold
        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['file_id']);
        $this->assertEquals(1.0, $result[0]['relevance']);
        $this->assertEquals('filename', $result[0]['match_type']);
    }

    /** @test */
    public function test_filename_match_below_partial_threshold_is_excluded(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'some_other_file.pdf',
            content: 'Some unrelated content.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 23,
            metadata: []
        );

        $this->filenameExtractor
            ->shouldReceive('extract')
            ->once()
            ->andReturn(['report.pdf']);

        // Very low partial match score (below 0.3 threshold)
        $this->searchService
            ->shouldReceive('searchByFilename')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.2, // Below 0.3 partial threshold
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([]);

        // filterAccessibleFileIds is called but result is filtered out by threshold
        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles('Find report.pdf', $user);

        // Should be excluded (0.2 < 0.3 partial threshold)
        $this->assertEmpty($result);
    }

    /** @test */
    public function test_content_match_uses_standard_threshold(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $highScoreChunk = new FileChunk(
            fileId: 1,
            fileName: 'relevant.pdf',
            content: 'Highly relevant content.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 24,
            metadata: []
        );

        $lowScoreChunk = new FileChunk(
            fileId: 2,
            fileName: 'irrelevant.pdf',
            content: 'Low relevance content.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 22,
            metadata: []
        );

        // No filename detected
        $this->filenameExtractor
            ->shouldReceive('extract')
            ->once()
            ->andReturn([]);

        // Content search returns both high and low score results
        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.85, // Above 0.7 standard threshold
                    'chunk_count' => 1,
                    'best_chunk' => $highScoreChunk,
                    'chunks' => [$highScoreChunk],
                ],
                [
                    'file_id' => 2,
                    'score' => 0.5, // Below 0.7 standard threshold
                    'chunk_count' => 1,
                    'best_chunk' => $lowScoreChunk,
                    'chunks' => [$lowScoreChunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1, 2]);

        $result = $this->provider->searchRelevantFiles('search query', $user);

        // Only high score result should be included
        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['file_id']);
        $this->assertEquals('content', $result[0]['match_type']);
    }

    /** @test */
    public function test_result_includes_match_type_in_output(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'document.pdf',
            content: 'Document content.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 17,
            metadata: []
        );

        $this->filenameExtractor
            ->shouldReceive('extract')
            ->once()
            ->andReturn([]);

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.9,
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles('search query', $user);

        $this->assertArrayHasKey('match_type', $result[0]);
        $this->assertEquals('content', $result[0]['match_type']);
    }

    // =========================================================================
    // Conversation context enhancement tests
    // =========================================================================

    /** @test */
    public function it_enhances_query_with_conversation_context_for_follow_ups(): void
    {
        // When QU judged the question a follow-up (follow_up_reference, threaded
        // by AiManager) and there's a focused entity, the query should include
        // the entity context
        $user = new \stdClass();
        $user->id = 1;

        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'teams.pdf',
            content: 'Team information with office locations.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 39,
            metadata: []
        );

        $this->filenameExtractor
            ->shouldReceive('extract')
            ->once()
            ->andReturn([]);

        // Should search with enhanced query that includes focused entity
        $this->searchService
            ->shouldReceive('searchByContent')
            ->with(
                Mockery::on(function ($query) {
                    // Query should include focused entity context for follow-up
                    return str_contains($query, 'Team') && str_contains($query, 'offices in NYC');
                }),
                Mockery::any()
            )
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.85,
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles(
            'Which of those have offices in NYC?',
            $user,
            [
                'conversation_context' => [
                    'focused_entity' => 'Team',
                    'last_answer_summary' => 'Found 5 active teams...',
                ],
                'follow_up_reference' => true,
            ]
        );

        $this->assertCount(1, $result);
    }

    /** @test */
    public function it_does_not_enhance_query_for_non_follow_up_questions(): void
    {
        // When question is NOT a follow-up, query should remain unchanged
        $user = new \stdClass();
        $user->id = 1;

        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'teams.pdf',
            content: 'Team information.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 17,
            metadata: []
        );

        $this->filenameExtractor
            ->shouldReceive('extract')
            ->once()
            ->andReturn([]);

        // Should search with original query (no enhancement)
        $this->searchService
            ->shouldReceive('searchByContent')
            ->with(
                'What teams are available?', // Original query unchanged
                Mockery::any()
            )
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.85,
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles(
            'What teams are available?',
            $user,
            [
                'conversation_context' => [
                    'focused_entity' => 'Team',
                    'last_answer_summary' => 'Found 5 active teams...',
                ],
                'follow_up_reference' => false,
            ]
        );

        $this->assertCount(1, $result);
    }

    /** @test */
    public function it_does_not_enhance_query_without_conversation_context(): void
    {
        // When no conversation context provided, query should remain unchanged
        $user = new \stdClass();
        $user->id = 1;

        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'teams.pdf',
            content: 'Team information.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 17,
            metadata: []
        );

        $this->filenameExtractor
            ->shouldReceive('extract')
            ->once()
            ->andReturn([]);

        // Should search with original query (no enhancement)
        $this->searchService
            ->shouldReceive('searchByContent')
            ->with(
                'Which of those are active?', // Original query unchanged (no context)
                Mockery::any()
            )
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.85,
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        // No conversation_context provided (even with a QU follow-up judgment)
        $result = $this->provider->searchRelevantFiles(
            'Which of those are active?',
            $user,
            ['follow_up_reference' => true]
        );

        $this->assertCount(1, $result);
    }

    /** @test */
    public function the_follow_up_decision_is_qus_judgment_not_a_word_list(): void
    {
        // Phase 2.B: the EN-only pronoun list ('those','them','these',...) is gone.
        // A French follow-up is engaged by QU's followUpReference; an English
        // pronoun WITHOUT QU's judgment no longer triggers anything.
        $user = new \stdClass();
        $user->id = 1;

        $cases = [
            // question => [QU follow_up_reference, enhancement expected]
            'Et celles-là ?' => [true, true],
            'Et pour les unités de celles-ci ?' => [true, true],
            'Which of those are active?' => [false, false],
            'Can any of them edit documents?' => [false, false],
            'What is the total count?' => [true, true],
            'List all users' => [false, false],
        ];

        $testedPatterns = 0;

        foreach ($cases as $question => [$quFollowUp, $isFollowUp]) {
            // Reset mocks
            Mockery::close();

            $this->searchService = Mockery::mock(FileSearchService::class);
            $this->accessResolver = Mockery::mock(FileAccessResolverInterface::class);
            $this->filenameExtractor = Mockery::mock(FilenameExtractor::class);

            $this->accessResolver->shouldReceive('shouldEnforceSecurity')
                ->byDefault()
                ->andReturn(true);

            $this->accessResolver->shouldReceive('isPhysicalFile')
                ->byDefault()
                ->andReturnUsing(function ($fileId) {
                    return is_string($fileId) && str_starts_with($fileId, 'physical:');
                });

            $this->filenameExtractor->shouldReceive('extract')
                ->andReturn([]);

            $chunk = new FileChunk(
                fileId: 1,
                fileName: 'test.pdf',
                content: 'Test content.',
                embedding: [],
                chunkIndex: 0,
                totalChunks: 1,
                startPosition: 0,
                endPosition: 13,
                metadata: []
            );

            $expectedQuery = $isFollowUp ? "Team: {$question}" : $question;

            $this->searchService
                ->shouldReceive('searchByContent')
                ->with($expectedQuery, Mockery::any())
                ->once()
                ->andReturn([
                    [
                        'file_id' => 1,
                        'score' => 0.85,
                        'chunk_count' => 1,
                        'best_chunk' => $chunk,
                        'chunks' => [$chunk],
                    ],
                ]);

            $this->accessResolver
                ->shouldReceive('filterAccessibleFileIds')
                ->once()
                ->andReturn([1]);

            $provider = new FileContextProvider(
                $this->searchService,
                $this->accessResolver,
                $this->filenameExtractor
            );

            $result = $provider->searchRelevantFiles(
                $question,
                $user,
                [
                    'conversation_context' => [
                        'focused_entity' => 'Team',
                    ],
                    'follow_up_reference' => $quFollowUp,
                ]
            );

            $this->assertCount(1, $result, "Expected 1 result for question: {$question}");
            $testedPatterns++;
        }

        // Ensure we tested all cases
        $this->assertEquals(6, $testedPatterns, 'Should test all 6 cases');
    }

    /** @test */
    public function it_does_not_enhance_when_focused_entity_is_empty(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'test.pdf',
            content: 'Test content.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 13,
            metadata: []
        );

        $this->filenameExtractor
            ->shouldReceive('extract')
            ->once()
            ->andReturn([]);

        // Should search with original query (focused_entity is empty)
        $this->searchService
            ->shouldReceive('searchByContent')
            ->with(
                'Which of those are active?', // Original query unchanged
                Mockery::any()
            )
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.85,
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles(
            'Which of those are active?',
            $user,
            [
                'conversation_context' => [
                    'focused_entity' => '', // Empty entity
                    'last_answer_summary' => 'Some previous results...',
                ],
                'follow_up_reference' => true,
            ]
        );

        $this->assertCount(1, $result);
    }

    // =========================================================================
    // File enumeration tests (capability A — Q4)
    // =========================================================================

    /** @test */
    public function it_enumerates_accessible_files_as_references(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $this->accessResolver
            ->shouldReceive('enumerateAccessibleFiles')
            ->once()
            ->with($user, Mockery::any())
            ->andReturn([
                ['file_id' => 10, 'name' => 'lease.pdf', 'type' => 'pdf'],
                ['file_id' => 11, 'name' => 'rules.docx', 'type' => 'docx'],
            ]);

        $result = $this->provider->enumerateAccessibleFiles($user);

        $this->assertCount(2, $result);
        $this->assertEquals(10, $result[0]['file_id']);
        $this->assertEquals('lease.pdf', $result[0]['filename']);
        $this->assertEquals('pdf', $result[0]['type']);
        $this->assertEquals('database', $result[0]['source']);
        $this->assertEquals('enumeration', $result[0]['match_type']);
    }

    /** @test */
    public function enumeration_entries_carry_no_fabricated_relevance_and_are_marked_listing_only(): void
    {
        // Regression (broken file scorer): enumeration used to assign a uniform
        // relevance of 1.0 with EMPTY snippets to every accessible file, which the
        // prompt rendered as "relevance: 100%" grounding — pure noise. No
        // similarity is ever computed during enumeration, so no relevance may be
        // claimed, and the entries must be marked listing-only so they never
        // enter grounding context.
        $user = new \stdClass();
        $user->id = 1;

        $this->accessResolver
            ->shouldReceive('enumerateAccessibleFiles')
            ->once()
            ->andReturn([
                ['file_id' => 10, 'name' => 'invoice-0042.pdf', 'type' => 'pdf'],
                ['file_id' => 11, 'name' => 'holiday.mov', 'type' => 'mov'],
            ]);

        $result = $this->provider->enumerateAccessibleFiles($user);

        foreach ($result as $entry) {
            $this->assertNull($entry['relevance'], 'enumeration must not fabricate a relevance score');
            $this->assertTrue($entry['listing_only'], 'enumeration entries must be marked listing-only');
            $this->assertSame('', $entry['snippet']);
            $this->assertSame('enumeration', $entry['match_type']);
        }
    }

    /** @test */
    public function enumeration_returns_only_what_the_resolver_allows(): void
    {
        // The provider must never widen the resolver's ACL-filtered set: it lists
        // exactly the descriptors the resolver returns and nothing else.
        $user = new \stdClass();
        $user->id = 7;

        $this->accessResolver
            ->shouldReceive('enumerateAccessibleFiles')
            ->once()
            ->andReturn([
                ['file_id' => 42, 'name' => 'allowed.pdf', 'type' => 'pdf'],
            ]);

        $result = $this->provider->enumerateAccessibleFiles($user);

        $this->assertCount(1, $result);
        $this->assertEquals(42, $result[0]['file_id']);
    }

    /** @test */
    public function enumeration_forwards_the_configured_max_files_limit(): void
    {
        config(['ai.file_context.enumeration.max_files' => 3]);

        $user = new \stdClass();
        $user->id = 1;

        $this->accessResolver
            ->shouldReceive('enumerateAccessibleFiles')
            ->once()
            ->with($user, 3)
            ->andReturn([]);

        $result = $this->provider->enumerateAccessibleFiles($user);

        $this->assertSame([], $result);
    }

    /** @test */
    public function enumeration_requires_a_user_when_security_enabled(): void
    {
        $this->accessResolver
            ->shouldReceive('shouldEnforceSecurity')
            ->andReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User required for file context retrieval');

        $this->provider->enumerateAccessibleFiles(null);
    }

    /** @test */
    public function enumeration_context_reports_counts_and_flags(): void
    {
        $user = new \stdClass();
        $user->id = 1;

        $this->accessResolver
            ->shouldReceive('enumerateAccessibleFiles')
            ->once()
            ->andReturn([
                ['file_id' => 10, 'name' => 'a.pdf', 'type' => 'pdf'],
                ['file_id' => 11, 'name' => 'b.pdf', 'type' => 'pdf'],
            ]);

        $context = $this->provider->getFileEnumerationContext($user);

        $this->assertTrue($context['enumeration']);
        $this->assertEquals(2, $context['file_count']);
        $this->assertCount(2, $context['relevant_files']);
        $this->assertTrue($context['has_database']);
        $this->assertFalse($context['has_physical']);
    }

    // =========================================================================
    // Grounding-quality snippet assembly tests (capability B — Q6)
    // =========================================================================

    /** @test */
    public function it_stitches_top_chunks_into_a_richer_snippet(): void
    {
        config(['ai.file_context.snippet_chunks' => 3]);
        config(['ai.file_context.snippet_length' => 2000]);

        $user = new \stdClass();
        $user->id = 1;

        // Three chunks of the same file (plain FileChunk objects, the shape
        // FileSearchService returns in chunks[]). The clause the model must quote
        // spans chunk 0 and chunk 1; the single-best-chunk path would show only one.
        $c0 = $this->fileChunk(1, 'doc.pdf', 'The owner shall not modify', 0);
        $c1 = $this->fileChunk(1, 'doc.pdf', 'any structural common portion', 1);
        $c2 = $this->fileChunk(1, 'doc.pdf', 'unrelated trailing text', 2);

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.95,
                    'chunk_count' => 3,
                    'best_chunk' => $c1,
                    'chunks' => [$c0, $c1, $c2],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles('Can I modify a common portion?', $user);

        $this->assertCount(1, $result);
        // Both top chunks present, in reading order (chunk 0 before chunk 1).
        $snippet = $result[0]['snippet'];
        $this->assertStringContainsString('The owner shall not modify', $snippet);
        $this->assertStringContainsString('any structural common portion', $snippet);
        $pos0 = strpos($snippet, 'The owner shall not modify');
        $pos1 = strpos($snippet, 'any structural common portion');
        $this->assertLessThan($pos1, $pos0, 'Chunks should be stitched in reading order');
    }

    /** @test */
    public function snippet_chunks_one_preserves_single_chunk_behaviour(): void
    {
        config(['ai.file_context.snippet_chunks' => 1]);
        config(['ai.file_context.snippet_length' => 2000]);

        $user = new \stdClass();
        $user->id = 1;

        $c0 = $this->fileChunk(1, 'doc.pdf', 'Only the best chunk content', 0);
        $c1 = $this->fileChunk(1, 'doc.pdf', 'Secondary chunk content', 1);

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.9,
                    'chunk_count' => 2,
                    'best_chunk' => $c0,
                    'chunks' => [$c0, $c1],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles('query', $user);

        $this->assertCount(1, $result);
        $this->assertEquals('Only the best chunk content', $result[0]['snippet']);
        $this->assertStringNotContainsString('Secondary chunk content', $result[0]['snippet']);
    }

    /** @test */
    public function snippet_centers_on_the_best_chunk_not_the_lowest_index_cover(): void
    {
        // Regression: a document whose operative clause lives at a HIGH chunk index
        // (e.g. art. 19 deep in a declaration) must not be crowded out by its
        // lowest-index cover/preamble chunks. The snippet must LEAD with the best
        // chunk and fill from its reading-order NEIGHBORS, not from index 0.
        config(['ai.file_context.snippet_chunks' => 3]);
        // Budget large enough for the best chunk + 2 neighbors, but far too small
        // to ALSO carry the three low-index cover chunks ahead of them. If the
        // assembler led with index 0, the operative clause would be truncated off.
        config(['ai.file_context.snippet_length' => 220]);
        // The right document scores low under a cross-lingual probe; admit it.
        config(['ai.file_context.min_relevance_score' => 0.4]);

        $user = new \stdClass();
        $user->id = 1;

        // Cover/preamble chunks (low index) — long enough to blow the budget on
        // their own if they led the snippet.
        $cover0 = $this->fileChunk(1, 'decl.pdf', str_repeat('COVER PAGE NOTARY PREAMBLE. ', 6), 0);
        $cover1 = $this->fileChunk(1, 'decl.pdf', str_repeat('TABLE OF CONTENTS SECTION. ', 6), 1);
        $cover2 = $this->fileChunk(1, 'decl.pdf', str_repeat('DEFINITIONS AND RECITALS. ', 6), 2);

        // The operative clause and its immediate neighbors (high index).
        $before = $this->fileChunk(1, 'decl.pdf', 'NEIGHBOR-BEFORE-23', 23);
        $best = $this->fileChunk(1, 'decl.pdf', 'OPERATIVE-ARTICLE-19-CLAUSE', 24);
        $after = $this->fileChunk(1, 'decl.pdf', 'NEIGHBOR-AFTER-25', 25);

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.61,
                    'chunk_count' => 6,
                    'best_chunk' => $best,
                    'chunks' => [$cover0, $cover1, $cover2, $before, $best, $after],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles('travaux parties exclusives', $user);

        $this->assertCount(1, $result);
        $snippet = $result[0]['snippet'];

        // The operative clause must be present (not truncated off by cover chunks).
        $this->assertStringContainsString('OPERATIVE-ARTICLE-19-CLAUSE', $snippet);

        // It must be CENTERED: filled from the best chunk's neighbors (23/25), not
        // from the globally-lowest-index cover chunks (0/1/2).
        $this->assertStringContainsString('NEIGHBOR-BEFORE-23', $snippet);
        $this->assertStringContainsString('NEIGHBOR-AFTER-25', $snippet);
        $this->assertStringNotContainsString('COVER PAGE NOTARY PREAMBLE', $snippet);
        $this->assertStringNotContainsString('TABLE OF CONTENTS SECTION', $snippet);

        // Selected chunks are sorted by reading order for a coherent read.
        $posBefore = strpos($snippet, 'NEIGHBOR-BEFORE-23');
        $posBest = strpos($snippet, 'OPERATIVE-ARTICLE-19-CLAUSE');
        $posAfter = strpos($snippet, 'NEIGHBOR-AFTER-25');
        $this->assertLessThan($posBest, $posBefore, 'Neighbor before should precede best in reading order');
        $this->assertLessThan($posAfter, $posBest, 'Best should precede neighbor after in reading order');
    }

    /** @test */
    public function snippet_centering_keeps_best_chunk_when_budget_fits_only_one(): void
    {
        // When the length budget can only fit the operative clause itself, the
        // best chunk must still survive (it leads selection) rather than the cover.
        config(['ai.file_context.snippet_chunks' => 3]);
        config(['ai.file_context.snippet_length' => 40]);
        config(['ai.file_context.min_relevance_score' => 0.4]);

        $user = new \stdClass();
        $user->id = 1;

        $cover0 = $this->fileChunk(1, 'decl.pdf', str_repeat('X', 200), 0);
        $best = $this->fileChunk(1, 'decl.pdf', 'OPERATIVE-CLAUSE', 24);

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.61,
                    'chunk_count' => 2,
                    'best_chunk' => $best,
                    'chunks' => [$cover0, $best],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $result = $this->provider->searchRelevantFiles('clause', $user);

        $this->assertCount(1, $result);
        // The operative clause leads the snippet, so it is the first thing the
        // budget admits — it must not be truncated off by the cover chunk.
        $this->assertStringContainsString('OPERATIVE-CLAUSE', $result[0]['snippet']);
    }

    // =========================================================================
    // Context-window expansion tests (small-to-big / sentence-window)
    // =========================================================================

    /** @test */
    public function window_indices_clamps_to_the_files_real_index_range(): void
    {
        // Pure windowing: from a best index and the file's available chunk indices,
        // select [best-window .. best+window] intersected with what actually exists.
        $method = new \ReflectionMethod(FileContextProvider::class, 'windowIndices');
        $method->setAccessible(true);

        $available = [0, 1, 2, 3, 4, 5];

        // Mid-document hit, window 2: full symmetric window.
        $this->assertSame([1, 2, 3, 4, 5], $method->invoke($this->provider, 3, 2, $available));

        // Hit at the start: clamp the low side.
        $this->assertSame([0, 1, 2], $method->invoke($this->provider, 0, 2, $available));

        // Hit at the end: clamp the high side.
        $this->assertSame([3, 4, 5], $method->invoke($this->provider, 5, 2, $available));

        // window 0: only the best chunk itself.
        $this->assertSame([3], $method->invoke($this->provider, 3, 0, $available));

        // Single-chunk file: no-op, just the best index.
        $this->assertSame([2], $method->invoke($this->provider, 2, 2, [2]));

        // Sparse/gappy index set (only existing indices are returned).
        $this->assertSame([10, 12], $method->invoke($this->provider, 11, 2, [10, 12, 20]));
    }

    /** @test */
    public function it_expands_snippet_with_index_adjacent_neighbors_from_the_chunk_store(): void
    {
        config(['ai.file_context.context_window' => 2]);
        config(['ai.file_context.snippet_chunks' => 5]);
        config(['ai.file_context.snippet_length' => 4000]);
        // The right document scores low under a cross-lingual probe; admit it.
        config(['ai.file_context.min_relevance_score' => 0.4]);

        $user = new \stdClass();
        $user->id = 1;

        // Only the operative clause (index 24) was independently retrieved by the
        // vector search. Its true neighbors (23 and 25) were NOT returned.
        $best = $this->fileChunk(1, 'decl.pdf', 'ARTICLE 19 travaux conservation immeuble', 24);

        // The full file as the chunk store has it: neighbors 23 (prejudice clause)
        // and 25 exist on disk even though search did not return them.
        $allChunks = [
            $this->fileChunk(1, 'decl.pdf', 'ARTICLE 18 preceding content', 22),
            $this->fileChunk(1, 'decl.pdf', 'PREJUDICE compensation clause neighbor', 23),
            $best,
            $this->fileChunk(1, 'decl.pdf', 'ARTICLE 20 following content', 25),
            $this->fileChunk(1, 'decl.pdf', 'ARTICLE 21 far content', 27),
        ];

        $chunkStore = Mockery::mock(ChunkStoreInterface::class);
        $chunkStore->shouldReceive('getFileChunks')
            ->once()
            ->with(1, null)
            ->andReturn($allChunks);

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'collection' => '__primary__',
                    'score' => 0.61,
                    'chunk_count' => 1,
                    'best_chunk' => $best,
                    'chunks' => [$best],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $provider = new FileContextProvider(
            $this->searchService,
            $this->accessResolver,
            $this->filenameExtractor,
            null,
            $chunkStore
        );

        $result = $provider->searchRelevantFiles('travaux parties exclusives', $user);

        $this->assertCount(1, $result);
        $snippet = $result[0]['snippet'];

        // The operative clause is still present.
        $this->assertStringContainsString('ARTICLE 19 travaux conservation immeuble', $snippet);

        // ...AND its index-adjacent neighbors (never independently retrieved) were
        // stitched in around it, so the model reads the full passage.
        $this->assertStringContainsString('PREJUDICE compensation clause neighbor', $snippet);
        $this->assertStringContainsString('ARTICLE 20 following content', $snippet);

        // The far chunk (index 27, outside the +/-2 window) is NOT pulled in.
        $this->assertStringNotContainsString('ARTICLE 21 far content', $snippet);

        // Stitched in reading order: neighbor-before precedes best precedes neighbor-after.
        $posBefore = strpos($snippet, 'PREJUDICE compensation clause neighbor');
        $posBest = strpos($snippet, 'ARTICLE 19 travaux conservation immeuble');
        $posAfter = strpos($snippet, 'ARTICLE 20 following content');
        $this->assertLessThan($posBest, $posBefore);
        $this->assertLessThan($posAfter, $posBest);
    }

    /**
     * Task 1.2: with the PACKAGE defaults (config/ai.php snippet_length /
     * context_window / snippet_chunks — not a test override) a ±2 window of
     * realistic ~1000-char chunks fits: neighbors on both sides survive the cap.
     * The old 1200 default cut the stitched passage back to ~one chunk.
     *
     * @test
     */
    public function package_default_snippet_length_fits_a_two_chunk_window_of_realistic_chunks(): void
    {
        $package = require __DIR__ . '/../../../../config/ai.php';
        $defaults = $package['file_context'];
        config([
            'ai.file_context.snippet_length' => $defaults['snippet_length'],
            'ai.file_context.context_window' => $defaults['context_window'],
            'ai.file_context.snippet_chunks' => $defaults['snippet_chunks'],
            'ai.file_context.min_relevance_score' => 0.4,
        ]);
        $this->assertSame(2, (int) $defaults['context_window'], 'precondition: package window is ±2');

        $user = new \stdClass();
        $user->id = 1;

        // ~1000-char chunks, the ingestion chunk size.
        $body = fn (string $tag) => $tag . ' ' . str_repeat('lorem ipsum dolor sit amet ', 36);
        $allChunks = [];
        foreach ([22 => 'FAR-BEFORE-22', 23 => 'NEIGHBOR-BEFORE-23', 24 => 'BEST-24', 25 => 'NEIGHBOR-AFTER-25', 26 => 'FAR-AFTER-26'] as $i => $tag) {
            $allChunks[] = $this->fileChunk(1, 'decl.pdf', $body($tag), $i);
        }
        $best = $allChunks[2];

        $chunkStore = Mockery::mock(ChunkStoreInterface::class);
        $chunkStore->shouldReceive('getFileChunks')->andReturn($allChunks);

        $this->searchService->shouldReceive('searchByContent')->once()->andReturn([[
            'file_id' => 1,
            'collection' => '__primary__',
            'score' => 0.61,
            'chunk_count' => 1,
            'best_chunk' => $best,
            'chunks' => [$best],
        ]]);
        $this->accessResolver->shouldReceive('filterAccessibleFileIds')->once()->andReturn([1]);

        $provider = new FileContextProvider(
            $this->searchService,
            $this->accessResolver,
            $this->filenameExtractor,
            null,
            $chunkStore
        );

        $snippet = $provider->searchRelevantFiles('query', $user)[0]['snippet'];

        foreach (['FAR-BEFORE-22', 'NEIGHBOR-BEFORE-23', 'BEST-24', 'NEIGHBOR-AFTER-25', 'FAR-AFTER-26'] as $tag) {
            $this->assertStringContainsString($tag, $snippet, "±2 window chunk {$tag} was cut by the default snippet_length");
        }
    }

    /**
     * Content search results for three files a (0.90), b (0.80), c (0.70).
     */
    private function threeFileContentResults(): array
    {
        $results = [];
        foreach ([[1, 'a.pdf', 0.90], [2, 'b.pdf', 0.80], [3, 'c.pdf', 0.70]] as [$id, $name, $score]) {
            $chunk = $this->fileChunk($id, $name, "content of {$name}", 0);
            $results[] = [
                'file_id' => $id,
                'collection' => '__primary__',
                'score' => $score,
                'chunk_count' => 1,
                'best_chunk' => $chunk,
                'chunks' => [$chunk],
            ];
        }

        return $results;
    }

    /**
     * Task 1.11: the final order is raw relevance.
     *
     * @test
     */
    public function the_final_order_is_raw_relevance(): void
    {
        config(['ai.file_context.min_relevance_score' => 0.4]);
        $user = (object) ['id' => 1];

        $this->searchService->shouldReceive('searchByContent')->once()->andReturn(array_reverse($this->threeFileContentResults()));
        $this->accessResolver->shouldReceive('filterAccessibleFileIds')->once()->andReturn([1, 2, 3]);

        $provider = new FileContextProvider(
            $this->searchService,
            $this->accessResolver,
            $this->filenameExtractor
        );

        $result = $provider->searchRelevantFiles('q', $user);

        $this->assertSame(['a.pdf', 'b.pdf', 'c.pdf'], array_column($result, 'filename'));
    }

    /**
     * Task 1.12: a host priority boost is a TIEBREAK, never the primary key. A
     * 0.41 team file (+1.0) must not outrank a 0.80 law chunk (+0.5).
     *
     * @test
     */
    public function host_priority_score_is_only_a_tiebreak_after_relevance(): void
    {
        config(['ai.file_context.min_relevance_score' => 0.4]);
        $user = (object) ['id' => 1];

        $team = $this->fileChunk(1, 'team-rules.pdf', 'team rules', 0);
        $law = $this->fileChunk(2, 'civil-code.pdf', 'law article', 0);
        $tieA = $this->fileChunk(3, 'tie-low-priority.pdf', 'tie a', 0);
        $tieB = $this->fileChunk(4, 'tie-high-priority.pdf', 'tie b', 0);
        $row = fn ($id, $chunk, $score) => [
            'file_id' => $id, 'collection' => '__primary__', 'score' => $score,
            'chunk_count' => 1, 'best_chunk' => $chunk, 'chunks' => [$chunk],
        ];

        $this->searchService->shouldReceive('searchByContent')->once()->andReturn([
            $row(2, $law, 0.80), $row(3, $tieA, 0.60), $row(4, $tieB, 0.60), $row(1, $team, 0.41),
        ]);
        $this->accessResolver->shouldReceive('filterAccessibleFileIds')->once()->andReturn([1, 2, 3, 4]);

        $enricher = new class implements \Condoedge\Ai\Contracts\FileContextEnricherInterface {
            public function enrich(array $files, string $question, mixed $user): array
            {
                $boost = ['team-rules.pdf' => 1.0, 'civil-code.pdf' => 0.5, 'tie-low-priority.pdf' => 0.1, 'tie-high-priority.pdf' => 0.9];
                foreach ($files as &$file) {
                    $file['priority_score'] = $file['relevance'] + ($boost[$file['filename']] ?? 0.0);
                }

                return $files;
            }
        };

        $provider = new FileContextProvider(
            $this->searchService,
            $this->accessResolver,
            $this->filenameExtractor,
            $enricher
        );

        $result = $provider->searchRelevantFiles('q', $user);

        $this->assertSame(
            ['civil-code.pdf', 'tie-high-priority.pdf', 'tie-low-priority.pdf', 'team-rules.pdf'],
            array_column($result, 'filename'),
            'relevance leads; priority_score only breaks the 0.60 tie'
        );
    }

    /**
     * Task 1.29 (supersedes M-2): when FileSearchService fused collections, the
     * fusion_score decides which candidates survive THIS angle's cut (pool order:
     * explicit filename match, then fused content by fusion) — but it is a
     * per-angle, file-level number, so it is NOT carried onto the references and
     * does not order them: every collection is searched with the same query
     * embedding, so the final order is cosine relevance (priority tiebreak).
     *
     * @test
     */
    public function fusion_cuts_the_pool_but_references_are_ordered_by_cosine_and_carry_no_fusion_score(): void
    {
        config(['ai.file_context.min_relevance_score' => 0.4]);
        $user = (object) ['id' => 1];

        $a = $this->fileChunk(1, 'a-high-cosine.pdf', 'a', 0);
        $b = $this->fileChunk(2, 'b-top-fused.pdf', 'b', 0);
        $c = $this->fileChunk(4, 'c-weak-fused.pdf', 'c', 0);
        $n = $this->fileChunk(3, 'named.pdf', 'n', 0);
        $row = fn ($id, $chunk, $score, $extra = []) => [
            'file_id' => $id, 'collection' => '__primary__', 'score' => $score,
            'chunk_count' => 1, 'best_chunk' => $chunk, 'chunks' => [$chunk],
        ] + $extra;

        $this->filenameExtractor->shouldReceive('extract')->andReturn(['named.pdf']);
        $this->searchService->shouldReceive('searchByFilename')->once()->andReturn([$row(3, $n, 0.85)]);
        $this->searchService->shouldReceive('searchByContent')->once()->andReturn([
            $row(1, $a, 0.90, ['fusion_score' => 0.016]),
            $row(2, $b, 0.50, ['fusion_score' => 0.033]),
            $row(4, $c, 0.95, ['fusion_score' => 0.010]),
        ]);
        $this->accessResolver->shouldReceive('filterAccessibleFileIds')->once()->andReturn([1, 2, 3, 4]);

        $provider = new FileContextProvider(
            $this->searchService,
            $this->accessResolver,
            $this->filenameExtractor
        );

        // limit 3: the fusion-ordered pool [named, b, a, c] is cut after a.
        $result = $provider->searchRelevantFiles('q', $user, ['limit' => 3]);

        $this->assertSame(['a-high-cosine.pdf', 'named.pdf', 'b-top-fused.pdf'], array_column($result, 'filename'));
        $this->assertSame([0.90, 0.85, 0.50], array_column($result, 'relevance'), 'relevance stays raw cosine');
        foreach ($result as $reference) {
            $this->assertArrayNotHasKey('fusion_score', $reference);
        }
    }

    /** @test */
    public function context_window_zero_is_a_no_op_and_never_calls_the_chunk_store(): void
    {
        config(['ai.file_context.context_window' => 0]);
        config(['ai.file_context.snippet_chunks' => 5]);
        config(['ai.file_context.snippet_length' => 4000]);
        // The right document scores low under a cross-lingual probe; admit it.
        config(['ai.file_context.min_relevance_score' => 0.4]);

        $user = new \stdClass();
        $user->id = 1;

        $best = $this->fileChunk(1, 'decl.pdf', 'ONLY-RETRIEVED-CHUNK', 24);

        // With the window disabled the chunk store must never be touched.
        $chunkStore = Mockery::mock(ChunkStoreInterface::class);
        $chunkStore->shouldReceive('getFileChunks')->never();

        $this->searchService
            ->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'collection' => '__primary__',
                    'score' => 0.61,
                    'chunk_count' => 1,
                    'best_chunk' => $best,
                    'chunks' => [$best],
                ],
            ]);

        $this->accessResolver
            ->shouldReceive('filterAccessibleFileIds')
            ->once()
            ->andReturn([1]);

        $provider = new FileContextProvider(
            $this->searchService,
            $this->accessResolver,
            $this->filenameExtractor,
            null,
            $chunkStore
        );

        $result = $provider->searchRelevantFiles('query', $user);

        $this->assertCount(1, $result);
        // Behaviour identical to today: only the retrieved chunk's content.
        $this->assertSame('ONLY-RETRIEVED-CHUNK', $result[0]['snippet']);
    }

    /**
     * Build a plain FileChunk, the shape FileSearchService returns inside a
     * result's chunks[] (no per-chunk score; only the file's best_chunk is known).
     */
    private function fileChunk(int $fileId, string $name, string $content, int $index): FileChunk
    {
        return new FileChunk(
            fileId: $fileId,
            fileName: $name,
            content: $content,
            embedding: [],
            chunkIndex: $index,
            totalChunks: 3,
            startPosition: 0,
            endPosition: mb_strlen($content),
            metadata: []
        );
    }
}
