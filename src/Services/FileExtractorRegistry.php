<?php

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\FileExtractorInterface;

/**
 * Registry for managing file extractors
 *
 * This service manages multiple file extractors and routes
 * extraction requests to the appropriate extractor based on file type.
 */
class FileExtractorRegistry
{
    /**
     * @var array<string, FileExtractorInterface> Map of extension => extractor
     */
    private array $extractors = [];

    /**
     * Register a file extractor
     *
     * @param FileExtractorInterface $extractor
     * @return void
     */
    public function register(FileExtractorInterface $extractor): void
    {
        foreach ($extractor->getSupportedExtensions() as $extension) {
            $this->extractors[strtolower($extension)] = $extractor;
        }
    }

    /**
     * Register multiple extractors at once
     *
     * @param array $extractors
     * @return void
     */
    public function registerMany(array $extractors): void
    {
        foreach ($extractors as $extractor) {
            if ($extractor instanceof FileExtractorInterface) {
                $this->register($extractor);
            }
        }
    }

    /**
     * Get an extractor for a specific file extension
     *
     * @param string $extension
     * @return FileExtractorInterface|null
     */
    public function getExtractor(string $extension): ?FileExtractorInterface
    {
        return $this->extractors[strtolower($extension)] ?? null;
    }

    /**
     * Check if a file type is supported
     *
     * @param string $extension
     * @return bool
     */
    public function supports(string $extension): bool
    {
        return isset($this->extractors[strtolower($extension)]);
    }

    /**
     * Get all supported file extensions
     *
     * @return array
     */
    public function getSupportedExtensions(): array
    {
        return array_keys($this->extractors);
    }

    /**
     * Extract text from a file
     *
     * This method automatically selects the appropriate extractor
     * based on the file extension.
     *
     * @param string $filePath
     * @return string
     * @throws \InvalidArgumentException If file type is not supported
     * @throws \RuntimeException If extraction fails
     */
    public function extract(string $filePath): string
    {
        $extension = $this->getFileExtension($filePath);

        if (!$this->supports($extension)) {
            throw new \InvalidArgumentException(
                "Unsupported file type: {$extension}. " .
                "Supported types: " . implode(', ', $this->getSupportedExtensions())
            );
        }

        $extractor = $this->getExtractor($extension);

        return $extractor->extract($filePath);
    }

    /**
     * Extract metadata from a file
     *
     * @param string $filePath
     * @return array
     * @throws \InvalidArgumentException If file type is not supported
     */
    public function extractMetadata(string $filePath): array
    {
        $extension = $this->getFileExtension($filePath);

        if (!$this->supports($extension)) {
            throw new \InvalidArgumentException(
                "Unsupported file type: {$extension}"
            );
        }

        $extractor = $this->getExtractor($extension);

        return $extractor->extractMetadata($filePath);
    }

    /**
     * Get the file extension from a file path
     *
     * @param string $filePath
     * @return string
     */
    private function getFileExtension(string $filePath): string
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        return strtolower($extension);
    }

    /**
     * Get statistics about registered extractors
     *
     * @return array
     */
    public function getStats(): array
    {
        $extractorClasses = array_unique(
            array_map(fn($e) => get_class($e), $this->extractors)
        );

        return [
            'total_extractors' => count($extractorClasses),
            'supported_extensions' => count($this->extractors),
            'extensions' => $this->getSupportedExtensions(),
            'extractors' => $extractorClasses,
        ];
    }
}
