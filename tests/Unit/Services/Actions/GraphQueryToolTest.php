<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Exceptions\ReadOnlyViolationException;
use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionKind;
use Condoedge\Ai\Services\Actions\GraphQueryTool;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\RecordingQueryExecutor;
use PHPUnit\Framework\TestCase;

/**
 * GraphQueryTool (C3-multi-query): the read-only run_query tool for the native loop. It is a
 * plain read-only AiAction whose handle() funnels its Cypher through the EXISTING QueryExecutor
 * — tenant scoping, the read-only gate and the QueryExecutionResult DTO are all REUSED, never
 * re-implemented. These pure-PHPUnit tests drive a recording fake executor so the contract is
 * pinned without a graph backend: a write-clause Cypher is rejected, a read query reaches the
 * executor with read_only forced on, rows are sampled/truncated for the model, and the
 * executor's own ReadOnlyViolationException degrades to a corrective tool result.
 */
class GraphQueryToolTest extends TestCase
{
    /** @test */
    public function it_is_a_read_only_generic_action_named_run_query(): void
    {
        $tool = new GraphQueryTool(new RecordingQueryExecutor());

        $this->assertSame('run_query', $tool->key());
        $this->assertFalse($tool->mutates(), 'the query tool is strictly read-only');
        $this->assertTrue($tool->authorize(null, []), 'read-only execution is not authz-gated here');

        $schema = $tool->inputSchema();
        $this->assertArrayHasKey('cypher', $schema);
        $this->assertTrue($schema['cypher']['required'] ?? false, 'cypher is required');
        $this->assertArrayHasKey('purpose', $schema);
    }

    /** @test */
    public function a_read_query_runs_through_the_executor_with_read_only_forced_on(): void
    {
        $executor = new RecordingQueryExecutor(new QueryExecutionResult(
            success: true,
            data: [['name' => 'Alice'], ['name' => 'Bob']],
        ));
        $tool = new GraphQueryTool($executor);

        $result = $tool->handle(
            ['purpose' => 'my team', 'cypher' => 'MATCH (p:Person) RETURN p.name AS name'],
            ActionContext::fromArray(['user' => (object) ['id' => 7]])
        );

        $this->assertSame('MATCH (p:Person) RETURN p.name AS name', $executor->lastQuery);
        $this->assertTrue($executor->lastOptions['read_only'] ?? null, 'the executor must run in read-only mode');
        $this->assertSame($executor->lastOptions['user']->id ?? null, 7, 'the acting user rides into tenant scoping');

        $this->assertSame(ActionKind::Table, $result->kind);
        $this->assertSame(['name'], $result->payload['headers']);
        $this->assertCount(2, $result->payload['rows']);
        $this->assertNotSame('', $result->summaryForLlm);
    }

    /** @test */
    public function a_write_clause_cypher_is_rejected_before_it_reaches_the_executor(): void
    {
        $executor = new RecordingQueryExecutor();
        $tool = new GraphQueryTool($executor);

        $result = $tool->handle(
            ['cypher' => 'MATCH (p:Person) DELETE p'],
            new ActionContext()
        );

        $this->assertSame(ActionKind::Error, $result->kind);
        $this->assertStringContainsStringIgnoringCase('read-only', $result->summaryForLlm);
        $this->assertNull($executor->lastQuery, 'a write query must never reach the executor');
    }

    /** @test */
    public function an_empty_cypher_is_a_corrective_error_not_an_executor_call(): void
    {
        $executor = new RecordingQueryExecutor();
        $tool = new GraphQueryTool($executor);

        $result = $tool->handle(['cypher' => '   '], new ActionContext());

        $this->assertSame(ActionKind::Error, $result->kind);
        $this->assertNull($executor->lastQuery);
    }

    /** @test */
    public function the_executor_read_only_violation_degrades_to_a_corrective_result(): void
    {
        // Defence in depth: even if the pre-flight regex is bypassed, the executor's gate is the
        // authority. A thrown ReadOnlyViolationException becomes a corrective tool result, never
        // an uncaught crash inside the loop.
        $executor = new RecordingQueryExecutor();
        $executor->throw = new ReadOnlyViolationException('Write operations not allowed in read-only mode');
        $tool = new GraphQueryTool($executor);

        $result = $tool->handle(
            ['cypher' => 'MATCH (p:Person) RETURN p'],
            new ActionContext()
        );

        $this->assertSame(ActionKind::Error, $result->kind);
        $this->assertStringContainsStringIgnoringCase('read-only', $result->summaryForLlm);
    }

    /** @test */
    public function a_failed_execution_result_becomes_a_corrective_error(): void
    {
        $executor = new RecordingQueryExecutor(new QueryExecutionResult(
            success: false,
            errors: ['boom'],
        ));
        $tool = new GraphQueryTool($executor);

        $result = $tool->handle(['cypher' => 'MATCH (p:Person) RETURN p'], new ActionContext());

        $this->assertSame(ActionKind::Error, $result->kind);
    }

    /** @test */
    public function rows_are_truncated_to_the_sampling_cap_for_the_model(): void
    {
        $rows = [];
        for ($i = 0; $i < 200; $i++) {
            $rows[] = ['n' => $i];
        }
        $executor = new RecordingQueryExecutor(new QueryExecutionResult(success: true, data: $rows));
        $tool = new GraphQueryTool($executor);

        $result = $tool->handle(['cypher' => 'MATCH (p:Person) RETURN p'], new ActionContext());

        $this->assertSame(ActionKind::Table, $result->kind);
        $this->assertLessThanOrEqual(GraphQueryTool::MAX_SAMPLE_ROWS, count($result->payload['rows']));
        $this->assertStringContainsString((string) count($rows), $result->summaryForLlm, 'the true row count is surfaced');
    }
}
