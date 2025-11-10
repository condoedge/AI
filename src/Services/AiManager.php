<?php

declare(strict_types=1);

namespace AiSystem\Services;

use AiSystem\Contracts\DataIngestionServiceInterface;
use AiSystem\Contracts\ContextRetrieverInterface;
use AiSystem\Contracts\EmbeddingProviderInterface;
use AiSystem\Contracts\LlmProviderInterface;
use AiSystem\Contracts\QueryGeneratorInterface;
use AiSystem\Contracts\QueryExecutorInterface;
use AiSystem\Contracts\ResponseGeneratorInterface;
use AiSystem\Domain\Contracts\Nodeable;

/**
 * AI Manager - Convenient wrapper around AI services
 *
 * This class provides a convenient API while properly using dependency injection
 * and Laravel's service container. All dependencies are injected via constructor,
 * making this class fully testable and following Laravel best practices.
 *
 * **Usage via Facade:**
 * ```php
 * use AiSystem\Facades\AI;
 *
 * AI::ingest($customer);
 * $context = AI::retrieveContext("Show all teams");
 * $response = AI::chat("What is 2+2?");
 * ```
 *
 * **Usage via Dependency Injection:**
 * ```php
 * use AiSystem\Services\AiManager;
 *
 * class CustomerController extends Controller
 * {
 *     public function __construct(private AiManager $ai) {}
 *
 *     public function store(Request $request)
 *     {
 *         $customer = Customer::create($request->validated());
 *         $this->ai->ingest($customer);
 *     }
 * }
 * ```
 *
 * **Testing:**
 * ```php
 * // Mock the facade
 * AI::shouldReceive('ingest')->once()->with($customer)->andReturn([...]);
 *
 * // Or inject a mock
 * $mockAi = Mockery::mock(AiManager::class);
 * $mockAi->shouldReceive('ingest')->once()->andReturn([...]);
 * ```
 *
 * @package AiSystem\Services
 */
class AiManager
{
    /**
     * Create a new AI Manager instance
     *
     * All dependencies are injected via constructor, ensuring:
     * - Testability: Easy to mock dependencies
     * - Flexibility: Can swap implementations via service provider
     * - Clarity: Dependencies are explicit and visible
     *
     * @param DataIngestionServiceInterface $ingestion Data ingestion service
     * @param ContextRetrieverInterface $context Context retrieval (RAG) service
     * @param EmbeddingProviderInterface $embedding Embedding generation service
     * @param LlmProviderInterface $llm Language model service
     * @param QueryGeneratorInterface $queryGenerator Query generation service
     * @param QueryExecutorInterface $queryExecutor Query execution service
     * @param ResponseGeneratorInterface $responseGenerator Response generation service
     */
    public function __construct(
        private readonly DataIngestionServiceInterface $ingestion,
        private readonly ContextRetrieverInterface $context,
        private readonly EmbeddingProviderInterface $embedding,
        private readonly LlmProviderInterface $llm,
        private readonly QueryGeneratorInterface $queryGenerator,
        private readonly QueryExecutorInterface $queryExecutor,
        private readonly ResponseGeneratorInterface $responseGenerator
    ) {
    }

    // =========================================================================
    // Data Ingestion Methods
    // =========================================================================

    /**
     * Ingest an entity into both graph and vector stores
     *
     * @param Nodeable $entity Entity to ingest (must implement Nodeable)
     * @return array Status array with keys: graph_stored, vector_stored, relationships_created, errors
     */
    public function ingest(Nodeable $entity): array
    {
        return $this->ingestion->ingest($entity);
    }

    /**
     * Ingest multiple entities in batch (more efficient)
     *
     * @param array $entities Array of Nodeable entities
     * @return array Summary with keys: total, succeeded, partially_succeeded, failed, errors
     */
    public function ingestBatch(array $entities): array
    {
        return $this->ingestion->ingestBatch($entities);
    }

    /**
     * Remove an entity from both stores
     *
     * @param Nodeable $entity Entity to remove
     * @return bool True if removed successfully
     */
    public function remove(Nodeable $entity): bool
    {
        return $this->ingestion->remove($entity);
    }

    /**
     * Sync an entity (update if exists, create if not)
     *
     * @param Nodeable $entity Entity to sync
     * @return array Status array with keys: action, graph_synced, vector_synced, errors
     */
    public function sync(Nodeable $entity): array
    {
        return $this->ingestion->sync($entity);
    }

    // =========================================================================
    // Context Retrieval (RAG) Methods
    // =========================================================================

    /**
     * Retrieve context for a question (RAG)
     *
     * Combines vector similarity search with graph schema discovery
     * to provide rich context for LLM query generation.
     *
     * @param string $question The question to get context for
     * @param array $options Optional parameters:
     *                       - collection: Vector collection name (default: 'questions')
     *                       - limit: Max similar queries (default: 5)
     *                       - includeSchema: Include graph schema (default: true)
     *                       - includeExamples: Include sample entities (default: true)
     *                       - examplesPerLabel: Examples per label (default: 2)
     *                       - scoreThreshold: Minimum similarity score (default: 0.0)
     * @return array Context array with keys: similar_queries, graph_schema, relevant_entities, errors
     */
    public function retrieveContext(string $question, array $options = []): array
    {
        return $this->context->retrieveContext($question, $options);
    }

    /**
     * Search for similar questions using vector similarity
     *
     * @param string $question Question to search for
     * @param array $options Optional parameters (collection, limit, scoreThreshold)
     * @return array Array of similar queries with scores
     */
    public function searchSimilar(string $question, array $options = []): array
    {
        return $this->context->searchSimilar($question, $options);
    }

    /**
     * Get graph database schema
     *
     * @return array Schema with keys: labels, relationships, properties
     */
    public function getSchema(): array
    {
        return $this->context->getGraphSchema();
    }

    /**
     * Get example entities for specified labels
     *
     * @param array $labels Array of label names
     * @param int $limit Number of examples per label (default: 3)
     * @return array Entities grouped by label
     */
    public function getExampleEntities(array $labels, int $limit = 3): array
    {
        return $this->context->getExampleEntities($labels, $limit);
    }

    // =========================================================================
    // Embedding Methods
    // =========================================================================

    /**
     * Generate embedding for a text
     *
     * @param string $text Text to embed
     * @return array Vector representation (array of floats)
     */
    public function embed(string $text): array
    {
        return $this->embedding->embed($text);
    }

    /**
     * Generate embeddings for multiple texts (batch - more efficient)
     *
     * @param array $texts Array of texts
     * @return array Array of vectors
     */
    public function embedBatch(array $texts): array
    {
        return $this->embedding->embedBatch($texts);
    }

    /**
     * Get the dimensionality of embeddings
     *
     * @return int Vector dimensions
     */
    public function getEmbeddingDimensions(): int
    {
        return $this->embedding->getDimensions();
    }

    /**
     * Get the embedding model being used
     *
     * @return string Model identifier
     */
    public function getEmbeddingModel(): string
    {
        return $this->embedding->getModel();
    }

    // =========================================================================
    // LLM Methods
    // =========================================================================

    /**
     * Chat with the LLM
     *
     * Accepts either a simple string or an array of messages.
     *
     * @param string|array $input Simple string or array of messages
     *                            String: Converted to [['role' => 'user', 'content' => $input]]
     *                            Array: [['role' => 'user'|'system'|'assistant', 'content' => '...']]
     * @param array $options Optional parameters (temperature, max_tokens, etc.)
     * @return string LLM response
     */
    public function chat(string|array $input, array $options = []): string
    {
        // Convert simple string to messages array
        if (is_string($input)) {
            $input = [['role' => 'user', 'content' => $input]];
        }

        return $this->llm->chat($input, $options);
    }

    /**
     * Chat with the LLM and get JSON response
     *
     * Forces the LLM to respond with valid JSON.
     *
     * @param string|array $input Simple string or array of messages
     * @param array $options Optional parameters
     * @return object|array Decoded JSON response
     */
    public function chatJson(string|array $input, array $options = []): object|array
    {
        // Convert simple string to messages array
        if (is_string($input)) {
            $input = [['role' => 'user', 'content' => $input]];
        }

        return $this->llm->chatJson($input, $options);
    }

    /**
     * Simple completion (prompt + optional system message)
     *
     * Convenience method for single-turn interactions.
     *
     * @param string $prompt User prompt
     * @param string|null $systemPrompt Optional system message
     * @param array $options Optional parameters
     * @return string LLM response
     */
    public function complete(string $prompt, ?string $systemPrompt = null, array $options = []): string
    {
        return $this->llm->complete($prompt, $systemPrompt, $options);
    }

    /**
     * Stream a chat response (for real-time UI)
     *
     * @param array $messages Array of messages
     * @param callable $callback Function to call with each chunk
     * @param array $options Optional parameters
     * @return void
     */
    public function stream(array $messages, callable $callback, array $options = []): void
    {
        $this->llm->stream($messages, $callback, $options);
    }

    /**
     * Get the LLM model being used
     *
     * @return string Model identifier
     */
    public function getLlmModel(): string
    {
        return $this->llm->getModel();
    }

    /**
     * Get the LLM provider name
     *
     * @return string Provider name (e.g., 'openai', 'anthropic')
     */
    public function getLlmProvider(): string
    {
        return $this->llm->getProvider();
    }

    /**
     * Get the maximum context length (tokens)
     *
     * @return int Maximum tokens
     */
    public function getLlmMaxTokens(): int
    {
        return $this->llm->getMaxTokens();
    }

    /**
     * Count tokens in a text (approximate)
     *
     * @param string $text Text to count
     * @return int Estimated token count
     */
    public function countTokens(string $text): int
    {
        return $this->llm->countTokens($text);
    }

    // =========================================================================
    // Query Generation Methods
    // =========================================================================

    /**
     * Generate a Cypher query from natural language question
     *
     * @param string $question Natural language question
     * @param array $context RAG context (use retrieveContext() to get this)
     * @param array $options Optional parameters (temperature, max_retries, allow_write, explain)
     * @return array Result with keys: cypher, explanation, confidence, warnings, metadata
     * @throws \RuntimeException If generation fails after retries
     */
    public function generateQuery(string $question, array $context = [], array $options = []): array
    {
        // If no context provided, retrieve it
        if (empty($context)) {
            $context = $this->retrieveContext($question);
        }

        return $this->queryGenerator->generate($question, $context, $options);
    }

    /**
     * Validate a Cypher query for syntax and safety
     *
     * @param string $cypherQuery Query to validate
     * @param array $options Validation options (allow_write, max_complexity)
     * @return array Validation result with keys: valid, errors, warnings, complexity, is_read_only
     * @throws \InvalidArgumentException If query is empty
     */
    public function validateQuery(string $cypherQuery, array $options = []): array
    {
        return $this->queryGenerator->validate($cypherQuery, $options);
    }

    /**
     * Sanitize a Cypher query by removing dangerous operations
     *
     * @param string $cypherQuery Query to sanitize
     * @return string Sanitized query
     */
    public function sanitizeQuery(string $cypherQuery): string
    {
        return $this->queryGenerator->sanitize($cypherQuery);
    }

    /**
     * Get available query templates
     *
     * @return array Array of template metadata
     */
    public function getQueryTemplates(): array
    {
        return $this->queryGenerator->getTemplates();
    }

    /**
     * Detect which template (if any) matches the question
     *
     * @param string $question Natural language question
     * @return string|null Template name or null if no match
     */
    public function detectQueryTemplate(string $question): ?string
    {
        return $this->queryGenerator->detectTemplate($question);
    }

    /**
     * Full pipeline: Question → Context → Query (convenience method)
     *
     * @param string $question Natural language question
     * @param array $options Generation options
     * @return array Result with keys: cypher, explanation, confidence, warnings, metadata, context
     */
    public function askQuestion(string $question, array $options = []): array
    {
        // Step 1: Retrieve context
        $context = $this->retrieveContext($question);

        // Step 2: Generate query
        $result = $this->generateQuery($question, $context, $options);

        // Add context to result for transparency
        $result['context'] = $context;

        return $result;
    }

    // =========================================================================
    // Query Execution Methods
    // =========================================================================

    /**
     * Execute a Cypher query
     *
     * @param string $cypherQuery Cypher query to execute
     * @param array $parameters Query parameters
     * @param array $options Execution options (timeout, limit, read_only, format, include_stats)
     * @return array Execution result with keys: success, data, stats, metadata, errors
     * @throws \RuntimeException If query execution fails
     */
    public function executeQuery(string $cypherQuery, array $parameters = [], array $options = []): array
    {
        return $this->queryExecutor->execute($cypherQuery, $parameters, $options);
    }

    /**
     * Execute query and return count only
     *
     * @param string $cypherQuery Query to execute
     * @param array $parameters Query parameters
     * @param array $options Execution options
     * @return int Count of results
     */
    public function executeCount(string $cypherQuery, array $parameters = [], array $options = []): int
    {
        return $this->queryExecutor->executeCount($cypherQuery, $parameters, $options);
    }

    /**
     * Execute query with pagination
     *
     * @param string $cypherQuery Query to execute
     * @param int $page Page number (1-indexed)
     * @param int $perPage Results per page
     * @param array $parameters Query parameters
     * @param array $options Execution options
     * @return array Paginated results with data and pagination metadata
     */
    public function executePaginated(
        string $cypherQuery,
        int $page = 1,
        int $perPage = 20,
        array $parameters = [],
        array $options = []
    ): array {
        return $this->queryExecutor->executePaginated($cypherQuery, $page, $perPage, $parameters, $options);
    }

    /**
     * Explain a query (show execution plan)
     *
     * @param string $cypherQuery Query to explain
     * @param array $parameters Query parameters
     * @return array Execution plan details
     */
    public function explainQuery(string $cypherQuery, array $parameters = []): array
    {
        return $this->queryExecutor->explain($cypherQuery, $parameters);
    }

    /**
     * Test if a query is valid (dry run)
     *
     * @param string $cypherQuery Query to test
     * @return bool True if query is valid
     */
    public function testQuery(string $cypherQuery): bool
    {
        return $this->queryExecutor->test($cypherQuery);
    }

    /**
     * Full pipeline: Question → Context → Query → Execute (convenience method)
     *
     * @param string $question Natural language question
     * @param array $options Options for generation and execution
     * @return array Result with query, execution results, and metadata
     */
    public function ask(string $question, array $options = []): array
    {
        // Step 1: Retrieve context
        $context = $this->retrieveContext($question);

        // Step 2: Generate query
        $queryResult = $this->generateQuery($question, $context, $options);

        // Step 3: Execute query
        $executionResult = $this->executeQuery($queryResult['cypher'], [], $options);

        return [
            'question' => $question,
            'cypher' => $queryResult['cypher'],
            'explanation' => $queryResult['explanation'],
            'data' => $executionResult['data'],
            'stats' => $executionResult['stats'],
            'context' => $context,
            'metadata' => array_merge($queryResult['metadata'], $executionResult['metadata']),
        ];
    }

    // =========================================================================
    // Response Generation Methods
    // =========================================================================

    /**
     * Generate natural language response from query results
     *
     * @param string $originalQuestion User's original question
     * @param array $queryResult Results from QueryExecutor
     * @param string $cypherQuery The Cypher query that was executed
     * @param array $options Generation options
     * @return array Response with answer, insights, visualizations
     */
    public function generateResponse(
        string $originalQuestion,
        array $queryResult,
        string $cypherQuery,
        array $options = []
    ): array {
        return $this->responseGenerator->generate($originalQuestion, $queryResult, $cypherQuery, $options);
    }

    /**
     * Extract insights from query results
     *
     * @param array $queryResult Query results to analyze
     * @return array Array of insights
     */
    public function extractInsights(array $queryResult): array
    {
        return $this->responseGenerator->extractInsights($queryResult);
    }

    /**
     * Suggest visualizations for query results
     *
     * @param array $queryResult Query results
     * @param string $cypherQuery Original query
     * @return array Suggested visualization types
     */
    public function suggestVisualizations(array $queryResult, string $cypherQuery): array
    {
        return $this->responseGenerator->suggestVisualizations($queryResult, $cypherQuery);
    }

    /**
     * Complete pipeline: Question → Context → Query → Execute → Respond
     *
     * @param string $question Natural language question
     * @param array $options Options for all stages
     * @return array Complete response with natural language answer
     */
    public function answerQuestion(string $question, array $options = []): array
    {
        try {
            // Step 1: Retrieve context
            $context = $this->retrieveContext($question);

            // Step 2: Generate query
            $queryResult = $this->generateQuery($question, $context, $options);

            // Step 3: Execute query
            $executionResult = $this->executeQuery($queryResult['cypher'], [], $options);

            // Step 4: Generate natural language response
            $responseResult = $this->generateResponse(
                $question,
                $executionResult,
                $queryResult['cypher'],
                $options
            );

            return [
                'question' => $question,
                'answer' => $responseResult['answer'],
                'insights' => $responseResult['insights'],
                'visualizations' => $responseResult['visualizations'],
                'cypher' => $queryResult['cypher'],
                'data' => $executionResult['data'],
                'stats' => $executionResult['stats'],
                'metadata' => [
                    'query' => $queryResult['metadata'],
                    'execution' => $executionResult['metadata'],
                    'response' => $responseResult['metadata'],
                ],
            ];

        } catch (\Throwable $e) {
            // Generate error response
            $errorResponse = $this->responseGenerator->generateErrorResponse($question, $e, $options);

            return [
                'question' => $question,
                'answer' => $errorResponse['answer'],
                'insights' => $errorResponse['insights'],
                'visualizations' => $errorResponse['visualizations'],
                'cypher' => null,
                'data' => [],
                'stats' => [],
                'metadata' => $errorResponse['metadata'],
            ];
        }
    }
}
