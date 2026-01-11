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
 * - Detects explicit filename references in queries for targeted search
 * - Combines filename and semantic content search results
 * - Applies access filtering (physical files always pass through)
 * - Truncates snippets to configured length
 * - Sorts by relevance score descending
 *
 * @package Condoedge\Ai\Services\Context
 */
class FileContextProvider
{
    /**
     * Threshold for partial filename matches (0.3 is low to allow more results)
     */
    private const FILENAME_PARTIAL_THRESHOLD = 0.3;

    /**
     * The filename extractor instance
     */
    private readonly FilenameExtractor $filenameExtractor;

    /**
     * Create a new FileContextProvider instance
     *
     * @param FileSearchService $searchService Service for searching file content
     * @param FileAccessResolverInterface $accessResolver Service for resolving file access
     * @param FilenameExtractor|null $filenameExtractor Service for extracting filenames from queries
     */
    public function __construct(
        private readonly FileSearchService $searchService,
        private readonly FileAccessResolverInterface $accessResolver,
        ?FilenameExtractor $filenameExtractor = null
    ) {
        $this->filenameExtractor = $filenameExtractor ?? new FilenameExtractor();
    }

    /**
     * Search for relevant files based on a question
     *
     * Searches across physical documentation and database file collections using
     * both filename-based and semantic content-based search. Combines results with
     * filename matches prioritized. Filters by access control and returns
     * standardized file references.
     *
     * Search strategy:
     * 1. Extract explicit filename references from query (e.g., "bariloche.txt")
     * 2. If filenames found, search by filename (high priority)
     * 3. Also search by content (semantic search)
     * 4. Combine results with filename matches first
     * 5. Apply different thresholds based on match type
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
        $minScore = $options['min_score'] ?? config('ai.file_context.min_relevance_score', 0.4);
        $maxReferences = $options['limit'] ?? config('ai.file_context.max_references', 5);
        $snippetLength = config('ai.file_context.snippet_length', 200);

        // Step 1: Extract explicit filename references
        $extractedFilenames = $this->filenameExtractor->extract($question);

        // Step 2: Search by filename (if references found)
        // Note: Sequential searches are acceptable for typical use (1-2 filenames per query).
        // For queries with many filenames, consider adding batch search to FileSearchService.
        $filenameResults = [];
        foreach ($extractedFilenames as $filename) {
            $results = $this->searchService->searchByFilename($filename, [
                'limit' => $maxReferences,
                'include_relationships' => false,
            ]);
            $filenameResults = array_merge($filenameResults, $results);
        }

        // Step 3: Search by content (semantic search)
        $contentResults = $this->searchService->searchByContent($question, [
            'limit' => $maxReferences * 3,
            'include_relationships' => false,
        ]);

        // Step 4: Combine results, filename matches first
        $combinedResults = $this->combineSearchResults($filenameResults, $contentResults);

        if (empty($combinedResults)) {
            return [];
        }

        // Step 5: Apply access control
        $fileIds = array_column($combinedResults, 'file_id');
        $accessibleFileIds = $this->accessResolver->filterAccessibleFileIds($fileIds, $user);

        // Step 6: Filter by access and apply score thresholds
        $filteredResults = [];
        foreach ($combinedResults as $result) {
            $fileId = $result['file_id'];

            // Check if file is accessible
            if (!in_array($fileId, $accessibleFileIds, true)) {
                continue;
            }

            $isFilenameMatch = $result['match_type'] === 'filename';
            $score = $result['score'];

            if ($isFilenameMatch) {
                // Exact filename match (1.0) always included
                // Partial filename match (0.85) uses lower threshold
                $isExactMatch = $score >= 1.0;
                if (!$isExactMatch && $score < self::FILENAME_PARTIAL_THRESHOLD) {
                    continue;
                }
            } else {
                // Content match uses standard threshold
                if ($score < $minScore) {
                    continue;
                }
            }

            $filteredResults[] = $result;
        }

        // Sort by score descending
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
                'filename' => $chunk->fileName,
                'snippet' => $snippet,
                'relevance' => $result['score'],
                'chunk_index' => $chunk->chunkIndex,
                'source' => $source,
                'match_type' => $result['match_type'] ?? 'content',
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
            'filename' => $fileName,
            'snippet' => $snippet,
            'relevance' => $relevanceScore,
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
     * Combine filename and content search results.
     *
     * Deduplicates by file_id, keeping filename match if exists (higher priority).
     * Filename matches are preserved with their match_type for threshold decisions.
     *
     * @param array $filenameResults Results from filename-based search
     * @param array $contentResults Results from content-based search
     * @return array Combined results with match_type annotations
     */
    private function combineSearchResults(array $filenameResults, array $contentResults): array
    {
        $combined = [];

        // Add filename results first (they have priority)
        foreach ($filenameResults as $result) {
            $fileId = $result['file_id'];
            $combined[$fileId] = array_merge($result, ['match_type' => 'filename']);
        }

        // Add content results, but don't override filename matches
        foreach ($contentResults as $result) {
            $fileId = $result['file_id'];
            if (!isset($combined[$fileId])) {
                $combined[$fileId] = array_merge($result, ['match_type' => 'content']);
            }
        }

        return array_values($combined);
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
