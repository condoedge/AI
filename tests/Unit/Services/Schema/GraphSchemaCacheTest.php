<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Schema;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Services\Schema\GraphSchemaCache;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Mockery;

/**
 * Task 5.3 — getSchema() runs 4 Neo4j queries (one a full-graph aggregation) and
 * was called twice per turn. It is now cached under ai:schema:v1:<database>
 * (TTL ai.schema_cache_ttl) and flushed by the ingest/index commands.
 */
class GraphSchemaCacheTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function retriever(GraphStoreInterface $store): ContextRetriever
    {
        $vector = Mockery::mock(VectorStoreInterface::class);

        return new ContextRetriever(
            $vector,
            $store,
            Mockery::mock(EmbeddingProviderInterface::class),
            new AiLogger(),
            []
        );
    }

    private function storeCalledOnce(): GraphStoreInterface
    {
        $store = Mockery::mock(GraphStoreInterface::class);
        $store->shouldReceive('getSchema')->once()->andReturn([
            'labels' => ['Invoice'],
            'relationshipTypes' => ['BELONGS_TO_TEAM'],
            'relationshipPatterns' => [['from_label' => 'Invoice', 'type' => 'BELONGS_TO_TEAM', 'to_label' => 'Team', 'count' => 3]],
            'propertyKeys' => ['id'],
        ]);

        return $store;
    }

    public function test_second_call_is_served_from_the_cache(): void
    {
        $retriever = $this->retriever($this->storeCalledOnce());

        $first = $retriever->getGraphSchema();
        $second = $retriever->getGraphSchema();

        $this->assertSame($first, $second);
        $this->assertSame(['Invoice'], $second['labels']);
    }

    public function test_cache_is_shared_across_retriever_instances_under_the_database_key(): void
    {
        config(['ai.graph.neo4j.database' => 'condo']);
        $store = $this->storeCalledOnce();

        $this->retriever($store)->getGraphSchema();
        $this->retriever($store)->getGraphSchema();

        $this->assertTrue(Cache::has('ai:schema:v1:condo'));
    }

    public function test_flush_clears_the_cached_schema(): void
    {
        $store = Mockery::mock(GraphStoreInterface::class);
        $store->shouldReceive('getSchema')->twice()->andReturn(['labels' => ['A']], ['labels' => ['A', 'B']]);
        $retriever = $this->retriever($store);

        $this->assertSame(['A'], $retriever->getGraphSchema()['labels']);
        (new GraphSchemaCache())->flush();

        $this->assertSame(['A', 'B'], $retriever->getGraphSchema()['labels']);
    }

    public function test_ai_manager_flush_schema_cache_clears_it(): void
    {
        config(['ai.graph.neo4j.database' => 'neo4j']);
        Cache::forever('ai:schema:v1:neo4j', ['labels' => ['Stale']]);

        $manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            Mockery::mock(ContextRetrieverInterface::class),
            Mockery::mock(EmbeddingProviderInterface::class),
            Mockery::mock(LlmProviderInterface::class),
            Mockery::mock(QueryGeneratorInterface::class),
            Mockery::mock(QueryExecutorInterface::class),
            Mockery::mock(ResponseGeneratorInterface::class),
            Mockery::mock(VectorStoreInterface::class),
        );
        $manager->flushSchemaCache();

        $this->assertFalse(Cache::has('ai:schema:v1:neo4j'));
    }

    public function test_a_zero_ttl_disables_caching(): void
    {
        config(['ai.schema_cache_ttl' => 0]);
        $store = Mockery::mock(GraphStoreInterface::class);
        $store->shouldReceive('getSchema')->twice()->andReturn(['labels' => ['A']]);
        $retriever = $this->retriever($store);

        $retriever->getGraphSchema();
        $retriever->getGraphSchema();

        $this->assertFalse(Cache::has('ai:schema:v1:neo4j'));
    }

    public function test_a_failing_store_is_not_cached(): void
    {
        $store = Mockery::mock(GraphStoreInterface::class);
        $store->shouldReceive('getSchema')->once()->andThrow(new \RuntimeException('neo4j down'));
        $store->shouldReceive('getSchema')->once()->andReturn(['labels' => ['A']]);
        $retriever = $this->retriever($store);

        try {
            $retriever->getGraphSchema();
            $this->fail('the store failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('neo4j down', $e->getMessage());
        }

        $this->assertSame(['A'], $retriever->getGraphSchema()['labels']);
    }

    /** Review I-1: a schema whose pattern scan failed (null) is served but never cached. */
    public function test_a_schema_with_unknown_patterns_is_not_cached(): void
    {
        $store = Mockery::mock(GraphStoreInterface::class);
        $store->shouldReceive('getSchema')->twice()->andReturn(
            ['labels' => ['A'], 'relationshipTypes' => ['T'], 'relationshipPatterns' => null],
            ['labels' => ['A'], 'relationshipTypes' => ['T'], 'relationshipPatterns' => [['from_label' => 'A', 'type' => 'T', 'to_label' => 'A']]]
        );
        $retriever = $this->retriever($store);

        $first = $retriever->getGraphSchema();
        $this->assertFalse(Cache::has('ai:schema:v1:neo4j'));
        $this->assertNull($first['relationshipPatterns'], 'unknown patterns stay null through the retriever');

        $second = $retriever->getGraphSchema();
        $this->assertCount(1, $second['relationshipPatterns']);
        $this->assertTrue(Cache::has('ai:schema:v1:neo4j'));
    }

    public function test_a_schema_without_any_pattern_key_normalizes_to_unknown(): void
    {
        $store = Mockery::mock(GraphStoreInterface::class);
        $store->shouldReceive('getSchema')->andReturn(['labels' => ['A']]);

        $this->assertNull($this->retriever($store)->getGraphSchema()['relationshipPatterns']);
    }

    // ---- Review I-2: auto-sync writes flush only when they change the schema ----

    private function cacheSchema(): void
    {
        config(['ai.graph.neo4j.database' => 'neo4j']);
        Cache::forever('ai:schema:v1:neo4j', [
            'labels' => ['User', 'Team'],
            'relationshipTypes' => ['MEMBER_OF'],
            'relationshipPatterns' => [['from_label' => 'User', 'type' => 'MEMBER_OF', 'to_label' => 'Team', 'count' => 3]],
        ]);
    }

    public function test_a_write_to_a_known_label_keeps_the_cache(): void
    {
        $this->cacheSchema();

        $this->assertFalse((new GraphSchemaCache())->flushIfUnknownLabel('User'));
        $this->assertTrue(Cache::has('ai:schema:v1:neo4j'));
    }

    public function test_a_write_creating_a_new_label_flushes(): void
    {
        $this->cacheSchema();

        $this->assertTrue((new GraphSchemaCache())->flushIfUnknownLabel('Invoice'));
        $this->assertFalse(Cache::has('ai:schema:v1:neo4j'));
    }

    public function test_a_known_relationship_pattern_keeps_the_cache(): void
    {
        $this->cacheSchema();

        $this->assertFalse((new GraphSchemaCache())->flushIfUnknownRelationship('User', 'MEMBER_OF', 'Team'));
        $this->assertTrue(Cache::has('ai:schema:v1:neo4j'));
    }

    /** @dataProvider newRelationships */
    public function test_a_new_relationship_type_or_pattern_flushes(string $from, string $type, string $to): void
    {
        $this->cacheSchema();

        $this->assertTrue((new GraphSchemaCache())->flushIfUnknownRelationship($from, $type, $to));
        $this->assertFalse(Cache::has('ai:schema:v1:neo4j'));
    }

    public static function newRelationships(): array
    {
        return [
            'new type' => ['User', 'OWNS', 'Team'],
            'known type, new direction/pattern' => ['Team', 'MEMBER_OF', 'User'],
        ];
    }

    public function test_nothing_cached_means_nothing_to_flush(): void
    {
        $this->assertFalse((new GraphSchemaCache())->flushIfUnknownLabel('Anything'));
        $this->assertFalse((new GraphSchemaCache())->flushIfUnknownRelationship('A', 'T', 'B'));
    }

    public function test_per_label_entries_are_cached_and_invalidated_by_flush(): void
    {
        $cache = new GraphSchemaCache();
        $calls = 0;
        $fetch = function () use (&$calls) {
            $calls++;

            return ['id' => 'Integer'];
        };

        $cache->rememberLabel('Invoice', $fetch);
        $cache->rememberLabel('Invoice', $fetch);
        $this->assertSame(1, $calls);

        $cache->flush();
        $cache->rememberLabel('Invoice', $fetch);
        $this->assertSame(2, $calls);
    }
}
