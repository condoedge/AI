<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\ResponseFileEnricher;
use Orchestra\Testbench\TestCase;

class ResponseFileEnricherTest extends TestCase
{
    private ResponseFileEnricher $enricher;

    protected function getPackageProviders($app): array
    {
        return [];
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->enricher = new ResponseFileEnricher();
    }

    // =========================================================================
    // extractCitationMarkers tests
    // =========================================================================

    /** @test */
    public function it_extracts_citation_markers_from_response(): void
    {
        $response = 'According to the documentation [1], the feature works as expected. See also [2] for more details.';

        $markers = $this->enricher->extractCitationMarkers($response);

        $this->assertIsArray($markers);
        $this->assertCount(2, $markers);
        $this->assertContains(1, $markers);
        $this->assertContains(2, $markers);
    }

    /** @test */
    public function it_extracts_multiple_citations_including_duplicates(): void
    {
        $response = 'The first point [1] is important. As mentioned in [1], this is key. Also see [3].';

        $markers = $this->enricher->extractCitationMarkers($response);

        // Should return unique markers only
        $this->assertCount(2, $markers);
        $this->assertContains(1, $markers);
        $this->assertContains(3, $markers);
    }

    /** @test */
    public function it_returns_empty_array_when_no_citations_found(): void
    {
        $response = 'This response has no citations at all.';

        $markers = $this->enricher->extractCitationMarkers($response);

        $this->assertIsArray($markers);
        $this->assertEmpty($markers);
    }

    /** @test */
    public function it_extracts_double_digit_citation_markers(): void
    {
        $response = 'See reference [10] and [15] for more information.';

        $markers = $this->enricher->extractCitationMarkers($response);

        $this->assertCount(2, $markers);
        $this->assertContains(10, $markers);
        $this->assertContains(15, $markers);
    }

    /** @test */
    public function it_handles_citations_at_start_and_end_of_response(): void
    {
        $response = '[1] The first item. And the last item [5]';

        $markers = $this->enricher->extractCitationMarkers($response);

        $this->assertCount(2, $markers);
        $this->assertContains(1, $markers);
        $this->assertContains(5, $markers);
    }

    /** @test */
    public function it_handles_consecutive_citations(): void
    {
        $response = 'Multiple sources [1][2][3] confirm this.';

        $markers = $this->enricher->extractCitationMarkers($response);

        $this->assertCount(3, $markers);
        $this->assertContains(1, $markers);
        $this->assertContains(2, $markers);
        $this->assertContains(3, $markers);
    }

    /** @test */
    public function it_ignores_non_numeric_brackets(): void
    {
        $response = 'The array [items] and object [data] are not citations. But [1] is.';

        $markers = $this->enricher->extractCitationMarkers($response);

        $this->assertCount(1, $markers);
        $this->assertContains(1, $markers);
    }

    // =========================================================================
    // buildReferencedFiles tests
    // =========================================================================

    /** @test */
    public function it_builds_referenced_files_for_cited_files_only(): void
    {
        $response = 'According to [1], this is correct.';

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 123,
                    'file_name' => 'document.pdf',
                    'snippet' => 'This is some relevant content.',
                    'relevance_score' => 0.85,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
                [
                    'file_id' => 456,
                    'file_name' => 'other.pdf',
                    'snippet' => 'Other content not cited.',
                    'relevance_score' => 0.75,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
            ],
            'file_count' => 2,
            'has_physical' => false,
            'has_database' => true,
        ];

        $result = $this->enricher->buildReferencedFiles($response, $fileContext);

        // Only file [1] should be included (first file in relevant_files)
        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['ref']);
        $this->assertEquals(123, $result[0]['id']);
        $this->assertEquals('document.pdf', $result[0]['name']);
    }

    /** @test */
    public function it_includes_all_required_fields_for_database_files(): void
    {
        $response = 'Check file [1] for details.';

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 42,
                    'file_name' => 'report.pdf',
                    'snippet' => 'Report content snippet.',
                    'relevance_score' => 0.92,
                    'chunk_index' => 2,
                    'source' => 'database',
                ],
            ],
            'file_count' => 1,
            'has_physical' => false,
            'has_database' => true,
        ];

        $options = [
            'download_url_resolver' => fn($id) => "/files/{$id}/download",
            'preview_url_resolver' => fn($id) => "/files/{$id}/preview",
            'can_download_resolver' => fn($id, $user) => true,
        ];

        $result = $this->enricher->buildReferencedFiles($response, $fileContext, $options);

        $this->assertCount(1, $result);

        $file = $result[0];
        $this->assertArrayHasKey('ref', $file);
        $this->assertArrayHasKey('id', $file);
        $this->assertArrayHasKey('name', $file);
        $this->assertArrayHasKey('snippet', $file);
        $this->assertArrayHasKey('relevance_score', $file);
        $this->assertArrayHasKey('source', $file);
        $this->assertArrayHasKey('chunk_index', $file);
        $this->assertArrayHasKey('download_url', $file);
        $this->assertArrayHasKey('preview_url', $file);
        $this->assertArrayHasKey('can_download', $file);

        $this->assertEquals(1, $file['ref']);
        $this->assertEquals(42, $file['id']);
        $this->assertEquals('report.pdf', $file['name']);
        $this->assertEquals('Report content snippet.', $file['snippet']);
        $this->assertEquals(0.92, $file['relevance_score']);
        $this->assertEquals('database', $file['source']);
        $this->assertEquals(2, $file['chunk_index']);
        $this->assertEquals('/files/42/download', $file['download_url']);
        $this->assertEquals('/files/42/preview', $file['preview_url']);
        $this->assertTrue($file['can_download']);
    }

    /** @test */
    public function it_handles_physical_files_with_null_urls(): void
    {
        $response = 'See documentation [1] for more.';

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 'physical:/docs/readme.md',
                    'file_name' => 'readme.md',
                    'snippet' => 'Documentation snippet.',
                    'relevance_score' => 0.88,
                    'chunk_index' => 0,
                    'source' => 'physical',
                ],
            ],
            'file_count' => 1,
            'has_physical' => true,
            'has_database' => false,
        ];

        $result = $this->enricher->buildReferencedFiles($response, $fileContext);

        $this->assertCount(1, $result);

        $file = $result[0];
        $this->assertEquals('physical', $file['source']);
        $this->assertNull($file['download_url']);
        $this->assertNull($file['preview_url']);
        $this->assertFalse($file['can_download']);
    }

    /** @test */
    public function it_builds_multiple_referenced_files(): void
    {
        $response = 'First [1], then [2], finally [3].';

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 1,
                    'file_name' => 'first.pdf',
                    'snippet' => 'First snippet.',
                    'relevance_score' => 0.9,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
                [
                    'file_id' => 2,
                    'file_name' => 'second.pdf',
                    'snippet' => 'Second snippet.',
                    'relevance_score' => 0.85,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
                [
                    'file_id' => 3,
                    'file_name' => 'third.pdf',
                    'snippet' => 'Third snippet.',
                    'relevance_score' => 0.8,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
            ],
            'file_count' => 3,
            'has_physical' => false,
            'has_database' => true,
        ];

        $result = $this->enricher->buildReferencedFiles($response, $fileContext);

        $this->assertCount(3, $result);
        $this->assertEquals(1, $result[0]['ref']);
        $this->assertEquals(2, $result[1]['ref']);
        $this->assertEquals(3, $result[2]['ref']);
    }

    /** @test */
    public function it_returns_empty_array_when_no_citations_in_response(): void
    {
        $response = 'This response has no file citations.';

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 1,
                    'file_name' => 'unused.pdf',
                    'snippet' => 'Unused content.',
                    'relevance_score' => 0.9,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
            ],
            'file_count' => 1,
            'has_physical' => false,
            'has_database' => true,
        ];

        $result = $this->enricher->buildReferencedFiles($response, $fileContext);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /** @test */
    public function it_handles_citation_markers_beyond_file_count(): void
    {
        $response = 'See [1] and [5] for details.';

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 1,
                    'file_name' => 'only.pdf',
                    'snippet' => 'Only file.',
                    'relevance_score' => 0.9,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
            ],
            'file_count' => 1,
            'has_physical' => false,
            'has_database' => true,
        ];

        $result = $this->enricher->buildReferencedFiles($response, $fileContext);

        // Only [1] should be included since [5] is beyond file count
        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['ref']);
    }

    /** @test */
    public function it_uses_user_from_options_for_can_download_resolver(): void
    {
        $response = 'See [1] for details.';

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 42,
                    'file_name' => 'file.pdf',
                    'snippet' => 'Content.',
                    'relevance_score' => 0.9,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
            ],
            'file_count' => 1,
            'has_physical' => false,
            'has_database' => true,
        ];

        $user = new \stdClass();
        $user->id = 123;
        $user->role = 'admin';

        $capturedUser = null;
        $options = [
            'user' => $user,
            'can_download_resolver' => function ($id, $u) use (&$capturedUser) {
                $capturedUser = $u;
                return $u->role === 'admin';
            },
        ];

        $result = $this->enricher->buildReferencedFiles($response, $fileContext, $options);

        $this->assertSame($user, $capturedUser);
        $this->assertTrue($result[0]['can_download']);
    }

    /** @test */
    public function it_handles_empty_file_context(): void
    {
        $response = 'See [1] for details.';

        $fileContext = [
            'relevant_files' => [],
            'file_count' => 0,
            'has_physical' => false,
            'has_database' => false,
        ];

        $result = $this->enricher->buildReferencedFiles($response, $fileContext);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /** @test */
    public function it_uses_default_can_download_true_when_resolver_not_provided(): void
    {
        $response = 'See [1] for details.';

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 1,
                    'file_name' => 'file.pdf',
                    'snippet' => 'Content.',
                    'relevance_score' => 0.9,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
            ],
            'file_count' => 1,
            'has_physical' => false,
            'has_database' => true,
        ];

        // No can_download_resolver provided
        $options = [
            'download_url_resolver' => fn($id) => "/files/{$id}/download",
            'preview_url_resolver' => fn($id) => "/files/{$id}/preview",
        ];

        $result = $this->enricher->buildReferencedFiles($response, $fileContext, $options);

        $this->assertTrue($result[0]['can_download']);
    }

    // =========================================================================
    // enrichResponse tests
    // =========================================================================

    /** @test */
    public function it_enriches_response_with_referenced_files(): void
    {
        $response = [
            'content' => 'According to [1], this is correct.',
            'success' => true,
        ];

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 1,
                    'file_name' => 'document.pdf',
                    'snippet' => 'Document content.',
                    'relevance_score' => 0.85,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
            ],
            'file_count' => 1,
            'has_physical' => false,
            'has_database' => true,
        ];

        $result = $this->enricher->enrichResponse($response, $fileContext);

        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('referenced_files', $result);
        $this->assertArrayHasKey('has_file_references', $result);

        $this->assertEquals('According to [1], this is correct.', $result['content']);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['has_file_references']);
        $this->assertCount(1, $result['referenced_files']);
    }

    /** @test */
    public function it_sets_has_file_references_to_false_when_no_citations(): void
    {
        $response = [
            'content' => 'No citations here.',
            'success' => true,
        ];

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 1,
                    'file_name' => 'document.pdf',
                    'snippet' => 'Document content.',
                    'relevance_score' => 0.85,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
            ],
            'file_count' => 1,
            'has_physical' => false,
            'has_database' => true,
        ];

        $result = $this->enricher->enrichResponse($response, $fileContext);

        $this->assertFalse($result['has_file_references']);
        $this->assertEmpty($result['referenced_files']);
    }

    /** @test */
    public function it_preserves_all_original_response_fields(): void
    {
        $response = [
            'content' => 'See [1] for details.',
            'success' => true,
            'tokens_used' => 150,
            'model' => 'gpt-4',
            'metadata' => ['key' => 'value'],
        ];

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 1,
                    'file_name' => 'file.pdf',
                    'snippet' => 'Content.',
                    'relevance_score' => 0.9,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
            ],
            'file_count' => 1,
            'has_physical' => false,
            'has_database' => true,
        ];

        $result = $this->enricher->enrichResponse($response, $fileContext);

        $this->assertEquals('See [1] for details.', $result['content']);
        $this->assertTrue($result['success']);
        $this->assertEquals(150, $result['tokens_used']);
        $this->assertEquals('gpt-4', $result['model']);
        $this->assertEquals(['key' => 'value'], $result['metadata']);
    }

    /** @test */
    public function it_passes_options_to_build_referenced_files(): void
    {
        $response = [
            'content' => 'Check [1] for info.',
        ];

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 99,
                    'file_name' => 'special.pdf',
                    'snippet' => 'Special content.',
                    'relevance_score' => 0.95,
                    'chunk_index' => 1,
                    'source' => 'database',
                ],
            ],
            'file_count' => 1,
            'has_physical' => false,
            'has_database' => true,
        ];

        $options = [
            'download_url_resolver' => fn($id) => "/custom/{$id}/dl",
            'preview_url_resolver' => fn($id) => "/custom/{$id}/view",
            'can_download_resolver' => fn($id, $user) => false,
        ];

        $result = $this->enricher->enrichResponse($response, $fileContext, $options);

        $referencedFile = $result['referenced_files'][0];
        $this->assertEquals('/custom/99/dl', $referencedFile['download_url']);
        $this->assertEquals('/custom/99/view', $referencedFile['preview_url']);
        $this->assertFalse($referencedFile['can_download']);
    }

    /** @test */
    public function it_handles_empty_response_content(): void
    {
        $response = [
            'content' => '',
            'success' => true,
        ];

        $fileContext = [
            'relevant_files' => [],
            'file_count' => 0,
            'has_physical' => false,
            'has_database' => false,
        ];

        $result = $this->enricher->enrichResponse($response, $fileContext);

        $this->assertEquals('', $result['content']);
        $this->assertFalse($result['has_file_references']);
        $this->assertEmpty($result['referenced_files']);
    }

    /** @test */
    public function it_extracts_content_from_response_array_for_citation_extraction(): void
    {
        $response = [
            'content' => 'First [1] then [2].',
            'other_field' => 'ignored',
        ];

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 1,
                    'file_name' => 'first.pdf',
                    'snippet' => 'First content.',
                    'relevance_score' => 0.9,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
                [
                    'file_id' => 2,
                    'file_name' => 'second.pdf',
                    'snippet' => 'Second content.',
                    'relevance_score' => 0.85,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
            ],
            'file_count' => 2,
            'has_physical' => false,
            'has_database' => true,
        ];

        $result = $this->enricher->enrichResponse($response, $fileContext);

        $this->assertTrue($result['has_file_references']);
        $this->assertCount(2, $result['referenced_files']);
    }

    /** @test */
    public function it_enrich_response_uses_answer_key(): void
    {
        $response = [
            'answer' => 'Based on the document [1], the policy states...',
            'insights' => [],
        ];

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 1,
                    'filename' => 'policy.pdf',
                    'snippet' => 'The policy states that...',
                    'relevance' => 0.95,
                    'source' => 'database',
                    'chunk_index' => 0,
                ],
            ],
        ];

        $result = $this->enricher->enrichResponse($response, $fileContext);

        // Should find the [1] citation
        $this->assertTrue($result['has_file_references']);
        $this->assertCount(1, $result['referenced_files']);
        $this->assertEquals(1, $result['referenced_files'][0]['ref']);
    }

    /** @test */
    public function it_enrich_response_falls_back_to_content_key(): void
    {
        // Test backwards compatibility with 'content' key
        $response = [
            'content' => 'See file [1] for details.',
        ];

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 2,
                    'filename' => 'details.txt',
                    'snippet' => 'Details here...',
                    'relevance' => 0.9,
                    'source' => 'database',
                    'chunk_index' => 1,
                ],
            ],
        ];

        $result = $this->enricher->enrichResponse($response, $fileContext);

        $this->assertTrue($result['has_file_references']);
    }

    /** @test */
    public function it_enrich_response_prefers_answer_over_content(): void
    {
        // When both 'answer' and 'content' are present, 'answer' should be used
        $response = [
            'answer' => 'The answer cites [1] from the file.',
            'content' => 'The content does not cite anything.',
        ];

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 1,
                    'filename' => 'document.pdf',
                    'snippet' => 'Document content.',
                    'relevance' => 0.85,
                    'source' => 'database',
                    'chunk_index' => 0,
                ],
            ],
        ];

        $result = $this->enricher->enrichResponse($response, $fileContext);

        // Should find [1] from 'answer' key, not miss it because 'content' was checked
        $this->assertTrue($result['has_file_references']);
        $this->assertCount(1, $result['referenced_files']);
        $this->assertEquals(1, $result['referenced_files'][0]['ref']);
    }

    // =========================================================================
    // Mixed source tests
    // =========================================================================

    /** @test */
    public function it_handles_mixed_physical_and_database_files(): void
    {
        $response = 'See [1] for docs and [2] for data.';

        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 'physical:/docs/guide.md',
                    'file_name' => 'guide.md',
                    'snippet' => 'Guide content.',
                    'relevance_score' => 0.9,
                    'chunk_index' => 0,
                    'source' => 'physical',
                ],
                [
                    'file_id' => 42,
                    'file_name' => 'data.pdf',
                    'snippet' => 'Data content.',
                    'relevance_score' => 0.85,
                    'chunk_index' => 0,
                    'source' => 'database',
                ],
            ],
            'file_count' => 2,
            'has_physical' => true,
            'has_database' => true,
        ];

        $options = [
            'download_url_resolver' => fn($id) => "/files/{$id}/download",
            'preview_url_resolver' => fn($id) => "/files/{$id}/preview",
        ];

        $result = $this->enricher->buildReferencedFiles($response, $fileContext, $options);

        $this->assertCount(2, $result);

        // Physical file
        $physical = $result[0];
        $this->assertEquals(1, $physical['ref']);
        $this->assertEquals('physical:/docs/guide.md', $physical['id']);
        $this->assertEquals('physical', $physical['source']);
        $this->assertNull($physical['download_url']);
        $this->assertNull($physical['preview_url']);
        $this->assertFalse($physical['can_download']);

        // Database file
        $database = $result[1];
        $this->assertEquals(2, $database['ref']);
        $this->assertEquals(42, $database['id']);
        $this->assertEquals('database', $database['source']);
        $this->assertEquals('/files/42/download', $database['download_url']);
        $this->assertEquals('/files/42/preview', $database['preview_url']);
        $this->assertTrue($database['can_download']);
    }
}
