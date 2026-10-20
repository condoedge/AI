<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * Context Retriever Interface
 *
 * Defines contract for retrieving context to support query generation
 * in Retrieval-Augmented Generation (RAG) systems.
 *
 * This service combines multiple context sources:
 * - Similar past questions from vector search
 * - Graph database schema information
 *
 * Use Case:
 * User: "Show teams with most active members"
 * → Retrieve similar questions from vector store
 * → Get graph schema (labels, relationships, properties)
 * → Combine into unified context for LLM
 * → LLM generates accurate Cypher query
 *
 * @package Condoedge\Ai\Contracts
 */
interface ContextRetrieverInterface
{
    /**
     * Retrieve comprehensive context for a natural language question
     *
     * Aggregates context from multiple sources:
     * 1. Vector search for semantically similar past questions/queries
     * 2. Graph database schema (node labels, relationships, properties)
     *
     * The retrieved context enables LLMs to generate accurate queries
     * by understanding:
     * - How similar questions were previously answered
     * - What data structures exist in the graph
     *
     * @param string $question Natural language question from user
     * @param array $options Configuration options:
     *                       - collection: Vector collection name (default: 'questions')
     *                       - limit: Max similar questions to retrieve (default: 5)
     *                       - includeSchema: Include graph schema (default: true)
     *
     * @return PipelineContext Typed context object containing:
     *               - similarQueries: Array of similar Q&A pairs with scores
     *               - graphSchema: Graph structure (labels, relationships, properties)
     *               - qu: QueryUnderstandingResult from QU analysis
     *               - semanticSelection: SemanticSelectionResult from context selection
     *               - errors: Array of non-fatal error messages (if any)
     */
    public function retrieveContext(string $question, array $options = []): PipelineContext;

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
}
