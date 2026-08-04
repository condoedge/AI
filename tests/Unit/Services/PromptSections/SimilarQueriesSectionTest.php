<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PromptSections\SimilarQueriesSection;
use Condoedge\Ai\Tests\TestCase;

/**
 * Lever L2 (few-shot hygiene): stored examples from old interactions were copied
 * byte-identically at "87% similar", beating the clarified intent. Besides --fresh
 * clearing the store, the section itself must frame examples as pattern reference
 * subordinate to the current question's intent/attributes/scoping.
 */
class SimilarQueriesSectionTest extends TestCase
{
    private function contextWith(array $similarQueries): PipelineContext
    {
        $context = new PipelineContext();
        $context->similarQueries = $similarQueries;

        return $context;
    }

    /** @test */
    public function it_is_excluded_when_there_are_no_similar_queries(): void
    {
        $section = new SimilarQueriesSection();

        $this->assertFalse($section->shouldInclude('q', $this->contextWith([])));
        $this->assertSame('', $section->format('q', $this->contextWith([])));
    }

    /** @test */
    public function it_renders_examples_with_question_query_and_similarity(): void
    {
        $section = new SimilarQueriesSection();
        $context = $this->contextWith([
            ['question' => 'How many units?', 'query' => 'MATCH (u:Unit) RETURN count(u)', 'score' => 0.87],
        ]);

        $output = $section->format('q', $context);

        $this->assertStringContainsString('SIMILAR QUERIES', $output);
        $this->assertStringContainsString('How many units?', $output);
        $this->assertStringContainsString('MATCH (u:Unit) RETURN count(u)', $output);
        $this->assertStringContainsString('87% similar', $output);
    }

    /** @test */
    public function examples_are_framed_as_subordinate_to_the_current_intent(): void
    {
        // Anti-poisoning framing: pattern reference only, never verbatim copy
        // over the clarified intent / requested attributes / scoping rules.
        $section = new SimilarQueriesSection();
        $context = $this->contextWith([
            ['question' => 'q1', 'query' => 'MATCH (n) RETURN n', 'score' => 0.9],
        ]);

        $output = $section->format('q', $context);

        $this->assertStringContainsString('PATTERN reference only', $output);
        $this->assertStringContainsString('PRECEDENCE', $output);
        $this->assertStringContainsString('OVERRIDE these examples', $output);
        $this->assertStringContainsString('NEVER copy an example verbatim', $output);
    }
}
