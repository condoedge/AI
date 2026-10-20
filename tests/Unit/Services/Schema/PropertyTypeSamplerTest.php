<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Schema;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Services\Schema\PropertyTypeSampler;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Task 5.2 — property types for the schema cards. db.schema.nodeTypeProperties()
 * took ~50 s on the CondoEdge graph, so types are probed on a small per-label
 * sample, server-side (values never leave Neo4j), cached per label.
 */
class PropertyTypeSamplerTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_probe_returns_type_metadata_only_never_node_values(): void
    {
        $cypher = PropertyTypeSampler::probeCypher('`Invoice`');

        $this->assertStringContainsString('MATCH (n:`Invoice`) WITH n LIMIT 25', $cypher);
        $this->assertStringContainsString('valueType(v)', $cypher);
        // The RETURN projects only the key, type names and boolean format flags.
        $return = substr($cypher, strrpos($cypher, 'RETURN'));
        $this->assertSame(
            'RETURN property, collect(DISTINCT t) AS types, all(x IN collect(iso) WHERE x) AS iso, all(x IN collect(numeric) WHERE x) AS numeric',
            $return
        );
    }

    public function test_maps_cypher_types_and_string_formats(): void
    {
        $store = Mockery::mock(GraphStoreInterface::class);
        $store->shouldReceive('query')
            ->once()
            ->with(PropertyTypeSampler::probeCypher('`Invoice`'))
            ->andReturn([
                ['property' => 'id', 'types' => ['INTEGER NOT NULL'], 'iso' => false, 'numeric' => false],
                ['property' => 'status', 'types' => ['STRING NOT NULL'], 'iso' => false, 'numeric' => true],
                ['property' => 'due_at', 'types' => ['STRING NOT NULL'], 'iso' => true, 'numeric' => false],
                ['property' => 'amount', 'types' => ['FLOAT NOT NULL'], 'iso' => false, 'numeric' => false],
                ['property' => 'flag', 'types' => ['BOOLEAN NOT NULL'], 'iso' => false, 'numeric' => false],
                ['property' => 'tags', 'types' => ['LIST<STRING NOT NULL> NOT NULL'], 'iso' => false, 'numeric' => false],
                ['property' => 'at', 'types' => ['ZONED DATETIME NOT NULL'], 'iso' => false, 'numeric' => false],
                ['property' => 'note', 'types' => ['STRING NOT NULL'], 'iso' => false, 'numeric' => false],
                ['property' => 'mixed', 'types' => ['INTEGER NOT NULL', 'FLOAT NOT NULL'], 'iso' => false, 'numeric' => false],
            ]);

        $types = (new PropertyTypeSampler($store))->forLabels(['Invoice']);

        $this->assertSame([
            'id' => 'Integer',
            'status' => 'String(numeric)',
            'due_at' => 'String(ISO date)',
            'amount' => 'Float',
            'flag' => 'Boolean',
            'tags' => 'List',
            'at' => 'ZonedDatetime',
            'note' => 'String',
            'mixed' => 'Float|Integer',
        ], $types['Invoice']);
    }

    public function test_samples_are_cached_per_label(): void
    {
        $store = Mockery::mock(GraphStoreInterface::class);
        $store->shouldReceive('query')->once()->andReturn([
            ['property' => 'id', 'types' => ['INTEGER NOT NULL'], 'iso' => false, 'numeric' => false],
        ]);

        (new PropertyTypeSampler($store))->forLabels(['Unit']);
        $again = (new PropertyTypeSampler($store))->forLabels(['Unit']);

        $this->assertSame(['id' => 'Integer'], $again['Unit']);
    }

    /**
     * Review I-3: a failing probe (e.g. Neo4j < 5.13, no valueType()) is skipped, not
     * fatal, and remembered as a SHORT negative result so it is not re-run every turn;
     * a flush (ingest) or the negative TTL lets it be retried.
     */
    public function test_a_failing_label_is_skipped_and_negatively_cached(): void
    {
        $store = Mockery::mock(GraphStoreInterface::class);
        $store->shouldReceive('query')->once()->andThrow(new \RuntimeException('Unknown function valueType'));

        $this->assertSame([], (new PropertyTypeSampler($store))->forLabels(['Unit']));
        // Second turn: served from the negative cache — no second probe (->once()).
        $this->assertSame([], (new PropertyTypeSampler($store))->forLabels(['Unit']));
    }

    public function test_the_negative_result_is_short_lived_and_cleared_by_flush(): void
    {
        $store = Mockery::mock(GraphStoreInterface::class);
        $store->shouldReceive('query')->once()->andThrow(new \RuntimeException('timeout'));
        $store->shouldReceive('query')->once()->andReturn([
            ['property' => 'id', 'types' => ['INTEGER NOT NULL'], 'iso' => false, 'numeric' => false],
        ]);

        (new PropertyTypeSampler($store))->forLabels(['Unit']);
        (new \Condoedge\Ai\Services\Schema\GraphSchemaCache())->flush();

        $this->assertSame(['Unit' => ['id' => 'Integer']], (new PropertyTypeSampler($store))->forLabels(['Unit']));
        $this->assertLessThanOrEqual(600, PropertyTypeSampler::NEGATIVE_TTL);
        $this->assertGreaterThan(0, PropertyTypeSampler::NEGATIVE_TTL);
    }

    public function test_the_negative_result_expires_after_its_ttl(): void
    {
        $store = Mockery::mock(GraphStoreInterface::class);
        $store->shouldReceive('query')->twice()->andThrow(new \RuntimeException('timeout'));

        (new PropertyTypeSampler($store))->forLabels(['Unit']);
        $this->travel(PropertyTypeSampler::NEGATIVE_TTL + 1)->seconds();
        (new PropertyTypeSampler($store))->forLabels(['Unit']);

        $this->assertTrue(true); // ->twice(): the probe re-ran once the negative entry expired
    }

    public function test_an_unsafe_label_is_skipped(): void
    {
        $store = Mockery::mock(GraphStoreInterface::class);
        $store->shouldNotReceive('query');

        $this->assertSame([], (new PropertyTypeSampler($store))->forLabels(['Bad`) DETACH DELETE n //']));
    }
}
