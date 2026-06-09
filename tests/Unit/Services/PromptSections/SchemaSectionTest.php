<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PromptSections\SchemaSection;
use PHPUnit\Framework\TestCase;

/**
 * E2: an empty graph schema must be SKIPPED, not narrated. Advertising an empty
 * "=== GRAPH SCHEMA === No schema information available." block is pure noise.
 *
 * Pure string behavior — no container needed.
 */
class SchemaSectionTest extends TestCase
{
    /** @test */
    public function it_is_skipped_when_the_schema_is_empty(): void
    {
        $section = new SchemaSection();

        $this->assertFalse($section->shouldInclude('q', new PipelineContext(), []));
    }

    /** @test */
    public function it_is_included_when_the_schema_has_data(): void
    {
        $section = new SchemaSection();

        $context = new PipelineContext();
        $context->graphSchema = ['labels' => ['Invoice']];

        $this->assertTrue($section->shouldInclude('q', $context, []));

        $output = $section->format('q', $context, []);
        $this->assertStringContainsString('GRAPH SCHEMA', $output);
        $this->assertStringContainsString('Invoice', $output);
    }
}
