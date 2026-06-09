<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PatternLibrary;
use Condoedge\Ai\Services\PromptSections\PatternLibrarySection;
use PHPUnit\Framework\TestCase;

/**
 * E7: PatternLibrarySection must emit ONLY the patterns relevant to the detected scopes,
 * not the entire library. A scope is linked to a library pattern by its `specification_type`
 * (e.g. relationship_traversal, property_filter) or, for pattern-type scopes, its `pattern`
 * name. With no detected scopes there is no relevance signal, so nothing is emitted.
 *
 * Pure string behavior over an injected PatternLibrary — no container needed.
 */
class PatternLibrarySectionTest extends TestCase
{
    private function library(): PatternLibrary
    {
        // Inject patterns directly so the test does not depend on config('ai.query_patterns').
        return new PatternLibrary([
            'property_filter' => [
                'description' => 'Filter entities by property value',
                'semantic_template' => 'Find {entity} where {property} {operator} {value}',
                'parameters' => ['entity' => 'label', 'property' => 'name'],
            ],
            'relationship_traversal' => [
                'description' => 'Find entities connected through relationship path',
                'semantic_template' => 'Find {start_entity} connected through relationships',
                'parameters' => ['start_entity' => 'label'],
            ],
            'temporal_filter' => [
                'description' => 'Filter entities by date/time conditions',
                'semantic_template' => 'Find {entity} where {date_property} is {temporal_operator} {temporal_value}',
                'parameters' => ['entity' => 'label'],
            ],
        ]);
    }

    private function section(): PatternLibrarySection
    {
        return new PatternLibrarySection($this->library());
    }

    private function contextWithScopes(array $scopes): PipelineContext
    {
        $context = new PipelineContext();
        $context->entityMetadata = ['detected_scopes' => $scopes];

        return $context;
    }

    /** @test */
    public function it_emits_only_patterns_matching_detected_scope_specification_types(): void
    {
        $context = $this->contextWithScopes([
            ['scope' => 'active', 'entity' => 'Person', 'specification_type' => 'property_filter'],
        ]);

        $output = $this->section()->format('show active people', $context, []);

        // The matching pattern is present...
        $this->assertStringContainsString('property_filter', $output);
        // ...and the unrelated patterns are NOT dumped.
        $this->assertStringNotContainsString('relationship_traversal', $output);
        $this->assertStringNotContainsString('temporal_filter', $output);
    }

    /** @test */
    public function it_matches_pattern_type_scopes_by_pattern_name(): void
    {
        $context = $this->contextWithScopes([
            [
                'scope' => 'recent',
                'entity' => 'Customer',
                'specification_type' => 'pattern',
                'pattern' => 'temporal_filter',
            ],
        ]);

        $output = $this->section()->format('recent customers', $context, []);

        $this->assertStringContainsString('temporal_filter', $output);
        $this->assertStringNotContainsString('property_filter', $output);
    }

    /** @test */
    public function it_emits_nothing_when_no_scopes_are_detected(): void
    {
        $output = $this->section()->format('hello', new PipelineContext(), []);

        $this->assertSame('', $output);
        $this->assertFalse($this->section()->shouldInclude('hello', new PipelineContext(), []));
    }

    /** @test */
    public function it_emits_nothing_when_scopes_match_no_known_pattern(): void
    {
        $context = $this->contextWithScopes([
            ['scope' => 'x', 'entity' => 'Y', 'specification_type' => 'generic'],
        ]);

        $this->assertSame('', $this->section()->format('q', $context, []));
        $this->assertFalse($this->section()->shouldInclude('q', $context, []));
    }
}
