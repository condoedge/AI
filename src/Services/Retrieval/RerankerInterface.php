<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

/**
 * RerankerInterface
 *
 * A precision-oriented re-ordering stage applied AFTER candidate retrieval and
 * access filtering, but BEFORE the final top-N truncation. Given a query and a
 * pool of candidates (typically over-fetched, e.g. limit*3), a reranker scores
 * each candidate against the query and returns the best $limit, re-ordered by
 * (query, candidate-text) relevance.
 *
 * Contract requirements (all implementations MUST honor these):
 *  - Model-agnostic: no provider/model names leak into the abstraction.
 *  - Skippable & safe: implementations MUST NEVER throw into the pipeline. On
 *    any error, timeout, or empty/garbage scoring response, they MUST fall back
 *    to returning the INPUT order (truncated to $limit). The retrieval pipeline
 *    must behave identically to "no reranker" when reranking is unavailable.
 *  - Order-preserving fallback: the relative order of the input candidates is
 *    the ground-truth fallback ordering.
 *
 * @template TCandidate
 */
interface RerankerInterface
{
    /**
     * Re-order candidates by (query, text) relevance and return the top $limit.
     *
     * @param string   $query      The user query / search string to rank against.
     * @param array    $candidates The candidate pool to re-rank. Opaque to this
     *                             interface — the caller supplies $textOf to pull
     *                             the comparable text out of each candidate.
     * @param callable $textOf     fn(mixed $candidate): string — extracts the text
     *                             representation of a candidate used for scoring.
     * @param int      $limit      Maximum number of candidates to return.
     * @return array The top-$limit candidates, re-ordered by relevance. On any
     *               failure this is exactly the first $limit of the input order.
     */
    public function rerank(string $query, array $candidates, callable $textOf, int $limit): array;
}
