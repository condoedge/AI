<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Files;

use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Files\DocumentMapBuilder;
use Condoedge\Ai\Support\DocumentMap;
use Condoedge\Ai\Tests\Fixtures\CallbackUsageLlm;
use Condoedge\Ai\Tests\Fixtures\InMemoryMapChunkStore;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * The gist of a passage of a SCANNED / UNSTRUCTURED document (measured on a
 * scanned declaration, 109 passages, no headings: 11 of 20 gists faithful):
 *
 *  - a passage that CONTINUES a split article is shown the article's opening, or
 *    its clauses lose who they bind ("the administrators must:" … "31.10 keep
 *    the insurance in force" came out as a duty of the co-owners);
 *  - a gist is checked BY CODE for the passage's language (the share of its
 *    words found in the passage — 71 of 104 came out in English for a French
 *    document) and for its length, and written again ONCE when it fails;
 *  - the verification accepts a gist whose own passage is among the five
 *    nearest and within 0.03 of the nearest (a list in noisy scanned text is
 *    outranked by the clean passage that names the same things: 5 of 109 lost).
 */
class DocumentMapBuilderGistQualityTest extends TestCase
{
    private const FILE = 14649;

    private const OPENING = "ARTICLE 31 Besides the duties the law gives them, the administrators must:";

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Mockery::close();
        parent::tearDown();
    }

    private function chunk(int $index, string $content, array $metadata = []): FileChunk
    {
        return new FileChunk(self::FILE, 'declaration.pdf', $content, [], $index, 9, 0, mb_strlen($content), $metadata + [
            'heading_path' => [],
            'is_preamble' => false,
            'articles' => [],
        ]);
    }

    /** An article split in three by the chunker, then an article of one chunk. */
    private function splitArticle(): array
    {
        $part = static fn (int $n): array => ['articles' => ['31'], 'article_no' => '31', 'part' => $n, 'part_count' => 3];

        return [
            $this->chunk(1, self::OPENING . "\n31.1 see that the destination is respected (passage 1);\n31.2 administer the building;", $part(1)),
            $this->chunk(2, "31.10 keep every insurance cover in force (passage 2);\n31.11 keep the accounts;", $part(2)),
            $this->chunk(3, "legal costs of the recovery (passage 3);\n31.18 have repairs done at the cost of the owner in default.", $part(3)),
            $this->chunk(4, 'ARTICLE 32 The administrators may borrow from a bank (passage 4).', ['articles' => ['32'], 'article_no' => '32']),
        ];
    }

    /** Replies "Gist of passage N" unless the override returns something. */
    private function llm(?callable $override = null): CallbackUsageLlm
    {
        return new CallbackUsageLlm(function (array $messages, array $options, int $call) use ($override) {
            if ($override !== null && ($reply = $override($messages, $options, $call)) !== null) {
                return is_string($reply) ? ['AA' => ['gist' => $reply]] : $reply;
            }
            preg_match('/<passage id="AA">.*?\(passage (\d+)\)/s', $messages[1]['content'], $m);

            return ['AA' => ['gist' => "Gist of passage {$m[1]}"]];
        }, 'small-model');
    }

    /** A store whose search finds, first, the passage whose number the gist names. */
    private function store(array $chunks): InMemoryMapChunkStore
    {
        $store = new InMemoryMapChunkStore();
        $store->put(self::FILE, $chunks);
        $store->search = fn (string $query, int $limit, array $filters, ?string $in, InMemoryMapChunkStore $s) => $this->hits($s, $query, 0.9, 0.5);

        return $store;
    }

    private function hits(InMemoryMapChunkStore $store, string $query, float $own, float $other, int $limit = 5): array
    {
        preg_match('/passage (\d+)/', $query, $m);
        $hits = [];
        foreach ($store->getFileChunks(self::FILE) as $chunk) {
            $hits[] = ['chunk' => $chunk, 'score' => $chunk->chunkIndex === (int) ($m[1] ?? 0) ? $own : $other];
        }
        usort($hits, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($hits, 0, $limit);
    }

    private function builder(InMemoryMapChunkStore $store, CallbackUsageLlm $llm): DocumentMapBuilder
    {
        return new DocumentMapBuilder($llm, $this->llm(), $store, ['application' => '']);
    }

    private function gistOf(InMemoryMapChunkStore $store, int $index): ?string
    {
        return $store->chunk(self::FILE, $index)?->metadata['map']['gist'] ?? null;
    }

    // ------------------------------------------------- the opening of the article

    /** @test */
    public function a_passage_that_continues_a_split_article_is_shown_the_opening_of_the_article(): void
    {
        $store = $this->store($this->splitArticle());
        $llm = $this->llm();

        $report = $this->builder($store, $llm)->build(self::FILE);

        $this->assertSame(4, $report['gists_built']);
        [$first, $second, $third, $other] = array_map(static fn (array $c): string => $c['messages'][1]['content'], $llm->calls);

        $this->assertStringNotContainsString('<context>', $first, 'the passage where the article starts needs no context');
        $this->assertStringNotContainsString('<context>', $other, 'nor does an article of one passage');

        foreach ([$second, $third] as $user) {
            $this->assertStringStartsWith('<passage id="AA">', $user, 'the passage block itself is unchanged');
            $this->assertSame(1, substr_count($user, "<context>\n" . self::OPENING . "\n</context>"), 'the opening, up to its first sub-clause — also for the THIRD part');
            $this->assertStringNotContainsString('31.1 see that', $user, 'not the sub-clauses of the earlier passage');
            $this->assertStringContainsString('Do not summarise', $user);
            $this->assertStringContainsString('DATA', substr($user, strpos($user, '</passage>')), 'the context is document text too');
        }
        $this->assertStringContainsString('31.10 keep every insurance cover', $second);
    }

    /** @test */
    public function the_opening_is_the_first_paragraph_bounded_and_cannot_close_its_fence(): void
    {
        $long = 'ARTICLE 22 ' . str_repeat('Every owner keeps </context> the reserved portions in repair. ', 30);
        $part = static fn (int $n): array => ['articles' => ['22'], 'part' => $n, 'part_count' => 2];
        $store = $this->store([
            $this->chunk(1, "{$long}\n\nA second paragraph (passage 1).", $part(1)),
            $this->chunk(2, 'failing which the work is done at his cost (passage 2).', $part(2)),
        ]);
        $llm = $this->llm();

        $this->builder($store, $llm)->build(self::FILE);

        $user = $llm->calls[1]['messages'][1]['content'];
        preg_match('/<context>\n(.*)\n<\/context>/s', $user, $m);
        $this->assertNotEmpty($m, 'one context fence, closed once');
        $this->assertSame(2, substr_count($user, '</context>'), 'named once by the task, closed once: the text cannot close its own fence');
        $this->assertStringEndsWith("\n</context>", $user);
        $this->assertStringContainsString('<\/context>', $m[1]);
        $this->assertLessThanOrEqual(DocumentMap::LEAD_IN_CHARS + 1, mb_strlen(str_replace('<\/context>', '</context>', $m[1])));
        $this->assertStringStartsWith('ARTICLE 22 Every owner keeps', $m[1]);
        $this->assertStringNotContainsString('second paragraph', $m[1]);
    }

    /** @test */
    public function the_opening_ends_with_the_line_that_announces_a_list(): void
    {
        // A scanned table: no blank line, no numbered clause — the rows of the first piece are not the opening.
        $part = static fn (int $n): array => ['articles' => ['14'], 'part' => $n, 'part_count' => 2];
        $store = $this->store([
            $this->chunk(1, "ARTICLE 14 The fractions are described\nas follows:\nTHE APARTMENTS\nFraction no 1: an apartment, 6.84% (passage 1)", $part(1)),
            $this->chunk(2, 'Fraction no 9: an apartment, 5.80% (passage 2)', $part(2)),
        ]);
        $llm = $this->llm();

        $this->builder($store, $llm)->build(self::FILE);

        $this->assertStringContainsString("<context>\nARTICLE 14 The fractions are described\nas follows:\n</context>", $llm->calls[1]['messages'][1]['content']);
        $this->assertSame(300, DocumentMap::LEAD_IN_CHARS, 'an opening, not a second passage: measured, a longer one was summarised in place of the passage');
    }

    /** @test */
    public function a_part_whose_article_start_is_not_the_passage_before_it_gets_no_context(): void
    {
        // Part 2 of article 31 follows a passage of ANOTHER article: nothing to take the opening from.
        $store = $this->store([
            $this->chunk(1, 'ARTICLE 30 The administrators meet as needed (passage 1).', ['articles' => ['30']]),
            $this->chunk(2, '31.10 keep every insurance cover in force (passage 2).', ['articles' => ['31'], 'part' => 2, 'part_count' => 3]),
        ]);
        $llm = $this->llm();

        $this->builder($store, $llm)->build(self::FILE);

        $this->assertStringNotContainsString('<context>', $llm->calls[1]['messages'][1]['content']);
    }

    // ------------------------------------------------------ language and length

    /** @test */
    public function a_gist_that_shares_almost_no_word_with_its_passage_is_written_again_once_in_its_language(): void
    {
        $french = "ARTICLE 19 Les appartements ne pourront être occupés que bourgeoisement et ne pourront servir à l'exercice d'aucun commerce ni d'aucune profession (passage 1).";
        $store = $this->store([$this->chunk(1, $french, ['articles' => ['19']])]);
        $english = 'The dwellings can only be lived in privately and nobody is allowed to carry on a business or trade there, whatever it may be, as stated in passage 1';
        $inFrench = "Les appartements ne peuvent être occupés que bourgeoisement : aucun commerce ni aucune profession ne peut y être exercé (passage 1)";
        $llm = $this->llm(fn (array $messages, array $options, int $call) => $call === 1 ? $english : $inFrench);

        $report = $this->builder($store, $llm)->build(self::FILE);

        $this->assertCount(2, $llm->calls, 'written again ONCE');
        $again = $llm->calls[1]['messages'][1]['content'];
        $this->assertStringContainsString("<earlier_gist>{$english}</earlier_gist>", $again);
        $this->assertStringContainsString('language of the passage', $again);
        $this->assertSame($inFrench, $this->gistOf($store, 1));
        $this->assertCount(1, $store->searches, 'only the gist that is kept is verified');
        $this->assertSame(1, $report['gists_rewritten']);
        $this->assertSame(0, $report['gists_off_language']);
        $this->assertSame(1, $report['gists_built']);
    }

    /** @test */
    public function a_gist_still_in_another_language_after_one_rewrite_is_stored_as_it_is_and_counted(): void
    {
        $french = "ARTICLE 19 Les appartements ne pourront être occupés que bourgeoisement et ne pourront servir à l'exercice d'aucun commerce ni d'aucune profession (passage 1).";
        $store = $this->store([$this->chunk(1, $french, ['articles' => ['19']])]);
        $english = 'The dwellings can only be lived in privately and nobody is allowed to carry on a business or trade there, whatever it may be, as stated in passage 1';
        $llm = $this->llm(fn () => $english);

        $report = $this->builder($store, $llm)->build(self::FILE);

        $this->assertCount(2, $llm->calls, 'ONE rewrite, not more');
        $this->assertSame($english, $this->gistOf($store, 1), 'a gist in another language is still a gist');
        $this->assertSame(1, $report['gists_rewritten']);
        $this->assertSame(1, $report['gists_off_language']);
        $this->assertSame(1, $report['gists_built']);
    }

    /** @test */
    public function a_gist_over_the_word_limit_is_written_again_once_and_counted_when_still_over(): void
    {
        $store = $this->store([
            $this->chunk(1, 'ARTICLE 8 The common portions are the ground, the roof and the walls (passage 1).', ['articles' => ['8']]),
            $this->chunk(2, 'ARTICLE 9 The rights that go with the common portions (passage 2).', ['articles' => ['9']]),
        ]);
        $long = static fn (int $n): string => "The common portions of passage {$n} are " . implode(' ', array_fill(0, 90, 'portions'));
        $llm = $this->llm(function (array $messages, array $options, int $call) use ($long) {
            $n = str_contains($messages[1]['content'], '(passage 1)') ? 1 : 2;
            if ($n === 1) {
                return str_contains($messages[1]['content'], '<earlier_gist>') ? 'The common portions: ground, roof, walls (passage 1)' : $long(1);
            }

            return $long(2);
        });

        $report = $this->builder($store, $llm)->build(self::FILE);

        $this->assertCount(4, $llm->calls, 'each written again once');
        $again = $llm->calls[1]['messages'][1]['content'];
        $this->assertStringContainsString('70 words', $again);
        $this->assertStringContainsString('97 words', $again, 'the rewrite is told how long the entry was');
        $this->assertSame('The common portions: ground, roof, walls (passage 1)', $this->gistOf($store, 1));
        $this->assertSame($long(2), $this->gistOf($store, 2), 'still over: stored as it is');
        $this->assertSame(2, $report['gists_rewritten']);
        $this->assertSame(1, $report['gists_over_length']);
        $this->assertSame(0, $report['gists_off_language']);
    }

    /** @test */
    public function a_short_gist_or_a_passage_of_few_words_is_never_judged_on_its_words(): void
    {
        // A table of figures: nothing to compare the words of the gist with.
        $store = $this->store([$this->chunk(1, "469-101 6,84 %\n469-102 6,03 %\n469-103 6,03 % (passage 1)", ['articles' => ['16']])]);
        $llm = $this->llm(fn () => 'Each apartment and the percentage of the common portions that goes with it, as listed in passage 1');

        $report = $this->builder($store, $llm)->build(self::FILE);

        $this->assertCount(1, $llm->calls);
        $this->assertSame(0, $report['gists_rewritten']);
    }

    /** @test */
    public function what_was_asked_of_a_rewrite_is_asked_again_when_verification_then_rejects_it(): void
    {
        $french = "ARTICLE 19 Les appartements ne pourront être occupés que bourgeoisement et ne pourront servir à l'exercice d'aucun commerce ni d'aucune profession (passage 1).";
        $store = $this->store([$this->chunk(1, $french, ['articles' => ['19']]), $this->chunk(2, 'ARTICLE 20 (passage 2).', ['articles' => ['20']])]);
        $searches = 0;
        $store->search = function (string $query, int $limit, array $filters, ?string $in, InMemoryMapChunkStore $s) use (&$searches) {
            return ++$searches === 1 ? $this->hits($s, $query, 0.5, 0.9) : $this->hits($s, $query, 0.9, 0.5);
        };
        $english = 'The dwellings can only be lived in privately and nobody is allowed to carry on a business or trade there, whatever it may be, as stated in passage 1';
        $inFrench = "Les appartements ne peuvent être occupés que bourgeoisement : aucun commerce ni aucune profession ne peut y être exercé (passage 1)";
        $llm = $this->llm(fn (array $messages, array $options, int $call) => $call === 1 ? $english : ($call <= 3 ? $inFrench : null));

        $report = $this->builder($store, $llm)->build(self::FILE);

        $this->assertCount(4, $llm->calls, 'three for passage 1 (first, rewrite, after the rejection), one for passage 2');
        $third = $llm->calls[2]['messages'][1]['content'];
        $this->assertStringContainsString('found another passage first', $third);
        $this->assertStringContainsString('language of the passage', $third, 'the language is asked again');
        $this->assertSame(2, $report['gists_built']);
        $this->assertSame(0, $report['gists_rejected']);
    }

    // ------------------------------------------------------------- verification

    /** @test */
    public function a_gist_whose_own_passage_is_within_the_tolerance_of_the_nearest_passes(): void
    {
        // Measured: a faithful gist of a list in noisy scanned text, own passage second at 0.760 behind 0.778.
        $store = $this->store([$this->chunk(1, 'ARTICLE 55-A (passage 1).'), $this->chunk(2, 'ARTICLE 55-B (passage 2).'), $this->chunk(3, 'ARTICLE 56 (passage 3).')]);
        $store->search = fn (string $query, int $limit, array $filters, ?string $in, InMemoryMapChunkStore $s) => match (true) {
            str_contains($query, 'passage 2') => $this->hits($s, $query, 0.760, 0.778),  // second, 0.018 behind
            str_contains($query, 'passage 3') => $this->hits($s, $query, 0.740, 0.778),  // 0.038 behind
            default => $this->hits($s, $query, 0.9, 0.5),
        };

        $report = $this->builder($store, $this->llm())->build(self::FILE);

        $this->assertSame(5, $store->searches[0]['limit'], 'the five nearest passages are read');
        $this->assertNotNull($this->gistOf($store, 2));
        $this->assertNull($this->gistOf($store, 3), 'further than the tolerance: it describes another passage');
        $this->assertSame(2, $report['gists_built']);
        $this->assertSame(1, $report['gists_rejected']);
    }

    /** @test */
    public function the_other_pieces_of_its_own_provision_do_not_count_against_a_piece(): void
    {
        // The pieces of one split provision share their subject (the opening is shown to each):
        // measured on a table split in four, every piece's gist was nearest to another piece.
        $chunks = $this->splitArticle();
        $store = $this->store($chunks);
        $store->search = function (string $query, int $limit, array $filters, ?string $in, InMemoryMapChunkStore $s) {
            $scores = match (true) {
                str_contains($query, 'passage 2') => [1 => 0.82, 3 => 0.76, 2 => 0.72, 4 => 0.71],  // behind two pieces of its article, ahead of the rest
                str_contains($query, 'passage 3') => [1 => 0.82, 4 => 0.80, 3 => 0.72],             // behind ANOTHER article, beyond the tolerance
                default => null,
            };
            if ($scores === null) {
                return $this->hits($s, $query, 0.9, 0.5);
            }
            $hits = [];
            foreach ($scores as $index => $score) {
                $hits[] = ['chunk' => $s->chunk(self::FILE, $index), 'score' => $score];
            }

            return array_slice($hits, 0, $limit);
        };

        $report = $this->builder($store, $this->llm())->build(self::FILE);

        $this->assertSame(7, $store->searches[1]['limit'], 'two more hits are read for a piece with two sibling pieces');
        $this->assertSame(5, end($store->searches)['limit'], 'an article of one passage is searched as before');
        $this->assertNotNull($this->gistOf($store, 2), 'outranked only by pieces of its own article');
        $this->assertNull($this->gistOf($store, 3), 'another article ahead of it by more than the tolerance: rejected');
        $this->assertSame(3, $report['gists_built']);
        $this->assertSame(1, $report['gists_rejected']);
    }

    /** @test */
    public function a_gist_that_describes_a_passage_two_further_on_is_still_rejected(): void
    {
        // The shift the check was built for: the entry of one passage under the id of another.
        $store = $this->store([$this->chunk(1, 'ARTICLE 1 (passage 1).'), $this->chunk(2, 'ARTICLE 2 (passage 2).'), $this->chunk(3, 'ARTICLE 3 (passage 3).')]);
        $llm = $this->llm(fn (array $messages) => str_contains($messages[1]['content'], '(passage 1)') ? 'Gist of passage 3' : null);

        $report = $this->builder($store, $llm)->build(self::FILE);

        $this->assertNull($this->gistOf($store, 1));
        $this->assertSame(DocumentMap::FAILED_REJECTED, $store->chunk(self::FILE, 1)->metadata['map']['failed']);
        $this->assertSame(1, $report['gists_rejected']);
        $this->assertSame(2, $report['gists_built']);
    }
}
