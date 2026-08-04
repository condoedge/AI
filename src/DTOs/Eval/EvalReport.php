<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs\Eval;

/**
 * Aggregated result of an ai:eval run.
 *
 * Holds every {@see EvalRow}, the per-stage average scorecard, the single
 * "most failing stage" headline, and a pass/fail decision derived from the
 * configured per-stage thresholds.
 */
class EvalReport
{
    public function __construct(
        /** @var EvalRow[] */
        public array $rows = [],
        /**
         * Per-stage average score 0-1, keyed:
         * understanding|retrieval|prompt|generation.
         * @var array<string, float>
         */
        public array $scorecard = [],
        /** The stage with the lowest average score (the headline blame). */
        public ?string $weakestStage = null,
        /**
         * How many rows blamed each stage.
         * @var array<string, int>
         */
        public array $blameCounts = [],
        /** @var array<string, float> Per-stage threshold the scorecard is checked against. */
        public array $thresholds = [],
        /** @var string[] Stages whose average fell below their threshold. */
        public array $failedStages = [],
        /** True when the run was offline (response generation skipped). */
        public bool $offline = false,
        /** Wall-clock metadata: user, counts, etc. */
        public array $meta = [],
    ) {}

    public function passed(): bool
    {
        return empty($this->failedStages) && $this->gateFailures() === [];
    }

    public function totalRows(): int
    {
        return count($this->rows);
    }

    /**
     * Every failed fact-gate across all rows. Computed from the rows (no extra
     * mutable state) so aggregate() stays untouched; a non-empty list makes the
     * run FAIL regardless of stage averages — gates are hard requirements.
     *
     * @return array<int, array{conversation_id: ?string, turn_index: int, question: string, gate: string, detail: string}>
     */
    public function gateFailures(): array
    {
        $failures = [];
        foreach ($this->rows as $row) {
            foreach ($row->gates as $gate) {
                if (($gate['passed'] ?? true) === false) {
                    $failures[] = [
                        'conversation_id' => $row->conversationId,
                        'turn_index' => $row->turnIndex,
                        'question' => $row->question,
                        'gate' => (string) ($gate['gate'] ?? ''),
                        'detail' => (string) ($gate['detail'] ?? ''),
                    ];
                }
            }
        }

        return $failures;
    }

    /**
     * Rows grouped by suite conversation, IN TURN ORDER, each turn carrying its
     * verbatim standard — the navigation index an out-of-process grading agent
     * uses before drilling into the full per-row traces.
     *
     * @return array<int, array{id: string, role: ?string, turns: array}>
     */
    public function conversationGroups(): array
    {
        $groups = [];
        foreach ($this->rows as $row) {
            if ($row->conversationId === null || $row->conversationId === '') {
                continue;
            }
            $groups[$row->conversationId] ??= [
                'id' => $row->conversationId,
                'role' => $row->role,
                'turns' => [],
            ];
            $groups[$row->conversationId]['turns'][] = [
                'turn_index' => $row->turnIndex,
                'question' => $row->question,
                'standard' => $row->standard,
                'verdict_label' => $row->verdictLabel,
                'gates' => $row->gates,
            ];
        }

        foreach ($groups as &$group) {
            usort($group['turns'], fn ($a, $b) => $a['turn_index'] <=> $b['turn_index']);
        }

        return array_values($groups);
    }

    public function toArray(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'offline' => $this->offline,
            'passed' => $this->passed(),
            'scorecard' => $this->scorecard,
            'weakest_stage' => $this->weakestStage,
            'blame_counts' => $this->blameCounts,
            'thresholds' => $this->thresholds,
            'failed_stages' => $this->failedStages,
            'gate_failures' => $this->gateFailures(),
            'conversations' => $this->conversationGroups(),
            'total_rows' => $this->totalRows(),
            'meta' => $this->meta,
            'rows' => array_map(fn (EvalRow $r) => $r->toArray(), $this->rows),
        ];
    }
}
