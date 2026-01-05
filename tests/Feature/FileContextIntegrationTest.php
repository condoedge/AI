<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FileAccessResolver;
use Condoedge\Ai\Services\Context\FilenameExtractor;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Services\PromptSections\FileContextSection;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Integration tests for file context retrieval pipeline
 *
 * These tests verify that the complete file context pipeline works correctly,
 * especially the key naming consistency between FileContextProvider and FileContextSection.
 *
 * Since full integration requires real Qdrant/Neo4j infrastructure, these tests
 * mock the storage layer but verify the full flow through FileContextProvider,
 * FilenameExtractor, and FileSearchService layers.
 */
class FileContextIntegrationTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Configure file context settings
        $app['config']->set('ai.file_context.min_relevance_score', 0.5);
        $app['config']->set('ai.file_context.max_references', 5);
        $app['config']->set('ai.file_context.snippet_length', 200);
        $app['config']->set('ai.file_context.security_enabled', true);
        $app['config']->set('ai.file_context.fallback_filters.use_user_filter', true);
        $app['config']->set('ai.file_context.fallback_filters.use_team_filter', false);
    }

    /** @test */
    public function file_context_uses_correct_key_names(): void
    {
        // Arrange: Mock search service to return a result
        $mockSearchService = Mockery::mock(FileSearchService::class);
        $mockSearchService->shouldReceive('searchByContent')
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.85,
                    'chunk_count' => 2,
                    'best_chunk' => (object) [
                        'fileId' => 1,
                        'fileName' => 'bariloche.txt',
                        'content' => 'Trip to Bariloche cost $500',
                        'chunkIndex' => 0,
                    ],
                ],
            ]);

        $mockAccessResolver = Mockery::mock(\Condoedge\Ai\Contracts\FileAccessResolverInterface::class);
        $mockAccessResolver->shouldReceive('filterAccessibleFileIds')->andReturn([1]);
        $mockAccessResolver->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $mockAccessResolver->shouldReceive('isPhysicalFile')->andReturn(false);

        $provider = new FileContextProvider($mockSearchService, $mockAccessResolver);

        // Act
        $result = $provider->searchRelevantFiles('bariloche trip cost', null);

        // Assert: Keys must match FileContextSection expectations
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('filename', $result[0], 'Should use "filename" key, not "file_name"');
        $this->assertArrayHasKey('relevance', $result[0], 'Should use "relevance" key, not "relevance_score"');
        $this->assertEquals('bariloche.txt', $result[0]['filename']);
        $this->assertEquals(0.85, $result[0]['relevance']);
    }

    /** @test */
    public function file_context_section_displays_files_correctly(): void
    {
        $section = new FileContextSection();

        $context = [
            'file_context' => [
                'relevant_files' => [
                    [
                        'filename' => 'bariloche.txt',
                        'relevance' => 0.85,
                        'snippet' => 'Trip expenses...',
                    ],
                ],
            ],
        ];

        $output = $section->format('test question', $context);

        $this->assertStringContainsString('bariloche.txt', $output);
        $this->assertStringContainsString('85%', $output);
        $this->assertStringNotContainsString('unknown', strtolower($output), 'Should not show "unknown" for filename');
    }

    /**
     * @test
     * Note: This test requires the FileModel facade from condoedge/utils package.
     * It's skipped in isolated package tests but works in full application context.
     */
    public function file_access_resolver_uses_fallback_when_scope_missing(): void
    {
        // Skip if FileModel facade is not available (isolated package tests)
        if (!$this->app->bound('file-model')) {
            $this->markTestSkipped('FileModel facade not available in isolated package tests');
        }

        // Configure fallback filters
        config([
            'ai.file_context.security_enabled' => true,
            'ai.file_context.access_scope' => 'nonExistentScope',
            'ai.file_context.fallback_filters.use_user_filter' => true,
            'ai.file_context.fallback_filters.use_team_filter' => false,
        ]);

        $resolver = new FileAccessResolver();

        // Create a mock user
        $user = (object) ['id' => 123];

        // This should not throw, should use fallback
        $result = $resolver->getAccessibleFileIds($user);

        // Result should be an array (possibly empty if no files match)
        $this->assertIsArray($result);
    }

    /** @test */
    public function file_context_provider_returns_consistent_structure(): void
    {
        // Arrange
        $mockSearchService = Mockery::mock(FileSearchService::class);
        $mockSearchService->shouldReceive('searchByContent')
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.9,
                    'chunk_count' => 1,
                    'best_chunk' => (object) [
                        'fileId' => 1,
                        'fileName' => 'test.pdf',
                        'content' => 'Test content for verification',
                        'chunkIndex' => 0,
                    ],
                ],
                [
                    'file_id' => 2,
                    'score' => 0.75,
                    'chunk_count' => 1,
                    'best_chunk' => (object) [
                        'fileId' => 2,
                        'fileName' => 'another.docx',
                        'content' => 'Another document content',
                        'chunkIndex' => 0,
                    ],
                ],
            ]);

        $mockAccessResolver = Mockery::mock(\Condoedge\Ai\Contracts\FileAccessResolverInterface::class);
        $mockAccessResolver->shouldReceive('filterAccessibleFileIds')->andReturn([1, 2]);
        $mockAccessResolver->shouldReceive('shouldEnforceSecurity')->andReturn(true);
        $mockAccessResolver->shouldReceive('isPhysicalFile')->andReturn(false);

        $provider = new FileContextProvider($mockSearchService, $mockAccessResolver);

        $user = (object) ['id' => 1];

        // Act
        $result = $provider->searchRelevantFiles('test query', $user);

        // Assert: All results have consistent structure
        $this->assertCount(2, $result);

        foreach ($result as $file) {
            $this->assertArrayHasKey('file_id', $file);
            $this->assertArrayHasKey('filename', $file);
            $this->assertArrayHasKey('snippet', $file);
            $this->assertArrayHasKey('relevance', $file);
            $this->assertArrayHasKey('chunk_index', $file);
            $this->assertArrayHasKey('source', $file);

            // Verify types
            $this->assertIsString($file['filename']);
            $this->assertIsString($file['snippet']);
            $this->assertIsNumeric($file['relevance']);
            $this->assertIsString($file['source']);
        }
    }

    /** @test */
    public function file_context_section_handles_empty_files(): void
    {
        $section = new FileContextSection();

        $context = [
            'file_context' => [
                'relevant_files' => [],
            ],
        ];

        $output = $section->format('test question', $context);

        // Empty context should return empty or minimal output
        $this->assertIsString($output);
    }

    /** @test */
    public function file_context_section_formats_multiple_files(): void
    {
        $section = new FileContextSection();

        $context = [
            'file_context' => [
                'relevant_files' => [
                    [
                        'filename' => 'document1.pdf',
                        'relevance' => 0.95,
                        'snippet' => 'First document content...',
                    ],
                    [
                        'filename' => 'document2.txt',
                        'relevance' => 0.80,
                        'snippet' => 'Second document content...',
                    ],
                ],
            ],
        ];

        $output = $section->format('test question', $context);

        $this->assertStringContainsString('document1.pdf', $output);
        $this->assertStringContainsString('document2.txt', $output);
        $this->assertStringContainsString('95%', $output);
        $this->assertStringContainsString('80%', $output);
    }

    // =========================================================================
    // Task 5: Integration Tests for Filename Search
    // =========================================================================

    /**
     * @test
     * Test 1: Explicit filename reference finds file with match_type='filename'
     *
     * This test verifies the full integration flow:
     * 1. User query contains explicit filename reference "bariloche_trip.txt"
     * 2. FilenameExtractor detects the filename
     * 3. FileSearchService.searchByFilename is called
     * 4. FileContextProvider returns result with match_type='filename'
     */
    public function test_explicit_filename_reference_finds_file(): void
    {
        // Create mock user
        $user = (object) ['id' => 1];

        // Create a FileChunk that represents the indexed file
        $fileChunk = new FileChunk(
            fileId: 1,
            fileName: 'bariloche_trip.txt',
            content: 'Notes about my trip to Bariloche, Argentina',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 43,
            metadata: []
        );

        // Mock FileSearchService
        $mockSearchService = Mockery::mock(FileSearchService::class);

        // Expect searchByFilename to be called with the detected filename
        $mockSearchService->shouldReceive('searchByFilename')
            ->with('bariloche_trip.txt', Mockery::any())
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 1.0, // Exact filename match
                    'chunk_count' => 1,
                    'best_chunk' => $fileChunk,
                    'chunks' => [$fileChunk],
                ],
            ]);

        // Content search is also called but returns empty (no semantic match needed)
        $mockSearchService->shouldReceive('searchByContent')
            ->once()
            ->andReturn([]);

        // Mock access resolver
        $mockAccessResolver = Mockery::mock(FileAccessResolverInterface::class);
        $mockAccessResolver->shouldReceive('filterAccessibleFileIds')->andReturn([1]);
        $mockAccessResolver->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $mockAccessResolver->shouldReceive('isPhysicalFile')->andReturn(false);

        // Use real FilenameExtractor (not mocked) to verify full integration
        $realFilenameExtractor = new FilenameExtractor();

        // Create provider with mocked services but real FilenameExtractor
        $provider = new FileContextProvider(
            $mockSearchService,
            $mockAccessResolver,
            $realFilenameExtractor
        );

        // Act: Query with explicit filename
        $results = $provider->searchRelevantFiles(
            'What is in the bariloche_trip.txt file?',
            $user
        );

        // Assert
        $this->assertNotEmpty($results, 'Should find at least one file');
        $this->assertEquals('bariloche_trip.txt', $results[0]['filename']);
        $this->assertEquals('filename', $results[0]['match_type'], 'Match type should be "filename" for explicit reference');
        $this->assertEquals(1.0, $results[0]['relevance'], 'Exact filename match should have score 1.0');
        $this->assertEquals(1, $results[0]['file_id']);
    }

    /**
     * @test
     * Test 2: Semantic search still works without explicit filename
     *
     * This test verifies:
     * 1. User query has no explicit filename reference
     * 2. FilenameExtractor returns empty array
     * 3. FileSearchService.searchByContent is called (semantic search)
     * 4. FileContextProvider returns result with match_type='content'
     */
    public function test_semantic_search_still_works_without_filename(): void
    {
        // Create mock user
        $user = (object) ['id' => 1];

        // Create a FileChunk for the file found via semantic search
        $fileChunk = new FileChunk(
            fileId: 2,
            fileName: 'vacation_notes.txt',
            content: 'Beautiful mountains and lakes in Patagonia region',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 49,
            metadata: []
        );

        // Mock FileSearchService
        $mockSearchService = Mockery::mock(FileSearchService::class);

        // searchByFilename should NOT be called (no filename detected)
        $mockSearchService->shouldNotReceive('searchByFilename');

        // Content search returns the file (semantic match)
        $mockSearchService->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 2,
                    'score' => 0.88, // Semantic similarity score
                    'chunk_count' => 1,
                    'best_chunk' => $fileChunk,
                    'chunks' => [$fileChunk],
                ],
            ]);

        // Mock access resolver
        $mockAccessResolver = Mockery::mock(FileAccessResolverInterface::class);
        $mockAccessResolver->shouldReceive('filterAccessibleFileIds')->andReturn([2]);
        $mockAccessResolver->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $mockAccessResolver->shouldReceive('isPhysicalFile')->andReturn(false);

        // Use real FilenameExtractor to verify it correctly returns empty
        $realFilenameExtractor = new FilenameExtractor();

        // Create provider
        $provider = new FileContextProvider(
            $mockSearchService,
            $mockAccessResolver,
            $realFilenameExtractor
        );

        // Act: Query without explicit filename but with relevant content
        $results = $provider->searchRelevantFiles(
            'Tell me about the Patagonia trip',
            $user
        );

        // Assert: Should find via content similarity
        $this->assertNotEmpty($results, 'Should find file via semantic search');
        $this->assertEquals('vacation_notes.txt', $results[0]['filename']);
        $this->assertEquals('content', $results[0]['match_type'], 'Match type should be "content" for semantic search');
        $this->assertEquals(0.88, $results[0]['relevance']);
        $this->assertEquals(2, $results[0]['file_id']);
    }

    /**
     * @test
     * Test 3: Mixed search combines filename and semantic results
     *
     * Verifies that when a query contains both an explicit filename AND
     * semantic keywords, both filename and content matches are returned
     * with appropriate match_type values.
     */
    public function test_mixed_search_combines_filename_and_semantic_results(): void
    {
        $user = (object) ['id' => 1];

        // File found by filename match
        $filenameChunk = new FileChunk(
            fileId: 1,
            fileName: 'budget.xlsx',
            content: 'Q1 budget projections',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 21,
            metadata: []
        );

        // Different file found by semantic match
        $contentChunk = new FileChunk(
            fileId: 2,
            fileName: 'financial_report.pdf',
            content: 'Annual financial overview and budget analysis',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 45,
            metadata: []
        );

        // Mock FileSearchService
        $mockSearchService = Mockery::mock(FileSearchService::class);

        // Filename search finds the budget file
        $mockSearchService->shouldReceive('searchByFilename')
            ->with('budget.xlsx', Mockery::any())
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 1.0,
                    'chunk_count' => 1,
                    'best_chunk' => $filenameChunk,
                    'chunks' => [$filenameChunk],
                ],
            ]);

        // Content search finds the financial report
        $mockSearchService->shouldReceive('searchByContent')
            ->once()
            ->andReturn([
                [
                    'file_id' => 2,
                    'score' => 0.92,
                    'chunk_count' => 1,
                    'best_chunk' => $contentChunk,
                    'chunks' => [$contentChunk],
                ],
            ]);

        // Mock access resolver
        $mockAccessResolver = Mockery::mock(FileAccessResolverInterface::class);
        $mockAccessResolver->shouldReceive('filterAccessibleFileIds')->andReturn([1, 2]);
        $mockAccessResolver->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $mockAccessResolver->shouldReceive('isPhysicalFile')->andReturn(false);

        $provider = new FileContextProvider(
            $mockSearchService,
            $mockAccessResolver,
            new FilenameExtractor()
        );

        // Act: Query with both filename and semantic content
        $results = $provider->searchRelevantFiles(
            'Check budget.xlsx for financial analysis',
            $user
        );

        // Assert: Both results should be present
        $this->assertCount(2, $results, 'Should find both filename and content matches');

        // Results should be sorted by score (1.0 filename match first, then 0.92 content match)
        $this->assertEquals('budget.xlsx', $results[0]['filename']);
        $this->assertEquals('filename', $results[0]['match_type']);
        $this->assertEquals(1.0, $results[0]['relevance']);

        $this->assertEquals('financial_report.pdf', $results[1]['filename']);
        $this->assertEquals('content', $results[1]['match_type']);
        $this->assertEquals(0.92, $results[1]['relevance']);
    }

    /**
     * @test
     * Test 4: FilenameExtractor correctly detects filenames in various formats
     *
     * This is a focused test on the FilenameExtractor component which is
     * critical to the filename search feature.
     */
    public function test_filename_extractor_detects_various_filename_formats(): void
    {
        $extractor = new FilenameExtractor();

        // Test 1: Basic filename
        $filenames = $extractor->extract('What is in report.pdf?');
        $this->assertContains('report.pdf', $filenames);

        // Test 2: Filename with underscores
        $filenames = $extractor->extract('Show me the bariloche_trip.txt file');
        $this->assertContains('bariloche_trip.txt', $filenames);

        // Test 3: Filename with hyphens
        $filenames = $extractor->extract('Open my-notes.md please');
        $this->assertContains('my-notes.md', $filenames);

        // Test 4: Multiple filenames in one query
        $filenames = $extractor->extract('Compare budget.xlsx with expenses.csv');
        $this->assertContains('budget.xlsx', $filenames);
        $this->assertContains('expenses.csv', $filenames);

        // Test 5: Quoted filename with spaces
        $filenames = $extractor->extract('Find "my document.pdf" in the archive');
        $this->assertContains('my document.pdf', $filenames);

        // Test 6: No filename in query
        $filenames = $extractor->extract('Tell me about the Patagonia trip');
        $this->assertEmpty($filenames);
    }

    /**
     * @test
     * Test 5: Deduplication when same file matches both filename and content
     *
     * When the same file is found by both filename and content search,
     * the filename match should take priority (higher confidence).
     */
    public function test_deduplication_prefers_filename_match_over_content_match(): void
    {
        $user = (object) ['id' => 1];

        // Same file matched by both searches
        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'budget.xlsx',
            content: 'Q1 budget projections and financial analysis',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 44,
            metadata: []
        );

        $mockSearchService = Mockery::mock(FileSearchService::class);

        // Filename search returns the file with score 1.0
        $mockSearchService->shouldReceive('searchByFilename')
            ->once()
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 1.0,
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);

        // Content search also returns the same file with lower score
        $mockSearchService->shouldReceive('searchByContent')
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

        $mockAccessResolver = Mockery::mock(FileAccessResolverInterface::class);
        $mockAccessResolver->shouldReceive('filterAccessibleFileIds')->andReturn([1]);
        $mockAccessResolver->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $mockAccessResolver->shouldReceive('isPhysicalFile')->andReturn(false);

        $provider = new FileContextProvider(
            $mockSearchService,
            $mockAccessResolver,
            new FilenameExtractor()
        );

        $results = $provider->searchRelevantFiles('Show budget.xlsx financial data', $user);

        // Assert: Only one result (deduplicated), with filename match priority
        $this->assertCount(1, $results, 'Should deduplicate to single result');
        $this->assertEquals('filename', $results[0]['match_type'], 'Filename match should take priority');
        $this->assertEquals(1.0, $results[0]['relevance'], 'Should use filename match score');
    }

    /**
     * @test
     * Test 6: Access control is applied to filename search results
     *
     * Verifies that even when a file is found by filename,
     * access control filtering is still applied.
     */
    public function test_access_control_applied_to_filename_search(): void
    {
        $user = (object) ['id' => 1];

        // Two files found by filename
        $accessibleChunk = new FileChunk(
            fileId: 1,
            fileName: 'public_report.pdf',
            content: 'Public information',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 18,
            metadata: []
        );

        $restrictedChunk = new FileChunk(
            fileId: 2,
            fileName: 'secret_report.pdf',
            content: 'Confidential information',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 24,
            metadata: []
        );

        $mockSearchService = Mockery::mock(FileSearchService::class);

        // Filename search returns both files
        $mockSearchService->shouldReceive('searchByFilename')
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 0.9,
                    'chunk_count' => 1,
                    'best_chunk' => $accessibleChunk,
                    'chunks' => [$accessibleChunk],
                ],
                [
                    'file_id' => 2,
                    'score' => 0.9,
                    'chunk_count' => 1,
                    'best_chunk' => $restrictedChunk,
                    'chunks' => [$restrictedChunk],
                ],
            ]);

        $mockSearchService->shouldReceive('searchByContent')
            ->andReturn([]);

        // Access resolver only allows file 1
        $mockAccessResolver = Mockery::mock(FileAccessResolverInterface::class);
        $mockAccessResolver->shouldReceive('filterAccessibleFileIds')
            ->with([1, 2], $user)
            ->andReturn([1]); // Only file 1 is accessible
        $mockAccessResolver->shouldReceive('shouldEnforceSecurity')->andReturn(true);
        $mockAccessResolver->shouldReceive('isPhysicalFile')->andReturn(false);

        $provider = new FileContextProvider(
            $mockSearchService,
            $mockAccessResolver,
            new FilenameExtractor()
        );

        $results = $provider->searchRelevantFiles('Find report.pdf', $user);

        // Assert: Only accessible file is returned
        $this->assertCount(1, $results);
        $this->assertEquals('public_report.pdf', $results[0]['filename']);
        $this->assertEquals(1, $results[0]['file_id']);
    }

    /**
     * @test
     * Test 7: Full flow integration with FileContextSection formatting
     *
     * Verifies the complete pipeline from search to prompt formatting.
     */
    public function test_full_flow_from_search_to_prompt_formatting(): void
    {
        $user = (object) ['id' => 1];

        $chunk = new FileChunk(
            fileId: 1,
            fileName: 'report.pdf',
            content: 'This is the report content with important findings.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 51,
            metadata: []
        );

        $mockSearchService = Mockery::mock(FileSearchService::class);
        $mockSearchService->shouldReceive('searchByFilename')
            ->andReturn([
                [
                    'file_id' => 1,
                    'score' => 1.0,
                    'chunk_count' => 1,
                    'best_chunk' => $chunk,
                    'chunks' => [$chunk],
                ],
            ]);
        $mockSearchService->shouldReceive('searchByContent')
            ->andReturn([]);

        $mockAccessResolver = Mockery::mock(FileAccessResolverInterface::class);
        $mockAccessResolver->shouldReceive('filterAccessibleFileIds')->andReturn([1]);
        $mockAccessResolver->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $mockAccessResolver->shouldReceive('isPhysicalFile')->andReturn(false);

        $provider = new FileContextProvider(
            $mockSearchService,
            $mockAccessResolver,
            new FilenameExtractor()
        );

        // Step 1: Search for files
        $results = $provider->searchRelevantFiles('What is in report.pdf?', $user);

        // Step 2: Build context for FileContextSection
        $context = [
            'file_context' => [
                'relevant_files' => $results,
            ],
        ];

        // Step 3: Format with FileContextSection
        $section = new FileContextSection();
        $output = $section->format('What is in report.pdf?', $context);

        // Assert: Full pipeline produces expected output
        $this->assertStringContainsString('report.pdf', $output);
        $this->assertStringContainsString('100%', $output); // 1.0 relevance = 100%
        $this->assertStringContainsString('important findings', $output); // snippet content
    }
}
