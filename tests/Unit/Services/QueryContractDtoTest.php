<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Mockery;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryExecutionResult;

/**
 * Pins the DTO-first query/exec contracts (ws-a-query-10 / ws-x-crosscutting-5):
 * the generator and executor return their typed DTOs, and the executor's
 * empty-cypher branch and success branch produce ONE consistent DTO shape so a
 * caller never has to special-case a divergent return.
 */
class QueryContractDtoTest extends TestCase
{
    private LlmProviderInterface $mockLlm;
    private GraphStoreInterface $mockGraph;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('ai.security.enabled', false);

        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);
        $this->mockGraph = Mockery::mock(GraphStoreInterface::class);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_generator_generate_returns_the_generation_result_dto(): void
    {
        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->andReturn('MATCH (n:Customer) RETURN n LIMIT 100');

        $generator = new QueryGenerator($this->mockLlm, $this->mockGraph, ['enable_templates' => false]);

        $context = new PipelineContext();
        $context->graphSchema = ['labels' => ['Customer'], 'relationships' => []];

        $result = $generator->generate('What are the customers?', $context, ['explain' => false]);

        $this->assertInstanceOf(QueryGenerationResult::class, $result);
        $this->assertTrue($result->hasCypher());
    }

    public function test_executor_execute_returns_the_execution_result_dto(): void
    {
        $this->mockGraph->shouldReceive('query')
            ->once()
            ->andReturn([['n' => ['id' => 1]]]);

        $executor = new QueryExecutor($this->mockGraph, [
            'default_timeout' => 30,
            'default_limit' => 100,
            'read_only_mode' => true,
            'default_format' => 'table',
            'log_slow_queries' => false,
        ]);

        $result = $executor->execute('MATCH (n:Customer) RETURN n LIMIT 10');

        $this->assertInstanceOf(QueryExecutionResult::class, $result);
        $this->assertTrue($result->success);
    }

    public function test_empty_and_success_execute_branches_share_one_dto_shape(): void
    {
        // Success branch: the store returns rows.
        $this->mockGraph->shouldReceive('query')
            ->once()
            ->andReturn([['n' => ['id' => 1]]]);

        $executor = new QueryExecutor($this->mockGraph, [
            'default_timeout' => 30,
            'default_limit' => 100,
            'read_only_mode' => true,
            'default_format' => 'table',
            'log_slow_queries' => false,
        ]);

        $success = $executor->execute('MATCH (n:Customer) RETURN n LIMIT 10');

        // Empty-cypher branch: nothing to run, the store is never hit.
        $empty = $executor->execute('');

        // Same DTO type and the SAME populated field set on both branches — the
        // empty branch no longer returns a divergent 'context'/'NO QUERY' shape
        // without 'errors'. Every well-formed field is present on both.
        $this->assertInstanceOf(QueryExecutionResult::class, $success);
        $this->assertInstanceOf(QueryExecutionResult::class, $empty);

        $this->assertIsBool($success->success);
        $this->assertIsBool($empty->success);
        $this->assertIsArray($success->data);
        $this->assertIsArray($empty->data);
        $this->assertIsArray($success->stats);
        $this->assertIsArray($empty->stats);
        $this->assertIsArray($success->metadata);
        $this->assertIsArray($empty->metadata);
        $this->assertIsArray($success->errors);
        $this->assertIsArray($empty->errors);

        // Both branches report success with no errors; the empty branch carries
        // its "no query" signal in metadata, not a divergent top-level key.
        $this->assertTrue($success->success);
        $this->assertTrue($empty->success);
        $this->assertSame([], $success->errors);
        $this->assertSame([], $empty->errors);
        $this->assertSame('NO QUERY', $empty->metadata['context']);
    }
}
