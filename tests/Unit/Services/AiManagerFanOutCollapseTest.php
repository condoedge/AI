<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Query\FanOutCollapse;
use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Services\Security\Scope\FilterClause;
use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Services\Security\SecurityFilterInjector;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Eval p11 D2 — a capped LIST whose companion counts PROVE a fan-out
 * (true_total rows > anchor_total distinct anchors) is re-run as one row per
 * anchor record (FanOutCollapse::perAnchorQuery, same scoped executor), so the
 * display, the disclosed population and any export reason in records:
 *  - p11-A excel: ContactDirectory fan-out, 100 of 1354 rows for 523 members;
 *  - p11-B resilience: OPTIONAL User over TeamRoles, 525 rows for 523 members
 *    (two TeamRoles without a user);
 *  - p11-B cold-roster: Contact fan-out, 100 of 530 rows.
 */
class AiManagerFanOutCollapseTest extends TestCase
{
    private AiManager $manager;
    private ContextRetrieverInterface $mockContext;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;

    private const EXCEL_CONTACTS = "MATCH (t:Team) WHERE t.id IN \$ai_team_ids AND t.id = 3\n"
        . "OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t)\n"
        . "OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id\n"
        . "OPTIONAL MATCH (c:Contact)-[:USER]->(u)\n"
        . "OPTIONAL MATCH (cd:ContactDirectory)-[:CONTACT]->(c)\n"
        . "RETURN u.name AS member_name, tr.role AS role, cd.value AS contact_info LIMIT 100";

    private const ROLES_WITH_OPTIONAL_USER = 'MATCH (t:Team) WHERE t.id = 3 '
        . 'MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) '
        . 'OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id '
        . 'RETURN u.name AS member_name, tr.role AS role LIMIT 100';

    private const COLD_ROSTER_CONTACTS = 'MATCH (t:Team) WHERE t.id = 3 '
        . 'MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'OPTIONAL MATCH (c:Contact)-[:USER]->(u) '
        . 'RETURN u.name AS name, u.email AS email, c.phone AS phone LIMIT 100';

    public function setUp(): void
    {
        parent::setUp();

        config()->set('logging.default', 'null');
        config()->set('ai.log_channel', 'null');
        config()->set('ai.file_context.enabled', false);
        config()->set('ai.query_execution.default_limit', 100);
        config()->set('ai.attached_actions.project_record_ids', false);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);

        $this->manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            Mockery::mock(EmbeddingProviderInterface::class),
            Mockery::mock(LlmProviderInterface::class),
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            Mockery::mock(VectorStoreInterface::class)
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function turn(string $cypher, string $intent = 'list', ?string $exportFormat = null): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(
            entityTypes: ['Team', 'TeamRole', 'User'],
            intent: $intent,
            questionType: 'data_query',
            exportFormat: $exportFormat,
            language: 'en',
        );
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: $cypher, explanation: '', confidence: 0.9));
    }

    /**
     * Executor: the generated query returns 100 fanned-out rows, executeCount the
     * ROW total, the count(DISTINCT anchor) query the anchor total, and the
     * per-anchor rewrite ($collapsedRows) one row per anchor with list columns.
     *
     * @param array<int, string> $seen every executed query, in order
     */
    private function executor(string $cypher, int $rowTotal, int $anchorTotal, ?array $collapsedRows, array &$seen): void
    {
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturnUsing(function ($query, $params, $options) use ($cypher, $anchorTotal, $collapsedRows, &$seen) {
                $seen[] = $query;
                if ($query === $cypher) {
                    $rows = [];
                    for ($i = 1; $i <= 100; $i++) {
                        $member = intdiv($i + 1, 2);
                        $rows[] = ['member_name' => "Member {$member}", 'role' => 'owner', 'contact_info' => "c{$i}@x.test"];
                    }
                    return new QueryExecutionResult(success: true, data: $rows, metadata: ['security_filter_applied' => true]);
                }
                if (preg_match('/count\(DISTINCT\s+\w+\)\s+AS\s+total$/', $query) === 1) {
                    return new QueryExecutionResult(success: true, data: [['total' => $anchorTotal]]);
                }
                $this->assertSame(13, $options['user']->id ?? null, 'the per-anchor re-run carries the same identity options');
                if ($collapsedRows === null) {
                    throw new \RuntimeException('collapsed query failed');
                }

                return new QueryExecutionResult(success: true, data: $collapsedRows, metadata: ['security_filter_applied' => true]);
            });
        $this->mockQueryExecutor->shouldReceive('executeCount')->once()->andReturn($rowTotal);
    }

    private function captureResponse(?PipelineContext &$captured): void
    {
        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (PipelineContext $ctx) use (&$captured) {
                $captured = $ctx;
                return ['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []];
            });
    }

    /** @return array<int, array<string, mixed>> */
    private function perMember(int $count, bool $withContacts = true): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['member_name' => "Member {$i}", 'role' => ['owner']]
                + ($withContacts ? ['contact_info' => ["m{$i}@x.test", "+1 514 555 {$i}", "m{$i}@x.test"]] : []);
        }

        return $rows;
    }

    /** @test */
    public function the_live_contact_directory_fan_out_is_rerun_one_row_per_member(): void
    {
        $this->turn(self::EXCEL_CONTACTS);
        $seen = [];
        $this->executor(self::EXCEL_CONTACTS, 1354, 523, $this->perMember(100), $seen);
        $captured = null;
        $this->captureResponse($captured);

        $result = $this->manager->answerQuestion('Who are my team members and their contact info?', ['user' => (object) ['id' => 13]]);

        $this->assertCount(3, $seen, 'data fetch, anchor count, per-anchor re-run — the collapsed population needs no extra count');
        $this->assertStringContainsString('WITH u, collect(DISTINCT tr.role)[0..11] AS role, collect(DISTINCT cd.value)[0..11] AS contact_info WHERE u IS NOT NULL', $seen[2]);
        $this->assertStringContainsString('MATCH (t:Team) WHERE t.id IN $ai_team_ids AND t.id = 3', $seen[2], 'scoping clauses kept verbatim');

        $execution = $captured->executionResult;
        $this->assertCount(100, $execution->data);
        $this->assertSame(['member_name' => 'Member 1', 'role' => 'owner', 'contact_info' => 'm1@x.test; +1 514 555 1'], $execution->data[0]);
        // The disclosed population is the 523 members, never the 1354 joined rows.
        $this->assertSame(523, $execution->metadata['true_total']);
        $this->assertSame(523, $execution->stats['true_total']);
        $this->assertSame(['row_total' => 1354, 'anchor_total' => 523, 'anchor_label' => 'User'], $execution->metadata['fan_out_collapsed']);
        $this->assertTrue($execution->metadata['security_filter_applied']);

        // The collapsed query is the turn's query (recorded for follow-ups / exports).
        $this->assertSame($seen[2], $result['cypher']);
        $this->assertSame(523, $result['metadata']['execution']['true_total']);
    }

    /** @test */
    public function team_roles_without_a_user_do_not_inflate_the_member_population(): void
    {
        // p11-B resilience t0/t1/t3: 525 rows, 523 distinct users — the two extra
        // rows are TeamRoles whose OPTIONAL User matched nothing.
        $this->turn(self::ROLES_WITH_OPTIONAL_USER);
        $seen = [];
        $this->executor(self::ROLES_WITH_OPTIONAL_USER, 525, 523, $this->perMember(100, false), $seen);
        $captured = null;
        $this->captureResponse($captured);

        $this->manager->answerQuestion('Who are the members of my team and what are their roles?', ['user' => (object) ['id' => 13]]);

        $this->assertStringEndsWith(
            'WITH u, collect(DISTINCT tr.role)[0..11] AS role WHERE u IS NOT NULL RETURN u.name AS member_name, role LIMIT 100',
            $seen[2]
        );
        $this->assertSame(523, $captured->executionResult->metadata['true_total']);
        $this->assertSame('owner', $captured->executionResult->data[0]['role']);
    }

    /** @test */
    public function the_cold_roster_contact_fan_out_is_collapsed_too(): void
    {
        // p11-B cold-roster: "100 of 530" for 523 members (Contact fan-out).
        $this->turn(self::COLD_ROSTER_CONTACTS);
        $seen = [];
        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = ['name' => "Member {$i}", 'email' => "m{$i}@x.test", 'phone' => ["514-{$i}", "438-{$i}"]];
        }
        $this->executor(self::COLD_ROSTER_CONTACTS, 530, 523, $rows, $seen);
        $captured = null;
        $this->captureResponse($captured);

        $this->manager->answerQuestion('List my team members', ['user' => (object) ['id' => 13]]);

        $this->assertStringContainsString('WITH u, collect(DISTINCT c.phone)[0..11] AS phone WHERE u IS NOT NULL RETURN u.name AS name, u.email AS email, phone', $seen[2]);
        $this->assertSame(523, $captured->executionResult->metadata['true_total']);
        $this->assertSame('514-1; 438-1', $captured->executionResult->data[0]['phone']);
    }

    /** @test */
    public function an_anchor_pinned_to_one_value_is_not_collapsed(): void
    {
        // "Payments of unit 310": the rows are the joined records of ONE anchor.
        $cypher = "MATCH (u:Unit)-[:UNION]->(un:Union) WHERE u.name = '310' OPTIONAL MATCH (p:Payment)-[:UNIT]->(u) RETURN u.name AS unit, p.amount AS amount LIMIT 100";
        $this->turn($cypher);
        $seen = [];
        $this->executor($cypher, 340, 3, $this->perMember(1), $seen);
        $captured = null;
        $this->captureResponse($captured);

        $this->manager->answerQuestion('payments of unit 310', ['user' => (object) ['id' => 13]]);

        $this->assertCount(2, $seen, 'no per-anchor re-run');
        $this->assertSame(340, $captured->executionResult->metadata['true_total']);
        $this->assertArrayNotHasKey('fan_out_collapsed', $captured->executionResult->metadata);
    }

    /** @test */
    public function aggregate_count_and_compare_intents_are_not_collapsed(): void
    {
        foreach (['count', 'aggregate', 'compare'] as $intent) {
            $this->setUp();
            $this->turn(self::EXCEL_CONTACTS, $intent);
            $seen = [];
            $this->executor(self::EXCEL_CONTACTS, 1354, 523, $this->perMember(100), $seen);
            $captured = null;
            $this->captureResponse($captured);

            $this->manager->answerQuestion('how many contact entries', ['user' => (object) ['id' => 13]]);

            $this->assertCount(2, $seen, "intent {$intent}: no per-anchor re-run");
            $this->assertSame(1354, $captured->executionResult->metadata['true_total']);
            Mockery::close();
        }
    }

    /** @test */
    public function a_heuristic_anchor_from_an_expression_first_item_is_not_collapsed(): void
    {
        // The anchor is only reliable when the FIRST RETURN item is a plain node
        // reference; an expression first item falls back to a heuristic pick.
        $cypher = 'MATCH (m:Member)-[:HAS_ROLE]->(r:Role) RETURN toUpper(m.name) AS name, r.name AS role LIMIT 100';
        $this->turn($cypher);
        $seen = [];
        $this->executor($cypher, 400, 100, $this->perMember(100), $seen);
        $captured = null;
        $this->captureResponse($captured);

        $this->manager->answerQuestion('members and roles', ['user' => (object) ['id' => 13]]);

        $this->assertCount(2, $seen);
        $this->assertSame(400, $captured->executionResult->metadata['true_total']);
    }

    /** @test */
    public function a_failed_per_anchor_rerun_keeps_the_original_result_and_the_turn_alive(): void
    {
        $this->turn(self::EXCEL_CONTACTS);
        $seen = [];
        $this->executor(self::EXCEL_CONTACTS, 1354, 523, null, $seen);
        $captured = null;
        $this->captureResponse($captured);

        $result = $this->manager->answerQuestion('Who are my team members and their contact info?', ['user' => (object) ['id' => 13]]);

        $this->assertCount(3, $seen);
        $this->assertSame(1354, $captured->executionResult->metadata['true_total']);
        $this->assertSame(523, $captured->executionResult->metadata['anchor_total']);
        $this->assertSame(self::EXCEL_CONTACTS, $result['cypher']);
    }

    /** @test */
    public function the_collapse_can_be_disabled_by_config(): void
    {
        config()->set('ai.query_execution.collapse_fan_out', false);
        $this->turn(self::EXCEL_CONTACTS);
        $seen = [];
        $this->executor(self::EXCEL_CONTACTS, 1354, 523, $this->perMember(100), $seen);
        $captured = null;
        $this->captureResponse($captured);

        $this->manager->answerQuestion('Who are my team members and their contact info?', ['user' => (object) ['id' => 13]]);

        $this->assertCount(2, $seen);
        $this->assertSame(1354, $captured->executionResult->metadata['true_total']);
    }
    /** @test */
    public function s1_a_rerun_that_is_still_fanned_out_keeps_the_original_and_its_row_total(): void
    {
        // A rewrite the database read differently returned one row per joined
        // record again: stamping true_total = anchor_total on those rows lied.
        $this->turn(self::EXCEL_CONTACTS);
        $seen = [];
        $stillFanned = [];
        for ($i = 1; $i <= 100; $i++) {
            $stillFanned[] = ['member_name' => 'Member ' . intdiv($i + 1, 2), 'role' => 'owner', 'contact_info' => "c{$i}@x.test"];
        }
        $this->executor(self::EXCEL_CONTACTS, 1354, 523, $stillFanned, $seen);
        $captured = null;
        $this->captureResponse($captured);

        $result = $this->manager->answerQuestion('Who are my team members and their contact info?', ['user' => (object) ['id' => 13]]);

        $this->assertCount(3, $seen, 'the per-anchor re-run ran');
        $this->assertSame(1354, $captured->executionResult->metadata['true_total']);
        $this->assertArrayNotHasKey('fan_out_collapsed', $captured->executionResult->metadata);
        $this->assertSame(self::EXCEL_CONTACTS, $result['cypher']);
    }

    /** @test */
    public function i2_a_collapsed_cell_is_capped_at_the_configured_limit_and_marked(): void
    {
        config()->set('ai.query_execution.collapse_cell_limit', 3);
        $this->turn(self::EXCEL_CONTACTS);
        $seen = [];
        $rows = $this->perMember(100);
        $rows[0]['contact_info'] = ['a@x.test', 'b@x.test', 'c@x.test', 'd@x.test'];
        $this->executor(self::EXCEL_CONTACTS, 1354, 523, $rows, $seen);
        $captured = null;
        $this->captureResponse($captured);

        $this->manager->answerQuestion('Who are my team members and their contact info?', ['user' => (object) ['id' => 13]]);

        $this->assertStringContainsString('collect(DISTINCT cd.value)[0..4] AS contact_info', $seen[2]);
        $this->assertSame('a@x.test; b@x.test; c@x.test; …', $captured->executionResult->data[0]['contact_info']);
        $this->assertSame(['cells' => 1, 'cell_limit' => 3], $captured->executionResult->metadata['fan_out_cells_truncated']);
    }

    /** @test */
    public function s2_an_anchor_filtered_through_a_function_is_not_collapsed(): void
    {
        $cypher = "MATCH (u:Unit)-[:UNION]->(un:Union) WHERE toLower(u.name) = '310' OPTIONAL MATCH (p:Payment)-[:UNIT]->(u) RETURN u.name AS unit, p.amount AS amount LIMIT 100";
        $this->turn($cypher);
        $seen = [];
        $this->executor($cypher, 340, 3, $this->perMember(1), $seen);
        $captured = null;
        $this->captureResponse($captured);

        $this->manager->answerQuestion('payments of unit 310', ['user' => (object) ['id' => 13]]);

        $this->assertCount(2, $seen, 'no per-anchor re-run');
        $this->assertSame(340, $captured->executionResult->metadata['true_total']);
    }

    /** @test */
    public function s5_the_collapsed_roster_is_scoped_exactly_like_the_original_by_the_real_injector(): void
    {
        // The REAL analyzer + injector (no mock): the rewrite keeps MATCH / WHERE
        // verbatim, so the tenant-scoping edits land identically — the injected
        // text before the projection is byte-identical.
        $filter = static fn (NodeBinding $b): ?FilterClause => new FilterClause('where', "WHERE {$b->alias}.team_id IN \$ai_team_ids", ['ai_team_ids' => [3]]);
        $inject = static function (string $cypher) use ($filter): ?string {
            $plan = (new CypherScopeAnalyzer())->analyze($cypher);
            return (new SecurityFilterInjector())->inject($cypher, $plan, $filter);
        };

        $collapsed = FanOutCollapse::perAnchorQuery(self::EXCEL_CONTACTS, 'u', 10);
        $this->assertNotNull($collapsed);

        $original = $inject(self::EXCEL_CONTACTS);
        $scopedCollapsed = $inject($collapsed);
        $this->assertNotNull($original, 'the original roster is scopeable');
        $this->assertNotNull($scopedCollapsed, 'the collapsed roster is scopeable');
        $this->assertNotSame(self::EXCEL_CONTACTS, $original, 'scoping edits were applied');

        $originalPrefix = rtrim(substr($original, 0, (int) strrpos($original, 'RETURN')));
        $collapsedPrefix = rtrim(substr($scopedCollapsed, 0, (int) strpos($scopedCollapsed, ' WITH u, collect(')));
        // Identical modulo whitespace (the rewrite joins the head with one space
        // where the original had a newline before RETURN).
        $squash = static fn (string $s): string => (string) preg_replace('/\s+/', ' ', $s);
        $this->assertSame($squash($originalPrefix), $squash($collapsedPrefix));
        $this->assertStringContainsString('WITH * WHERE t.team_id IN $ai_team_ids', $collapsedPrefix, 'every scoping edit lands BEFORE the grouping WITH');
        $this->assertStringEndsWith(
            'WITH u, collect(DISTINCT tr.role)[0..11] AS role, collect(DISTINCT cd.value)[0..11] AS contact_info WHERE u IS NOT NULL RETURN u.name AS member_name, role, contact_info LIMIT 100',
            $scopedCollapsed
        );
    }
}
