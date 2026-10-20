<?php

declare(strict_types=1);

namespace Condoedge\Ai\GraphStore;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\Types\CypherMap;
use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\Node;
use Laudis\Neo4j\Types\Relationship;
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

    public function __construct(?array $config = null)
    {
        $config = $config ?? config('ai.graph.neo4j');

        $this->uri = $config['uri'] ?? 'bolt://localhost:7687';
        $this->username = $config['username'] ?? 'neo4j';
        $this->database = $config['database'] ?? 'neo4j';

        $this->initializeCredentials($config);

        // Database is specified in the URI as a query parameter.
        $uriWithDatabase = $this->uri;
        if ($this->database && $this->database !== 'neo4j') {
            $separator = str_contains($this->uri, '?') ? '&' : '?';
            $uriWithDatabase = $this->uri . $separator . 'database=' . urlencode($this->database);
        }

        $this->client = ClientBuilder::create()
            ->withDriver('default', $uriWithDatabase, Authenticate::basic(
                $this->username,
                $this->getPassword()
            ))
            ->withDefaultDriver('default')
            ->build();
    }

    public function createNode(string $label, array $properties): string|int
    {
        $safeLabel = CypherSanitizer::escapeLabel($label);

        $cypher = "MERGE (n:{$safeLabel} {id: \$id}) SET n = \$properties RETURN elementId(n) as nodeId, n.id as appId";

        $result = $this->query($cypher, ['id' => $properties['id'], 'properties' => $properties]);

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
        ";

        $params = [
            'fromId' => $fromId,
            'toId' => $toId,
        ];

        if (!empty($properties)) {
            $cypher .= "SET r = \$properties\n";
            $params['properties'] = $properties;
        }

        $cypher .= "RETURN r";

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
            $result = $this->client->run($cypher, $parameters);

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
            'relationshipPatterns' => [],
            'propertyKeys' => [],
        ];

        $labels = $this->query("CALL db.labels()");
        $schema['labels'] = array_column($labels, 'label');

        $relTypes = $this->query("CALL db.relationshipTypes()");
        $schema['relationshipTypes'] = array_column($relTypes, 'relationshipType');

        $propKeys = $this->query("CALL db.propertyKeys()");
        $schema['propertyKeys'] = array_column($propKeys, 'propertyKey');

        try {
            $schema['relationshipPatterns'] = $this->getRelationshipPatterns();
        } catch (\Throwable $e) {
            // null = UNKNOWN (the full-graph scan failed, e.g. timed out) — never []
            // ("the graph has no edges"). GraphSchemaCache refuses to cache it.
            $schema['relationshipPatterns'] = null;
            Log::warning('Failed to retrieve Neo4j relationship patterns', [
                'error' => $e->getMessage(),
            ]);
        }

        return $schema;
    }

    /**
     * @return array<int, array{from_label: string, type: string, to_label: string, count: int}>
     */
    private function getRelationshipPatterns(): array
    {
        $rows = $this->query("
            MATCH (from)-[r]->(to)
            WITH labels(from) AS fromLabels, type(r) AS type, labels(to) AS toLabels, count(r) AS count
            UNWIND fromLabels AS fromLabel
            UNWIND toLabels AS toLabel
            RETURN fromLabel, type, toLabel, count
            ORDER BY type, fromLabel, toLabel
        ");

        $patterns = [];
        foreach ($rows as $row) {
            if (!isset($row['fromLabel'], $row['type'], $row['toLabel'])) {
                continue;
            }

            $patterns[] = [
                'from_label' => (string) $row['fromLabel'],
                'type' => (string) $row['type'],
                'to_label' => (string) $row['toLabel'],
                'count' => (int) ($row['count'] ?? 0),
            ];
        }

        return $patterns;
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
     * Recursively converts Laudis types (CypherMap, CypherList, Node, Relationship)
     * to plain PHP arrays and scalar values.
     *
     * @param mixed $value The value to convert
     * @return mixed Plain PHP value
     */
    protected function convertValue(mixed $value): mixed
    {
        if ($value instanceof Node) {
            $props = $value->getProperties();
            return $this->convertValue($props);
        }

        if ($value instanceof Relationship) {
            $props = $value->getProperties();
            return $this->convertValue($props);
        }

        if ($value instanceof CypherMap) {
            $result = [];
            foreach ($value as $key => $val) {
                $result[$key] = $this->convertValue($val);
            }
            return $result;
        }

        if ($value instanceof CypherList) {
            $result = [];
            foreach ($value as $val) {
                $result[] = $this->convertValue($val);
            }
            return $result;
        }

        // Other iterables, but not strings/arrays which are handled below.
        if (is_iterable($value) && !is_array($value) && !is_string($value)) {
            $result = [];
            foreach ($value as $key => $val) {
                $result[$key] = $this->convertValue($val);
            }
            return $result;
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $val) {
                $result[$key] = $this->convertValue($val);
            }
            return $result;
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
