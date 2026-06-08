<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Response\ResponseFileEnricher;
use Condoedge\Ai\Services\Response\ResponseEntityEnricher;

/**
 * AI Manager - Convenient wrapper around AI services
 *
 * This class provides a convenient API while properly using dependency injection
 * and Laravel's service container. All dependencies are injected via constructor,
 * making this class fully testable and following Laravel best practices.
 *
 * **Usage via Facade:**
 * ```php
 * use Condoedge\Ai\Facades\AI;
 *
 * AI::ingest($customer);
 * $context = AI::retrieveContext("Show all teams");
 * $response = AI::chat("What is 2+2?");
 * ```
 *
 * **Usage via Dependency Injection:**
 * ```php
 * use Condoedge\Ai\Services\AiManager;
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
 * @package Condoedge\Ai\Services
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
     * @param VectorStoreInterface $vectorStore Vector store for query storage
     * @param FileContextProvider|null $fileContextProvider File context provider for file-based RAG
     * @param ResponseFileEnricher|null $responseFileEnricher Response enricher for file references
     * @param ResponseEntityEnricher|null $responseEntityEnricher Response enricher for entity actions
     */
    public function __construct(
        private readonly DataIngestionServiceInterface $ingestion,
        private readonly ContextRetrieverInterface $context,
        private readonly EmbeddingProviderInterface $embedding,
        private readonly LlmProviderInterface $llm,
        private readonly QueryGeneratorInterface $queryGenerator,
        private readonly QueryExecutorInterface $queryExecutor,
        private readonly ResponseGeneratorInterface $responseGenerator,
        private readonly VectorStoreInterface $vectorStore,
        private readonly ?FileContextProvider $fileContextProvider = null,
        private readonly ?ResponseFileEnricher $responseFileEnricher = null,
        private readonly ?ResponseEntityEnricher $responseEntityEnricher = null
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
     * @return PipelineContext Typed context object containing all pipeline data
     */
    public function retrieveContext(string $question, array $options = []): PipelineContext
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

    /**
     * Store a question-query pair in the vector store for future RAG retrieval
     *
     * This method stores a question and its corresponding Cypher query in the
     * vector database, enabling the system to learn from past queries and provide
     * better context for similar future questions.
     *
     * The question is embedded and stored with its query in the 'questions' collection.
     * This builds up a knowledge base of question-query pairs that can be used for
     * few-shot learning in future query generation.
     *
     * @param string $question Natural language question
     * @param string $cypherQuery The Cypher query that answers the question
     * @param array $metadata Optional metadata to store with the query (e.g., user_id, timestamp, confidence)
     * @param string $collection Collection name (default: 'questions')
     * @return array Result with keys: success, point_id, errors
     *
     * @throws \RuntimeException If embedding generation fails
     * @throws \RuntimeException If vector store operation fails
     */
    public function storeQuery(
        string $question,
        ?string $cypherQuery,
        array $metadata = [],
        string $collection = 'questions'
    ): array {
        try {
            // Ensure collection exists
            if (!$this->vectorStore->collectionExists($collection)) {
                $vectorSize = $this->embedding->getDimensions();
                $this->vectorStore->createCollection($collection, $vectorSize, 'cosine');
            }

            // Generate embedding for the question
            $embedding = $this->embedding->embed($question);

            // Create unique ID for this question-query pair
            $pointId = md5($question . $cypherQuery);

            // Prepare payload
            $payload = array_merge([
                'question' => $question,
                'cypher_query' => $cypherQuery,
                'created_at' => now()->toIso8601String(),
            ], $metadata);

            // Store in vector database
            $success = $this->vectorStore->upsert($collection, [
                [
                    'id' => $pointId,
                    'vector' => $embedding,
                    'payload' => $payload,
                ],
            ]);

            return [
                'success' => $success,
                'point_id' => $pointId,
                'errors' => [],
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'point_id' => null,
                'errors' => [$e->getMessage()],
            ];
        }
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
     * @param PipelineContext|null $context Pipeline context (use retrieveContext() to get this)
     * @param array $options Optional parameters (temperature, max_retries, allow_write, explain)
     * @return array Result with keys: cypher, explanation, confidence, warnings, metadata
     */
    public function generateQuery(string $question, ?PipelineContext $context = null, array $options = []): array
    {
        try {
            // If no context provided, retrieve it
            if ($context === null) {
                $context = $this->context->retrieveContext($question, $options);
            }

            $generation = $this->queryGenerator->generate($question, $context, $options);

            if (isset($generation['cypher']) && $generation['cypher']) {
                // Parameterize user-specific values before storing as reusable examples.
                // This prevents user A's query from leaking data to user B.
                $storedCypher = $this->parameterizeCypher($generation['cypher']);

                $this->storeQuery(
                    question: $question,
                    cypherQuery: $storedCypher,
                    metadata: [
                        'confidence' => $generation['confidence'],
                        'generated_at' => now()->toIso8601String(),
                    ]
                );
            }

            return $generation;

        } catch (\Throwable $e) {
            \Log::error('Error generating query: ' . $e->getMessage(), [
                'question' => $question,
                'exception' => get_class($e),
            ]);

            return [
                'cypher' => null,
                'explanation' => null,
                'confidence' => 0,
                'warnings' => [$e->getMessage()],
                'metadata' => ['error' => true, 'error_message' => $e->getMessage()],
            ];
        }
    }

    /**
     * Validate a Cypher query for syntax and safety
     *
     * @param string $cypherQuery Query to validate
     * @param array $options Validation options (allow_write, max_complexity)
     * @return array Validation result with keys: valid, errors, warnings, complexity, is_read_only
     * @throws \InvalidArgumentException If query is empty
     */
    public function validateQuery(?string $cypherQuery, array $options = []): array
    {
        return $this->queryGenerator->validate($cypherQuery, $options);
    }

    /**
     * Sanitize a Cypher query by removing dangerous operations
     *
     * @param string $cypherQuery Query to sanitize
     * @return string Sanitized query
     */
    public function sanitizeQuery(?string $cypherQuery): string
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
        try {
            // Step 1: Retrieve context (returns PipelineContext)
            $ctx = $this->retrieveContext($question);

            // Step 2: Generate query
            $result = $this->generateQuery($question, $ctx, $options);

            // Add context to result for transparency
            $result['context'] = $ctx;

            return $result;

        } catch (\Throwable $e) {
            \Log::error('Error in askQuestion: ' . $e->getMessage(), [
                'question' => $question,
                'exception' => get_class($e),
            ]);

            return [
                'cypher' => null,
                'explanation' => null,
                'confidence' => 0,
                'warnings' => [__('ai.error.encountered_issue', ['question' => $question])],
                'metadata' => ['error' => true, 'error_message' => $e->getMessage()],
                'context' => new PipelineContext(),
            ];
        }
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
    public function executeQuery(?string $cypherQuery, array $parameters = [], array $options = []): array
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
    public function executeCount(?string $cypherQuery, array $parameters = [], array $options = []): int
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
        ?string $cypherQuery,
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
    public function explainQuery(?string $cypherQuery, array $parameters = []): array
    {
        return $this->queryExecutor->explain($cypherQuery, $parameters);
    }

    /**
     * Test if a query is valid (dry run)
     *
     * @param string $cypherQuery Query to test
     * @return bool True if query is valid
     */
    public function testQuery(?string $cypherQuery): bool
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
        try {
            // Step 1: Retrieve context (returns PipelineContext)
            $ctx = $this->retrieveContext($question);

            // Step 2: Generate query
            $queryResult = $this->generateQuery($question, $ctx, $options);

            // Step 3: Execute query
            $executionResult = $this->executeQuery($queryResult['cypher'], [], $options);

            return [
                'question' => $question,
                'cypher' => $queryResult['cypher'],
                'explanation' => $queryResult['explanation'],
                'data' => $executionResult['data'],
                'stats' => $executionResult['stats'],
                'context' => $ctx,
                'metadata' => array_merge($queryResult['metadata'], $executionResult['metadata']),
            ];

        } catch (\Throwable $e) {
            \Log::error('Error in ask: ' . $e->getMessage(), [
                'question' => $question,
                'exception' => get_class($e),
            ]);

            return [
                'question' => $question,
                'cypher' => null,
                'explanation' => null,
                'data' => [],
                'stats' => [],
                'context' => new PipelineContext(),
                'metadata' => ['error' => true, 'error_message' => $e->getMessage()],
            ];
        }
    }

    // =========================================================================
    // Response Generation Methods
    // =========================================================================

    /**
     * Generate natural language response from pipeline context
     *
     * @param PipelineContext $context Pipeline context with question, query result, execution data
     * @param array $options Generation options
     * @return array Response with answer, insights, visualizations
     */
    public function generateResponse(
        PipelineContext $context,
        array $options = []
    ): array {
        return $this->responseGenerator->generate($context, $options);
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
    public function suggestVisualizations(array $queryResult, ?string $cypherQuery): array
    {
        return $this->responseGenerator->suggestVisualizations($queryResult, $cypherQuery);
    }

    /**
     * Complete pipeline: Question → Context → Query → Execute → Respond
     *
     * Steps:
     * 1. Retrieve context (returns PipelineContext DTO with QU + semantic selection)
     * 2. Merge conversation context if provided
     * 3. Retrieve file context using clarified question for better matching
     * 4. Generate and execute Cypher query (or skip for knowledge-only questions)
     * 5. Build and return final response
     *
     * @param string $question Natural language question
     * @param array $options Options for all stages
     * @return array Complete response with natural language answer
     */
    public function answerQuestion(string $question, array $options = []): array
    {
        try {
            // Step 1: Retrieve context (returns PipelineContext DTO)
            $ctx = $this->context->retrieveContext($question, $options);

            // Step 2: Merge conversation context if provided
            if (!empty($options['conversation_context'])) {
                $ctx->conversationContext = $options['conversation_context'];
            }

            // Step 3: Retrieve file context using clarified question for better matching
            $ctx = $this->retrieveAndSetFileContext($ctx, $question, $options);

            // Step 4: Generate and execute query (or skip for knowledge-only questions)
            $ctx = $this->generateAndExecute($ctx, $question, $options);

            // Step 5: Build and return response
            return $this->buildFinalResponse($question, $ctx, $options);

        } catch (\Throwable $e) {
            \Log::error('Error answering question: ' . $e->getMessage());
            $errorResponse = $this->responseGenerator->generateErrorResponse($question, $e, $options);

            return [
                'question' => $question,
                'answer' => $errorResponse['answer'],
                'insights' => $errorResponse['insights'],
                'visualizations' => $errorResponse['visualizations'],
                'cypher' => null,
                'data' => [],
                'stats' => [],
                'referenced_files' => [],
                'metadata' => $errorResponse['metadata'],
            ];
        }
    }

    /**
     * Retrieve file context using clarified question for better semantic matching.
     */
    private function retrieveAndSetFileContext(PipelineContext $ctx, string $question, array $options): PipelineContext
    {
        if (!config('ai.file_context.enabled', true)) {
            return $ctx;
        }

        $user = $options['user'] ?? null;

        // Build search queries from QU output — multiple angles for better recall
        $queries = array_filter(array_unique([
            $ctx->qu->searchQuery,
            $question,
            $ctx->qu->clarifiedQuestion,
        ]));

        if (!empty($ctx->qu->searchTags)) {
            $queries[] = implode(' ', $ctx->qu->searchTags);
        }

        // Try all queries and merge results (different phrasings find different files)
        $allFiles = [];
        foreach ($queries as $searchQuery) {
            $fileContext = $this->retrieveFileContext($searchQuery, $user);
            foreach ($fileContext['relevant_files'] ?? [] as $file) {
                $fileId = $file['file_id'] ?? $file['filename'] ?? '';
                if (!isset($allFiles[$fileId]) || ($file['relevance'] ?? 0) > ($allFiles[$fileId]['relevance'] ?? 0)) {
                    $allFiles[$fileId] = $file;
                }
            }
        }

        if (!empty($allFiles)) {
            // Sort by relevance descending
            $files = array_values($allFiles);
            usort($files, fn($a, $b) => ($b['priority_score'] ?? $b['relevance'] ?? 0) <=> ($a['priority_score'] ?? $a['relevance'] ?? 0));

            $ctx->fileContext = [
                'relevant_files' => $files,
                'file_count' => count($files),
                'has_physical' => collect($files)->contains('source', 'physical'),
                'has_database' => collect($files)->contains(fn($f) => ($f['source'] ?? '') !== 'physical'),
            ];
        }

        return $ctx;
    }

    /**
     * Generate Cypher query and execute it, or skip for knowledge-only questions.
     */
    private function generateAndExecute(PipelineContext $ctx, string $question, array $options): PipelineContext
    {
        $hasFileContext = !empty($ctx->fileContext['relevant_files'] ?? $ctx->fileContext);

        // Skip Cypher for knowledge queries — no graph data answers "can I do X?"
        if ($ctx->qu->questionType === 'knowledge_query') {
            $ctx->queryResult = new QueryGenerationResult(
                explanation: 'Knowledge query — answered from documents.',
                confidence: 1.0,
                metadata: ['source' => 'file_context', 'question_type' => 'knowledge_query'],
            );
            $ctx->executionResult = QueryExecutionResult::empty();
            return $ctx;
        }

        // Pass the PipelineContext directly to QueryGenerator
        $queryResult = $this->generateQuery($question, $ctx, $options);

        // Thread pipeline identity/provenance into execution so the executor can
        // (a) team-filter against the real user ($options['user']) rather than
        // auth()->user(), and (b) log the real question instead of 'N/A'.
        $execOptions = $options + [
            'original_question' => $question,
            'conversation_id' => $options['conversation_id'] ?? null,
        ];

        // Execute the generated query
        $executionResult = $this->executeQuery($queryResult['cypher'], [], $execOptions);
        $executionResult = $this->enrichResultsWithEntityActions($executionResult);
        $executionResult = $this->filterSensitiveResults($executionResult, $options['user'] ?? null);

        // Store results on context as DTOs
        $ctx->queryResult = new QueryGenerationResult(
            cypher: $queryResult['cypher'] ?? null,
            explanation: $queryResult['explanation'] ?? '',
            confidence: (float) ($queryResult['confidence'] ?? 0.0),
            warnings: $queryResult['warnings'] ?? [],
            metadata: $queryResult['metadata'] ?? [],
        );
        $ctx->executionResult = new QueryExecutionResult(
            success: !empty($executionResult['data']) || empty($executionResult['errors']),
            data: $executionResult['data'] ?? [],
            stats: $executionResult['stats'] ?? [],
            metadata: $executionResult['metadata'] ?? [],
            errors: $executionResult['errors'] ?? [],
        );

        return $ctx;
    }

    /**
     * Build the final response array from the pipeline context.
     */
    private function buildFinalResponse(string $question, PipelineContext $ctx, array $options): array
    {
        $responseResult = $this->generateResponse($ctx, $options);

        // Enrich response with file references
        if (!empty($ctx->fileContext)) {
            $responseResult = $this->enrichResponseWithFiles($responseResult, $ctx->fileContext, $options);
        }

        // Apply scope clarification
        $answer = $responseResult['answer'];
        $securityFilterApplied = $ctx->executionResult->metadata['security_filter_applied'] ?? false;
        $scopeClarified = false;

        if ($securityFilterApplied && config('ai.security.clarify_scope', true)) {
            $prefix = config('ai.security.scope_prefix', 'Based on your permissions, ');
            if (!str_starts_with($answer, $prefix)) {
                $answer = $prefix . lcfirst($answer);
                $scopeClarified = true;
            }
        }

        return [
            'question' => $question,
            'answer' => $answer,
            'insights' => $responseResult['insights'],
            'visualizations' => $responseResult['visualizations'],
            'cypher' => $ctx->queryResult->cypher,
            'data' => $ctx->executionResult->data,
            'stats' => $ctx->executionResult->stats,
            'referenced_files' => $responseResult['referenced_files'] ?? [],
            'metadata' => [
                'query' => $ctx->queryResult->metadata,
                'execution' => $ctx->executionResult->metadata,
                'response' => $responseResult['metadata'],
                'scope_clarified' => $scopeClarified,
                'security_filter_applied' => $securityFilterApplied,
            ],
        ];
    }

    // =========================================================================
    // Query Parameterization (security: prevent user ID leakage in stored queries)
    // =========================================================================

    /**
     * Replace user-specific values with placeholders before storing queries.
     * Prevents user A's data from leaking to user B via similar queries.
     *
     * Uses config('ai.query_parameters') for project-specific fields.
     * Default: user_id → $currentUserId, team_id → $currentTeamId
     */
    private function parameterizeCypher(string $cypher): string
    {
        foreach ($this->getQueryParameters() as $param) {
            $value = $param['value'];
            if ($value === null) {
                continue;
            }

            $field = $param['field'];
            $placeholder = $param['placeholder'];
            $quotedValue = preg_quote((string) $value, '/');

            // Property syntax: {field: value}
            $cypher = preg_replace(
                '/\b' . preg_quote($field, '/') . '\s*:\s*' . $quotedValue . '\b/',
                $field . ': ' . $placeholder,
                $cypher
            );
            // WHERE syntax: x.field = value
            $cypher = preg_replace(
                '/(\b' . preg_quote($field, '/') . '\s*=\s*)' . $quotedValue . '\b/',
                '${1}' . $placeholder,
                $cypher
            );
        }

        return $cypher;
    }

    /**
     * Replace placeholders with actual current user values when serving similar queries.
     */
    public static function deparameterizeCypher(string $cypher): string
    {
        foreach (static::resolveQueryParameters() as $param) {
            $value = $param['value'];
            if ($value === null) {
                continue;
            }
            $cypher = str_replace($param['placeholder'], (string) $value, $cypher);
        }

        return $cypher;
    }

    /**
     * Get configurable query parameters for parameterization.
     * Projects can add custom fields via config('ai.query_parameters').
     *
     * Each entry: ['field' => 'user_id', 'placeholder' => '$currentUserId', 'resolver' => fn() => auth()->id()]
     */
    private function getQueryParameters(): array
    {
        return static::resolveQueryParameters();
    }

    private static function resolveQueryParameters(): array
    {
        // Built-in parameters
        $params = [
            ['field' => 'user_id', 'placeholder' => '$currentUserId', 'value' => auth()->id()],
            ['field' => 'team_id', 'placeholder' => '$currentTeamId', 'value' => function_exists('currentTeamId') ? currentTeamId() : null],
        ];

        // Project-specific parameters from config
        foreach (config('ai.query_parameters', []) as $custom) {
            if (!empty($custom['field']) && !empty($custom['placeholder'])) {
                $value = is_callable($custom['resolver'] ?? null) ? ($custom['resolver'])() : ($custom['value'] ?? null);
                $params[] = [
                    'field' => $custom['field'],
                    'placeholder' => $custom['placeholder'],
                    'value' => $value,
                ];
            }
        }

        return $params;
    }

    // =========================================================================
    // File Context Methods
    // =========================================================================

    /**
     * Retrieve file context for the question.
     *
     * @param string $question The question to retrieve file context for
     * @param mixed $user The user to check access for
     * @return array File context array or empty if unavailable
     */
    public function retrieveFileContext(string $question, mixed $user): array
    {
        if ($this->fileContextProvider === null) {
            return [];
        }

        try {
            return $this->fileContextProvider->getFileContext($question, $user);
        } catch (\Throwable $e) {
            \Log::warning('Failed to retrieve file context: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Enrich response with file references.
     *
     * @param array $response The response array to enrich
     * @param array $fileContext The file context containing relevant files
     * @param array $options Additional options for enrichment
     * @return array The enriched response array
     */
    protected function enrichResponseWithFiles(array $response, array $fileContext, array $options): array
    {
        if ($this->responseFileEnricher === null) {
            return $response;
        }

        return $this->responseFileEnricher->enrichResponse($response, $fileContext, $options);
    }

    /**
     * Enrich query results with entity action metadata
     *
     * Adds 'has_actions', 'available_actions', and 'entity_type' to each result
     * that has entity actions configured.
     *
     * @param array $executionResult The query execution result (contains 'data' key)
     * @return array The execution result with enriched data
     */
    protected function enrichResultsWithEntityActions(array $executionResult): array
    {
        if ($this->responseEntityEnricher === null) {
            return $executionResult;
        }

        if (empty($executionResult['data'])) {
            return $executionResult;
        }

        $executionResult['data'] = $this->responseEntityEnricher->enrichEntityResults(
            $executionResult['data']
        );

        return $executionResult;
    }

    // =========================================================================
    // Security Methods
    // =========================================================================

    /**
     * Filter sensitive data from query results based on user access level
     *
     * Note: Security filtering is now handled at the Eloquent level via HasSecurity.
     * This method is kept as a placeholder for future AiAccessGate integration
     * that will provide AI-specific permission checks.
     *
     * @param array $executionResult Query execution result
     * @param mixed $user User for access checking
     * @return array Filtered execution result
     */
    protected function filterSensitiveResults(array $executionResult, mixed $user): array
    {
        // Security is now enforced at the Eloquent fetch level via HasSecurity
        // This method is a no-op pending AiAccessGate implementation
        return $executionResult;
    }
}
