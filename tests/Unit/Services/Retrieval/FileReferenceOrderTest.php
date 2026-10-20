<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Services\Retrieval\FileReferenceOrder;
use PHPUnit\Framework\TestCase;

/**
 * The single file-reference ordering rule. Scores from different scales
 * (cross-collection RRF, raw cosine) are NEVER compared with
 * each other: a scale TIER is the first key, the tier's own score the second,
 * the host priority_score only a tiebreak, and input order breaks full ties.
 */
class FileReferenceOrderTest extends TestCase
{
    private function ids(array $refs): array
    {
        return array_column(FileReferenceOrder::sort($refs), 'id');
    }

    /** @test */
    public function without_fusion_it_is_pure_relevance_order(): void
    {
        $this->assertSame(['b', 'c', 'a'], $this->ids([
            ['id' => 'a', 'relevance' => 0.41],
            ['id' => 'b', 'relevance' => 0.90],
            ['id' => 'c', 'relevance' => 0.70],
        ]));
    }

    /** @test */
    public function fused_items_order_by_fusion_score_never_by_raw_relevance(): void
    {
        // RRF values (~0.03) are not on the cosine scale: fused items are ordered
        // among themselves by fusion_score, not by their raw relevance.
        $this->assertSame(['f-top', 'f-second', 'f-third'], $this->ids([
            ['id' => 'f-second', 'relevance' => 0.90, 'fusion_score' => 0.020],
            ['id' => 'f-third', 'relevance' => 0.95, 'fusion_score' => 0.010],
            ['id' => 'f-top', 'relevance' => 0.50, 'fusion_score' => 0.033],
        ]));
    }

    /** @test */
    public function an_unfused_filename_match_leads_fused_content_without_comparing_scales(): void
    {
        // In a fused retrieval the only unfused items are explicit filename
        // matches (never fused). They led before tiering (RRF is tiny) and still
        // do — by TIER, not by comparing 0.85 with 0.033.
        $this->assertSame(['by-name-weak', 'fused-a', 'fused-b'], $this->ids([
            ['id' => 'fused-b', 'relevance' => 0.99, 'fusion_score' => 0.020, 'match_type' => 'content'],
            ['id' => 'fused-a', 'relevance' => 0.60, 'fusion_score' => 0.033, 'match_type' => 'content'],
            ['id' => 'by-name-weak', 'relevance' => 0.30, 'match_type' => 'filename'],
        ]));
    }

    /** @test */
    public function unfused_filename_and_content_matches_compete_on_score(): void
    {
        // No fusion: one raw scale, so a 0.9 content hit precedes a 0.85 partial
        // filename match (unchanged behaviour).
        $this->assertSame(['content', 'by-name'], $this->ids([
            ['id' => 'by-name', 'relevance' => 0.85, 'match_type' => 'filename'],
            ['id' => 'content', 'relevance' => 0.90, 'match_type' => 'content'],
        ]));
    }

    /** @test */
    public function priority_score_is_only_a_tiebreak(): void
    {
        $this->assertSame(['law', 'tie-hi', 'tie-lo', 'team'], $this->ids([
            ['id' => 'team', 'relevance' => 0.41, 'priority_score' => 1.41],
            ['id' => 'tie-lo', 'relevance' => 0.60, 'priority_score' => 0.60],
            ['id' => 'law', 'relevance' => 0.80, 'priority_score' => 1.30],
            ['id' => 'tie-hi', 'relevance' => 0.60, 'priority_score' => 1.60],
        ]));
    }

    /** @test */
    public function full_ties_keep_input_order(): void
    {
        $refs = [];
        foreach (range(1, 12) as $i) {
            $refs[] = ['id' => "r{$i}", 'relevance' => 0.5, 'priority_score' => 1.0];
        }

        $this->assertSame(array_column($refs, 'id'), $this->ids($refs));
    }

    /** @test */
    public function raw_search_results_order_by_their_score_key(): void
    {
        // Pre-transform search results carry 'score' instead of 'relevance'.
        $this->assertSame(['x', 'y'], $this->ids([
            ['id' => 'y', 'score' => 0.5],
            ['id' => 'x', 'score' => 0.8],
        ]));
    }

    /** @test */
    public function primary_score_is_the_tier_score(): void
    {
        $this->assertSame(0.02, FileReferenceOrder::primaryScore(['relevance' => 0.9, 'fusion_score' => 0.02]));
        $this->assertSame(0.9, FileReferenceOrder::primaryScore(['relevance' => 0.9]));
        $this->assertSame(0.0, FileReferenceOrder::primaryScore([]));
    }
}
