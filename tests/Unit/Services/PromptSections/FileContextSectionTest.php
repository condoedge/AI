<?php

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\Services\PromptSections\FileContextSection;
use Condoedge\Ai\Tests\TestCase;

class FileContextSectionTest extends TestCase
{
    private FileContextSection $section;

    public function setUp(): void
    {
        parent::setUp();
        $this->section = new FileContextSection();
    }

    /** @test */
    public function it_has_correct_name_and_priority(): void
    {
        $this->assertEquals('file_context', $this->section->getName());
        $this->assertEquals(45, $this->section->getPriority()); // Before similar_queries (50)
    }

    /** @test */
    public function it_should_not_include_when_no_files_in_context(): void
    {
        $result = $this->section->shouldInclude('question', [], []);
        $this->assertFalse($result);

        $result = $this->section->shouldInclude('question', ['file_context' => []], []);
        $this->assertFalse($result);

        $result = $this->section->shouldInclude('question', ['file_context' => ['relevant_files' => []]], []);
        $this->assertFalse($result);
    }

    /** @test */
    public function it_should_include_when_files_present(): void
    {
        $context = [
            'file_context' => [
                'relevant_files' => [
                    [
                        'filename' => 'auth.md',
                        'relevance' => 0.85,
                        'snippet' => 'Configure authentication using middleware...',
                    ],
                ],
            ],
        ];

        $result = $this->section->shouldInclude('question', $context, []);
        $this->assertTrue($result);
    }

    /** @test */
    public function it_formats_output_with_citation_instructions(): void
    {
        $context = [
            'file_context' => [
                'relevant_files' => [
                    [
                        'filename' => 'auth.md',
                        'relevance' => 0.85,
                        'snippet' => 'Configure authentication using middleware...',
                    ],
                ],
            ],
        ];

        $output = $this->section->format('How do I configure auth?', $context);

        $this->assertStringContainsString('FILE CONTEXT', $output);
        $this->assertStringContainsString('Citation Instructions', $output);
        $this->assertStringContainsString('cite your sources', $output);
        $this->assertStringContainsString('[1]', $output);
        $this->assertStringContainsString('[2]', $output);
    }

    /** @test */
    public function it_includes_numbered_markers_in_output(): void
    {
        $context = [
            'file_context' => [
                'relevant_files' => [
                    [
                        'filename' => 'auth.md',
                        'relevance' => 0.85,
                        'snippet' => 'Configure authentication using middleware...',
                    ],
                    [
                        'filename' => 'guards.md',
                        'relevance' => 0.78,
                        'snippet' => 'Guards handle session management...',
                    ],
                ],
            ],
        ];

        $output = $this->section->format('How does auth work?', $context);

        // Verify markers are present for each file
        $this->assertStringContainsString('[1] auth.md', $output);
        $this->assertStringContainsString('[2] guards.md', $output);
    }

    /** @test */
    public function it_lists_file_names_and_snippets(): void
    {
        $context = [
            'file_context' => [
                'relevant_files' => [
                    [
                        'filename' => 'auth.md',
                        'relevance' => 0.85,
                        'snippet' => 'Configure authentication using middleware...',
                    ],
                    [
                        'filename' => 'guards.md',
                        'relevance' => 0.78,
                        'snippet' => 'Guards handle session management...',
                    ],
                ],
            ],
        ];

        $output = $this->section->format('How does auth work?', $context);

        // Verify file names are present
        $this->assertStringContainsString('auth.md', $output);
        $this->assertStringContainsString('guards.md', $output);

        // Verify relevance percentages are shown
        $this->assertStringContainsString('85%', $output);
        $this->assertStringContainsString('78%', $output);

        // Verify snippets are included
        $this->assertStringContainsString('Configure authentication using middleware', $output);
        $this->assertStringContainsString('Guards handle session management', $output);
    }

    /** @test */
    public function it_formats_relevance_as_percentage(): void
    {
        $context = [
            'file_context' => [
                'relevant_files' => [
                    [
                        'filename' => 'test.md',
                        'relevance' => 0.923,
                        'snippet' => 'Test content...',
                    ],
                ],
            ],
        ];

        $output = $this->section->format('test question', $context);

        // Should show 92% (rounded down)
        $this->assertStringContainsString('92%', $output);
    }

    /** @test */
    public function it_returns_empty_string_when_no_files(): void
    {
        $context = [
            'file_context' => [
                'relevant_files' => [],
            ],
        ];

        $output = $this->section->format('test question', $context);

        $this->assertEquals('', $output);
    }

    /** @test */
    public function it_handles_missing_file_context_gracefully(): void
    {
        $output = $this->section->format('test question', []);

        $this->assertEquals('', $output);
    }

    /** @test */
    public function it_includes_example_citation_format(): void
    {
        $context = [
            'file_context' => [
                'relevant_files' => [
                    [
                        'filename' => 'auth.md',
                        'relevance' => 0.85,
                        'snippet' => 'Configure authentication...',
                    ],
                ],
            ],
        ];

        $output = $this->section->format('test question', $context);

        // Should include an example showing how to cite
        $this->assertStringContainsString('Example', $output);
        $this->assertStringContainsString('middleware', $output);
    }
}
