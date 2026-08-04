<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Eval;

use Condoedge\Ai\DTOs\Eval\EvalReport;
use Condoedge\Ai\DTOs\Eval\EvalRow;

/**
 * PURE-PHP, deterministic per-stage failure attribution.
 *
 * Given an {@see EvalRow} (its cheap deterministic pipeline signals), it scores
 * each of the four stages 0-1 and blames exactly ONE weakest stage with a short
 * reason. It does NOT call any LLM or external service, so it is fully
 * unit-testable offline — this is the deterministic heart of the harness.
 *
 * Stages and the signals they are scored from:
 *
 *   understanding — QU predicted entity types / sensible question type.
 *                   Blamed when QU produced nothing yet the question clearly
 *                   needed data (qu mismatch).
 *   retrieval     — were entities selected, files found, rows returned, and
 *                   was context quality decent? Blamed on empty selection /
 *                   row_count 0 / low quality.
 *   prompt        — proxy for "was there context for the prompt to ground in?"
 *                   scored from deterministic context signals.
 *   generation    — proxy for "did the pipeline produce a usable answer?"
 *                   scored from deterministic context signals.
 *
 * The lowest-scoring stage (with a deterministic tie-break order) is the
 * weakest stage. Hard outcomes (context-not-retrieved, stage-error) short-circuit
 * to the responsible stage.
 */
class AttributionService
{
    /** Deterministic tie-break / earliest-stage-first ordering. */
    public const STAGES = ['understanding', 'retrieval', 'prompt', 'generation'];

    public function __construct(private readonly array $config = []) {}

    /**
     * Score the four stages for a single row and assign blame.
     * Mutates and returns the row (stageScores / weakestStage / blameReason /
     * verdictLabel get populated). Pure: no I/O.
     */
    public function attribute(EvalRow $row): EvalRow
    {
        // ---- Hard verdicts short-circuit to the responsible stage ----
        if ($row->verdictLabel === 'stage-error' && $row->erroredStage !== null) {
            $row->stageScores = $this->zeroFrom($row->erroredStage);
            $row->weakestStage = $row->erroredStage;
            $row->blameReason = "Stage '{$row->erroredStage}' threw: " . $this->trim($row->error);
            return $row;
        }

        if (($row->verdictLabel === 'context-not-retrieved' || $row->contextIsEmpty())
            && !$this->isDigestTurn($row)
            && !$this->isClarifyTurn($row)) {
            // DIGEST EXEMPTION (G(ii), iteration-11 verdict): a digest turn
            // legitimately has no selected entities, no files and no cypher —
            // the config-declared source walk replaces retrieval and generation
            // entirely — so "empty context" is its NORMAL shape, never a
            // context-not-retrieved verdict. Digest rows fall through to the
            // soft scoring below (where understanding is also exempted).
            // CLARIFY EXEMPTION (same class, other pipeline-owning branch): a
            // clarify turn by DESIGN skips file search and query generation
            // (the clarifying question rode the single QU call), so no files /
            // no cypher / 0 rows is ITS normal shape too — never a
            // context-not-retrieved verdict.
            // Nothing was retrieved. Decide whether understanding or retrieval is at fault:
            // if QU also produced nothing for a data question, understanding is upstream cause.
            $blame = $this->emptyContextBlame($row);
            $row->stageScores = [
                'understanding' => $blame === 'understanding' ? 0.0 : 0.5,
                'retrieval' => 0.0,
                'prompt' => 0.0,
                'generation' => 0.0,
            ];
            $row->weakestStage = $blame;
            $row->verdictLabel = 'context-not-retrieved';
            $row->blameReason = $blame === 'understanding'
                ? 'No context retrieved and query understanding produced no entity types for a data question.'
                : 'No context retrieved (no entities selected, no files, no cypher).';
            return $row;
        }

        // ---- Soft scoring from deterministic signals ----
        $scores = [
            'understanding' => $this->scoreUnderstanding($row),
            'retrieval' => $this->scoreRetrieval($row),
            'prompt' => $this->scorePrompt($row),
            'generation' => $this->scoreGeneration($row),
        ];

        $row->stageScores = $scores;

        $weakest = $this->weakest($scores);
        $row->weakestStage = $weakest;
        $row->blameReason = $this->reasonFor($weakest, $row, $scores);

        $passThreshold = (float) ($this->config['row_pass_threshold'] ?? 0.6);
        $row->verdictLabel = $scores[$weakest] >= $passThreshold ? 'passed' : 'failed';

        return $row;
    }

    /**
     * Reconcile the soft-score verdict with the deterministic fact-gates AFTER
     * they were evaluated: a turn whose declared gates ALL passed must not carry
     * verdict_label 'failed' from neutral-signal scoring alone (observed live:
     * an export turn whose artifact evidence passed every gate was still labeled
     * 'failed' by the context-signal proxy — a contradiction in the report).
     * Hard verdicts (stage-error / context-not-retrieved / gate-failed) carry
     * real diagnostic signal and are never touched; rows with no gates keep the
     * soft verdict unchanged.
     *
     * VACUOUS passes are not evidence: a conditional gate whose condition never
     * fired (marked 'vacuous' => true by ExpectationGate) verified nothing, so
     * the upgrade requires at least one TRIGGERED pass — otherwise weak-signal
     * scoring stands.
     */
    public function reconcileGateVerdict(EvalRow $row): EvalRow
    {
        if ($row->gates === [] || $row->hasGateFailure() || $row->verdictLabel !== 'failed') {
            return $row;
        }

        $triggered = false;
        foreach ($row->gates as $gate) {
            if (($gate['passed'] ?? false) === true && empty($gate['vacuous'])) {
                $triggered = true;
                break;
            }
        }
        if (!$triggered) {
            return $row;
        }

        $row->verdictLabel = 'passed';
        $row->blameReason = trim($row->blameReason . ' (Reconciled to passed: every declared fact-gate passed and no stage error — neutral-signal scoring alone must not fail the turn.)');

        return $row;
    }

    /**
     * Aggregate a set of attributed rows into a report: per-stage averages,
     * the single most-failing stage, blame counts, and pass/fail vs thresholds.
     *
     * @param EvalRow[]              $rows
     * @param array<string, float>   $thresholds  Per-stage minimum averages.
     */
    public function aggregate(array $rows, array $thresholds = [], array $meta = []): EvalReport
    {
        $report = new EvalReport(rows: array_values($rows), thresholds: $thresholds, meta: $meta);

        if (empty($rows)) {
            $report->scorecard = array_fill_keys(self::STAGES, 0.0);
            return $report;
        }

        // Per-stage averages.
        $sums = array_fill_keys(self::STAGES, 0.0);
        $counts = array_fill_keys(self::STAGES, 0);
        $blameCounts = array_fill_keys(self::STAGES, 0);

        foreach ($rows as $row) {
            foreach (self::STAGES as $stage) {
                if (array_key_exists($stage, $row->stageScores)) {
                    $sums[$stage] += $row->stageScores[$stage];
                    $counts[$stage]++;
                }
            }
            if ($row->weakestStage !== null && isset($blameCounts[$row->weakestStage])) {
                $blameCounts[$row->weakestStage]++;
            }
        }

        $scorecard = [];
        foreach (self::STAGES as $stage) {
            $scorecard[$stage] = $counts[$stage] > 0
                ? round($sums[$stage] / $counts[$stage], 4)
                : 0.0;
        }

        $report->scorecard = $scorecard;
        $report->blameCounts = $blameCounts;
        // "offline" = the run skipped response generation (the --offline CLI flag).
        $report->offline = (bool) ($meta['offline'] ?? false);

        // Most-failing stage = lowest average (deterministic tie-break by STAGE order).
        $report->weakestStage = $this->weakest($scorecard);

        // Failed stages = averages below their configured threshold.
        $failed = [];
        foreach (self::STAGES as $stage) {
            $min = $thresholds[$stage] ?? null;
            if ($min !== null && $scorecard[$stage] < (float) $min) {
                $failed[] = $stage;
            }
        }
        $report->failedStages = $failed;

        return $report;
    }

    // ---------------------------------------------------------------------
    // Per-stage scoring
    // ---------------------------------------------------------------------

    private function scoreUnderstanding(EvalRow $row): float
    {
        // Digest turns don't need entity types — QU's digest_request judgment
        // routed them to the source walk, which never runs entity selection.
        // "Understanding produced no entity types" is KNOWN NOISE there (G(ii),
        // iteration-11 verdict) — the routing itself succeeding IS the
        // understanding signal.
        if ($this->isDigestTurn($row)) {
            return 0.9;
        }

        // Clarify turns don't need entity types either — QU judging the turn
        // genuinely ambiguous and composing the clarifying question IS the
        // understanding output for the turn.
        if ($this->isClarifyTurn($row)) {
            return 0.9;
        }

        // Knowledge queries don't need entity types — treat understanding as fine
        // unless the answer is irrelevant (handled by generation).
        if ($row->questionType === 'knowledge_query') {
            return 0.9;
        }

        // Data question: reward having predicted some entity types and a coherent
        // outcome (selection happened). qu mismatch = predicted nothing but the
        // question clearly required data.
        $predicted = !empty($row->quEntityTypes);
        $selected = !empty($row->selectedEntities) || !empty($row->cypher);

        if ($predicted && $selected) {
            return 0.95;
        }
        if ($predicted && !$selected) {
            return 0.6; // understood, but selection failed downstream (retrieval owns that)
        }
        if (!$predicted && $selected) {
            return 0.55; // selection rescued a weak QU
        }
        return 0.25; // predicted nothing AND nothing selected → understanding mismatch
    }

    private function scoreRetrieval(EvalRow $row): float
    {
        // Export-from-context turns ("give me that as an Excel") compose from the
        // PREVIOUS turn's result or the current identity — NO retrieval is expected
        // on this turn, so scoring retrieval from its (correctly absent) signals
        // would misdiagnose the scorecard. Attribute neutrally instead.
        if ($this->isExportFromContext($row)) {
            return 0.7;
        }

        // Digest turns run NO retrieval/selection by design (the source walk
        // replaces it); score from what the walk actually produced instead of
        // punishing the absent selection signals.
        if ($this->isDigestTurn($row)) {
            return $row->rowCount > 0 ? 0.8 : 0.5;
        }

        // Clarify turns stop BEFORE file search and query generation by design
        // (the branch answers with QU's clarifying question) — like exports
        // from context, NO retrieval is expected, so attribute neutrally.
        if ($this->isClarifyTurn($row)) {
            return 0.7;
        }

        $score = 0.0;
        $hasEntities = !empty($row->selectedEntities);
        $hasFiles = !empty($row->relevantFiles);
        $hasCypher = !empty($row->cypher);

        // Knowledge queries are satisfied by files alone.
        if ($row->questionType === 'knowledge_query') {
            $score = $hasFiles ? 0.9 : 0.2;
            return round(min(1.0, $score + ($row->contextQuality * 0.1)), 4);
        }

        // Data queries: selection + a runnable query + rows returned all matter.
        if ($hasEntities || $hasCypher) {
            $score += 0.4;
        }
        if ($hasCypher) {
            $score += 0.2;
        }
        if ($row->rowCount > 0) {
            $score += 0.3; // the query actually returned data
        } elseif ($hasCypher) {
            $score += 0.0; // query ran but row_count 0 → likely retrieval/selection miss
        }
        // Context quality nudges within the remaining headroom.
        $score += min(0.1, $row->contextQuality * 0.1);
        if ($hasFiles) {
            $score = max($score, 0.5);
        }

        return round(min(1.0, $score), 4);
    }

    private function scorePrompt(EvalRow $row): float
    {
        // Deterministic proxy: was there context for the prompt to ground in,
        // and did the pipeline produce an answer? Grounding/faithfulness are not
        // measured in-process — that grading is done out-of-process by an agent.
        return $this->neutralFromContext($row);
    }

    private function scoreGeneration(EvalRow $row): float
    {
        return $this->neutralFromContext($row);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Derive a neutral (not punitive) score from deterministic context signals
     * so the prompt/generation stages still rank sensibly without in-process grading.
     */
    private function neutralFromContext(EvalRow $row): float
    {
        if ($row->answer === '') {
            return 0.3;
        }
        // A clarify turn's answer IS the deterministic clarifying question —
        // there is never data context to ground in, so scoring it from
        // rowCount/cypher would punish the branch for working as designed.
        if ($this->isClarifyTurn($row)) {
            return 0.7;
        }
        if ($row->questionType === 'knowledge_query') {
            return empty($row->relevantFiles) ? 0.4 : 0.7;
        }
        if ($row->rowCount > 0) {
            return 0.7;
        }
        return !empty($row->cypher) ? 0.5 : 0.4;
    }

    /**
     * Whether this row's final response was an export composed from conversation
     * context (previous turn's result) or the current identity — turns where NO
     * retrieval is expected, so retrieval must not be blamed for absent signals.
     */
    private function isExportFromContext(EvalRow $row): bool
    {
        $meta = $row->responseMetadata;

        return ($meta['export_intent'] ?? false) === true
            && in_array($meta['export_source'] ?? '', ['previous_result', 'current_identity'], true);
    }

    /**
     * Whether this row's final response came from the attention-digest branch
     * (response_metadata.digest — stamped by DigestResponseComposer). Digest
     * turns legitimately skip entity selection, file search and query
     * generation, so the empty-context heuristics and the "no entity types"
     * understanding signal are exempted for them (G(ii)).
     */
    private function isDigestTurn(EvalRow $row): bool
    {
        return is_array($row->responseMetadata['digest'] ?? null);
    }

    /**
     * Whether this row's final response came from the clarify branch
     * (response_metadata.clarification — stamped by AiManager::clarifyResponse).
     * Clarify turns legitimately skip file search and query generation (the
     * clarifying question rode the single QU call), so the empty-context
     * heuristics and the "no entity types" understanding signal are exempted
     * for them — the exact mirror of the digest exemption.
     */
    private function isClarifyTurn(EvalRow $row): bool
    {
        return is_array($row->responseMetadata['clarification'] ?? null);
    }

    /**
     * Decide blame when no context was retrieved at all.
     */
    private function emptyContextBlame(EvalRow $row): string
    {
        // Knowledge query with no files → retrieval (docs not indexed/found).
        if ($row->questionType === 'knowledge_query') {
            return 'retrieval';
        }
        // Data question where QU itself produced no entity types → understanding
        // is the upstream cause; otherwise retrieval failed to select.
        return empty($row->quEntityTypes) ? 'understanding' : 'retrieval';
    }

    /**
     * Lowest score wins blame; ties broken by earliest pipeline stage.
     *
     * @param array<string, float> $scores
     */
    private function weakest(array $scores): string
    {
        $weakest = self::STAGES[0];
        $min = $scores[$weakest] ?? 1.0;

        foreach (self::STAGES as $stage) {
            if (!array_key_exists($stage, $scores)) {
                continue;
            }
            if ($scores[$stage] < $min) {
                $min = $scores[$stage];
                $weakest = $stage;
            }
        }

        return $weakest;
    }

    /** @param array<string, float> $scores */
    private function reasonFor(string $stage, EvalRow $row, array $scores): string
    {
        $score = $scores[$stage] ?? 0.0;

        return match ($stage) {
            'understanding' => empty($row->quEntityTypes) && !$this->isDigestTurn($row) && !$this->isClarifyTurn($row)
                ? 'Query understanding produced no entity types for a data question (mismatch).'
                : sprintf('Weakest stage understanding (%.2f).', $score),
            'retrieval' => $this->isClarifyTurn($row)
                ? 'Clarify turn — the branch answers with the clarifying question; no retrieval expected on this turn.'
                : ($this->isExportFromContext($row)
                ? 'Export composed from conversation/identity context — no retrieval expected on this turn.'
                : ($row->rowCount === 0 && !empty($row->cypher)
                    ? 'Query ran but returned 0 rows — retrieval/selection likely missed the target.'
                    : (empty($row->selectedEntities) && empty($row->relevantFiles)
                        ? 'No entities selected and no files found.'
                        : sprintf('Weakest stage retrieval (%.2f).', $score)))),
            'prompt' => sprintf('Weakest stage prompt (%.2f) from context signals — grounding graded out-of-process.', $score),
            'generation' => sprintf('Weakest stage generation (%.2f) from context signals — answer quality graded out-of-process.', $score),
            default => sprintf('Weakest stage %s (%.2f).', $stage, $score),
        };
    }

    /** @return array<string, float> */
    private function zeroFrom(string $stage): array
    {
        $scores = array_fill_keys(self::STAGES, 1.0);
        // Everything from the failing stage onward is unscored/zero.
        $hit = false;
        foreach (self::STAGES as $s) {
            if ($s === $stage) {
                $hit = true;
            }
            if ($hit) {
                $scores[$s] = 0.0;
            }
        }
        return $scores;
    }

    private function trim(string $message, int $max = 120): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        return strlen($message) > $max ? substr($message, 0, $max) . '...' : $message;
    }
}
