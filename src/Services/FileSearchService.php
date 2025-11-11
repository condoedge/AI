<?php

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Models\File;

/**
 * File Search Service
 *
 * Provides unified search across both storage systems:
 * - Content search: Qdrant (semantic similarity)
 * - Metadata search: Neo4j (graph queries)
 * - Hybrid search: Combines both approaches
 * - Relationship traversal: Find related files via graph
 */
class FileSearchService
{
    /**
     * @param ChunkStoreInterface $chunkStore
     * @param GraphStoreInterface $graphStore
     */
    public function __construct(
        private readonly ChunkStoreInterface $chunkStore,
        private readonly GraphStoreInterface $graphStore
    ) {}

    /**
     * Search files by content similarity
     *
     * @param string $query Search query
     * @param array $options Options:
     *   - limit: Maximum results (default: 10)
     *   - min_score: Minimum similarity score (default: 0.0)
     *   - file_types: Filter by extensions (e.g., ['pdf', 'docx'])
     *   - file_id: Search within specific file
     *   - include_relationships: Load Neo4j relationships (default: false)
     * @return array
     */
    public function searchByContent(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? 10;
        $includeRelationships = $options['include_relationships'] ?? false;

        // Build filters for Qdrant
        $filters = [];
        if (isset($options['file_id'])) {
            $filters['file_id'] = $options['file_id'];
        }
        if (isset($options['file_types'])) {
            $filters['file_types'] = $options['file_types'];
        }
        if (isset($options['min_score'])) {
            $filters['min_score'] = $options['min_score'];
        }

        // Search Qdrant for chunks (get more than limit to account for grouping)
        $chunks = $this->chunkStore->searchByContent($query, $limit * 3, $filters);

        // Group chunks by file and calculate aggregate scores
        $fileGroups = collect($chunks)->groupBy(fn($result) => $result['chunk']->fileId);

        $results = [];
        foreach ($fileGroups as $fileId => $fileChunks) {
            // Calculate average score for this file
            $avgScore = collect($fileChunks)->avg('score');

            // Get best matching chunk for preview
            $bestChunk = collect($fileChunks)->sortByDesc('score')->first();

            $results[] = [
                'file_id' => $fileId,
                'score' => $avgScore,
                'chunk_count' => count($fileChunks),
                'best_chunk' => $bestChunk['chunk'],
                'chunks' => array_map(fn($r) => $r['chunk'], $fileChunks->toArray()),
            ];
        }

        // Sort by aggregate score and limit
        $results = collect($results)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->toArray();

        // Load File models
        $fileIds = array_column($results, 'file_id');
        $files = File::whereIn('id', $fileIds)->get()->keyBy('id');

        // Enhance results with File models
        foreach ($results as &$result) {
            $file = $files->get($result['file_id']);
            if ($file) {
                $result['file'] = $file;

                // Optionally load relationships from Neo4j
                if ($includeRelationships) {
                    $result['relationships'] = $this->getFileRelationships($file);
                }
            }
        }

        return $results;
    }

    /**
     * Search files by metadata (Neo4j graph query)
     *
     * @param array $criteria Search criteria:
     *   - extension: File extension
     *   - mime_type: MIME type
     *   - user_id: Uploaded by user
     *   - team_id: Belongs to team
     *   - uploaded_after: Date filter
     *   - uploaded_before: Date filter
     *   - size_min: Minimum file size
     *   - size_max: Maximum file size
     * @param int $limit Maximum results
     * @return array
     */
    public function searchByMetadata(array $criteria, int $limit = 10): array
    {
        $cypher = $this->buildMetadataQuery($criteria, $limit);
        $results = $this->graphStore->query($cypher, $criteria);

        // Extract file IDs from results
        $fileIds = array_column($results, 'id');

        if (empty($fileIds)) {
            return [];
        }

        // Load File models
        $files = File::whereIn('id', $fileIds)->get();

        return $files->map(function ($file) {
            return [
                'file' => $file,
                'relationships' => $this->getFileRelationships($file),
            ];
        })->toArray();
    }

    /**
     * Hybrid search combining content and metadata
     *
     * @param string $contentQuery Content search query
     * @param array $metadataFilters Metadata filters
     * @param array $options Search options
     * @return array
     */
    public function hybridSearch(
        string $contentQuery,
        array $metadataFilters = [],
        array $options = []
    ): array {
        $limit = $options['limit'] ?? 10;

        // Step 1: Content search
        $contentResults = $this->searchByContent($contentQuery, array_merge($options, [
            'limit' => $limit * 2, // Get more for filtering
            'include_relationships' => false,
        ]));

        // Step 2: Apply metadata filters
        if (!empty($metadataFilters)) {
            $contentResults = $this->filterByMetadata($contentResults, $metadataFilters);
        }

        // Step 3: Limit results
        $contentResults = array_slice($contentResults, 0, $limit);

        // Step 4: Enhance with relationships
        foreach ($contentResults as &$result) {
            if (isset($result['file'])) {
                $result['relationships'] = $this->getFileRelationships($result['file']);
            }
        }

        return $contentResults;
    }

    /**
     * Get related files via graph traversal
     *
     * @param File $file
     * @param string|null $relationshipType Optional relationship type filter
     * @param int $limit Maximum results
     * @return array
     */
    public function getRelatedFiles(File $file, ?string $relationshipType = null, int $limit = 10): array
    {
        $relationshipFilter = $relationshipType ? ":{$relationshipType}" : '';

        $cypher = "
            MATCH (f:File {id: \$file_id})-[r{$relationshipFilter}]-(related:File)
            RETURN DISTINCT related.id as id, type(r) as relationship_type
            LIMIT \$limit
        ";

        $results = $this->graphStore->query($cypher, [
            'file_id' => $file->id,
            'limit' => $limit,
        ]);

        if (empty($results)) {
            return [];
        }

        $fileIds = array_column($results, 'id');
        $files = File::whereIn('id', $fileIds)->get()->keyBy('id');

        return collect($results)->map(function ($result) use ($files) {
            return [
                'file' => $files->get($result['id']),
                'relationship_type' => $result['relationship_type'],
            ];
        })->toArray();
    }

    /**
     * Get files uploaded by a specific user
     *
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getFilesByUser(int $userId, int $limit = 10): array
    {
        $cypher = "
            MATCH (u:User {id: \$user_id})<-[:UPLOADED_BY]-(f:File)
            RETURN f.id as id
            ORDER BY f.uploaded_at DESC
            LIMIT \$limit
        ";

        $results = $this->graphStore->query($cypher, [
            'user_id' => $userId,
            'limit' => $limit,
        ]);

        $fileIds = array_column($results, 'id');

        return File::whereIn('id', $fileIds)
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    /**
     * Get files belonging to a specific team
     *
     * @param int $teamId
     * @param int $limit
     * @return array
     */
    public function getFilesByTeam(int $teamId, int $limit = 10): array
    {
        $cypher = "
            MATCH (t:Team {id: \$team_id})<-[:BELONGS_TO_TEAM]-(f:File)
            RETURN f.id as id
            ORDER BY f.uploaded_at DESC
            LIMIT \$limit
        ";

        $results = $this->graphStore->query($cypher, [
            'team_id' => $teamId,
            'limit' => $limit,
        ]);

        $fileIds = array_column($results, 'id');

        return File::whereIn('id', $fileIds)
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    /**
     * Get file relationships from Neo4j
     *
     * @param File $file
     * @return array
     */
    protected function getFileRelationships(File $file): array
    {
        $cypher = "
            MATCH (f:File {id: \$file_id})-[r]-(other)
            RETURN type(r) as type, labels(other) as labels, properties(other) as properties
        ";

        return $this->graphStore->query($cypher, ['file_id' => $file->id]);
    }

    /**
     * Build metadata query from criteria
     *
     * @param array $criteria
     * @param int $limit
     * @return string
     */
    protected function buildMetadataQuery(array $criteria, int $limit): string
    {
        $conditions = [];

        if (isset($criteria['extension'])) {
            $conditions[] = "f.extension = \$extension";
        }

        if (isset($criteria['mime_type'])) {
            $conditions[] = "f.mime_type = \$mime_type";
        }

        if (isset($criteria['user_id'])) {
            $conditions[] = "EXISTS((f)-[:UPLOADED_BY]->(:User {id: \$user_id}))";
        }

        if (isset($criteria['team_id'])) {
            $conditions[] = "EXISTS((f)-[:BELONGS_TO_TEAM]->(:Team {id: \$team_id}))";
        }

        if (isset($criteria['size_min'])) {
            $conditions[] = "f.size >= \$size_min";
        }

        if (isset($criteria['size_max'])) {
            $conditions[] = "f.size <= \$size_max";
        }

        $whereClause = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return "
            MATCH (f:File)
            {$whereClause}
            RETURN f.id as id
            ORDER BY f.uploaded_at DESC
            LIMIT {$limit}
        ";
    }

    /**
     * Filter results by metadata criteria
     *
     * @param array $results
     * @param array $filters
     * @return array
     */
    protected function filterByMetadata(array $results, array $filters): array
    {
        return array_filter($results, function ($result) use ($filters) {
            $file = $result['file'] ?? null;
            if (!$file) {
                return false;
            }

            foreach ($filters as $key => $value) {
                if (property_exists($file, $key) && $file->$key !== $value) {
                    return false;
                }
            }

            return true;
        });
    }
}
