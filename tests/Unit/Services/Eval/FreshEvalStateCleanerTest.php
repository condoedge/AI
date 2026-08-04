<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Eval;

use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Eval\FreshEvalStateCleaner;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Lever L2 (few-shot hygiene): --fresh must clear the stored question->cypher
 * few-shot example store ('questions' vector collection). Stored examples from
 * old interactions anchor generation — a byte-identical "87% similar" example
 * beats the clarified intent — so an eval re-run that claims freshness while
 * serving poisoned examples is dishonest.
 *
 * The cleaner clears via the owning service's targeted API
 * (AiManager::clearStoredQueries) and keeps the honest NOT-cleared note for
 * AiQueryLog rows, which are intentionally kept as --from-logs history.
 */
class FreshEvalStateCleanerTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param array|\Throwable $result clearStoredQueries() return value, or a throwable to raise
     */
    private function cleanerWith(array|\Throwable $result): FreshEvalStateCleaner
    {
        $manager = Mockery::mock(AiManager::class);
        $expectation = $manager->shouldReceive('clearStoredQueries')
            ->once()
            ->with('questions');

        if ($result instanceof \Throwable) {
            $expectation->andThrow($result);
        } else {
            $expectation->andReturn($result);
        }

        return new FreshEvalStateCleaner($manager);
    }

    /** @test */
    public function it_clears_the_stored_question_cypher_example_store_via_the_owning_service(): void
    {
        $cleaner = $this->cleanerWith(['success' => true, 'deleted' => 7, 'errors' => []]);

        $output = implode("\n", $cleaner->clean(null));

        $this->assertStringContainsString(
            "Cleared 7 stored question->cypher few-shot example(s) ('questions' vector collection)",
            $output
        );
        $this->assertStringContainsString('AiManager::clearStoredQueries()', $output);
    }

    /** @test */
    public function the_examples_store_is_no_longer_listed_as_not_cleared_on_success(): void
    {
        $cleaner = $this->cleanerWith(['success' => true, 'deleted' => 0, 'errors' => []]);

        $output = implode("\n", $cleaner->clean(null));

        // The old honest NOT-cleared inventory entry for the example store must be
        // gone now that --fresh actually clears it.
        $this->assertStringNotContainsString('NOT cleared: stored question->cypher', $output);
    }

    /** @test */
    public function it_keeps_the_honest_not_cleared_note_for_query_log_rows(): void
    {
        $cleaner = $this->cleanerWith(['success' => true, 'deleted' => 3, 'errors' => []]);

        $output = implode("\n", $cleaner->clean(null));

        // AiQueryLog rows stay: they feed --from-logs question sets.
        $this->assertStringContainsString('NOT cleared: AiQueryLog', $output);
    }

    /** @test */
    public function a_refused_clear_degrades_to_an_honest_skipped_line(): void
    {
        $cleaner = $this->cleanerWith(['success' => false, 'deleted' => 0, 'errors' => ['qdrant unreachable']]);

        $output = implode("\n", $cleaner->clean(null));

        $this->assertStringContainsString(
            "SKIPPED (NOT cleared) stored question->cypher few-shot examples ('questions')",
            $output
        );
        $this->assertStringContainsString('qdrant unreachable', $output);
        // Honesty: when clearing fails, the operator is told examples still anchor retrieval.
        $this->assertStringContainsString('still serve as retrieval examples', $output);
    }

    /** @test */
    public function a_throwing_vector_store_never_breaks_the_clean_run(): void
    {
        $cleaner = $this->cleanerWith(new \RuntimeException('vector store down'));

        $lines = $cleaner->clean(null);
        $output = implode("\n", $lines);

        $this->assertStringContainsString(
            "SKIPPED (NOT cleared) stored question->cypher few-shot examples ('questions')",
            $output
        );
        $this->assertStringContainsString('vector store down', $output);
        // The rest of the inventory still ran (the AiQueryLog note is emitted last).
        $this->assertStringContainsString('NOT cleared: AiQueryLog', $output);
    }
}
