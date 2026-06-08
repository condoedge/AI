<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Eval;

use Condoedge\Ai\DTOs\Eval\EvalRow;
use Condoedge\Ai\DTOs\Eval\JudgeVerdict;
use Condoedge\Ai\Services\Eval\AttributionService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the deterministic per-stage attribution ruleset.
 *
 * No LLM, no DB, no container — fully offline. Fixture EvalRows / verdicts in,
 * correct weakest-stage + scorecard math out.
 */
class AttributionServiceTest extends TestCase
{
    private AttributionService $attribution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attribution = new AttributionService(['row_pass_threshold' => 0.6]);
    }

    /** @test */
    public function empty_context_for_a_data_query_with_no_qu_blames_understanding(): void
    {
        $row = new EvalRow(
            question: 'How many widgets are there?',
            questionType: 'data_query',
            quEntityTypes: [],          // QU produced nothing
            selectedEntities: [],
            relevantFiles: [],
            cypher: null
        );

        $this->attribution->attribute($row);

        $this->assertSame('context-not-retrieved', $row->verdictLabel);
        $this->assertSame('understanding', $row->weakestStage);
    }

    /** @test */
    public function empty_context_but_qu_predicted_types_blames_retrieval(): void
    {
        $row = new EvalRow(
            question: 'How many widgets are there?',
            questionType: 'data_query',
            quEntityTypes: ['Widget'],  // QU understood it
            selectedEntities: [],       // but nothing was selected / found
            relevantFiles: [],
            cypher: null
        );

        $this->attribution->attribute($row);

        $this->assertSame('context-not-retrieved', $row->verdictLabel);
        $this->assertSame('retrieval', $row->weakestStage);
    }

    /** @test */
    public function knowledge_query_with_no_files_blames_retrieval(): void
    {
        $row = new EvalRow(
            question: 'What does the policy say about pets?',
            questionType: 'knowledge_query',
            quEntityTypes: [],
            selectedEntities: [],
            relevantFiles: [],
            cypher: null
        );

        $this->attribution->attribute($row);

        $this->assertSame('context-not-retrieved', $row->verdictLabel);
        $this->assertSame('retrieval', $row->weakestStage);
    }

    /** @test */
    public function stage_error_short_circuits_to_the_errored_stage(): void
    {
        $row = new EvalRow(question: 'q', verdictLabel: 'stage-error', erroredStage: 'retrieval', error: 'Neo4j down');

        $this->attribution->attribute($row);

        $this->assertSame('retrieval', $row->weakestStage);
        $this->assertSame(0.0, $row->stageScores['retrieval']);
        $this->assertStringContainsString('threw', $row->blameReason);
    }

    /** @test */
    public function disclosing_the_model_or_schema_blames_prompt(): void
    {
        $verdict = new JudgeVerdict(
            faithfulness: 0.9,
            grounding: 0.9,
            relevance: 0.9,
            usefulness: 0.9,
            disclosesModelOrSchema: true
        );

        $row = new EvalRow(
            question: 'Who are you?',
            questionType: 'knowledge_query',
            selectedEntities: ['Doc'],
            relevantFiles: [['filename' => 'a.md']],
            rowCount: 0,
            answer: 'I am GPT-4 by OpenAI.',
            verdict: $verdict
        );

        $this->attribution->attribute($row);

        $this->assertSame('prompt', $row->weakestStage);
        $this->assertStringContainsString('disclosed', strtolower($row->blameReason));
    }

    /** @test */
    public function low_relevance_and_usefulness_blames_generation(): void
    {
        $verdict = new JudgeVerdict(
            faithfulness: 0.9,
            grounding: 0.9,
            relevance: 0.1,   // off-topic
            usefulness: 0.2
        );

        $row = new EvalRow(
            question: 'How many widgets are there?',
            questionType: 'data_query',
            selectedEntities: ['Widget'],
            cypher: 'MATCH (w:Widget) RETURN count(w)',
            rowCount: 1,
            quEntityTypes: ['Widget'],
            answer: 'The weather is nice today.',
            verdict: $verdict
        );

        $this->attribution->attribute($row);

        $this->assertSame('generation', $row->weakestStage);
    }

    /** @test */
    public function row_with_zero_rows_returned_blames_retrieval(): void
    {
        $verdict = new JudgeVerdict(faithfulness: 0.7, grounding: 0.7, relevance: 0.8, usefulness: 0.8);

        $row = new EvalRow(
            question: 'How many widgets?',
            questionType: 'data_query',
            selectedEntities: ['Widget'],
            cypher: 'MATCH (w:Widget) RETURN count(w)',
            rowCount: 0,    // query ran but found nothing
            quEntityTypes: ['Widget'],
            answer: 'There appear to be none.',
            verdict: $verdict
        );

        $this->attribution->attribute($row);

        $this->assertSame('retrieval', $row->weakestStage);
        $this->assertStringContainsString('0 rows', $row->blameReason);
    }

    /** @test */
    public function a_strong_row_passes(): void
    {
        $verdict = new JudgeVerdict(faithfulness: 0.95, grounding: 0.95, relevance: 0.95, usefulness: 0.95);

        $row = new EvalRow(
            question: 'How many widgets?',
            questionType: 'data_query',
            selectedEntities: ['Widget'],
            cypher: 'MATCH (w:Widget) RETURN count(w)',
            rowCount: 1,
            quEntityTypes: ['Widget'],
            answer: 'There are 42 widgets.',
            verdict: $verdict
        );

        $this->attribution->attribute($row);

        $this->assertSame('passed', $row->verdictLabel);
        foreach ($row->stageScores as $score) {
            $this->assertGreaterThanOrEqual(0.6, $score);
        }
    }

    /** @test */
    public function aggregate_computes_per_stage_averages_and_most_failing_stage(): void
    {
        $rowA = new EvalRow(stageScores: [
            'understanding' => 1.0, 'retrieval' => 0.2, 'prompt' => 0.8, 'generation' => 0.9,
        ], weakestStage: 'retrieval');
        $rowB = new EvalRow(stageScores: [
            'understanding' => 0.8, 'retrieval' => 0.4, 'prompt' => 0.6, 'generation' => 0.7,
        ], weakestStage: 'retrieval');

        $report = $this->attribution->aggregate(
            [$rowA, $rowB],
            ['understanding' => 0.6, 'retrieval' => 0.6, 'prompt' => 0.6, 'generation' => 0.6]
        );

        // Averages.
        $this->assertEqualsWithDelta(0.9, $report->scorecard['understanding'], 0.0001);
        $this->assertEqualsWithDelta(0.3, $report->scorecard['retrieval'], 0.0001);
        $this->assertEqualsWithDelta(0.7, $report->scorecard['prompt'], 0.0001);
        $this->assertEqualsWithDelta(0.8, $report->scorecard['generation'], 0.0001);

        // Most-failing stage + blame counts.
        $this->assertSame('retrieval', $report->weakestStage);
        $this->assertSame(2, $report->blameCounts['retrieval']);

        // Threshold check: retrieval avg 0.3 < 0.6 → failed.
        $this->assertContains('retrieval', $report->failedStages);
        $this->assertFalse($report->passed());
    }

    /** @test */
    public function aggregate_passes_when_all_stages_meet_thresholds(): void
    {
        $row = new EvalRow(stageScores: [
            'understanding' => 0.9, 'retrieval' => 0.8, 'prompt' => 0.7, 'generation' => 0.75,
        ], weakestStage: 'prompt');

        $report = $this->attribution->aggregate(
            [$row],
            ['understanding' => 0.6, 'retrieval' => 0.6, 'prompt' => 0.6, 'generation' => 0.6]
        );

        $this->assertTrue($report->passed());
        $this->assertEmpty($report->failedStages);
    }

    /** @test */
    public function aggregate_marks_offline_when_all_verdicts_skipped(): void
    {
        $row = new EvalRow(
            stageScores: ['understanding' => 0.9, 'retrieval' => 0.8, 'prompt' => 0.5, 'generation' => 0.5],
            verdict: JudgeVerdict::skipped('offline')
        );

        $report = $this->attribution->aggregate([$row], []);

        $this->assertTrue($report->offline);
    }

    /** @test */
    public function ties_break_to_the_earliest_pipeline_stage(): void
    {
        // understanding and retrieval both 0.2 → earliest (understanding) wins.
        $report = $this->attribution->aggregate([
            new EvalRow(stageScores: [
                'understanding' => 0.2, 'retrieval' => 0.2, 'prompt' => 0.9, 'generation' => 0.9,
            ]),
        ], []);

        $this->assertSame('understanding', $report->weakestStage);
    }
}
