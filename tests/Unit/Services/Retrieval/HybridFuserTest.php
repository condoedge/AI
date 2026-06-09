<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Services\Retrieval\HybridFuser;
use Condoedge\Ai\Tests\TestCase;

/**
 * Unit tests for HybridFuser: Reciprocal Rank Fusion and the BM25-style lexical
 * channel used to fold a sparse signal into the dense semantic ranking.
 */
class HybridFuserTest extends TestCase
{
    private HybridFuser $fuser;

    public function setUp(): void
    {
        parent::setUp();
        $this->fuser = new HybridFuser();
    }

    // =====================================================================
    // RRF fusion
    // =====================================================================

    /** @test */
    public function it_ranks_a_document_top_when_both_channels_agree(): void
    {
        $dense = ['a', 'b', 'c'];
        $sparse = ['a', 'c', 'b'];

        $fused = $this->fuser->fuse($dense, $sparse);

        // 'a' is rank 1 in both lists -> highest RRF score -> first.
        $this->assertSame('a', $fused[0]);
        $this->assertCount(3, $fused);
    }

    /** @test */
    public function it_promotes_a_document_only_the_sparse_channel_ranks_high(): void
    {
        // Dense buries 'z' at the bottom; sparse ranks it first (exact lexical match).
        $dense = ['a', 'b', 'c', 'd', 'z'];
        $sparse = ['z'];

        $fused = $this->fuser->fuse($dense, $sparse);

        // 'z' gains 1/(60+1) from sparse on top of its dense contribution, lifting it
        // above the mid/low dense-only entries.
        $zPos = array_search('z', $fused, true);
        $dPos = array_search('d', $fused, true);
        $this->assertLessThan($dPos, $zPos, 'sparse-favored doc should outrank a low dense-only doc');
    }

    /** @test */
    public function it_returns_the_union_of_keys_across_channels(): void
    {
        $fused = $this->fuser->fuse(['a', 'b'], ['b', 'c']);

        sort($fused);
        $this->assertSame(['a', 'b', 'c'], $fused);
    }

    /** @test */
    public function it_preserves_first_seen_order_on_ties(): void
    {
        // Each key appears exactly once at rank 1 of its own single-item list, so all
        // fused scores are equal; ties must fall back to first-seen (input) order.
        $fused = $this->fuser->fuseRankings([['x'], ['y'], ['z']]);

        $this->assertSame(['x', 'y', 'z'], $fused);
    }

    /** @test */
    public function rrf_score_is_computed_from_rank_not_score(): void
    {
        // Two channels, lengths differ; verify the exact RRF arithmetic for the top key.
        // 'a': rank 1 in both -> 1/61 + 1/61. 'b': rank 2 dense + rank 1 sparse missing.
        $fuser = new HybridFuser(60);
        $fused = $fuser->fuse(['a', 'b'], ['a']);

        $this->assertSame('a', $fused[0]);
        $this->assertSame('b', $fused[1]);
    }

    /** @test */
    public function smaller_k_sharpens_the_top_rank_advantage(): void
    {
        // With a tiny k, rank-1 dominance is amplified; 'top' (rank1 sparse, rank3 dense)
        // should beat 'mid' (rank1 dense, absent sparse).
        $fuser = new HybridFuser(1);
        $dense = ['mid', 'other', 'top'];
        $sparse = ['top'];

        $fused = $fuser->fuse($dense, $sparse);

        $topPos = array_search('top', $fused, true);
        $midPos = array_search('mid', $fused, true);
        // top: 1/(1+3) + 1/(1+1) = 0.25 + 0.5 = 0.75 ; mid: 1/(1+1) = 0.5
        $this->assertLessThan($midPos, $topPos);
    }

    // =====================================================================
    // Lexical (BM25-ish) channel
    // =====================================================================

    /** @test */
    public function lexical_ranking_surfaces_exact_token_matches(): void
    {
        $documents = [
            'Invoice' => 'Invoice invoices billing',
            'Person'  => 'Person people member',
            'Order'   => 'Order orders purchase',
        ];

        $ranking = $this->fuser->lexicalRanking('show me the invoices', $documents);

        $this->assertSame('Invoice', $ranking[0]);
    }

    /** @test */
    public function lexical_ranking_drops_zero_overlap_candidates(): void
    {
        $documents = [
            'Invoice' => 'Invoice invoices billing',
            'Person'  => 'Person people member',
        ];

        // Query overlaps only the Invoice lexical field.
        $ranking = $this->fuser->lexicalRanking('invoice', $documents);

        $this->assertSame(['Invoice'], $ranking);
    }

    /** @test */
    public function lexical_scores_are_empty_for_no_overlap(): void
    {
        $documents = ['Person' => 'Person people member'];

        $scores = $this->fuser->lexicalScores('quarterly revenue', $documents);

        $this->assertSame([], $scores);
    }

    /** @test */
    public function lexical_scores_are_empty_for_empty_query(): void
    {
        $documents = ['Person' => 'Person people member'];

        $this->assertSame([], $this->fuser->lexicalScores('', $documents));
        $this->assertSame([], $this->fuser->lexicalScores('   ', $documents));
    }

    /** @test */
    public function idf_down_weights_pool_common_terms(): void
    {
        // 'team' appears in every document (uninformative); 'volunteers' is distinctive.
        $documents = [
            'TeamA' => 'team alpha',
            'TeamB' => 'team beta',
            'TeamC' => 'team volunteers',
        ];

        $scores = $this->fuser->lexicalScores('team volunteers', $documents);

        // TeamC matches the rare distinctive term too, so it must score highest.
        arsort($scores);
        $this->assertSame('TeamC', array_key_first($scores));
    }

    /** @test */
    public function tokenizer_is_case_insensitive_and_splits_on_punctuation(): void
    {
        $documents = ['E' => 'Customer-Account'];

        $ranking = $this->fuser->lexicalRanking('account', $documents);

        $this->assertSame(['E'], $ranking);
    }

    /** @test */
    public function single_character_tokens_are_ignored(): void
    {
        // 'a' is a 1-char token and must not produce a match on its own.
        $documents = ['X' => 'a b c'];

        $scores = $this->fuser->lexicalScores('a', $documents);

        $this->assertSame([], $scores);
    }
}
