<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FileAccessResolver;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Services\PromptSections\FileContextSection;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Integration tests for file context retrieval pipeline
 *
 * These tests verify that the complete file context pipeline works correctly,
 * especially the key naming consistency between FileContextProvider and FileContextSection.
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
}
