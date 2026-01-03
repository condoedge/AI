<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

/**
 * Response File Enricher
 *
 * Enriches AI responses with file reference metadata by extracting citation
 * markers from the response text and building actionable file references.
 *
 * Citation format: [N] where N is a 1-indexed reference to the relevant_files array.
 *
 * Key features:
 * - Extracts citation markers [1], [2], etc. from response text
 * - Maps citations to files in the provided file context
 * - Builds actionable metadata for database files (download/preview URLs)
 * - Physical files have null URLs and cannot be downloaded directly
 *
 * @package Condoedge\Ai\Services\Response
 */
class ResponseFileEnricher
{
    /**
     * Prefix used to identify physical file IDs
     */
    private const PHYSICAL_PREFIX = 'physical:';

    /**
     * Extract citation markers from response text
     *
     * Finds all [N] patterns in the response and returns unique integers.
     *
     * @param string $response The response text to search
     * @return array<int> Array of unique citation marker integers
     */
    public function extractCitationMarkers(string $response): array
    {
        $pattern = '/\[(\d+)\]/';
        preg_match_all($pattern, $response, $matches);

        if (empty($matches[1])) {
            return [];
        }

        // Convert to integers and return unique values
        $markers = array_map('intval', $matches[1]);

        return array_values(array_unique($markers));
    }

    /**
     * Build referenced files array for cited files only
     *
     * Creates an array of file references with actionable metadata for each
     * file that was actually cited in the response text.
     *
     * @param string $response The response text containing citations
     * @param array $fileContext The file context from FileContextProvider containing:
     *   - relevant_files: Array of file references (1-indexed for citations)
     *   - file_count: Number of files
     *   - has_physical: Whether physical files are present
     *   - has_database: Whether database files are present
     * @param array $options Additional options:
     *   - user: The user object for permission checks
     *   - download_url_resolver: Closure(int $id): string for download URLs
     *   - preview_url_resolver: Closure(int $id): string for preview URLs
     *   - can_download_resolver: Closure(int $id, mixed $user): bool for download permission
     * @return array Array of referenced file metadata
     */
    public function buildReferencedFiles(string $response, array $fileContext, array $options = []): array
    {
        $relevantFiles = $fileContext['relevant_files'] ?? [];

        if (empty($relevantFiles)) {
            return [];
        }

        $citationMarkers = $this->extractCitationMarkers($response);

        if (empty($citationMarkers)) {
            return [];
        }

        $referencedFiles = [];

        foreach ($citationMarkers as $marker) {
            // Citations are 1-indexed, array is 0-indexed
            $index = $marker - 1;

            if ($index < 0 || $index >= count($relevantFiles)) {
                continue;
            }

            $file = $relevantFiles[$index];
            $referencedFiles[] = $this->buildFileReference($file, $marker, $options);
        }

        return $referencedFiles;
    }

    /**
     * Enrich a response array with file reference metadata
     *
     * Adds 'referenced_files' and 'has_file_references' keys to the response array.
     *
     * @param array $response The original response array (must contain 'answer' or 'content' key)
     * @param array $fileContext The file context from FileContextProvider
     * @param array $options Options for URL resolution (see buildReferencedFiles)
     * @return array The enriched response array
     */
    public function enrichResponse(array $response, array $fileContext, array $options = []): array
    {
        $content = $response['answer'] ?? $response['content'] ?? '';
        $referencedFiles = $this->buildReferencedFiles($content, $fileContext, $options);

        return array_merge($response, [
            'referenced_files' => $referencedFiles,
            'has_file_references' => !empty($referencedFiles),
        ]);
    }

    /**
     * Build a single file reference with full metadata
     *
     * @param array $file The file data from relevant_files
     * @param int $refNumber The citation reference number (1-indexed)
     * @param array $options URL resolver options
     * @return array Complete file reference with actionable metadata
     */
    private function buildFileReference(array $file, int $refNumber, array $options): array
    {
        $fileId = $file['file_id'];
        $source = $file['source'] ?? 'database';
        $isPhysical = $this->isPhysicalFile($fileId);

        // Physical files cannot be downloaded/previewed directly
        if ($isPhysical) {
            return [
                'ref' => $refNumber,
                'id' => $fileId,
                'name' => $file['filename'],
                'snippet' => $file['snippet'],
                'relevance_score' => $file['relevance'],
                'source' => $source,
                'chunk_index' => $file['chunk_index'],
                'download_url' => null,
                'preview_url' => null,
                'can_download' => false,
            ];
        }

        // Database files - resolve URLs
        $downloadUrl = $this->resolveUrl($fileId, $options['download_url_resolver'] ?? null);
        $previewUrl = $this->resolveUrl($fileId, $options['preview_url_resolver'] ?? null);
        $canDownload = $this->resolveCanDownload(
            $fileId,
            $options['user'] ?? null,
            $options['can_download_resolver'] ?? null
        );

        return [
            'ref' => $refNumber,
            'id' => $fileId,
            'name' => $file['filename'],
            'snippet' => $file['snippet'],
            'relevance_score' => $file['relevance'],
            'source' => $source,
            'chunk_index' => $file['chunk_index'],
            'download_url' => $downloadUrl,
            'preview_url' => $previewUrl,
            'can_download' => $canDownload,
        ];
    }

    /**
     * Resolve a URL using the provided resolver closure
     *
     * @param int|string $fileId The file ID
     * @param \Closure|null $resolver The URL resolver closure
     * @return string|null The resolved URL or null
     */
    private function resolveUrl(int|string $fileId, ?\Closure $resolver): ?string
    {
        if ($resolver === null) {
            return null;
        }

        return $resolver($fileId);
    }

    /**
     * Resolve whether the file can be downloaded
     *
     * @param int|string $fileId The file ID
     * @param mixed $user The user object
     * @param \Closure|null $resolver The permission resolver closure
     * @return bool Whether the file can be downloaded
     */
    private function resolveCanDownload(int|string $fileId, mixed $user, ?\Closure $resolver): bool
    {
        if ($resolver === null) {
            // Default to true for database files when no resolver is provided
            return true;
        }

        return $resolver($fileId, $user);
    }

    /**
     * Check if a file ID represents a physical file
     *
     * @param int|string $fileId The file ID to check
     * @return bool True if this is a physical file ID
     */
    private function isPhysicalFile(int|string $fileId): bool
    {
        if (!is_string($fileId)) {
            return false;
        }

        return str_starts_with($fileId, self::PHYSICAL_PREFIX);
    }
}
