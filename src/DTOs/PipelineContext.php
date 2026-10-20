<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\Support\NullPipelineObserver;

/**
 * Single typed context object flowing through the entire AI pipeline.
 *
 * Built by ContextRetriever, enriched by AiManager, read by QueryGenerator,
 * ResponseGenerator, and all prompt/response sections.
 */
class PipelineContext
{
    /** The original user question */
    public string $question = '';

    public QueryUnderstandingResult $qu;
    public SemanticSelectionResult $semanticSelection;
    public QueryGenerationResult $queryResult;
    public QueryExecutionResult $executionResult;

    public array $similarQueries = [];
    public array $graphSchema = [];
    public array $entityMetadata = [];
    public array $selectionInfo = [];
    public array $errors = [];
    public array $quality = [];

    /** File context from FileContextProvider */
    public array $fileContext = [];

    /** Conversation context from chat pipeline */
    public array $conversationContext = [];

    /**
     * Page context (the soft prior for "which page/record the chat was opened from").
     * The array form of a PageContext DTO; default [] keeps the PageContextSection emitting
     * zero tokens when absent. Symmetric to $conversationContext.
     */
    public array $pageContext = [];

    /**
     * Host-supplied narration for the current page ("Explain this page"). Set only when the
     * turn is answered as read-only page narration grounded on config('ai.chat.page_descriptions');
     * default '' keeps PageDescriptionSection silent (zero tokens) for every ordinary turn.
     */
    public string $pageDescription = '';

    /**
     * The turn's pipeline observer (observation only — never read back by the
     * pipeline). Set by AiManager::answerQuestion / ContextRetriever from
     * options['observer']; services that build LLM prompts report their
     * verbatim messages and token usage through it. Default: the null observer.
     */
    public PipelineObserverInterface $observer;

    /**
     * The turn's PII token map (Phase 8 batch A) — shared by every response
     * section of ONE turn so a value keeps one token across the whole prompt.
     * Built lazily by PiiMasker::for(); reset by ResponseGenerator per generation.
     */
    public ?\Condoedge\Ai\Services\Response\PiiMasker $piiMasker = null;

    /**
     * The turn's deterministic data-answer plan (compact LLM summary, facts,
     * system-computed totals, the user-facing table). Built lazily by
     * DataAnswerPlan::for(); reset by ResponseGenerator per generation.
     */
    public ?\Condoedge\Ai\Services\Response\DataAnswerPlan $dataAnswerPlan = null;

    /**
     * The links/actions CODE attaches to this turn's answer (actions plan B1–B4), computed
     * by AiManager before composition so the narrator is told which chips exist and may
     * reference rows with {{record:N}}. Null = nothing attached (standalone generation).
     */
    public ?\Condoedge\Ai\Services\Actions\Attach\AttachedActions $attachedActions = null;

    public function __construct()
    {
        $this->observer = new NullPipelineObserver();
        $this->qu = new QueryUnderstandingResult();
        $this->semanticSelection = new SemanticSelectionResult();
        $this->queryResult = new QueryGenerationResult();
        $this->executionResult = QueryExecutionResult::empty();
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }
}
