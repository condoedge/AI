<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Services\Retrieval\PassageBranchCoherence;
use PHPUnit\Framework\TestCase;

/**
 * Branch coherence inside ONE document (eval p17-B rights t0).
 *
 * A structured document can title a section the same way in two of its parts (a
 * regulation with a "DUTIES OF THE OPERATOR" section under both "LEASED AIRCRAFT"
 * and "OWNED AIRCRAFT"). A question about one part then retrieves passages of
 * both, and the answer serves a rule of the other part as if it applied. When the
 * candidate passages sit in different branches that hold a same-titled section,
 * only the branch the search angles' vote groups prefer stays. Structure only:
 * heading paths and the angles' own rankings, no vocabulary.
 */
class PassageBranchCoherenceTest extends TestCase
{
    private const LEASED = ['AIRCRAFT RULES', 'PART II — LEASED AIRCRAFT', 'SECTION II — DUTIES OF THE OPERATOR'];
    private const OWNED = ['AIRCRAFT RULES', 'PART III — OWNED AIRCRAFT', 'SECTION IV — DUTIES OF THE OPERATOR'];
    private const OWNED_FUNDS = ['AIRCRAFT RULES', 'PART III — OWNED AIRCRAFT', 'SECTION V — MAINTENANCE RESERVE'];
    private const LEASED_END = ['AIRCRAFT RULES', 'PART II — LEASED AIRCRAFT', 'SECTION IV — END OF THE LEASE'];

    private const PATHS = [
        8 => self::LEASED, 11 => self::LEASED, 25 => self::LEASED_END,
        58 => self::OWNED, 61 => self::OWNED, 74 => self::OWNED_FUNDS,
    ];

    /** One ballot per angle: its vote group and its passages of the file, best first. */
    private function ballot(string $group, int ...$picks): array
    {
        return ['group' => $group, 'picks' => $picks];
    }

    /** @test */
    public function the_branch_the_vote_groups_prefer_stays_and_the_same_titled_other_one_goes(): void
    {
        // Eval p17 shape: the plain wordings lead with the other part's same-titled
        // section; the clarified question and the probe are about the owned part.
        $verdict = PassageBranchCoherence::resolve(self::PATHS, [
            $this->ballot('search_query', 8, 58, 61),
            $this->ballot('question', 8, 11, 58),
            $this->ballot('clarified_question', 61, 58, 74),
            $this->ballot('probe_1', 58, 74, 61),
        ]);

        $this->assertNotNull($verdict);
        $this->assertEqualsCanonicalizing([8, 11, 25], $verdict['dropped'], 'every candidate of the dropped branch goes');
        $this->assertSame('PART III — OWNED AIRCRAFT', $verdict['kept']);
        $this->assertSame(['PART II — LEASED AIRCRAFT'], $verdict['dropped_branches']);
        $this->assertGreaterThan($verdict['weights']['PART II — LEASED AIRCRAFT'], $verdict['weights']['PART III — OWNED AIRCRAFT']);
    }

    /** @test */
    public function each_vote_group_casts_one_vote_whatever_the_number_of_its_passages_or_renderings(): void
    {
        // Four groups: weights sum to four, however many passages each ballot holds
        // and although the probe was written in two languages.
        $verdict = PassageBranchCoherence::resolve(self::PATHS, [
            $this->ballot('search_query', 58, 61, 74),
            $this->ballot('question', 8),
            $this->ballot('probe_1', 58),
            $this->ballot('probe_1', 61, 74),
            $this->ballot('probe_2', 74, 58),
        ]);

        $this->assertEqualsWithDelta(4.0, array_sum($verdict['weights']), 1e-9);
        $this->assertEqualsWithDelta(1.0, $verdict['weights']['PART II — LEASED AIRCRAFT'], 1e-9, 'the question group, whole');
        $this->assertSame([8, 11, 25], $verdict['dropped']);
    }

    /** @test */
    public function the_branch_the_angles_prefer_wins_even_when_the_other_one_has_more_passages(): void
    {
        // A question about the LEASED part: every angle leads with it and fills its
        // remaining places with the (larger) owned part. Counting passages would
        // keep the owned part; the angles' own order keeps the leased one.
        $paths = self::PATHS + [64 => self::OWNED, 82 => self::OWNED_FUNDS];

        $verdict = PassageBranchCoherence::resolve($paths, [
            $this->ballot('search_query', 8, 11, 58, 61, 74),
            $this->ballot('question', 11, 8, 58, 64, 82),
            $this->ballot('clarified_question', 8, 11, 61, 74, 82),
        ]);

        $this->assertSame('PART II — LEASED AIRCRAFT', $verdict['kept']);
        $this->assertEqualsCanonicalizing([58, 61, 64, 74, 82], $verdict['dropped']);
    }

    /** @test */
    public function a_ballots_first_passage_weighs_more_than_its_last(): void
    {
        $leading = PassageBranchCoherence::resolve(self::PATHS, [$this->ballot('question', 8, 58)], 0.5);
        $trailing = PassageBranchCoherence::resolve(self::PATHS, [$this->ballot('question', 58, 8)], 0.5);

        $this->assertSame('PART II — LEASED AIRCRAFT', $leading['kept']);
        $this->assertSame('PART III — OWNED AIRCRAFT', $trailing['kept']);
    }

    /** @test */
    public function branches_without_a_same_titled_section_are_both_kept(): void
    {
        // A question may legitimately span two parts of a document.
        $this->assertNull(PassageBranchCoherence::resolve(
            [25 => self::LEASED_END, 58 => self::OWNED, 74 => self::OWNED_FUNDS],
            [$this->ballot('question', 58, 74, 25), $this->ballot('search_query', 58, 25)]
        ));
    }

    /** @test */
    public function passages_of_one_branch_are_never_touched(): void
    {
        $this->assertNull(PassageBranchCoherence::resolve(
            [58 => self::OWNED, 61 => self::OWNED, 74 => self::OWNED_FUNDS],
            [$this->ballot('question', 58, 61, 74)]
        ));
    }

    /** @test */
    public function a_document_without_heading_paths_is_untouched(): void
    {
        $this->assertNull(PassageBranchCoherence::resolve([8 => [], 58 => []], [$this->ballot('question', 8, 58)]));
        $this->assertNull(PassageBranchCoherence::resolve([], []));
        $this->assertNull(PassageBranchCoherence::resolve(self::PATHS, []), 'no ballot, no vote to weigh');
    }

    /** @test */
    public function a_split_vote_keeps_both_branches(): void
    {
        // One group prefers each part: dropping one would be a coin flip.
        $this->assertNull(PassageBranchCoherence::resolve(self::PATHS, [
            $this->ballot('search_query', 58, 8),
            $this->ballot('question', 8, 58),
        ]));
    }

    /** @test */
    public function the_required_share_of_the_vote_is_a_parameter(): void
    {
        $ballots = [
            $this->ballot('search_query', 58, 61, 8),
            $this->ballot('question', 58, 61, 8),
            $this->ballot('clarified_question', 8, 58),
        ];

        // The owned part holds 1.92 of 3 votes (64 %).
        $this->assertSame('PART III — OWNED AIRCRAFT', PassageBranchCoherence::resolve(self::PATHS, $ballots)['kept']);
        $this->assertNull(PassageBranchCoherence::resolve(self::PATHS, $ballots, 0.75));
    }

    /** @test */
    public function a_passage_above_the_branching_level_belongs_to_no_branch_and_stays(): void
    {
        $verdict = PassageBranchCoherence::resolve(
            [0 => ['AIRCRAFT RULES']] + self::PATHS,
            [$this->ballot('question', 0, 58, 61, 8), $this->ballot('search_query', 58, 0, 74)]
        );

        $this->assertNotContains(0, $verdict['dropped']);
        $this->assertSame([8, 11, 25], $verdict['dropped']);
    }

    /** @test */
    public function a_third_branch_that_shares_no_title_with_the_kept_one_stays(): void
    {
        $general = ['AIRCRAFT RULES', 'PART I — GENERAL PROVISIONS'];

        $verdict = PassageBranchCoherence::resolve(
            [1 => $general] + self::PATHS,
            [$this->ballot('question', 58, 1, 8), $this->ballot('search_query', 58, 61, 1)]
        );

        $this->assertSame([8, 11, 25], $verdict['dropped']);
        $this->assertSame(['PART II — LEASED AIRCRAFT'], $verdict['dropped_branches']);
    }

    /** @test */
    public function documents_under_different_roots_still_branch_where_the_same_titled_sections_part(): void
    {
        $annex = ['ANNEX', 'TABLE OF FEES'];

        $verdict = PassageBranchCoherence::resolve(
            [200 => $annex] + self::PATHS,
            [$this->ballot('question', 58, 200, 8), $this->ballot('search_query', 61, 58, 200)]
        );

        $this->assertSame([8, 11, 25], $verdict['dropped']);
    }

    /**
     * @test
     * @dataProvider sameTitles
     */
    public function a_section_title_is_compared_without_its_numbering(string $a, string $b): void
    {
        $verdict = PassageBranchCoherence::resolve(
            [8 => ['DOC', 'PART A', $a], 58 => ['DOC', 'PART B', $b], 61 => ['DOC', 'PART B', $b]],
            [$this->ballot('question', 58, 61, 8)]
        );

        $this->assertNotNull($verdict, "'{$a}' and '{$b}' are the same title");
        $this->assertSame([8], $verdict['dropped']);
    }

    public static function sameTitles(): array
    {
        return [
            'label + roman numeral' => ['DIVISION II — DUTIES OF THE OPERATOR', 'DIVISION IV — DUTIES OF THE OPERATOR'],
            'paragraph sign' => ['§ 1. — Duties of the operator', '§ 3. — Duties of the operator'],
            'decimal numbering' => ['2.3 Duties of the operator', '4.1 Duties of the operator'],
            'colon' => ['Section 2: Duties of the operator', 'Section 7: Duties of the operator'],
            'no numbering, case and spacing' => ['Duties of the  operator', 'DUTIES OF THE OPERATOR'],
            'accents kept' => ['SECTION II — DES DEVOIRS DE L’EXPLOITANT', 'SECTION IV — Des devoirs de l’exploitant'],
        ];
    }

    /** @test */
    public function different_titles_under_the_same_numbering_do_not_collide(): void
    {
        $this->assertNull(PassageBranchCoherence::resolve(
            [8 => ['DOC', 'PART A', 'DIVISION II — DUTIES OF THE OPERATOR'], 58 => ['DOC', 'PART B', 'DIVISION II — DUTIES OF THE LESSOR']],
            [$this->ballot('question', 58, 8)],
            0.5
        ));
    }

    /** @test */
    public function a_heading_that_is_only_a_number_has_no_title_to_collide_on(): void
    {
        $this->assertNull(PassageBranchCoherence::resolve(
            [8 => ['DOC', 'PART A', 'SECTION 1'], 58 => ['DOC', 'PART B', 'SECTION 1']],
            [$this->ballot('question', 58, 8)],
            0.5
        ));
    }
}
