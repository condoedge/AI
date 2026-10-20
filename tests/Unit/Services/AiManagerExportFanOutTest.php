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
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Eval p11 D1 — "…as an Excel with their contact info" after a 523-member roster.
 *
 * Live trace (p11-1001-A turn 2): the fresh query extended the roster with
 * `cd.value AS contact_info` (ContactDirectory, one row per email/phone): 100 of
 * 1354 rows for 523 members. The export source check required >= 90% of the
 * previous 100 (name, role) rows inside the fresh 100-row window (~40 members),
 * failed, exported the previous result ("Fields included: Member Name, Role",
 * 100 of 525) and — requested_fields being set only on a pass — never said the
 * contact info was missing.
 */
class AiManagerExportFanOutTest extends TestCase
{
    private AiManager $manager;
    private ContextRetrieverInterface $mockContext;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;

    private const ROSTER = "MATCH (t:Team) WHERE t.id IN \$ai_team_ids AND t.id = 3\n"
        . "OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t)\n"
        . "OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id\n"
        . "RETURN u.name AS member_name, tr.role AS role LIMIT 100";

    private const ROSTER_CONTACTS = "MATCH (t:Team) WHERE t.id IN \$ai_team_ids AND t.id = 3\n"
        . "OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t)\n"
        . "OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id\n"
        . "OPTIONAL MATCH (c:Contact)-[:USER]->(u)\n"
        . "OPTIONAL MATCH (cd:ContactDirectory)-[:CONTACT]->(c)\n"
        . "RETURN u.name AS member_name, tr.role AS role, cd.value AS contact_info LIMIT 100";

    public function setUp(): void
    {
        parent::setUp();

        config()->set('logging.default', 'null');
        config()->set('ai.log_channel', 'null');
        config()->set('ai.file_context.enabled', false);
        config()->set('ai.query_execution.default_limit', 100);
        config()->set('ai.export.document_row_limit', 250);
        config()->set('ai.attached_actions.project_record_ids', false);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

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

    private function exportTurn(?string $freshCypher, bool $followUp = true): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(
            entityTypes: ['Team', 'TeamRole', 'User', 'ContactDirectory'],
            searchQuery: 'team members contact information',
            intent: 'file_listing',
            questionType: 'data_query',
            exportFormat: 'excel',
            language: 'en',
            followUpReference: $followUp,
        );
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: $freshCypher, explanation: '', confidence: 0.9));
    }

    /** One row per (member, role) — member $i, $count rows. */
    private static function members(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['member_name' => "Member {$i}", 'role' => 'owner'];
        }

        return $rows;
    }

    /**
     * The fan-out: ~2.6 rows per member (email, phone, and every 3rd a second
     * email). $withIds: each row carries its member's record id (`user_id`, as
     * RecordIdProjection projects it).
     */
    private static function fannedOut(int $count, bool $withIds = true): array
    {
        $rows = [];
        for ($m = 1; count($rows) < $count; $m++) {
            foreach (["m{$m}@x.test", "+1 514 555 {$m}"] + ($m % 3 === 0 ? [2 => "m{$m}@alt.test"] : []) as $value) {
                if (count($rows) < $count) {
                    $rows[] = ['member_name' => "Member {$m}", 'role' => 'owner', 'contact_info' => $value]
                        + ($withIds ? ['user_id' => $m] : []);
                }
            }
        }

        return $rows;
    }

    private function conversation(): array
    {
        return [
            'user' => (object) ['id' => 13],
            'conversation_context' => [
                'last_cypher_query' => self::ROSTER,
                'last_result_count' => 100,
                'last_result_sample' => array_slice(self::members(100), 0, 10),
            ],
        ];
    }

    /**
     * The live graph: 523 members (2 TeamRoles without a user → 525 roster rows),
     * 1354 member×contact rows. A query is served up to its effective LIMIT —
     * the trailing LIMIT when present, else options.limit.
     *
     * @param array<int, array{query: string, limit: mixed}> $seen
     */
    private function liveGraph(array &$seen): void
    {
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturnUsing(function ($query, $params, $options) use (&$seen) {
                $query = (string) $query;
                if ($query === '') {
                    return QueryExecutionResult::empty();
                }
                $seen[] = ['query' => $query, 'limit' => $options['limit'] ?? null];
                if (preg_match('/count\(DISTINCT\s+u\)\s+AS\s+total$/', $query) === 1) {
                    return new QueryExecutionResult(success: true, data: [['total' => 523]]);
                }
                $limit = preg_match('/LIMIT\s+(\d+)\s*$/', $query, $m) === 1 ? (int) $m[1] : (int) ($options['limit'] ?? 100);
                $rows = str_contains($query, 'cd.value')
                    ? self::fannedOut(min($limit, 1354))
                    : self::members(min($limit, 523));

                return new QueryExecutionResult(success: true, data: $rows);
            });
        $this->mockQueryExecutor->shouldReceive('executeCount')
            ->andReturnUsing(fn ($query) => str_contains($query, 'cd.value') ? 1354 : 525);
    }

    /** @test */
    public function the_live_fan_out_extension_is_exported_one_row_per_member_with_all_contact_values(): void
    {
        // Source collapse off: this pins the EXPORT path's own fan-out tolerance
        // (a: collapse by the shared columns; b: equal anchor population).
        config()->set('ai.query_execution.collapse_fan_out', false);
        $this->exportTurn(self::ROSTER_CONTACTS);
        $seen = [];
        $this->liveGraph($seen);

        $result = $this->manager->answerQuestion('Perfect - give me that as an Excel with their contact info.', $this->conversation());

        $response = $result['metadata']['response'];
        $this->assertSame('current_result', $response['export_source']);
        $rows = $response['export_document_rows'];
        // Grouped by the member's record id (user_id); the internal id column is
        // dropped from the document.
        $this->assertSame(['member_name' => 'Member 1', 'role' => 'owner', 'contact_info' => 'm1@x.test; +1 514 555 1'], $rows[0]);
        $this->assertSame('m3@x.test; +1 514 555 3; m3@alt.test', $rows[2]['contact_info']);
        $names = array_column($rows, 'member_name');
        $this->assertSame(count($names), count(array_unique($names)), 'one row per member');
        $this->assertStringContainsString('Fields included: Member Name, Role, Contact Info.', $result['answer']);
        $this->assertStringNotContainsString('not included', $result['answer']);
        // Disclosure in MEMBERS: never "of 1354".
        $this->assertSame(523, $response['result_total']);
        $this->assertFalse($response['export_source_complete']);
        $this->assertStringContainsString(count($rows) . ' of 523', $result['answer']);
        $this->assertStringNotContainsString('1354', $result['answer']);
    }

    /** @test */
    public function with_the_source_collapse_the_extension_is_exported_up_to_the_document_row_limit(): void
    {
        // Full pipeline: the fresh query is collapsed per member at execution
        // (D2), the export re-runs it without the display LIMIT up to
        // ai.export.document_row_limit (D1c) — 250 of 523 members.
        $this->exportTurn(self::ROSTER_CONTACTS);
        $seen = [];
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturnUsing(function ($query, $params, $options) use (&$seen) {
                $query = (string) $query;
                if ($query === '') {
                    return QueryExecutionResult::empty();
                }
                $seen[] = ['query' => $query, 'limit' => $options['limit'] ?? null];
                if (preg_match('/count\(DISTINCT\s+u\)\s+AS\s+total$/', $query) === 1) {
                    return new QueryExecutionResult(success: true, data: [['total' => 523]]);
                }
                $limit = preg_match('/LIMIT\s+(\d+)\s*$/', $query, $m) === 1 ? (int) $m[1] : (int) ($options['limit'] ?? 100);
                if (str_contains($query, 'WITH u, collect(DISTINCT')) {
                    $rows = [];
                    for ($i = 1; $i <= min($limit, 523); $i++) {
                        $rows[] = ['member_name' => "Member {$i}", 'role' => ['owner'], 'contact_info' => ["m{$i}@x.test", "+1 514 555 {$i}"]];
                    }
                    return new QueryExecutionResult(success: true, data: $rows);
                }

                return new QueryExecutionResult(success: true, data: str_contains($query, 'cd.value')
                    ? self::fannedOut(min($limit, 1354))
                    : self::members(min($limit, 523)));
            });
        $this->mockQueryExecutor->shouldReceive('executeCount')
            ->andReturnUsing(fn ($query) => str_contains($query, 'cd.value') ? 1354 : 525);

        $result = $this->manager->answerQuestion('Perfect - give me that as an Excel with their contact info.', $this->conversation());

        $response = $result['metadata']['response'];
        $this->assertSame('current_result', $response['export_source']);
        $this->assertCount(250, $response['export_document_rows']);
        $this->assertSame('m250@x.test; +1 514 555 250', $response['export_document_rows'][249]['contact_info']);
        $this->assertSame(523, $response['result_total']);
        $this->assertStringContainsString('250 of 523', $result['answer']);

        $rerun = array_values(array_filter($seen, fn ($s) => $s['limit'] === 250 && str_contains($s['query'], 'WITH u, collect(DISTINCT')));
        $this->assertCount(1, $rerun, 'the collapsed query is re-run once at the document row limit');
        // The display LIMIT is replaced by an explicit document LIMIT (review I1:
        // the executor appends its own only when no LIMIT word appears anywhere).
        $this->assertStringEndsWith('RETURN u.name AS member_name, role, contact_info LIMIT 250', $rerun[0]['query']);
        $this->assertSame(1, substr_count($rerun[0]['query'], 'LIMIT'));
    }

    /** @test */
    public function the_previous_result_is_re_executed_up_to_the_document_row_limit(): void
    {
        // A pure "export that": the recorded roster query (display LIMIT 100) is
        // re-run without it, capped by ai.export.document_row_limit.
        $this->exportTurn(null);
        $seen = [];
        $this->liveGraph($seen);

        $result = $this->manager->answerQuestion('Export that to Excel', $this->conversation());

        $response = $result['metadata']['response'];
        $this->assertSame('previous_result', $response['export_source']);
        $this->assertCount(250, $response['export_document_rows']);
        $this->assertSame(525, $response['result_total']);
        $this->assertStringContainsString('250 of 525', $result['answer']);
        $reExecution = array_values(array_filter($seen, fn ($s) => str_contains($s['query'], 'tr.role AS role') && !str_contains($s['query'], 'count(')));
        $this->assertSame(250, $reExecution[0]['limit']);
        $this->assertStringEndsWith('RETURN u.name AS member_name, tr.role AS role LIMIT 250', $reExecution[0]['query']);
    }

    /** @test */
    public function a_semantic_limit_below_the_display_cap_is_never_stripped(): void
    {
        // "Top 5" is the ask, not the display cap: the recorded LIMIT 5 stays.
        $recorded = 'MATCH (u:User) RETURN u.name AS member_name ORDER BY u.balance DESC LIMIT 5';
        $this->exportTurn(null);
        $seen = [];
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturnUsing(function ($query, $params, $options) use (&$seen) {
                $seen[] = $query;
                return new QueryExecutionResult(success: true, data: str_contains($query, 'LIMIT 5') ? self::members(5) : []);
            });
        $this->mockQueryExecutor->shouldReceive('executeCount')->never();

        $result = $this->manager->answerQuestion('Export that to Excel', [
            'conversation_context' => ['last_cypher_query' => $recorded, 'last_result_count' => 5],
        ]);

        $this->assertContains($recorded, $seen);
        $this->assertCount(5, $result['metadata']['response']['export_document_rows']);
        $this->assertTrue($result['metadata']['response']['export_source_complete']);
    }

    /** @test */
    public function an_uncapped_fan_out_of_the_same_members_is_collapsed_and_exported(): void
    {
        $recorded = 'MATCH (u:User) RETURN u.name AS member_name, u.role AS role';
        $fresh = 'MATCH (u:User) OPTIONAL MATCH (c:Contact)-[:USER]->(u) RETURN u.name AS member_name, u.role AS role, c.value AS contact_info';
        $this->exportTurn($fresh);
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturnUsing(fn ($query) => new QueryExecutionResult(success: true, data: str_starts_with((string) $query, $recorded)
                ? [['member_name' => 'Julie', 'role' => 'lead'], ['member_name' => 'Karen', 'role' => 'contact']]
                : [
                    ['member_name' => 'Julie', 'role' => 'lead', 'contact_info' => 'julie@x.test', 'user_id' => 1],
                    ['member_name' => 'Julie', 'role' => 'lead', 'contact_info' => '514-555-0101', 'user_id' => 1],
                    ['member_name' => 'Karen', 'role' => 'contact', 'contact_info' => 'karen@x.test', 'user_id' => 2],
                    ['member_name' => 'Karen', 'role' => 'contact', 'contact_info' => null, 'user_id' => 2],
                ]));
        $this->mockQueryExecutor->shouldReceive('executeCount')->never();

        $result = $this->manager->answerQuestion('Excel with their contact info', [
            'conversation_context' => ['last_cypher_query' => $recorded, 'last_result_count' => 2],
        ]);

        $response = $result['metadata']['response'];
        $this->assertSame('current_result', $response['export_source']);
        $this->assertSame([
            ['member_name' => 'Julie', 'role' => 'lead', 'contact_info' => 'julie@x.test; 514-555-0101'],
            ['member_name' => 'Karen', 'role' => 'contact', 'contact_info' => 'karen@x.test'],
        ], $response['export_document_rows']);
        $this->assertTrue($response['export_source_complete']);
        $this->assertSame(2, $response['result_total']);
    }

    /** @test */
    public function i4_distinct_members_sharing_displayed_values_are_never_merged(): void
    {
        // Two different "John Smith, owner" members: grouping by the displayed
        // (name, role) values put both people's contacts in one row (PII mixing).
        $recorded = 'MATCH (u:User) RETURN u.name AS member_name, u.role AS role';
        $fresh = 'MATCH (u:User) OPTIONAL MATCH (c:Contact)-[:USER]->(u) RETURN u.name AS member_name, u.role AS role, c.value AS contact_info';
        $previousRows = [['member_name' => 'John Smith', 'role' => 'owner'], ['member_name' => 'John Smith', 'role' => 'owner']];

        foreach (['with record ids' => true, 'without record ids' => false] as $case => $withIds) {
            $this->setUp();
            $this->exportTurn($fresh);
            $freshRows = [
                ['member_name' => 'John Smith', 'role' => 'owner', 'contact_info' => 'john.a@x.test'] + ($withIds ? ['user_id' => 1] : []),
                ['member_name' => 'John Smith', 'role' => 'owner', 'contact_info' => '514-555-0101'] + ($withIds ? ['user_id' => 1] : []),
                ['member_name' => 'John Smith', 'role' => 'owner', 'contact_info' => 'john.b@x.test'] + ($withIds ? ['user_id' => 2] : []),
            ];
            $this->mockQueryExecutor->shouldReceive('execute')
                ->andReturnUsing(fn ($query) => new QueryExecutionResult(success: true, data: str_starts_with((string) $query, $recorded) ? $previousRows : $freshRows));
            $this->mockQueryExecutor->shouldReceive('executeCount')->never();

            $result = $this->manager->answerQuestion('Excel with their contact info', [
                'conversation_context' => ['last_cypher_query' => $recorded, 'last_result_count' => 2],
            ]);

            $rows = $result['metadata']['response']['export_document_rows'];
            $this->assertSame('current_result', $result['metadata']['response']['export_source'], $case);
            if ($withIds) {
                $this->assertSame([
                    ['member_name' => 'John Smith', 'role' => 'owner', 'contact_info' => 'john.a@x.test; 514-555-0101'],
                    ['member_name' => 'John Smith', 'role' => 'owner', 'contact_info' => 'john.b@x.test'],
                ], $rows, $case);
            } else {
                // No identity: exported as fetched, one row per joined record.
                $this->assertSame(array_column($freshRows, 'contact_info'), array_column($rows, 'contact_info'), $case);
            }
            Mockery::close();
        }
    }

    /** @test */
    public function a_previous_result_kept_for_an_export_that_asked_for_more_fields_discloses_them(): void
    {
        // The fresh query extends the same members (overlapping values, a strict
        // column superset) but cannot be proven the same population: the
        // previous result is exported — and the missing field is said.
        $recorded = 'MATCH (u:User) RETURN u.name AS member_name, u.role AS role';
        $fresh = 'MATCH (u:User) OPTIONAL MATCH (c:Contact)-[:USER]->(u) RETURN u.name AS member_name, u.role AS role, c.value AS contact_info';
        $this->exportTurn($fresh);
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturnUsing(fn ($query) => new QueryExecutionResult(success: true, data: str_starts_with((string) $query, $recorded)
                ? [['member_name' => 'Julie', 'role' => 'lead'], ['member_name' => 'Karen', 'role' => 'contact'], ['member_name' => 'Nada', 'role' => 'owner']]
                : [['member_name' => 'Julie', 'role' => 'lead', 'contact_info' => 'julie@x.test']]));
        $this->mockQueryExecutor->shouldReceive('executeCount')->never();

        $result = $this->manager->answerQuestion('Excel with their contact info', [
            'conversation_context' => ['last_cypher_query' => $recorded, 'last_result_count' => 3],
        ]);

        $this->assertSame('previous_result', $result['metadata']['response']['export_source']);
        $this->assertStringContainsString('Fields included: Member Name, Role.', $result['answer']);
        $this->assertStringContainsString('Requested fields not included: Contact Info.', $result['answer']);
    }
    /** @test */
    public function i1_a_recorded_query_with_a_subquery_limit_is_re_run_with_an_explicit_document_limit(): void
    {
        // The executor appends its LIMIT only when no LIMIT word appears anywhere:
        // with the display LIMIT stripped, `CALL { … LIMIT 1 }` made the re-run unbounded.
        $recorded = 'MATCH (u:User) CALL { WITH u MATCH (u)-[:HAS]->(c:Contact) RETURN c LIMIT 1 } RETURN u.name AS member_name, c.value AS contact LIMIT 100';
        $this->exportTurn(null);
        $seen = [];
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturnUsing(function ($query, $params, $options) use (&$seen) {
                if ((string) $query === '') {
                    return QueryExecutionResult::empty();
                }
                $seen[] = ['query' => (string) $query, 'limit' => $options['limit'] ?? null];
                return new QueryExecutionResult(success: true, data: self::members(3));
            });
        $this->mockQueryExecutor->shouldReceive('executeCount')->never();

        $this->manager->answerQuestion('Export that to Excel', [
            'conversation_context' => ['last_cypher_query' => $recorded, 'last_result_count' => 100],
        ]);

        $this->assertSame(
            'MATCH (u:User) CALL { WITH u MATCH (u)-[:HAS]->(c:Contact) RETURN c LIMIT 1 } RETURN u.name AS member_name, c.value AS contact LIMIT 250',
            $seen[0]['query']
        );
        $this->assertSame(250, $seen[0]['limit']);
    }

    /** @test */
    public function s3_an_ordered_list_at_the_display_cap_is_widened_with_its_order_kept_but_a_top_n_or_page_is_not(): void
    {
        // The generator habitually writes `ORDER BY u.name LIMIT 100` (100 = the
        // executor/display cap): "export my roster" must not ship 100 of 523.
        // Below the cap the LIMIT is the user's top-N; SKIP is a page.
        $cases = [
            'display cap' => ['MATCH (u:User) RETURN u.name AS member_name ORDER BY u.name LIMIT 100', 'MATCH (u:User) RETURN u.name AS member_name ORDER BY u.name LIMIT 250', 250],
            'top-n' => ['MATCH (u:User) RETURN u.name AS member_name ORDER BY u.balance DESC LIMIT 10', 'MATCH (u:User) RETURN u.name AS member_name ORDER BY u.balance DESC LIMIT 10', null],
            'page' => ['MATCH (u:User) RETURN u.name AS member_name ORDER BY u.name SKIP 100 LIMIT 100', 'MATCH (u:User) RETURN u.name AS member_name ORDER BY u.name SKIP 100 LIMIT 100', null],
        ];
        foreach ($cases as $case => [$recorded, $expected, $expectedLimit]) {
            $this->setUp();
            $this->exportTurn(null);
            $seen = [];
            $this->mockQueryExecutor->shouldReceive('execute')
                ->andReturnUsing(function ($query, $params, $options) use (&$seen) {
                    if ((string) $query === '') {
                        return QueryExecutionResult::empty();
                    }
                    $seen[] = ['query' => (string) $query, 'limit' => $options['limit'] ?? null];
                    return new QueryExecutionResult(success: true, data: self::members(10));
                });
            $this->mockQueryExecutor->shouldReceive('executeCount')->andReturn(523);

            $this->manager->answerQuestion('Export that to Excel', [
                'conversation_context' => ['last_cypher_query' => $recorded, 'last_result_count' => 100],
            ]);

            $this->assertSame($expected, $seen[0]['query'], $case);
            $this->assertSame($expectedLimit, $seen[0]['limit'], $case);
            Mockery::close();
        }
    }

    /** @test */
    public function i4_same_population_needs_known_equal_labels_and_a_non_trivial_non_empty_overlap(): void
    {
        $samePopulation = new \ReflectionMethod(AiManager::class, 'samePopulation');
        $named = static fn (int $from, int $to): array => array_map(static fn (int $i): array => ['member_name' => "Member {$i}"], range($from, $to));
        $nulls = static fn (int $n): array => array_fill(0, $n, ['member_name' => null]);
        $call = fn (array $previousRows, array $previousMeta, array $freshRows, array $freshMeta, ?string $previousCypher = null, ?string $freshCypher = null): bool
            => $samePopulation->invoke($this->manager, ['rows' => $previousRows, 'meta' => $previousMeta], $freshMeta, $freshRows, ['member_name'], $previousCypher, $freshCypher);

        $previousMeta = ['total_rows' => 523, 'anchor_total' => 523, 'anchor_label' => 'User'];
        $freshMeta = ['anchor_total' => 523, 'anchor_label' => 'User'];

        // Live shape: two windows of one 523-member population, 40 members in common.
        $this->assertTrue($call($named(1, 100), $previousMeta, $named(61, 140), $freshMeta));
        // All-null shared values ("" == "") are no overlap.
        $this->assertFalse($call($nulls(100), $previousMeta, $nulls(100), $freshMeta));
        // One coincidental common value of a large window is not enough.
        $this->assertFalse($call($named(1, 100), $previousMeta, $named(100, 199), $freshMeta));
        // Different labels / an unknown label never prove the same population.
        $this->assertFalse($call($named(1, 100), $previousMeta, $named(1, 100), ['anchor_total' => 523, 'anchor_label' => 'Contact']));
        $this->assertFalse($call($named(1, 100), ['total_rows' => 523], $named(1, 100), $freshMeta));
        // ...unless the recorded query's own anchor names it.
        $this->assertTrue($call($named(1, 100), ['total_rows' => 523], $named(1, 100), $freshMeta, 'MATCH (u:User) RETURN u.name AS member_name'));
        $this->assertFalse($call($named(1, 100), ['total_rows' => 523], $named(1, 100), $freshMeta, 'MATCH (c:Contact) RETURN c.name AS member_name'));
    }
}
