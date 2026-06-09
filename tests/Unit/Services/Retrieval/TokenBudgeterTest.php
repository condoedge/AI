<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Retrieval\TokenBudgeter;
use Condoedge\Ai\Tests\TestCase;

class TokenBudgeterTest extends TestCase
{
    /** @test */
    public function estimate_tokens_is_zero_for_empty_string(): void
    {
        $this->assertSame(0, (new TokenBudgeter())->estimateTokens(''));
    }

    /** @test */
    public function estimate_tokens_uses_four_chars_per_token(): void
    {
        // 8 chars / 4 = 2 tokens (plain text, no JSON uplift)
        $this->assertSame(2, (new TokenBudgeter())->estimateTokens('abcdefgh'));
    }

    /** @test */
    public function estimate_tokens_adds_overhead_for_json_like_text(): void
    {
        $plain = (new TokenBudgeter())->estimateTokens('aaaaaaaa'); // 8 chars
        $json = (new TokenBudgeter())->estimateTokens('{"aaaa":1}'); // starts with {
        $this->assertGreaterThan($plain, $json);
    }

    /** @test */
    public function stats_breaks_down_tokens_by_section_for_pipeline_context(): void
    {
        $ctx = new PipelineContext();
        $ctx->similarQueries = [['question' => 'List teams', 'query' => 'MATCH (t:Team) RETURN t']];
        $ctx->graphSchema = ['labels' => ['Team'], 'relationships' => ['MEMBER_OF'], 'propertyKeys' => ['id', 'name']];

        $stats = (new TokenBudgeter())->stats($ctx);

        $this->assertArrayHasKey('token_estimate', $stats);
        $this->assertArrayHasKey('breakdown', $stats);
        $this->assertGreaterThan(0, $stats['breakdown']['similar_queries']);
        $this->assertGreaterThan(0, $stats['breakdown']['graph_schema']);
        $this->assertSame(array_sum($stats['breakdown']), $stats['token_estimate']);
        $this->assertSame(1, $stats['items_included']['similar_queries']);
    }

    /** @test */
    public function stats_computes_compression_ratio_for_semantic_selection(): void
    {
        $entityConfigs = array_fill_keys(['A', 'B', 'C', 'D'], ['graph' => []]);
        $ctx = new PipelineContext();
        $ctx->selectionInfo = ['method' => 'semantic', 'entities_selected' => 1];

        $stats = (new TokenBudgeter($entityConfigs))->stats($ctx);

        // 1 of 4 selected -> 75% compression
        $this->assertSame(75.0, $stats['compression_ratio']);
    }

    /** @test */
    public function confidence_is_zero_for_empty_distribution(): void
    {
        $c = (new TokenBudgeter())->confidence([]);
        $this->assertSame(0.0, $c['overall']);
        $this->assertSame(0, $c['considered']);
    }

    /** @test */
    public function confidence_considers_only_top_k(): void
    {
        $c = (new TokenBudgeter())->confidence([0.9, 0.8, 0.7, 0.1, 0.1], 3);
        $this->assertSame(3, $c['considered']);
        $this->assertEqualsWithDelta(0.9, $c['top_score'], 0.0001);
        $this->assertEqualsWithDelta(0.8, $c['strength'], 0.0001);
    }

    /** @test */
    public function confidence_clamps_into_unit_interval(): void
    {
        $c = (new TokenBudgeter())->confidence([1.0, 1.0, 0.0]);
        $this->assertGreaterThanOrEqual(0.0, $c['overall']);
        $this->assertLessThanOrEqual(1.0, $c['overall']);
    }
}
