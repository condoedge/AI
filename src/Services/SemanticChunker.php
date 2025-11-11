<?php

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\FileChunkerInterface;

/**
 * Semantic text chunking service
 *
 * This service intelligently splits text into chunks while preserving
 * sentence and paragraph boundaries for better semantic coherence.
 */
class SemanticChunker implements FileChunkerInterface
{
    /**
     * Default chunk size in characters
     */
    private const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * Default overlap between chunks in characters
     */
    private const DEFAULT_OVERLAP = 200;

    /**
     * File type specific chunk sizes
     */
    private const CHUNK_SIZES = [
        'pdf' => 1200,
        'txt' => 1000,
        'md' => 1500,
        'docx' => 1200,
        'html' => 1500,
        'default' => 1000,
    ];

    /**
     * File type specific overlap sizes
     */
    private const OVERLAP_SIZES = [
        'pdf' => 200,
        'txt' => 150,
        'md' => 300,
        'docx' => 200,
        'html' => 300,
        'default' => 200,
    ];

    /**
     * {@inheritdoc}
     */
    public function chunk(string $content, array $options = []): array
    {
        $chunkSize = $options['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE;
        $overlap = $options['overlap'] ?? self::DEFAULT_OVERLAP;
        $preserveSentences = $options['preserve_sentences'] ?? true;
        $preserveParagraphs = $options['preserve_paragraphs'] ?? true;

        // Normalize line endings
        $content = $this->normalizeLineEndings($content);

        // Handle empty or whitespace-only content
        if (trim($content) === '') {
            return [];
        }

        // If content is smaller than chunk size, return as single chunk
        if (strlen($content) <= $chunkSize) {
            return [$content];
        }

        // Try paragraph-based chunking first if enabled
        if ($preserveParagraphs) {
            $chunks = $this->chunkByParagraphs($content, $chunkSize, $overlap);
            if (count($chunks) > 0) {
                return $chunks;
            }
        }

        // Fall back to sentence-based chunking if enabled
        if ($preserveSentences) {
            $chunks = $this->chunkBySentences($content, $chunkSize, $overlap);
            if (count($chunks) > 0) {
                return $chunks;
            }
        }

        // Fall back to character-based chunking
        return $this->chunkByCharacters($content, $chunkSize, $overlap);
    }

    /**
     * {@inheritdoc}
     */
    public function getRecommendedChunkSize(string $fileType): int
    {
        return self::CHUNK_SIZES[$fileType] ?? self::CHUNK_SIZES['default'];
    }

    /**
     * {@inheritdoc}
     */
    public function getRecommendedOverlap(string $fileType): int
    {
        return self::OVERLAP_SIZES[$fileType] ?? self::OVERLAP_SIZES['default'];
    }

    /**
     * Normalize line endings to \n
     *
     * @param string $content
     * @return string
     */
    private function normalizeLineEndings(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    /**
     * Chunk text by paragraphs
     *
     * @param string $content
     * @param int $chunkSize
     * @param int $overlap
     * @return array
     */
    private function chunkByParagraphs(string $content, int $chunkSize, int $overlap): array
    {
        // Split by double newlines (paragraphs)
        $paragraphs = preg_split('/\n\s*\n/', $content, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($paragraphs)) {
            return [];
        }

        return $this->groupIntoChunks($paragraphs, $chunkSize, $overlap, "\n\n");
    }

    /**
     * Chunk text by sentences
     *
     * @param string $content
     * @param int $chunkSize
     * @param int $overlap
     * @return array
     */
    private function chunkBySentences(string $content, int $chunkSize, int $overlap): array
    {
        // Split by sentence boundaries (. ! ?) followed by whitespace or end of string
        $sentences = preg_split('/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($sentences)) {
            return [];
        }

        return $this->groupIntoChunks($sentences, $chunkSize, $overlap, ' ');
    }

    /**
     * Chunk text by fixed character positions
     *
     * @param string $content
     * @param int $chunkSize
     * @param int $overlap
     * @return array
     */
    private function chunkByCharacters(string $content, int $chunkSize, int $overlap): array
    {
        $chunks = [];
        $length = strlen($content);
        $position = 0;

        // Ensure overlap doesn't exceed chunk size minus 1 (need to move forward)
        $overlap = min($overlap, $chunkSize - 1);

        while ($position < $length) {
            $chunk = substr($content, $position, $chunkSize);
            $chunks[] = $chunk;
            $position += $chunkSize - $overlap;
        }

        return array_filter($chunks, fn($chunk) => trim($chunk) !== '');
    }

    /**
     * Group text units (paragraphs or sentences) into chunks
     *
     * @param array $units Array of text units
     * @param int $chunkSize Target chunk size
     * @param int $overlap Overlap size
     * @param string $separator Separator between units
     * @return array
     */
    private function groupIntoChunks(array $units, int $chunkSize, int $overlap, string $separator): array
    {
        $chunks = [];
        $currentChunk = [];
        $currentLength = 0;

        foreach ($units as $unit) {
            $unitLength = strlen($unit);

            // If a single unit exceeds chunk size, we can't use this chunking method
            if ($unitLength > $chunkSize) {
                return [];
            }

            // If adding this unit would exceed chunk size and we already have content
            if ($currentLength > 0 && ($currentLength + $unitLength + strlen($separator)) > $chunkSize) {
                // Save current chunk
                $chunks[] = implode($separator, $currentChunk);

                // Start new chunk with overlap
                $currentChunk = $this->getOverlapUnits($currentChunk, $overlap, $separator);
                $currentLength = strlen(implode($separator, $currentChunk));
            }

            $currentChunk[] = $unit;
            $currentLength += $unitLength + strlen($separator);
        }

        // Add final chunk if not empty
        if (!empty($currentChunk)) {
            $chunks[] = implode($separator, $currentChunk);
        }

        return array_filter($chunks, fn($chunk) => trim($chunk) !== '');
    }

    /**
     * Get units to include in overlap
     *
     * @param array $units Previous units
     * @param int $overlapSize Target overlap size
     * @param string $separator Separator between units
     * @return array
     */
    private function getOverlapUnits(array $units, int $overlapSize, string $separator): array
    {
        $overlapUnits = [];
        $overlapLength = 0;

        // Take units from the end until we reach overlap size
        for ($i = count($units) - 1; $i >= 0; $i--) {
            $unit = $units[$i];
            $unitLength = strlen($unit);

            if ($overlapLength + $unitLength > $overlapSize) {
                break;
            }

            array_unshift($overlapUnits, $unit);
            $overlapLength += $unitLength + strlen($separator);
        }

        return $overlapUnits;
    }
}
