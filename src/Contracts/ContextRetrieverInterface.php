<?php

declare(strict_types=1);

namespace AiSystem\Contracts;

/**
 * Context Retriever Interface
 *
 * Defines contract for retrieving context to support query generation
 * in Retrieval-Augmented Generation (RAG) systems.
 *
 * This service combines multiple context sources:
 * - Similar past questions from vector search
 * - Graph database schema information
 * - Example entities for concrete context
 *
 * Use Case:
 * User: "Show teams with most active members"
 * → Retrieve similar questions from vector store
 * → Get graph schema (labels, relationships, properties)
 * → Get example entities (sample Team, Person records)
 * → Combine into unified context for LLM
 * → LLM generates accurate Cypher query
 *
 * @package AiSystem\Contracts
 */
interface ContextRetrieverInterface
{
    /**
     * Retrieve comprehensive context for a natural language question
     *
     * Aggregates context from multiple sources:
     * 1. Vector search for semantically similar past questions/queries
     * 2. Graph database schema (node labels, relationships, properties)
     * 3. Example entities to provide concrete data samples
     *
     * The retrieved context enables LLMs to generate accurate queries
     * by understanding:
     * - How similar questions were previously answered
     * - What data structures exist in the graph
     * - What actual data looks like
     *
     * @param string $question Natural language question from user
     * @param array $options Configuration options:
     *                       - collection: Vector collection name (default: 'questions')
     *                       - limit: Max similar questions to retrieve (default: 5)
     *                       - includeSchema: Include graph schema (default: true)
     *                       - includeExamples: Include sample entities (default: true)
     *
     * @return array Context structure:
     *               - similar_queries: Array of similar Q&A pairs with scores
     *               - graph_schema: Graph structure (labels, relationships, properties)
     *               - relevant_entities: Sample entities by label
     *               - errors: Array of non-fatal error messages (if any)
     *
     * @example
     * $context = $retriever->retrieveContext("Show active teams", [
     *     'collection' => 'customer_questions',
     *     'limit' => 10,
     *     'includeSchema' => true,
     *     'includeExamples' => true
     * ]);
     *
     * // Returns:
     * [
     *     'similar_queries' => [
     *         ['question' => '...', 'query' => '...', 'score' => 0.89],
     *     ],
     *     'graph_schema' => [
     *         'labels' => ['Team', 'Person'],
     *         'relationships' => ['MEMBER_OF'],
     *         'properties' => ['id', 'name'],
     *     ],
     *     'relevant_entities' => [
     *         'Team' => [['id' => 1, 'name' => 'Alpha']],
     *     ],
     *     'errors' => []
     * ]
     */
    public function retrieveContext(string $question, array $options = []): array;

    /**
     * Search for semantically similar questions in vector store
     *
     * Converts the input question to an embedding and performs
     * similarity search to find previously answered questions.
     *
     * This enables "few-shot learning" where the LLM sees examples
     * of how similar questions were answered before.
     *
     * @param string $question Question to search for
     * @param string $collection Vector collection to search (default: 'questions')
     * @param int $limit Maximum number of results to return (default: 5)
     *
     * @return array Similar questions with structure:
     *               [
     *                   [
     *                       'question' => 'Original question text',
     *                       'query' => 'Associated Cypher query',
     *                       'score' => 0.85, // Similarity score (0-1)
     *                       'metadata' => [...] // Additional payload data
     *                   ],
     *                   ...
     *               ]
     *
     * @throws \RuntimeException If vector search fails critically
     *
     * @example
     * $similar = $retriever->searchSimilar(
     *     "Find all customers",
     *     'questions',
     *     10
     * );
     */
    public function searchSimilar(
        string $question,
        string $collection = 'questions',
        int $limit = 5
    ): array;

    /**
     * Get graph database schema information
     *
     * Retrieves structural information about the graph database:
     * - Node labels (entity types)
     * - Relationship types (connections between entities)
     * - Property keys (attributes on nodes/relationships)
     *
     * This schema information helps LLMs understand what data
     * structures are available for querying.
     *
     * @return array Schema structure:
     *               [
     *                   'labels' => ['Team', 'Person', 'Customer'],
     *                   'relationships' => ['MEMBER_OF', 'PURCHASED'],
     *                   'properties' => ['id', 'name', 'email', 'created_at']
     *               ]
     *
     * @throws \RuntimeException If schema retrieval fails
     *
     * @example
     * $schema = $retriever->getGraphSchema();
     * // Use to inform LLM about available node types and relationships
     */
    public function getGraphSchema(): array;

    /**
     * Get example entities from graph to provide concrete context
     *
     * Retrieves sample entities of a specific label to show
     * the LLM what actual data looks like. This helps generate
     * more accurate queries by understanding:
     * - What properties entities actually have
     * - What values look like (strings, numbers, dates, etc.)
     * - Data structure patterns
     *
     * @param string $label Entity label/type to get examples for
     * @param int $limit Maximum number of examples (default: 3)
     *
     * @return array Sample entities:
     *               [
     *                   ['id' => 1, 'name' => 'Alpha Team', 'created_at' => '2024-01-15'],
     *                   ['id' => 2, 'name' => 'Beta Team', 'created_at' => '2024-02-20'],
     *               ]
     *
     * @throws \RuntimeException If entity retrieval fails
     * @throws \InvalidArgumentException If label is empty or invalid
     *
     * @example
     * $examples = $retriever->getExampleEntities('Customer', 5);
     * // Use to show LLM actual customer data structure
     */
    public function getExampleEntities(string $label, int $limit = 3): array;
}
