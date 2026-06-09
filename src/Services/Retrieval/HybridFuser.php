<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

/**
 * HybridFuser
 *
 * Fuses a dense (vector-similarity) ranking with a sparse/lexical ranking using
 * Reciprocal Rank Fusion (RRF). RRF combines result lists by RANK, not by score:
 *
 *     fused(d) = Σ_i  1 / (k + rank_i(d))
 *
 * where rank_i is the 1-based position of document d in list i (best == 1) and k
 * is a smoothing constant (≈60 is the de-facto default in Elastic/Weaviate/Qdrant).
 * Fusing by rank sidesteps the incompatible-scale problem: BM25/lexical scores and
 * cosine similarities live on different scales and cannot be linearly added without
 * per-query calibration, but their *ranks* are directly comparable.
 *
 * The lexical channel here is a lightweight, dependency-free BM25-style token-overlap
 * scorer over short metadata text (entity names / aliases / scope phrases). It is NOT
 * a full inverted-index BM25 — it is the right size for the small, in-memory candidate
 * pools the retriever produces (tens of entities, not a corpus). It captures exact
 * lexical matches — names, aliases, IDs, acronyms — that a paraphrase-oriented embedding
 * can miss, lifting recall on the head/tail queries where dense retrieval is weak.
 *
 * The class is pure and deterministic: no I/O, no model calls, no config reads. The
 * caller decides whether to apply fusion (config-gated) and supplies both rankings.
 */
final class HybridFuser
{
    /**
     * Default RRF smoothing constant. Larger k flattens the contribution of top
     * ranks (more democratic fusion); ≈60 is the widely-adopted default.
     */
    public const DEFAULT_RRF_K = 60;

    /**
     * BM25 term-frequency saturation parameter. Bounds the gain from repeated
     * terms so a candidate cannot dominate purely by length/repetition.
     */
    private const BM25_K1 = 1.2;

    /**
     * BM25 length-normalization parameter (0 = none, 1 = full). Modest by default
     * because the lexical fields here (names/aliases/scopes) are already short.
     */
    private const BM25_B = 0.5;

    public function __construct(
        private readonly int $rrfK = self::DEFAULT_RRF_K,
    ) {
    }

    /**
     * Fuse any number of ranked key-lists into a single ranking via RRF.
     *
     * Each input is an ordered list of opaque keys, best-first (rank 1 = first
     * element). A key may appear in some lists and not others; missing lists
     * simply contribute nothing for that key. Ties in the fused score preserve
     * the order in which keys were first encountered across the input lists, so
     * fusion is deterministic.
     *
     * @param array<int, array<int, string>> $rankings One ordered key-list per channel.
     * @return array<int, string> Keys ordered best-first by fused RRF score.
     */
    public function fuseRankings(array $rankings): array
    {
        $k = $this->rrfK;
        $fused = [];
        $firstSeen = [];
        $order = 0;

        foreach ($rankings as $ranking) {
            $rank = 0;
            foreach (array_values($ranking) as $key) {
                $rank++;
                if (!array_key_exists($key, $fused)) {
                    $fused[$key] = 0.0;
                    $firstSeen[$key] = $order++;
                }
                $fused[$key] += 1.0 / ($k + $rank);
            }
        }

        $keys = array_keys($fused);
        usort($keys, function ($a, $b) use ($fused, $firstSeen) {
            $cmp = $fused[$b] <=> $fused[$a];
            if ($cmp !== 0) {
                return $cmp;
            }
            // Stable: keep first-seen order among equal fused scores.
            return $firstSeen[$a] <=> $firstSeen[$b];
        });

        return $keys;
    }

    /**
     * Convenience wrapper that fuses exactly a dense ranking and a sparse ranking.
     *
     * @param array<int, string> $denseRanking  Keys ordered best-first by dense score.
     * @param array<int, string> $sparseRanking Keys ordered best-first by lexical score.
     * @return array<int, string> Keys ordered best-first by fused RRF score.
     */
    public function fuse(array $denseRanking, array $sparseRanking): array
    {
        return $this->fuseRankings([$denseRanking, $sparseRanking]);
    }

    /**
     * Score a set of candidates against the query by lexical token overlap (BM25-ish)
     * and return them ordered best-first.
     *
     * $documents maps an opaque candidate key to its lexical text (e.g. an entity's
     * name + aliases + scope phrases joined into one string). Candidates with zero
     * query-term overlap score 0 and are dropped from the ranking — the sparse
     * channel only contributes positive lexical signal; absence is not evidence.
     *
     * @param string                  $query     The raw/normalized user query.
     * @param array<string, string>   $documents key => lexical text for that candidate.
     * @return array<int, string> Candidate keys ordered best-first by lexical score (matches only).
     */
    public function lexicalRanking(string $query, array $documents): array
    {
        $scores = $this->lexicalScores($query, $documents);

        $keys = array_keys($scores);
        $original = array_keys($documents);
        $position = array_flip($original);

        usort($keys, function ($a, $b) use ($scores, $position) {
            $cmp = $scores[$b] <=> $scores[$a];
            if ($cmp !== 0) {
                return $cmp;
            }
            // Stable: preserve the caller's document order among equal scores.
            return ($position[$a] ?? 0) <=> ($position[$b] ?? 0);
        });

        return $keys;
    }

    /**
     * Compute BM25-style lexical relevance of each candidate to the query.
     *
     * Returns only candidates with a positive score (>0); a candidate sharing no
     * query term is omitted entirely. The scorer treats $documents as the local
     * "corpus" so IDF is computed over the candidate pool — common-across-pool
     * terms are down-weighted, distinctive terms up-weighted.
     *
     * @param string                $query     The query text.
     * @param array<string, string> $documents key => lexical text.
     * @return array<string, float> key => positive BM25 score (unordered).
     */
    public function lexicalScores(string $query, array $documents): array
    {
        $queryTerms = $this->tokenize($query);
        if (empty($queryTerms) || empty($documents)) {
            return [];
        }

        // Tokenize each document once; track term frequencies and lengths.
        $docTokens = [];
        $totalLength = 0;
        foreach ($documents as $key => $text) {
            $tokens = $this->tokenize($text);
            $docTokens[$key] = $tokens;
            $totalLength += count($tokens);
        }

        $docCount = count($docTokens);
        $avgLength = $docCount > 0 ? $totalLength / $docCount : 0.0;

        // Document frequency per UNIQUE query term across the candidate pool.
        $queryTermSet = array_unique($queryTerms);
        $docFreq = [];
        foreach ($queryTermSet as $term) {
            $df = 0;
            foreach ($docTokens as $tokens) {
                if (in_array($term, $tokens, true)) {
                    $df++;
                }
            }
            $docFreq[$term] = $df;
        }

        $scores = [];
        foreach ($docTokens as $key => $tokens) {
            $length = count($tokens);
            if ($length === 0) {
                continue;
            }

            $tf = array_count_values($tokens);
            $score = 0.0;

            foreach ($queryTermSet as $term) {
                if (!isset($tf[$term])) {
                    continue;
                }

                $idf = $this->idf($docFreq[$term], $docCount);
                $f = $tf[$term];

                $normLength = $avgLength > 0 ? ($length / $avgLength) : 1.0;
                $denominator = $f + self::BM25_K1 * (1 - self::BM25_B + self::BM25_B * $normLength);
                $score += $idf * (($f * (self::BM25_K1 + 1)) / ($denominator ?: 1.0));
            }

            if ($score > 0.0) {
                $scores[$key] = $score;
            }
        }

        return $scores;
    }

    /**
     * Robertson-Sparck-Jones IDF with a +1 shift so it stays non-negative even
     * when a term appears in every candidate (df == docCount).
     */
    private function idf(int $docFreq, int $docCount): float
    {
        if ($docCount <= 0) {
            return 0.0;
        }

        return (float) log(1 + (($docCount - $docFreq + 0.5) / ($docFreq + 0.5)));
    }

    /**
     * Lowercase, split on non-alphanumeric boundaries, drop empties and 1-char
     * noise. Deliberately language-agnostic and list-free: no hardcoded stopword
     * dictionary — IDF over the candidate pool naturally discounts pool-common
     * terms, which is the model-agnostic equivalent of stopword removal.
     *
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }

        return array_values(array_filter($parts, static fn(string $t): bool => mb_strlen($t) > 1));
    }
}
