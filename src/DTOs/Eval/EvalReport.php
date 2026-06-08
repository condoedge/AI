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
        /** True when the run was offline (judge skipped everywhere). */
        public bool $offline = false,
        /** Wall-clock metadata: user, counts, etc. */
        public array $meta = [],
    ) {}

    public function passed(): bool
    {
        return empty($this->failedStages);
    }

    public function totalRows(): int
    {
        return count($this->rows);
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
            'total_rows' => $this->totalRows(),
            'meta' => $this->meta,
            'rows' => array_map(fn (EvalRow $r) => $r->toArray(), $this->rows),
        ];
    }
}
