<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

/**
 * NullReranker
 *
 * Identity reranker: preserves the input order and simply truncates to $limit.
 * This is the safe default used whenever reranking is disabled (config off) or
 * no concrete reranker is bound in the container. It encodes the contract's
 * "fallback is the input order" guarantee as a first-class, dependency-free
 * implementation so the pipeline can always resolve *some* RerankerInterface.
 */
final class NullReranker implements RerankerInterface
{
    /**
     * {@inheritDoc}
     */
    public function rerank(string $query, array $candidates, callable $textOf, int $limit): array
    {
        if ($limit < 0) {
            $limit = 0;
        }

        // Re-index to a clean 0..n list, then truncate. Never throws.
        return array_slice(array_values($candidates), 0, $limit);
    }
}
