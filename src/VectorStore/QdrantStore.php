<?php

namespace Condoedge\Ai\VectorStore;

use Condoedge\Ai\Contracts\GroupedSearchInterface;
use Condoedge\Ai\Contracts\UpdatesPayloadInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;

/**
 * Qdrant Vector Store Implementation
 *
 * Connects to Qdrant via REST API for vector storage and similarity search.
 * Qdrant documentation: https://qdrant.tech/documentation/
 */
class QdrantStore implements VectorStoreInterface, UpdatesPayloadInterface, GroupedSearchInterface
{
    protected string $host;
    protected int $port;
    protected ?string $apiKey;
    protected int $timeout;
    protected string $baseUrl;

    /** Every collection this host names lives under it (two hosts on one Qdrant); '' = none. */
    protected string $collectionPrefix;

    public function __construct(?array $config = null)
    {
        $config = $config ?? config('ai.vector.qdrant');

        $this->host = $config['host'] ?? 'localhost';
        $this->port = $config['port'] ?? 6333;
        $this->apiKey = $config['api_key'] ?? null;
        $this->timeout = $config['timeout'] ?? 30;
        $this->collectionPrefix = (string) ($config['collection_prefix'] ?? '');

        $this->baseUrl = "http://{$this->host}:{$this->port}";
    }

    public function createCollection(string $name, int $vectorSize, string $distance = 'cosine'): bool
    {
        try {
            $response = $this->request('PUT', "/collections/{$name}", [
                'vectors' => [
                    'size' => $vectorSize,
                    'distance' => ucfirst($distance), // Cosine, Euclid, Dot
                ]
            ]);

            $created = isset($response['result']) && $response['result'] === true;

            // Create a full-text payload index on file_name so filename/substring
            // matches (match:{text}) are efficient and reliable. Best-effort: a
            // failure here must never abort collection creation.
            if ($created) {
                $this->ensurePayloadIndex($name, 'file_name', 'text');
            }

            return $created;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to create collection '{$name}': " . $e->getMessage());
        }
    }

    /**
     * Ensure a payload field index exists on a collection.
     *
     * Creates a Qdrant payload index (PUT /collections/{c}/index) for the given
     * field. A full-text ('text') schema enables match:{text} substring search on
     * string payloads such as file_name. Best-effort: any failure is swallowed so
     * callers never break when the index already exists or cannot be created.
     *
     * @param string $collection Collection name
     * @param string $field Payload field to index
     * @param string $schema Field schema: 'text', 'keyword', 'integer', etc.
     * @return bool True if the index was created/confirmed, false on failure
     */
    public function ensurePayloadIndex(string $collection, string $field, string $schema = 'keyword'): bool
    {
        try {
            $this->request('PUT', "/collections/{$collection}/index", [
                'field_name' => $field,
                'field_schema' => $schema,
            ]);

            return true;
        } catch (\Exception $e) {
            // Index creation is best-effort. The collection is still usable for
            // exact-value filters and (degraded) scroll-based filename search.
            return false;
        }
    }

    /**
     * Scroll (page) through points in a collection, optionally filtered.
     *
     * Unlike search(), scroll does not require a query vector — it iterates over
     * stored points by payload filter. Used as a fallback for filename search when
     * a full-text payload index is unavailable.
     *
     * @param string $collection Collection name
     * @param array $filter Optional payload filter (simple or pre-built Qdrant format)
     * @param int $limit Maximum number of points to return
     * @param bool $withPayload Whether to include payloads
     * @return array Array of points (each with 'id' and 'payload')
     */
    public function scroll(
        string $collection,
        array $filter = [],
        int $limit = 100,
        bool $withPayload = true
    ): array {
        try {
            $payload = [
                'limit' => $limit,
                'with_payload' => $withPayload,
                'with_vector' => false,
            ];

            if (!empty($filter)) {
                $payload['filter'] = $this->buildFilter($filter);
            }

            $response = $this->request('POST', "/collections/{$collection}/points/scroll", $payload);

            return $response['result']['points'] ?? [];
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to scroll points in collection '{$collection}': " . $e->getMessage());
        }
    }

    public function collectionExists(string $name): bool
    {
        try {
            $response = $this->request('GET', '/collections');
            $collections = $response['result']['collections'] ?? [];

            foreach ($collections as $collection) {
                if ($collection['name'] === $this->collectionPrefix . $name) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function deleteCollection(string $name): bool
    {
        try {
            $response = $this->request('DELETE', "/collections/{$name}");
            return isset($response['result']) && $response['result'] === true;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to delete collection '{$name}': " . $e->getMessage());
        }
    }

    public function upsert(string $collection, array $points): bool
    {
        try {
            if (!$this->collectionExists($collection)) {
                $this->createCollection($collection, count($points[0]['vector']));
            }

            // wait=true makes the upsert synchronous so points are immediately
            // searchable/readable. Without it Qdrant returns 'acknowledged' and
            // applies the write asynchronously, causing read-after-write misses
            // (empty search/count results) for ingestion that verifies right away.
            $response = $this->request('PUT', "/collections/{$collection}/points?wait=true", [
                'points' => $points
            ]);

            // Qdrant returns 'acknowledged' for async operations, 'completed' for sync
            $status = $response['result']['status'] ?? $response['status'] ?? null;
            return in_array($status, ['acknowledged', 'completed', 'ok']);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to upsert points in '{$collection}': " . $e->getMessage());
        }
    }

    public function listCollections(): array
    {
        try {
            $response = $this->request('GET', '/collections');
            $names = array_map(fn($col) => (string) $col['name'], $response['result']['collections'] ?? []);
            if ($this->collectionPrefix === '') {
                return $names;
            }

            // Only this host's collections, by the names it gives them.
            return array_values(array_map(
                fn (string $name) => substr($name, strlen($this->collectionPrefix)),
                array_filter($names, fn (string $name) => str_starts_with($name, $this->collectionPrefix))
            ));
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to list collections: " . $e->getMessage());
        }
    }

    public function search(
        string $collection,
        array $vector,
        int $limit = 10,
        array $filter = [],
        float $scoreThreshold = 0.0
    ): array {
        try {
            $payload = [
                'vector' => $vector,
                'limit' => $limit,
                'with_payload' => true,
                'with_vector' => false,
            ];

            if ($scoreThreshold > 0) {
                $payload['score_threshold'] = $scoreThreshold;
            }

            if (!empty($filter)) {
                $payload['filter'] = $this->buildFilter($filter);
            }

            $response = $this->request('POST', "/collections/{$collection}/points/search", $payload);

            return $response['result'] ?? [];
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to search in collection '{$collection}': " . $e->getMessage());
        }
    }

    public function searchGroups(
        string $collection,
        array $vector,
        string $groupBy,
        int $limit = 10,
        array $filter = [],
        float $scoreThreshold = 0.0,
        int $groupSize = 1
    ): array {
        try {
            $payload = [
                'vector' => $vector,
                'group_by' => $groupBy,
                'limit' => $limit,
                'group_size' => max(1, $groupSize),
                'with_payload' => true,
                'with_vector' => false,
            ];

            if ($scoreThreshold > 0) {
                $payload['score_threshold'] = $scoreThreshold;
            }

            if (!empty($filter)) {
                $payload['filter'] = $this->buildFilter($filter);
            }

            $response = $this->request('POST', "/collections/{$collection}/points/search/groups", $payload);

            $groups = [];
            foreach ((array) ($response['result']['groups'] ?? []) as $group) {
                if (is_array($group) && isset($group['id'])) {
                    $groups[] = ['key' => $group['id'], 'hits' => array_values((array) ($group['hits'] ?? []))];
                }
            }

            return $groups;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to search groups in collection '{$collection}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function scrollPage(
        string $collection,
        array $filter = [],
        int $limit = 100,
        bool|array $withPayload = true,
        mixed $offset = null
    ): array {
        try {
            $payload = [
                'limit' => $limit,
                'with_payload' => $withPayload,
                'with_vector' => false,
            ];

            if (!empty($filter)) {
                $payload['filter'] = $this->buildFilter($filter);
            }
            if ($offset !== null) {
                $payload['offset'] = $offset;
            }

            $response = $this->request('POST', "/collections/{$collection}/points/scroll", $payload);

            return [
                'points' => array_values((array) ($response['result']['points'] ?? [])),
                'next' => $response['result']['next_page_offset'] ?? null,
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to scroll points in collection '{$collection}': " . $e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getPoint(string $collection, string|int $id): ?array
    {
        try {
            $response = $this->request('GET', "/collections/{$collection}/points/{$id}");
            return $response['result'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function deletePoints(string $collection, array $ids): bool
    {
        try {
            // wait=true makes the delete synchronous so removed points are gone
            // for any immediately-following read (mirrors the upsert behavior).
            $response = $this->request('POST', "/collections/{$collection}/points/delete?wait=true", [
                'points' => $ids
            ]);

            // Qdrant returns 'acknowledged' for async operations, 'completed' for sync
            $status = $response['result']['status'] ?? $response['status'] ?? null;
            return in_array($status, ['acknowledged', 'completed', 'ok']);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to delete points in '{$collection}': " . $e->getMessage());
        }
    }

    /**
     * Merge payload keys into existing points (Qdrant "set payload").
     *
     * POST /points/payload only writes the given top-level keys: the points'
     * vectors and their other payload keys are left as they are, which is what
     * lets data be attached after ingest without re-embedding. (PUT on the same
     * endpoint would REPLACE the whole payload — never use it here.)
     *
     * @param string $collection Collection name
     * @param array $payload Top-level payload keys to set
     * @param array $pointIds Ids of the points to update
     * @return bool True when Qdrant confirmed the write
     */
    public function setPayload(string $collection, array $payload, array $pointIds): bool
    {
        // No points selected means nothing to write: succeed without a
        // round-trip so batch callers need no empty-batch guard of their own.
        if ($pointIds === []) {
            return true;
        }

        try {
            // wait=true makes the write synchronous so a read that follows
            // immediately sees the new keys (mirrors the upsert behavior).
            // array_values: a keyed id array would encode as a JSON object,
            // which Qdrant does not accept for "points".
            $response = $this->request('POST', "/collections/{$collection}/points/payload?wait=true", [
                'payload' => $payload,
                'points' => array_values($pointIds),
            ]);

            // Qdrant returns 'acknowledged' for async operations, 'completed' for sync
            $status = $response['result']['status'] ?? $response['status'] ?? null;
            return in_array($status, ['acknowledged', 'completed', 'ok']);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to set payload in '{$collection}': " . $e->getMessage());
        }
    }

    public function getCollectionInfo(string $name): array
    {
        try {
            $response = $this->request('GET', "/collections/{$name}");
            return $response['result'] ?? [];
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to get collection info for '{$name}': " . $e->getMessage());
        }
    }

    public function count(string $collection, array $filter = []): int
    {
        try {
            // Send empty object if no filter, otherwise send filter
            $payload = !empty($filter) ? ['filter' => $this->buildFilter($filter)] : new \stdClass();

            $response = $this->request('POST', "/collections/{$collection}/points/count", $payload);

            return $response['result']['count'] ?? 0;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to count points in '{$collection}': " . $e->getMessage());
        }
    }

    /**
     * Build Qdrant filter from simple key-value array or return pre-built filter
     *
     * Accepts two formats:
     * 1. Simple key-value: ['file_id' => 123] → converts to Qdrant format
     * 2. Pre-built Qdrant format: ['must' => [...]] → returns as-is
     *
     * Pre-built filters are detected by presence of 'must', 'should', or 'must_not' keys.
     */
    protected function buildFilter(array $filter): array
    {
        if (isset($filter['must']) || isset($filter['should']) || isset($filter['must_not'])) {
            return $filter;
        }

        $must = [];

        foreach ($filter as $key => $value) {
            $must[] = [
                'key' => $key,
                'match' => ['value' => $value]
            ];
        }

        return ['must' => $must];
    }

    /**
     * Make HTTP request to Qdrant API
     */
    protected function request(string $method, string $endpoint, array|object $data = []): array
    {
        if ($this->collectionPrefix !== '' && str_starts_with($endpoint, '/collections/')) {
            $endpoint = '/collections/' . $this->collectionPrefix . substr($endpoint, strlen('/collections/'));
        }

        return $this->send($method, $this->baseUrl . $endpoint, $data);
    }

    protected function send(string $method, string $url, array|object $data = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = [
            'Content-Type: application/json',
        ];

        if ($this->apiKey) {
            $headers[] = 'api-key: ' . $this->apiKey;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            // Convert empty arrays to objects so they encode as {} not [].
            $cleanedData = $this->prepareJsonData($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cleanedData, JSON_PRESERVE_ZERO_FRACTION));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("Qdrant HTTP request failed: {$error}");
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("Qdrant returned error {$httpCode}: {$response}");
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to decode Qdrant response: " . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Prepare data for JSON encoding
     * Converts empty arrays in 'payload' fields to stdClass so they encode as {} instead of []
     */
    protected function prepareJsonData($data, string $parentKey = '')
    {
        if (is_object($data)) {
            return $data;
        }

        if (is_array($data)) {
            // An empty 'payload' must encode as {} rather than [].
            if (empty($data) && $parentKey === 'payload') {
                return new \stdClass();
            }

            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->prepareJsonData($value, (string)$key);
            }

            return $result;
        }

        return $data;
    }

    /**
     * Test connection to Qdrant
     */
    public function testConnection(): bool
    {
        try {
            $this->request('GET', '/');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Ensure a collection exists, create if it doesn't
     */
    public function ensureCollection(string $name, int $vectorSize, string $distance = 'cosine'): void
    {
        if (!$this->collectionExists($name)) {
            $this->createCollection($name, $vectorSize, $distance);
        }
    }

    /**
     * Delete all points in a collection (but keep the collection structure)
     */
    public function deleteAll(string $collection): bool
    {
        try {
            // Get collection info first to preserve settings
            $info = $this->getCollectionInfo($collection);
            $vectorSize = $info['config']['params']['vectors']['size'] ?? 1536;
            $distance = $info['config']['params']['vectors']['distance'] ?? 'Cosine';

            // Delete and recreate the collection (fastest way to clear all points)
            $this->deleteCollection($collection);
            $this->createCollection($collection, $vectorSize, strtolower($distance));

            return true;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to delete all points in '{$collection}': " . $e->getMessage());
        }
    }

    /**
     * Batch upsert points (optimized for large batches)
     */
    public function upsertBatch(string $collection, array $points): bool
    {
        $qdrantPoints = array_map(function ($point) {
            return [
                'id' => $point['id'],
                'vector' => $point['vector'],
                'payload' => $point['metadata'] ?? $point['payload'] ?? [],
            ];
        }, $points);

        return $this->upsert($collection, $qdrantPoints);
    }
}
