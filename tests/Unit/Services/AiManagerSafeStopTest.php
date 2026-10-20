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
 * Task 1.6 — a scoping safe-stop must never read as "no data".
 *
 * QueryExecutor returns success=true, data=[], metadata.safe_stop=true when the
 * scoping layer refuses a query. Before this fix the chat path never read the
 * flag and the empty-result template told the user "no results" — a silent
 * wrong answer. The pipeline must say honestly that it could not run the query
 * safely, in the question's language, with status=blocked_by_scoping.
 */
class AiManagerSafeStopTest extends TestCase
{
    private ContextRetrieverInterface $mockContext;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;
    private AiManager $manager;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('logging.default', 'null');
        config()->set('ai.log_channel', 'null');
        config()->set('ai.file_context.enabled', false);
        app()->setLocale('en');

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

    private function safeStoppedTurn(string $language): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: $language);
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (i:Invoice) RETURN i.total AS total',
            confidence: 0.9,
        ));

        $this->mockQueryExecutor->shouldReceive('execute')->once()->andReturn(new QueryExecutionResult(
            success: true,
            data: [],
            metadata: ['security_filter_applied' => false, 'safe_stop' => true],
        ));
        $this->mockQueryExecutor->shouldReceive('executeCount')->never();

        // Never the empty-result template, never an LLM narration over nothing.
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();
    }

    /** @test */
    public function a_safe_stop_is_answered_honestly_in_english(): void
    {
        $this->safeStoppedTurn('en');

        $result = $this->manager->answerQuestion('What do I owe?', ['store_examples' => false]);

        $this->assertSame(trans('ai.error.scoping_blocked', [], 'en'), $result['answer']);
        $this->assertStringContainsString("couldn't run that query safely", $result['answer']);
        $this->assertSame('blocked_by_scoping', $result['metadata']['response']['status'] ?? null);
        $this->assertArrayNotHasKey('empty_result', $result['metadata']['response']);
    }

    private function safeStoppedContext(string $questionType): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: $questionType, language: 'fr');
        $ctx->fileContext = ['relevant_files' => [['file_id' => 1, 'filename' => 'ccq.pdf', 'snippet' => 'Art. 1064', 'relevance' => 0.8]]];
        $ctx->queryResult = new QueryGenerationResult(cypher: 'MATCH (i:Invoice) RETURN i.total AS total', confidence: 0.9);
        $ctx->executionResult = new QueryExecutionResult(success: true, metadata: ['safe_stop' => true]);

        return $ctx;
    }

    /** @test */
    public function a_safe_stop_on_a_mixed_question_with_documents_answers_from_them_and_discloses_the_block(): void
    {
        // Focus-2: documents answer the non-data half through the normal LLM
        // path; the fixed localized sentence discloses the blocked data part.
        $this->mockResponseGenerator->shouldReceive('generate')->once()
            ->andReturn(['answer' => 'Chaque copropriétaire contribue aux charges [1].', 'insights' => [], 'visualizations' => [], 'format' => 'text', 'metadata' => []]);
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $result = (function (string $q, $c) { return $this->buildFinalResponse($q, $c, []); })->call($this->manager, 'Quelles sont mes obligations et combien je dois?', $this->safeStoppedContext('hybrid'));

        $this->assertStringContainsString('charges [1]', $result['answer']);
        $this->assertStringEndsWith(trans('ai.error.data_part_blocked', [], 'fr'), $result['answer']);
        $this->assertNotSame('ai.error.data_part_blocked', trans('ai.error.data_part_blocked', [], 'fr'));
        $this->assertSame('blocked_by_scoping', $result['metadata']['response']['status'] ?? null);
    }

    /** @test */
    public function a_safe_stop_on_a_pure_data_question_keeps_the_canned_answer_even_with_documents(): void
    {
        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $result = (function (string $q, $c) { return $this->buildFinalResponse($q, $c, []); })->call($this->manager, 'Combien je dois?', $this->safeStoppedContext('data_query'));

        $this->assertSame(trans('ai.error.scoping_blocked', [], 'fr'), $result['answer']);
        $this->assertSame('blocked_by_scoping', $result['metadata']['response']['status'] ?? null);
    }

    /** @test */
    public function a_safe_stop_is_answered_in_the_question_language(): void
    {
        $this->safeStoppedTurn('fr');

        $result = $this->manager->answerQuestion('Combien est-ce que je dois?', ['store_examples' => false]);

        $this->assertSame(trans('ai.error.scoping_blocked', [], 'fr'), $result['answer']);
        $this->assertNotSame(trans('ai.error.scoping_blocked', [], 'en'), $result['answer']);
        $this->assertSame('blocked_by_scoping', $result['metadata']['response']['status'] ?? null);
    }
}
