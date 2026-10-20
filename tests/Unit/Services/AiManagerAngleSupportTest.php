<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Which search angles stand behind a retrieved passage.
 *
 * The fan-out searches the user's own wording and several rewrites of it (QU's search
 * query and clarified question, the document probes). A passage the user's OWN wording
 * found is about what they asked; one that only a rewrite found may be where the rewrite
 * drifted. The final references carry that fact — `angle_groups` (every vote group one of
 * whose own picks the passage holds) and `question_supported` (the user's wording is one
 * of them) — for consumers that must not build on drift: the follow-up question chips
 * take no excerpt from an unused passage the question itself did not find (live
 * 2026-10-06: a wall-removal question got chips about common expenses and a hypothec,
 * from passages only the rewrites had found).
 */
class AiManagerAngleSupportTest extends TestCase
{
    use FileFanOutHarness;

    private const FILE = 'physical:rules/aircraft.md';

    private const QUESTION = 'How big must the maintenance reserve be?';

    public function setUp(): void
    {
        parent::setUp();

        config([
            'logging.default' => 'null',
            'ai.file_context.enabled' => true,
            'ai.file_context.min_relevance_score' => 0.4,
            'ai.file_context.max_references' => 5,
            'ai.file_context.max_chunks_per_file' => 3,
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

    private function qu(): QueryUnderstandingResult
    {
        return new QueryUnderstandingResult(
            searchQuery: 'maintenance reserve amount',
            questionType: 'knowledge_query',
            language: 'en',
            clarifiedQuestion: 'What is the required size of the maintenance reserve fund?',
        );
    }

    /**
     * @param list<array> $files
     * @return array<int, array{groups: list<string>, question: bool}> by chunk index
     */
    private function support(array $files): array
    {
        $support = [];
        foreach ($files as $file) {
            $support[$file['chunk_index']] = ['groups' => $file['angle_groups'], 'question' => $file['question_supported']];
        }
        ksort($support);

        return $support;
    }

    /** @test */
    public function each_passage_carries_the_angles_that_picked_it(): void
    {
        // The question finds passage 10; its rewrites find 10 too, and each one more
        // passage of its own (40, 70).
        $this->rankings = fn (string $query): array => [self::FILE => match ($query) {
            self::QUESTION => [10 => 0.72],
            'maintenance reserve amount' => [10 => 0.70, 40 => 0.66],
            default => [10 => 0.69, 70 => 0.64],
        }];

        $support = $this->support($this->fanOut(self::QUESTION, $this->qu()));

        $this->assertSame([
            10 => ['groups' => ['search_query', 'question', 'clarified_question'], 'question' => true],
            40 => ['groups' => ['search_query'], 'question' => false],
            70 => ['groups' => ['clarified_question'], 'question' => false],
        ], $support);
    }

    /** @test A pick that lies inside a passage's window is a pick of that passage. */
    public function a_neighbouring_pick_inside_the_passage_window_supports_it(): void
    {
        // The question's best chunk is 11; the search query's is 10 — one stitched passage.
        $this->rankings = fn (string $query): array => [self::FILE => $query === self::QUESTION
            ? [11 => 0.72]
            : [10 => 0.75, 40 => 0.66]];

        $files = $this->fanOut(self::QUESTION, $this->qu());
        $support = $this->support($files);

        $first = array_key_first($support);
        $this->assertContains($first, [10, 11]);
        $this->assertTrue($support[$first]['question'], 'the question picked a chunk this passage holds');
        $this->assertFalse($support[40]['question']);
    }

    /** @test The user's wording found nothing: no passage is supported by it. */
    public function when_the_question_itself_finds_nothing_no_passage_is_question_supported(): void
    {
        $this->rankings = fn (string $query): array => [self::FILE => $query === self::QUESTION
            ? []
            : [10 => 0.70, 40 => 0.66]];

        $support = $this->support($this->fanOut(self::QUESTION, $this->qu()));

        $this->assertNotSame([], $support);
        $this->assertSame([false, false], array_column($support, 'question'));
    }

    /** @test The observed stage shows the support of each passage. */
    public function the_file_context_trace_lists_the_angle_groups_of_each_passage(): void
    {
        $this->rankings = fn (string $query): array => [self::FILE => $query === self::QUESTION
            ? [10 => 0.72]
            : [10 => 0.70, 40 => 0.66]];

        $files = $this->fanOut(self::QUESTION, $this->qu());

        // No snippet-chunk maps or per-angle scores leak into the final references…
        foreach ($files as $file) {
            $this->assertArrayNotHasKey('snippet_chunks', $file);
            $this->assertArrayNotHasKey('chunk_scores', $file);
        }
        // …only the two support facts.
        $this->assertSame(['search_query', 'question', 'clarified_question'], $files[0]['angle_groups']);
        $this->assertTrue($files[0]['question_supported']);
    }
}
