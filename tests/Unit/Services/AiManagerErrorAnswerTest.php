<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Exceptions\QueryExecutionException;
use Condoedge\Ai\Exceptions\SecurityException;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Task 1.23 — every stage-error path yields a NON-EMPTY, question-language answer.
 *
 * Live eval 2026-09-29 (rights t2, "So how much is my contribution this year?"):
 * a Neo4j SyntaxError at execution produced an EMPTY answer string. The real
 * ResponseGenerator is used here (LLM mocked, never called on error paths) so
 * the assertions read the actual rendered, localized copy.
 */
class AiManagerErrorAnswerTest extends TestCase
{
    private ContextRetrieverInterface $mockContext;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private LlmProviderInterface $mockLlm;
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
        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);

        $this->manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            Mockery::mock(EmbeddingProviderInterface::class),
            $this->mockLlm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            new ResponseGenerator($this->mockLlm),
            Mockery::mock(VectorStoreInterface::class)
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function frenchDataTurn(): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'fr');

        return $ctx;
    }

    /** @test */
    public function an_execution_exception_yields_a_non_empty_answer_in_the_question_language(): void
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($this->frenchDataTurn());
        $this->mockQueryGenerator->shouldReceive('generate')->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (i:Invoice) RETURN sum(i.total) AS total',
            confidence: 0.9,
        ));
        $this->mockQueryExecutor->shouldReceive('execute')->andThrow(new QueryExecutionException(
            'Query execution failed: Neo.ClientError.Statement.SyntaxError Invalid input'
        ));
        $this->mockLlm->shouldReceive('complete')->never();

        $result = $this->manager->answerQuestion('Combien est ma contribution cette année?', ['store_examples' => false]);

        $this->assertNotSame('', trim($result['answer']));
        $this->assertStringContainsString("J'ai rencontré un problème", $result['answer']);
        $this->assertTrue($result['metadata']['response']['error'] ?? false);
    }

    /** @test */
    public function an_execution_exception_on_a_turn_with_documents_still_yields_the_localized_error_not_an_llm_guess(): void
    {
        // Documents were retrieved (law chunks) but the DATA query errored: the
        // data question was not answered, so the honest outcome is the localized
        // error — not an LLM narration over an empty result set.
        $ctx = $this->frenchDataTurn();
        $ctx->fileContext = ['relevant_files' => [['file_id' => 1, 'snippet' => 'Art. 1064 CCQ', 'relevance' => 0.8]]];
        $ctx->queryResult = new QueryGenerationResult(cypher: 'MATCH (i:Invoice) RETURN sum(i.total) AS total', confidence: 0.9);
        $ctx->executionResult = new QueryExecutionResult(
            success: false,
            errors: ['Query execution failed: Neo.ClientError.Statement.SyntaxError'],
        );
        $this->mockLlm->shouldReceive('complete')->never();

        $result = (function (string $q, $c) { return $this->buildFinalResponse($q, $c, []); })->call($this->manager, 'Combien est ma contribution cette année?', $ctx);

        $this->assertNotSame('', trim($result['answer']));
        $this->assertStringContainsString("J'ai rencontré un problème", $result['answer']);
    }

    /** @test */
    public function a_mixed_question_with_documents_answers_from_them_and_discloses_the_failed_data_part(): void
    {
        // Focus-2 (review): the turn is NOT a pure data_query and documents were
        // retrieved, so the documents still answer — through the normal LLM path,
        // with a prompt notice forbidding any claim about the data, and a fixed
        // localized sentence appended (the deterministic honesty guarantee).
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'hybrid', language: 'fr');
        $ctx->fileContext = ['relevant_files' => [['file_id' => 1, 'filename' => 'ccq.pdf', 'snippet' => 'Art. 1064 CCQ', 'relevance' => 0.8]]];
        $ctx->queryResult = new QueryGenerationResult(cypher: 'MATCH (i:Invoice) RETURN sum(i.total) AS total', confidence: 0.9);
        $ctx->executionResult = new QueryExecutionResult(success: false, errors: ['Neo.ClientError.Statement.SyntaxError']);

        $prompt = null;
        $this->mockLlm->shouldReceive('complete')->once()
            ->andReturnUsing(function ($userPrompt) use (&$prompt) {
                $prompt = $userPrompt;
                return "Chaque copropriétaire contribue aux charges communes [1].";
            });

        $result = (function (string $q, $c) { return $this->buildFinalResponse($q, $c, []); })->call($this->manager, 'Quelles sont mes obligations et combien je dois?', $ctx);

        $this->assertStringContainsString('could not be retrieved', (string) $prompt);
        $this->assertStringContainsString('do not claim there is no data', (string) $prompt);
        $this->assertStringContainsString('charges communes [1]', $result['answer']);
        $this->assertStringEndsWith(trans('ai.error.data_part_failed', [], 'fr'), $result['answer']);
        $this->assertNotSame('ai.error.data_part_failed', trans('ai.error.data_part_failed', [], 'fr'));
        $this->assertSame('partial_data_failed', $result['metadata']['response']['status'] ?? null);
    }

    /** @test A role refused the data is told so, not "an issue occurred, try again" (live 8-data editor-money). */
    public function a_query_the_users_access_refuses_is_answered_as_no_access_not_as_an_error(): void
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($this->frenchDataTurn());
        $this->mockQueryGenerator->shouldReceive('generate')->andReturn(new QueryGenerationResult(cypher: 'MATCH (c:Campaign) RETURN c.raised AS raised', confidence: 0.9));
        $this->mockQueryExecutor->shouldReceive('execute')->andThrow(new SecurityException('You do not have permission to query Campaign data'));
        $this->mockLlm->shouldReceive('complete')->never();

        $result = $this->manager->answerQuestion("Combien d'argent on a amassé?", ['store_examples' => false]);

        $this->assertSame(trans('ai.error.no_access', [], 'fr'), $result['answer']);
        $this->assertNotSame('ai.error.no_access', $result['answer']);
        $this->assertSame('access_denied', $result['metadata']['response']['status'] ?? null);
    }

    /** @test */
    public function a_mixed_question_whose_data_part_is_refused_says_so_under_the_documents_answer(): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'hybrid', language: 'fr');
        $ctx->fileContext = ['relevant_files' => [['file_id' => 1, 'filename' => 'aide.md', 'snippet' => 'Tableau de bord', 'relevance' => 0.8]]];
        $ctx->queryResult = new QueryGenerationResult(cypher: 'MATCH (c:Campaign) RETURN c.raised AS raised', confidence: 0.9);
        $ctx->executionResult = new QueryExecutionResult(success: false, metadata: ['execution_error' => SecurityException::class], errors: ['You do not have permission to query Campaign data']);
        $this->mockLlm->shouldReceive('complete')->once()->andReturn('Le montant se voit au tableau de bord [1].');

        $result = (function (string $q, $c) { return $this->buildFinalResponse($q, $c, []); })->call($this->manager, 'Où voir le montant et combien on a?', $ctx);

        $this->assertStringEndsWith(trans('ai.error.data_part_denied', [], 'fr'), $result['answer']);
        $this->assertNotSame('ai.error.data_part_denied', trans('ai.error.data_part_denied', [], 'fr'));
    }

    /** @test */
    public function an_execution_failure_result_without_documents_yields_the_localized_error(): void
    {
        $ctx = $this->frenchDataTurn();
        $ctx->queryResult = new QueryGenerationResult(cypher: 'MATCH (i:Invoice) RETURN i', confidence: 0.9);
        $ctx->executionResult = new QueryExecutionResult(success: false, errors: ['boom']);
        $this->mockLlm->shouldReceive('complete')->never();

        $result = (function (string $q, $c) { return $this->buildFinalResponse($q, $c, []); })->call($this->manager, 'Combien est ma contribution cette année?', $ctx);

        $this->assertStringContainsString("J'ai rencontré un problème", $result['answer']);
    }

    /** @test */
    public function a_generation_failure_yields_the_localized_error(): void
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($this->frenchDataTurn());
        $this->mockQueryGenerator->shouldReceive('generate')->andThrow(new \RuntimeException('LLM unavailable'));
        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(QueryExecutionResult::empty());
        $this->mockLlm->shouldReceive('complete')->never();

        $result = $this->manager->answerQuestion('Combien est ma contribution cette année?', ['store_examples' => false]);

        $this->assertStringContainsString("J'ai rencontré un problème", $result['answer']);
    }

    // =========================================================================
    // Eval p11 D3: a FAILED understanding is a temporary problem, never "no results"
    // =========================================================================

    /** @test */
    public function a_failed_understanding_with_nothing_retrieved_answers_a_temporary_problem_not_no_results(): void
    {
        // Live p11 my-balance-then-pdf#1: QU threw TransientLlmException, the turn
        // continued on the neutral fallback (hybrid/other, no export_format) and
        // answered "No results were found for your request to create a PDF".
        $ctx = new PipelineContext();
        $ctx->qu = QueryUnderstandingResult::failed();
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: '', explanation: '', confidence: 1.0));
        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(QueryExecutionResult::empty());
        $this->mockLlm->shouldReceive('complete')->never();

        $observer = new \Condoedge\Ai\Support\RecordingPipelineObserver();
        $result = $this->manager->answerQuestion('Create a PDF of this.', ['store_examples' => false, 'observer' => $observer]);

        $this->assertSame(trans('ai.error.understanding_unavailable', [], 'en'), $result['answer']);
        $this->assertStringContainsString('temporary problem', $result['answer']);
        $this->assertStringNotContainsStringIgnoringCase('no results', $result['answer']);
        $this->assertSame('understanding_failed', $result['metadata']['response']['status'] ?? null);
        $this->assertSame('qu_failed', $result['metadata']['export_intent_unavailable'] ?? null);
        // The eval harness reads the error branch (stage-error) — never empty_result.
        $this->assertSame('error', $observer->stageData('composition')['branch'] ?? null);
    }

    /** @test */
    public function the_failed_understanding_answer_follows_the_app_locale_when_no_language_is_known(): void
    {
        app()->setLocale('fr');
        $ctx = new PipelineContext();
        $ctx->qu = QueryUnderstandingResult::failed();
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: null, explanation: '', confidence: 0.0));
        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(QueryExecutionResult::empty());
        $this->mockLlm->shouldReceive('complete')->never();

        $result = $this->manager->answerQuestion('Crée un PDF de ceci.', ['store_examples' => false]);

        $this->assertSame(trans('ai.error.understanding_unavailable', [], 'fr'), $result['answer']);
        $this->assertNotSame('ai.error.understanding_unavailable', $result['answer'], 'the key must be translated');
        $this->assertNotSame(trans('ai.error.understanding_unavailable', [], 'en'), $result['answer']);
    }

    /** @test */
    public function a_failed_understanding_whose_fallback_query_found_rows_still_answers_from_them(): void
    {
        // The full-schema fallback can still ground an answer: only the EMPTY
        // outcome is untrustworthy without an understanding.
        $ctx = new PipelineContext();
        $ctx->qu = QueryUnderstandingResult::failed();
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: 'MATCH (u:Unit) RETURN u.name AS unit', confidence: 0.9));
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(success: true, data: [['unit' => '310']]));
        $this->mockLlm->shouldReceive('complete')->andReturn('Unit 310 is listed.');

        $result = $this->manager->answerQuestion('Which units?', ['store_examples' => false]);

        $this->assertNotSame(trans('ai.error.understanding_unavailable', [], 'en'), $result['answer']);
        $this->assertNotSame('understanding_failed', $result['metadata']['response']['status'] ?? null);
    }
}
