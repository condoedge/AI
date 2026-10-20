<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\GraphStore;

use Condoedge\Ai\GraphStore\BoltNeo4jStore;
use Condoedge\Ai\GraphStore\Neo4jStore;
use Condoedge\Ai\Tests\TestCase;

/**
 * Review I-1 (Phase 5A): the full-graph relationship-pattern scan is the query most
 * likely to time out. Its failure must surface as `relationshipPatterns => null`
 * ("unknown"), never as `[]` ("the graph has no edges") — an empty list got cached
 * for the whole TTL and stripped every edge from the schema cards.
 */
class SchemaPatternFailureTest extends TestCase
{
    private const CONFIG = [
        'uri' => 'bolt://localhost:7687',
        'username' => 'neo4j',
        'password' => 'placeholder-password',
        'database' => 'neo4j',
    ];

    private static function answer(string $cypher, bool $patternsFail): array
    {
        return match (true) {
            str_contains($cypher, 'db.labels()') => [['label' => 'Invoice'], ['label' => 'Team']],
            str_contains($cypher, 'db.relationshipTypes()') => [['relationshipType' => 'BELONGS_TO_TEAM']],
            str_contains($cypher, 'db.propertyKeys()') => [['propertyKey' => 'id']],
            $patternsFail => throw new \RuntimeException('Neo4j transaction timed out'),
            default => [['fromLabel' => 'Invoice', 'type' => 'BELONGS_TO_TEAM', 'toLabel' => 'Team', 'count' => 3]],
        };
    }

    public static function stores(): array
    {
        return ['bolt' => ['bolt'], 'http' => ['http']];
    }

    private function store(string $kind, bool $patternsFail): object
    {
        if ($kind === 'bolt') {
            return new class (self::CONFIG, $patternsFail) extends BoltNeo4jStore {
                public function __construct(array $config, private bool $fail)
                {
                    parent::__construct($config);
                }

                public function query(string $cypher, array $parameters = []): array
                {
                    return SchemaPatternFailureTest::respond($cypher, $this->fail);
                }
            };
        }

        return new class (['uri' => 'http://localhost:7474'] + self::CONFIG, $patternsFail) extends Neo4jStore {
            public function __construct(array $config, private bool $fail)
            {
                parent::__construct($config);
            }

            public function query(string $cypher, array $parameters = []): array
            {
                return SchemaPatternFailureTest::respond($cypher, $this->fail);
            }
        };
    }

    public static function respond(string $cypher, bool $patternsFail): array
    {
        return self::answer($cypher, $patternsFail);
    }

    /** @dataProvider stores */
    public function test_a_failed_pattern_scan_is_reported_as_unknown_not_empty(string $kind): void
    {
        $schema = $this->store($kind, patternsFail: true)->getSchema();

        $this->assertArrayHasKey('relationshipPatterns', $schema);
        $this->assertNull($schema['relationshipPatterns']);
        $this->assertSame(['Invoice', 'Team'], $schema['labels']);
        $this->assertSame(['BELONGS_TO_TEAM'], $schema['relationshipTypes']);
    }

    /** @dataProvider stores */
    public function test_a_successful_pattern_scan_is_a_list(string $kind): void
    {
        $schema = $this->store($kind, patternsFail: false)->getSchema();

        $this->assertSame(
            [['from_label' => 'Invoice', 'type' => 'BELONGS_TO_TEAM', 'to_label' => 'Team', 'count' => 3]],
            $schema['relationshipPatterns']
        );
    }
}
