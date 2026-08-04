<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Eval;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Eval\PipelineEvalDriver;
use Condoedge\Ai\Tests\TestCase;

/**
 * Pins that the eval driver scores PRODUCTION: page_context and the acting user
 * are threaded through every pipeline stage exactly as AiManager::answerQuestion
 * threads them, so a page-scoped recommended question is not evaluated context-less.
 */
class PipelineEvalDriverTest extends TestCase
{
    /** @test */
    public function it_threads_page_context_and_user_through_every_stage_like_answer_question(): void
    {
        $pageContext = ['entity_type' => 'Unit', 'entity_id' => 42, 'label' => 'Unit 42'];
        $user = (object) ['id' => 7];

        $facade = new RecordingAiFacade();
        $driver = new PipelineEvalDriver($facade);

        $driver->run(
            question: 'Show this unit payment history',
            user: $user,
            tags: ['page:Unit'],
            offline: false,
            pageContext: $pageContext,
        );

        // Stage 1 — context retrieval gets page_context + user as options, exactly
        // like answerQuestion passes $options into ContextRetriever::retrieveContext.
        $this->assertSame($pageContext, $facade->retrieveContextOptions['page_context'] ?? null);
        $this->assertSame($user, $facade->retrieveContextOptions['user'] ?? null);

        // Stage 2 — the PipelineContext handed to query generation carries pageContext
        // (so the QueryGenerator's PageContextSection can bind "this unit").
        $this->assertNotNull($facade->generateQueryContext);
        $this->assertSame($pageContext, $facade->generateQueryContext->pageContext);
        $this->assertSame($user, $facade->generateQueryOptions['user'] ?? null);

        // Stage 4 — the PipelineContext handed to response generation carries pageContext
        // too, so prompt assembly is not blind to the page the chat was opened from.
        $this->assertNotNull($facade->generateResponseContext);
        $this->assertSame($pageContext, $facade->generateResponseContext->pageContext);
        $this->assertSame($user, $facade->generateResponseOptions['user'] ?? null);
    }

    /** @test */
    public function it_leaves_page_context_empty_when_none_is_supplied(): void
    {
        $facade = new RecordingAiFacade();
        $driver = new PipelineEvalDriver($facade);

        $driver->run('How many units are there?', (object) ['id' => 1]);

        $this->assertSame([], $facade->retrieveContextOptions['page_context'] ?? []);
        $this->assertNotNull($facade->generateResponseContext);
        $this->assertSame([], $facade->generateResponseContext->pageContext);
    }

    /** @test */
    public function the_understanding_trace_captures_the_extra_retrieval_angle_families(): void
    {
        // The corpus-translation and register-expansion angles are retrieval
        // levers — a grader must be able to see from the trace whether QU
        // emitted zero angles (config/parse problem) or bad ones (prompt
        // problem) on a missed-document turn.
        $facade = new RecordingAiFacade();
        $facade->quOverride = new QueryUnderstandingResult(
            questionType: 'knowledge_query',
            corpusTranslations: ['fr' => 'formulation en langue du corpus'],
            registerExpansions: ['reformulation in the corpus register'],
        );

        $driver = new PipelineEvalDriver($facade);
        $row = $driver->run('Can I do X?', (object) ['id' => 1]);

        $this->assertSame(
            ['fr' => 'formulation en langue du corpus'],
            $row->understanding['corpus_translations'] ?? null
        );
        $this->assertSame(
            ['reformulation in the corpus register'],
            $row->understanding['register_expansions'] ?? null
        );
    }

    /** @test */
    public function a_docless_knowledge_query_still_generates_a_query_like_production(): void
    {
        // Production's knowledge-skip (AiManager::generateAndExecute) requires
        // $hasFileContext: with NO documents retrieved it falls through to
        // query generation so an empty result becomes an explicit no-source
        // abstention instead of a confident "answered from documents". The
        // driver must take the same branch — skipping on question type alone
        // grades a blinder pipeline than production runs.
        $facade = new RecordingAiFacade();
        $facade->quOverride = new QueryUnderstandingResult(questionType: 'knowledge_query');

        $driver = new PipelineEvalDriver($facade);
        $row = $driver->run('Can I do X?', (object) ['id' => 1]);

        $this->assertNotNull($facade->generateQueryContext, 'query generation must run for a docless knowledge query');
        $this->assertSame('MATCH (u:Unit) RETURN u', $row->cypher);
    }

    /** @test */
    public function a_knowledge_query_with_documents_skips_query_generation_like_production(): void
    {
        // The other side of the parity: WITH documents retrieved, the
        // knowledge-skip holds (the documents are the grounding) — same
        // branch as AiManager::generateAndExecute.
        $facade = new RecordingAiFacade();
        $facade->quOverride = new QueryUnderstandingResult(questionType: 'knowledge_query');
        $facade->fileContextFiles = [['file_id' => 9, 'snippet' => 'policy text', 'relevance' => 0.9]];

        $driver = new PipelineEvalDriver($facade);
        $row = $driver->run('What does the policy say?', (object) ['id' => 1]);

        $this->assertNull($facade->generateQueryContext, 'the knowledge-skip must hold when documents ground the turn');
        $this->assertNull($row->cypher);
    }
}

/**
 * Minimal AI-facade double that records the context/options each pipeline stage
 * receives, returning enough signal to walk the driver to the response stage.
 */
class RecordingAiFacade
{
    public array $retrieveContextOptions = [];
    public ?PipelineContext $generateQueryContext = null;
    public array $generateQueryOptions = [];
    public ?PipelineContext $generateResponseContext = null;
    public array $generateResponseOptions = [];

    /** When set, the QU result stage 1 returns (to exercise trace capture). */
    public ?QueryUnderstandingResult $quOverride = null;

    /** Files the file-context seam stamps onto the context (default: none). */
    public array $fileContextFiles = [];

    public function retrieveContext(string $question, array $options = []): PipelineContext
    {
        $this->retrieveContextOptions = $options;

        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = $this->quOverride ?? new QueryUnderstandingResult(questionType: 'data_query');
        $ctx->selectionInfo = ['selected_entities' => ['Unit']];

        return $ctx;
    }

    public function retrieveFileContext(string $query, mixed $user = null, array $options = []): array
    {
        return [];
    }

    /** Production file-context seam: stamps the configured files (none by default). */
    public function retrieveFileContextInto(PipelineContext $context, string $question, array $options = []): PipelineContext
    {
        if ($this->fileContextFiles !== []) {
            $context->fileContext = ['relevant_files' => $this->fileContextFiles];
        }

        return $context;
    }

    /** Production entity-action enrichment seam: the stub passes rows through. */
    public function enrichExecutionResult(\Condoedge\Ai\DTOs\QueryExecutionResult $executionResult): \Condoedge\Ai\DTOs\QueryExecutionResult
    {
        return $executionResult;
    }

    public function generateQuery(string $question, ?PipelineContext $context = null, array $options = []): array
    {
        $this->generateQueryContext = $context;
        $this->generateQueryOptions = $options;

        return [
            'cypher' => 'MATCH (u:Unit) RETURN u',
            'confidence' => 0.9,
            'warnings' => [],
            'explanation' => '',
            'metadata' => [],
        ];
    }

    public function executeQuery(string $cypher, array $params = [], array $options = []): array
    {
        return ['data' => [['u' => 1]], 'metadata' => [], 'errors' => [], 'stats' => []];
    }

    /**
     * The driver now finishes turns through the PRODUCTION composition seam
     * (AiManager::composeResponse) rather than calling generateResponse directly,
     * so the export/abstention paths are exercised. Same recording, same shape.
     */
    public function composeResponse(string $question, PipelineContext $context, array $options = []): array
    {
        $this->generateResponseContext = $context;
        $this->generateResponseOptions = $options;

        return [
            'question' => $question,
            'answer' => 'Here is the unit payment history.',
            'insights' => [],
            'visualizations' => [],
            'cypher' => $context->queryResult->cypher,
            'data' => $context->executionResult->data,
            'stats' => [],
            'referenced_files' => [],
            'metadata' => ['query' => [], 'execution' => [], 'response' => []],
        ];
    }
}
