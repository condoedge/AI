<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Condoedge\Ai\Domain\ValueObjects\VectorConfig;
use Condoedge\Ai\Services\VectorPayloadBuilder;
use Illuminate\Support\Facades\Cache;
use Mockery;

/**
 * Unit tests for VectorPayloadBuilder
 *
 * Verifies the shared embed-text + payload + collection-ensure logic used by
 * both the single and batch ingestion paths, including the convergence that
 * closes the historical divergence (sensible-column stripping + owner/security
 * metadata in both paths).
 */
class VectorPayloadBuilderTest extends TestCase
{
    private VectorPayloadBuilder $builder;

    public function setUp(): void
    {
        parent::setUp();
        $this->builder = new VectorPayloadBuilder();
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_build_embed_text_concatenates_fields_with_spaces(): void
    {
        $data = ['name' => 'John Doe', 'description' => 'Software Engineer'];

        $text = $this->builder->buildEmbedText($data, ['name', 'description']);

        $this->assertSame('John Doe Software Engineer', $text);
    }

    public function test_build_embed_text_skips_null_and_empty_values(): void
    {
        $data = ['name' => 'John', 'middle' => '', 'last' => null, 'suffix' => 'Jr'];

        $text = $this->builder->buildEmbedText($data, ['name', 'middle', 'last', 'suffix']);

        $this->assertSame('John Jr', $text);
    }

    public function test_build_embed_text_flattens_arrays_of_scalars(): void
    {
        $data = ['tags' => ['php', 'laravel', 'rag']];

        $text = $this->builder->buildEmbedText($data, ['tags']);

        $this->assertSame('php laravel rag', $text);
    }

    public function test_build_embed_text_for_strips_sensible_columns(): void
    {
        $entity = $this->makeEntityWithSensibleColumns(['ssn']);
        $config = new VectorConfig(
            collection: 'people',
            embedFields: ['name', 'ssn'],
            metadata: ['id'],
        );
        $data = ['id' => 1, 'name' => 'Alice', 'ssn' => '123-45-6789'];

        $text = $this->builder->buildEmbedTextFor($entity, $data, $config);

        // ssn is a sensible column → excluded from the embedded text
        $this->assertSame('Alice', $text);
        $this->assertStringNotContainsString('123-45-6789', $text);
    }

    public function test_build_payload_includes_id_and_security_metadata(): void
    {
        $entity = $this->makeEntity(1);
        $config = new VectorConfig(
            collection: 'people',
            embedFields: ['name'],
            metadata: ['id', 'category'],
        );
        $data = ['id' => 1, 'name' => 'Alice', 'category' => 'TypeA'];

        $payload = $this->builder->buildPayload($entity, $data, $config);

        $this->assertSame(1, $payload['id']);
        $this->assertSame('TypeA', $payload['category']);
        $this->assertArrayHasKey('_entity_type', $payload);
        $this->assertArrayHasKey('_entity_class', $payload);
        $this->assertSame(get_class($entity), $payload['_entity_class']);
    }

    public function test_build_payload_includes_owner_id_when_entity_exposes_it(): void
    {
        $entity = $this->makeEntityWithOwner(7, 99);
        $config = new VectorConfig(
            collection: 'docs',
            embedFields: ['name'],
            metadata: ['id'],
        );
        $data = ['id' => 7, 'name' => 'Doc'];

        $payload = $this->builder->buildPayload($entity, $data, $config);

        $this->assertSame(99, $payload['_owner_id']);
    }

    public function test_build_payload_omits_owner_id_for_plain_nodeable(): void
    {
        $entity = $this->makeEntity(1);
        $config = new VectorConfig(
            collection: 'people',
            embedFields: ['name'],
            metadata: ['id'],
        );
        $data = ['id' => 1, 'name' => 'Alice'];

        $payload = $this->builder->buildPayload($entity, $data, $config);

        // A hand-rolled Nodeable without getAttribute must not crash and must
        // simply carry no owner tag.
        $this->assertArrayNotHasKey('_owner_id', $payload);
    }

    public function test_build_point_assembles_id_vector_and_payload(): void
    {
        $entity = $this->makeEntity(5);
        $config = new VectorConfig(
            collection: 'people',
            embedFields: ['name'],
            metadata: ['id'],
        );
        $data = ['id' => 5, 'name' => 'Bob'];
        $vector = [0.1, 0.2, 0.3];

        $point = $this->builder->buildPoint($entity, $data, $config, $vector);

        $this->assertSame(5, $point['id']);
        $this->assertSame($vector, $point['vector']);
        $this->assertSame(5, $point['payload']['id']);
    }

    public function test_single_and_batch_paths_produce_identical_payloads(): void
    {
        // The whole point of the shared builder: identical output regardless of
        // entry point. Two independently-constructed builders (as the single and
        // batch services each construct) must yield byte-identical payloads.
        $entity = $this->makeEntityWithOwner(3, 42);
        $config = new VectorConfig(
            collection: 'docs',
            embedFields: ['name', 'body'],
            metadata: ['id', 'category'],
        );
        $data = ['id' => 3, 'name' => 'Title', 'body' => 'Content', 'category' => 'A'];

        $single = (new VectorPayloadBuilder())->buildPayload($entity, $data, $config);
        $batch = (new VectorPayloadBuilder())->buildPayload($entity, $data, $config);

        $this->assertSame($single, $batch);

        $singleText = (new VectorPayloadBuilder())->buildEmbedTextFor($entity, $data, $config);
        $batchText = (new VectorPayloadBuilder())->buildEmbedTextFor($entity, $data, $config);

        $this->assertSame($singleText, $batchText);
        $this->assertSame('Title Content', $singleText);
    }

    public function test_ensure_collection_exists_creates_missing_collection(): void
    {
        Cache::flush();
        $config = new VectorConfig(
            collection: $this->getTestCollectionName('vpb'),
            embedFields: ['name'],
            metadata: ['id'],
        );

        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $vectorStore->shouldReceive('collectionExists')->once()->andReturn(false);
        $vectorStore->shouldReceive('createCollection')->once()->andReturn(true);

        $this->builder->ensureCollectionExists($vectorStore, $config);

        // Second call should be short-circuited by the cache (no further store hits)
        $this->builder->ensureCollectionExists($vectorStore, $config);

        $this->assertTrue(true);
    }

    public function test_ensure_collection_exists_skips_creation_when_present(): void
    {
        Cache::flush();
        $config = new VectorConfig(
            collection: $this->getTestCollectionName('vpb'),
            embedFields: ['name'],
            metadata: ['id'],
        );

        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $vectorStore->shouldReceive('collectionExists')->once()->andReturn(true);
        $vectorStore->shouldNotReceive('createCollection');

        $this->builder->ensureCollectionExists($vectorStore, $config);

        $this->assertTrue(true);
    }

    // ----------------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------------

    private function makeEntity(int $id): Nodeable
    {
        return new class($id) implements Nodeable {
            public function __construct(private int $id) {}
            public function getId(): int { return $this->id; }
            public function toArray(): array { return ['id' => $this->id]; }
            public function getGraphConfig(): GraphConfig
            {
                return new GraphConfig('Thing', ['id'], []);
            }
            public function getVectorConfig(): VectorConfig
            {
                return new VectorConfig('things', ['name'], ['id']);
            }
        };
    }

    private function makeEntityWithSensibleColumns(array $columns): Nodeable
    {
        return new class($columns) implements Nodeable {
            protected array $sensibleColumns;
            public function __construct(array $columns) { $this->sensibleColumns = $columns; }
            public function getId(): int { return 1; }
            public function toArray(): array { return ['id' => 1]; }
            public function getGraphConfig(): GraphConfig
            {
                return new GraphConfig('Person', ['id'], []);
            }
            public function getVectorConfig(): VectorConfig
            {
                return new VectorConfig('people', ['name'], ['id']);
            }
        };
    }

    private function makeEntityWithOwner(int $id, int $ownerId): Nodeable
    {
        return new class($id, $ownerId) implements Nodeable {
            public function __construct(private int $id, private int $ownerId) {}
            public function getId(): int { return $this->id; }
            public function toArray(): array { return ['id' => $this->id]; }
            public function getAttribute(string $key): mixed
            {
                return $key === 'user_id' ? $this->ownerId : null;
            }
            public function getGraphConfig(): GraphConfig
            {
                return new GraphConfig('Doc', ['id'], []);
            }
            public function getVectorConfig(): VectorConfig
            {
                return new VectorConfig('docs', ['name'], ['id']);
            }
        };
    }
}
