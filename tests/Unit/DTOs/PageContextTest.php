<?php

namespace Condoedge\Ai\Tests\Unit\DTOs;

use Condoedge\Ai\DTOs\PageContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the PageContext DTO.
 *
 * PageContext is the readonly carrier for "which page/record the chat was opened
 * from". It crosses the Kompo prop boundary as an array (fromArray/toArray) and is
 * threaded — defaulting to null at every call site — into AskQuestionInput and the
 * PipelineContext as a soft prior. Nothing here may have side effects or touch the
 * container, so these tests run as a plain PHPUnit TestCase.
 */
class PageContextTest extends TestCase
{
    public function test_constructs_with_all_properties(): void
    {
        $ctx = new PageContext(
            route: 'units.show',
            entityType: 'Unit',
            entityId: 402,
            filters: ['status' => 'active'],
            label: 'Unit 402',
        );

        $this->assertSame('units.show', $ctx->route);
        $this->assertSame('Unit', $ctx->entityType);
        $this->assertSame(402, $ctx->entityId);
        $this->assertSame(['status' => 'active'], $ctx->filters);
        $this->assertSame('Unit 402', $ctx->label);
    }

    public function test_defaults_are_null_and_empty(): void
    {
        $ctx = new PageContext();

        $this->assertNull($ctx->route);
        $this->assertNull($ctx->entityType);
        $this->assertNull($ctx->entityId);
        $this->assertSame([], $ctx->filters);
        $this->assertNull($ctx->label);
    }

    public function test_entity_id_accepts_string(): void
    {
        // entityId is int|string|null so it survives both numeric keys and slugs/UUIDs.
        $ctx = new PageContext(entityType: 'Unit', entityId: 'unit-402-abc');

        $this->assertSame('unit-402-abc', $ctx->entityId);
    }

    public function test_creates_from_array(): void
    {
        $ctx = PageContext::fromArray([
            'route' => 'units.show',
            'entity_type' => 'Unit',
            'entity_id' => 402,
            'filters' => ['status' => 'active'],
            'label' => 'Unit 402',
        ]);

        $this->assertSame('units.show', $ctx->route);
        $this->assertSame('Unit', $ctx->entityType);
        $this->assertSame(402, $ctx->entityId);
        $this->assertSame(['status' => 'active'], $ctx->filters);
        $this->assertSame('Unit 402', $ctx->label);
    }

    public function test_from_array_tolerates_missing_keys(): void
    {
        // A host may pass only a subset; missing keys fall back to the constructor defaults.
        $ctx = PageContext::fromArray(['entity_type' => 'Unit']);

        $this->assertNull($ctx->route);
        $this->assertSame('Unit', $ctx->entityType);
        $this->assertNull($ctx->entityId);
        $this->assertSame([], $ctx->filters);
        $this->assertNull($ctx->label);
    }

    public function test_from_array_coerces_non_array_filters_to_empty(): void
    {
        // Defensive: a malformed 'filters' prop must never break the typed array contract.
        $ctx = PageContext::fromArray(['filters' => 'not-an-array']);

        $this->assertSame([], $ctx->filters);
    }

    public function test_to_array_round_trips(): void
    {
        $data = [
            'route' => 'units.show',
            'entity_type' => 'Unit',
            'entity_id' => 402,
            'filters' => ['status' => 'active'],
            'label' => 'Unit 402',
        ];

        $array = PageContext::fromArray($data)->toArray();

        $this->assertSame($data, $array);
        $this->assertEquals($data, PageContext::fromArray($array)->toArray());
    }
}
