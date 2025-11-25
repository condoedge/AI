<?php

namespace Condoedge\Ai\VectorStore;

use Condoedge\Ai\Contracts\VectorStoreInterface;

/**
 * Qdrant Vector Store Implementation
 *
 * Connects to Qdrant via REST API for vector storage and similarity search.
 * Qdrant documentation: https://qdrant.tech/documentation/
 */
class QdrantStore implements VectorStoreInterface
{
    protected string $host;
    protected int $port;
    protected ?string $apiKey;
    protected int $timeout;
    protected string $baseUrl;

    public function __construct(?array $config = null)
    {
        $config = $config ?? config('ai.qdrant');

        $this->host = $config['host'] ?? 'localhost';
        $this->port = $config['port'] ?? 6333;
        $this->apiKey = $config['api_key'] ?? null;
        $this->timeout = $config['timeout'] ?? 30;

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

            return isset($response['result']) && $response['result'] === true;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to create collection '{$name}': " . $e->getMessage());
        }
    }

    public function collectionExists(string $name): bool
    {
        try {
            $response = $this->request('GET', '/collections');
            $collections = $response['result']['collections'] ?? [];

            foreach ($collections as $collection) {
                if ($collection['name'] === $name) {
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

            $response = $this->request('PUT', "/collections/{$collection}/points", [
                'points' => $points
            ]);

            // Qdrant returns 'acknowledged' for async operations, 'completed' for sync
            $status = $response['result']['status'] ?? $response['status'] ?? null;
            return in_array($status, ['acknowledged', 'completed', 'ok']);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to upsert points in '{$collection}': " . $e->getMessage());
        }
    }

    public function listCollections()
    {
        try {
            $response = $this->request('GET', '/collections');
            $collections = $response['result']['collections'] ?? [];

            return array_map(fn($col) => $col['name'], $collections);
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
            $response = $this->request('POST', "/collections/{$collection}/points/delete", [
                'points' => $ids
            ]);

            // Qdrant returns 'acknowledged' for async operations, 'completed' for sync
            $status = $response['result']['status'] ?? $response['status'] ?? null;
            return in_array($status, ['acknowledged', 'completed', 'ok']);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to delete points in '{$collection}': " . $e->getMessage());
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
     * Build Qdrant filter from simple key-value array
     */
    protected function buildFilter(array $filter): array
    {
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
        $url = $this->baseUrl . $endpoint;

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
            // Always send JSON body for POST/PUT/PATCH, even if empty
            // Convert empty arrays to objects so they encode as {} not []
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
        // If it's already an object, return as-is
        if (is_object($data)) {
            return $data;
        }

        if (is_array($data)) {
            // Special case: empty array in 'payload' field should become {}
            if (empty($data) && $parentKey === 'payload') {
                return new \stdClass();
            }

            // Recursively process array elements
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
}
