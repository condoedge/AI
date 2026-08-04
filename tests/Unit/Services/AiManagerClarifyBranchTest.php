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
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * G2/L5 item C — the clarify BRANCH (iteration-11: "Show me the balance."
 * scored 0.15 five runs straight because QU manufactured a confident
 * clarified_question and the pipeline force-resolved a guessed reading).
 *
 * PIPELINE CONTRACT PINNED HERE: when QU flags needs_clarification (with a
 * usable clarifying_question), the turn SKIPS file search and query generation
 * entirely and answers with that ONE short clarifying question — zero extra
 * LLM calls (the question was produced in the same QU call). Guards: never on
 * followUpReference or digestRequest turns; config-gated
 * (ai.understanding.clarify_enabled, default true); an empty clarifying
 * question falls back to the normal pipeline (fail-safe).
 */
class AiManagerClarifyBranchTest extends TestCase
{
    private AiManager $manager;
    private ContextRetrieverInterface $mockContext;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;
    private FileContextProvider $mockFileContextProvider;
    private LlmProviderInterface $mockLlm;

    /** @var int */
    private int $searchCalls = 0;

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.security.enabled' => false]);
        config(['ai.file_context.enabled' => true]);
        config(['ai.understanding.clarify_enabled' => true]);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockFileContextProvider = Mockery::mock(FileContextProvider::class);
        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);

        $this->searchCalls = 0;
        $this->mockFileContextProvider->shouldReceive('getFileContext')
            ->andReturnUsing(function () {
                $this->searchCalls++;
                return ['relevant_files' => [], 'file_count' => 0];
            });

        $this->manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            Mockery::mock(EmbeddingProviderInterface::class),
            $this->mockLlm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            Mockery::mock(VectorStoreInterface::class),
            $this->mockFileContextProvider
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeContext(
        bool $needsClarification = true,
        string $clarifyingQuestion = 'Which balance do you mean — your own account, or the syndicate finances?',
        bool $followUp = false
    ): PipelineContext {
        $ctx = new PipelineContext();
        $ctx->question = 'Show me the balance.';
        $ctx->qu = new QueryUnderstandingResult(
            searchQuery: 'balance',
            intent: 'lookup',
            questionType: 'data_query',
            followUpReference: $followUp,
            needsClarification: $needsClarification,
            candidateReadings: ['Your own account balance', 'The syndicate financial balance'],
            clarifyingQuestion: $clarifyingQuestion,
        );
        $ctx->graphSchema = ['labels' => ['Invoice']];

        return $ctx;
    }

    /** @test */
    public function a_genuinely_ambiguous_turn_answers_with_the_one_clarifying_question(): void
    {
        $ctx = $this->makeContext();

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        // The whole point: NO retrieval, NO generation, NO response LLM call.
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockQueryExecutor->shouldReceive('execute')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockLlm->shouldReceive('chat')->never();

        $result = $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertSame(
            'Which balance do you mean — your own account, or the syndicate finances?',
            $result['answer']
        );
        $this->assertNull($result['cypher']);
        $this->assertSame([], $result['data']);
        $this->assertSame(0, $this->searchCalls, 'file search must never run on a clarify turn');
        $this->assertSame(
            ['Your own account balance', 'The syndicate financial balance'],
            $result['metadata']['response']['clarification']['candidate_readings'] ?? null,
            'the readings ride the trace for graders/UI'
        );
        $this->assertSame('clarification', $result['metadata']['query']['source'] ?? null);
    }

    /** @test */
    public function the_branch_is_config_gated(): void
    {
        config(['ai.understanding.clarify_enabled' => false]);
        $ctx = $this->makeContext();

        $this->runNormalTurn($ctx);

        $result = $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertSame('normal answer', $result['answer']);
    }

    /** @test */
    public function a_follow_up_reference_never_clarifies(): void
    {
        // Defence-in-depth beyond the parse guard: a DTO that somehow carries
        // both flags still routes to the normal pipeline.
        $ctx = $this->makeContext(followUp: true);

        $this->runNormalTurn($ctx);

        $result = $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertSame('normal answer', $result['answer']);
    }

    /** @test */
    public function an_empty_clarifying_question_falls_back_to_the_normal_pipeline(): void
    {
        $ctx = $this->makeContext(clarifyingQuestion: '   ');

        $this->runNormalTurn($ctx);

        $result = $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertSame('normal answer', $result['answer'], 'fail-safe: no usable question means no clarify branch');
    }

    /**
     * Wire the mocks for a turn the NORMAL pipeline must own.
     */
    private function runNormalTurn(PipelineContext $ctx): void
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')->once()->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (i:Invoice) RETURN sum(i.due) AS balance',
            explanation: '',
            confidence: 0.9,
        ));
        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(new QueryExecutionResult(
            success: true, data: [['balance' => 120]], stats: [], metadata: [], errors: [],
        ));
        $this->mockResponseGenerator->shouldReceive('generate')->andReturn([
            'answer' => 'normal answer', 'insights' => [], 'visualizations' => [], 'metadata' => [],
        ]);
    }
}
