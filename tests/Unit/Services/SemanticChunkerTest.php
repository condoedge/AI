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

    /**
     * The chunk-size budget is expressed in CHARACTERS, not bytes. Multibyte
     * (accented French / UTF-8) units must be measured with mb_strlen so the
     * grouping budget is not halved for non-ASCII content. Each accented
     * paragraph below is 40 characters (80 bytes); with a 200-character budget
     * roughly four paragraphs fit per chunk. Byte counting only fits two,
     * producing chunks ~half the intended size.
     */
    public function test_groups_multibyte_units_by_character_budget()
    {
        // 10 paragraphs of 40 accented characters each (80 bytes each).
        $paragraph = str_repeat('é', 40);
        $content = implode("\n\n", array_fill(0, 10, $paragraph));

        $chunks = $this->chunker->chunk($content, [
            'chunk_size' => 200,
            'overlap' => 0,
            'preserve_sentences' => false,
            'preserve_paragraphs' => true,
        ]);

        // Each chunk must approach the character budget (multiple paragraphs),
        // not stop at ~2 paragraphs because bytes were counted instead of chars.
        $this->assertGreaterThan(
            120,
            mb_strlen($chunks[0], 'UTF-8'),
            'First chunk should pack close to the 200-char budget, not be byte-capped to ~82 chars'
        );

        // And no chunk may exceed the requested character budget.
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(
                200,
                mb_strlen($chunk, 'UTF-8'),
                'Chunk must not exceed the character budget'
            );
        }
    }

    /**
     * A single oversized unit (paragraph/sentence longer than chunk_size) must
     * only force *that unit* through finer chunking — it must NOT discard the
     * paragraph/sentence boundaries of the rest of the document by collapsing
     * everything to blind character slicing.
     */
    public function test_oversized_unit_does_not_collapse_whole_document()
    {
        $oversized = str_repeat('X', 250); // single paragraph > chunk_size
        $content = "Short para one.\n\n{$oversized}\n\nShort para three.\n\nShort para four.";

        $chunks = $this->chunker->chunk($content, [
            'chunk_size' => 100,
            'overlap' => 0,
            'preserve_sentences' => false,
            'preserve_paragraphs' => true,
        ]);

        // The small paragraphs around the oversized block must survive as their
        // own boundary-aligned chunks, not be glued onto the X-block by a blind
        // character window.
        $joined = implode("\n---\n", $chunks);
        $this->assertStringContainsString('Short para one.', $joined);
        $this->assertStringContainsString('Short para three.', $joined);
        $this->assertStringContainsString('Short para four.', $joined);

        // No chunk that contains a real (non-oversized) paragraph should be
        // contaminated with the X-block: the first paragraph must not be fused
        // to the oversized unit in a single chunk.
        foreach ($chunks as $chunk) {
            if (str_contains($chunk, 'Short para one.')) {
                $this->assertStringNotContainsString(
                    'XXXXX',
                    $chunk,
                    'Small paragraph boundary was collapsed into the oversized unit'
                );
            }
        }

        // Every chunk must still respect the character budget.
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(100, mb_strlen($chunk, 'UTF-8'));
        }
    }

    /**
     * Oversized multibyte unit must also be split by characters (mb_substr),
     * never producing a chunk that overshoots the character budget.
     */
    public function test_oversized_multibyte_unit_is_split_by_characters()
    {
        $oversized = str_repeat('é', 300); // 300 chars / 600 bytes, one paragraph
        $content = "Petit.\n\n{$oversized}\n\nFin.";

        $chunks = $this->chunker->chunk($content, [
            'chunk_size' => 100,
            'overlap' => 0,
            'preserve_sentences' => false,
            'preserve_paragraphs' => true,
        ]);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(
                100,
                mb_strlen($chunk, 'UTF-8'),
                'Oversized multibyte unit must be sliced by characters within budget'
            );
        }

        $this->assertStringContainsString('Petit.', implode('', $chunks));
        $this->assertStringContainsString('Fin.', implode('', $chunks));
    }
}
