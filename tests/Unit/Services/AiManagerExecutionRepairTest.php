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
use Condoedge\Ai\Exceptions\QueryExecutionException;
use Condoedge\Ai\Exceptions\QueryTimeoutException;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Task 5.5 — repair on execution error.
 *
 * A Neo4j statement error (syntax / semantic: "Variable not defined", type errors,
 * "Unknown function") on the generated query triggers ONE regeneration fed with the
 * Neo4j error text and the generator's OWN draft (never the tenant-scoped rewrite);
 * the new draft goes through the same scoped executor. Infrastructure outcomes —
 * timeout, safe_stop, rate limit / connection — are never retried.
 */
class AiManagerExecutionRepairTest extends TestCase
{
    private const BROKEN = 'MATCH (a:Account) RETURN sum(ab.credit) AS balance';
    private const FIXED = 'MATCH (a:Account) OPTIONAL MATCH (ab:AccountBalance)-[:ACCOUNT]->(a) RETURN sum(ab.credit) AS balance';

    private ContextRetrieverInterface $context;
    private QueryGeneratorInterface $generator;
    private QueryExecutorInterface $executor;
    private ResponseGeneratorInterface $responder;
    private AiManager $manager;
    private array $recorded = [];

    public function setUp(): void
    {
        parent::setUp();

        config()->set('logging.default', 'null');
        config()->set('ai.log_channel', 'null');
        config()->set('ai.file_context.enabled', false);

        $this->context = Mockery::mock(ContextRetrieverInterface::class);
        $this->generator = Mockery::mock(QueryGeneratorInterface::class);
        $this->executor = Mockery::mock(QueryExecutorInterface::class);
        $this->responder = Mockery::mock(ResponseGeneratorInterface::class);

        $this->manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->context,
            Mockery::mock(EmbeddingProviderInterface::class),
            Mockery::mock(LlmProviderInterface::class),
            $this->generator,
            $this->executor,
            $this->responder,
            Mockery::mock(VectorStoreInterface::class)
        );

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'en');
        $this->context->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->responder->shouldReceive('generate')->andReturnUsing(
            fn (PipelineContext $ctx) => ['answer' => 'rows: ' . count($ctx->executionResult->data), 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]
        );
        $this->responder->shouldReceive('generateEmptyResponse')
            ->andReturn(['answer' => 'none', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);
        $this->responder->shouldReceive('generateErrorResponse')
            ->andReturn(['answer' => 'error answer', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => ['error' => true]]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** The executor's wrapping of a Neo4j statement error (previous = the driver exception). */
    private function neo4jStatementError(string $message): QueryExecutionException
    {
        $driver = new \RuntimeException(
            'Neo4j errors detected. First one with code "Neo.ClientError.Statement.SyntaxError" and message "'
            . $message . " (line 1, column 30 (offset: 29))\n\"MATCH (a:Account) WHERE a.team_id IN \$ai_team_ids RETURN sum(ab.credit)\"\n ^\""
        );

        return new QueryExecutionException('Query execution failed: Failed to execute Neo4j query: ' . $driver->getMessage(), 0, new \RuntimeException('Failed to execute Neo4j query: ' . $driver->getMessage(), 0, $driver));
    }

    /** The turn's conversation recorded FIXED, answering $question. */
    private function recordedTurn(string $question): void
    {
        $this->recorded = [
            'last_cypher_query' => self::FIXED,
            'last_cypher_question' => $question,
            'last_cypher_parameters' => ['pii_0123456789ab' => 'a@b.c', 'ai_team_ids' => [99]],
        ];
    }

    private function ask(RecordingPipelineObserver $observer): array
    {
        return $this->manager->answerQuestion('What is the balance of each account?', [
            'store_examples' => false,
            'conversation_context' => $this->recorded,
            'observer' => $observer,
        ]);
    }

    /** @test */
    public function a_statement_error_is_repaired_once_and_the_answer_comes_from_the_repaired_rows(): void
    {
        $repairOptions = null;
        $this->generator->shouldReceive('generate')->twice()->andReturnUsing(
            function ($q, $ctx, $options) use (&$repairOptions) {
                if (!isset($options['repair'])) {
                    return new QueryGenerationResult(cypher: self::BROKEN, confidence: 0.9);
                }
                $repairOptions = $options;

                return new QueryGenerationResult(cypher: self::FIXED, confidence: 0.9);
            }
        );

        $executed = [];
        $this->executor->shouldReceive('execute')->twice()->andReturnUsing(function ($cypher) use (&$executed) {
            $executed[] = $cypher;
            if ($cypher === self::BROKEN) {
                throw $this->neo4jStatementError('Variable `ab` not defined');
            }

            return new QueryExecutionResult(success: true, data: [['balance' => 10.0], ['balance' => 5.0]]);
        });

        $observer = new RecordingPipelineObserver();
        $result = $this->ask($observer);

        $this->assertSame([self::BROKEN, self::FIXED], $executed);
        $this->assertSame('rows: 2', $result['answer']);
        $this->assertSame(self::FIXED, $result['cypher']);

        // Feedback = the generator's OWN draft + the Neo4j error text, not the scoped rewrite.
        $this->assertSame(self::BROKEN, $repairOptions['repair']['cypher']);
        $this->assertStringContainsString('Variable `ab` not defined', $repairOptions['repair']['error']);
        $this->assertStringContainsString('Neo.ClientError.Statement.SyntaxError', $repairOptions['repair']['error']);
        $this->assertStringNotContainsString('ai_team_ids', $repairOptions['repair']['error']);
        $this->assertSame(1, $repairOptions['max_retries']);

        $execution = $observer->stageData('execution');
        $this->assertTrue($execution['repair_attempted']);
        $this->assertTrue($execution['repair_succeeded']);
        $this->assertSame(2, $execution['row_count']);
    }

    /** @test */
    public function a_repair_that_fails_again_falls_back_to_the_error_answer(): void
    {
        $this->generator->shouldReceive('generate')->twice()->andReturnUsing(
            fn ($q, $ctx, $options) => new QueryGenerationResult(cypher: isset($options['repair']) ? self::FIXED : self::BROKEN, confidence: 0.9)
        );
        $this->executor->shouldReceive('execute')->twice()->andThrow($this->neo4jStatementError('Unknown function \'nofunc\''));

        $observer = new RecordingPipelineObserver();
        $result = $this->ask($observer);

        $this->assertSame('error answer', $result['answer']);
        $execution = $observer->stageData('execution');
        $this->assertTrue($execution['repair_attempted']);
        $this->assertFalse($execution['repair_succeeded']);
    }

    /** @test */
    public function the_same_question_again_runs_the_recorded_query_with_no_generation_and_answers_from_its_rows(): void
    {
        $this->generator->shouldNotReceive('generate');
        $executed = [];
        $this->executor->shouldReceive('execute')->once()->andReturnUsing(function ($cypher, $params) use (&$executed) {
            $executed = [$cypher, $params];

            return new QueryExecutionResult(success: true, data: [['balance' => 10.0], ['balance' => 5.0]]);
        });
        $this->recordedTurn('WHAT is the balance of each account');

        $result = $this->ask(new RecordingPipelineObserver());

        $this->assertSame(self::FIXED, $executed[0]);
        $this->assertSame(['pii_0123456789ab' => 'a@b.c'], $executed[1], 'Only the recorded PII tokens ride along.');
        $this->assertSame('rows: 2', $result['answer']);
    }

    /** @test */
    public function the_generator_saying_the_recorded_query_answers_runs_it_and_a_plain_decline_does_not(): void
    {
        $this->generator->shouldReceive('generate')->once()->andReturn(
            new QueryGenerationResult(cypher: '', confidence: 1.0, metadata: ['reuse_previous' => true])
        );
        $this->executor->shouldReceive('execute')->once()->andReturn(new QueryExecutionResult(success: true, data: [['balance' => 10.0]]));
        $this->recordedTurn('Another wording');

        $this->assertSame('rows: 1', $this->ask(new RecordingPipelineObserver())['answer']);
    }

    /** @test */
    public function a_decline_without_the_reuse_signal_runs_nothing(): void
    {
        $this->generator->shouldReceive('generate')->once()->andReturn(new QueryGenerationResult(cypher: '', confidence: 1.0));
        $executed = [];
        $this->executor->shouldReceive('execute')->andReturnUsing(function ($cypher) use (&$executed) {
            $executed[] = $cypher;

            return new QueryExecutionResult(success: true, data: []);
        });
        $this->recordedTurn('Another wording');

        $this->ask(new RecordingPipelineObserver());

        $this->assertNotContains(self::FIXED, $executed, 'The recorded query does not run.');
    }

    /** @test */
    public function a_timeout_is_never_retried(): void
    {
        $this->generator->shouldReceive('generate')->once()
            ->andReturn(new QueryGenerationResult(cypher: self::BROKEN, confidence: 0.9));
        $this->executor->shouldReceive('execute')->once()
            ->andThrow(new QueryTimeoutException('Query exceeded timeout of 30 seconds'));

        $observer = new RecordingPipelineObserver();
        $result = $this->ask($observer);

        $this->assertSame('error answer', $result['answer']);
        $this->assertFalse($observer->stageData('execution')['repair_attempted']);
    }

    /** @test */
    public function a_safe_stop_is_never_retried(): void
    {
        $this->generator->shouldReceive('generate')->once()
            ->andReturn(new QueryGenerationResult(cypher: self::BROKEN, confidence: 0.9));
        $this->executor->shouldReceive('execute')->once()
            ->andReturn(new QueryExecutionResult(success: true, data: [], metadata: ['safe_stop' => true]));

        $observer = new RecordingPipelineObserver();
        $this->ask($observer);

        $this->assertFalse($observer->stageData('execution')['repair_attempted']);
    }

    /** @test */
    public function an_infrastructure_error_without_a_statement_code_is_never_retried(): void
    {
        $this->generator->shouldReceive('generate')->once()
            ->andReturn(new QueryGenerationResult(cypher: self::BROKEN, confidence: 0.9));
        $this->executor->shouldReceive('execute')->once()->andThrow(new QueryExecutionException(
            'Query execution failed: Failed to execute Neo4j query: Cannot connect to host localhost:7687',
            0,
            new \RuntimeException('Cannot connect to host localhost:7687')
        ));

        $observer = new RecordingPipelineObserver();
        $this->ask($observer);

        $this->assertFalse($observer->stageData('execution')['repair_attempted']);
    }

    /** @test */
    public function repair_can_be_disabled(): void
    {
        config()->set('ai.query_generation.repair_on_execution_error', false);
        $this->generator->shouldReceive('generate')->once()
            ->andReturn(new QueryGenerationResult(cypher: self::BROKEN, confidence: 0.9));
        $this->executor->shouldReceive('execute')->once()->andThrow($this->neo4jStatementError('Variable `ab` not defined'));

        $observer = new RecordingPipelineObserver();
        $result = $this->ask($observer);

        $this->assertSame('error answer', $result['answer']);
        $this->assertFalse($observer->stageData('execution')['repair_attempted']);
    }
}
