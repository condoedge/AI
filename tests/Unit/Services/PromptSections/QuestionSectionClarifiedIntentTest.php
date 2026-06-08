<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\PromptSections\QuestionSection;
use PHPUnit\Framework\TestCase;

/**
 * P0-2: QuestionSection must surface the Query-Understanding clarified intent to
 * the Cypher LLM so the disambiguated underlying data relationships are visible
 * alongside the raw question — but only when it adds signal.
 *
 * Pure string behavior — real PipelineContext / QueryUnderstandingResult, no container.
 */
class QuestionSectionClarifiedIntentTest extends TestCase
{
    private function contextWithClarified(string $clarified): PipelineContext
    {
        $context = new PipelineContext();
        $context->qu = new QueryUnderstandingResult(clarifiedQuestion: $clarified);

        return $context;
    }

    /** @test */
    public function it_includes_the_clarified_intent_when_it_differs_from_the_question(): void
    {
        $question = 'How many parking spaces do I have?';
        $clarified = 'How many parking space entities are related to the person representing me in the graph?';

        $output = (new QuestionSection())->format($question, $this->contextWithClarified($clarified), []);

        // Raw question is still present and spotlighted.
        $this->assertStringContainsString('<user_question>', $output);
        $this->assertStringContainsString($question, $output);

        // Clarified intent is surfaced (spotlighted) as a disambiguation block.
        $this->assertStringContainsString('<clarified_intent>', $output);
        $this->assertStringContainsString('</clarified_intent>', $output);
        $this->assertStringContainsString($clarified, $output);
    }

    /** @test */
    public function it_omits_the_clarified_intent_when_empty(): void
    {
        $question = 'List all invoices';

        $output = (new QuestionSection())->format($question, $this->contextWithClarified(''), []);

        $this->assertStringContainsString($question, $output);
        $this->assertStringNotContainsString('<clarified_intent>', $output);
    }

    /** @test */
    public function it_does_not_duplicate_the_question_when_clarified_equals_it(): void
    {
        $question = 'List all invoices';
        // Clarified question identical to the raw question (modulo surrounding whitespace).
        $output = (new QuestionSection())->format($question, $this->contextWithClarified('  List all invoices  '), []);

        $this->assertStringContainsString($question, $output);
        // No redundant clarified block when it carries no new signal.
        $this->assertStringNotContainsString('<clarified_intent>', $output);
    }
}
