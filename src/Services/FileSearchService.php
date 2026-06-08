<?php

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Utils\Facades\FileModel;
use Illuminate\Database\Eloquent\Model;

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
     * Sentinel collection key for the chunk store's bound (primary) collection,
     * which is searched without an explicit collection name. Used to qualify
     * composite file identities and to normalize scores per source collection.
     */
    private const PRIMARY_COLLECTION_KEY = '__primary__';

    /**
     * Additional collections to union into content/filename search alongside the
     * chunk store's bound (primary) collection. Used so physical/documentation
     * docs (e.g. 'documentation_chunks') are returned by content search (B4).
     *
     * @var array<string>
     */
    private readonly array $additionalCollections;

    /**
     * @param ChunkStoreInterface $chunkStore
     * @param GraphStoreInterface $graphStore
     * @param array<string> $additionalCollections Extra collections to also search
     */
    public function __construct(
        private readonly ChunkStoreInterface $chunkStore,
        private readonly GraphStoreInterface $graphStore,
        array $additionalCollections = []
    ) {
        // Normalize: unique, non-empty strings.
        $this->additionalCollections = array_values(array_unique(array_filter(
            $additionalCollections,
            fn ($c) => is_string($c) && trim($c) !== ''
        )));
    }

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

        // Search Qdrant for chunks (get more than limit to account for grouping).
        // Primary collection uses the bound collection (no override); any
        // additional collections (e.g. documentation_chunks) are unioned in (B4).
        //
        // Cross-collection score normalization: raw cosine scores from different
        // collections are NOT comparable on the same scale (different embedding
        // distributions / point densities), so a strong match in one collection
        // could be unfairly out-ranked by a mediocre match in another. When more
        // than one collection contributes, each collection's result set is min-max
        // normalized to [0, 1] BEFORE merging so scores are comparable across the
        // union. Each result is tagged with its source collection so it can be
        // grouped under a COMPOSITE identity (collection-qualified id) — a physical
        // doc and a DB file can no longer collide on the same bare numeric id.
        //
        // When only the primary collection is searched there is nothing to make
        // comparable, so scores pass through UNCHANGED — preserving the absolute
        // cosine scale that downstream min_score thresholds rely on.
        $buckets = [];

        $primary = $this->chunkStore->searchByContent($query, $limit * 3, $filters);
        if (!empty($primary)) {
            $buckets[self::PRIMARY_COLLECTION_KEY] = $this->tagCollection($primary, self::PRIMARY_COLLECTION_KEY);
        }

        foreach ($this->additionalCollections as $collection) {
            try {
                $extra = $this->chunkStore->searchByContent($query, $limit * 3, $filters, $collection);
                if (!empty($extra)) {
                    $buckets[$collection] = $this->tagCollection($extra, $collection);
                }
            } catch (\Throwable $e) {
                // A failing secondary collection must not break primary results.
                continue;
            }
        }

        // Normalize per collection only when unioning multiple collections; a
        // single source needs no cross-collection rescaling.
        $normalize = count($buckets) > 1;

        $chunks = [];
        foreach ($buckets as $bucket) {
            $chunks = array_merge($chunks, $normalize ? $this->normalizeScores($bucket) : $bucket);
        }

        // Group chunks by a COMPOSITE identity (source collection + file id) so a
        // physical doc and a DB file sharing a bare numeric id are kept distinct.
        // The `physical:` prefix on physical-file ids is preserved within the key.
        $fileGroups = collect($chunks)->groupBy(fn($result) => $this->compositeFileKey($result));

        $results = [];
        foreach ($fileGroups as $compositeKey => $fileChunks) {
            // Score each file by its BEST chunk (max), not the average. Averaging
            // dilutes a strong, on-topic chunk with weaker ones and drops files
            // that are genuinely relevant (e.g. chunks [0.8, 0.2, 0.2, 0.2]
            // averaged to ~0.35 fell under a 0.4 threshold). Best-chunk scoring
            // keeps such files. The average is preserved as metadata. Scores here
            // are the per-collection-normalized scores, so cross-collection
            // comparison in the final sort is fair.
            $maxScore = collect($fileChunks)->max('score');
            $avgScore = collect($fileChunks)->avg('score');

            // Get best matching chunk for preview
            $bestChunk = collect($fileChunks)->sortByDesc('score')->first();

            $results[] = [
                // Keep the ORIGINAL file id for downstream FileModel loading and
                // consumers; the composite key is grouping-only.
                'file_id' => $bestChunk['chunk']->fileId,
                'composite_id' => $compositeKey,
                'collection' => $bestChunk['collection'] ?? self::PRIMARY_COLLECTION_KEY,
                'score' => $maxScore,
                'avg_score' => $avgScore,
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
        $files = FileModel::whereIn('id', $fileIds)->get()->keyBy('id');

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
     * Tag every result in one collection's result set with its source collection.
     * Scores are left untouched here (normalization, if any, happens separately).
     *
     * @param array  $results    Raw results: each ['chunk' => FileChunk, 'score' => float].
     * @param string $collection Source collection key (PRIMARY_COLLECTION_KEY for the bound one).
     * @return array Results with an added 'collection' tag.
     */
    private function tagCollection(array $results, string $collection): array
    {
        foreach ($results as &$r) {
            $r['collection'] = $collection;
        }
        unset($r);

        return $results;
    }

    /**
     * Min-max normalize a single collection's scores to [0, 1] within that set.
     *
     * Per-collection scaling makes scores comparable across the union of
     * collections (different collections have different raw cosine distributions).
     * When all scores in the set are equal (including a single-result set), every
     * result is treated as fully relevant within its set (normalized to 1.0)
     * rather than zeroed out. The raw cosine is preserved as 'raw_score'.
     *
     * @param array $results Tagged results for ONE collection.
     * @return array Results with a normalized 'score' and preserved 'raw_score'.
     */
    private function normalizeScores(array $results): array
    {
        if (empty($results)) {
            return [];
        }

        $scores = array_map(static fn ($r) => (float) ($r['score'] ?? 0.0), $results);
        $min = min($scores);
        $max = max($scores);
        $range = $max - $min;

        foreach ($results as &$r) {
            $raw = (float) ($r['score'] ?? 0.0);
            $r['raw_score'] = $raw;
            $r['score'] = $range > 0.0 ? (($raw - $min) / $range) : 1.0;
        }
        unset($r);

        return $results;
    }

    /**
     * Build a COMPOSITE, collision-proof identity for grouping a chunk's file.
     *
     * Combines the source collection with the chunk's file id so that a physical
     * document and a database file that happen to share the same bare numeric id
     * are never merged into one group. The `physical:` prefix that physical-file
     * ids already carry is preserved inside the key, adding a second layer of
     * disambiguation.
     *
     * @param array $result A tagged search result (['chunk' => FileChunk, 'collection' => string, ...]).
     * @return string Composite key, e.g. "documentation_chunks::physical:/docs/a.md" or "__primary__::5".
     */
    private function compositeFileKey(array $result): string
    {
        $collection = $result['collection'] ?? self::PRIMARY_COLLECTION_KEY;
        $fileId = $result['chunk']->fileId;

        return $collection . '::' . $fileId;
    }

    /**
     * Search files by filename
     *
     * @param string $filename The filename to search for
     * @param array $options Options:
     *   - limit: Maximum results (default: 10)
     *   - file_types: Filter by extensions (e.g., ['pdf', 'docx'])
     *   - file_id: Search within specific file
     *   - include_relationships: Load Neo4j relationships (default: false)
     * @return array
     */
    public function searchByFilename(string $filename, array $options = []): array
    {
        $limit = $options['limit'] ?? 10;
        $includeRelationships = $options['include_relationships'] ?? false;

        // Build filters for chunk store
        $filters = [];
        if (isset($options['file_types'])) {
            $filters['file_types'] = $options['file_types'];
        }
        if (isset($options['file_id'])) {
            $filters['file_id'] = $options['file_id'];
        }

        // Search chunk store by filename (primary collection), then union any
        // additional collections (e.g. documentation_chunks) so physical docs are
        // found by explicit filename references too (B4).
        $chunks = $this->chunkStore->searchByFilename($filename, $limit * 2, $filters);

        foreach ($this->additionalCollections as $collection) {
            try {
                $extra = $this->chunkStore->searchByFilename($filename, $limit * 2, $filters, $collection);
                $chunks = array_merge($chunks, $extra);
            } catch (\Throwable $e) {
                continue;
            }
        }

        if (empty($chunks)) {
            return [];
        }

        // Group chunks by file ID (match searchByContent pattern)
        $fileGroups = collect($chunks)->groupBy(fn($result) => $result['chunk']->fileId);

        $results = [];
        foreach ($fileGroups as $fileId => $fileChunks) {
            // Get best matching chunk for preview (highest score)
            $bestChunk = collect($fileChunks)->sortByDesc('score')->first();

            $results[] = [
                'file_id' => $fileId,
                'score' => $bestChunk['score'], // Use best chunk's score for filename match
                'best_chunk' => $bestChunk['chunk'],
                'chunk_count' => count($fileChunks),
                'chunks' => array_map(fn($r) => $r['chunk'], $fileChunks->toArray()),
            ];
        }

        // Sort by score descending and limit
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        $results = array_slice($results, 0, $limit);

        // Load File models
        $fileIds = array_column($results, 'file_id');
        $files = FileModel::whereIn('id', $fileIds)->get()->keyBy('id');

        // Enhance with File models
        foreach ($results as &$result) {
            $file = $files->get($result['file_id']);
            if ($file) {
                $result['file'] = $file;

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
        $files = FileModel::whereIn('id', $fileIds)->get();

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
     * @param Model $file
     * @param string|null $relationshipType Optional relationship type filter
     * @param int $limit Maximum results
     * @return array
     */
    public function getRelatedFiles(Model $file, ?string $relationshipType = null, int $limit = 10): array
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
        $files = FileModel::whereIn('id', $fileIds)->get()->keyBy('id');

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

        return FileModel::whereIn('id', $fileIds)
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

        return FileModel::whereIn('id', $fileIds)
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    /**
     * Get file relationships from Neo4j
     *
     * @param Model $file
     * @return array
     */
    protected function getFileRelationships(Model $file): array
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
