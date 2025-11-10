<?php

namespace AiSystem\GraphStore;

use AiSystem\Contracts\GraphStoreInterface;

/**
 * Neo4j Graph Store Implementation
 *
 * Connects to Neo4j via HTTP API for graph operations.
 * For production, consider using official neo4j-php-client for Bolt protocol.
 */
class Neo4jStore implements GraphStoreInterface
{
    protected string $uri;
    protected string $username;
    protected string $password;
    protected string $database;
    protected string $httpEndpoint;

    public function __construct(?array $config = null)
    {
        $config = $config ?? config('ai.neo4j');

        $this->uri = $config['uri'] ?? 'bolt://localhost:7687';
        $this->username = $config['username'] ?? 'neo4j';
        $this->password = $config['password'] ?? 'password';
        $this->database = $config['database'] ?? 'neo4j';

        // Convert bolt:// to http:// for HTTP API
        // bolt://localhost:7687 -> http://localhost:7474
        $parsedUri = parse_url($this->uri);
        $host = $parsedUri['host'] ?? 'localhost';
        $httpPort = 7474; // Neo4j HTTP port

        $this->httpEndpoint = "http://{$host}:{$httpPort}/db/{$this->database}/tx/commit";
    }

    public function createNode(string $label, array $properties): string|int
    {
        $propsStr = $this->arrayToCypherProps($properties);

        $cypher = "CREATE (n:{$label} {$propsStr}) RETURN id(n) as nodeId, n.id as appId";

        $result = $this->query($cypher, $properties);

        if (empty($result)) {
            throw new \RuntimeException("Failed to create node with label '{$label}'");
        }

        // Return the application ID (from properties) if it exists, otherwise Neo4j internal ID
        return $result[0]['appId'] ?? $result[0]['nodeId'];
    }

    public function updateNode(string $label, string|int $id, array $properties): bool
    {
        $setClause = $this->arrayToSetClause($properties);

        $cypher = "MATCH (n:{$label} {id: \$id}) SET {$setClause} RETURN n";

        $params = array_merge(['id' => $id], $properties);
        $result = $this->query($cypher, $params);

        return !empty($result);
    }

    public function deleteNode(string $label, string|int $id): bool
    {
        $cypher = "MATCH (n:{$label} {id: \$id}) DETACH DELETE n";

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
        $propsStr = !empty($properties) ? $this->arrayToCypherProps($properties) : '';

        $cypher = "
            MATCH (from:{$fromLabel} {id: \$fromId})
            MATCH (to:{$toLabel} {id: \$toId})
            MERGE (from)-[r:{$type} {$propsStr}]->(to)
            RETURN r
        ";

        $params = array_merge([
            'fromId' => $fromId,
            'toId' => $toId,
        ], $properties);

        $result = $this->query($cypher, $params);

        return !empty($result);
    }

    public function deleteRelationship(
        string $fromLabel,
        string|int $fromId,
        string $toLabel,
        string|int $toId,
        string $type
    ): bool {
        $cypher = "
            MATCH (from:{$fromLabel} {id: \$fromId})-[r:{$type}]->(to:{$toLabel} {id: \$toId})
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
        $cypher = "MATCH (n:{$label} {id: \$id}) RETURN count(n) as count";
        $result = $this->query($cypher, ['id' => $id]);

        return !empty($result) && $result[0]['count'] > 0;
    }

    public function getNode(string $label, string|int $id): ?array
    {
        $cypher = "MATCH (n:{$label} {id: \$id}) RETURN n";
        $result = $this->query($cypher, ['id' => $id]);

        if (empty($result)) {
            return null;
        }

        return $result[0]['n'] ?? null;
    }

    public function beginTransaction()
    {
        // For HTTP API, transactions are handled per-request
        // Return a transaction object that will be used in commit/rollback
        return ['endpoint' => str_replace('/commit', '', $this->httpEndpoint)];
    }

    public function commit($transaction): bool
    {
        // HTTP API commits automatically with /tx/commit endpoint
        return true;
    }

    public function rollback($transaction): bool
    {
        // HTTP API transactions are atomic per-request
        return true;
    }

    /**
     * Execute Cypher query via Neo4j HTTP API
     */
    protected function executeCypher(string $cypher, array $parameters = []): array
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

        $ch = curl_init($this->httpEndpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
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
     */
    protected function arrayToCypherProps(array $properties): string
    {
        $parts = [];
        foreach (array_keys($properties) as $key) {
            $parts[] = "{$key}: \${$key}";
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
            $parts[] = "n.{$key} = \${$key}";
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
