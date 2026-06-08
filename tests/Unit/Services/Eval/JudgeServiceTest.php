<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Eval;

use Condoedge\Ai\DTOs\Eval\JudgeVerdict;
use Condoedge\Ai\Services\Eval\JudgeService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the reference-free judge's rubric PARSING (offline).
 *
 * parseVerdict() is exercised directly with stubbed LLM payloads so no API key
 * or network is required. judge() with a null resolver must skip gracefully.
 */
class JudgeServiceTest extends TestCase
{
    private JudgeService $judge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->judge = new JudgeService(llmResolver: null, config: []);
    }

    /** @test */
    public function it_parses_a_well_formed_rubric_array(): void
    {
        $verdict = $this->judge->parseVerdict([
            'faithfulness' => 0.9,
            'grounding' => 0.8,
            'relevance' => 1.0,
            'usefulness' => 0.7,
            'discloses_model_or_schema' => false,
            'claims' => [
                ['claim' => 'There are 42 widgets', 'grounded' => true, 'reason' => 'matches row count'],
            ],
            'reason' => 'accurate and grounded',
        ]);

        $this->assertFalse($verdict->skipped);
        $this->assertSame(0.9, $verdict->faithfulness);
        $this->assertSame(0.8, $verdict->grounding);
        $this->assertSame(1.0, $verdict->relevance);
        $this->assertSame(0.7, $verdict->usefulness);
        $this->assertFalse($verdict->disclosesModelOrSchema);
        $this->assertCount(1, $verdict->claims);
        $this->assertTrue($verdict->claims[0]['grounded']);
        $this->assertEqualsWithDelta(0.85, $verdict->generationScore(), 0.0001);
    }

    /** @test */
    public function it_parses_a_json_string_with_code_fences(): void
    {
        $payload = "```json\n" . json_encode([
            'faithfulness' => 0.5,
            'grounding' => 0.5,
            'relevance' => 0.5,
            'usefulness' => 0.5,
        ]) . "\n```";

        $verdict = $this->judge->parseVerdict($payload);

        $this->assertFalse($verdict->skipped);
        $this->assertSame(0.5, $verdict->faithfulness);
    }

    /** @test */
    public function it_clamps_out_of_range_and_percent_scores(): void
    {
        $verdict = $this->judge->parseVerdict([
            'faithfulness' => 1.5,   // > 1 but not a percent → clamp to 1
            'grounding' => -0.3,     // < 0 → clamp to 0
            'relevance' => 80,       // percent scale → 0.8
            'usefulness' => 0.4,
        ]);

        $this->assertSame(1.0, $verdict->faithfulness);
        $this->assertSame(0.0, $verdict->grounding);
        $this->assertSame(0.8, $verdict->relevance);
    }

    /** @test */
    public function it_flags_model_or_schema_disclosure(): void
    {
        $verdict = $this->judge->parseVerdict([
            'faithfulness' => 0.9,
            'grounding' => 0.9,
            'relevance' => 0.9,
            'usefulness' => 0.9,
            'discloses_model_or_schema' => true,
        ]);

        $this->assertTrue($verdict->disclosesModelOrSchema);
    }

    /** @test */
    public function unparseable_response_yields_a_skipped_verdict(): void
    {
        $verdict = $this->judge->parseVerdict('not json at all');

        $this->assertTrue($verdict->skipped);
        $this->assertSame(0.0, $verdict->generationScore());
    }

    /** @test */
    public function judge_skips_gracefully_with_no_resolver(): void
    {
        $verdict = $this->judge->judge('q', 'an answer', ['data' => [], 'files' => [], 'cypher' => null]);

        $this->assertInstanceOf(JudgeVerdict::class, $verdict);
        $this->assertTrue($verdict->skipped);
    }

    /** @test */
    public function judge_skips_when_answer_is_empty(): void
    {
        // Even with a (would-be) resolver, an empty answer is nothing to judge.
        $verdict = $this->judge->judge('q', '   ', ['data' => []]);

        $this->assertTrue($verdict->skipped);
    }

    /** @test */
    public function missing_dimensions_default_to_zero(): void
    {
        $verdict = $this->judge->parseVerdict(['reason' => 'partial']);

        $this->assertFalse($verdict->skipped);
        $this->assertSame(0.0, $verdict->faithfulness);
        $this->assertSame(0.0, $verdict->grounding);
        $this->assertSame('partial', $verdict->reason);
    }
}
