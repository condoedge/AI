<?php

namespace Condoedge\Ai\GraphStore;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Exceptions\CypherInjectionException;
use Condoedge\Ai\Services\Resilience\RetryPolicy;
use Condoedge\Ai\Services\Resilience\CircuitBreaker;
use Condoedge\Ai\Services\Security\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Log;

/**
 * Neo4j Graph Store Implementation (HTTP API)
 *
 * Connects to Neo4j via HTTP API for graph operations with retry logic and circuit breaker.
 * Use this for http:// or https:// URIs. For bolt:// URIs, use BoltNeo4jStore instead.
 *
 * @see BoltNeo4jStore For Bolt protocol support (faster, recommended for production)
 * @see Neo4jStoreFactory For automatic driver selection based on URI scheme
 */
class Neo4jStore implements GraphStoreInterface
{
    use Neo4jCredentialsTrait;

    protected string $uri;
    protected string $username;
    protected string $database;
    protected string $httpEndpoint;
    protected RetryPolicy $retryPolicy;
    protected CircuitBreaker $circuitBreaker;

    /**
     * Reusable cURL handle for connection pooling
     */
    private ?\CurlHandle $curlHandle = null;

    public function __construct(?array $config = null)
    {
        $config = $config ?? config('ai.graph.neo4j');

        $this->uri = $config['uri'] ?? 'http://localhost:7474';
        $this->username = $config['username'] ?? 'neo4j';
        $this->database = $config['database'] ?? 'neo4j';

        // Initialize credentials using trait
        $this->initializeCredentials($config);

        // Parse URI for HTTP endpoint
        $parsedUri = parse_url($this->uri);
        $scheme = $parsedUri['scheme'] ?? 'http';
        $host = $parsedUri['host'] ?? 'localhost';
        $port = $parsedUri['port'] ?? ($scheme === 'https' ? 7473 : 7474);

        $this->httpEndpoint = "{$scheme}://{$host}:{$port}/db/{$this->database}/tx/commit";

        // Initialize retry policy and circuit breaker
        $this->retryPolicy = RetryPolicy::forDatabaseOperations();
        $this->circuitBreaker = new CircuitBreaker('neo4j', failureThreshold: 5, recoveryTimeoutSeconds: 30);
    }

    /**
     * Clean up cURL handle on object destruction
     */
    public function __destruct()
    {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
            $this->curlHandle = null;
        }
    }

    public function createNode(string $label, array $properties): string|int
    {
        // Validate label to prevent injection
        $safeLabel = CypherSanitizer::escapeLabel($label);

        // Use parameter binding instead of string interpolation for security
        $cypher = "CREATE (n:{$safeLabel}) SET n = \$properties RETURN id(n) as nodeId, n.id as appId";

        $result = $this->query($cypher, ['properties' => $properties]);

        if (empty($result)) {
            throw new \RuntimeException("Failed to create node with label '{$label}'");
        }

        // Return the application ID (from properties) if it exists, otherwise Neo4j internal ID
        return $result[0]['appId'] ?? $result[0]['nodeId'];
    }

    public function updateNode(string $label, string|int $id, array $properties): bool
    {
        // Validate label to prevent injection
        $safeLabel = CypherSanitizer::escapeLabel($label);

        $setClause = $this->arrayToSetClause($properties);

        $cypher = "MATCH (n:{$safeLabel} {id: \$id}) SET {$setClause} RETURN n";

        $params = array_merge(['id' => $id], $properties);
        $result = $this->query($cypher, $params);

        return !empty($result);
    }

    public function deleteNode(string $label, string|int $id): bool
    {
        // Validate label to prevent injection
        $safeLabel = CypherSanitizer::escapeLabel($label);

        $cypher = "MATCH (n:{$safeLabel} {id: \$id}) DETACH DELETE n";

        $this->query($cypher, ['id' => $id]);

        return true;
    }

    public function createRelationship(
        string $fromLabel,
        string|int $fromId,
        string $toLabel,
        string|int $toId,
        string $type,
        array $properties = []
    ): bool {
        // Validate labels and type to prevent injection
        $safeFromLabel = CypherSanitizer::escapeLabel($fromLabel);
        $safeToLabel = CypherSanitizer::escapeLabel($toLabel);
        $safeType = CypherSanitizer::escapeRelationshipType($type);

        // Use parameter binding instead of string interpolation for security
        $cypher = "
            MATCH (from:{$safeFromLabel} {id: \$fromId})
            MATCH (to:{$safeToLabel} {id: \$toId})
            MERGE (from)-[r:{$safeType}]->(to)
            SET r = \$properties
            RETURN r
        ";

        $result = $this->query($cypher, [
            'fromId' => $fromId,
            'toId' => $toId,
            'properties' => $properties,
        ]);

        return !empty($result);
    }

    public function deleteRelationship(
        string $fromLabel,
        string|int $fromId,
        string $toLabel,
        string|int $toId,
        string $type
    ): bool {
        // Validate labels and type to prevent injection
        $safeFromLabel = CypherSanitizer::escapeLabel($fromLabel);
        $safeToLabel = CypherSanitizer::escapeLabel($toLabel);
        $safeType = CypherSanitizer::escapeRelationshipType($type);

        $cypher = "
            MATCH (from:{$safeFromLabel} {id: \$fromId})-[r:{$safeType}]->(to:{$safeToLabel} {id: \$toId})
            DELETE r
        ";

        $this->query($cypher, [
            'fromId' => $fromId,
            'toId' => $toId,
        ]);

        return true;
    }

    public function query(string $cypher, array $parameters = []): array
    {
        try {
            $response = $this->executeCypher($cypher, $parameters);

            if (!empty($response['errors'])) {
                $error = $response['errors'][0];
                throw new \RuntimeException("Neo4j query error: {$error['message']}");
            }

            // Extract results from Neo4j response format
            return $this->extractResults($response);

        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to execute Neo4j query: " . $e->getMessage());
        }
    }

    public function getSchema(): array
    {
        $schema = [
            'labels' => [],
            'relationshipTypes' => [],
            'propertyKeys' => [],
        ];

        // Get all node labels
        $labels = $this->query("CALL db.labels()");
        $schema['labels'] = array_column($labels, 'label');

        // Get all relationship types
        $relTypes = $this->query("CALL db.relationshipTypes()");
        $schema['relationshipTypes'] = array_column($relTypes, 'relationshipType');

        // Get all property keys
        $propKeys = $this->query("CALL db.propertyKeys()");
        $schema['propertyKeys'] = array_column($propKeys, 'propertyKey');

        return $schema;
    }

    public function nodeExists(string $label, string|int $id): bool
    {
        // Validate label to prevent injection
        $safeLabel = CypherSanitizer::escapeLabel($label);

        $cypher = "MATCH (n:{$safeLabel} {id: \$id}) RETURN count(n) as count";
        $result = $this->query($cypher, ['id' => $id]);

        return !empty($result) && $result[0]['count'] > 0;
    }

    public function relationshipExists(
        string $fromLabel,
        string|int $fromId,
        string $toLabel,
        string|int $toId,
        string $type
    ): bool {
        // Validate labels and type to prevent injection
        $safeFromLabel = CypherSanitizer::escapeLabel($fromLabel);
        $safeToLabel = CypherSanitizer::escapeLabel($toLabel);
        $safeType = CypherSanitizer::escapeRelationshipType($type);

        $cypher = "MATCH (from:{$safeFromLabel} {id: \$fromId})-[r:{$safeType}]->(to:{$safeToLabel} {id: \$toId}) " .
                  "RETURN count(r) as count";

        $result = $this->query($cypher, [
            'fromId' => $fromId,
            'toId' => $toId,
        ]);

        return !empty($result) && $result[0]['count'] > 0;
    }

    public function getNode(string $label, string|int $id): ?array
    {
        // Validate label to prevent injection
        $safeLabel = CypherSanitizer::escapeLabel($label);

        $cypher = "MATCH (n:{$safeLabel} {id: \$id}) RETURN n";
        $result = $this->query($cypher, ['id' => $id]);

        if (empty($result)) {
            return null;
        }

        return $result[0]['n'] ?? null;
    }

    /**
     * Begin a new transaction
     *
     * @return array Transaction context with 'id', 'endpoint', and 'commit_endpoint'
     * @throws \RuntimeException If transaction cannot be started
     */
    public function beginTransaction(): array
    {
        $txEndpoint = str_replace('/tx/commit', '/tx', $this->httpEndpoint);

        $ch = curl_init($txEndpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['statements' => []]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->getPassword()}");
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("Failed to begin Neo4j transaction: {$error}");
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("Failed to begin Neo4j transaction: HTTP {$httpCode}");
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to decode Neo4j transaction response: " . json_last_error_msg());
        }

        // Extract transaction ID from commit URL
        // Format: http://localhost:7474/db/neo4j/tx/123/commit
        $commitUrl = $data['commit'] ?? '';
        preg_match('/\/tx\/(\d+)\/commit/', $commitUrl, $matches);
        $txId = $matches[1] ?? null;

        if (!$txId) {
            throw new \RuntimeException('Failed to parse transaction ID from Neo4j response');
        }

        $baseEndpoint = str_replace('/tx/commit', '', $this->httpEndpoint);

        return [
            'id' => $txId,
            'endpoint' => "{$baseEndpoint}/tx/{$txId}",
            'commit_endpoint' => $commitUrl,
        ];
    }

    /**
     * Commit a transaction
     *
     * @param array $transaction Transaction context from beginTransaction()
     * @return bool Success status
     */
    public function commit($transaction): bool
    {
        // Legacy behavior for non-transaction calls
        if (!is_array($transaction) || empty($transaction['commit_endpoint'])) {
            return true;
        }

        $ch = curl_init($transaction['commit_endpoint']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['statements' => []]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->getPassword()}");
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Log::error('Failed to commit Neo4j transaction', [
                'error' => $error,
                'transaction_id' => $transaction['id'] ?? 'unknown',
            ]);
            return false;
        }

        if ($httpCode >= 400) {
            Log::error('Neo4j transaction commit failed', [
                'http_code' => $httpCode,
                'transaction_id' => $transaction['id'] ?? 'unknown',
                'response' => $response,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Rollback a transaction
     *
     * @param array $transaction Transaction context from beginTransaction()
     * @return bool Success status
     */
    public function rollback($transaction): bool
    {
        // Legacy behavior for non-transaction calls
        if (!is_array($transaction) || empty($transaction['endpoint'])) {
            return true;
        }

        $ch = curl_init($transaction['endpoint']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->getPassword()}");
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Log::error('Failed to rollback Neo4j transaction', [
                'error' => $error,
                'transaction_id' => $transaction['id'] ?? 'unknown',
            ]);
            return false;
        }

        if ($httpCode >= 400) {
            Log::error('Neo4j transaction rollback failed', [
                'http_code' => $httpCode,
                'transaction_id' => $transaction['id'] ?? 'unknown',
                'response' => $response,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Execute a query within a transaction
     *
     * @param array $transaction Transaction context from beginTransaction()
     * @param string $cypher Cypher query
     * @param array $parameters Query parameters
     * @return array Query results
     * @throws \RuntimeException If query fails
     */
    public function queryInTransaction(array $transaction, string $cypher, array $parameters = []): array
    {
        if (empty($transaction['endpoint'])) {
            throw new \RuntimeException('Invalid transaction context: missing endpoint');
        }

        $response = $this->performHttpRequestToEndpoint(
            $transaction['endpoint'],
            $cypher,
            $parameters
        );

        if (!empty($response['errors'])) {
            $error = $response['errors'][0];
            throw new \RuntimeException("Neo4j query error in transaction: {$error['message']}");
        }

        return $this->extractResults($response);
    }

    /**
     * Execute Cypher query via Neo4j HTTP API with retry and circuit breaker
     */
    protected function executeCypher(string $cypher, array $parameters = []): array
    {
        // Wrap in circuit breaker to prevent cascading failures
        return $this->circuitBreaker->call(function () use ($cypher, $parameters) {
            // Wrap in retry policy for transient failures
            return $this->retryPolicy->execute(
                operation: function () use ($cypher, $parameters) {
                    return $this->performHttpRequest($cypher, $parameters);
                },
                onRetry: function (\Exception $e, int $attempt, int $delay) use ($cypher) {
                    Log::warning('Neo4j request failed, retrying', SensitiveDataSanitizer::forLogging([
                        'attempt' => $attempt,
                        'delay_ms' => $delay,
                        'error' => $e->getMessage(),
                        'cypher' => substr($cypher, 0, 100), // Log first 100 chars
                    ]));
                }
            );
        });
    }

    /**
     * Get a reusable cURL handle for connection pooling
     *
     * @param string|null $endpoint Optional endpoint override
     * @return \CurlHandle
     */
    private function getCurlHandle(?string $endpoint = null): \CurlHandle
    {
        $targetEndpoint = $endpoint ?? $this->httpEndpoint;

        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init();
        }

        // Set/update the URL for this request
        curl_setopt($this->curlHandle, CURLOPT_URL, $targetEndpoint);

        // Configure for connection reuse
        curl_setopt($this->curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->curlHandle, CURLOPT_POST, true);
        curl_setopt($this->curlHandle, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Connection: keep-alive', // Enable HTTP keep-alive
        ]);
        curl_setopt($this->curlHandle, CURLOPT_USERPWD, "{$this->username}:{$this->getPassword()}");
        curl_setopt($this->curlHandle, CURLOPT_TIMEOUT, 30);
        curl_setopt($this->curlHandle, CURLOPT_CONNECTTIMEOUT, 5);

        // Enable TCP keep-alive
        curl_setopt($this->curlHandle, CURLOPT_TCP_KEEPALIVE, 1);
        curl_setopt($this->curlHandle, CURLOPT_TCP_KEEPIDLE, 60);
        curl_setopt($this->curlHandle, CURLOPT_TCP_KEEPINTVL, 30);

        return $this->curlHandle;
    }

    /**
     * Reset the cURL handle (useful after errors)
     */
    private function resetCurlHandle(): void
    {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
            $this->curlHandle = null;
        }
    }

    /**
     * Perform the actual HTTP request to Neo4j
     */
    private function performHttpRequest(string $cypher, array $parameters): array
    {
        $payload = [
            'statements' => [
                [
                    'statement' => $cypher,
                    'parameters' => (object) $parameters, // Force object instead of array
                ]
            ]
        ];

        $jsonPayload = json_encode($payload);

        $ch = $this->getCurlHandle();
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        // DON'T close the handle - reuse it for connection pooling

        if ($error) {
            // Reset handle on error for next request
            $this->resetCurlHandle();
            throw new \RuntimeException("Neo4j HTTP request failed: {$error}");
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("Neo4j returned error {$httpCode}: {$response}");
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to decode Neo4j response: " . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Perform HTTP request to a specific endpoint (used for transaction queries)
     *
     * @param string $endpoint The endpoint URL to send the request to
     * @param string $cypher Cypher query
     * @param array $parameters Query parameters
     * @return array Decoded response
     * @throws \RuntimeException If request fails
     */
    private function performHttpRequestToEndpoint(string $endpoint, string $cypher, array $parameters): array
    {
        $payload = [
            'statements' => [
                [
                    'statement' => $cypher,
                    'parameters' => (object) $parameters, // Force object instead of array
                ]
            ]
        ];

        $jsonPayload = json_encode($payload);

        $ch = $this->getCurlHandle($endpoint);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        // DON'T close the handle - reuse it for connection pooling

        if ($error) {
            // Reset handle on error for next request
            $this->resetCurlHandle();
            throw new \RuntimeException("Neo4j HTTP request failed: {$error}");
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("Neo4j returned error {$httpCode}: {$response}");
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to decode Neo4j response: " . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Extract results from Neo4j response format
     */
    protected function extractResults(array $response): array
    {
        $results = [];

        if (empty($response['results'])) {
            return $results;
        }

        $result = $response['results'][0];

        foreach ($result['data'] ?? [] as $row) {
            $rowData = [];

            foreach ($result['columns'] as $index => $column) {
                $value = $row['row'][$index] ?? null;
                $rowData[$column] = $value;
            }

            $results[] = $rowData;
        }

        return $results;
    }

    /**
     * Convert array to Cypher properties syntax
     *
     * @deprecated Use parameter binding with SET n = $properties instead.
     *             This method uses string interpolation which poses injection risks.
     * @param array $properties The properties to convert
     * @return string The Cypher properties syntax
     */
    protected function arrayToCypherProps(array $properties): string
    {
        @trigger_error(
            'arrayToCypherProps() is deprecated. Use parameter binding with SET n = $properties instead.',
            E_USER_DEPRECATED
        );

        $parts = [];
        foreach (array_keys($properties) as $key) {
            // Validate property key to prevent injection
            $safeKey = CypherSanitizer::validatePropertyKey($key);
            $parts[] = "{$safeKey}: \${$key}";
        }
        return '{' . implode(', ', $parts) . '}';
    }

    /**
     * Convert array to SET clause
     */
    protected function arrayToSetClause(array $properties): string
    {
        $parts = [];
        foreach (array_keys($properties) as $key) {
            // Validate property key to prevent injection
            $safeKey = CypherSanitizer::validatePropertyKey($key);
            $parts[] = "n.{$safeKey} = \${$key}";
        }
        return implode(', ', $parts);
    }

    /**
     * Test connection to Neo4j
     */
    public function testConnection(): bool
    {
        try {
            $this->query("RETURN 1 as test");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
