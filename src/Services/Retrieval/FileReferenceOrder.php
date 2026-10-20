<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

/**
 * The single ordering rule for file references (relevant_files) and for the
 * raw file-search results they are built from.
 *
 * Scores on DIFFERENT scales are never compared with each other — a
 * cross-collection rank-fusion score (RRF, ~0.01-0.05) and a raw cosine
 * similarity mean different things. So the first key is a scale TIER:
 *
 *   1. RAW       — raw similarity only (relevance, or 'score' pre-transform);
 *   0. FUSED     — numeric fusion_score (FileSearchService merged collections).
 *
 * RAW above FUSED is deliberate: FileSearchService sets fusion_score on EVERY
 * content result of a fused search, so inside one fused retrieval the only
 * unfused items are explicit FILENAME matches (never fused). Those led the
 * fused content before tiering too (RRF values ~0.03 are below any filename
 * score), and the user named the file. In an unfused retrieval everything is
 * RAW and filename/content matches compete on score, exactly as before.
 *
 * Then, within a tier: the tier's own score DESC; then priority_score DESC (a
 * host authority boost is a TIEBREAK only — a boosted 0.41 team file never
 * outranks a 0.80 law chunk); then input order (PHP >= 8.0 sorts are stable).
 *
 * With no fusion (the default today) this is pure relevance order with the
 * priority tiebreak.
 *
 * The FUSED tier only ever applies to FileContextProvider's raw per-angle
 * candidate pool (the cut to max_references). fusion_score is file-level and
 * relative to one angle's result lists, so it is never carried onto references
 * (Task 1.29): the final sort and AiManager's cross-angle merge see relevance
 * only, and a file's chunks are ordered by their own cosine.
 *
 * Shared by FileContextProvider (candidate-pool cut and final sort) and AiManager
 * (cross-angle merge).
 */
final class FileReferenceOrder
{
    private const TIER_RAW = 1;
    private const TIER_FUSED = 0;

    /**
     * @param array<int,array> $references
     * @return array<int,array>
     */
    public static function sort(array $references): array
    {
        usort($references, [self::class, 'compare']);

        return $references;
    }

    public static function compare(array $a, array $b): int
    {
        $byTier = self::tier($b) <=> self::tier($a);
        if ($byTier !== 0) {
            return $byTier;
        }

        $byScore = self::primaryScore($b) <=> self::primaryScore($a);
        if ($byScore !== 0) {
            return $byScore;
        }

        return (float) ($b['priority_score'] ?? 0.0) <=> (float) ($a['priority_score'] ?? 0.0);
    }

    /**
     * The reference's score on its own tier's scale (see class doc).
     */
    public static function primaryScore(array $reference): float
    {
        return match (self::tier($reference)) {
            self::TIER_FUSED => (float) $reference['fusion_score'],
            default => self::rawScore($reference),
        };
    }

    private static function tier(array $reference): int
    {
        if (is_numeric($reference['fusion_score'] ?? null)) {
            return self::TIER_FUSED;
        }

        return self::TIER_RAW;
    }

    private static function rawScore(array $reference): float
    {
        return (float) ($reference['relevance'] ?? $reference['score'] ?? 0.0);
    }
}
