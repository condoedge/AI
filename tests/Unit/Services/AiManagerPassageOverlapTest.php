<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Kept passages of one file never print a chunk twice (eval p18-B rights t0).
 *
 * After branch coherence moved the file's primary passage to chunk 58, the window
 * rule admitted chunks 61 and 64 as distinct passages (each more than
 * context_window away from the last) — but every snippet is that chunk ±2, so
 * art. 1064–1065 reached the prompt in [1] and [2], art. 1067–1068 in [2] and [3],
 * and the answer repeated exactly those articles. Passages whose snippets share a
 * chunk are now ONE passage (each chunk once, in reading order, one slot); a slot
 * freed that way goes to the next passage at least two vote groups picked, else to
 * the next passage in consensus order (eval p19-B rights t0: two slots stayed empty
 * while the first candidate, one group's pick, was refused).
 */
class AiManagerPassageOverlapTest extends TestCase
{
    use FileFanOutHarness;

    private const FILE = 'physical:laws/code.md';

    private const QUESTION = 'What are my main rights and duties as a co-owner here?';

    public function setUp(): void
    {
        parent::setUp();

        config([
            'logging.default' => 'null',
            'ai.file_context.enabled' => true,
            'ai.file_context.min_relevance_score' => 0.4,
            'ai.file_context.max_references' => 5,
            'ai.file_context.max_chunks_per_file' => 5,
            'ai.file_context.context_window' => 2,
            'ai.file_context.recall_floor' => 0,
        ]);
        $this->searched = [];
        $this->trace = [];
        $this->headings = [];
        $this->passageChunks = true;
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Three vote groups: the search subject, the user's wording, the clarified
     * question — and, with $tags, a fourth (the keyword angle).
     */
    private function qu(bool $tags = false): QueryUnderstandingResult
    {
        return new QueryUnderstandingResult(
            searchQuery: 'co-owner rights and duties',
            questionType: 'knowledge_query',
            clarifiedQuestion: 'What are the rights and duties of a co-owner?',
            searchTags: $tags ? ['rights', 'duties'] : [],
            language: 'en',
        );
    }

    /**
     * @param array<int, float> $subject   the search-subject angle's chunk scores
     * @param array<int, float> $question  the user's wording
     * @param array<int, float> $clarified the clarified question
     * @param array<int, float> $tags      the keyword angle (qu(tags: true))
     */
    private function rank(array $subject, array $question, array $clarified, array $tags = []): void
    {
        $this->rankings = fn (string $query): array => [self::FILE => match ($query) {
            'co-owner rights and duties' => $subject,
            self::QUESTION => $question,
            'rights duties' => $tags,
            default => $clarified,
        }];
    }

    /** @return list<int> every chunk a reference's snippet prints, in order */
    private function printed(array $reference): array
    {
        preg_match_all('/chunk (\d+)/', (string) $reference['snippet'], $m);

        return array_map('intval', $m[1]);
    }

    /** The p18 shape inside the kept branch: 58, 61 and 64 are three chunks apart. */
    private function p18(): void
    {
        $this->rank(
            [58 => 0.635, 59 => 0.621, 60 => 0.599, 61 => 0.587, 32 => 0.573, 64 => 0.572],
            [58 => 0.610, 60 => 0.603, 61 => 0.598, 59 => 0.589, 69 => 0.575, 64 => 0.548],
            [60 => 0.617, 58 => 0.613, 59 => 0.610, 61 => 0.602, 64 => 0.596, 69 => 0.576],
        );
    }

    /** @test */
    public function passages_whose_windows_overlap_become_one_passage_printing_each_chunk_once(): void
    {
        $this->p18();

        $files = $this->fanOut(self::QUESTION, $this->qu());

        // 58 ±2, 61 ±2 and 64 ±2 chain into ONE passage: chunks 56..66, each once.
        $this->assertSame(range(56, 66), $this->printed($files[0]));
        $this->assertSame(58, $files[0]['chunk_index'], 'it stays the passage the angles ranked first');
        $this->assertSame(
            implode(FileContextProvider::SNIPPET_SEPARATOR, array_map(static fn (int $i): string => "chunk {$i}", range(56, 66))),
            $files[0]['snippet'],
            'reading order, the provider\'s own separator'
        );

        // No chunk is printed by two passages of the file.
        $all = array_merge(...array_map(fn (array $f): array => $this->printed($f), $files));
        $this->assertSame(count($all), count(array_unique($all)), 'every chunk reaches the prompt once');

        // The other passages are the ones that overlapped nothing.
        $this->assertEqualsCanonicalizing([58, 69, 32], array_column($files, 'chunk_index'));
        $this->assertSame(range(67, 71), $this->printed($files[array_search(69, array_column($files, 'chunk_index'), true)]), 'an ADJACENT window shares no chunk: it stays its own passage');
    }

    /** @test */
    public function the_chunk_map_served_the_merge_and_does_not_reach_the_prompt_context(): void
    {
        $this->p18();

        foreach ($this->fanOut(self::QUESTION, $this->qu()) as $file) {
            $this->assertArrayNotHasKey('snippet_chunks', $file);
        }
    }

    /** @test */
    public function the_trace_names_the_passages_that_were_merged(): void
    {
        $this->p18();

        $this->fanOut(self::QUESTION, $this->qu());

        $this->assertSame([[58, 61, 64]], $this->trace['passage_overlap'][self::FILE]['merged']);
    }

    /** @test */
    public function a_freed_slot_goes_first_to_the_next_passage_two_vote_groups_picked(): void
    {
        // The five slots went to 58, 61, 64, 90 and 120; the first three are now one
        // passage, so two slots are free. Of the passages left, 150 is a pick of two
        // vote groups (the question's and the clarified question's); 200 and 210 are
        // picks of the keyword angle alone.
        $this->rank(
            [58 => 0.70, 61 => 0.66, 64 => 0.62, 90 => 0.58, 120 => 0.50],
            [58 => 0.70, 61 => 0.66, 64 => 0.62, 120 => 0.57, 150 => 0.50],
            [58 => 0.70, 61 => 0.66, 64 => 0.62, 90 => 0.57, 150 => 0.50],
            [58 => 0.70, 61 => 0.66, 64 => 0.62, 200 => 0.57, 210 => 0.50],
        );

        $files = $this->fanOut(self::QUESTION, $this->qu(tags: true));

        // The supported passage takes the first freed slot; the other one would stay
        // EMPTY, so the next passage in consensus order has it — one group's pick.
        $this->assertSame([58, 90, 120, 150, 200], array_column($files, 'chunk_index'));
        $this->assertSame(range(148, 152), $this->printed($files[3]));
        $this->assertSame(range(198, 202), $this->printed($files[4]));
        $overlap = $this->trace['passage_overlap'][self::FILE];
        $this->assertSame([[58, 61, 64]], $overlap['merged']);
        $this->assertSame([150, 200], $overlap['filled']);
        $this->assertSame([200], $overlap['single_group'], 'the trace says which filler one angle alone stands behind');
        $this->assertSame([210], $overlap['unsupported'], 'no slot was left for it');
    }

    /**
     * @test Eval p19-B rights t0: the merges freed two slots, the first candidate in
     * consensus order was the passage the answer needed — one probe's pick — and the
     * two-group rule refused it, so both slots stayed EMPTY (while a passage of the same
     * single-group support, admitted by the budget, kept its place). A slot that would
     * stay empty goes to the next candidate in consensus order.
     */
    public function a_slot_that_would_stay_empty_goes_to_the_next_passage_in_consensus_order(): void
    {
        // Five slots: 58, 61, 90, 93 and 120 — 58+61 and 90+93 are now two passages, so
        // two slots are free. No passage left has two groups behind it: 200, 201 and 230
        // are the keyword angle's own.
        $this->rank(
            [58 => 0.70, 61 => 0.66, 90 => 0.62, 93 => 0.58, 120 => 0.50],
            [58 => 0.70, 61 => 0.66, 90 => 0.62, 93 => 0.58, 120 => 0.50],
            [58 => 0.70, 61 => 0.66, 90 => 0.62, 93 => 0.58, 120 => 0.50],
            [58 => 0.70, 61 => 0.66, 200 => 0.60, 201 => 0.55, 230 => 0.50],
        );

        $files = $this->fanOut(self::QUESTION, $this->qu(tags: true));

        // 200 first; 201 shares chunks with it (no chunk twice); 230 takes the other slot.
        $this->assertSame([58, 90, 120, 200, 230], array_column($files, 'chunk_index'));
        $all = array_merge(...array_map(fn (array $f): array => $this->printed($f), $files));
        $this->assertSame(count($all), count(array_unique($all)), 'every chunk reaches the prompt once');

        $overlap = $this->trace['passage_overlap'][self::FILE];
        $this->assertSame([[58, 61], [90, 93]], $overlap['merged']);
        $this->assertSame([200, 230], $overlap['filled']);
        $this->assertSame([200, 230], $overlap['single_group']);
        $this->assertArrayNotHasKey('unsupported', $overlap);
    }

    /**
     * @test Filling never displaces: the passages the budget admitted all stay, the
     * supported candidate has the freed slot before one angle's own favourite, and no
     * passage is added beyond the slots the merges freed.
     */
    public function filling_a_freed_slot_displaces_nothing(): void
    {
        // Slots: 58, 61 (one passage now), 90, 120, 150 — ONE slot is free. 180 is a pick
        // of two groups; 200 is the keyword angle's own, and its best-scored passage.
        $this->rank(
            [58 => 0.70, 61 => 0.66, 90 => 0.62, 120 => 0.58, 150 => 0.54],
            [58 => 0.70, 61 => 0.66, 90 => 0.62, 120 => 0.58, 180 => 0.41],
            [58 => 0.70, 61 => 0.66, 90 => 0.62, 150 => 0.58, 180 => 0.41],
            [200 => 0.90, 58 => 0.70, 61 => 0.66, 90 => 0.62, 120 => 0.58],
        );

        $files = $this->fanOut(self::QUESTION, $this->qu(tags: true));

        $this->assertSame([58, 90, 120, 150, 180], array_column($files, 'chunk_index'), 'the admitted passages, then the filler');
        $overlap = $this->trace['passage_overlap'][self::FILE];
        $this->assertSame([180], $overlap['filled']);
        $this->assertArrayNotHasKey('single_group', $overlap);
        $this->assertSame([200], $overlap['unsupported'], 'one freed slot, one filler');
    }

    /** @test */
    public function a_freed_slot_is_never_given_to_a_passage_that_overlaps_a_kept_one(): void
    {
        // Budget 3: 58, 61 and 64 took the slots and are now one passage. 67 is the next
        // passage and every group picked it — but its window (65..69) overlaps the merged
        // passage (56..66): it would only chain it on, past what the budget admitted.
        config(['ai.file_context.max_chunks_per_file' => 3, 'ai.file_context.max_references' => 5]);
        $this->rank(
            [58 => 0.70, 61 => 0.66, 64 => 0.62],
            [58 => 0.70, 61 => 0.66, 67 => 0.62],
            [58 => 0.70, 64 => 0.66, 67 => 0.62],
        );

        $files = $this->fanOut(self::QUESTION, $this->qu());

        $this->assertSame([58], array_column($files, 'chunk_index'));
        $this->assertSame(range(56, 66), $this->printed($files[0]), 'the merged passage is not extended past the passages the budget admitted');
    }

    /** @test */
    public function passages_that_share_no_chunk_are_left_exactly_as_they_were(): void
    {
        // 58, 66 and 74: windows 56..60, 64..68, 72..76.
        $this->rank(
            [58 => 0.70, 66 => 0.66, 74 => 0.62],
            [58 => 0.70, 74 => 0.66, 66 => 0.62],
            [66 => 0.70, 58 => 0.66, 74 => 0.62],
        );

        $files = $this->fanOut(self::QUESTION, $this->qu());

        $this->assertEqualsCanonicalizing([58, 66, 74], array_column($files, 'chunk_index'));
        foreach ($files as $file) {
            $this->assertSame(range($file['chunk_index'] - 2, $file['chunk_index'] + 2), $this->printed($file));
        }
        $this->assertArrayNotHasKey('passage_overlap', $this->trace);
    }

    /** @test */
    public function the_question_angles_best_passage_is_inside_the_merged_passage_it_overlaps(): void
    {
        // The user's own wording prefers 61; the other two groups prefer 58. The floor
        // keeps 61 — as part of the passage it shares chunks with, not as a repeat.
        $this->rank(
            [58 => 0.70, 90 => 0.60],
            [61 => 0.70, 90 => 0.55],
            [58 => 0.70, 90 => 0.60],
        );

        $files = $this->fanOut(self::QUESTION, $this->qu());

        $this->assertSame([self::FILE => 61], $this->trace['question_passages']);
        $merged = $files[array_search(58, array_column($files, 'chunk_index'), true)];
        $this->assertSame(range(56, 63), $this->printed($merged));
        $this->assertNotContains(61, array_column($files, 'chunk_index'), 'not a passage of its own');
    }

    /** @test */
    public function references_without_a_chunk_map_are_not_merged(): void
    {
        // A provider that does not say which chunks a snippet holds: the snippets cannot
        // be joined chunk by chunk, so the passages stay as the window rule kept them.
        $this->passageChunks = false;
        $this->p18();

        $files = $this->fanOut(self::QUESTION, $this->qu());

        $this->assertContains(61, array_column($files, 'chunk_index'));
        $this->assertContains(64, array_column($files, 'chunk_index'));
        $this->assertArrayNotHasKey('passage_overlap', $this->trace);
    }
}
