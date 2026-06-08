<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs\Eval;

/**
 * Reference-free LLM-as-judge verdict for a single answer.
 *
 * Each dimension is scored 0.0-1.0. The judge decomposes the answer into
 * claims and grades faithfulness/grounding/relevance/usefulness, plus a
 * model-agnostic / privacy compliance flag (the answer must not name the
 * underlying LLM vendor or dump raw schema).
 *
 * When the judge cannot run (no API key / offline), {@see self::skipped()}
 * produces a neutral, non-throwing verdict carrying a reason.
 */
class JudgeVerdict
{
    public function __construct(
        /** 0-1: do the answer's claims follow from the provided data/files? */
        public readonly float $faithfulness = 0.0,
        /** 0-1: is every claim grounded in retrieved context (no fabrication)? */
        public readonly float $grounding = 0.0,
        /** 0-1: does the answer actually address the question asked? */
        public readonly float $relevance = 0.0,
        /** 0-1: is the answer useful/actionable to the asker? */
        public readonly float $usefulness = 0.0,
        /** True when the answer leaks the LLM vendor/model or raw schema. */
        public readonly bool $disclosesModelOrSchema = false,
        /** @var array<int, array{claim?: string, grounded?: bool, reason?: string}> Per-claim breakdown */
        public readonly array $claims = [],
        /** Short free-text rationale from the judge. */
        public readonly string $reason = '',
        /** True when the judge could not run and scores are neutral placeholders. */
        public readonly bool $skipped = false,
    ) {}

    /**
     * Neutral verdict used when the judge is unavailable (offline / no key).
     * Scores stay at 0 but {@see $skipped} signals attribution to treat this
     * as "not judged" rather than "judged poorly".
     */
    public static function skipped(string $reason = 'judge unavailable'): self
    {
        return new self(skipped: true, reason: $reason);
    }

    /**
     * Mean of the four quality dimensions (0-1). Returns 0 when skipped.
     */
    public function generationScore(): float
    {
        if ($this->skipped) {
            return 0.0;
        }

        return round(
            ($this->faithfulness + $this->grounding + $this->relevance + $this->usefulness) / 4,
            4
        );
    }

    public function toArray(): array
    {
        return [
            'faithfulness' => $this->faithfulness,
            'grounding' => $this->grounding,
            'relevance' => $this->relevance,
            'usefulness' => $this->usefulness,
            'discloses_model_or_schema' => $this->disclosesModelOrSchema,
            'claims' => $this->claims,
            'reason' => $this->reason,
            'skipped' => $this->skipped,
            'generation_score' => $this->generationScore(),
        ];
    }
}
