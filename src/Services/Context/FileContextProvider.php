<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Services\FileSearchService;

/**
 * File Context Provider
 *
 * Provides unified file search across both physical documentation files
 * and database-backed files. Applies access control filtering and
 * transforms results into a standard format for AI context building.
 *
 * Key features:
 * - Searches both physical (documentation) and database file collections
 * - Applies access filtering (physical files always pass through)
 * - Truncates snippets to configured length
 * - Sorts by relevance score descending
 *
 * @package Condoedge\Ai\Services\Context
 */
class FileContextProvider
{
    /**
     * Create a new FileContextProvider instance
     *
     * @param FileSearchService $searchService Service for searching file content
     * @param FileAccessResolverInterface $accessResolver Service for resolving file access
     */
    public function __construct(
        private readonly FileSearchService $searchService,
        private readonly FileAccessResolverInterface $accessResolver
    ) {}

    /**
     * Search for relevant files based on a question
     *
     * Searches across physical documentation and database file collections,
     * filters by access control, and returns standardized file references.
     *
     * @param string $question The search query/question
     * @param mixed $user The user to check access for (typically Authenticatable)
     * @param array $options Additional search options:
     *   - collections: Array of collection names to search (default: both physical + database)
     *   - limit: Override max references limit
     *   - min_score: Override minimum relevance score
     * @return array Array of file references with standardized format
     * @throws \RuntimeException When security is enabled and user is null
     */
    public function searchRelevantFiles(string $question, mixed $user, array $options = []): array
    {
        // SECURITY: Require user when security is enabled
        $this->validateUserForSecurity($user);

        // Get configuration values
        $minScore = $options['min_score'] ?? config('ai.file_context.min_relevance_score', 0.7);
        $maxReferences = $options['limit'] ?? config('ai.file_context.max_references', 5);
        $snippetLength = config('ai.file_context.snippet_length', 200);

        // Search for relevant content across collections
        // Request more results than needed to allow for filtering
        $searchResults = $this->searchService->searchByContent($question, [
            'limit' => $maxReferences * 3,
            'include_relationships' => false,
        ]);

        if (empty($searchResults)) {
            return [];
        }

        // Extract file IDs from search results
        $fileIds = array_column($searchResults, 'file_id');

        // Apply access control filtering
        $accessibleFileIds = $this->accessResolver->filterAccessibleFileIds($fileIds, $user);

        // Filter results to only accessible files and apply minimum score
        $filteredResults = [];
        foreach ($searchResults as $result) {
            $fileId = $result['file_id'];

            // Check if file is accessible
            if (!in_array($fileId, $accessibleFileIds, false)) {
                continue;
            }

            // Check if score meets minimum threshold
            if ($result['score'] < $minScore) {
                continue;
            }

            $filteredResults[] = $result;
        }

        // Sort by relevance score descending
        usort($filteredResults, fn($a, $b) => $b['score'] <=> $a['score']);

        // Limit results
        $filteredResults = array_slice($filteredResults, 0, $maxReferences);

        // Transform to standard format
        return array_map(function ($result) use ($snippetLength) {
            $chunk = $result['best_chunk'];
            $fileId = $result['file_id'];
            $source = $this->accessResolver->isPhysicalFile($fileId) ? 'physical' : 'database';

            // Get content and truncate if needed
            $content = $chunk->content;
            $snippet = $this->truncateSnippet($content, $snippetLength);

            return [
                'file_id' => $fileId,
                'file_name' => $chunk->fileName,
                'snippet' => $snippet,
                'relevance_score' => $result['score'],
                'chunk_index' => $chunk->chunkIndex,
                'source' => $source,
            ];
        }, $filteredResults);
    }

    /**
     * Build a single file reference for response metadata
     *
     * @param int $refNumber Reference number for citation
     * @param int|string $fileId The file ID
     * @param string $fileName The file name
     * @param string $snippet Content snippet
     * @param float $relevanceScore Relevance score
     * @param int $chunkIndex Chunk index within the file
     * @param string $source Source type ('physical' or 'database')
     * @return array Standardized file reference array
     */
    public function buildFileReference(
        int $refNumber,
        int|string $fileId,
        string $fileName,
        string $snippet,
        float $relevanceScore,
        int $chunkIndex,
        string $source
    ): array {
        return [
            'ref_number' => $refNumber,
            'file_id' => $fileId,
            'file_name' => $fileName,
            'snippet' => $snippet,
            'relevance_score' => $relevanceScore,
            'chunk_index' => $chunkIndex,
            'source' => $source,
        ];
    }

    /**
     * Get file context for prompt building
     *
     * Returns a structured context array suitable for injecting into AI prompts,
     * with metadata about the types of files found.
     *
     * @param string $question The search query/question
     * @param mixed $user The user to check access for
     * @return array Context array with: relevant_files, file_count, has_physical, has_database
     * @throws \RuntimeException When security is enabled and user is null
     */
    public function getFileContext(string $question, mixed $user): array
    {
        // SECURITY: Require user when security is enabled
        $this->validateUserForSecurity($user);

        $relevantFiles = $this->searchRelevantFiles($question, $user);

        // Analyze sources
        $hasPhysical = false;
        $hasDatabase = false;

        foreach ($relevantFiles as $file) {
            if ($file['source'] === 'physical') {
                $hasPhysical = true;
            } else {
                $hasDatabase = true;
            }

            // Early exit if we've found both types
            if ($hasPhysical && $hasDatabase) {
                break;
            }
        }

        return [
            'relevant_files' => $relevantFiles,
            'file_count' => count($relevantFiles),
            'has_physical' => $hasPhysical,
            'has_database' => $hasDatabase,
        ];
    }

    /**
     * Truncate a snippet to the configured maximum length
     *
     * @param string $content The content to truncate
     * @param int $maxLength Maximum length in characters
     * @return string Truncated content with ellipsis if needed
     */
    private function truncateSnippet(string $content, int $maxLength): string
    {
        if (strlen($content) <= $maxLength) {
            return $content;
        }

        return substr($content, 0, $maxLength) . '...';
    }

    /**
     * Validate that user is provided when security is enabled
     *
     * @param mixed $user The user to validate
     * @throws \RuntimeException When security is enabled and user is null
     */
    private function validateUserForSecurity(mixed $user): void
    {
        if ($this->accessResolver->shouldEnforceSecurity() && $user === null) {
            throw new \RuntimeException('User required for file context retrieval');
        }
    }
}
