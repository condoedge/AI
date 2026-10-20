<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Digest;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Services\Digest\AttentionSourceRegistry;
use Condoedge\Ai\Services\Digest\DigestRunner;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Multi-query digest v1 — the deterministic source loop.
 *
 * SECURITY INVARIANT (design §0.4): every source query executes through
 * QueryExecutorInterface::execute() with the SAME options the main pipeline
 * builds (user, conversation_id, read-only default) — the runner has no other
 * path to the graph. LLM BUDGET: the runner makes ZERO LLM calls.
 */
class DigestRunnerTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function registry(array $sources, array $digest = []): AttentionSourceRegistry
    {
        return new AttentionSourceRegistry($sources, $digest + ['max_sources' => 8, 'default_row_cap' => 5]);
    }

    private function ok(array $rows): QueryExecutionResult
    {
        return new QueryExecutionResult(success: true, data: $rows, stats: [], metadata: [], errors: []);
    }

    /** @test */
    public function it_runs_every_enabled_source_in_order_through_the_executor_with_the_same_pipeline_options(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $seen = [];
        $executor->shouldReceive('execute')
            ->twice()
            ->andReturnUsing(function ($cypher, $params, $options) use (&$seen) {
                $seen[] = ['cypher' => $cypher, 'options' => $options];
                return $this->ok([['id' => 1, 'title' => 'row']]);
            });

        $runner = new DigestRunner($executor, $this->registry([
            'a' => ['label' => 'A', 'cypher' => 'MATCH (a:A) RETURN a.id AS id', 'limit' => 3],
            'b' => ['label' => 'B', 'cypher' => 'MATCH (b:B) RETURN b.id AS id'],
        ]));

        $user = (object) ['id' => 13];
        $results = $runner->run([
            'user' => $user,
            'original_question' => 'what should we take care of next?',
            'conversation_id' => 'c-1',
        ]);

        $this->assertCount(2, $results);
        $this->assertSame(['a', 'b'], [$results[0]->key, $results[1]->key]);

        // Identity + provenance travel UNCHANGED into every execution (tenant
        // scoping resolves against $options['user'] inside the executor).
        foreach ($seen as $call) {
            $this->assertSame($user, $call['options']['user'] ?? null);
            $this->assertSame('c-1', $call['options']['conversation_id'] ?? null);
            $this->assertSame('what should we take care of next?', $call['options']['original_question'] ?? null);
        }

        // Per-source cap: the entry's own limit first, the digest default otherwise.
        $this->assertSame(3, $seen[0]['options']['limit'] ?? null);
        $this->assertSame(5, $seen[1]['options']['limit'] ?? null);
    }

    /** @test */
    public function it_hard_caps_the_number_of_sources_executed_per_run(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->twice()->andReturn($this->ok([['id' => 1]]));

        $runner = new DigestRunner($executor, $this->registry([
            'one' => ['label' => '1', 'cypher' => 'MATCH (n) RETURN n'],
            'two' => ['label' => '2', 'cypher' => 'MATCH (n) RETURN n'],
            'three' => ['label' => '3', 'cypher' => 'MATCH (n) RETURN n'],
        ], ['max_sources' => 2]));

        $results = $runner->run([]);

        $this->assertCount(2, $results);
        $this->assertSame(['one', 'two'], [$results[0]->key, $results[1]->key]);
    }

    /** @test */
    public function a_cap_hit_source_gets_its_true_total_from_a_companion_count_with_the_same_options(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')
            ->once()
            ->andReturn($this->ok(array_map(fn ($i) => ['id' => $i], range(1, 5))));
        $executor->shouldReceive('executeCount')
            ->once()
            ->andReturnUsing(function ($cypher, $params, $options) {
                $this->assertSame('MATCH (t:Task) RETURN t.id AS id', $cypher, 'canonical cypher carries no LIMIT — nothing to strip');
                $this->assertSame(13, $options['user']->id ?? null, 'count must carry the same identity options');
                return 130;
            });

        $runner = new DigestRunner($executor, $this->registry([
            'open_tasks' => ['label' => 'Open tasks', 'cypher' => 'MATCH (t:Task) RETURN t.id AS id'],
        ]));

        $results = $runner->run(['user' => (object) ['id' => 13]]);

        $this->assertSame(130, $results[0]->trueTotal);
        $this->assertTrue($results[0]->capHit);
        $this->assertSame(5, $results[0]->rowCount);
    }

    /** @test */
    public function a_below_cap_source_never_runs_the_companion_count(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->once()->andReturn($this->ok([['id' => 1], ['id' => 2]]));
        $executor->shouldReceive('executeCount')->never();

        $runner = new DigestRunner($executor, $this->registry([
            'open_tasks' => ['label' => 'Open tasks', 'cypher' => 'MATCH (t:Task) RETURN t.id AS id'],
        ]));

        $results = $runner->run([]);

        $this->assertNull($results[0]->trueTotal);
        $this->assertFalse($results[0]->capHit);
    }

    /** @test */
    public function a_count_failure_leaves_the_total_unknown_but_never_fails_the_source(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')
            ->once()
            ->andReturn($this->ok(array_map(fn ($i) => ['id' => $i], range(1, 5))));
        $executor->shouldReceive('executeCount')->once()->andThrow(new \RuntimeException('count down'));

        $runner = new DigestRunner($executor, $this->registry([
            'open_tasks' => ['label' => 'Open tasks', 'cypher' => 'MATCH (t:Task) RETURN t.id AS id'],
        ]));

        $results = $runner->run([]);

        $this->assertNull($results[0]->error);
        $this->assertNull($results[0]->trueTotal);
        $this->assertTrue($results[0]->capHit, 'capHit survives so the composer can hedge "more may exist"');
        $this->assertSame(5, $results[0]->rowCount);
    }

    /** @test */
    public function an_empty_primary_with_a_declared_fallback_runs_the_fallback_through_the_identical_path(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $user = (object) ['id' => 13];
        $executor->shouldReceive('execute')
            ->twice()
            ->andReturnUsing(function ($cypher, $params, $options) use ($user) {
                $this->assertSame($user, $options['user'] ?? null, 'fallback runs with the SAME scoped options');
                if (str_contains($cypher, 'DESC')) {
                    return $this->ok([['id' => 9, 'name' => 'Last AGM']]);
                }
                return $this->ok([]);
            });

        $runner = new DigestRunner($executor, $this->registry([
            'upcoming_meetings' => [
                'label' => 'Upcoming meetings',
                'cypher' => 'MATCH (m:Meeting) RETURN m.id AS id',
                'fallback_cypher' => 'MATCH (m:Meeting) RETURN m.id AS id ORDER BY m.start_date DESC',
                'fallback_label' => 'Most recent meetings — none scheduled',
            ],
        ]));

        $results = $runner->run(['user' => $user]);

        $this->assertTrue($results[0]->usedFallback);
        $this->assertSame('Most recent meetings — none scheduled', $results[0]->fallbackLabel);
        $this->assertSame(1, $results[0]->rowCount);
        $this->assertNull($results[0]->error);
    }

    /** @test */
    public function a_non_empty_primary_never_runs_the_fallback(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->once()->andReturn($this->ok([['id' => 1]]));

        $runner = new DigestRunner($executor, $this->registry([
            'upcoming_meetings' => [
                'label' => 'Upcoming meetings',
                'cypher' => 'MATCH (m:Meeting) RETURN m.id AS id',
                'fallback_cypher' => 'MATCH (m:Meeting) RETURN m.id AS id ORDER BY m.start_date DESC',
                'fallback_label' => 'Most recent meetings',
            ],
        ]));

        $results = $runner->run([]);

        $this->assertFalse($results[0]->usedFallback);
    }

    /** @test */
    public function a_fallback_failure_degrades_to_the_plain_honest_empty_never_a_failed_section(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')
            ->twice()
            ->andReturnUsing(function ($cypher) {
                if (str_contains($cypher, 'DESC')) {
                    throw new \RuntimeException('fallback exploded');
                }
                return $this->ok([]);
            });

        $runner = new DigestRunner($executor, $this->registry([
            'upcoming_meetings' => [
                'label' => 'Upcoming meetings',
                'cypher' => 'MATCH (m:Meeting) RETURN m.id AS id',
                'fallback_cypher' => 'MATCH (m:Meeting) RETURN m.id AS id ORDER BY m.start_date DESC',
                'fallback_label' => 'Most recent meetings',
            ],
        ]));

        $results = $runner->run([]);

        // §3 step 4 (single statement of the fallback-error semantics): the
        // PRIMARY's honest-empty is the truth of the section; the fallback
        // failure is recorded as a NOTE (fallbackError), never as the error.
        $this->assertNull($results[0]->error, 'the PRIMARY succeeded honestly empty — fallback failure must not fail it');
        $this->assertFalse($results[0]->usedFallback);
        $this->assertSame(0, $results[0]->rowCount);
        $this->assertNotNull($results[0]->fallbackError, 'the failed fallback is visible as a note');
        $this->assertStringContainsString('fallback exploded', $results[0]->fallbackError);
    }

    /** @test */
    public function the_runner_strips_skip_security_from_every_execution_primary_fallback_and_count(): void
    {
        // SECURITY INVARIANT hardening (review note): the executor honors
        // $options['skip_security'] — the runner must never pass it, even when a
        // poisoned options array carries it. Covers all three executor paths.
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $optionsSeen = [];
        $executor->shouldReceive('execute')
            ->times(3)
            ->andReturnUsing(function ($cypher, $params, $options) use (&$optionsSeen) {
                $optionsSeen[] = $options;
                if (str_contains($cypher, 'CAPPED')) {
                    return $this->ok([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]]);
                }
                return $this->ok([]);
            });
        $executor->shouldReceive('executeCount')
            ->once()
            ->andReturnUsing(function ($cypher, $params, $options) use (&$optionsSeen) {
                $optionsSeen[] = $options;
                return 42;
            });

        $runner = new DigestRunner($executor, $this->registry([
            'capped' => ['label' => 'Capped', 'cypher' => 'CAPPED'],
            'with_fallback' => [
                'label' => 'With fallback',
                'cypher' => 'PRIMARY',
                'fallback_cypher' => 'FALLBACK',
                'fallback_label' => 'Recent',
            ],
        ]));

        $runner->run(['user' => (object) ['id' => 13], 'skip_security' => true]);

        $this->assertCount(4, $optionsSeen, 'primary x2 + fallback + companion count');
        foreach ($optionsSeen as $i => $options) {
            $this->assertArrayNotHasKey(
                'skip_security',
                $options,
                "SECURITY INVARIANT: executor call #{$i} must never receive skip_security"
            );
            $this->assertSame(13, $options['user']->id ?? null, 'the pipeline user still threads through');
        }
    }

    /** @test */
    public function a_caller_supplied_limit_option_never_overrides_the_per_source_row_cap(): void
    {
        // PHP's array union keeps left-side keys: a pipeline options array that
        // ever carried 'limit' would silently replace every source's cap AND
        // desync capHit (computed against the cap) from the actual truncation.
        // The per-source cap must be authoritative on primary AND fallback.
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $limits = [];
        $executor->shouldReceive('execute')
            ->twice()
            ->andReturnUsing(function ($cypher, $params, $options) use (&$limits) {
                $limits[] = $options['limit'] ?? null;
                if (str_contains($cypher, 'FALLBACK')) {
                    return $this->ok([['id' => 9]]);
                }
                return $this->ok([]);
            });

        $runner = new DigestRunner($executor, $this->registry([
            'meetings' => [
                'label' => 'Meetings',
                'cypher' => 'PRIMARY',
                'fallback_cypher' => 'FALLBACK',
                'fallback_label' => 'Recent',
                'limit' => 3,
            ],
        ]));

        $runner->run(['limit' => 50]);

        $this->assertSame([3, 3], $limits, 'primary and fallback both run with the per-source cap, never the caller limit');
    }

    /** @test */
    public function a_failed_execution_result_with_no_error_detail_is_an_error_source_never_an_honest_empty(): void
    {
        // QueryExecutorInterface is a public seam: a host implementation may
        // return success=false with empty errors[] and no safe_stop flag. That
        // is a FAILURE — rendering it as "nothing pending" would mask a broken
        // source as an honest empty (§3 step 4 forbids exactly that).
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->once()->andReturn(new QueryExecutionResult(
            success: false,
            data: [],
            stats: [],
            metadata: [],
            errors: [],
        ));

        $runner = new DigestRunner($executor, $this->registry([
            'flaky' => ['label' => 'Flaky', 'cypher' => 'MATCH (x) RETURN x'],
        ]));

        $results = $runner->run([]);

        $this->assertNotNull($results[0]->error, 'success=false must surface as an error line, never honest-empty');
        $this->assertSame(0, $results[0]->rowCount);
    }

    /** @test */
    public function enabled_sources_beyond_the_cap_are_skipped_with_a_logged_warning(): void
    {
        // Amendment B2: cap overflow must be LOUD — the skipped keys are exposed
        // by the registry (metadata.digest.skipped_sources) and the runner logs
        // a warning naming them, so a capped-out source is never mistaken for a
        // never-configured one.
        \Illuminate\Support\Facades\Log::spy();

        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->twice()->andReturn($this->ok([['id' => 1]]));

        $registry = $this->registry([
            'one' => ['label' => '1', 'cypher' => 'MATCH (n) RETURN n'],
            'two' => ['label' => '2', 'cypher' => 'MATCH (n) RETURN n'],
            'three' => ['label' => '3', 'cypher' => 'MATCH (n) RETURN n'],
        ], ['max_sources' => 2]);

        $results = (new DigestRunner($executor, $registry))->run([]);

        $this->assertCount(2, $results);
        $this->assertSame(['three'], $registry->skippedSourceKeys());

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => is_string($message) && str_contains($message, 'three'))
            ->once();
    }

    /** @test */
    public function one_failing_source_becomes_an_error_entry_and_never_kills_the_remaining_sources(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')
            ->twice()
            ->andReturnUsing(function ($cypher) {
                if (str_contains($cypher, ':Broken')) {
                    throw new \RuntimeException('rate limit exceeded');
                }
                return $this->ok([['id' => 1]]);
            });

        $runner = new DigestRunner($executor, $this->registry([
            'broken' => ['label' => 'Broken', 'cypher' => 'MATCH (b:Broken) RETURN b.id AS id'],
            'healthy' => ['label' => 'Healthy', 'cypher' => 'MATCH (h:Healthy) RETURN h.id AS id'],
        ]));

        $results = $runner->run([]);

        $this->assertNotNull($results[0]->error);
        $this->assertStringContainsString('rate limit', $results[0]->error);
        $this->assertNull($results[1]->error);
        $this->assertSame(1, $results[1]->rowCount);
    }

    /** @test */
    public function a_security_safe_stop_is_reported_as_an_error_not_as_an_honest_empty(): void
    {
        // Coverage-gate risk (design §6): a mis-authored query the analyzer cannot
        // prove scoped safe-stops to an empty result. Rendering that as "nothing
        // pending" would hide the misconfiguration — it must be LOUD.
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->once()->andReturn(new QueryExecutionResult(
            success: true,
            data: [],
            stats: [],
            metadata: ['security_filter_applied' => false, 'safe_stop' => true],
            errors: [],
        ));

        $runner = new DigestRunner($executor, $this->registry([
            'unprovable' => ['label' => 'Unprovable', 'cypher' => 'MATCH (x) RETURN x'],
        ]));

        $results = $runner->run([]);

        $this->assertNotNull($results[0]->error);
        $this->assertSame(0, $results[0]->rowCount);
    }

    /** @test */
    public function the_source_metadata_rides_the_result_for_composition(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->once()->andReturn($this->ok([]));

        $runner = new DigestRunner($executor, $this->registry([
            'reviews' => [
                'label' => 'Pending reviews',
                'description' => 'Contact changes waiting for review.',
                'cypher' => 'MATCH (r:Review) RETURN r.id AS id',
                'action_key' => 'contacts',
                'entity' => 'Review',
                'empty_text' => 'No pending reviews — inbox clear.',
            ],
        ]));

        $result = $runner->run([])[0];

        $this->assertSame('Pending reviews', $result->label);
        $this->assertSame('Contact changes waiting for review.', $result->description);
        $this->assertSame('contacts', $result->actionKey);
        $this->assertSame('Review', $result->entity);
        $this->assertSame('No pending reviews — inbox clear.', $result->emptyText);
    }

    /**
     * @test p12 H2/H3: the cypher that produced the rows rides the result — the composer maps
     * its projections to entity properties (declared money columns, enum legends). Fallback
     * rows carry the FALLBACK cypher (their projections are the fallback's own).
     */
    public function the_executed_cypher_rides_the_result_primary_or_fallback(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->andReturnUsing(function ($cypher) {
            if (str_contains($cypher, 'Bill')) {
                return $this->ok([['id' => 1, 'due_amount' => 10.5]]);
            }

            return str_contains($cypher, 'DESC') ? $this->ok([['id' => 9, 'name' => 'Last AGM']]) : $this->ok([]);
        });

        $runner = new DigestRunner($executor, $this->registry([
            'bills' => ['label' => 'Bills', 'cypher' => 'MATCH (b:Bill) RETURN b.id AS id, b.due_amount AS due_amount'],
            'meetings' => [
                'label' => 'Upcoming meetings',
                'cypher' => 'MATCH (m:Meeting) RETURN m.id AS id',
                'fallback_cypher' => 'MATCH (m:Meeting) RETURN m.id AS id, m.name AS name ORDER BY m.start_date DESC',
                'fallback_label' => 'Recent meetings',
            ],
            'empty' => ['label' => 'Empty', 'cypher' => 'MATCH (e:Empty) RETURN e.id AS id'],
        ]));

        [$bills, $meetings, $empty] = $runner->run([]);

        $this->assertSame('MATCH (b:Bill) RETURN b.id AS id, b.due_amount AS due_amount', $bills->cypher);
        $this->assertSame('MATCH (m:Meeting) RETURN m.id AS id, m.name AS name ORDER BY m.start_date DESC', $meetings->cypher);
        $this->assertSame('MATCH (e:Empty) RETURN e.id AS id', $empty->cypher);
        $this->assertSame($bills->cypher, $bills->withRows([['id' => 2]])->cypher, 'withRows keeps the cypher');
    }
}
