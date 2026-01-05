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
}
