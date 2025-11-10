<?php

declare(strict_types=1);

namespace AiSystem\Services;

use AiSystem\Contracts\ContextRetrieverInterface;
use AiSystem\Contracts\VectorStoreInterface;
use AiSystem\Contracts\GraphStoreInterface;
use AiSystem\Contracts\EmbeddingProviderInterface;

/**
 * ContextRetriever
 *
 * Implements Retrieval-Augmented Generation (RAG) by combining multiple
 * context sources to support natural language query generation.
 *
 * This service provides rich context for LLMs by aggregating:
 * - Similar past questions from vector search (few-shot learning)
 * - Graph database schema information (structural understanding)
 * - Example entities for concrete data context
 *
 * Architecture Principles:
 * 1. Interface-based dependency injection
 * 2. Separation of concerns (vector vs graph operations)
 * 3. Graceful degradation on partial failures
 * 4. Comprehensive error handling and status reporting
 *
 * Example Usage:
 * ```php
 * $retriever = new ContextRetriever(
 *     vectorStore: new QdrantStore($config),
 *     graphStore: new Neo4jStore($config),
 *     embeddingProvider: new OpenAiEmbeddingProvider($config)
 * );
 *
 * $context = $retriever->retrieveContext(
 *     "Show teams with most active members",
 *     ['limit' => 10, 'includeSchema' => true]
 * );
 *
 * // Returns:
 * [
 *     'similar_queries' => [
 *         ['question' => 'List all teams', 'query' => 'MATCH (t:Team)...', 'score' => 0.89],
 *     ],
 *     'graph_schema' => [
 *         'labels' => ['Team', 'Person'],
 *         'relationships' => ['MEMBER_OF'],
 *         'properties' => ['id', 'name', 'created_at']
 *     ],
 *     'relevant_entities' => [
 *         'Team' => [['id' => 1, 'name' => 'Alpha Team']],
 *         'Person' => [['id' => 1, 'name' => 'John Doe']]
 *     ]
 * ]
 * ```
 *
 * @package AiSystem\Services
 */
class ContextRetriever implements ContextRetrieverInterface
{
    /**
     * Default options for context retrieval
     */
    private const DEFAULT_COLLECTION = 'questions';
    private const DEFAULT_LIMIT = 5;
    private const DEFAULT_EXAMPLES_PER_LABEL = 2;
    private const DEFAULT_SCORE_THRESHOLD = 0.0;

    /**
     * Create context retriever with injected dependencies
     *
     * All dependencies are interfaces to ensure:
     * - Service can work with any vector store implementation (Qdrant, Pinecone, etc.)
     * - Service can work with any graph store implementation (Neo4j, ArangoDB, etc.)
     * - Service can work with any embedding provider (OpenAI, Anthropic, etc.)
     * - Service is fully testable with mocks/stubs
     *
     * @param VectorStoreInterface $vectorStore Vector database for similarity search
     * @param GraphStoreInterface $graphStore Graph database for schema/entity queries
     * @param EmbeddingProviderInterface $embeddingProvider Text-to-vector embedding service
     */
    public function __construct(
        private readonly VectorStoreInterface $vectorStore,
        private readonly GraphStoreInterface $graphStore,
        private readonly EmbeddingProviderInterface $embeddingProvider
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * Retrieves comprehensive context by aggregating multiple sources:
     * 1. Vector similarity search for related past queries
     * 2. Graph schema information (labels, relationships, properties)
     * 3. Example entities to provide concrete data context
     *
     * The service implements graceful degradation: if one source fails,
     * others still provide partial context. Errors are collected but
     * non-fatal, allowing the caller to decide how to proceed.
     *
     * @param string $question Natural language question from user
     * @param array $options Configuration options:
     *                       - collection: Vector collection name (default: 'questions')
     *                       - limit: Max similar queries to retrieve (default: 5)
     *                       - includeSchema: Include graph schema (default: true)
     *                       - includeExamples: Include sample entities (default: true)
     *                       - examplesPerLabel: Number of examples per label (default: 2)
     *                       - scoreThreshold: Minimum similarity score (default: 0.0)
     *
     * @return array Context structure with keys:
     *               - similar_queries: Array of similar Q&A pairs with scores
     *               - graph_schema: Graph structure (labels, relationships, properties)
     *               - relevant_entities: Sample entities grouped by label
     *               - errors: Array of non-fatal error messages (empty if no errors)
     */
    public function retrieveContext(string $question, array $options = []): array
    {
        // Validate input
        if (empty(trim($question))) {
            throw new \InvalidArgumentException('Question cannot be empty');
        }

        // Extract options with defaults
        $collection = $options['collection'] ?? self::DEFAULT_COLLECTION;
        $limit = $options['limit'] ?? self::DEFAULT_LIMIT;
        $includeSchema = $options['includeSchema'] ?? true;
        $includeExamples = $options['includeExamples'] ?? true;
        $examplesPerLabel = $options['examplesPerLabel'] ?? self::DEFAULT_EXAMPLES_PER_LABEL;
        $scoreThreshold = $options['scoreThreshold'] ?? self::DEFAULT_SCORE_THRESHOLD;

        // Initialize context structure
        $context = [
            'similar_queries' => [],
            'graph_schema' => [],
            'relevant_entities' => [],
            'errors' => [],
        ];

        // 1. Search for similar queries (graceful degradation on failure)
        try {
            $context['similar_queries'] = $this->searchSimilarQueries(
                $question,
                $collection,
                $limit,
                $scoreThreshold
            );
        } catch (\Exception $e) {
            $context['errors'][] = 'Vector search failed: ' . $e->getMessage();
        }

        // 2. Get graph schema (if requested)
        if ($includeSchema) {
            try {
                $context['graph_schema'] = $this->getGraphSchema();
            } catch (\Exception $e) {
                $context['errors'][] = 'Schema retrieval failed: ' . $e->getMessage();
            }
        }

        // 3. Get example entities (if requested and schema available)
        if ($includeExamples && !empty($context['graph_schema']['labels'])) {
            $context['relevant_entities'] = $this->retrieveExampleEntities(
                $context['graph_schema']['labels'],
                $examplesPerLabel,
                $context['errors']
            );
        }

        return $context;
    }

    /**
     * {@inheritDoc}
     *
     * Performs semantic similarity search to find previously answered
     * questions similar to the input question. This enables few-shot
     * learning where the LLM can see examples of how similar questions
     * were answered.
     *
     * Process:
     * 1. Convert question text to embedding vector
     * 2. Search vector store for similar embeddings
     * 3. Format results with question, query, score, metadata
     *
     * Results are sorted by similarity score (highest first).
     *
     * @param string $question Question to search for
     * @param string $collection Vector collection to search (default: 'questions')
     * @param int $limit Maximum number of results (default: 5)
     *
     * @return array Similar questions with structure:
     *               [
     *                   [
     *                       'question' => 'Original question text',
     *                       'query' => 'Associated Cypher query',
     *                       'score' => 0.89, // Similarity score (0-1)
     *                       'metadata' => [...] // Additional payload data
     *                   ],
     *                   ...
     *               ]
     *
     * @throws \RuntimeException If embedding generation fails
     * @throws \RuntimeException If vector search fails
     */
    public function searchSimilar(
        string $question,
        string $collection = 'questions',
        int $limit = 5
    ): array {
        return $this->searchSimilarQueries($question, $collection, $limit, 0.0);
    }

    /**
     * {@inheritDoc}
     *
     * Retrieves graph database schema information including:
     * - Node labels (entity types like Team, Person, Customer)
     * - Relationship types (connections like MEMBER_OF, PURCHASED)
     * - Property keys (attributes like id, name, email)
     *
     * This structural information helps LLMs understand what data
     * structures are available when generating queries.
     *
     * @return array Schema structure with keys:
     *               - labels: Array of node label strings
     *               - relationships: Array of relationship type strings
     *               - properties: Array of property key strings
     *
     * @throws \RuntimeException If schema retrieval fails
     */
    public function getGraphSchema(): array
    {
        $schema = $this->graphStore->getSchema();

        // Normalize schema structure to consistent format
        return [
            'labels' => $schema['labels'] ?? [],
            'relationships' => $schema['relationshipTypes'] ?? $schema['relationships'] ?? [],
            'properties' => $schema['propertyKeys'] ?? $schema['properties'] ?? [],
        ];
    }

    /**
     * {@inheritDoc}
     *
     * Retrieves sample entities of a specific label to show the LLM
     * what actual data looks like. This provides concrete context about:
     * - What properties entities actually have
     * - What values look like (strings, numbers, dates, etc.)
     * - Data structure and format patterns
     *
     * Uses Cypher MATCH query to retrieve random sample entities.
     *
     * @param string $label Entity label/type to get examples for
     * @param int $limit Maximum number of examples (default: 3)
     *
     * @return array Sample entities as associative arrays:
     *               [
     *                   ['id' => 1, 'name' => 'Alpha Team', 'created_at' => '2024-01-15'],
     *                   ['id' => 2, 'name' => 'Beta Team', 'created_at' => '2024-02-20'],
     *               ]
     *
     * @throws \InvalidArgumentException If label is empty or invalid
     * @throws \RuntimeException If query execution fails
     */
    public function getExampleEntities(string $label, int $limit = 3): array
    {
        // Validate label
        if (empty($label)) {
            throw new \InvalidArgumentException('Label cannot be empty');
        }

        // Validate label name (prevent Cypher injection)
        if (!$this->isValidLabel($label)) {
            throw new \InvalidArgumentException(
                'Invalid label name: must contain only alphanumeric characters and underscores'
            );
        }

        // Build Cypher query to retrieve sample entities
        // Using backtick quoting for label name safety
        $cypher = "MATCH (n:`{$label}`) RETURN n LIMIT \$limit";

        try {
            $results = $this->graphStore->query($cypher, ['limit' => $limit]);

            // Extract node properties from query results
            return array_map(function ($row) {
                return $row['n'] ?? [];
            }, $results);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to retrieve example entities for label '{$label}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Search for similar queries with score threshold filtering
     *
     * Internal method that adds score threshold support to similarity search.
     * This allows filtering out low-quality matches.
     *
     * @param string $question Question to search for
     * @param string $collection Vector collection to search
     * @param int $limit Maximum results to return
     * @param float $scoreThreshold Minimum similarity score (0.0 - 1.0)
     *
     * @return array Similar questions with scores above threshold
     *
     * @throws \RuntimeException If embedding generation fails
     * @throws \RuntimeException If vector search fails
     */
    private function searchSimilarQueries(
        string $question,
        string $collection,
        int $limit,
        float $scoreThreshold
    ): array {
        // Generate embedding for the input question
        try {
            $embedding = $this->embeddingProvider->embed($question);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Failed to generate embedding for question: ' . $e->getMessage(),
                0,
                $e
            );
        }

        // Validate embedding
        if (empty($embedding)) {
            throw new \RuntimeException('Embedding provider returned empty vector');
        }

        // Search vector store for similar embeddings
        try {
            $results = $this->vectorStore->search(
                $collection,
                $embedding,
                $limit,
                [],
                $scoreThreshold
            );
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Vector store search failed: ' . $e->getMessage(),
                0,
                $e
            );
        }

        // Format results for consistent structure
        return array_map(function ($result) {
            return [
                'question' => $result['payload']['question'] ?? '',
                'query' => $result['payload']['cypher_query'] ?? $result['payload']['query'] ?? '',
                'score' => $result['score'] ?? 0.0,
                'metadata' => $result['payload'] ?? [],
            ];
        }, $results);
    }

    /**
     * Retrieve example entities for multiple labels
     *
     * Iterates through labels and retrieves sample entities for each.
     * Implements graceful degradation: individual label failures don't
     * stop retrieval for other labels.
     *
     * @param array $labels Array of node labels to retrieve examples for
     * @param int $limit Number of examples per label
     * @param array &$errors Reference to errors array for collecting failures
     *
     * @return array Example entities grouped by label:
     *               [
     *                   'Team' => [['id' => 1, 'name' => 'Alpha']],
     *                   'Person' => [['id' => 1, 'name' => 'John']]
     *               ]
     */
    private function retrieveExampleEntities(
        array $labels,
        int $limit,
        array &$errors
    ): array {
        $entities = [];

        foreach ($labels as $label) {
            try {
                $examples = $this->getExampleEntities($label, $limit);

                // Only include if examples were found
                if (!empty($examples)) {
                    $entities[$label] = $examples;
                }
            } catch (\Exception $e) {
                // Continue on error - don't fail entire context retrieval
                // Individual label failures are acceptable
                $errors[] = "Example retrieval for label '{$label}' failed: " . $e->getMessage();
            }
        }

        return $entities;
    }

    /**
     * Validate label name to prevent Cypher injection
     *
     * Label names should only contain alphanumeric characters and underscores.
     * This prevents malicious input from executing arbitrary Cypher code.
     *
     * @param string $label Label name to validate
     *
     * @return bool True if valid, false otherwise
     */
    private function isValidLabel(string $label): bool
    {
        // Allow alphanumeric characters, underscores, and hyphens
        // Start with letter or underscore
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $label) === 1;
    }
}
