<?php

declare(strict_types=1);

namespace Condoedge\Ai\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * FileSearch Facade - Laravel Facade for File Search Service
 *
 * This facade provides static access to the FileSearchService while properly
 * leveraging Laravel's service container. Use this to search files across
 * both Neo4j (metadata/relationships) and Qdrant (content) storage.
 *
 * **Basic Usage:**
 * ```php
 * use Condoedge\Ai\Facades\FileSearch;
 *
 * // Search by content (semantic search in Qdrant)
 * $results = FileSearch::searchByContent("Laravel configuration", [
 *     'limit' => 5,
 *     'file_types' => ['pdf', 'md'],
 *     'min_score' => 0.7,
 * ]);
 *
 * // Search by metadata (Neo4j graph query)
 * $files = FileSearch::searchByMetadata([
 *     'extension' => 'pdf',
 *     'user_id' => 123,
 *     'size_min' => 1000,
 * ], limit: 10);
 *
 * // Hybrid search (combines content + metadata)
 * $results = FileSearch::hybridSearch(
 *     contentQuery: "Redis configuration",
 *     metadataFilters: ['extension' => 'md'],
 *     options: ['limit' => 10, 'include_relationships' => true]
 * );
 *
 * // Get related files via graph traversal
 * $related = FileSearch::getRelatedFiles($file, 'BELONGS_TO', limit: 10);
 *
 * // Get files by user
 * $files = FileSearch::getFilesByUser(userId: 123, limit: 10);
 *
 * // Get files by team
 * $files = FileSearch::getFilesByTeam(teamId: 456, limit: 10);
 * ```
 *
 * **Search Results Format:**
 * ```php
 * // searchByContent returns:
 * [
 *     [
 *         'file_id' => 1,
 *         'score' => 0.85,
 *         'chunk_count' => 3,
 *         'best_chunk' => FileChunk,
 *         'chunks' => [FileChunk, FileChunk, FileChunk],
 *         'file' => File,
 *         'relationships' => [...], // If include_relationships = true
 *     ],
 *     // ...
 * ]
 *
 * // searchByMetadata returns:
 * [
 *     [
 *         'file' => File,
 *         'relationships' => [
 *             ['type' => 'UPLOADED_BY', 'labels' => ['User'], 'properties' => [...]],
 *             ['type' => 'BELONGS_TO_TEAM', 'labels' => ['Team'], 'properties' => [...]],
 *         ],
 *     ],
 *     // ...
 * ]
 * ```
 *
 * **Use Cases:**
 * ```php
 * // 1. Find documentation about a topic
 * $docs = FileSearch::searchByContent("How to configure queues?", [
 *     'file_types' => ['pdf', 'md'],
 *     'limit' => 5,
 * ]);
 *
 * // 2. Find all PDFs uploaded by a specific user
 * $files = FileSearch::searchByMetadata([
 *     'extension' => 'pdf',
 *     'user_id' => auth()->id(),
 * ]);
 *
 * // 3. Semantic search with metadata constraints
 * $results = FileSearch::hybridSearch(
 *     contentQuery: "database migration",
 *     metadataFilters: [
 *         'extension' => 'md',
 *         'team_id' => currentTeam()->id,
 *     ]
 * );
 *
 * // 4. Find files related to a project
 * $projectFiles = FileSearch::getRelatedFiles($projectFile, 'BELONGS_TO');
 *
 * // 5. Get AI-powered answers using file context
 * $results = FileSearch::searchByContent("Redis configuration");
 * $context = collect($results)->flatMap(fn($r) => $r['chunks'])->pluck('content');
 * $answer = AI::answerQuestion("How do I configure Redis?", [
 *     'context' => $context->implode("\n\n"),
 * ]);
 * ```
 *
 * **Testing:**
 * ```php
 * use Condoedge\Ai\Facades\FileSearch;
 *
 * // Mock facade in tests
 * FileSearch::shouldReceive('searchByContent')
 *     ->once()
 *     ->with('test query', ['limit' => 10])
 *     ->andReturn([
 *         [
 *             'file_id' => 1,
 *             'score' => 0.85,
 *             'file' => $mockFile,
 *         ]
 *     ]);
 * ```
 *
 * @method static array searchByContent(string $query, array $options = [])
 * @method static array searchByMetadata(array $criteria, int $limit = 10)
 * @method static array hybridSearch(string $contentQuery, array $metadataFilters = [], array $options = [])
 * @method static array getRelatedFiles(\Condoedge\Ai\Models\File $file, ?string $relationshipType = null, int $limit = 10)
 * @method static array getFilesByUser(int $userId, int $limit = 10)
 * @method static array getFilesByTeam(int $teamId, int $limit = 10)
 *
 * @see \Condoedge\Ai\Services\FileSearchService
 * @package Condoedge\Ai\Facades
 */
class FileSearch extends Facade
{
    /**
     * Get the registered name of the component
     *
     * This returns the key used in the service container to resolve
     * the underlying FileSearchService instance.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'file-search';
    }
}
