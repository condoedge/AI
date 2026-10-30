<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Support\AiLogger;

/**
 * CalculationMatcher (Task 5.11)
 *
 * Decides which host-defined canonical calculations (ai.calculations) the question
 * asks for, by EMBEDDING similarity between the question phrasings (the raw question
 * and the Query-Understanding rewrite) and each calculation's "label — description".
 * A calculation matches when its best similarity clears `minSimilarity` AND lies
 * within `margin` of the best-matching calculation — so "receivables outstanding"
 * picks the receivables formula alone instead of every money formula.
 *
 * The matched calculations are the only ones rendered, and their labels seed the
 * entity selection (a metric the question asks for must have its entities in the
 * schema even when Query Understanding named other entity types). A verdict of
 * "none asked for" renders no calculation at all.
 *
 * Calculation embeddings are cached (they only change with the config). An
 * embedding failure means "cannot judge" (null): callers fall back to label overlap.
 */
final class CalculationMatcher
{
    public const DEFAULT_MIN_SIMILARITY = 0.45;
    public const DEFAULT_MARGIN = 0.03;
    private const CACHE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly EmbeddingProviderInterface $embedding,
        private readonly AiLogger $logger,
        private readonly float $minSimilarity = self::DEFAULT_MIN_SIMILARITY,
        private readonly float $margin = self::DEFAULT_MARGIN,
    ) {}

    public static function fromConfig(EmbeddingProviderInterface $embedding, AiLogger $logger): self
    {
        return new self(
            $embedding,
            $logger,
            (float) config('ai.calculation_matching.min_similarity', self::DEFAULT_MIN_SIMILARITY),
            (float) config('ai.calculation_matching.margin', self::DEFAULT_MARGIN),
        );
    }

    /**
     * @param array<int|string, mixed> $calculations ai.calculations
     * @param string[] $queries question phrasings (deduplicated, blanks ignored)
     * @return array<string, float>|null matched calculation key => similarity, best first;
     *                                    [] = judged: the question asks for none;
     *                                    null = could not judge (nothing to compare, embedding failed)
     */
    public function match(array $calculations, array $queries): ?array
    {
        $texts = [];
        foreach ($calculations as $key => $calc) {
            if (is_array($calc)) {
                $text = self::textOf($key, $calc);
                if ($text !== '') {
                    $texts[(string) $key] = $text;
                }
            }
        }

        $queries = array_values(array_unique(array_filter(
            array_map(fn ($q) => is_string($q) ? trim($q) : '', $queries),
            fn ($q) => $q !== ''
        )));

        if ($texts === [] || $queries === []) {
            return null;
        }

        try {
            $calcVectors = $this->calculationVectors($texts);
            $queryVectors = array_map(fn (string $q) => $this->embedding->embed($q), $queries);
        } catch (\Throwable $e) {
            $this->logger->debug('calculation_matcher', 'embedding failed, cannot judge', ['error' => $e->getMessage()]);

            return null;
        }

        $scores = [];
        foreach ($calcVectors as $key => $vector) {
            $best = -1.0;
            foreach ($queryVectors as $queryVector) {
                $best = max($best, self::cosine($queryVector, $vector));
            }
            $scores[$key] = $best;
        }
        arsort($scores);

        $top = reset($scores);
        if ($top === false || $top < $this->minSimilarity) {
            return [];
        }

        return array_filter(
            $scores,
            fn (float $score) => $score >= $this->minSimilarity && $score >= $top - $this->margin
        );
    }

    /**
     * The matches the asker can run: a calculation reading an entity outside $readable goes
     * (the model would improvise around it), replaced by its 'reachable_alternative' when it
     * declares one the asker can read. out_of_reach = something matched and nothing is left:
     * what the question asks for is not this asker's to read.
     *
     * @param array<string, float> $matched
     * @param array<int|string, mixed> $calculations
     * @param list<string>|null $readable the asker's entity labels; null = no limit
     * @return array{matched: array<string, float>, out_of_reach: bool}
     */
    public static function withinReach(array $matched, array $calculations, ?array $readable): array
    {
        if ($readable === null || $matched === []) {
            return ['matched' => $matched, 'out_of_reach' => false];
        }

        $reachable = fn (string $key): bool => is_array($calculations[$key] ?? null)
            && array_diff(self::labelsOf($calculations[$key]), $readable) === [];

        $kept = [];
        foreach ($matched as $key => $score) {
            $alternative = $calculations[$key]['reachable_alternative'] ?? null;
            $use = $reachable((string) $key) ? (string) $key : (is_string($alternative) && $reachable($alternative) ? $alternative : null);
            if ($use !== null) {
                $kept[$use] = max($score, $kept[$use] ?? $score);
            }
        }

        return ['matched' => $kept, 'out_of_reach' => $kept === []];
    }

    /**
     * The text a calculation is matched on: "label — description".
     */
    public static function textOf(int|string $key, array $calc): string
    {
        $label = is_string($calc['label'] ?? null) && trim($calc['label']) !== ''
            ? trim($calc['label'])
            : (is_string($key) ? $key : '');
        $description = is_string($calc['description'] ?? null) ? trim($calc['description']) : '';

        return trim($description !== '' ? "{$label} — {$description}" : $label);
    }

    /**
     * A calculation's graph labels: its explicit 'entities', else the node labels its
     * cypher MATCHes ((i:Invoice) → Invoice). Structural — no phrase matching.
     *
     * @return string[]
     */
    public static function labelsOf(array $calc): array
    {
        if (is_array($calc['entities'] ?? null)) {
            return array_values(array_filter($calc['entities'], fn ($l) => is_string($l) && $l !== ''));
        }

        $cypher = $calc['cypher'] ?? null;
        if (!is_string($cypher) || $cypher === '') {
            return [];
        }

        preg_match_all('/\(\s*\w*\s*:\s*`?([A-Za-z_][A-Za-z0-9_]*)/', $cypher, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @param array<string, string> $texts key => text
     * @return array<string, float[]>
     */
    private function calculationVectors(array $texts): array
    {
        $vectors = [];
        $missing = [];
        foreach ($texts as $key => $text) {
            $cached = $this->cacheGet($text);
            if ($cached !== null) {
                $vectors[$key] = $cached;
            } else {
                $missing[$key] = $text;
            }
        }

        if ($missing !== []) {
            $embedded = $this->embedding->embedBatch(array_values($missing));
            foreach (array_keys($missing) as $i => $key) {
                $vector = $embedded[$i] ?? null;
                if (!is_array($vector) || $vector === []) {
                    continue;
                }
                $vectors[$key] = $vector;
                $this->cachePut($missing[$key], $vector);
            }
        }

        return array_intersect_key(array_replace(array_fill_keys(array_keys($texts), null), $vectors), $vectors);
    }

    private function cacheKey(string $text): string
    {
        return 'ai:calc_embedding:' . md5($this->embedding->getModel() . '|' . $text);
    }

    private function cacheGet(string $text): ?array
    {
        try {
            $value = function_exists('cache') ? cache()->get($this->cacheKey($text)) : null;
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($value) && $value !== [] ? $value : null;
    }

    private function cachePut(string $text, array $vector): void
    {
        try {
            if (function_exists('cache')) {
                cache()->put($this->cacheKey($text), $vector, self::CACHE_TTL_SECONDS);
            }
        } catch (\Throwable $e) {
            // Best effort: an uncached vector is simply re-embedded next turn.
        }
    }

    /**
     * @param float[] $a
     * @param float[] $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $x) {
            $y = (float) ($b[$i] ?? 0.0);
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        return $na > 0.0 && $nb > 0.0 ? $dot / sqrt($na * $nb) : 0.0;
    }
}
