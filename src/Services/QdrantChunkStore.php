<?php

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\ChunkLinkStoreInterface;
use Condoedge\Ai\Contracts\ChunkMapLookupInterface;
use Condoedge\Ai\Contracts\ChunkMapStoreInterface;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\GroupedSearchInterface;
use Condoedge\Ai\Contracts\UpdatesPayloadInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Support\FileLink;

/**
 * Qdrant-based chunk storage service
 *
 * This service stores file chunks with their embeddings in Qdrant
 * and provides semantic search capabilities.
 */
class QdrantChunkStore implements ChunkStoreInterface, ChunkMapStoreInterface, ChunkMapLookupInterface, ChunkLinkStoreInterface
{
    /**
     * Default collection name for file chunks
     */
    private const DEFAULT_COLLECTION = 'file_chunks';

    /**
     * Default embedding vector dimension (OpenAI ada-002)
     */
    private const DEFAULT_VECTOR_SIZE = 1536;

    /**
     * Top-level payload key holding a chunk's document map (written after
     * ingest by setChunkMap(), read back into FileChunk::$metadata['map']).
     */
    private const MAP_PAYLOAD_KEY = 'map';

    /**
     * @var int Embedding vector dimension
     */
    private readonly int $vectorSize;

    /** A distinct search on a collection that cannot be grouped fetches this many times its limit … */
    private const DISTINCT_OVERFETCH = 4;

    /** … up to this many points. */
    private const DISTINCT_FETCH_CAP = 400;

    /** Most copies read to pick the representatives of one search's groups. */
    private const DISTINCT_COPIES_CAP = 5000;

    /** Page size of a scroll over a whole collection. */
    private const SCROLL_PAGE = 1000;

    /** Points per page, and pages at most, of a lookup of stored maps by text hash (findChunkMaps()). */
    private const LOOKUP_PAGE = 256;
    private const LOOKUP_PAGES = 200;

    /**
     * @var array<string,bool> Collections whose existence/index has been verified
     */
    private array $verifiedCollections = [];

    /** @var array<string,bool> Collections in which every point carries a dedupe key */
    private array $fullyLinked = [];

    /** @var array<string,bool> Collections whose link-key indexes were ensured */
    private array $linkIndexed = [];

    /**
     * @param VectorStoreInterface $vectorStore
     * @param EmbeddingProviderInterface $embeddingProvider
     * @param string $collection Collection name
     * @param int|null $vectorSize Embedding dimension (defaults to config or 1536)
     */
    public function __construct(
        private readonly VectorStoreInterface $vectorStore,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly string $collection = self::DEFAULT_COLLECTION,
        ?int $vectorSize = null
    ) {
        $this->vectorSize = $vectorSize ?? $this->resolveVectorSize();
    }

    /**
     * {@inheritdoc}
     */
    public function storeChunk(FileChunk $chunk, ?string $collection = null): bool
    {
        return $this->storeChunks([$chunk], $collection);
    }

    /**
     * {@inheritdoc}
     */
    public function storeChunks(array $chunks, ?string $collection = null): bool
    {
        if (empty($chunks)) {
            return true;
        }

        $target = $this->resolveCollection($collection);
        $this->ensureCollectionExists($target);

        $points = [];
        $linked = false;

        foreach ($chunks as $chunk) {
            if (!($chunk instanceof FileChunk)) {
                throw new \InvalidArgumentException('All chunks must be FileChunk instances');
            }

            $this->assertValidVector($chunk);

            // A chunk that comes with a document map (a record carried over a
            // re-ingest of unchanged text — FileProcessor) gets it where it is
            // read from: the point's own top-level key, not inside `metadata`.
            $metadata = $chunk->metadata;
            $map = is_array($metadata[self::MAP_PAYLOAD_KEY] ?? null) ? $metadata[self::MAP_PAYLOAD_KEY] : null;
            unset($metadata[self::MAP_PAYLOAD_KEY]);

            // The link of an UPLOADED file to its record (FileProcessor): three
            // top-level keys the search filters and groups by. A project file
            // carries none — it is global by construction.
            $link = FileLink::ofChunk($chunk);
            unset($metadata[FileLink::METADATA_KEY]);
            $linked = $linked || $link !== null;

            $points[] = [
                'id' => $chunk->getVectorId(),
                'vector' => $chunk->embedding,
                'payload' => [
                    'file_id' => $chunk->fileId,
                    'file_name' => $chunk->fileName,
                    'content' => $chunk->content,
                    'chunk_index' => $chunk->chunkIndex,
                    'total_chunks' => $chunk->totalChunks,
                    'start_position' => $chunk->startPosition,
                    'end_position' => $chunk->endPosition,
                    'metadata' => $metadata,
                    'created_at' => time(),
                ] + ($map !== null ? [self::MAP_PAYLOAD_KEY => $map] : []) + ($link ?? []),
            ];
        }

        if ($linked) {
            $this->ensureLinkIndexes($target);
        }

        return $this->vectorStore->upsert($target, $points);
    }

    /**
     * {@inheritdoc}
     *
     * The map lives under its own TOP-LEVEL payload key (`map`), next to — not
     * inside — the ingest-time `metadata`: a payload write replaces whole
     * top-level keys, so nesting it there would overwrite the structural
     * metadata the chunker stored. It is written as a payload merge on the
     * existing point, so the embedding is neither needed nor touched.
     *
     * The collection is deliberately NOT created here: a map only makes sense
     * on a point that already exists, and a missing collection is just a failed
     * write.
     */
    public function setChunkMap(FileChunk $chunk, array $map, ?string $collection = null): bool
    {
        // Capability check: a vector store that cannot update payloads in place
        // leaves the chunk unmapped (reported as false) instead of failing.
        if (!($this->vectorStore instanceof UpdatesPayloadInterface)) {
            return false;
        }

        try {
            return $this->vectorStore->setPayload(
                $this->resolveCollection($collection),
                [self::MAP_PAYLOAD_KEY => $map],
                [$chunk->getVectorId()]
            );
        } catch (\Throwable $e) {
            // Best-effort by contract: the chunk stays stored and searchable
            // without its map, so the caller decides whether to retry.
            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     * A filtered scroll that reads the `map` key only — neither a chunk's text
     * nor any other payload key leaves the store. After each page the texts whose
     * GIST was found are taken out of the filter, so the copies of a document
     * filed many times are not read once per copy. Bounded by LOOKUP_PAGES pages.
     *
     * No payload index is created here (`map.hash` is filtered by a scan of the
     * collection: milliseconds at thousands of points, index-time only).
     */
    public function findChunkMaps(array $hashes, ?string $collection = null): array
    {
        $wanted = [];
        foreach ($hashes as $hash) {
            if (is_string($hash) && $hash !== '') {
                $wanted[$hash] = true;
            }
        }
        if ($wanted === [] || !$this->vectorStore instanceof GroupedSearchInterface) {
            return [];
        }

        $target = $this->resolveCollection($collection);
        $found = [];
        $offset = null;
        for ($page = 0; $page < self::LOOKUP_PAGES && $wanted !== []; $page++) {
            $result = $this->vectorStore->scrollPage(
                $target,
                ['must' => [['key' => self::MAP_PAYLOAD_KEY . '.hash', 'match' => ['any' => array_map('strval', array_keys($wanted))]]]],
                self::LOOKUP_PAGE,
                ['include' => [self::MAP_PAYLOAD_KEY]],
                $offset
            );
            foreach ($result['points'] as $point) {
                $record = $point['payload'][self::MAP_PAYLOAD_KEY] ?? null;
                $hash = is_array($record) ? ($record['hash'] ?? null) : null;
                if (!is_string($hash) || $hash === '') {
                    continue;
                }
                $found[$hash][] = $record;
                if (is_string($record['gist'] ?? null) && trim($record['gist']) !== '') {
                    unset($wanted[$hash]);
                }
            }
            $offset = $result['next'];
            if ($offset === null || $result['points'] === []) {
                break;
            }
        }

        return $found;
    }

    /**
     * {@inheritdoc}
     */
    public function searchByContent(string $query, int $limit = 10, array $filters = [], ?string $collection = null): array
    {
        $target = $this->resolveCollection($collection);
        $this->ensureCollectionExists($target);

        $queryEmbedding = $this->embeddingProvider->embed($query);
        $qdrantFilter = $this->buildQdrantFilter($filters);
        $scoreThreshold = $filters['min_score'] ?? 0.0;

        // DISTINCT PASSAGES (`distinct`): one candidate per (normalised text,
        // owner) — the same document filed several times on one record is ONE
        // passage, whatever the limit.
        if (!empty($filters['distinct'])) {
            return $this->searchDistinct(
                $target,
                $queryEmbedding,
                $limit,
                $qdrantFilter,
                (float) $scoreThreshold,
                ($filters['memo'] ?? null) instanceof \ArrayObject ? $filters['memo'] : null
            );
        }

        $results = $this->vectorStore->search(
            $target,
            $queryEmbedding,
            $limit,
            $qdrantFilter,
            $scoreThreshold
        );

        return array_map(fn ($result) => [
            'chunk' => $this->chunkFromPayload($result['payload'] ?? []),
            'score' => $result['score'] ?? 0.0,
        ], $results);
    }

    /**
     * A content search that returns DISTINCT passages: chunks with the same
     * normalised text under the same owner (one `dedupe` key, {@see FileLink})
     * are one candidate, represented by the copy with the lowest file id, with
     * `duplicates` = how many other copies stand behind it.
     *
     * WHY INSIDE THE SEARCH. Copies of one text have one vector: the nearest
     * neighbours of a chunk are its own copies at the same score (measured: 35
     * copies at 1.0 before any other text), so a plain top-k holds one passage
     * k times and collapsing it afterwards leaves one candidate. The vector
     * store groups by the key BEFORE the limit ({@see GroupedSearchInterface}).
     *
     * WHY A SECOND LOOKUP. Which copy a group returns is arbitrary (equal
     * scores), and two search angles of one turn must name the SAME file or the
     * copies come back as different files downstream. The copies of the returned
     * groups are read once — under the same filter as the search, the ACL
     * included, without their text — and the lowest file id represents each.
     * With a turn memo that read happens once per group and turn.
     *
     * A grouped search does not return points WITHOUT the key. Until every point
     * of the collection carries it (`ai:files:link`), and on a store that cannot
     * group, the search over-fetches plainly and collapses in code — the copies
     * can then still use up the fetch: a degraded mode, not the design.
     *
     * @param array<int, float> $vector
     * @return list<array{chunk: FileChunk, score: float, duplicates: int}>
     */
    private function searchDistinct(string $target, array $vector, int $limit, array $filter, float $scoreThreshold, ?\ArrayObject $memo): array
    {
        // FAST PATH — the normal library has no copies: a plain search whose
        // results are all different passages IS the distinct search, at the cost
        // of the search made before this existed. (Copies share one vector, so a
        // text with copies shows up as a repeated key right here; only a group
        // cut by the limit itself could hide one.)
        //
        // ONCE PER TURN. The angles of one turn search the same files under the
        // same filter: when one of them showed copies, the next would only pay the
        // plain search to learn it again (measured live: 10 plain searches of
        // ~25 ms thrown away per document turn). The turn memo remembers it and
        // the later angles go straight to the grouped search — which is correct
        // with or without copies, only dearer. Without a memo nothing is assumed.
        $copiesSeenKey = 'distinct_copies:' . $target . ':' . sha1((string) json_encode($filter));
        $canGroup = null;
        if ($memo !== null && $memo->offsetExists($copiesSeenKey)) {
            $canGroup = $this->vectorStore instanceof GroupedSearchInterface && $this->everyPointHasDedupeKey($target);
        }

        if ($canGroup !== true) {
            $plain = $this->vectorStore->search($target, $vector, $limit, $filter, $scoreThreshold);
            $seen = [];
            foreach ($plain as $result) {
                $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
                $key = FileLink::fromPayload($payload)[FileLink::DEDUPE] ?? FileLink::dedupeKey((string) ($payload['content'] ?? ''), null);
                if (isset($seen[$key])) {
                    $seen = null;
                    break;
                }
                $seen[$key] = true;
            }
            if ($seen !== null) {
                return array_map(fn (array $result): array => [
                    'chunk' => $this->chunkFromPayload($result['payload'] ?? []),
                    'score' => $result['score'] ?? 0.0,
                    'duplicates' => 0,
                ], $plain);
            }
            if ($memo !== null) {
                $memo[$copiesSeenKey] = true;
            }
        }

        if (!($canGroup ?? ($this->vectorStore instanceof GroupedSearchInterface && $this->everyPointHasDedupeKey($target)))) {
            return $this->collapsed(
                $this->vectorStore->search($target, $vector, min(max($limit * self::DISTINCT_OVERFETCH, $limit), self::DISTINCT_FETCH_CAP), $filter, $scoreThreshold),
                $limit
            );
        }

        $groups = $this->vectorStore->searchGroups($target, $vector, FileLink::DEDUPE, $limit, $filter, $scoreThreshold);
        if ($groups === []) {
            return [];
        }

        $copies = $this->copiesOf($target, array_map(static fn (array $group): string => (string) $group['key'], $groups), $filter, $memo);

        $results = [];
        foreach ($groups as $group) {
            $hit = $group['hits'][0] ?? null;
            if (!is_array($hit)) {
                continue;
            }
            $payload = $hit['payload'] ?? [];
            $known = $copies[(string) $group['key']] ?? null;
            if ($known !== null) {
                // The representative's own point, with the text the search returned
                // (the same text: that is what the key says).
                $payload = $known['payload'] + ['content' => $payload['content'] ?? ''];
            }
            $results[] = [
                'chunk' => $this->chunkFromPayload($payload),
                'score' => $hit['score'] ?? 0.0,
                'duplicates' => $known !== null ? max(0, $known['count'] - 1) : 0,
            ];
        }

        return $results;
    }

    /**
     * Per dedupe key: the payload (without the text) of the copy with the lowest
     * file id among the points the search could return, and how many copies
     * there are.
     *
     * @param list<string> $keys
     * @return array<string, array{payload: array<string, mixed>, count: int}>
     */
    private function copiesOf(string $target, array $keys, array $filter, ?\ArrayObject $memo): array
    {
        $memoPrefix = 'dedupe:' . $target . ':' . sha1((string) json_encode($filter)) . ':';
        $known = [];
        $missing = [];
        foreach ($keys as $key) {
            if ($memo !== null && $memo->offsetExists($memoPrefix . $key)) {
                $known[$key] = $memo[$memoPrefix . $key];
            } else {
                $missing[] = $key;
            }
        }
        if ($missing === [] || !$this->vectorStore instanceof GroupedSearchInterface) {
            return array_filter($known);
        }

        $copiesFilter = $filter;
        $copiesFilter['must'][] = ['key' => FileLink::DEDUPE, 'match' => ['any' => array_values($missing)]];

        $found = [];
        try {
            $page = $this->vectorStore->scrollPage($target, $copiesFilter, self::DISTINCT_COPIES_CAP, ['exclude' => ['content']]);
            foreach ($page['points'] as $point) {
                $payload = is_array($point['payload'] ?? null) ? $point['payload'] : [];
                $key = $payload[FileLink::DEDUPE] ?? null;
                if (!is_string($key) || !isset($payload['file_id'])) {
                    continue;
                }
                $found[$key]['count'] = ($found[$key]['count'] ?? 0) + 1;
                if (!isset($found[$key]['payload']) || self::precedes($payload, $found[$key]['payload'])) {
                    $found[$key]['payload'] = $payload;
                }
            }
        } catch (\Throwable $e) {
            // The copies could not be read: each group keeps the copy the search
            // returned (still one candidate per group).
        }

        foreach ($missing as $key) {
            $known[$key] = $found[$key] ?? null;
            if ($memo !== null) {
                $memo[$memoPrefix . $key] = $known[$key];
            }
        }

        return array_filter($known);
    }

    /**
     * Plain results collapsed to one per (normalised text, owner): the copy with
     * the lowest file id among those returned, at the group's best score.
     *
     * @param array<int, array<string, mixed>> $results
     * @return list<array{chunk: FileChunk, score: float, duplicates: int}>
     */
    private function collapsed(array $results, int $limit): array
    {
        $groups = [];
        foreach ($results as $result) {
            $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
            $link = FileLink::fromPayload($payload);
            $key = $link[FileLink::DEDUPE] ?? FileLink::dedupeKey((string) ($payload['content'] ?? ''), null);
            $score = (float) ($result['score'] ?? 0.0);

            if (!isset($groups[$key])) {
                $groups[$key] = ['payload' => $payload, 'score' => $score, 'count' => 1];
                continue;
            }
            $groups[$key]['count']++;
            $groups[$key]['score'] = max($groups[$key]['score'], $score);
            if (self::precedes($payload, $groups[$key]['payload'])) {
                $groups[$key]['payload'] = $payload;
            }
        }

        return array_map(fn (array $group): array => [
            'chunk' => $this->chunkFromPayload($group['payload']),
            'score' => $group['score'],
            'duplicates' => $group['count'] - 1,
        ], array_slice(array_values($groups), 0, max(0, $limit)));
    }

    /** Whether point payload $a is the copy that represents its group before $b (lowest file id, then chunk). */
    private static function precedes(array $a, array $b): bool
    {
        $left = $a['file_id'] ?? '';
        $right = $b['file_id'] ?? '';
        $byId = is_numeric($left) && is_numeric($right) ? $left <=> $right : strcmp((string) $left, (string) $right);

        return ($byId ?: ((int) ($a['chunk_index'] ?? 0) <=> (int) ($b['chunk_index'] ?? 0))) < 0;
    }

    /**
     * Whether every point of the collection carries a `dedupe` key (a grouped
     * search silently leaves out the ones that do not). A yes is remembered for
     * this instance; a no is asked again — the backfill may be running.
     */
    private function everyPointHasDedupeKey(string $target): bool
    {
        if (!empty($this->fullyLinked[$target])) {
            return true;
        }

        try {
            $unlinked = $this->vectorStore->count($target, ['must' => [['is_empty' => ['key' => FileLink::DEDUPE]]]]);
        } catch (\Throwable $e) {
            return false;
        }

        return $this->fullyLinked[$target] = $unlinked === 0;
    }

    /**
     * {@inheritdoc}
     */
    public function linkFileChunks(int|string $fileId, array $owner, ?string $collection = null, bool $dryRun = false): array
    {
        $target = $this->resolveCollection($collection);
        $chunks = $this->getFileChunks($fileId, $target);

        $changed = 0;
        foreach ($chunks as $chunk) {
            $link = FileLink::payload($owner, $chunk->content);
            if (FileLink::ofChunk($chunk) === $link) {
                continue;
            }
            $changed++;
            if ($dryRun) {
                continue;
            }
            if (!$this->vectorStore instanceof UpdatesPayloadInterface
                || !$this->vectorStore->setPayload($target, $link, [$chunk->getVectorId()])) {
                throw new \RuntimeException("The link of file {$fileId} chunk {$chunk->chunkIndex} could not be written to '{$target}'.");
            }
        }

        return ['points' => count($chunks), 'changed' => $changed];
    }

    /**
     * {@inheritdoc}
     */
    public function storedFileIds(?string $collection = null): array
    {
        $target = $this->resolveCollection($collection);
        if (!$this->vectorStore instanceof GroupedSearchInterface) {
            throw new \RuntimeException('The vector store cannot list the files of a collection (no paged scroll).');
        }

        $ids = [];
        $offset = null;
        do {
            $page = $this->vectorStore->scrollPage($target, [], self::SCROLL_PAGE, ['include' => ['file_id']], $offset);
            foreach ($page['points'] as $point) {
                $fileId = $point['payload']['file_id'] ?? null;
                if (is_int($fileId) || is_string($fileId)) {
                    $ids[(string) $fileId] = $fileId;
                }
            }
            $offset = $page['next'];
        } while ($offset !== null && $page['points'] !== []);

        $ids = array_values($ids);
        usort($ids, static fn ($a, $b): int => is_numeric($a) && is_numeric($b) ? $a <=> $b : strcmp((string) $a, (string) $b));

        return $ids;
    }

    /**
     * {@inheritdoc}
     */
    public function ensureLinkIndexes(?string $collection = null): void
    {
        $target = $this->resolveCollection($collection);
        if (!empty($this->linkIndexed[$target])) {
            return;
        }

        foreach ([FileLink::OWNER, FileLink::ANCESTORS, FileLink::DEDUPE] as $key) {
            $this->vectorStore->ensurePayloadIndex($target, $key, 'keyword');
        }
        $this->linkIndexed[$target] = true;
    }

    /** A FileChunk from a point payload (no embedding: search results do not carry one). */
    private function chunkFromPayload(array $payload): FileChunk
    {
        return new FileChunk(
            fileId: $payload['file_id'],
            fileName: $payload['file_name'],
            content: $payload['content'],
            embedding: [],
            chunkIndex: $payload['chunk_index'],
            totalChunks: $payload['total_chunks'],
            startPosition: $payload['start_position'],
            endPosition: $payload['end_position'],
            metadata: $this->metadataFromPayload($payload)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function searchByFilename(string $filename, int $limit = 10, array $filters = [], ?string $collection = null): array
    {
        $target = $this->resolveCollection($collection);
        $this->ensureCollectionExists($target);

        // Return early for empty filenames to avoid matching all files.
        if (trim($filename) === '') {
            return [];
        }

        // Build filter for filename matching (uses Qdrant text match:{text}).
        $qdrantFilter = $this->buildFilenameFilter($filename, $filters);

        // PRIMARY: indexed text-match path. Wrapped so a missing/absent payload
        // index (or any Qdrant error) degrades to the scroll fallback below
        // instead of throwing and wiping all file context.
        try {
            if (empty($qdrantFilter)) {
                return [];
            }

            $totalCount = $this->vectorStore->count($target, $qdrantFilter);

            if ($totalCount === 0) {
                return [];
            }

            // Filter-only search via a dummy vector. Request a higher limit to cover
            // multiple chunks per file, capped at 500 to bound memory when totalCount
            // is very large.
            $requestLimit = min(max($limit * 3, 100), $totalCount, 500);
            $results = $this->vectorStore->search(
                $target,
                array_fill(0, $this->vectorSize, 0.0), // Dummy vector for filter-only search
                $requestLimit,
                $qdrantFilter,
                0.0 // No score threshold for filename search
            );

            return $this->buildFilenameResults($results, $filename, $limit);
        } catch (\Throwable $e) {
            // FALLBACK: scroll the collection (optionally narrowed by file_id) and
            // perform a case-insensitive substring match on file_name in PHP. This
            // works even when the file_name full-text payload index is missing.
            return $this->searchByFilenameFallback($filename, $limit, $filters, $target);
        }
    }

    /**
     * Filename search fallback using scroll + PHP substring matching.
     *
     * Used when the indexed text-match path is unavailable (e.g. the file_name
     * payload index does not exist). Pulls points via scroll and filters by a
     * case-insensitive substring match on file_name. Never throws — returns an
     * empty array if even the scroll fails.
     *
     * @param string $filename
     * @param int $limit
     * @param array $filters
     * @param string $collection Target collection to scroll
     * @return array
     */
    private function searchByFilenameFallback(string $filename, int $limit, array $filters, string $collection): array
    {
        // Only constrain by file_id in the scroll filter (file_name can't be
        // reliably filtered server-side without the index — that's the whole point).
        $scrollFilter = [];
        if (isset($filters['file_id'])) {
            $scrollFilter = [
                'must' => [
                    ['key' => 'file_id', 'match' => ['value' => $filters['file_id']]],
                ],
            ];
        }

        try {
            // Pull a bounded page of points; cap to protect memory.
            $scrollLimit = min(max($limit * 20, 200), 1000);
            $points = $this->vectorStore->scroll($collection, $scrollFilter, $scrollLimit);
        } catch (\Throwable $e) {
            return [];
        }

        $needle = strtolower(trim($filename));

        // Keep only points whose file_name contains the search term.
        $matching = [];
        foreach ($points as $point) {
            $storedFilename = $point['payload']['file_name'] ?? null;
            if ($storedFilename === null) {
                continue;
            }

            if (str_contains(strtolower($storedFilename), $needle) && $this->audienceAllows($filters, (array) ($point['payload'] ?? []))) {
                $matching[] = $point;
            }
        }

        return $this->buildFilenameResults($matching, $filename, $limit);
    }

    /**
     * Build deduplicated filename-search results from raw vector-store points.
     *
     * Groups by file_id (one entry per file), scores by match quality
     * (exact = 1.0, partial = 0.85), enforces the limit, and sorts exact
     * matches first. Shared by the indexed and fallback filename paths.
     *
     * @param array $results Raw points (each with a 'payload')
     * @param string $filename Original search term
     * @param int $limit Max unique files to return
     * @return array
     */
    private function buildFilenameResults(array $results, string $filename, int $limit): array
    {
        $seenFiles = [];
        $output = [];

        foreach ($results as $result) {
            $payload = $result['payload'] ?? [];
            $fileId = $payload['file_id'] ?? null;

            if ($fileId === null || isset($seenFiles[$fileId])) {
                continue;
            }

            $seenFiles[$fileId] = true;

            $chunk = new FileChunk(
                fileId: $payload['file_id'],
                fileName: $payload['file_name'],
                content: $payload['content'],
                embedding: [],
                chunkIndex: $payload['chunk_index'],
                totalChunks: $payload['total_chunks'],
                startPosition: $payload['start_position'],
                endPosition: $payload['end_position'],
                metadata: $this->metadataFromPayload($payload)
            );

            // Score based on match quality:
            // - Exact match (case-insensitive): 1.0
            // - Partial match (filename contains search term): 0.85
            $storedFilename = $payload['file_name'];
            $isExactMatch = strcasecmp($storedFilename, $filename) === 0;

            $output[] = [
                'chunk' => $chunk,
                'score' => $isExactMatch ? 1.0 : 0.85,
                'match_type' => $isExactMatch ? 'exact' : 'partial',
            ];

            if (count($output) >= $limit) {
                break;
            }
        }

        usort($output, fn($a, $b) => $b['score'] <=> $a['score']);

        return $output;
    }

    /**
     * Build filter for filename search
     *
     * @param string $filename
     * @param array $filters
     * @return array
     */
    private function buildFilenameFilter(string $filename, array $filters): array
    {
        // Return empty filter for empty filename to prevent matching all files
        if (trim($filename) === '') {
            return [];
        }

        $must = [];

        // Qdrant text match searches within string values (substring match).
        $must[] = [
            'key' => 'file_name',
            'match' => ['text' => $filename],
        ];

        if (isset($filters['file_id'])) {
            $must[] = [
                'key' => 'file_id',
                'match' => ['value' => $filters['file_id']],
            ];
        }

        if (($audience = $this->audienceClause($filters)) !== null) {
            $must[] = $audience;
        }

        return empty($must) ? [] : ['must' => $must];
    }

    /**
     * The asker's readable documents (`audiences`, {@see \Condoedge\Ai\Support\Audiences}):
     * a chunk written for one of them, or for everyone (no audience). Null without the filter.
     */
    private function audienceClause(array $filters): ?array
    {
        if (!isset($filters['audiences']) || !is_array($filters['audiences'])) {
            return null;
        }
        $audiences = array_values(array_filter($filters['audiences'], 'is_string'));
        $common = ['is_empty' => ['key' => 'metadata.audience']];

        return $audiences === []
            ? $common
            : ['should' => [['key' => 'metadata.audience', 'match' => ['any' => $audiences]], $common]];
    }

    /** The same rule as {@see audienceClause()}, on a payload already fetched. */
    private function audienceAllows(array $filters, array $payload): bool
    {
        if (!isset($filters['audiences']) || !is_array($filters['audiences'])) {
            return true;
        }
        $written = array_filter((array) ($payload['metadata']['audience'] ?? []), 'is_string');

        return $written === [] || array_intersect($written, $filters['audiences']) !== [];
    }

    /**
     * {@inheritdoc}
     */
    public function getFileChunks(int|string $fileId, ?string $collection = null): array
    {
        $target = $this->resolveCollection($collection);
        $this->ensureCollectionExists($target);

        $totalCount = $this->vectorStore->count($target, [
            'must' => [
                ['key' => 'file_id', 'match' => ['value' => $fileId]],
            ],
        ]);

        if ($totalCount === 0) {
            return [];
        }

        $results = $this->vectorStore->search(
            $target,
            array_fill(0, $this->vectorSize, 0.0), // Dummy vector for filter-only search
            $totalCount,
            [
                'must' => [
                    ['key' => 'file_id', 'match' => ['value' => $fileId]],
                ],
            ],
            0.0 // No score threshold for fetching all chunks
        );

        $chunks = array_map(function ($result) {
            $payload = $result['payload'] ?? [];

            return new FileChunk(
                fileId: $payload['file_id'],
                fileName: $payload['file_name'],
                content: $payload['content'],
                embedding: [], // Don't include embedding to save memory
                chunkIndex: $payload['chunk_index'],
                totalChunks: $payload['total_chunks'],
                startPosition: $payload['start_position'],
                endPosition: $payload['end_position'],
                metadata: $this->metadataFromPayload($payload)
            );
        }, $results);

        usort($chunks, fn($a, $b) => $a->chunkIndex <=> $b->chunkIndex);

        return $chunks;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteFileChunks(int|string $fileId, ?string $collection = null): bool
    {
        $target = $this->resolveCollection($collection);

        $chunks = $this->getFileChunks($fileId, $target);

        if (empty($chunks)) {
            return true;
        }

        $ids = array_map(fn($chunk) => $chunk->getVectorId(), $chunks);

        return $this->vectorStore->deletePoints($target, $ids);
    }

    /**
     * {@inheritdoc}
     */
    public function getChunkCount(array $filters = []): int
    {
        // A count is an inventory of what is STORED, not a document search, so
        // out-of-scope chunks count unless the caller explicitly asks otherwise
        // (the union keeps a caller-supplied include_out_of_scope).
        $qdrantFilter = $this->buildQdrantFilter($filters + ['include_out_of_scope' => true]);

        return $this->vectorStore->count($this->collection, $qdrantFilter);
    }

    /**
     * {@inheritdoc}
     */
    public function hasFileChunks(int|string $fileId, ?string $collection = null): bool
    {
        $target = $this->resolveCollection($collection);

        // The first ingest into a new collection: it holds nothing yet (storeChunks creates it).
        if (!$this->vectorStore->collectionExists($target)) {
            return false;
        }

        $count = $this->vectorStore->count($target, [
            'file_id' => $fileId
        ]);

        return $count > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getChunk(string $vectorId): ?FileChunk
    {
        $point = $this->vectorStore->getPoint($this->collection, $vectorId);

        if (!$point) {
            return null;
        }

        $payload = $point['payload'] ?? [];

        return new FileChunk(
            fileId: $payload['file_id'],
            fileName: $payload['file_name'],
            content: $payload['content'],
            embedding: $point['vector'] ?? [],
            chunkIndex: $payload['chunk_index'],
            totalChunks: $payload['total_chunks'],
            startPosition: $payload['start_position'],
            endPosition: $payload['end_position'],
            metadata: $this->metadataFromPayload($payload)
        );
    }

    /**
     * Chunk metadata as read from a point payload.
     *
     * The ingest-time `metadata` is returned as stored; when the point also
     * carries a document map (its own top-level payload key, see setChunkMap())
     * it is exposed as `metadata['map']` so consumers reach it through the
     * FileChunk they already hold. A point without a map — or with a value that
     * is not a map — yields exactly the metadata it always did.
     *
     * @param array $payload Point payload
     * @return array
     */
    private function metadataFromPayload(array $payload): array
    {
        $metadata = $payload['metadata'] ?? [];

        if (is_array($payload[self::MAP_PAYLOAD_KEY] ?? null)) {
            $metadata[self::MAP_PAYLOAD_KEY] = $payload[self::MAP_PAYLOAD_KEY];
        }

        // The link of an uploaded file to its record, when the point carries one.
        $link = FileLink::fromPayload($payload);
        if ($link !== null) {
            $metadata[FileLink::METADATA_KEY] = $link;
        }

        return $metadata;
    }

    /**
     * Reject an empty or wrong-dimension embedding before it reaches Qdrant.
     *
     * An empty vector is unsearchable (and may be silently dropped/rejected by
     * Qdrant), and a wrong-dimension vector corrupts the collection. Catching
     * both here prevents a partial/failed embedding batch from silently
     * misaligning chunk -> vector.
     *
     * @param FileChunk $chunk
     * @return void
     * @throws \InvalidArgumentException
     */
    private function assertValidVector(FileChunk $chunk): void
    {
        $dimension = count($chunk->embedding);

        if ($dimension === 0) {
            throw new \InvalidArgumentException(sprintf(
                'Refusing to store an empty embedding for file %s chunk %d.',
                (string) $chunk->fileId,
                $chunk->chunkIndex
            ));
        }

        if ($dimension !== $this->vectorSize) {
            throw new \InvalidArgumentException(sprintf(
                'Embedding dimension mismatch for file %s chunk %d: expected %d, got %d.',
                (string) $chunk->fileId,
                $chunk->chunkIndex,
                $this->vectorSize,
                $dimension
            ));
        }
    }

    /**
     * Resolve the collection to operate on for a given call.
     *
     * A null override resolves to the bound (default) collection, preserving the
     * single-collection behavior. A non-null value routes the call to that
     * collection (e.g. 'documentation_chunks' for physical docs).
     *
     * @param string|null $collection
     * @return string
     */
    private function resolveCollection(?string $collection): string
    {
        $resolved = $collection !== null && trim($collection) !== '' ? $collection : $this->collection;

        return $resolved;
    }

    /**
     * Ensure a collection exists in Qdrant (lazy - only called when needed).
     *
     * Verification is tracked per collection so multiple collections (e.g.
     * file_chunks + documentation_chunks) can be used by the same store.
     *
     * @param string|null $collection Collection to verify (defaults to bound collection)
     * @return void
     */
    private function ensureCollectionExists(?string $collection = null): void
    {
        $target = $this->resolveCollection($collection);

        if (!empty($this->verifiedCollections[$target])) {
            return;
        }

        if (!$this->vectorStore->collectionExists($target)) {
            $this->vectorStore->createCollection(
                $target,
                $this->vectorSize,
                'cosine'
            );
        }

        // Best-effort: ensure the file_name full-text index exists even for
        // collections created before this path (createCollection also does this
        // for fresh collections). Never throws.
        $this->vectorStore->ensurePayloadIndex($target, 'file_name', 'text');

        // Indexes for the structural filters (Phase 7.2 / 7.3) so a selective
        // payload filter on an HNSW-indexed collection keeps full recall. An
        // integer index on file_id still lets the keyword branch of the ACL filter
        // match string ids (verified against Qdrant), so it is safe on any collection.
        $this->vectorStore->ensurePayloadIndex($target, 'file_id', 'integer');
        $this->vectorStore->ensurePayloadIndex($target, 'metadata.articles', 'keyword');

        $this->verifiedCollections[$target] = true;
    }

    /**
     * Resolve the vector size from config or active provider dimensions.
     *
     * @return int
     */
    private function resolveVectorSize(): int
    {
        $explicit = config('ai.embedding.vector_size');
        if ($explicit) {
            return (int) $explicit;
        }

        $provider = config('ai.embedding.default', 'openai');
        return (int) config("ai.embedding.{$provider}.dimensions", self::DEFAULT_VECTOR_SIZE);
    }

    /**
     * Build Qdrant filter from search filters
     *
     * @param array $filters
     * @return array
     */
    private function buildQdrantFilter(array $filters): array
    {
        $must = [];

        if (isset($filters['file_id'])) {
            $must[] = [
                'key' => 'file_id',
                'match' => ['value' => $filters['file_id']],
            ];
        }

        // ACL pre-filter (Phase 7.3): the caller's accessible file ids. Qdrant
        // matches integer and keyword values as distinct types and a MatchAny list
        // must be homogeneous, so the set is matched in both forms (payload ids
        // are ints for model-backed files, but may have been stored as strings).
        if (isset($filters['file_ids']) && is_array($filters['file_ids'])) {
            $ints = [];
            $strings = [];
            foreach ($filters['file_ids'] as $id) {
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $ints[] = (int) $id;
                }
                $strings[] = (string) $id;
            }
            $should = [];
            if ($ints !== []) {
                $should[] = ['key' => 'file_id', 'match' => ['any' => array_values(array_unique($ints))]];
            }
            if ($strings !== []) {
                $should[] = ['key' => 'file_id', 'match' => ['any' => array_values(array_unique($strings))]];
            }
            // An empty set matches nothing (callers skip the search instead).
            $must[] = $should === [] ? ['key' => 'file_id', 'match' => ['any' => ['__none__']]] : ['should' => $should];
        }

        // SCOPE of the question (uploaded files only — the caller never passes it
        // for the project collection): the chunk's `owner` is the focus record or
        // one of the records that own it (a document of the project applies to
        // its tasks), or the focus owns the chunk's owner (a question about the
        // project includes the documents filed on its tasks) — never a sibling's.
        // A chunk of no record (a document of the team as a whole) stays
        // eligible. Beside the ACL above, never instead of it.
        $focus = is_array($filters['scope'] ?? null) ? ($filters['scope']['focus'] ?? null) : null;
        if (is_string($focus) && $focus !== '') {
            $owners = array_values(array_unique(array_merge(
                [$focus],
                array_filter((array) ($filters['scope']['ancestors'] ?? []), static fn ($key): bool => is_string($key) && $key !== '')
            )));
            $must[] = ['should' => [
                ['key' => FileLink::OWNER, 'match' => ['any' => $owners]],
                ['key' => FileLink::ANCESTORS, 'match' => ['value' => $focus]],
                ['is_empty' => ['key' => FileLink::OWNER]],
            ]];
        }

        if (($audience = $this->audienceClause($filters)) !== null) {
            $must[] = $audience;
        }

        // Only these articles (the frontmatter slug, else the file name): a task's grounding.
        if (!empty($filters['slugs']) && is_array($filters['slugs'])) {
            $must[] = ['key' => 'metadata.slug', 'match' => ['any' => array_values(array_map('strval', $filters['slugs']))]];
        }

        // Exact-article angle (Phase 7.2): chunks whose structural metadata
        // lists one of the requested article numbers.
        if (!empty($filters['article_numbers']) && is_array($filters['article_numbers'])) {
            $must[] = [
                'key' => 'metadata.articles',
                'match' => ['any' => array_values(array_map('strval', $filters['article_numbers']))],
            ];
        }

        // Match file_types by extension suffix in the filename.
        if (!empty($filters['file_types'])) {
            $should = [];
            foreach ($filters['file_types'] as $type) {
                $should[] = [
                    'key' => 'file_name',
                    'match' => ['text' => ".$type"],
                ];
            }

            if (!empty($should)) {
                $must[] = ['should' => $should];
            }
        }

        $filter = empty($must) ? [] : ['must' => $must];

        // Document-map scope: leave out chunks mapped as belonging to a part of
        // their document that does not govern the application's users. Qdrant's
        // must_not only rejects points where the condition MATCHES, so a chunk
        // with no `map` key (never mapped) or no `map.scope` still matches —
        // unmapped collections behave exactly as before.
        if ($this->excludesOutOfScope($filters)) {
            $filter['must_not'] = [
                ['key' => self::MAP_PAYLOAD_KEY . '.scope', 'match' => ['value' => 'out']],
            ];
        }

        return $filter;
    }

    /**
     * Whether a search built from these filters must leave out-of-scope chunks out.
     *
     * Only document search goes through buildQdrantFilter(); the by-file lookups
     * (getFileChunks / deleteFileChunks / hasFileChunks) build their own filter
     * and always see every chunk, because windows, deletes and the map builder
     * itself need the whole file.
     *
     * @param array $filters
     * @return bool
     */
    private function excludesOutOfScope(array $filters): bool
    {
        // A user who names an article by number asked for THAT article: the
        // explicit reference outranks the map's judgment about its part.
        if (!empty($filters['article_numbers'])) {
            return false;
        }

        // Explicit opt-in by the caller (e.g. inventory counts, diagnostics).
        if (!empty($filters['include_out_of_scope'])) {
            return false;
        }

        // Feature switch: with the document map off nothing writes `map`, and a
        // stale one left in the collection must not keep hiding chunks.
        return (bool) config('ai.file_context.document_map.enabled', true);
    }
}
