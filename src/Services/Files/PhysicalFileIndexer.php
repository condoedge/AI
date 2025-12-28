<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Files;

use Condoedge\Ai\Services\Context\FileAccessResolver;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * Physical File Indexer
 *
 * Discovers and indexes physical documentation files from the filesystem.
 * Supports glob patterns with recursive (**) matching using Symfony Finder.
 *
 * Physical files are identified by the 'physical:' prefix and always bypass
 * security checks, making them ideal for documentation that should be
 * accessible to all users.
 *
 * @package Condoedge\Ai\Services\Files
 */
class PhysicalFileIndexer
{
    /**
     * Discover files matching glob patterns
     *
     * Uses config('ai.file_context.physical_paths') if patterns not provided.
     * Uses config('ai.file_context.base_path') as root directory.
     * Filters results by config('ai.file_context.supported_extensions').
     *
     * @param array|null $patterns Glob patterns to match (e.g., ['docs/**\/*.md'])
     * @return array<string> Array of absolute file paths
     */
    public function discoverFiles(?array $patterns = null): array
    {
        $patterns = $patterns ?? config('ai.file_context.physical_paths', []);

        if (empty($patterns)) {
            return [];
        }

        $basePath = $this->getBasePath();
        $supportedExtensions = config('ai.file_context.supported_extensions', ['md', 'mdx', 'txt', 'rst']);

        $discoveredFiles = [];

        foreach ($patterns as $pattern) {
            $files = $this->findFilesForPattern($basePath, $pattern, $supportedExtensions);
            $discoveredFiles = array_merge($discoveredFiles, $files);
        }

        // Remove duplicates while preserving keys, then re-index
        return array_values(array_unique($discoveredFiles));
    }

    /**
     * Generate a physical file ID from an absolute path
     *
     * Creates an ID with the physical: prefix and relative path.
     * Normalizes path separators to forward slashes.
     *
     * @param string $path Absolute file path
     * @return string Physical file ID (e.g., 'physical:docs/readme.md')
     */
    public function generateFileId(string $path): string
    {
        $basePath = $this->getBasePath();

        // Normalize path separators
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedBasePath = str_replace('\\', '/', $basePath);

        // Remove base path to get relative path
        $relativePath = $normalizedPath;
        if (str_starts_with($normalizedPath, $normalizedBasePath)) {
            $relativePath = substr($normalizedPath, strlen($normalizedBasePath));
            $relativePath = ltrim($relativePath, '/');
        }

        return FileAccessResolver::PHYSICAL_PREFIX . $relativePath;
    }

    /**
     * Create a file object compatible with FileProcessor
     *
     * Returns a stdClass object with all required properties for file processing.
     *
     * @param string $path Absolute file path
     * @return object File object with id, name, path, extension, size, is_physical
     */
    public function createFileObject(string $path): object
    {
        $file = new \stdClass();
        $file->id = $this->generateFileId($path);
        $file->name = basename($path);
        $file->path = $path;
        $file->extension = pathinfo($path, PATHINFO_EXTENSION);
        $file->size = file_exists($path) ? filesize($path) : 0;
        $file->is_physical = true;

        return $file;
    }

    /**
     * Discover and create file objects for all matching files
     *
     * Combines discoverFiles and createFileObject for convenience.
     *
     * @param array|null $patterns Glob patterns to match
     * @return array<object> Array of file objects
     */
    public function createFileObjects(?array $patterns = null): array
    {
        $paths = $this->discoverFiles($patterns);

        return array_map(
            fn(string $path) => $this->createFileObject($path),
            $paths
        );
    }

    /**
     * Get the base path for file discovery
     *
     * @return string
     */
    private function getBasePath(): string
    {
        return config('ai.file_context.base_path', base_path());
    }

    /**
     * Find files matching a single pattern
     *
     * Handles both simple patterns and recursive (**) patterns.
     *
     * @param string $basePath Base directory path
     * @param string $pattern Glob pattern
     * @param array $supportedExtensions Allowed file extensions
     * @return array<string> Array of absolute file paths
     */
    private function findFilesForPattern(string $basePath, string $pattern, array $supportedExtensions): array
    {
        // Normalize pattern separators
        $pattern = str_replace('\\', '/', $pattern);

        // Handle recursive patterns using Symfony Finder
        if (str_contains($pattern, '**')) {
            return $this->findFilesRecursive($basePath, $pattern, $supportedExtensions);
        }

        // Handle simple patterns
        return $this->findFilesSimple($basePath, $pattern, $supportedExtensions);
    }

    /**
     * Find files using recursive pattern with Symfony Finder
     *
     * @param string $basePath Base directory path
     * @param string $pattern Pattern containing **
     * @param array $supportedExtensions Allowed file extensions
     * @return array<string>
     */
    private function findFilesRecursive(string $basePath, string $pattern, array $supportedExtensions): array
    {
        // Parse pattern: split into directory prefix and file pattern
        // e.g., "docs/**/*.md" -> directory: "docs", file pattern: "*.md"
        $parts = explode('**/', $pattern, 2);
        $directoryPrefix = rtrim($parts[0], '/');
        $filePattern = $parts[1] ?? '*';

        $searchPath = $basePath;
        if (!empty($directoryPrefix)) {
            $searchPath = $basePath . '/' . $directoryPrefix;
        }

        // Normalize the search path
        $searchPath = str_replace('\\', '/', $searchPath);

        if (!is_dir($searchPath)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()
            ->in($searchPath)
            ->name($filePattern);

        $files = [];
        foreach ($finder as $file) {
            $extension = $file->getExtension();

            // Filter by supported extensions
            if (in_array($extension, $supportedExtensions, true)) {
                // Normalize path separators
                $files[] = str_replace('\\', '/', $file->getRealPath());
            }
        }

        return $files;
    }

    /**
     * Find files using simple (non-recursive) pattern
     *
     * @param string $basePath Base directory path
     * @param string $pattern Simple glob pattern
     * @param array $supportedExtensions Allowed file extensions
     * @return array<string>
     */
    private function findFilesSimple(string $basePath, string $pattern, array $supportedExtensions): array
    {
        $fullPattern = $basePath . '/' . $pattern;

        // Normalize path
        $fullPattern = str_replace('\\', '/', $fullPattern);

        $matchedFiles = glob($fullPattern, GLOB_BRACE);

        if ($matchedFiles === false) {
            return [];
        }

        $files = [];
        foreach ($matchedFiles as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }

            $extension = pathinfo($filePath, PATHINFO_EXTENSION);

            // Filter by supported extensions
            if (in_array($extension, $supportedExtensions, true)) {
                // Normalize path separators
                $files[] = str_replace('\\', '/', $filePath);
            }
        }

        return $files;
    }
}
