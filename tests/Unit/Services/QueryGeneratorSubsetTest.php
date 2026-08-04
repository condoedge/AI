<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The generation loop must reject an out-of-subset draft (so it would fail closed
 * at execution) and feed the reason back so the EXISTING retry loop regenerates
 * an in-subset query. No new control flow — reuses the validate/retry loop.
 */
class QueryGeneratorSubsetTest extends TestCase
{
    private LlmProviderInterface $mockLlm;
    private GraphStoreInterface $mockGraph;
    private QueryGenerator $generator;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('ai.security.enabled', true);
        config()->set('ai.security.enforce_scopeable_subset', true);

        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);
        $this->mockGraph = Mockery::mock(GraphStoreInterface::class);

        $this->generator = new QueryGenerator($this->mockLlm, $this->mockGraph, [
            'temperature' => 0.1,
            'max_retries' => 3,
            'allow_write_operations' => false,
            'default_limit' => 100,
            'max_complexity' => 100,
            'enable_templates' => false, // force the LLM path
        ]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeContext(): PipelineContext
    {
        $context = new PipelineContext();
        $context->graphSchema = ['labels' => ['Invoice', 'Person'], 'relationships' => []];
        return $context;
    }

    /** @test */
    public function out_of_subset_draft_is_rejected_and_retry_produces_in_subset_query(): void
    {
        // First draft: passes basic validation but is OUTSIDE the scopeable subset
        // (label-free node access) → would fail closed at execution.
        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->andReturn('MATCH (n) RETURN n LIMIT 100');

        // Second draft: in-subset.
        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->andReturn('MATCH (i:Invoice) RETURN i LIMIT 100');

        $result = $this->generator->generate('Which invoices exist?', $this->makeContext(), ['explain' => false]);

        $this->assertGreaterThan(0, $result->metadata['retry_count']);
        $this->assertStringContainsString('MATCH (i:Invoice)', $result->cypher);
    }

    /** @test */
    public function in_subset_draft_is_accepted_first_try(): void
    {
        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->andReturn('MATCH (i:Invoice) WHERE i.status = "open" RETURN i LIMIT 100');

        $result = $this->generator->generate('Open invoices?', $this->makeContext(), ['explain' => false]);

        $this->assertSame(0, $result->metadata['retry_count']);
        $this->assertStringContainsString('MATCH (i:Invoice)', $result->cypher);
    }

    /** @test */
    public function subset_enforcement_off_accepts_out_of_subset_draft(): void
    {
        config()->set('ai.security.enforce_scopeable_subset', false);

        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->andReturn('MATCH (n) RETURN n LIMIT 100');

        $result = $this->generator->generate('Anything?', $this->makeContext(), ['explain' => false]);

        $this->assertSame(0, $result->metadata['retry_count']);
    }
}
