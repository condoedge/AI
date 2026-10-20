<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Query;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Services\Digest\AttentionSourceRegistry;
use Condoedge\Ai\Services\Query\EmptyResultInspector;
use Condoedge\Ai\Services\Query\UserOwnershipPaths;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Eval p16 A#3 / B#1 — what an EMPTY result means, established from the executed
 * query and host config through the scoped executor (no LLM call):
 *  - its SCOPE (the user's own records vs the whole team's);
 *  - for an own-data query, whether the user has ANY record of the entity the
 *    question hangs on (the configured ownership path);
 *  - for a team query, the labeled fallback rows the host declared for the entity.
 */
class EmptyResultInspectorTest extends TestCase
{
    private const CONTRIBUTION = "MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL "
        . "MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE substring(i.invoiced_at, 0, 4) = '2026' "
        . 'RETURN i.invoice_number AS invoice_number, i.due_amount AS contribution LIMIT 100';

    private const NEXT_MEETING = 'MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids AND m.start_date > toString(date()) '
        . 'RETURN m.name AS meeting_name, m.start_date AS start_date ORDER BY m.start_date ASC LIMIT 1';

    private const UNIT_PATH = 'MATCH (c:Contact {user_id: $userId})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(n:Unit) WHERE cu.sold_at IS NULL';

    private const PRIMARY = 'MATCH (m:Meeting) WHERE m.start_date >= toString(date()) RETURN m.id AS id, m.name AS name ORDER BY m.start_date ASC';
    private const FALLBACK = 'MATCH (m:Meeting) WHERE m.start_date < toString(date()) RETURN m.id AS id, m.name AS name ORDER BY m.start_date DESC';

    private $executor;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('logging.default', 'null');
        config()->set('entities', [
            'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['display_name' => 'condo unit']],
        ]);
        $this->executor = Mockery::mock(QueryExecutorInterface::class);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function inspector(?array $sources = null): EmptyResultInspector
    {
        return new EmptyResultInspector(
            $this->executor,
            new AttentionSourceRegistry($sources ?? $this->meetingSources(), ['default_row_cap' => 5]),
            new UserOwnershipPaths([
                'Unit' => ['cypher' => self::UNIT_PATH],
                'Contact' => ['cypher' => 'MATCH (n:Contact {user_id: $userId})'],
            ], 'User'),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function meetingSources(): array
    {
        return [
            'upcoming_meetings' => [
                'label' => 'Upcoming meetings',
                'cypher' => self::PRIMARY,
                'fallback_cypher' => self::FALLBACK,
                'fallback_label' => 'Most recent meetings — none scheduled',
                'limit' => 3,
                'entity' => 'Meeting',
                'action_key' => 'meetings',
            ],
        ];
    }

    private function emptyRun(bool $scoped = true): QueryExecutionResult
    {
        return new QueryExecutionResult(success: true, data: [], metadata: ['security_filter_applied' => $scoped]);
    }

    // ------------------------------------------------------------------ scope

    /** @test */
    public function the_scope_comes_from_the_executed_query_not_from_any_possessive_judgment(): void
    {
        $inspector = $this->inspector();

        $this->assertSame('own', $inspector->scope(self::CONTRIBUTION, $this->emptyRun(), 13));
        $this->assertSame('team', $inspector->scope(self::NEXT_MEETING, $this->emptyRun(), 13), 'tenant-scoped, not anchored on the user');
        $this->assertNull($inspector->scope(self::NEXT_MEETING, $this->emptyRun(false), 13), 'no tenant filter ran: no scope is claimed');
        $this->assertNull($inspector->scope(null, $this->emptyRun(), 13));
    }

    // ------------------------------------------------- aggregate over nothing

    /** @test */
    public function a_global_aggregate_row_of_empty_values_is_an_aggregate_over_nothing(): void
    {
        // Live 2026-10-06: the same "my contribution" question came back as
        // `RETURN sum(n.total_amount) AS contribution_amount` → one row {0} → "$0.00".
        // A RETURN of aggregates only always yields ONE row, whatever it matched.
        $inspector = $this->inspector();
        $sum = 'MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) MATCH (n:Invoice)-[:CUSTOMER]->(u) '
            . 'RETURN sum(n.total_amount) AS contribution_amount';

        $this->assertTrue($inspector->isAggregateOverNothing($sum, [['contribution_amount' => 0]]));
        $this->assertTrue($inspector->isAggregateOverNothing($sum, [['contribution_amount' => 0.0]]));
        $this->assertTrue($inspector->isAggregateOverNothing(
            'MATCH (i:Invoice) RETURN round(sum(i.due_amount), 2) AS due, count(i) AS invoices, collect(i.invoice_number) AS numbers, min(i.due_at) AS oldest LIMIT 100',
            [['due' => 0.0, 'invoices' => 0, 'numbers' => [], 'oldest' => null]]
        ));

        $this->assertFalse($inspector->isAggregateOverNothing($sum, [['contribution_amount' => 1250.5]]), 'a real figure');
        $this->assertFalse($inspector->isAggregateOverNothing($sum, []), 'no row at all is a plain empty result');
        $this->assertFalse($inspector->isAggregateOverNothing(
            'MATCH (u:Unit) RETURN u.name AS unit_name, sum(u.balance) AS balance',
            [['unit_name' => null, 'balance' => 0]]
        ), 'a grouping key: the row is a record, not a global aggregate');
        $this->assertFalse($inspector->isAggregateOverNothing(
            'MATCH (u:Unit) RETURN u.balance AS balance',
            [['balance' => 0]]
        ), 'a stored zero is a fact about a record');
        $this->assertFalse($inspector->isAggregateOverNothing($sum, [['contribution_amount' => 0], ['contribution_amount' => 0]]));
    }

    // -------------------------------------------------------------- ownership

    /** @test */
    public function an_own_data_empty_is_explained_by_one_count_of_the_ownership_path(): void
    {
        $seen = [];
        $this->executor->shouldReceive('execute')->once()
            ->andReturnUsing(function ($cypher, $params, $options) use (&$seen) {
                $seen[] = [$cypher, $params, $options];

                return new QueryExecutionResult(success: true, data: [['total' => 0]], metadata: ['security_filter_applied' => true]);
            });

        $check = $this->inspector()->ownershipCheck(self::CONTRIBUTION, 13, [
            'user' => (object) ['id' => 13], 'rate_limit_batch' => 'turn:abc', 'skip_security' => true,
        ]);

        $this->assertSame(['entity' => 'Unit', 'display_name' => 'condo unit', 'count' => 0], $check);
        $this->assertSame(
            'MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(n:Unit) WHERE cu.sold_at IS NULL RETURN count(DISTINCT n) AS total',
            $seen[0][0]
        );
        $this->assertSame([], $seen[0][1]);
        $this->assertSame('turn:abc', $seen[0][2]['rate_limit_batch'], 'rides the turn\'s single rate-limit charge');
        $this->assertSame(13, $seen[0][2]['user']->id, 'same identity options as the turn\'s query');
        $this->assertArrayNotHasKey('skip_security', $seen[0][2], 'a security bypass is never forwarded');
    }

    /** @test */
    public function the_count_is_reported_when_the_user_does_have_such_records(): void
    {
        $this->executor->shouldReceive('execute')->once()
            ->andReturn(new QueryExecutionResult(success: true, data: [['total' => 2]]));

        $this->assertSame(2, $this->inspector()->ownershipCheck(self::CONTRIBUTION, 13, [])['count'] ?? null);
    }

    /** @test */
    public function no_ownership_fact_without_a_trustworthy_count(): void
    {
        // Not anchored on the user: no execution at all.
        $this->assertNull($this->inspector()->ownershipCheck(self::NEXT_MEETING, 13, []));

        $this->executor->shouldReceive('execute')->times(3)->andReturn(
            new QueryExecutionResult(success: true, data: [], metadata: ['safe_stop' => true]),
            new QueryExecutionResult(success: false, errors: ['boom']),
            new QueryExecutionResult(success: true, data: [['total' => null]]),
        );
        $this->assertNull($this->inspector()->ownershipCheck(self::CONTRIBUTION, 13, []), 'safe-stopped');
        $this->assertNull($this->inspector()->ownershipCheck(self::CONTRIBUTION, 13, []), 'failed');
        $this->assertNull($this->inspector()->ownershipCheck(self::CONTRIBUTION, 13, []), 'no number');
    }

    /** @test */
    public function a_throwing_ownership_count_never_costs_the_turn(): void
    {
        $this->executor->shouldReceive('execute')->once()->andThrow(new \RuntimeException('rate limited'));

        $this->assertNull($this->inspector()->ownershipCheck(self::CONTRIBUTION, 13, []));
    }

    // --------------------------------------------------------------- fallback

    /** @test */
    public function the_fallback_runs_only_after_the_sources_own_query_confirmed_there_is_nothing(): void
    {
        $seen = [];
        $rows = [['id' => 140, 'name' => 'AGA 2023'], ['id' => 131, 'name' => 'CA sept. 2023']];
        $this->executor->shouldReceive('execute')->twice()
            ->andReturnUsing(function ($cypher, $params, $options) use (&$seen, $rows) {
                $seen[] = [$cypher, $options];

                return $cypher === self::PRIMARY
                    ? new QueryExecutionResult(success: true, data: [], metadata: ['security_filter_applied' => true])
                    : new QueryExecutionResult(success: true, data: $rows, metadata: ['security_filter_applied' => true]);
            });

        $fallback = $this->inspector()->sourceFallback('Meeting', [
            'user' => (object) ['id' => 13], 'rate_limit_batch' => 'turn:abc', 'limit' => 100, 'skip_security' => true,
        ]);

        $this->assertNotNull($fallback);
        $this->assertSame('upcoming_meetings', $fallback['source']);
        $this->assertSame('Upcoming meetings', $fallback['label']);
        $this->assertSame('Most recent meetings — none scheduled', $fallback['fallback_label']);
        $this->assertSame('Meeting', $fallback['entity']);
        $this->assertSame(self::FALLBACK, $fallback['cypher']);
        $this->assertSame(3, $fallback['limit']);
        $this->assertSame($rows, $fallback['execution']->data);

        $this->assertSame([self::PRIMARY, self::FALLBACK], array_column($seen, 0), 'primary first, then the fallback');
        foreach ($seen as [, $options]) {
            $this->assertSame(3, $options['limit'], 'the source\'s own row cap');
            $this->assertSame('turn:abc', $options['rate_limit_batch']);
            $this->assertSame(13, $options['user']->id);
            $this->assertArrayNotHasKey('skip_security', $options);
        }
    }

    /** @test */
    public function no_fallback_when_the_sources_own_query_has_rows(): void
    {
        // Upcoming meetings DO exist: the empty question was about something narrower —
        // "none scheduled" would be false, so the labeled fallback must not run.
        $this->executor->shouldReceive('execute')->once()->with(self::PRIMARY, [], Mockery::any())
            ->andReturn(new QueryExecutionResult(success: true, data: [['id' => 200, 'name' => 'AGA 2027']]));

        $this->assertNull($this->inspector()->sourceFallback('Meeting', []));
    }

    /** @test */
    public function no_fallback_without_exactly_one_source_for_the_entity(): void
    {
        $this->executor->shouldNotReceive('execute');

        $this->assertNull($this->inspector()->sourceFallback('Invoice', []), 'no source declares the entity');
        $this->assertNull($this->inspector()->sourceFallback(null, []));

        $ambiguous = $this->meetingSources() + [
            'board_meetings' => ['label' => 'Board meetings', 'cypher' => 'MATCH (m:Meeting) RETURN m.id AS id', 'entity' => 'Meeting'],
        ];
        $this->assertNull($this->inspector($ambiguous)->sourceFallback('Meeting', []), 'two sources for one entity');

        $noFallback = ['tasks' => ['label' => 'Open tasks', 'cypher' => 'MATCH (t:Task) RETURN t.id AS id', 'entity' => 'Task']];
        $this->assertNull($this->inspector($noFallback)->sourceFallback('Task', []));
    }

    /** @test */
    public function a_failed_or_empty_fallback_leaves_the_honest_empty(): void
    {
        $empty = new QueryExecutionResult(success: true, data: []);

        $this->executor->shouldReceive('execute')->times(2)->andReturn($empty, $empty);
        $this->assertNull($this->inspector()->sourceFallback('Meeting', []), 'the fallback found nothing either');

        $this->executor->shouldReceive('execute')->times(2)->andReturn(
            $empty,
            new QueryExecutionResult(success: true, data: [], metadata: ['safe_stop' => true]),
        );
        $this->assertNull($this->inspector()->sourceFallback('Meeting', []), 'safe-stopped fallback');

        $this->executor->shouldReceive('execute')->once()->andReturn(new QueryExecutionResult(success: false, errors: ['x']));
        $this->assertNull($this->inspector()->sourceFallback('Meeting', []), 'a failed primary proves nothing');

        $this->executor->shouldReceive('execute')->once()->andThrow(new \RuntimeException('down'));
        $this->assertNull($this->inspector()->sourceFallback('Meeting', []), 'never throws');
    }
}
