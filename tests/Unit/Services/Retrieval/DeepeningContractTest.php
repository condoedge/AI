<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Services\Retrieval\DeepeningContract;
use PHPUnit\Framework\TestCase;

/**
 * The deepening contract of a follow-up knowledge turn (eval p17-B rights t1).
 *
 * "Tell me more about X" retrieved the passage holding the NEW rule at rank 1-2 on
 * every angle, and the answer restated the previous turn's points instead: nothing
 * told the composer which of the passages the user had already been given. Code
 * computes it from the conversation memory (the passages and articles the previous
 * answer cited) against this turn's passages — article numbers from the passages'
 * own structure, never a vocabulary.
 */
class DeepeningContractTest extends TestCase
{
    private const LAW = 'physical:rules/aircraft.md';

    /** A passage record as the previous turn stored it (ResponseFileEnricher). */
    private function cited(int $chunk, string $snippet, array $extra = []): array
    {
        return ['ref' => 1, 'id' => self::LAW, 'name' => 'aircraft.md', 'snippet' => $snippet, 'chunk_index' => $chunk] + $extra;
    }

    /** A passage as this turn retrieved it. */
    private function passage(int $chunk, string $snippet, string $file = self::LAW): array
    {
        return ['file_id' => $file, 'filename' => basename($file), 'snippet' => $snippet, 'chunk_index' => $chunk, 'relevance' => 0.6];
    }

    private function articles(int ...$numbers): string
    {
        return implode("\n…\n", array_map(static fn (int $n): string => "**{$n}.** Rule {$n} of the text.", $numbers));
    }

    /** @test */
    public function it_separates_the_articles_already_cited_from_the_new_ones(): void
    {
        $contract = DeepeningContract::between(
            [$this->cited(59, $this->articles(1063, 1064, 1065, 1066), ['cited_articles' => [1063, 1064, 1065]])],
            [
                $this->passage(59, $this->articles(1063, 1064, 1065, 1066)),
                $this->passage(74, $this->articles(1071, 1072, 1073)),
            ]
        );

        $this->assertSame([1063, 1064, 1065], $contract['covered']);
        $this->assertSame([1 => [1066], 2 => [1071, 1072, 1073]], $contract['new']);
    }

    /** @test */
    public function an_answer_that_named_no_article_covers_everything_its_cited_passage_defines(): void
    {
        // Live (rights t0 → t1): the answer cited passage [1] five times without an
        // article number and had used each of its articles. Counting only the
        // cited chunk's own article (1064) marked 1063 and 1065 NEW, and the
        // follow-up re-served them.
        $contract = DeepeningContract::between(
            [$this->cited(59, $this->articles(1063, 1064, 1065), ['passage' => '**1064.** Rule 1064 of the text.', 'article_no' => '1064'])],
            [$this->passage(59, $this->articles(1063, 1064, 1065)), $this->passage(74, $this->articles(1071))]
        );

        $this->assertSame([1063, 1064, 1065], $contract['covered']);
        $this->assertSame([1], $contract['covered_passages']);
        $this->assertSame([2 => [1071]], $contract['new']);
    }

    /** @test */
    public function a_continuation_passage_is_covered_by_its_chunk_metadata(): void
    {
        // No line-start clause number in the text: the chunk's article metadata.
        $contract = DeepeningContract::between(
            [$this->cited(60, 'second paragraph of the rule, continued.', ['article_no' => '1064'])],
            [$this->passage(59, $this->articles(1064)), $this->passage(74, $this->articles(1071))]
        );

        $this->assertSame([1064], $contract['covered']);
        $this->assertSame([2 => [1071]], $contract['new']);
    }

    /** @test */
    public function there_is_no_contract_without_a_previous_citation(): void
    {
        $this->assertNull(DeepeningContract::between([], [$this->passage(74, $this->articles(1071))]));
    }

    /** @test */
    public function there_is_no_contract_when_nothing_is_new(): void
    {
        $this->assertNull(DeepeningContract::between(
            [$this->cited(59, $this->articles(1063, 1064))],
            [$this->passage(59, $this->articles(1063, 1064))]
        ));
    }

    /** @test */
    public function an_unrelated_turn_gets_no_contract(): void
    {
        // The conversation still remembers an old citation, but this turn's
        // passages hold none of what it covered and QU did not judge it a
        // follow-up: there is nothing to build on and nothing to repeat.
        $previous = [$this->cited(59, $this->articles(1063, 1064))];
        $current = [$this->passage(113, $this->articles(1098, 1099))];

        $this->assertNull(DeepeningContract::between($previous, $current));

        // When QU judged the turn a follow-up, the contract stands.
        $contract = DeepeningContract::between($previous, $current, followUp: true);
        $this->assertSame([1063, 1064], $contract['covered']);
        $this->assertSame([1 => [1098, 1099]], $contract['new']);
    }

    /**
     * @test The comparison without the judgment of whether a contract is worth stating:
     * the follow-up question chips need the split on every turn — also when nothing is new
     * (then nothing may be offered as new) and on a turn that is not a follow-up.
     */
    public function the_bare_comparison_is_available_whatever_the_turn(): void
    {
        $previous = [$this->cited(59, $this->articles(1063, 1064))];

        // Nothing new: between() states no contract; the comparison says so explicitly.
        $this->assertSame(
            ['covered' => [1063, 1064], 'covered_passages' => [1], 'new' => [], 'returns_to_covered_ground' => true],
            DeepeningContract::compare($previous, [$this->passage(59, $this->articles(1063, 1064))])
        );

        // An unrelated turn: nothing of it was covered.
        $this->assertSame(
            ['covered' => [1063, 1064], 'covered_passages' => [], 'new' => [1 => [1098, 1099]], 'returns_to_covered_ground' => false],
            DeepeningContract::compare($previous, [$this->passage(113, $this->articles(1098, 1099))])
        );

        // No reference names a file: nothing can be compared.
        $this->assertNull(DeepeningContract::compare([], [$this->passage(59, $this->articles(1063))]));
        $this->assertNull(DeepeningContract::compare([['snippet' => '**1063.** Rule.']], [$this->passage(59, $this->articles(1063))]));
    }

    /** @test */
    public function articles_are_compared_within_one_document(): void
    {
        // Article 12 of the by-laws is not article 12 of the law.
        $contract = DeepeningContract::between(
            [$this->cited(4, $this->articles(100, 101))],
            [
                $this->passage(4, $this->articles(100, 101)),
                $this->passage(9, $this->articles(100, 102), 'physical:rules/other.md'),
            ]
        );

        $this->assertSame([2 => [100, 102]], $contract['new']);
    }

    /** @test */
    public function passages_without_article_numbers_are_told_apart_by_the_passage_itself(): void
    {
        // A how-to document has no articles: the passage the previous answer cited
        // is the covered one, any other passage is new.
        $contract = DeepeningContract::between(
            [$this->cited(3, 'Open Team settings, then Members.')],
            [$this->passage(3, 'Open Team settings, then Members.'), $this->passage(7, 'Roles decide what a member can see.')]
        );

        $this->assertSame([], $contract['covered']);
        $this->assertSame([1], $contract['covered_passages']);
        $this->assertSame([2 => []], $contract['new']);
    }

    /** @test */
    public function the_covered_passages_are_named_by_this_turns_numbers(): void
    {
        $contract = DeepeningContract::between(
            [$this->cited(59, $this->articles(1063, 1064), ['cited_articles' => [1063, 1064]])],
            [$this->passage(74, $this->articles(1071)), $this->passage(59, $this->articles(1063, 1064))]
        );

        $this->assertSame([2], $contract['covered_passages'], 'passage [2] holds nothing new');
        $this->assertSame([1 => [1071]], $contract['new']);
    }

    /** @test */
    public function it_lists_the_chunks_the_previous_turn_cited_per_file(): void
    {
        $cited = DeepeningContract::citedChunks(['last_referenced_files' => [
            $this->cited(59, 'a'),
            $this->cited(8, 'b'),
            ['id' => 42, 'name' => 'minutes.pdf', 'snippet' => 'c', 'chunk_index' => 3],
            ['id' => 43, 'name' => 'legacy.pdf', 'snippet' => 'no chunk index'],
        ]]);

        $this->assertSame([self::LAW => [59, 8], '42' => [3]], $cited);
        $this->assertSame([], DeepeningContract::citedChunks([]));
    }

    /**
     * @test
     * @dataProvider articleLists
     */
    public function article_lists_read_as_runs(array $articles, string $expected): void
    {
        $this->assertSame($expected, DeepeningContract::formatArticles($articles));
    }

    public static function articleLists(): array
    {
        return [
            [[1071], 'art. 1071'],
            [[1071, 1072], 'art. 1071, 1072'],
            [[1063, 1064, 1065, 1066], 'art. 1063–1066'],
            [[1015, 1063, 1064, 1065, 1067, 1068], 'art. 1015, 1063–1065, 1067, 1068'],
            [[], ''],
        ];
    }

    // --------------------------------- is a passage ground the previous answer covered?

    /** @test */
    public function a_passage_is_covered_ground_when_the_previous_answer_cited_that_chunk_or_an_article_it_defines(): void
    {
        $previous = [
            $this->cited(59, $this->articles(1063, 1064, 1065, 1066), ['cited_articles' => [1063, 1064]]),
            $this->cited(92, 'A paragraph without a number.'),
        ];

        // The very chunk the answer cited…
        $this->assertTrue(DeepeningContract::coveredGround($previous, $this->passage(92, 'A paragraph without a number.')));
        // …or another chunk defining an article the answer named (its OWN text: `passage`,
        // not the window stitched around it).
        $own = ['passage' => $this->articles(1064)] + $this->passage(60, $this->articles(1063, 1064, 1065));
        $this->assertTrue(DeepeningContract::coveredGround($previous, $own));

        // A chunk whose own article the answer did not name is new ground, whatever its
        // window holds; so is a chunk of another file, and anything on a first turn.
        $new = ['passage' => $this->articles(1066)] + $this->passage(61, $this->articles(1064, 1065, 1066));
        $this->assertFalse(DeepeningContract::coveredGround($previous, $new));
        $this->assertFalse(DeepeningContract::coveredGround($previous, $this->passage(38, $this->articles(1045))));
        $this->assertFalse(DeepeningContract::coveredGround($previous, $this->passage(59, $this->articles(1063), 'physical:rules/other.md')));
        $this->assertFalse(DeepeningContract::coveredGround([], $this->passage(59, $this->articles(1063))));
    }

    /** @test */
    public function an_answer_that_named_no_article_covers_every_article_of_the_passage_it_cited(): void
    {
        $previous = [$this->cited(59, $this->articles(1063, 1064, 1065))];

        $this->assertTrue(DeepeningContract::coveredGround($previous, ['passage' => $this->articles(1065)] + $this->passage(60, $this->articles(1065, 1066))));
        $this->assertFalse(DeepeningContract::coveredGround($previous, ['passage' => $this->articles(1066)] + $this->passage(61, $this->articles(1065, 1066))));
    }
}
