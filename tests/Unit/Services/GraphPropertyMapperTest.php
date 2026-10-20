<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\GraphPropertyMapper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GraphPropertyMapper
 *
 * Verifies the shared Neo4j property extraction + sanitization logic that
 * both the single (DataIngestionService) and batch (BatchDataIngestionService)
 * ingestion paths rely on.
 */
class GraphPropertyMapperTest extends TestCase
{
    private GraphPropertyMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new GraphPropertyMapper();
    }

    public function test_extract_properties_keeps_only_requested_fields(): void
    {
        $data = ['id' => 1, 'name' => 'Alice', 'email' => 'a@b.c', 'secret' => 'x'];

        $result = $this->mapper->extractProperties($data, ['id', 'name', 'email']);

        $this->assertSame(['id' => 1, 'name' => 'Alice', 'email' => 'a@b.c'], $result);
        $this->assertArrayNotHasKey('secret', $result);
    }

    public function test_extract_properties_skips_missing_fields(): void
    {
        $data = ['id' => 1, 'name' => 'Alice'];

        $result = $this->mapper->extractProperties($data, ['id', 'name', 'nonexistent']);

        $this->assertSame(['id' => 1, 'name' => 'Alice'], $result);
    }

    public function test_extract_properties_json_encodes_associative_arrays(): void
    {
        $translations = ['fr' => 'Bonjour', 'en' => 'Hello'];
        $data = ['id' => 1, 'translations' => $translations];

        $result = $this->mapper->extractProperties($data, ['id', 'translations']);

        $this->assertIsString($result['translations']);
        $this->assertSame(json_encode($translations, JSON_UNESCAPED_UNICODE), $result['translations']);
    }

    public function test_extract_properties_converts_objects_with_to_array(): void
    {
        $obj = new class {
            public function toArray(): array
            {
                return ['a' => 1, 'b' => 2];
            }
        };
        $data = ['id' => 1, 'payload' => $obj];

        $result = $this->mapper->extractProperties($data, ['payload']);

        // toArray() result is an associative array → JSON-encoded for Neo4j
        $this->assertSame(json_encode(['a' => 1, 'b' => 2], JSON_UNESCAPED_UNICODE), $result['payload']);
    }

    public function test_extract_properties_converts_stringable_objects(): void
    {
        $obj = new class {
            public function __toString(): string
            {
                return 'stringified';
            }
        };
        $data = ['name' => $obj];

        $result = $this->mapper->extractProperties($data, ['name']);

        $this->assertSame('stringified', $result['name']);
    }

    public function test_extract_properties_skips_unconvertible_objects(): void
    {
        $obj = new class {
            public int $x = 1;
        };
        $data = ['name' => 'keep', 'weird' => $obj];

        $result = $this->mapper->extractProperties($data, ['name', 'weird']);

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayNotHasKey('weird', $result);
    }

    public function test_extract_properties_casts_decimal_cast_attributes_to_float(): void
    {
        // Eloquent decimal:N casts return strings ('1234.50'); Neo4j SUM()/comparisons
        // over strings are wrong or fail, so the mapper must coerce them to float.
        $entity = $this->entityWithCasts([
            'calc_due_amount' => 'decimal:2',
            'calc_total_amount' => 'decimal:2',
        ]);
        $data = ['id' => 1, 'calc_due_amount' => '1234.50', 'calc_total_amount' => '0.00'];

        $result = $this->mapper->extractProperties($data, ['id', 'calc_due_amount', 'calc_total_amount'], $entity);

        $this->assertSame(1234.5, $result['calc_due_amount']);
        $this->assertSame(0.0, $result['calc_total_amount']);
    }

    public function test_extract_properties_keeps_numeric_string_codes_without_decimal_cast(): void
    {
        // Ids/codes like account '2410' are numeric strings but NOT decimal-cast —
        // they must stay strings (finance lookups match on the string form).
        $entity = $this->entityWithCasts(['calc_due_amount' => 'decimal:2']);
        $data = ['account_code' => '2410', 'reference' => '00042'];

        $result = $this->mapper->extractProperties($data, ['account_code', 'reference'], $entity);

        $this->assertSame('2410', $result['account_code']);
        $this->assertSame('00042', $result['reference']);
    }

    public function test_extract_properties_leaves_int_and_bool_unchanged_with_casts_present(): void
    {
        $entity = $this->entityWithCasts([
            'count' => 'integer',
            'active' => 'boolean',
            'calc_due_amount' => 'decimal:2',
        ]);
        $data = ['count' => 3, 'active' => true];

        $result = $this->mapper->extractProperties($data, ['count', 'active'], $entity);

        $this->assertSame(3, $result['count']);
        $this->assertTrue($result['active']);
    }

    public function test_extract_properties_skips_coercion_for_non_numeric_or_null_decimal_values(): void
    {
        $entity = $this->entityWithCasts(['calc_due_amount' => 'decimal:2']);
        $data = ['calc_due_amount' => null];

        $result = $this->mapper->extractProperties($data, ['calc_due_amount'], $entity);

        $this->assertNull($result['calc_due_amount']);
    }

    public function test_extract_properties_without_casts_metadata_keeps_strings(): void
    {
        // An entity that exposes no getCasts() (non-Eloquent Nodeable) behaves as before.
        $entity = new class {
        };
        $data = ['calc_due_amount' => '1234.50'];

        $result = $this->mapper->extractProperties($data, ['calc_due_amount'], $entity);

        $this->assertSame('1234.50', $result['calc_due_amount']);
    }

    public function test_sanitize_for_graph_passes_through_primitives(): void
    {
        $this->assertSame('text', $this->mapper->sanitizeForGraph('text'));
        $this->assertSame(42, $this->mapper->sanitizeForGraph(42));
        $this->assertSame(3.14, $this->mapper->sanitizeForGraph(3.14));
        $this->assertTrue($this->mapper->sanitizeForGraph(true));
        $this->assertNull($this->mapper->sanitizeForGraph(null));
    }

    public function test_sanitize_for_graph_keeps_lists_of_primitives(): void
    {
        $list = ['a', 'b', 'c'];

        $this->assertSame($list, $this->mapper->sanitizeForGraph($list));
    }

    public function test_sanitize_for_graph_json_encodes_associative_maps(): void
    {
        $map = ['fr' => 'Bonjour', 'en' => 'Hello'];

        $this->assertSame(
            json_encode($map, JSON_UNESCAPED_UNICODE),
            $this->mapper->sanitizeForGraph($map)
        );
    }

    public function test_sanitize_for_graph_json_encodes_lists_with_nested_arrays(): void
    {
        $nested = [['a' => 1], ['b' => 2]];

        $this->assertSame(
            json_encode($nested, JSON_UNESCAPED_UNICODE),
            $this->mapper->sanitizeForGraph($nested)
        );
    }

    /**
     * Build an entity stub exposing Eloquent's getCasts() shape.
     */
    private function entityWithCasts(array $casts): object
    {
        return new class($casts) {
            public function __construct(private array $casts)
            {
            }

            public function getCasts(): array
            {
                return $this->casts;
            }
        };
    }
}
