<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Chat\Exporter;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Services\Chat\Exporter\RecordedQueryRerun;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The re-run of a message's RECORDED query for a full export (eval p18-A roster: the
 * answer shows 25 of 523 and its stored rows are the executor's first 100).
 *
 * Pinned here:
 *  - the display cap is widened with FanOutCollapse::uncappedQuery semantics, up to
 *    ai.export.document_row_limit (probed with ONE extra row, so "complete" is a fact);
 *  - ONE execution through the scoped executor = one rate-limit charge, as the ACTING
 *    user, read-only — nothing stored can widen it (only `$pii_…` bindings are replayed);
 *  - a query that must not be widened (the user's own top-N, a page) is run as recorded.
 */
class RecordedQueryRerunTest extends TestCase
{
    private const ROSTER = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'RETURN u.name AS name, u.email AS email LIMIT 100';

    public function setUp(): void
    {
        parent::setUp();

        config([
            'ai.query_execution.default_limit' => 100,
            'ai.export.document_row_limit' => 600,
            'ai.export.pdf_document_row_limit' => null,
        ]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function members(int $count): array
    {
        return array_map(static fn (int $i): array => ['name' => "Member {$i}", 'email' => "m{$i}@example.com"], range(1, $count));
    }

    private function user(int $id = 13): object
    {
        return (object) ['id' => $id];
    }

    /** An executor that answers once and records what it was asked. */
    private function executor(array $rows, ?array &$seen, array $metadata = []): QueryExecutorInterface
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->once()->andReturnUsing(
            function (?string $cypher, array $parameters, array $options) use (&$seen, $rows, $metadata) {
                $seen = ['cypher' => $cypher, 'parameters' => $parameters, 'options' => $options];

                return new QueryExecutionResult(success: true, data: $rows, stats: [], metadata: $metadata);
            }
        );

        return $executor;
    }

    /** @test */
    public function the_display_cap_is_widened_up_to_the_document_limit_and_every_row_comes_back(): void
    {
        $result = (new RecordedQueryRerun($this->executor($this->members(523), $seen)))->run(self::ROSTER, [], $this->user());

        $this->assertStringEndsWith('RETURN u.name AS name, u.email AS email LIMIT 601', $seen['cypher'], 'the document limit plus the one probe row');
        $this->assertSame(601, $seen['options']['limit']);
        $this->assertCount(523, $result['rows']);
        $this->assertTrue($result['complete']);
    }

    /** @test */
    public function it_runs_once_as_the_acting_user_read_only_with_nothing_else_threaded(): void
    {
        $user = $this->user(13);

        (new RecordedQueryRerun($this->executor($this->members(3), $seen)))->run(self::ROSTER, [], $user, [
            'conversation_id' => 77,
            // Nothing a caller (or stored data) passes may weaken the execution.
            'read_only' => false,
            'skip_security' => true,
            'user' => $this->user(999),
            'rate_limit_batch' => 'free-ride',
            'ai_team_ids' => [1, 2, 3],
        ]);

        $this->assertSame($user, $seen['options']['user'], 'the acting user scopes the query');
        $this->assertTrue($seen['options']['read_only']);
        $this->assertSame(77, $seen['options']['conversation_id']);
        $this->assertSame(
            ['conversation_id', 'limit', 'original_question', 'read_only', 'user'],
            (function (array $keys) { sort($keys); return $keys; })(array_keys($seen['options'])),
            'one un-batched execution: one rate-limit charge, the executor\'s own tenant scoping'
        );
    }

    /** @test */
    public function only_the_recorded_pii_bindings_are_replayed(): void
    {
        $recorded = [
            'pii_0123456789ab' => 'ana@example.com',
            'ai_team_ids' => [1, 2, 3],
            'pii_short' => 'x',
            'pii_0123456789ac' => ['not', 'a', 'string'],
            7 => 'positional',
        ];

        (new RecordedQueryRerun($this->executor($this->members(1), $seen)))->run(self::ROSTER, $recorded, $this->user());

        $this->assertSame(['pii_0123456789ab' => 'ana@example.com'], $seen['parameters']);
        $this->assertSame(['pii_0123456789ab' => 'ana@example.com'], RecordedQueryRerun::boundParameters($recorded));
        $this->assertSame([], RecordedQueryRerun::boundParameters('not-an-array'));
    }

    /** @test */
    public function more_rows_than_the_document_limit_are_cut_and_reported_incomplete(): void
    {
        config(['ai.export.document_row_limit' => 250]);

        $result = (new RecordedQueryRerun($this->executor($this->members(251), $seen)))->run(self::ROSTER, [], $this->user());

        $this->assertStringEndsWith('LIMIT 251', $seen['cypher']);
        $this->assertCount(250, $result['rows']);
        $this->assertFalse($result['complete']);
    }

    /** @test */
    public function the_pdf_limit_bounds_a_pdf_rerun(): void
    {
        config(['ai.export.pdf_document_row_limit' => 150]);

        (new RecordedQueryRerun($this->executor($this->members(5), $seen)))->run(self::ROSTER, [], $this->user(), ['format' => 'pdf']);

        $this->assertStringEndsWith('LIMIT 151', $seen['cypher']);
    }

    /** @test */
    public function a_query_that_must_not_be_widened_runs_as_recorded(): void
    {
        $topFive = 'MATCH (u:Unit) RETURN u.name AS name ORDER BY u.balance DESC LIMIT 5';

        $result = (new RecordedQueryRerun($this->executor($this->members(5), $seen)))->run($topFive, [], $this->user());

        $this->assertSame($topFive, $seen['cypher'], 'the user\'s own top-N is never widened');
        $this->assertSame(100, $seen['options']['limit']);
        $this->assertCount(5, $result['rows']);
        $this->assertTrue($result['complete'], 'fewer rows than the executor cap: everything the query returns');
    }

    /** @test */
    public function collapsed_list_columns_are_joined_like_the_document_export(): void
    {
        $rows = [['name' => 'Ana', 'emails' => ['a@example.com', 'b@example.com']]];

        $result = (new RecordedQueryRerun($this->executor($rows, $seen)))->run(self::ROSTER, [], $this->user());

        $this->assertSame([['name' => 'Ana', 'emails' => 'a@example.com; b@example.com']], $result['rows']);
    }

    /** @test */
    public function a_safe_stop_or_an_errored_execution_returns_no_rows(): void
    {
        $safeStop = (new RecordedQueryRerun($this->executor([], $seen, ['safe_stop' => true])))->run(self::ROSTER, [], $this->user());
        $this->assertSame([], $safeStop['rows']);

        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->once()->andReturn(
            new QueryExecutionResult(success: false, data: $this->members(2), stats: [], metadata: [], errors: ['boom'])
        );
        $errored = (new RecordedQueryRerun($executor))->run(self::ROSTER, [], $this->user());
        $this->assertSame([], $errored['rows'], 'rows of a failed execution are never exported');
    }

    /** @test */
    public function a_blank_query_is_never_executed(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldNotReceive('execute');

        $this->assertSame([], (new RecordedQueryRerun($executor))->run('   ', [], $this->user())['rows']);
    }

    /** @test */
    public function without_an_acting_user_nothing_is_executed(): void
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldNotReceive('execute');

        // Never left to the executor's auth() fallback: the caller states who is asking.
        $this->assertSame([], (new RecordedQueryRerun($executor))->run(self::ROSTER, [], null)['rows']);
        $this->assertSame([], (new RecordedQueryRerun($executor))->run(self::ROSTER, [], 13)['rows']);
    }

    /**
     * @test
     * @dataProvider coverage
     */
    public function it_tells_whether_a_rerun_would_hold_every_row(bool $expected, ?string $cypher, int $total, int $documentLimit): void
    {
        config(['ai.export.document_row_limit' => $documentLimit]);

        $this->assertSame($expected, RecordedQueryRerun::coversAll($cypher, $total));
    }

    /** @test */
    public function a_query_bound_to_more_than_pii_tokens_cannot_be_replayed(): void
    {
        $this->assertTrue(RecordedQueryRerun::coversAll(self::ROSTER, 523, null, []));
        $this->assertTrue(RecordedQueryRerun::coversAll(self::ROSTER, 523, null, ['pii_0123456789ab' => 'ana@example.com']));
        $this->assertFalse(RecordedQueryRerun::coversAll(self::ROSTER, 523, null, ['pii_0123456789ab' => 'ana@example.com', 'since' => '2026-01-01']));
        $this->assertFalse(RecordedQueryRerun::coversAll(self::ROSTER, 523, null, ['pii_0123456789ab' => ['a list']]));
    }

    public static function coverage(): array
    {
        $paged = 'MATCH (u:Unit) RETURN u.name AS name SKIP 100 LIMIT 100';

        return [
            'the whole population fits the document' => [true, self::ROSTER, 523, 600],
            'exactly the document limit' => [true, self::ROSTER, 600, 600],
            'more rows than a document may hold' => [false, self::ROSTER, 601, 600],
            'a document no larger than the display cap' => [false, self::ROSTER, 90, 100],
            'no recorded query' => [false, null, 523, 600],
            'a blank query' => [false, '  ', 523, 600],
            'a page of a list (SKIP)' => [false, $paged, 523, 600],
            'the user\'s own top-N' => [false, 'MATCH (u:Unit) RETURN u.name AS name ORDER BY u.name LIMIT 5', 523, 600],
            'an unknown total' => [false, self::ROSTER, 0, 600],
        ];
    }
}
