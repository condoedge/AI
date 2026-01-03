<?php

declare(strict_types=1);

namespace Condoedge\Ai\GraphStore;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\TransactionInterface;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\Types\CypherMap;
use Laudis\Neo4j\Types\Node;
use Illuminate\Support\Facades\Log;

/**
 * Neo4j Graph Store Implementation (Bolt Protocol)
 *
 * Connects to Neo4j via Bolt protocol using the official laudis/neo4j-php-client.
 * Use this for bolt://, neo4j://, neo4j+s://, or neo4j+ssc:// URIs.
 * For http:// URIs, use Neo4jStore instead.
 *
 * Benefits over HTTP:
 * - Faster binary protocol
 * - Built-in connection pooling
 * - Proper transaction support
 * - Recommended for production
 *
 * @see Neo4jStore For HTTP API support
 * @see Neo4jStoreFactory For automatic driver selection based on URI scheme
 */
class BoltNeo4jStore implements GraphStoreInterface
{
    use Neo4jCredentialsTrait;

    protected string $uri;
    protected string $username;
    protected string $database;
    protected ClientInterface $client;

    /**
     * Active transaction for transaction methods
     */
    private ?TransactionInterface $activeTransaction = null;

    public function __construct(?array $config = null)
    {
        $config = $config ?? config('ai.graph.neo4j');

        $this->uri = $config['uri'] ?? 'bolt://localhost:7687';
        $this->username = $config['username'] ?? 'neo4j';
        $this->database = $config['database'] ?? 'neo4j';

        // Initialize credentials using trait
        $this->initializeCredentials($config);

        // Build the Neo4j client
        $this->client = ClientBuilder::create()
            ->withDriver('default', $this->uri, Authenticate::basic(
                $this->username,
                $this->getPassword()
            ))
            ->withDefaultDriver('default')
            ->build();
    }

    public function createNode(string $label, array $properties): string|int
    {
        $safeLabel = CypherSanitizer::escapeLabel($label);

        $cypher = "CREATE (n:{$safeLabel}) SET n = \$properties RETURN elementId(n) as nodeId, n.id as appId";

        $result = $this->query($cypher, ['properties' => $properties]);

        if (empty($result)) {
            throw new \RuntimeException("Failed to create node with label '{$label}'");
        }

        return $result[0]['appId'] ?? $result[0]['nodeId'];
    }

    public function updateNode(string $label, string|int $id, array $properties): bool
    {
        $safeLabel = CypherSanitizer::escapeLabel($label);
        $setClause = $this->arrayToSetClause($properties);

        $cypher = "MATCH (n:{$safeLabel} {id: \$id}) SET {$setClause} RETURN n";

        $params = array_merge(['id' => $id], $properties);
        $result = $this->query($cypher, $params);

        return !empty($result);
    }

    public function deleteNode(string $label, string|int $id): bool
    {
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
        $safeFromLabel = CypherSanitizer::escapeLabel($fromLabel);
        $safeToLabel = CypherSanitizer::escapeLabel($toLabel);
        $safeType = CypherSanitizer::escapeRelationshipType($type);

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
            $result = $this->client->run($cypher, $parameters, $this->database);

            return $this->convertResultToArray($result);

        } catch (\Exception $e) {
            Log::error('Neo4j Bolt query failed', [
                'error' => $e->getMessage(),
                'cypher' => substr($cypher, 0, 200),
            ]);
            throw new \RuntimeException("Failed to execute Neo4j query: " . $e->getMessage(), 0, $e);
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
     * @return array Transaction context with 'session' key
     */
    public function beginTransaction(): array
    {
        $session = $this->client->beginTransaction(null, $this->database);

        // Store reference for later use
        $txId = spl_object_id($session);

        return [
            'id' => $txId,
            'session' => $session,
        ];
    }

    /**
     * Commit a transaction
     *
     * @param mixed $transaction Transaction context from beginTransaction()
     * @return bool Success status
     */
    public function commit($transaction): bool
    {
        if (!is_array($transaction) || empty($transaction['session'])) {
            return true;
        }

        try {
            $session = $transaction['session'];
            if ($session instanceof TransactionInterface) {
                $session->commit();
            }
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to commit Neo4j Bolt transaction', [
                'error' => $e->getMessage(),
                'transaction_id' => $transaction['id'] ?? 'unknown',
            ]);
            return false;
        }
    }

    /**
     * Rollback a transaction
     *
     * @param mixed $transaction Transaction context from beginTransaction()
     * @return bool Success status
     */
    public function rollback($transaction): bool
    {
        if (!is_array($transaction) || empty($transaction['session'])) {
            return true;
        }

        try {
            $session = $transaction['session'];
            if ($session instanceof TransactionInterface) {
                $session->rollback();
            }
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to rollback Neo4j Bolt transaction', [
                'error' => $e->getMessage(),
                'transaction_id' => $transaction['id'] ?? 'unknown',
            ]);
            return false;
        }
    }

    /**
     * Execute a query within a transaction
     *
     * @param array $transaction Transaction context from beginTransaction()
     * @param string $cypher Cypher query
     * @param array $parameters Query parameters
     * @return array Query results
     */
    public function queryInTransaction(array $transaction, string $cypher, array $parameters = []): array
    {
        if (empty($transaction['session'])) {
            throw new \RuntimeException('Invalid transaction context: missing session');
        }

        try {
            $session = $transaction['session'];
            if (!$session instanceof TransactionInterface) {
                throw new \RuntimeException('Invalid transaction session type');
            }

            $result = $session->run($cypher, $parameters);

            return $this->convertResultToArray($result);

        } catch (\Exception $e) {
            throw new \RuntimeException("Neo4j query error in transaction: " . $e->getMessage(), 0, $e);
        }
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

    /**
     * Convert Neo4j result set to plain PHP arrays
     *
     * @param iterable $result The Neo4j result set
     * @return array Plain PHP array representation
     */
    protected function convertResultToArray(iterable $result): array
    {
        $rows = [];

        foreach ($result as $record) {
            $row = [];

            foreach ($record->keys() as $key) {
                $value = $record->get($key);
                $row[$key] = $this->convertValue($value);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Convert a Neo4j value to a plain PHP value
     *
     * @param mixed $value The value to convert
     * @return mixed Plain PHP value
     */
    protected function convertValue(mixed $value): mixed
    {
        if ($value instanceof Node) {
            // Convert Node to array of properties
            return $value->getProperties()->toArray();
        }

        if ($value instanceof CypherMap) {
            return $value->toArray();
        }

        if (is_iterable($value) && !is_array($value)) {
            return iterator_to_array($value);
        }

        return $value;
    }

    /**
     * Convert array to SET clause for Cypher
     */
    protected function arrayToSetClause(array $properties): string
    {
        $parts = [];
        foreach (array_keys($properties) as $key) {
            $safeKey = CypherSanitizer::validatePropertyKey($key);
            $parts[] = "n.{$safeKey} = \${$key}";
        }
        return implode(', ', $parts);
    }
}
