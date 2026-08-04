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
 * Digest-intent detection lives in the QU LLM call (the SAME single call, like
 * export_format) — NOT in a hardcoded phrase list. The prompt asks for
 * "digest_request" as a CONCEPT (a BROAD what-needs-my-attention ask across the
 * workspace vs a single-topic question), and ONLY when the host has configured
 * at least one enabled ai.attention_sources entry — hosts without the feature
 * pay zero tokens and see zero behavior change (white-label rule).
 */
class QueryUnderstandingDigestRequestTest extends TestCase
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

    private function serviceReturning(array $response, ?array &$captured = null): QueryUnderstandingService
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages) use (&$captured, $response) {
            $captured = $messages;
            return $response;
        });

        return new QueryUnderstandingService($llm, $this->logger(), []);
    }

    private function configureSources(): void
    {
        config(['ai.attention_sources' => [
            'open_tasks' => ['label' => 'Open tasks', 'cypher' => 'MATCH (t:Task) RETURN t'],
        ]]);
    }

    /** @test */
    public function the_prompt_asks_for_digest_request_only_when_attention_sources_are_configured(): void
    {
        $this->configureSources();

        $captured = null;
        $service = $this->serviceReturning(['search_query' => 'x'], $captured);
        $service->analyze('what should I take care of next?', []);

        $system = $captured[0]['content'] ?? '';
        $this->assertStringContainsString('"digest_request"', $system);
        // White-label invariant: concept description, no phrase lists and no
        // host-domain vocabulary baked into package code.
        $this->assertStringNotContainsString('user may say', $system);
        $this->assertStringNotContainsString('bills', strtolower($system));
    }

    /** @test */
    public function hosts_without_the_feature_pay_zero_tokens_no_digest_request_line(): void
    {
        config(['ai.attention_sources' => []]);

        $captured = null;
        $service = $this->serviceReturning(['search_query' => 'x'], $captured);
        $service->analyze('what should I take care of next?', []);

        $this->assertStringNotContainsString('digest_request', $captured[0]['content'] ?? '');
    }

    /** @test */
    public function a_registry_of_only_disabled_entries_counts_as_unconfigured(): void
    {
        config(['ai.attention_sources' => [
            'held' => ['label' => 'Held', 'cypher' => 'MATCH (n) RETURN n', 'enabled' => false],
        ]]);

        $captured = null;
        $service = $this->serviceReturning(['search_query' => 'x'], $captured);
        $service->analyze('anything', []);

        $this->assertStringNotContainsString('digest_request', $captured[0]['content'] ?? '');
    }

    /** @test */
    public function it_parses_digest_request_true(): void
    {
        $this->configureSources();

        $service = $this->serviceReturning([
            'search_query' => 'workspace attention overview',
            'digest_request' => true,
        ]);

        $result = $service->analyze('What should we take care of next?', []);

        $this->assertTrue($result->digestRequest);
    }

    /** @test */
    public function absent_false_or_null_digest_request_degrades_to_false(): void
    {
        foreach ([[], ['digest_request' => false], ['digest_request' => null]] as $i => $extra) {
            $this->configureSources();
            $service = $this->serviceReturning(['search_query' => 'x'] + $extra);

            $result = $service->analyze('which bills are overdue?', []);

            $this->assertFalse($result->digestRequest, "variant #{$i} must degrade to the normal pipeline");
        }
    }

    /** @test */
    public function consumers_tolerate_results_built_without_the_new_field(): void
    {
        $bare = new QueryUnderstandingResult(entityTypes: ['Task']);
        $this->assertFalse($bare->digestRequest);

        $empty = QueryUnderstandingResult::empty('what next?');
        $this->assertFalse($empty->digestRequest);
    }

    /** @test */
    public function a_failed_qu_call_yields_no_digest_request(): void
    {
        $this->configureSources();

        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andThrow(new \RuntimeException('LLM down'));

        $service = new QueryUnderstandingService($llm, $this->logger(), []);
        $result = $service->analyze('what needs my attention?', []);

        $this->assertFalse($result->digestRequest, 'fail-safe: QU failure routes the turn through the normal pipeline');
    }
}
