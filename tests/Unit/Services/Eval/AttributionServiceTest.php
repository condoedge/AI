<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Eval;

use Condoedge\Ai\DTOs\Eval\EvalRow;
use Condoedge\Ai\Services\Eval\AttributionService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the deterministic per-stage attribution ruleset.
 *
 * No LLM, no DB, no container — fully offline. Fixture EvalRows in, correct
 * weakest-stage + scorecard math out. Attribution scores exclusively from cheap
 * deterministic pipeline signals; answer grading is done out-of-process by an
 * agent, so there is no in-process verdict input.
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
    public function a_digest_turn_is_exempt_from_the_no_entity_types_understanding_signal(): void
    {
        // G(ii), iteration-11 verdict: digest turns legitimately skip entity
        // selection and query generation (the config-declared source walk
        // replaces both), so "understanding produced no entity types" is KNOWN
        // NOISE there — the harness must not blame understanding or label the
        // turn context-not-retrieved.
        $row = new EvalRow(
            question: 'What should I take care of next?',
            questionType: 'data_query',
            quEntityTypes: [],       // digest asks routinely predict nothing
            selectedEntities: [],    // no selection happens on the digest branch
            relevantFiles: [],
            cypher: null,            // no generated query either
            rowCount: 12,
            answer: 'Your attention digest.',
        );
        $row->responseMetadata = ['digest' => ['sources' => [['key' => 'open_tasks', 'row_count' => 5]]]];

        $this->attribution->attribute($row);

        $this->assertNotSame('context-not-retrieved', $row->verdictLabel);
        $this->assertNotSame('understanding', $row->weakestStage, 'digest turns must never be blamed on understanding for empty entity types');
        $this->assertStringNotContainsString('no entity types', $row->blameReason);
    }

    /** @test */
    public function a_failed_digest_turn_still_never_blames_understanding_for_empty_entity_types(): void
    {
        // Even a digest whose sources all errored (0 rows) is a digest-branch
        // problem, not an understanding mismatch.
        $row = new EvalRow(
            question: 'What should I take care of next?',
            questionType: 'data_query',
            quEntityTypes: [],
            selectedEntities: [],
            relevantFiles: [],
            cypher: null,
            rowCount: 0,
            answer: 'Some sources could not be checked.',
        );
        $row->responseMetadata = ['digest' => ['sources' => [], 'failed_sources' => ['open_tasks']]];

        $this->attribution->attribute($row);

        $this->assertNotSame('understanding', $row->weakestStage);
        $this->assertStringNotContainsString('no entity types', $row->blameReason);
    }

    /** @test */
    public function a_clarify_turn_is_not_labeled_context_not_retrieved(): void
    {
        // Clarify turns by DESIGN skip file search and query generation (the
        // clarifying question rode the single QU call): no files, no cypher,
        // rowCount 0 — empty context is their NORMAL shape, exactly like the
        // digest exemption. The clarification metadata is stamped by
        // AiManager::clarifyResponse (metadata.response.clarification).
        $row = new EvalRow(
            question: 'What is the balance?',
            questionType: 'data_query',
            quEntityTypes: [],
            selectedEntities: [],
            relevantFiles: [],
            cypher: null,
            rowCount: 0,
            answer: 'Which balance do you mean: the operating account or your contribution account?',
        );
        $row->responseMetadata = ['clarification' => ['candidate_readings' => ['operating account', 'contribution account']]];

        $this->attribution->attribute($row);

        $this->assertNotSame('context-not-retrieved', $row->verdictLabel);
        $this->assertSame('passed', $row->verdictLabel, 'a correct clarify turn must not fail on absent retrieval signals');
        $this->assertNotSame('understanding', $row->weakestStage, 'routing to clarify IS the understanding signal');
    }

    /** @test */
    public function a_clarify_turn_with_selected_entities_is_not_soft_failed_for_absent_data_signals(): void
    {
        // Entity selection runs in stage 1 BEFORE the clarify branch owns the
        // turn, so retrieval-ish signals may exist — but cypher/rows never
        // will. Soft scoring must not fail the turn for their absence.
        $row = new EvalRow(
            question: 'ambiguous balance question',
            questionType: 'data_query',
            quEntityTypes: ['Invoice'],
            selectedEntities: ['Invoice'],
            relevantFiles: [],
            cypher: null,
            rowCount: 0,
            answer: 'Do you mean X or Y?',
        );
        $row->responseMetadata = ['clarification' => ['candidate_readings' => []]];

        $this->attribution->attribute($row);

        $this->assertSame('passed', $row->verdictLabel);
        foreach ($row->stageScores as $stage => $score) {
            $this->assertGreaterThanOrEqual(0.6, $score, "stage {$stage} must score neutrally on a clarify turn");
        }
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
    public function a_well_retrieved_and_answered_row_passes_from_signals_alone(): void
    {
        // No verdict input at all: a data query that selected entities, ran a
        // query returning rows, and produced an answer must PASS on deterministic
        // signals — every stage scores at or above the row-pass threshold.
        $row = new EvalRow(
            question: 'How many widgets?',
            questionType: 'data_query',
            selectedEntities: ['Widget'],
            cypher: 'MATCH (w:Widget) RETURN count(w)',
            rowCount: 1,
            quEntityTypes: ['Widget'],
            answer: 'There are 42 widgets.'
        );

        $this->attribution->attribute($row);

        $this->assertSame('passed', $row->verdictLabel);
        foreach ($row->stageScores as $score) {
            $this->assertGreaterThanOrEqual(0.6, $score);
        }
    }

    /** @test */
    public function an_unanswered_data_query_fails_on_signals_alone(): void
    {
        // No verdict input: retrieval selected an entity but generated no cypher,
        // returned no rows, and produced no answer. The prompt/generation stages
        // score from context signals (empty answer → low), so the row is FAILED
        // and blamed on the weakest downstream stage.
        $row = new EvalRow(
            question: 'How many widgets?',
            questionType: 'data_query',
            selectedEntities: ['Widget'],
            cypher: null,
            rowCount: 0,
            quEntityTypes: ['Widget'],
            answer: ''
        );

        $this->attribution->attribute($row);

        $this->assertSame('failed', $row->verdictLabel);
        $this->assertContains($row->weakestStage, ['prompt', 'generation']);
        $this->assertLessThan(0.6, $row->stageScores[$row->weakestStage]);
    }

    /** @test */
    public function export_from_previous_result_turns_do_not_blame_retrieval(): void
    {
        // iter-3 misdiagnosis: "Create a PDF of this." composed its export from
        // the PREVIOUS turn's sample (export_source=previous_result) — no
        // retrieval expected on the turn — yet retrieval was scored 0.28 and
        // blamed. Export-from-context turns must attribute retrieval neutrally.
        $row = new EvalRow(
            question: 'Create a PDF of this.',
            questionType: 'knowledge_query',
            selectedEntities: ['Unit', 'User', 'Team'],
            relevantFiles: [],
            cypher: null,
            rowCount: 0,
            quEntityTypes: [],
            answer: 'I prepared the PDF with the available information.'
        );
        $row->responseMetadata = [
            'export_intent' => true,
            'export_format' => 'pdf',
            'export_source' => 'previous_result',
            'export_document_markdown' => '# X',
        ];

        $this->attribution->attribute($row);

        $this->assertNotSame('retrieval', $row->weakestStage, 'retrieval must not be blamed on an export-from-previous turn');
        $this->assertGreaterThanOrEqual(0.6, $row->stageScores['retrieval']);
    }

    /** @test */
    public function identity_export_turns_score_retrieval_neutrally_too(): void
    {
        $row = new EvalRow(
            question: 'Can you create a pdf of my information?',
            questionType: 'data_query',
            selectedEntities: [],
            relevantFiles: [],
            cypher: 'MATCH (u:User) RETURN u',
            rowCount: 0,
            quEntityTypes: ['User'],
            answer: 'I prepared the PDF.'
        );
        $row->responseMetadata = [
            'export_intent' => true,
            'export_format' => 'pdf',
            'export_source' => 'current_identity',
        ];

        $this->attribution->attribute($row);

        $this->assertGreaterThanOrEqual(0.6, $row->stageScores['retrieval']);
        $this->assertNotSame('retrieval', $row->weakestStage);
    }

    /** @test */
    public function a_current_result_export_still_scores_retrieval_from_its_signals(): void
    {
        // The neutral attribution is scoped to previous_result/current_identity:
        // a cold export ran real retrieval + execution and stays accountable.
        $row = new EvalRow(
            question: 'Excel of the roster',
            questionType: 'data_query',
            selectedEntities: [],
            relevantFiles: [],
            cypher: null,
            rowCount: 0,
            quEntityTypes: [],
            answer: 'x'
        );
        $row->responseMetadata = [
            'export_intent' => true,
            'export_format' => 'excel',
            'export_source' => 'current_result',
        ];

        $this->attribution->attribute($row);

        // contextIsEmpty short-circuits — but even that path blames a real stage,
        // proving the neutral score did NOT apply to current_result exports.
        $this->assertContains($row->weakestStage, ['understanding', 'retrieval']);
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
    public function aggregate_reflects_the_offline_flag_from_run_meta(): void
    {
        $row = new EvalRow(
            stageScores: ['understanding' => 0.9, 'retrieval' => 0.8, 'prompt' => 0.5, 'generation' => 0.5]
        );

        // "offline" now means the run skipped response generation (the --offline
        // CLI flag), carried on the run meta — not any judge/verdict state.
        $offlineReport = $this->attribution->aggregate([$row], [], ['offline' => true]);
        $this->assertTrue($offlineReport->offline);

        $onlineReport = $this->attribution->aggregate([$row], [], ['offline' => false]);
        $this->assertFalse($onlineReport->offline);

        $defaultReport = $this->attribution->aggregate([$row], []);
        $this->assertFalse($defaultReport->offline);
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

    // ------------------------------------------------------------------
    // Gate/verdict reconciliation: all-green gates override a soft 'failed'
    // ------------------------------------------------------------------

    private function passingGate(): array
    {
        return ['gate' => 'produces_file', 'expected' => 'excel', 'actual' => 'excel', 'passed' => true, 'detail' => ''];
    }

    private function failingGate(): array
    {
        return ['gate' => 'min_rows', 'expected' => 1, 'actual' => 0, 'passed' => false, 'detail' => '0 rows'];
    }

    /** @test */
    public function a_soft_failed_verdict_with_all_gates_passing_is_reconciled_to_passed(): void
    {
        // The observed harness bug: an export turn whose artifact evidence passed
        // every declared gate still carried verdict_label 'failed' from
        // neutral-signal scoring.
        $row = new EvalRow(verdictLabel: 'failed');
        $row->gates = [$this->passingGate(), $this->passingGate()];

        $this->attribution->reconcileGateVerdict($row);

        $this->assertSame('passed', $row->verdictLabel);
        $this->assertStringContainsString('Reconciled', $row->blameReason);
    }

    /** @test */
    public function reconciliation_never_touches_rows_without_gates(): void
    {
        $row = new EvalRow(verdictLabel: 'failed');

        $this->attribution->reconcileGateVerdict($row);

        $this->assertSame('failed', $row->verdictLabel);
    }

    /** @test */
    public function reconciliation_never_upgrades_a_gate_failed_or_stage_error_row(): void
    {
        $gateFailed = new EvalRow(verdictLabel: 'gate-failed');
        $gateFailed->gates = [$this->passingGate(), $this->failingGate()];
        $this->attribution->reconcileGateVerdict($gateFailed);
        $this->assertSame('gate-failed', $gateFailed->verdictLabel);

        $stageError = new EvalRow(verdictLabel: 'stage-error');
        $stageError->gates = [$this->passingGate()];
        $this->attribution->reconcileGateVerdict($stageError);
        $this->assertSame('stage-error', $stageError->verdictLabel);
    }

    private function vacuousGate(): array
    {
        return [
            'gate' => 'no_self_contact',
            'expected' => true,
            'actual' => ['email_present' => false],
            'passed' => true,
            'detail' => '',
            'vacuous' => true,
        ];
    }

    /** @test */
    public function reconciliation_requires_at_least_one_triggered_gate_pass(): void
    {
        // Every declared gate passed only VACUOUSLY (condition never fired):
        // nothing was actually verified, so the soft verdict must stand.
        $row = new EvalRow(verdictLabel: 'failed', blameReason: 'weak signals');
        $row->gates = [$this->vacuousGate(), $this->vacuousGate()];

        $this->attribution->reconcileGateVerdict($row);

        $this->assertSame('failed', $row->verdictLabel);

        // One TRIGGERED pass beside the vacuous one is real evidence — upgrade.
        $mixed = new EvalRow(verdictLabel: 'failed');
        $mixed->gates = [$this->vacuousGate(), $this->passingGate()];

        $this->attribution->reconcileGateVerdict($mixed);

        $this->assertSame('passed', $mixed->verdictLabel);
    }

    /** @test */
    public function reconciliation_leaves_passed_rows_untouched(): void
    {
        $row = new EvalRow(verdictLabel: 'passed', blameReason: 'fine');
        $row->gates = [$this->passingGate()];

        $this->attribution->reconcileGateVerdict($row);

        $this->assertSame('passed', $row->verdictLabel);
        $this->assertSame('fine', $row->blameReason);
    }
}
