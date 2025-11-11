<?php

namespace Condoedge\Ai\Contracts;

/**
 * Interface for extracting text content from files
 *
 * Implementations of this interface extract readable text from
 * various file formats for processing and indexing.
 */
interface FileExtractorInterface
{
    /**
     * Extract text content from a file
     *
     * @param string $filePath Absolute path to the file
     * @return string Extracted text content
     * @throws \RuntimeException If extraction fails
     */
    public function extract(string $filePath): string;

    /**
     * Check if this extractor supports a given file type
     *
     * @param string $extension File extension (e.g., 'pdf', 'txt')
     * @return bool
     */
    public function supports(string $extension): bool;

    /**
     * Get the file extensions supported by this extractor
     *
     * @return array
     */
    public function getSupportedExtensions(): array;

    /**
     * Extract metadata from the file (optional)
     *
     * @param string $filePath Absolute path to the file
     * @return array Metadata (e.g., page count, author, title)
     */
    public function extractMetadata(string $filePath): array;
}
