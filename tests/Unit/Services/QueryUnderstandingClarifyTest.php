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
 * G2/L5 item C — the clarify signal lives in the SAME single QU LLM call
 * (like export_format / digest_request): the prompt asks for
 * needs_clarification / candidate_readings / clarifying_question as CONCEPTS
 * (genuinely divergent readings), never as hardcoded phrase lists. Guards are
 * enforced at parse time: a follow-up or digest turn can NEVER come out
 * needing clarification, and the feature is config-gated
 * (ai.understanding.clarify_enabled, default true).
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
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('debug')->byDefault();
        $logger->shouldReceive('error')->byDefault();

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

    private function clarifyResponse(array $extra = []): array
    {
        return [
            'search_query' => 'balance',
            'needs_clarification' => true,
            'candidate_readings' => ['Your own account balance', 'The syndicate financial balance'],
            'clarifying_question' => 'Which balance do you mean — your own account, or the syndicate finances?',
        ] + $extra;
    }

    /** @test */
    public function the_prompt_asks_for_the_clarify_keys_as_concepts(): void
    {
        config(['ai.understanding.clarify_enabled' => true]);

        $captured = null;
        $service = $this->serviceReturning(['search_query' => 'x'], $captured);
        $service->analyze('Show me the balance.', []);

        $system = $captured[0]['content'] ?? '';
        $this->assertStringContainsString('"needs_clarification"', $system);
        $this->assertStringContainsString('"candidate_readings"', $system);
        $this->assertStringContainsString('"clarifying_question"', $system);
        // White-label / no-word-list invariant: the clarify block is a concept
        // description only — no example phrases, no domain vocabulary.
        $clarifyBlock = substr($system, (int) strpos($system, '"needs_clarification"'));
        $clarifyBlock = substr($clarifyBlock, 0, strpos($clarifyBlock, 'Rules:') ?: null);
        $this->assertStringNotContainsString('user may say', $clarifyBlock);
        $this->assertStringNotContainsString('balance', strtolower($clarifyBlock));
    }

    /** @test */
    public function the_clarify_keys_are_absent_when_the_feature_is_disabled(): void
    {
        config(['ai.understanding.clarify_enabled' => false]);

        $captured = null;
        $service = $this->serviceReturning(['search_query' => 'x'], $captured);
        $service->analyze('Show me the balance.', []);

        $this->assertStringNotContainsString('needs_clarification', $captured[0]['content'] ?? '');
    }

    /** @test */
    public function it_parses_a_genuine_clarification_request(): void
    {
        config(['ai.understanding.clarify_enabled' => true]);

        $service = $this->serviceReturning($this->clarifyResponse());
        $result = $service->analyze('Show me the balance.', []);

        $this->assertTrue($result->needsClarification);
        $this->assertCount(2, $result->candidateReadings);
        $this->assertStringContainsString('Which balance', $result->clarifyingQuestion);
    }

    /** @test */
    public function a_follow_up_reference_can_never_need_clarification(): void
    {
        config(['ai.understanding.clarify_enabled' => true]);

        $service = $this->serviceReturning($this->clarifyResponse(['follow_up_reference' => true]));
        $result = $service->analyze('and the balance of it?', []);

        $this->assertFalse($result->needsClarification, 'guard: followUpReference wins over clarify');
    }

    /** @test */
    public function a_digest_request_can_never_need_clarification(): void
    {
        config(['ai.understanding.clarify_enabled' => true]);
        config(['ai.attention_sources' => [
            'open_tasks' => ['label' => 'Open tasks', 'cypher' => 'MATCH (t:Task) RETURN t'],
        ]]);

        $service = $this->serviceReturning($this->clarifyResponse(['digest_request' => true]));
        $result = $service->analyze('what should I take care of?', []);

        $this->assertFalse($result->needsClarification, 'guard: digestRequest wins over clarify');
    }

    /** @test */
    public function absent_or_malformed_values_degrade_to_no_clarification(): void
    {
        foreach ([[], ['needs_clarification' => null], ['needs_clarification' => 'yes-ish' === 'no']] as $i => $extra) {
            config(['ai.understanding.clarify_enabled' => true]);
            $service = $this->serviceReturning(['search_query' => 'x'] + $extra);

            $result = $service->analyze('how many units?', []);

            $this->assertFalse($result->needsClarification, "variant #{$i} must degrade to the normal pipeline");
            $this->assertSame([], $result->candidateReadings);
            $this->assertSame('', $result->clarifyingQuestion);
        }
    }

    /** @test */
    public function consumers_tolerate_results_built_without_the_new_fields(): void
    {
        $bare = new QueryUnderstandingResult(entityTypes: ['Task']);
        $this->assertFalse($bare->needsClarification);
        $this->assertSame([], $bare->candidateReadings);
        $this->assertSame('', $bare->clarifyingQuestion);

        $empty = QueryUnderstandingResult::empty('balance?');
        $this->assertFalse($empty->needsClarification);
    }
}
