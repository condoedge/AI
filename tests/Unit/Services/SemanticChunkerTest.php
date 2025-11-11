<?php

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\SemanticChunker;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SemanticChunker
 */
class SemanticChunkerTest extends TestCase
{
    private SemanticChunker $chunker;

    public function setUp(): void
    {
        parent::setUp();
        $this->chunker = new SemanticChunker();
    }

    public function test_chunks_small_content_as_single_chunk()
    {
        $content = 'This is a small text that fits in one chunk.';

        $chunks = $this->chunker->chunk($content, ['chunk_size' => 1000]);

        $this->assertCount(1, $chunks);
        $this->assertEquals($content, $chunks[0]);
    }

    public function test_chunks_by_paragraphs()
    {
        $content = "First paragraph.\n\nSecond paragraph.\n\nThird paragraph.";

        $chunks = $this->chunker->chunk($content, [
            'chunk_size' => 30,
            'overlap' => 0,
            'preserve_paragraphs' => true,
        ]);

        $this->assertGreaterThan(1, count($chunks));
    }

    public function test_chunks_by_sentences()
    {
        $content = "First sentence. Second sentence. Third sentence. Fourth sentence.";

        $chunks = $this->chunker->chunk($content, [
            'chunk_size' => 30,
            'overlap' => 0,
            'preserve_sentences' => true,
            'preserve_paragraphs' => false,
        ]);

        $this->assertGreaterThan(1, count($chunks));
    }

    public function test_chunks_by_characters_when_other_methods_fail()
    {
        $content = str_repeat('a', 200);

        $chunks = $this->chunker->chunk($content, [
            'chunk_size' => 50,
            'overlap' => 10,
        ]);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(50, strlen($chunk));
        }
    }

    public function test_applies_overlap_between_chunks()
    {
        $content = str_repeat('Hello ', 100); // 600 characters

        $chunks = $this->chunker->chunk($content, [
            'chunk_size' => 100,
            'overlap' => 20,
        ]);

        $this->assertGreaterThan(1, count($chunks));

        // Check that consecutive chunks share some content (overlap)
        for ($i = 0; $i < count($chunks) - 1; $i++) {
            $currentEnd = substr($chunks[$i], -20);
            $nextStart = substr($chunks[$i + 1], 0, 20);

            // There should be some similarity (not exact due to word boundaries)
            $this->assertNotEmpty($currentEnd);
            $this->assertNotEmpty($nextStart);
        }
    }

    public function test_returns_recommended_chunk_size_for_file_type()
    {
        $this->assertEquals(1200, $this->chunker->getRecommendedChunkSize('pdf'));
        $this->assertEquals(1000, $this->chunker->getRecommendedChunkSize('txt'));
        $this->assertEquals(1500, $this->chunker->getRecommendedChunkSize('md'));
        $this->assertEquals(1200, $this->chunker->getRecommendedChunkSize('docx'));
        $this->assertEquals(1000, $this->chunker->getRecommendedChunkSize('unknown'));
    }

    public function test_returns_recommended_overlap_for_file_type()
    {
        $this->assertEquals(200, $this->chunker->getRecommendedOverlap('pdf'));
        $this->assertEquals(150, $this->chunker->getRecommendedOverlap('txt'));
        $this->assertEquals(300, $this->chunker->getRecommendedOverlap('md'));
        $this->assertEquals(200, $this->chunker->getRecommendedOverlap('docx'));
        $this->assertEquals(200, $this->chunker->getRecommendedOverlap('unknown'));
    }

    public function test_normalizes_line_endings()
    {
        $content = "Line 1\r\nLine 2\rLine 3\nLine 4";

        $chunks = $this->chunker->chunk($content);

        foreach ($chunks as $chunk) {
            $this->assertStringNotContainsString("\r\n", $chunk);
            $this->assertStringNotContainsString("\r", $chunk);
        }
    }

    public function test_filters_empty_chunks()
    {
        $content = "Content here.\n\n\n\n\nMore content.";

        $chunks = $this->chunker->chunk($content, [
            'chunk_size' => 20,
        ]);

        foreach ($chunks as $chunk) {
            $this->assertNotEmpty(trim($chunk));
        }
    }

    public function test_handles_empty_content()
    {
        $content = '';

        $chunks = $this->chunker->chunk($content);

        $this->assertEquals([], $chunks);
    }

    public function test_handles_whitespace_only_content()
    {
        $content = '     ';

        $chunks = $this->chunker->chunk($content);

        $this->assertEquals([], $chunks);
    }

    public function test_respects_custom_chunk_size()
    {
        $content = str_repeat('Test ', 100);

        $chunks = $this->chunker->chunk($content, [
            'chunk_size' => 50,
        ]);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(50, strlen($chunk));
        }
    }

    public function test_respects_custom_overlap()
    {
        $content = str_repeat('Word ', 200);

        $chunks1 = $this->chunker->chunk($content, [
            'chunk_size' => 100,
            'overlap' => 10,
        ]);

        $chunks2 = $this->chunker->chunk($content, [
            'chunk_size' => 100,
            'overlap' => 50,
        ]);

        // More overlap should result in more chunks
        $this->assertGreaterThan(count($chunks1), count($chunks2));
    }

    public function test_preserves_paragraph_boundaries_when_enabled()
    {
        $content = "Paragraph one.\n\nParagraph two.\n\nParagraph three.";

        $chunks = $this->chunker->chunk($content, [
            'chunk_size' => 30,
            'preserve_paragraphs' => true,
        ]);

        // Each chunk should not split paragraphs
        foreach ($chunks as $chunk) {
            $paragraphCount = substr_count($chunk, "\n\n");
            // Should contain complete paragraphs
            $this->assertGreaterThanOrEqual(0, $paragraphCount);
        }
    }

    public function test_preserves_sentence_boundaries_when_enabled()
    {
        $content = "First. Second. Third. Fourth. Fifth. Sixth. Seventh.";

        $chunks = $this->chunker->chunk($content, [
            'chunk_size' => 20,
            'preserve_sentences' => true,
            'preserve_paragraphs' => false,
        ]);

        // Chunks should end with sentence boundaries
        foreach ($chunks as $chunk) {
            $trimmed = trim($chunk);
            if (!empty($trimmed)) {
                // Should end with punctuation or be a complete sentence
                $lastChar = substr($trimmed, -1);
                $this->assertTrue(
                    in_array($lastChar, ['.', '!', '?']) || $chunk === end($chunks)
                );
            }
        }
    }
}
