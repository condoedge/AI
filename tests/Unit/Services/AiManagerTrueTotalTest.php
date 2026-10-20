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
 * TRUE-TOTAL COMPANION COUNT (B): when execution returns EXACTLY the executor
 * cap, AiManager calls QueryExecutor::executeCount with the SAME scoped
 * query/options (trailing LIMIT stripped) to establish the true total, and
 * threads it into (a) the executionResult stats+metadata the response prompt
 * reads, (b) the export sourceMeta (total_rows real, complete computed
 * honestly). On count failure, the pre-existing "more may exist" behavior is
 * kept unchanged.
 */
class AiManagerTrueTotalTest extends TestCase
{
    private AiManager $manager;
    private ContextRetrieverInterface $mockContext;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('logging.default', 'null');
        config()->set('ai.log_channel', 'null');
        config()->set('ai.file_context.enabled', false);
        config()->set('ai.query_execution.default_limit', 100);
        // These tests pin the companion-count STAMPING (true_total / anchor_total)
        // on the generated query's own rows. The per-anchor collapse that consumes
        // those stamps (eval p11 D2) is covered by AiManagerFanOutCollapseTest, and
        // the export re-run up to the document row limit (p11 D1c) by
        // AiManagerExportFanOutTest — both off here so each test sees one fetch.
        config()->set('ai.query_execution.collapse_fan_out', false);
        config()->set('ai.export.document_row_limit', 100);

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

    /** @return array<int, array<string, mixed>> */
    private function rows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['id' => $i, 'name' => "Member {$i}"];
        }

        return $rows;
    }

    private function pipelineContextFor(string $cypher): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: $cypher, explanation: '', confidence: 0.9, warnings: [], metadata: []));
    }

    /** @test */
    public function a_cap_hit_execution_gets_the_true_total_stamped_and_threaded_to_the_response_prompt(): void
    {
        $cypher = 'MATCH (u:User) RETURN u.name AS name LIMIT 100';
        $this->pipelineContextFor($cypher);

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $this->rows(100)));

        // The companion count runs with the SAME scoped options and the TRAILING
        // LIMIT stripped (the count must see the uncapped population).
        $this->mockQueryExecutor->shouldReceive('executeCount')
            ->once()
            ->andReturnUsing(function ($countCypher, $params, $options) {
                $this->assertStringNotContainsString('LIMIT', strtoupper($countCypher));
                $this->assertStringContainsString('MATCH (u:User) RETURN u.name AS name', $countCypher);
                $this->assertSame(13, $options['user']->id ?? null, 'count must carry the same identity options');
                return 250;
            });

        $capturedCtx = null;
        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (PipelineContext $ctx) use (&$capturedCtx) {
                $capturedCtx = $ctx;
                return ['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []];
            });

        $result = $this->manager->answerQuestion('who are my team members?', [
            'user' => (object) ['id' => 13],
        ]);

        $this->assertSame(250, $capturedCtx->executionResult->metadata['true_total'] ?? null);
        $this->assertSame(250, $capturedCtx->executionResult->stats['true_total'] ?? null);
        $this->assertSame(250, $result['metadata']['execution']['true_total'] ?? null);
    }

    /**
     * Stub the executor for a members×roles fan-out: the data fetch returns the
     * cap (100 rows), the ROW count (executeCount) says $rowTotal, and the
     * DISTINCT-anchor count (a second execute) says $anchorTotal.
     *
     * @param array<int, string> $anchorQueries collects the distinct-count queries
     */
    private function fanOutExecutor(string $cypher, int $rowTotal, int $anchorTotal, array &$anchorQueries): void
    {
        $this->mockQueryExecutor->shouldReceive('execute')
            ->twice()
            ->andReturnUsing(function ($query, $params, $options) use ($cypher, $anchorTotal, &$anchorQueries) {
                if ($query === $cypher) {
                    return new QueryExecutionResult(success: true, data: $this->rows(100));
                }

                $anchorQueries[] = $query;
                $this->assertSame(13, $options['user']->id ?? null, 'the anchor count must carry the same identity options');

                return new QueryExecutionResult(success: true, data: [['total' => $anchorTotal]]);
            });
        $this->mockQueryExecutor->shouldReceive('executeCount')
            ->once()
            ->andReturnUsing(function ($countCypher, $params, $options) use ($rowTotal) {
                $this->assertSame(13, $options['user']->id ?? null, 'the row count must carry the same identity options');
                return $rowTotal;
            });
    }

    /** @test */
    public function a_fan_out_join_keeps_the_row_total_and_stamps_the_distinct_anchor_count_separately(): void
    {
        // Task 1.24 + review I-1: a roster joining members to roles. true_total
        // stays the ROW total (completeness/truncation consumers read rows); the
        // DISTINCT anchor count rides a SEPARATE key for "how many members"
        // wording — both through the SAME scoped executor (never the raw store).
        $cypher = 'MATCH (m:Member)-[:HAS_ROLE]->(r:Role) RETURN m.name AS name, r.name AS role LIMIT 100';
        $this->pipelineContextFor($cypher);

        $anchorQueries = [];
        $this->fanOutExecutor($cypher, 400, 100, $anchorQueries);

        $capturedCtx = null;
        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (PipelineContext $ctx) use (&$capturedCtx) {
                $capturedCtx = $ctx;
                return ['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []];
            });

        $result = $this->manager->answerQuestion('team members with their roles', [
            'user' => (object) ['id' => 13],
        ]);

        $this->assertCount(1, $anchorQueries);
        $this->assertMatchesRegularExpression('/RETURN\s+count\(DISTINCT\s+m\)\s+AS\s+total\s*$/i', $anchorQueries[0]);
        $this->assertStringContainsString('MATCH (m:Member)-[:HAS_ROLE]->(r:Role)', $anchorQueries[0]);
        $this->assertStringNotContainsString('LIMIT', strtoupper($anchorQueries[0]));

        $execution = $result['metadata']['execution'];
        $this->assertSame(400, $execution['true_total'] ?? null, 'true_total is the ROW total');
        $this->assertSame(100, $execution['anchor_total'] ?? null);
        $this->assertSame('m', $execution['anchor_variable'] ?? null);
        $this->assertSame('Member', $execution['anchor_label'] ?? null);
        $this->assertSame(400, $capturedCtx->executionResult->metadata['true_total'] ?? null);
    }

    /** @test */
    public function s1_a_comment_or_string_never_moves_the_final_return_of_the_anchor_count(): void
    {
        // Review S1: only strings were masked — a RETURN inside a comment became
        // the "final RETURN" and the count query was cut inside the comment.
        $cypher = "MATCH (m:Member)-[:HAS_ROLE]->(r:Role) WHERE r.note <> 'RETURN x' "
            . "RETURN m.name AS name, r.name AS role // RETURN r.name AS role\nLIMIT 100";
        $this->pipelineContextFor($cypher);

        $anchorQueries = [];
        $this->fanOutExecutor($cypher, 400, 100, $anchorQueries);
        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn(['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);

        $result = $this->manager->answerQuestion('team members with their roles', ['user' => (object) ['id' => 13]]);

        $this->assertSame(
            "MATCH (m:Member)-[:HAS_ROLE]->(r:Role) WHERE r.note <> 'RETURN x' RETURN count(DISTINCT m) AS total",
            $anchorQueries[0] ?? null
        );
        $this->assertSame('m', $result['metadata']['execution']['anchor_variable'] ?? null);
        $this->assertTrue($result['metadata']['execution']['anchor_first_item'] ?? null);
    }

    /** @test */
    public function a_cold_export_of_a_fan_out_is_never_complete_when_rows_were_dropped(): void
    {
        // Review I-1 exact example: cap 100, a 400-row members×roles join covering
        // 100 distinct members. Treating the distinct count as the row total made
        // the export claim complete=true while 300 rows were dropped.
        $cypher = 'MATCH (m:Member)-[:HAS_ROLE]->(r:Role) RETURN m.name AS name, r.name AS role LIMIT 100';
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', exportFormat: 'excel');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: $cypher, explanation: '', confidence: 0.9, warnings: [], metadata: []));

        $anchorQueries = [];
        $this->fanOutExecutor($cypher, 400, 100, $anchorQueries);

        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $result = $this->manager->answerQuestion('excel of my team members with roles', [
            'user' => (object) ['id' => 13],
        ]);

        $response = $result['metadata']['response'];
        $this->assertFalse($response['export_source_complete'], '100 of 400 rows is NOT complete');
        $this->assertSame(400, $response['result_total']);
        $this->assertStringContainsString('100 of 400', $result['answer']);
    }

    /** @test */
    public function the_first_projected_node_variable_is_the_anchor_even_when_qu_targets_another(): void
    {
        // Task 1.27 (f), live roster t0: QU entity types [Team, TeamRole] made the
        // anchor TeamRole although the RETURN's subject is u:User (member_name =
        // u.name) → "523 distinct roles". The RETURN projection is authoritative:
        // its first item's node variable is the grain the rows are about.
        $cypher = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
            . 'OPTIONAL MATCH (tr:TeamRole) WHERE tr.team_id = t.id AND tr.user_id = u.id '
            . 'RETURN u.name AS member_name, tr.role AS role LIMIT 100';
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', entityTypes: ['Team', 'TeamRole']);
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: $cypher, explanation: '', confidence: 0.9, warnings: [], metadata: []));

        $anchorQueries = [];
        $this->fanOutExecutor($cypher, 523, 480, $anchorQueries);
        $this->mockResponseGenerator->shouldReceive('generate')->once()
            ->andReturn(['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);

        $result = $this->manager->answerQuestion('Who are the members of my team and what are their roles?', ['user' => (object) ['id' => 13]]);

        $this->assertMatchesRegularExpression('/count\(DISTINCT\s+u\)/i', $anchorQueries[0]);
        $this->assertSame('User', $result['metadata']['execution']['anchor_label'] ?? null);
    }

    /** @test */
    public function qu_types_only_break_the_tie_among_projected_variables_when_the_first_item_is_an_expression(): void
    {
        // When the first RETURN item is not a plain node reference (an expression),
        // the projected node variables are the candidates and QU's target type
        // picks among them — never a variable absent from the RETURN.
        $cypher = 'MATCH (m:Member)-[:HAS_ROLE]->(r:Role) RETURN toUpper(m.name) AS name, m.email AS email, r.name AS role LIMIT 100';
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', entityTypes: ['Role']);
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: $cypher, explanation: '', confidence: 0.9, warnings: [], metadata: []));

        $anchorQueries = [];
        $this->fanOutExecutor($cypher, 400, 6, $anchorQueries);
        $this->mockResponseGenerator->shouldReceive('generate')->once()
            ->andReturn(['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);

        $result = $this->manager->answerQuestion('which roles exist in my team', ['user' => (object) ['id' => 13]]);

        $this->assertMatchesRegularExpression('/count\(DISTINCT\s+r\)/i', $anchorQueries[0]);
        $this->assertSame('Role', $result['metadata']['execution']['anchor_label'] ?? null);
        $this->assertSame(6, $result['metadata']['execution']['anchor_total'] ?? null);
    }

    /** @test */
    public function function_arguments_and_unwind_scalars_are_not_counted_as_pattern_node_variables(): void
    {
        // Review M-1: only variables bound in MATCH / OPTIONAL MATCH patterns
        // count. Here the only pattern-bound node is m (size(t) and the UNWIND
        // scalar t must not make it look like a fan-out) — row count only.
        $cypher = 'MATCH (m:Member) WHERE size(m.tags) > 0 UNWIND m.tags AS t WITH m, t WHERE toLower(t) <> "" RETURN m.name AS name, t AS tag LIMIT 100';
        $this->pipelineContextFor($cypher);

        $this->mockQueryExecutor->shouldReceive('execute')->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $this->rows(100)));
        $this->mockQueryExecutor->shouldReceive('executeCount')->once()->andReturn(250);
        $this->mockResponseGenerator->shouldReceive('generate')->once()
            ->andReturn(['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);

        $result = $this->manager->answerQuestion('member tags', ['user' => (object) ['id' => 13]]);

        $this->assertSame(250, $result['metadata']['execution']['true_total'] ?? null);
        $this->assertArrayNotHasKey('anchor_total', $result['metadata']['execution']);
    }

    /** @test */
    public function an_aggregating_return_keeps_the_row_count(): void
    {
        // Not derivable (the RETURN aggregates — rows are groups, not anchors):
        // unchanged behavior, the row count via executeCount.
        $this->pipelineContextFor('MATCH (m:Member)-[:HAS_ROLE]->(r:Role) RETURN r.name AS role, count(m) AS members LIMIT 100');

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $this->rows(100)));
        $this->mockQueryExecutor->shouldReceive('executeCount')->once()->andReturn(120);

        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn(['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);

        $result = $this->manager->answerQuestion('members per role', []);

        $this->assertSame(120, $result['metadata']['execution']['true_total'] ?? null);
    }

    /** @test */
    public function a_below_cap_execution_never_runs_the_companion_count(): void
    {
        $this->pipelineContextFor('MATCH (u:User) RETURN u.name AS name LIMIT 100');

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $this->rows(7)));
        $this->mockQueryExecutor->shouldReceive('executeCount')->never();

        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn(['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);

        $result = $this->manager->answerQuestion('who are my team members?', []);

        $this->assertArrayNotHasKey('true_total', $result['metadata']['execution']);
    }

    /** @test */
    public function a_count_failure_keeps_the_result_unstamped_and_the_turn_alive(): void
    {
        $this->pipelineContextFor('MATCH (u:User) RETURN u.name AS name LIMIT 100');

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $this->rows(100)));
        $this->mockQueryExecutor->shouldReceive('executeCount')
            ->once()
            ->andThrow(new \RuntimeException('count backend down'));

        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn(['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);

        $result = $this->manager->answerQuestion('who are my team members?', []);

        $this->assertArrayNotHasKey('true_total', $result['metadata']['execution']);
        $this->assertCount(100, $result['data']);
    }

    /** @test */
    public function an_inconsistent_count_below_the_retrieved_rows_is_distrusted(): void
    {
        $this->pipelineContextFor('MATCH (u:User) RETURN u.name AS name LIMIT 100');

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $this->rows(100)));
        $this->mockQueryExecutor->shouldReceive('executeCount')->once()->andReturn(40);

        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn(['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);

        $result = $this->manager->answerQuestion('who are my team members?', []);

        $this->assertArrayNotHasKey('true_total', $result['metadata']['execution']);
    }

    /** @test */
    public function a_cold_export_at_the_cap_uses_the_true_total_for_honest_source_meta(): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', exportFormat: 'excel');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: 'MATCH (u:User) RETURN u.name AS name LIMIT 100', explanation: '', confidence: 0.9, warnings: [], metadata: []));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $this->rows(100)));
        $this->mockQueryExecutor->shouldReceive('executeCount')->once()->andReturn(250);

        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $result = $this->manager->answerQuestion('excel of all my team members please', []);

        $response = $result['metadata']['response'];
        $this->assertTrue($response['export_intent']);
        $this->assertFalse($response['export_source_complete'], '100 of 250 is NOT complete');
        $this->assertSame(250, $response['result_total']);
        $this->assertStringContainsString('100 of 250', $result['answer']);
    }

    /** @test */
    public function a_cold_export_whose_count_confirms_the_cap_equals_the_population_is_complete(): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', exportFormat: 'excel');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: 'MATCH (u:User) RETURN u.name AS name LIMIT 100', explanation: '', confidence: 0.9, warnings: [], metadata: []));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $this->rows(100)));
        $this->mockQueryExecutor->shouldReceive('executeCount')->once()->andReturn(100);

        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $result = $this->manager->answerQuestion('excel of all my team members please', []);

        $response = $result['metadata']['response'];
        // The companion count PROVED the cap-hit set is the whole population:
        // complete=true, and no "more may exist" hedging anywhere.
        $this->assertTrue($response['export_source_complete']);
        $this->assertStringNotContainsString('more may exist', $result['answer']);
        $this->assertStringNotContainsString('more may exist', $response['export_document_markdown']);
    }

    /** @test */
    public function a_follow_up_export_re_execution_at_the_cap_uses_the_companion_count_for_its_total(): void
    {
        $recordedCypher = 'MATCH (u:User) RETURN u.name AS name LIMIT 100';

        $ctx = new PipelineContext();
        // QU's export judgment (the only export signal since Phase 2.B).
        $ctx->qu = new QueryUnderstandingResult(exportFormat: 'excel', language: 'en');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: null, explanation: '', confidence: 0.0, warnings: [], metadata: []));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->twice()
            ->andReturnUsing(function ($cypher) use ($recordedCypher) {
                if ($cypher === $recordedCypher) {
                    return new QueryExecutionResult(success: true, data: $this->rows(100));
                }

                return new QueryExecutionResult(success: true, data: []);
            });
        $this->mockQueryExecutor->shouldReceive('executeCount')->once()->andReturn(250);

        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $result = $this->manager->answerQuestion('export that to Excel', [
            'conversation_context' => [
                'last_cypher_query' => $recordedCypher,
                'last_result_count' => 100,
                'last_result_sample' => array_slice($this->rows(100), 0, 10),
            ],
        ]);

        $response = $result['metadata']['response'];
        $this->assertSame('previous_result', $response['export_source']);
        $this->assertFalse($response['export_source_complete']);
        $this->assertSame(250, $response['result_total']);
        $this->assertStringContainsString('100 of 250', $result['answer']);
    }
}
