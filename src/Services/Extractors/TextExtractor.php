<?php

namespace Condoedge\Ai\Services\Extractors;

use Condoedge\Ai\Contracts\FileExtractorInterface;

/**
 * Plain text file extractor
 *
 * Extracts content from plain text files (.txt)
 */
class TextExtractor implements FileExtractorInterface
{
    /**
     * {@inheritdoc}
     */
    public function extract(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new \RuntimeException("File not readable: {$filePath}");
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$filePath}");
        }

        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        // Remove null bytes and other control characters except newlines and tabs
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);

        return trim($content);
    }

    /**
     * {@inheritdoc}
     */
    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), $this->getSupportedExtensions());
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedExtensions(): array
    {
        return ['txt', 'text', 'log'];
    }

    /**
     * {@inheritdoc}
     */
    public function extractMetadata(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        return [
            'file_size' => filesize($filePath),
            'line_count' => count($lines),
            'character_count' => strlen($content),
            'word_count' => str_word_count($content),
            'encoding' => mb_detect_encoding($content, ['UTF-8', 'ASCII', 'ISO-8859-1'], true) ?: 'unknown',
        ];
    }
}
