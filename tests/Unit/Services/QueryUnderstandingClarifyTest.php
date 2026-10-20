<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The clarify judgment is NO LONGER part of the Query-Understanding call.
 *
 * Measured (eval p15, 2026-10-06): "needs_clarification" came back true on 0 of
 * 148 eval turns — including the turn written for it — while its three key docs
 * cost ~264 prompt tokens on EVERY turn; a live A/B with an explicit rule changed
 * nothing. Inside the many-key prompt the small model never flags. The judgment is
 * made by the focused ClarityChecker call instead, only when the structural
 * preconditions hold (see ClarityCheckerTest / AiManagerClarityCheckTest).
 *
 * So, whatever ai.understanding.clarify_enabled says: the QU prompt asks for none
 * of the three keys, and a model that volunteers them is ignored (the clarify
 * fields of the QU result stay at their defaults until the checker fills them).
 */
class QueryUnderstandingClarifyTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function logger(): AiLogger
    {
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldIgnoreMissing();
        $logger->shouldReceive('stopTimer')->andReturn(0.0);

        return $logger;
    }

    private function serviceReturning(array $response, ?array &$captured = null, array $config = []): QueryUnderstandingService
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages) use (&$captured, $response) {
            $captured = $messages;

            return $response;
        });

        return new QueryUnderstandingService($llm, $this->logger(), $config);
    }

    /** @test */
    public function the_qu_prompt_asks_for_none_of_the_clarify_keys(): void
    {
        foreach ([true, false] as $enabled) {
            config(['ai.understanding.clarify_enabled' => $enabled]);

            $captured = null;
            $this->serviceReturning(['search_query' => 'x'], $captured)->analyze('Show me the balance.', []);

            $system = (string) ($captured[0]['content'] ?? '');
            $this->assertStringNotContainsString('needs_clarification', $system);
            $this->assertStringNotContainsString('candidate_readings', $system);
            $this->assertStringNotContainsString('clarifying_question', $system);
            // The neighbouring key and the rules are untouched and still adjacent.
            $this->assertMatchesRegularExpression('/- "follow_up_reference":[^\n]*\n\r?\nRules:/', $system);
        }
    }

    /** @test */
    public function the_conversation_block_no_longer_names_the_clarify_key(): void
    {
        $captured = null;
        $this->serviceReturning(['search_query' => 'x'], $captured)->analyze('and the second one?', [], [
            'recent_exchanges' => [
                ['role' => 'user', 'question' => 'List the open work orders', 'answer_summary' => null],
                ['role' => 'assistant', 'question' => null, 'answer_summary' => 'Here are the 3 open work orders.'],
            ],
        ]);

        $system = (string) ($captured[0]['content'] ?? '');
        $this->assertStringContainsString('CONVERSATION SO FAR (authoritative context for "follow_up_reference"', $system);
        $this->assertStringNotContainsString('needs_clarification', $system);
    }

    /** @test */
    public function clarify_keys_a_model_volunteers_are_ignored(): void
    {
        config(['ai.understanding.clarify_enabled' => true]);

        $result = $this->serviceReturning([
            'search_query' => 'balance',
            'question_type' => 'data_query',
            'needs_clarification' => true,
            'candidate_readings' => ['One reading', 'Another reading'],
            'clarifying_question' => 'Which one do you mean?',
        ])->analyze('Show me the balance.', []);

        $this->assertFalse($result->needsClarification);
        $this->assertSame([], $result->candidateReadings);
        $this->assertSame('', $result->clarifyingQuestion);
        $this->assertSame('data_query', $result->questionType, 'the rest of the reply is parsed as before');
    }

    /** @test */
    public function consumers_tolerate_results_built_without_the_clarify_fields(): void
    {
        $bare = new QueryUnderstandingResult(entityTypes: ['Task']);
        $this->assertFalse($bare->needsClarification);
        $this->assertSame([], $bare->candidateReadings);
        $this->assertSame('', $bare->clarifyingQuestion);

        $empty = QueryUnderstandingResult::empty('balance?');
        $this->assertFalse($empty->needsClarification);
    }
}
