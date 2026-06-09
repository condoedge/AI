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
}
