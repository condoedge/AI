<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

/**
 * The outcome of {@see \Condoedge\Ai\Services\Retrieval\EntitySelectionPruner::prune()}:
 * the graph labels kept for the query-generation prompt, in tier order (seed →
 * scope → identity → tenant → closure → ownership; vector only as a no-seed
 * fallback), with why each one is there.
 */
final class PrunedEntitySelection
{
    /**
     * @param string[] $labels ordered, unique graph labels (capped)
     * @param array<string, string> $tiers label => tier constant
     * @param array<string, string> $reasons label => human-readable reason
     * @param string[] $identityLabels identity labels included (subset of $labels)
     * @param string[] $dropped labels that qualified but were cut by the cap
     */
    public function __construct(
        public readonly array $labels = [],
        public readonly array $tiers = [],
        public readonly array $reasons = [],
        public readonly array $identityLabels = [],
        public readonly array $dropped = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->labels === [];
    }
}
