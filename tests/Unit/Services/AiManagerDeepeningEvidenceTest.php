<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * New evidence on a follow-up turn (eval p17-B rights t1).
 *
 * The previous answer's passages are re-supplied to the composer anyway (the
 * conversation memory). When the per-file budget forces a choice between two
 * passages the search angles support EQUALLY, the one the previous turn did not
 * cite takes the slot. Never more than a tiebreak: a passage the angles prefer
 * keeps its place whether or not it was cited before.
 */
class AiManagerDeepeningEvidenceTest extends TestCase
{
    use FileFanOutHarness;

    private const FILE = 'physical:rules/aircraft.md';

    private const QUESTION = 'Tell me more about the maintenance reserve.';

    public function setUp(): void
    {
        parent::setUp();

        config([
            'logging.default' => 'null',
            'ai.file_context.enabled' => true,
            'ai.file_context.min_relevance_score' => 0.4,
            'ai.file_context.max_references' => 5,
            'ai.file_context.max_chunks_per_file' => 2,
            'ai.file_context.context_window' => 2,
            'ai.file_context.recall_floor' => 0,
        ]);
        $this->searched = [];
        $this->trace = [];
        $this->headings = [];
        // Two vote groups. Both rank 10 first; they disagree symmetrically on 20
        // and 30, which therefore carry the same vote. 20 has the higher cosine.
        $this->rankings = fn (string $query): array => [self::FILE => $query === self::QUESTION
            ? [10 => 0.70, 20 => 0.66, 30 => 0.60]
            : [10 => 0.70, 30 => 0.62, 20 => 0.61]];
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function qu(): QueryUnderstandingResult
    {
        return new QueryUnderstandingResult(searchQuery: 'maintenance reserve', questionType: 'knowledge_query', language: 'en');
    }

    private function previousTurnCited(int ...$chunks): array
    {
        return ['last_referenced_files' => array_map(
            static fn (int $chunk): array => ['ref' => 1, 'id' => self::FILE, 'name' => 'aircraft.md', 'snippet' => "chunk {$chunk}", 'chunk_index' => $chunk],
            $chunks
        )];
    }

    /** @test */
    public function without_a_previous_citation_a_tie_goes_to_the_higher_cosine(): void
    {
        $files = $this->fanOut(self::QUESTION, $this->qu());

        $this->assertSame([10, 20], array_column($files, 'chunk_index'));
    }

    /** @test */
    public function an_uncited_passage_wins_a_tie_against_one_the_previous_turn_cited(): void
    {
        $files = $this->fanOut(self::QUESTION, $this->qu(), $this->previousTurnCited(20));

        $this->assertSame([10, 30], array_column($files, 'chunk_index'));
    }

    /** @test */
    public function a_cited_passage_the_angles_prefer_keeps_its_slot(): void
    {
        // 10 was cited too, and every angle ranks it first: it stays the primary.
        $files = $this->fanOut(self::QUESTION, $this->qu(), $this->previousTurnCited(10, 20));

        $this->assertSame([10, 30], array_column($files, 'chunk_index'));
    }

    /** @test */
    public function a_citation_of_another_file_changes_nothing(): void
    {
        $conversation = ['last_referenced_files' => [
            ['ref' => 1, 'id' => 'physical:rules/other.md', 'name' => 'other.md', 'snippet' => 'x', 'chunk_index' => 20],
        ]];

        $files = $this->fanOut(self::QUESTION, $this->qu(), $conversation);

        $this->assertSame([10, 20], array_column($files, 'chunk_index'));
    }

    /** @test */
    public function the_trace_states_the_contract_the_composer_is_given(): void
    {
        $files = $this->fanOut(self::QUESTION, $this->qu(), $this->previousTurnCited(10));

        $this->assertSame([10, 20], array_column($files, 'chunk_index'));
        $this->assertSame(
            ['covered' => [], 'covered_passages' => [1], 'new' => [2 => []]],
            $this->trace['deepening']
        );
    }

    /** @test */
    public function a_first_turn_has_no_contract_in_the_trace(): void
    {
        $this->fanOut(self::QUESTION, $this->qu());

        $this->assertArrayNotHasKey('deepening', $this->trace);
    }
}
