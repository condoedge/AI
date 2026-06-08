<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\Contracts\LlmProviderInterface;

/**
 * LlmReranker
 *
 * A model-agnostic, LLM-as-reranker. It scores the candidate pool against the
 * query with a SINGLE cheap structured (chatJson) call at temperature 0, then
 * returns the best $limit candidates re-ordered by the model's relevance score.
 *
 * Design constraints (see RerankerInterface):
 *  - Model-agnostic: depends only on LlmProviderInterface; no provider/model
 *    names appear anywhere.
 *  - One call: all candidate snippets are scored in a single prompt to keep it
 *    cheap (no per-candidate calls).
 *  - Hard cap: at most self::DEFAULT_MAX_CANDIDATES candidates are ever sent to
 *    the model. Extra candidates beyond the cap keep their input order and are
 *    appended after the scored ones (so nothing is silently dropped before the
 *    final truncation).
 *  - Never throws into the pipeline: ANY exception, or an empty / unparseable /
 *    out-of-range scoring response, degrades gracefully to the INPUT order.
 *
 * The scoring response is expected to be a JSON object of the form:
 *   { "scores": [ { "index": <int>, "score": <number 0..1> }, ... ] }
 * but the parser is deliberately lenient (accepts a bare list, "ranking",
 * "results", string indices, etc.) and ignores anything it cannot understand.
 */
final class LlmReranker implements RerankerInterface
{
    /**
     * Maximum number of candidates ever sent to the model in one scoring call.
     * Beyond this, candidates keep their input order (cheap + bounded prompt).
     */
    private const DEFAULT_MAX_CANDIDATES = 30;

    /**
     * Maximum characters of each candidate's text included in the prompt.
     * Keeps the single scoring call bounded regardless of snippet length.
     */
    private const SNIPPET_CHARS = 500;

    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly int $maxCandidates = self::DEFAULT_MAX_CANDIDATES
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function rerank(string $query, array $candidates, callable $textOf, int $limit): array
    {
        if ($limit < 0) {
            $limit = 0;
        }

        // Normalize to a clean 0..n indexed list. This index is the stable
        // identity used to map model scores back to candidates AND is the
        // ground-truth fallback ordering.
        $candidates = array_values($candidates);
        $total = count($candidates);

        // Nothing to do — preserve input order semantics.
        if ($total === 0 || $limit === 0) {
            return array_slice($candidates, 0, $limit);
        }

        // The pool actually scored by the model is capped. Anything beyond the
        // cap keeps its input order and is appended after the scored block.
        $cap = $this->maxCandidates > 0 ? $this->maxCandidates : self::DEFAULT_MAX_CANDIDATES;
        $scoredCount = min($total, $cap);

        // Build the snippet list for the scored window only.
        $snippets = [];
        for ($i = 0; $i < $scoredCount; $i++) {
            $snippets[$i] = $this->snippetOf($candidates[$i], $textOf);
        }

        // Attempt scoring. On ANY failure, fall back to input order.
        $order = $this->scoreOrder($query, $snippets);

        if ($order === null) {
            return array_slice($candidates, 0, $limit);
        }

        // Reassemble: scored block (model order) + any over-cap tail (input order).
        $reordered = [];
        foreach ($order as $i) {
            $reordered[] = $candidates[$i];
        }
        for ($i = $scoredCount; $i < $total; $i++) {
            $reordered[] = $candidates[$i];
        }

        return array_slice($reordered, 0, $limit);
    }

    /**
     * Ask the model to score the snippets and return a re-ordered list of the
     * scored-window indices (0..scoredCount-1), best first.
     *
     * Returns null on any failure so the caller can fall back to input order.
     *
     * @param array<int,string> $snippets index => snippet text
     * @return array<int,int>|null Ordered, complete permutation of array_keys($snippets), or null
     */
    private function scoreOrder(string $query, array $snippets): ?array
    {
        if (empty($snippets)) {
            return null;
        }

        try {
            $messages = $this->buildMessages($query, $snippets);

            $response = $this->llm->chatJson($messages, [
                'temperature' => 0.0,
                'max_tokens' => 500,
            ]);

            $scores = $this->extractScores($response, count($snippets));

            if (empty($scores)) {
                return null;
            }

            return $this->orderFromScores($scores, count($snippets));
        } catch (\Throwable $e) {
            // Reranking must never break the pipeline.
            return null;
        }
    }

    /**
     * Build a single, model-agnostic scoring prompt.
     *
     * @param array<int,string> $snippets
     * @return array<int,array{role:string,content:string}>
     */
    private function buildMessages(string $query, array $snippets): array
    {
        $lines = [];
        foreach ($snippets as $i => $text) {
            $lines[] = "[{$i}] " . $text;
        }
        $candidateBlock = implode("\n", $lines);

        $system = 'You are a precise search re-ranker. You score how relevant each '
            . 'candidate passage is to the user query. Respond with JSON only.';

        $user = "Query:\n{$query}\n\n"
            . "Candidates (each prefixed by its [index]):\n{$candidateBlock}\n\n"
            . "Score EVERY candidate from 0.0 (irrelevant) to 1.0 (highly relevant) "
            . "based only on how well it answers the query. "
            . 'Return JSON of the exact form: '
            . '{"scores":[{"index":0,"score":0.0}, ...]} '
            . 'with one entry per candidate index.';

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * Leniently extract index => score pairs from a structured response.
     *
     * Accepts:
     *   { "scores": [ {"index":0,"score":0.9}, ... ] }
     *   { "ranking": [ ... ] } / { "results": [ ... ] }
     *   [ {"index":0,"score":0.9}, ... ]          (bare list)
     *   { "0": 0.9, "1": 0.2 }                     (map of index => score)
     *
     * Out-of-range or non-numeric entries are ignored. Returns [] if nothing
     * usable was found.
     *
     * @param object|array $response
     * @return array<int,float> index => score
     */
    private function extractScores(object|array $response, int $count): array
    {
        $data = is_object($response) ? (array) $response : $response;

        // Find the list of {index, score} entries under common keys.
        $list = null;
        foreach (['scores', 'ranking', 'results', 'rankings'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $list = $data[$key];
                break;
            }
        }

        // Bare list of entries.
        if ($list === null && array_is_list($data)) {
            $list = $data;
        }

        $scores = [];

        if (is_array($list)) {
            foreach ($list as $entry) {
                $entry = is_object($entry) ? (array) $entry : $entry;
                if (!is_array($entry)) {
                    continue;
                }
                if (!isset($entry['index']) || !is_numeric($entry['index'])) {
                    continue;
                }
                $idx = (int) $entry['index'];
                if ($idx < 0 || $idx >= $count) {
                    continue;
                }
                $score = isset($entry['score']) && is_numeric($entry['score'])
                    ? (float) $entry['score']
                    : 0.0;
                $scores[$idx] = $score;
            }
        }

        // Fallback shape: { "0": 0.9, "1": 0.2 } (map of index => numeric score).
        if (empty($scores)) {
            foreach ($data as $k => $v) {
                if (is_numeric($k) && is_numeric($v)) {
                    $idx = (int) $k;
                    if ($idx >= 0 && $idx < $count) {
                        $scores[$idx] = (float) $v;
                    }
                }
            }
        }

        return $scores;
    }

    /**
     * Turn an index => score map into a complete, ordered permutation of all
     * indices [0..count-1], best score first. Indices the model omitted are
     * treated as score 0.0 and appended in their original order, so the result
     * is always a full permutation (no candidate is dropped here).
     *
     * @param array<int,float> $scores
     * @return array<int,int>
     */
    private function orderFromScores(array $scores, int $count): array
    {
        $ordered = [];
        for ($i = 0; $i < $count; $i++) {
            $ordered[$i] = $scores[$i] ?? 0.0;
        }

        // Stable sort by score desc; PHP's array functions aren't stable, so
        // sort on [score, -index] to keep input order among equal scores.
        $indices = array_keys($ordered);
        usort($indices, function ($a, $b) use ($ordered) {
            $cmp = $ordered[$b] <=> $ordered[$a];
            if ($cmp !== 0) {
                return $cmp;
            }
            return $a <=> $b; // preserve input order on ties
        });

        return $indices;
    }

    /**
     * Extract and bound a candidate's text for the prompt.
     */
    private function snippetOf(mixed $candidate, callable $textOf): string
    {
        try {
            $text = (string) $textOf($candidate);
        } catch (\Throwable $e) {
            $text = '';
        }

        $text = trim($text);
        if (mb_strlen($text) > self::SNIPPET_CHARS) {
            $text = mb_substr($text, 0, self::SNIPPET_CHARS);
        }

        return $text;
    }
}
