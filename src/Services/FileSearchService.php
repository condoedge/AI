<?php

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Support\FileLink;
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
     * composite file identities and to rank-fuse scores per source collection.
     */
    private const PRIMARY_COLLECTION_KEY = '__primary__';

    /**
     * Reciprocal Rank Fusion constant. Dampens the contribution of low ranks so a
     * top-ranked result in any collection dominates; 60 is the value from the
     * original RRF paper and the de-facto default.
     */
    private const RRF_K = 60;

    /**
     * Additional collections to union into content/filename search alongside the
     * chunk store's bound (primary) collection. Used so physical/documentation
     * docs (e.g. 'documentation_chunks') are returned by content search.
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
     *   - slugs: only the articles with these slugs (chunk metadata.slug)
     * @return array
     */
    public function searchByContent(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? 10;
        $includeRelationships = $options['include_relationships'] ?? false;

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
        // Exact-article angle (Phase 7.2): a payload filter on the chunk's article
        // numbers, applied to every collection (a team's by-laws have articles too).
        if (!empty($options['article_numbers']) && is_array($options['article_numbers'])) {
            $filters['article_numbers'] = array_values(array_map('strval', $options['article_numbers']));
        }
        // The documents the asker may read ({@see \Condoedge\Ai\Support\Audiences}), on every collection.
        if (is_array($options['audiences'] ?? null)) {
            $filters['audiences'] = $options['audiences'];
        }
        if (!empty($options['slugs']) && is_array($options['slugs'])) {
            $filters['slugs'] = array_values($options['slugs']);
        }

        // ACL as a PRE-filter (Phase 7.3): the caller's accessible DB file ids
        // restrict the DB-file (primary) collection inside the vector search, so
        // foreign files can never fill the top-k and push the user's own file out.
        // null = no pre-filter (security off / unresolved); [] = no accessible DB
        // file, so the primary collection is not searched at all. The physical
        // corpus collections are never ACL-filtered — it is readable by everyone.
        // The caller still post-filters every result (defense in depth).
        $aclFileIds = $options['acl_file_ids'] ?? null;
        $primaryFilters = $filters;
        if (is_array($aclFileIds)) {
            $primaryFilters['file_ids'] = array_values($aclFileIds);
        }

        // UPLOADED files only (the primary collection) — PROJECT files are global
        // and are searched below with none of this:
        //  - SCOPE: the record the question is about and the records that own it
        //    ({focus, ancestors}); the store keeps the documents of that record,
        //    of its owners and of what it owns, and those of no record. Beside
        //    the ACL, never instead of it.
        //  - DISTINCT: the same text filed several times on one record is one
        //    candidate, grouped inside the vector search — before this limit and
        //    every one after it (ai.file_context.dedupe, default on). `memo` lets
        //    one turn's angles agree on the copy that represents a group.
        if (is_array($options['scope'] ?? null) && is_string($options['scope']['focus'] ?? null) && $options['scope']['focus'] !== '') {
            $primaryFilters['scope'] = [
                'focus' => $options['scope']['focus'],
                'ancestors' => array_values(array_filter((array) ($options['scope']['ancestors'] ?? []), 'is_string')),
            ];
        }
        if (config('ai.file_context.dedupe', true)) {
            $primaryFilters['distinct'] = true;
            if (($options['memo'] ?? null) instanceof \ArrayObject) {
                $primaryFilters['memo'] = $options['memo'];
            }
        }

        // Search Qdrant for chunks (get more than limit to account for grouping).
        // Primary collection uses the bound collection (no override); any
        // additional collections (e.g. documentation_chunks) are unioned in.
        //
        // Raw cosine scores from different collections are NOT comparable on the
        // same scale, so when more than one collection contributes, results are
        // ordered by Reciprocal Rank Fusion (a rank-based fuse that needs no
        // comparable score scale) rather than by raw score. Crucially, each
        // result's RAW similarity is preserved as 'score' so downstream absolute
        // thresholds and the displayed relevance reflect real cosine similarity,
        // not a rescaled rank — a min-max rescale would boost a lone/weak match to
        // 1.0 and squash a strong match toward 0. Each result is tagged with its
        // source collection so it groups under a COMPOSITE identity
        // (collection-qualified id) and a physical doc and a DB file cannot collide
        // on the same bare numeric id.
        $buckets = [];

        $primary = $aclFileIds === []
            ? []
            : $this->chunkStore->searchByContent($query, $limit * 3, $primaryFilters);
        if (!empty($primary) && !empty($primaryFilters['distinct'])) {
            $primary = $this->onePassagePerText($primary);
        }
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
                // A failing secondary collection must not break primary results —
                // but the turn trace records it (observation only).
                \Condoedge\Ai\Support\PipelineObserverScope::current()->stageError('file_context', $e);
                continue;
            }
        }

        // Fuse by rank only when unioning multiple collections; a single source
        // is already on one comparable cosine scale and is ordered by raw score.
        $fuse = count($buckets) > 1;

        $chunks = [];
        foreach ($buckets as $bucket) {
            $chunks = array_merge($chunks, $fuse ? $this->assignRankFusion($bucket) : $bucket);
        }

        // Group chunks by a COMPOSITE identity (source collection + file id) so a
        // physical doc and a DB file sharing a bare numeric id are kept distinct.
        // The `physical:` prefix on physical-file ids is preserved within the key.
        $fileGroups = collect($chunks)->groupBy(fn($result) => $this->compositeFileKey($result));

        $results = [];
        foreach ($fileGroups as $compositeKey => $groupChunks) {
            // PREAMBLE (Phase 7.1b): title / table-of-contents / heading-only chunks
            // (chunk metadata is_preamble) match any query naming the document
            // ("Members' Policy Handbook") and used to win the file's slot with the chunk
            // that answers nothing. They never set the file's relevance or best
            // chunk while the file has any content chunk in the results.
            $contentChunks = collect($groupChunks)->reject(fn ($r) => self::isPreambleChunk($r['chunk']))->values();
            $preambleOnly = $contentChunks->isEmpty();
            $fileChunks = $preambleOnly ? collect($groupChunks) : $contentChunks;

            // Score each file by its BEST chunk (max RAW similarity), not the
            // average. Averaging dilutes a strong, on-topic chunk with weaker ones
            // and drops files that are genuinely relevant (e.g. chunks
            // [0.8, 0.2, 0.2, 0.2] averaged to ~0.35 fell under a 0.4 threshold).
            // 'score' stays RAW cosine so absolute thresholds and the displayed
            // relevance are real similarities. The average is kept as metadata.
            $maxScore = collect($fileChunks)->max('score');
            $avgScore = collect($fileChunks)->avg('score');

            $bestChunk = collect($fileChunks)->sortByDesc('score')->first();

            $result = [
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
                'preamble_only' => $preambleOnly && $fileChunks->contains(fn ($r) => self::isPreambleChunk($r['chunk'])),
                // Each retrieved chunk's own RAW similarity by chunk_index, so a
                // caller can surface several distinct passages of one file.
                'chunk_scores' => $fileChunks->reduce(function (array $carry, array $r) {
                    $index = (int) $r['chunk']->chunkIndex;
                    $carry[$index] = max($carry[$index] ?? 0.0, (float) ($r['score'] ?? 0.0));
                    return $carry;
                }, []),
            ];

            // The record an UPLOADED file is filed on (every chunk of a file carries
            // the same link), and — per chunk — how many other copies of the same
            // text under that owner the distinct search folded into it.
            $link = FileLink::ofChunk($bestChunk['chunk']);
            if ($link !== null) {
                $result['owner'] = $link[FileLink::OWNER];
                $result['ancestors'] = $link[FileLink::ANCESTORS];
            }
            $duplicates = [];
            $coOwners = [];
            foreach ($fileChunks as $r) {
                if ((int) ($r['duplicates'] ?? 0) > 0) {
                    $duplicates[(int) $r['chunk']->chunkIndex] = (int) $r['duplicates'];
                }
                if (!empty($r['co_owners'])) {
                    $coOwners[(int) $r['chunk']->chunkIndex] = $r['co_owners'];
                }
            }
            if ($duplicates !== []) {
                $result['duplicates'] = $duplicates;
            }
            // … and the OTHER records the same text is filed on (onePassagePerText()).
            if ($coOwners !== []) {
                $result['co_owners'] = $coOwners;
            }

            // When fusing collections, the cross-collection ordering key is the sum
            // of the group's per-chunk RRF contributions (rank-based, scale-free).
            if ($fuse) {
                $result['fusion_score'] = (float) collect($fileChunks)->sum('rrf');
            }

            $results[] = $result;
        }

        // A file that matched ONLY through preamble chunks is kept only when
        // nothing else matched (better a title page than no grounding at all).
        $withContent = array_values(array_filter($results, static fn (array $r) => !$r['preamble_only']));
        if ($withContent !== []) {
            $results = $withContent;
        }

        // Order by the rank-fusion score when collections were merged, otherwise by
        // raw similarity; then limit.
        $results = collect($results)
            ->sortByDesc($fuse ? 'fusion_score' : 'score')
            ->take($limit)
            ->values()
            ->toArray();

        $fileIds = array_column($results, 'file_id');
        $files = FileModel::whereIn('id', $fileIds)->get()->keyBy('id');

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
     * One passage per TEXT among the uploaded candidates.
     *
     * The store returns one candidate per (text, owner): the same text filed on
     * two records is two candidates, and stays two there — whose document it is
     * differs. For the TURN it is one passage: shown once per owner it took the
     * slots of the other documents (measured on a by-law filed on two projects
     * of one team: no passage of the policy on the questions that needed both).
     * It is kept ONCE — the copy with the lowest file id, so every angle of a
     * turn names the same file — at the best score, with the other owners it is
     * filed on (`co_owners`: each {owner, ancestors}), which the answer's header
     * states beside its own. Nothing is lost but the repetition.
     *
     * @param list<array{chunk: mixed, score?: float, duplicates?: int}> $results
     * @return list<array>
     */
    private function onePassagePerText(array $results): array
    {
        $byText = [];
        foreach ($results as $position => $result) {
            $chunk = $result['chunk'] ?? null;
            if (!is_object($chunk) || !isset($chunk->content)) {
                $byText['#' . $position] = [$result];
                continue;
            }
            $byText[FileLink::dedupeKey((string) $chunk->content, null)][] = $result;
        }

        $kept = [];
        foreach ($byText as $copies) {
            if (count($copies) === 1) {
                $kept[] = $copies[0];
                continue;
            }

            $first = $copies[0];
            foreach ($copies as $copy) {
                $byId = is_numeric($copy['chunk']->fileId) && is_numeric($first['chunk']->fileId)
                    ? $copy['chunk']->fileId <=> $first['chunk']->fileId
                    : strcmp((string) $copy['chunk']->fileId, (string) $first['chunk']->fileId);
                if (($byId ?: ($copy['chunk']->chunkIndex <=> $first['chunk']->chunkIndex)) < 0) {
                    $first = $copy;
                }
            }

            $own = FileLink::ofChunk($first['chunk'])[FileLink::OWNER] ?? null;
            $others = [];
            foreach ($copies as $copy) {
                $link = FileLink::ofChunk($copy['chunk']);
                $owner = $link[FileLink::OWNER] ?? null;
                if ($copy === $first || $owner === $own) {
                    continue;
                }
                $others[$owner ?? ''] = ['owner' => $owner, 'ancestors' => $link[FileLink::ANCESTORS] ?? []];
            }

            $first['score'] = max(array_map(static fn (array $copy): float => (float) ($copy['score'] ?? 0.0), $copies));
            if ($others !== []) {
                $first['co_owners'] = array_values($others);
            }
            $kept[] = $first;
        }

        return $kept;
    }

    /**
     * Whether a chunk is a title / TOC / heading-only chunk (chunker metadata).
     */
    private static function isPreambleChunk(mixed $chunk): bool
    {
        return is_object($chunk) && is_array($chunk->metadata ?? null) && !empty($chunk->metadata['is_preamble']);
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
     * Assign each result in one collection a Reciprocal Rank Fusion contribution.
     *
     * Ranking within the collection is by raw score descending (1-based); the
     * contribution is 1 / (RRF_K + rank). RRF fuses collections by rank rather than
     * by raw score, so a top-ranked match in any collection ranks highly without
     * needing a comparable cosine scale across collections. The RAW 'score' is left
     * untouched so absolute thresholds and the reported relevance stay on the real
     * cosine scale.
     *
     * @param array $results Tagged results for ONE collection.
     * @return array Results with an added 'rrf' fusion contribution.
     */
    private function assignRankFusion(array $results): array
    {
        if (empty($results)) {
            return [];
        }

        // Stable rank by raw score descending without mutating the input order.
        $ordered = $results;
        usort($ordered, static fn ($a, $b) => ($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0));

        $rrfByKey = new \SplObjectStorage();
        foreach ($ordered as $rank => $r) {
            $rrfByKey[$r['chunk']] = 1.0 / (self::RRF_K + $rank + 1);
        }

        foreach ($results as &$r) {
            $r['rrf'] = $rrfByKey[$r['chunk']];
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

        $filters = [];
        if (isset($options['file_types'])) {
            $filters['file_types'] = $options['file_types'];
        }
        if (isset($options['file_id'])) {
            $filters['file_id'] = $options['file_id'];
        }
        if (is_array($options['audiences'] ?? null)) {
            $filters['audiences'] = $options['audiences'];
        }

        // Search chunk store by filename (primary collection), then union any
        // additional collections (e.g. documentation_chunks) so physical docs are
        // found by explicit filename references too.
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

        $fileGroups = collect($chunks)->groupBy(fn($result) => $result['chunk']->fileId);

        $results = [];
        foreach ($fileGroups as $fileId => $fileChunks) {
            $bestChunk = collect($fileChunks)->sortByDesc('score')->first();

            $results[] = [
                'file_id' => $fileId,
                'score' => $bestChunk['score'],
                'best_chunk' => $bestChunk['chunk'],
                'chunk_count' => count($fileChunks),
                'chunks' => array_map(fn($r) => $r['chunk'], $fileChunks->toArray()),
            ];
        }

        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        $results = array_slice($results, 0, $limit);

        $fileIds = array_column($results, 'file_id');
        $files = FileModel::whereIn('id', $fileIds)->get()->keyBy('id');

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
}
