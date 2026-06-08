<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

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
    public array $relevantEntities = [];
    public array $entityMetadata = [];
    public array $selectionInfo = [];
    public array $errors = [];
    public array $quality = [];

    /** File context from FileContextProvider */
    public array $fileContext = [];

    /** Conversation context from chat pipeline */
    public array $conversationContext = [];

    public function __construct()
    {
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
