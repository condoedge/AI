<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Context;

use Condoedge\Ai\Services\Context\FilenameExtractor;
use PHPUnit\Framework\TestCase;

class FilenameExtractorTest extends TestCase
{
    private FilenameExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new FilenameExtractor();
    }

    public function test_extracts_filename_with_extension(): void
    {
        $result = $this->extractor->extract('Check the bariloche.txt file');

        $this->assertEquals(['bariloche.txt'], $result);
    }

    public function test_extracts_multiple_filenames(): void
    {
        $result = $this->extractor->extract('Compare report.pdf and data.xlsx');

        $this->assertEqualsCanonicalizing(['report.pdf', 'data.xlsx'], $result);
    }

    public function test_extracts_filename_with_path(): void
    {
        $result = $this->extractor->extract('Look at docs/readme.md');

        $this->assertEquals(['readme.md'], $result);
    }

    public function test_handles_quoted_filenames(): void
    {
        $result = $this->extractor->extract('Open "my document.docx" please');

        $this->assertEquals(['my document.docx'], $result);
    }

    public function test_returns_empty_for_no_filename(): void
    {
        $result = $this->extractor->extract('What are the sales figures?');

        $this->assertEquals([], $result);
    }

    public function test_extracts_common_extensions(): void
    {
        $extensions = [
            'txt', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv',
            'md', 'json', 'xml', 'html', 'htm', 'rtf', 'odt',
            'ppt', 'pptx', 'png', 'jpg', 'jpeg', 'gif', 'svg',
        ];

        foreach ($extensions as $ext) {
            $result = $this->extractor->extract("Check file.{$ext}");
            $this->assertEquals(["file.{$ext}"], $result, "Failed for extension: {$ext}");
        }
    }

    public function test_case_insensitive_extension_matching(): void
    {
        $result = $this->extractor->extract('Open FILE.PDF');

        $this->assertEquals(['FILE.PDF'], $result);
    }

    public function test_ignores_filenames_within_urls(): void
    {
        $result = $this->extractor->extract('Check https://example.com/file.pdf');

        $this->assertEquals([], $result);
    }

    public function test_extracts_filename_alongside_url(): void
    {
        $result = $this->extractor->extract('Check file.txt at https://example.com/other.pdf');

        $this->assertEquals(['file.txt'], $result);
    }

    public function test_extracts_filename_from_natural_language(): void
    {
        $queries = [
            'What does the budget_2024.xlsx contain?' => ['budget_2024.xlsx'],
            'Can you summarize notes.txt for me?' => ['notes.txt'],
            'I need info from quarterly-report.pdf' => ['quarterly-report.pdf'],
        ];

        foreach ($queries as $query => $expected) {
            $result = $this->extractor->extract($query);
            $this->assertEquals($expected, $result, "Failed for query: {$query}");
        }
    }
}
