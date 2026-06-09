<?php

namespace Condoedge\Ai\Tests\Unit\DTOs;

use Condoedge\Ai\DTOs\PipelineContext;
use PHPUnit\Framework\TestCase;

/**
 * PipelineContext gains one new typed pageContext property (default []), symmetric to the
 * existing conversationContext property. AiManager merges the page-context array onto it; the
 * PageContextSection reads it. Default-empty keeps the section emitting zero tokens when absent.
 */
class PipelineContextPageContextTest extends TestCase
{
    public function test_page_context_defaults_to_empty_array(): void
    {
        $ctx = new PipelineContext();

        $this->assertSame([], $ctx->pageContext);
    }

    public function test_page_context_is_assignable(): void
    {
        $ctx = new PipelineContext();
        $ctx->pageContext = [
            'route' => 'units.show',
            'entity_type' => 'Unit',
            'entity_id' => 402,
            'filters' => ['status' => 'active'],
            'label' => 'Unit 402',
        ];

        $this->assertSame('Unit', $ctx->pageContext['entity_type']);
        $this->assertSame(402, $ctx->pageContext['entity_id']);
    }

    public function test_page_context_is_an_array_typed_property(): void
    {
        $property = new \ReflectionProperty(PipelineContext::class, 'pageContext');
        $type = $property->getType();

        $this->assertNotNull($type);
        $this->assertSame('array', (string) $type);
    }
}
