<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs\Digest;

/**
 * The outcome of ONE attention source inside a digest run.
 *
 * Error semantics (design §3 step 4 — the single statement):
 *  - `error` set    → the PRIMARY failed (throw / execution error / coverage-gate
 *    safe-stop): the section renders as one honest "could not be checked" line,
 *    never as honest-empty and never as digest death;
 *  - `fallbackError` set → the primary succeeded with 0 rows and the FALLBACK
 *    failed: the section renders as the primary's honest-empty (the truth) plus
 *    a small "recent-history context could not be loaded" note.
 */
class DigestSourceResult
{
    public function __construct(
        /** Registry key of the source (e.g. 'open_tasks'). */
        public readonly string $key,
        /** Section heading, host language. */
        public readonly string $label,
        /** One sentence of intent, rendered into the composition prompt. */
        public readonly string $description = '',
        /** The rows to render (fallback rows when $usedFallback). @var array<int, array<string, mixed>> */
        public readonly array $rows = [],
        /** count($rows). */
        public readonly int $rowCount = 0,
        /** TRUE population total when the companion count established one (cap-hit only); null = unknown/complete. */
        public readonly ?int $trueTotal = null,
        /** Whether the row cap was hit (so the composer hedges "more may exist" when the total stayed unknown). */
        public readonly bool $capHit = false,
        /** Whether $rows came from the fallback query — rendered ONLY under $fallbackLabel. */
        public readonly bool $usedFallback = false,
        /** Mandatory heading for fallback rows (never the primary label). */
        public readonly ?string $fallbackLabel = null,
        /** Generic action for the section link: [label](action://{actionKey}). */
        public readonly ?string $actionKey = null,
        /** Graph label for per-row entity-action enrichment. */
        public readonly ?string $entity = null,
        /** Host-configured note for sources whose emptiness is itself the news. */
        public readonly ?string $emptyText = null,
        /** PRIMARY failure — one honest error line, never honest-empty (§3 step 4). */
        public readonly ?string $error = null,
        /** FALLBACK failure — honest-empty + note, never a failed section (§3 step 4). */
        public readonly ?string $fallbackError = null,
        /**
         * The cypher that produced $rows (the FALLBACK cypher when $usedFallback): the
         * composer maps its projections to entity properties — declared money columns
         * (MoneyColumns) and enum legends (CodedColumns), p12 H2/H3.
         */
        public readonly ?string $cypher = null,
    ) {
    }

    /**
     * Clone with replacement rows (entity-action enrichment seam).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function withRows(array $rows): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            description: $this->description,
            rows: $rows,
            rowCount: count($rows),
            trueTotal: $this->trueTotal,
            capHit: $this->capHit,
            usedFallback: $this->usedFallback,
            fallbackLabel: $this->fallbackLabel,
            actionKey: $this->actionKey,
            entity: $this->entity,
            emptyText: $this->emptyText,
            error: $this->error,
            fallbackError: $this->fallbackError,
            cypher: $this->cypher,
        );
    }

    /**
     * Whether this source counts as EMPTY for the digest's "is anything
     * pending" framing. A fallback source still counts as empty — its rows are
     * context, not pending items (design §5.1).
     */
    public function countsAsEmpty(): bool
    {
        return $this->error === null && ($this->usedFallback || $this->rowCount === 0);
    }
}
