<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Query;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Services\Query\RecordParentEnricher;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Eval p18-B digest t1 ("When was our last meeting then?", 0.55 in four runs): a
 * single-record lookup showed only what the generator happened to project — the
 * union the meeting belongs to in 1 run of 4. One record whose id is known is
 * enriched with the display name of each many-to-one parent its entity config
 * declares, through the scoped executor (one query by id, no LLM call).
 */
class RecordParentEnricherTest extends TestCase
{
    private const LAST_MEETING = 'MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids AND m.start_date < toString(date()) '
        . 'RETURN m.name AS meeting_name, m.start_date AS date, m.id AS meeting_id ORDER BY m.start_date DESC LIMIT 1';

    private const ROW = ['meeting_name' => 'AGA 2023', 'date' => '2023-10-24 16:00', 'meeting_id' => 140];

    private $executor;

    /** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> */
    private array $executed = [];

    public function setUp(): void
    {
        parent::setUp();

        config()->set('logging.default', 'null');
        config()->set('ai.response_generation.identity_labels', ['user' => 'User', 'team' => 'Team']);
        config()->set('entities', $this->entities());
        $this->executor = Mockery::mock(QueryExecutorInterface::class);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> */
    private function entities(): array
    {
        return [
            'App\\Models\\Meeting' => [
                'graph' => [
                    'label' => 'Meeting',
                    'properties' => ['id', 'name', 'start_date', 'team_id', 'user_id', 'union_id'],
                    'relationships' => [
                        ['type' => 'HAS_MOTIONS', 'target_label' => 'Motion', 'direction' => 'outgoing'],
                        ['type' => 'HAS_FILES', 'target_label' => 'File', 'foreign_key' => 'parent_id', 'morph' => 'parent', 'direction' => 'outgoing'],
                        ['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id', 'direction' => 'outgoing'],
                        ['type' => 'BELONGS_TO_USER', 'target_label' => 'User', 'foreign_key' => 'user_id', 'direction' => 'outgoing'],
                        ['type' => 'BELONGS_TO_UNION', 'target_label' => 'Union', 'foreign_key' => 'union_id', 'direction' => 'outgoing'],
                    ],
                ],
            ],
            'App\\Models\\Union' => ['graph' => ['label' => 'Union', 'properties' => ['id', 'name', 'team_id']]],
            'App\\Models\\Team' => ['graph' => ['label' => 'Team', 'properties' => ['id', 'name']]],
            'App\\Models\\User' => ['graph' => ['label' => 'User', 'properties' => ['id', 'name']]],
            'App\\Models\\Motion' => ['graph' => ['label' => 'Motion', 'properties' => ['id', 'title', 'meeting_id']]],
        ];
    }

    private function enricher(): RecordParentEnricher
    {
        return new RecordParentEnricher($this->executor);
    }

    private function executorReturns(QueryExecutionResult|\Throwable|null $result = null): void
    {
        $this->executor->shouldReceive('execute')
            ->andReturnUsing(function ($query, $params, $options) use ($result) {
                $this->executed[] = [(string) $query, $params, $options];
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result ?? new QueryExecutionResult(success: true, data: [], metadata: ['security_filter_applied' => true]);
            });
    }

    private function rows(array $rows): QueryExecutionResult
    {
        return new QueryExecutionResult(success: true, data: $rows, metadata: ['security_filter_applied' => true]);
    }

    // ---------------------------------------------------------------- the case

    /** @test */
    public function a_single_record_gets_the_name_of_the_parent_its_entity_declares(): void
    {
        $this->executorReturns($this->rows([['p0_0' => 'SDC du 1240 St-Sébastien']]));

        $enriched = $this->enricher()->enrich(self::LAST_MEETING, [self::ROW], [
            'user' => (object) ['id' => 13],
            'rate_limit_batch' => 'turn:abc',
            'skip_security' => true,
            'limit' => 100,
        ], ['Meeting']);

        $this->assertNotNull($enriched);
        $this->assertSame(
            ['meeting_name' => 'AGA 2023', 'date' => '2023-10-24 16:00', 'meeting_id' => 140, 'union_name' => 'SDC du 1240 St-Sébastien'],
            $enriched['row'],
            'the parent name is appended; the original columns keep their order and values'
        );
        $this->assertSame(['union_name' => 'Union'], $enriched['columns']);
        $this->assertSame('Meeting', $enriched['entity']);

        // ONE query, by id, through the executor with the turn's own options.
        $this->assertCount(1, $this->executed);
        [$query, $params, $options] = $this->executed[0];
        $this->assertSame(
            'MATCH (n:Meeting) WHERE n.id = $ai_record_id OPTIONAL MATCH (n)-[:BELONGS_TO_UNION]->(p0:Union) RETURN p0.name AS p0_0 LIMIT 1',
            $query
        );
        $this->assertSame(['ai_record_id' => 140], $params, 'the id is BOUND with the type the graph returned it in');
        $this->assertSame(13, $options['user']->id);
        $this->assertSame('turn:abc', $options['rate_limit_batch'], 'rides the turn\'s single rate-limit charge');
        $this->assertArrayNotHasKey('skip_security', $options);
        $this->assertTrue($options['read_only']);
        $this->assertSame($query, $enriched['cypher']);
        $this->assertTrue($enriched['execution']->metadata['security_filter_applied']);
    }

    /** @test */
    public function the_identity_parents_are_the_scope_of_the_row_not_a_description_of_it(): void
    {
        // Team (the tenant) and User (the actor's own kind) are declared belongs-to parents
        // too; they are what every visible record shares, and are never added.
        $this->executorReturns($this->rows([['p0_0' => 'SDC du 1240 St-Sébastien']]));

        $enriched = $this->enricher()->enrich(self::LAST_MEETING, [self::ROW], [], ['Meeting']);

        $this->assertSame(['union_name'], array_keys($enriched['columns']));
        $this->assertStringNotContainsString(':Team', $this->executed[0][0]);
        $this->assertStringNotContainsString(':User', $this->executed[0][0]);
    }

    /** @test */
    public function a_parent_the_query_already_names_is_not_fetched_again(): void
    {
        $cypher = 'MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids OPTIONAL MATCH (m)-[:BELONGS_TO_UNION]->(un:Union) '
            . 'RETURN m.name AS meeting_name, un.name AS condo, m.id AS meeting_id LIMIT 1';
        $this->executor->shouldReceive('execute')->never();

        $enriched = $this->enricher()->enrich($cypher, [['meeting_name' => 'AGA 2023', 'condo' => 'SDC du 1240', 'meeting_id' => 140]], [], ['Meeting']);

        $this->assertNull($enriched, 'nothing to add: no query runs');
    }

    /** @test */
    public function a_column_already_carrying_the_parents_name_is_kept(): void
    {
        // A host source query (no parse of its aliases needed): the column is there.
        $this->executor->shouldReceive('execute')->never();

        $this->assertNull($this->enricher()->enrich(
            'MATCH (m:Meeting) RETURN m.id AS meeting_id, m.name AS name, m.union_label AS union_name LIMIT 1',
            [['meeting_id' => 140, 'name' => 'AGA 2023', 'union_name' => null]],
            [],
            ['Meeting']
        ));
    }

    /** @test */
    public function a_split_parent_name_is_joined_in_the_declared_order(): void
    {
        config()->set('entities', [
            'App\\Models\\BoardMember' => ['graph' => [
                'label' => 'BoardMember',
                'properties' => ['id', 'role', 'contact_id'],
                'relationships' => [['type' => 'BELONGS_TO_CONTACT', 'target_label' => 'Contact', 'foreign_key' => 'contact_id']],
            ]],
            'App\\Models\\Contact' => [
                'graph' => ['label' => 'Contact', 'properties' => ['id', 'first_name', 'last_name']],
                'metadata' => ['name_properties' => ['first_name', 'last_name']],
            ],
        ]);
        $this->executorReturns($this->rows([['p0_0' => 'Julie', 'p0_1' => 'Rousseau']]));

        $enriched = $this->enricher()->enrich(
            'MATCH (b:BoardMember) WHERE b.role = "president" RETURN b.role AS role, b.id AS board_member_id LIMIT 1',
            [['role' => 'president', 'board_member_id' => 'bm-7']],
            [],
            ['BoardMember']
        );

        $this->assertSame('Julie Rousseau', $enriched['row']['contact_name']);
        $this->assertStringContainsString('RETURN p0.first_name AS p0_0, p0.last_name AS p0_1 LIMIT 1', $this->executed[0][0]);
        $this->assertSame(['ai_record_id' => 'bm-7'], $this->executed[0][1]);
    }

    /** @test */
    public function several_parents_are_fetched_in_one_query_up_to_the_cap(): void
    {
        $relationships = [];
        $entities = [];
        foreach (['Union', 'Building', 'Supplier', 'Category', 'Fund'] as $parent) {
            $key = strtolower($parent) . '_id';
            $relationships[] = ['type' => 'BELONGS_TO_' . strtoupper($parent), 'target_label' => $parent, 'foreign_key' => $key];
            $entities['App\\Models\\' . $parent] = ['graph' => ['label' => $parent, 'properties' => ['id', 'name']]];
        }
        $entities['App\\Models\\Bill'] = ['graph' => [
            'label' => 'Bill',
            'properties' => ['id', 'number', 'union_id', 'building_id', 'supplier_id', 'category_id', 'fund_id'],
            'relationships' => $relationships,
        ]];
        config()->set('entities', $entities);
        $this->executorReturns($this->rows([['p0_0' => 'SDC A', 'p1_0' => null, 'p2_0' => 'Plomberie X']]));

        $enriched = $this->enricher()->enrich(
            'MATCH (b:Bill) WHERE b.number = "F-12" RETURN b.number AS number, b.id AS bill_id',
            [['number' => 'F-12', 'bill_id' => 9]],
            [],
            ['Bill']
        );

        $this->assertCount(1, $this->executed, 'ONE query for every parent');
        $this->assertSame(RecordParentEnricher::MAX_PARENTS, substr_count($this->executed[0][0], 'OPTIONAL MATCH'));
        $this->assertSame(
            ['number' => 'F-12', 'bill_id' => 9, 'union_name' => 'SDC A', 'supplier_name' => 'Plomberie X'],
            $enriched['row'],
            'a parent the record does not have adds no column'
        );
    }

    // ------------------------------------------------- the record's own detail fields

    /**
     * The Meeting of this class with more of its own properties, and the fields the host
     * declares worth showing on ONE meeting (`metadata.detail_properties`).
     *
     * @param list<mixed> $details
     * @param array<string, mixed> $meeting extra keys of the Meeting config
     */
    private function meetingDetails(array $details, array $meeting = []): void
    {
        $entities = $this->entities();
        $entities['App\\Models\\Meeting']['graph']['properties'] = ['id', 'name', 'start_date', 'team_id', 'user_id', 'union_id', 'place', 'president', 'type_id', 'notes', 'access_code'];
        $entities['App\\Models\\Meeting']['metadata'] = ['detail_properties' => $details];
        $entities['App\\Models\\Meeting'] = array_replace_recursive($entities['App\\Models\\Meeting'], $meeting);
        config()->set('entities', $entities);
    }

    /**
     * @test Eval p19-B digest t1: the union was shown since the parent lookup, the meeting's
     * PLACE — recorded — was not, because the generator did not project it. The fields the
     * host declares worth showing on a single record come back in the SAME query by id.
     */
    public function a_single_record_gets_its_own_declared_detail_fields_in_the_same_query(): void
    {
        $this->meetingDetails(['start_date', 'place', 'president']);
        $this->executorReturns($this->rows([['p0_0' => 'SDC du 1240 St-Sébastien', 'place' => 'ZOOM', 'president' => null]]));

        $enriched = $this->enricher()->enrich(self::LAST_MEETING, [self::ROW], ['user' => (object) ['id' => 13]], ['Meeting']);

        $this->assertCount(1, $this->executed, 'ONE query for the parents and the details');
        $this->assertSame(
            // start_date is already a column of the row (projected as "date"): not fetched again.
            'MATCH (n:Meeting) WHERE n.id = $ai_record_id OPTIONAL MATCH (n)-[:BELONGS_TO_UNION]->(p0:Union) '
                . 'RETURN p0.name AS p0_0, n.place AS place, n.president AS president LIMIT 1',
            $this->executed[0][0]
        );
        $this->assertSame(
            ['meeting_name' => 'AGA 2023', 'date' => '2023-10-24 16:00', 'meeting_id' => 140, 'place' => 'ZOOM', 'union_name' => 'SDC du 1240 St-Sébastien'],
            $enriched['row'],
            'the record\'s own fields, then what it belongs to — an EMPTY detail field adds no column'
        );
        $this->assertSame(['place'], $enriched['details']);
        $this->assertSame(['union_name' => 'Union'], $enriched['columns']);
    }

    /** @test Zero config: an entity that declares no detail field is enriched exactly as before. */
    public function without_declared_detail_fields_nothing_changes(): void
    {
        $this->executorReturns($this->rows([['p0_0' => 'SDC du 1240 St-Sébastien']]));

        $enriched = $this->enricher()->enrich(self::LAST_MEETING, [self::ROW], [], ['Meeting']);

        $this->assertStringEndsWith('RETURN p0.name AS p0_0 LIMIT 1', $this->executed[0][0]);
        $this->assertSame([], $enriched['details']);
    }

    /** @test A record with no parent to name is still shown with its own detail fields. */
    public function the_detail_fields_are_fetched_when_there_is_no_parent_to_name(): void
    {
        $this->meetingDetails(['place']);
        $cypher = 'MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids OPTIONAL MATCH (m)-[:BELONGS_TO_UNION]->(un:Union) '
            . 'RETURN m.name AS meeting_name, un.name AS condo, m.id AS meeting_id LIMIT 1';
        $this->executorReturns($this->rows([['place' => 'ZOOM']]));

        $enriched = $this->enricher()->enrich($cypher, [['meeting_name' => 'AGA 2023', 'condo' => 'SDC du 1240', 'meeting_id' => 140]], [], ['Meeting']);

        $this->assertSame('MATCH (n:Meeting) WHERE n.id = $ai_record_id RETURN n.place AS place LIMIT 1', $this->executed[0][0]);
        $this->assertSame(['meeting_name' => 'AGA 2023', 'condo' => 'SDC du 1240', 'meeting_id' => 140, 'place' => 'ZOOM'], $enriched['row']);
        $this->assertSame([], $enriched['columns']);
        $this->assertSame(['place'], $enriched['details']);
    }

    /**
     * @test Which declared fields are fetched: the entity's OWN plain properties, never a
     * sensitive column, never one the row already shows — under its own name, under
     * another alias or inside an expression — and at most MAX_DETAILS, in declared order.
     */
    public function only_own_non_sensitive_fields_the_row_does_not_show_are_fetched(): void
    {
        $this->meetingDetails(
            // a sensitive column · not a property of the entity · not an identifier · not a string · twice
            ['access_code', 'venue_capacity', 'n.place) DETACH DELETE (n', 42, 'place', 'place', 'president', 'notes'],
            ['security' => ['sensible_columns' => ['access_code']]]
        );
        $this->executorReturns($this->rows([['p0_0' => 'SDC A', 'place' => 'ZOOM', 'president' => 'J. Rousseau', 'notes' => 'Quorum reached']]));

        $enriched = $this->enricher()->enrich(self::LAST_MEETING, [self::ROW], [], ['Meeting']);

        $this->assertStringEndsWith('RETURN p0.name AS p0_0, n.place AS place, n.president AS president, n.notes AS notes LIMIT 1', $this->executed[0][0]);
        $this->assertSame(['place', 'president', 'notes'], $enriched['details']);

        // Already on the row: its own column, another alias, or inside an expression.
        foreach ([
            'm.place AS place' => ['place' => 'ZOOM'],
            'm.place AS venue' => ['venue' => 'ZOOM'],
            'toUpper(m.place) AS venue' => ['venue' => 'ZOOM'],
        ] as $projection => $column) {
            $this->executed = [];
            $this->meetingDetails(['place']);

            $this->enricher()->enrich(
                "MATCH (m:Meeting) WHERE m.team_id IN \$ai_team_ids RETURN m.name AS meeting_name, {$projection}, m.id AS meeting_id LIMIT 1",
                [['meeting_name' => 'AGA 2023'] + $column + ['meeting_id' => 140]],
                [],
                ['Meeting']
            );

            $this->assertStringEndsWith('RETURN p0.name AS p0_0 LIMIT 1', $this->executed[0][0], $projection);
        }

        // The cap.
        $this->executed = [];
        $this->meetingDetails(['place', 'president', 'notes', 'type_id', 'start_date', 'name']);
        $this->enricher()->enrich(
            'MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids RETURN m.id AS meeting_id LIMIT 1',
            [['meeting_id' => 140]],
            [],
            ['Meeting']
        );
        $this->assertSame(RecordParentEnricher::MAX_DETAILS, substr_count($this->executed[0][0], ' n.') - 1, 'n.id in the WHERE aside');
    }

    /**
     * @test A detail field that holds a CODE reads as its label, as every coded column of
     * a result does (the host's declared legend, in the answer's language when the host
     * ships that line) — the lookup's own projection is what the legend is found by.
     */
    public function a_coded_detail_field_reads_as_its_label(): void
    {
        $this->meetingDetails(['type_id', 'place'], ['metadata' => ['common_properties' => [
            'type_id' => 'Meeting type (1=Annual assembly, 5=Board meeting)',
        ]]]);
        app('translator')->addLines(['*.Annual assembly' => 'Assemblée annuelle'], 'fr');
        $this->executorReturns($this->rows([['p0_0' => 'SDC A', 'type_id' => 1, 'place' => 'ZOOM']]));

        $english = $this->enricher()->enrich(self::LAST_MEETING, [self::ROW], [], ['Meeting']);
        $french = $this->enricher()->enrich(self::LAST_MEETING, [self::ROW], [], ['Meeting'], 'fr');

        $this->assertSame('Annual assembly', $english['row']['type_id']);
        $this->assertSame('Assemblée annuelle', $french['row']['type_id']);
        $this->assertSame('ZOOM', $french['row']['place'], 'a plain value is untouched');
    }

    /** @test Nothing recorded in any declared field, and no parent: the row stands as returned. */
    public function a_record_with_nothing_to_add_is_left_alone(): void
    {
        $this->meetingDetails(['place', 'president']);
        $this->executorReturns($this->rows([['p0_0' => null, 'place' => '  ', 'president' => null]]));

        $this->assertNull($this->enricher()->enrich(self::LAST_MEETING, [self::ROW], [], ['Meeting']));
    }

    // ------------------------------------------------------- which parents count

    /** @test */
    public function only_declared_belongs_to_parents_that_are_askable_and_named_count(): void
    {
        config()->set('entities', [
            'App\\Models\\Schedule' => ['graph' => [
                'label' => 'Schedule',
                'properties' => ['id', 'title', 'meeting_id', 'schedule_id', 'balance_id', 'log_id'],
                'relationships' => [
                    // the parent has no `name` and declares no name properties
                    ['type' => 'BELONGS_TO_MEETING', 'target_label' => 'Meeting', 'foreign_key' => 'meeting_id'],
                    // a self reference: the header would read as the record's own name
                    ['type' => 'BELONGS_TO_PARENT_SCHEDULE', 'target_label' => 'Schedule', 'foreign_key' => 'schedule_id'],
                    // has-many: the key is the OTHER table's
                    ['type' => 'HAS_MOTIONS', 'target_label' => 'Motion', 'foreign_key' => 'motion_schedule_id'],
                    // structural-only entity
                    ['type' => 'BELONGS_TO_BALANCE', 'target_label' => 'AccountBalance', 'foreign_key' => 'balance_id'],
                    // not a configured entity
                    ['type' => 'BELONGS_TO_LOG', 'target_label' => 'AuditLog', 'foreign_key' => 'log_id'],
                    // polymorphic / pivot / inverse declarations
                    ['type' => 'PARENT', 'target_label' => '_polymorphic', 'foreign_key' => 'log_id'],
                    ['type' => 'TAGGED', 'target_label' => 'Motion', 'foreign_key' => 'meeting_id', 'pivot' => 'schedule_tag'],
                    ['type' => 'OWNED_BY', 'target_label' => 'Motion', 'foreign_key' => 'meeting_id', 'inverse' => true],
                    ['type' => 'INCOMING', 'target_label' => 'Motion', 'foreign_key' => 'meeting_id', 'direction' => 'incoming'],
                ],
            ]],
            'App\\Models\\Meeting' => ['graph' => ['label' => 'Meeting', 'properties' => ['id', 'title']]],
            'App\\Models\\Motion' => ['graph' => ['label' => 'Motion', 'properties' => ['id', 'name']]],
            'App\\Models\\AccountBalance' => ['graph' => ['label' => 'AccountBalance', 'properties' => ['id', 'name']], 'metadata' => ['askable' => false]],
        ]);
        $this->executor->shouldReceive('execute')->never();

        $this->assertNull($this->enricher()->enrich(
            'MATCH (s:Schedule) RETURN s.title AS title, s.id AS schedule_id LIMIT 1',
            [['title' => 'Budget', 'schedule_id' => 3]],
            [],
            ['Schedule']
        ));
    }

    /** @test */
    public function a_parent_whose_name_is_a_sensitive_column_is_never_fetched(): void
    {
        config()->set('entities.App\\Models\\Union.security', ['sensible_columns' => ['name']]);
        $this->executor->shouldReceive('execute')->never();

        $this->assertNull($this->enricher()->enrich(self::LAST_MEETING, [self::ROW], [], ['Meeting']));
    }

    // ------------------------------------------------------------ when it runs

    /** @test */
    public function it_never_runs_for_several_rows_an_aggregate_or_an_unknown_record(): void
    {
        $this->executor->shouldReceive('execute')->never();
        $enricher = $this->enricher();

        $this->assertNull($enricher->enrich(self::LAST_MEETING, [self::ROW, self::ROW], [], ['Meeting']), 'a list');
        $this->assertNull($enricher->enrich(self::LAST_MEETING, [], [], ['Meeting']), 'an empty result');
        $this->assertNull($enricher->enrich(
            'MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids RETURN m.name AS meeting_name, count(m) AS total, m.id AS meeting_id',
            [['meeting_name' => 'AGA 2023', 'total' => 1, 'meeting_id' => 140]],
            [],
            ['Meeting']
        ), 'an aggregating projection');
        $this->assertNull($enricher->enrich(
            'MATCH (m:Meeting) RETURN m.name AS meeting_name, m.start_date AS date LIMIT 1',
            [['meeting_name' => 'AGA 2023', 'date' => '2023-10-24']],
            [],
            ['Meeting']
        ), 'the record\'s id is not on the row');
        $this->assertNull($enricher->enrich(
            'MATCH (g:Gadget) RETURN g.name AS name, g.id AS gadget_id LIMIT 1',
            [['name' => 'X', 'gadget_id' => 4]],
            [],
            ['Gadget']
        ), 'not a configured entity');
        $this->assertNull($enricher->enrich(null, [self::ROW], [], ['Meeting']), 'no query');
    }

    /** @test */
    public function a_failed_blocked_or_empty_lookup_leaves_the_row_alone(): void
    {
        foreach ([
            'throws' => new \RuntimeException('rate limited'),
            'safe stop' => new QueryExecutionResult(success: true, data: [], metadata: ['safe_stop' => true]),
            'errors' => new QueryExecutionResult(success: false, data: [], errors: ['boom']),
            // The record is not visible to this user (another tenant's id): no row.
            'no row' => $this->rows([]),
            'no parent' => $this->rows([['p0_0' => null]]),
        ] as $case => $result) {
            $executor = Mockery::mock(QueryExecutorInterface::class);
            $executor->shouldReceive('execute')->once()->andReturnUsing(static function () use ($result) {
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            });

            $this->assertNull((new RecordParentEnricher($executor))->enrich(self::LAST_MEETING, [self::ROW], [], ['Meeting']), $case);
        }
    }
}
