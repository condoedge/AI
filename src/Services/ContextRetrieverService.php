<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;

/**
 * Context Retriever Service
 *
 * Implements Retrieval-Augmented Generation (RAG) by combining multiple
 * context sources to support natural language to query generation.
 *
 * Architecture Principles:
 * - Modular Design: Self-contained, independent service
 * - Decoupling: Depends ONLY on interfaces, not concrete implementations
 * - Service Independence: Fully testable in isolation with mocks
 * - Graceful Degradation: Continues on partial failures
 *
 * Context Sources:
 * 1. Vector Search: Similar past questions/queries (few-shot learning)
 * 2. Graph Schema: Structure information (labels, relationships, properties)
 * 3. Example Entities: Concrete data samples for context
 *
 * Example Flow:
 * User: "Show teams with most active members"
 * → Vector: Find similar questions about teams/members
 * → Schema: Get Team, Person labels + MEMBER_OF relationship
 * → Examples: Sample Team and Person entities
 * → Context sent to LLM for accurate Cypher generation
 *
 * @package Condoedge\Ai\Services
 */
class ContextRetrieverService implements ContextRetrieverInterface
{
    /**
     * Create context retriever with injected dependencies
     *
     * All dependencies are interfaces to ensure:
     * - Service can work with any vector store implementation
     * - Service can work with any graph store implementation
     * - Service can work with any embedding provider
     * - Service is fully testable with mocks
     *
     * @param VectorStoreInterface $vectorStore Vector database for similarity search
     * @param GraphStoreInterface $graphStore Graph database for schema/entity queries
     * @param EmbeddingProviderInterface $embeddingProvider Text-to-vector conversion
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
     * Retrieves comprehensive context by aggregating:
     * 1. Similar questions from vector search (semantic similarity)
     * 2. Graph schema information (structure understanding)
     * 3. Example entities (concrete data context)
     *
     * Implements graceful degradation: if one source fails, others
     * still provide partial context. Errors are collected but non-fatal.
     *
     * @param string $question Natural language question
     * @param array $options Configuration:
     *                       - collection: Vector collection name (default: 'questions')
     *                       - limit: Max similar questions (default: 5)
     *                       - includeSchema: Include graph schema (default: true)
     *                       - includeExamples: Include sample entities (default: true)
     *
     * @return array Complete context with keys:
     *               - similar_questions: Array of similar Q&A pairs
     *               - schema: Graph structure info (if includeSchema=true)
     *               - examples: Sample entities by label (if includeExamples=true)
     *               - errors: Non-fatal error messages
     */
    public function retrieveContext(string $question, array $options = []): array
    {
        // Extract options with defaults
        $collection = $options['collection'] ?? 'questions';
        $limit = $options['limit'] ?? 5;
        $includeSchema = $options['includeSchema'] ?? true;
        $includeExamples = $options['includeExamples'] ?? true;

        $context = [];

        // 1. Search for similar questions (graceful degradation on failure)
        try {
            $context['similar_questions'] = $this->searchSimilar($question, $collection, $limit);
        } catch (\Exception $e) {
            $context['similar_questions'] = [];
            $context['errors'][] = 'Vector search failed: ' . $e->getMessage();
        }

        // 2. Get graph schema (if requested)
        if ($includeSchema) {
            try {
                $context['schema'] = $this->getGraphSchema();
            } catch (\Exception $e) {
                $context['schema'] = [];
                $context['errors'][] = 'Schema retrieval failed: ' . $e->getMessage();
            }
        }

        // 3. Get example entities (if requested and schema available)
        if ($includeExamples && isset($context['schema']['labels'])) {
            $context['examples'] = [];

            foreach ($context['schema']['labels'] as $label) {
                try {
                    // Get 2 examples per label by default
                    $examples = $this->getExampleEntities($label, 2);

                    // Only include if examples were found
                    if (!empty($examples)) {
                        $context['examples'][$label] = $examples;
                    }
                } catch (\Exception $e) {
                    // Continue on error - don't fail entire context retrieval
                    // Individual label failures are acceptable
                    $context['errors'][] = "Example retrieval for {$label} failed: " . $e->getMessage();
                }
            }
        }

        // Initialize errors array if not set (no errors occurred)
        if (!isset($context['errors'])) {
            $context['errors'] = [];
        }

        return $context;
    }

    /**
     * {@inheritDoc}
     *
     * Performs semantic similarity search using:
     * 1. Convert question to embedding vector
     * 2. Search vector store for similar embeddings
     * 3. Format results with question, query, score, metadata
     *
     * Results are sorted by similarity score (highest first).
     *
     * @param string $question Question to search for
     * @param string $collection Vector collection to search
     * @param int $limit Maximum results to return
     *
     * @return array Similar questions with structure:
     *               [
     *                   ['question' => '...', 'query' => '...', 'score' => 0.89, 'metadata' => [...]],
     *                   ['question' => '...', 'query' => '...', 'score' => 0.82, 'metadata' => [...]],
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
        // Generate embedding for the input question
        $embedding = $this->embeddingProvider->embed($question);

        // Search vector store for similar embeddings
        $results = $this->vectorStore->search($collection, $embedding, $limit);

        // Format results for consistent structure
        return array_map(function ($result) {
            return [
                'question' => $result['payload']['question'] ?? '',
                'query' => $result['payload']['cypher_query'] ?? '',
                'score' => $result['score'] ?? 0.0,
                'metadata' => $result['payload'] ?? [],
            ];
        }, $results);
    }

    /**
     * {@inheritDoc}
     *
     * Retrieves graph database schema using GraphStoreInterface::getSchema()
     * and formats into consistent structure with:
     * - labels: All node types in the graph
     * - relationships: All relationship types connecting nodes
     * - properties: All property keys used in graph
     *
     * @return array Schema with keys:
     *               - labels: Array of node label strings
     *               - relationships: Array of relationship type strings
     *               - properties: Array of property key strings
     *
     * @throws \RuntimeException If schema retrieval fails
     */
    public function getGraphSchema(): array
    {
        $schema = $this->graphStore->getSchema();

        return [
            'labels' => $schema['labels'] ?? [],
            'relationships' => $schema['relationshipTypes'] ?? [],
            'properties' => $schema['propertyKeys'] ?? [],
        ];
    }

    /**
     * {@inheritDoc}
     *
     * Queries graph database for sample entities of specified label.
     * Uses Cypher MATCH query to retrieve random sample.
     *
     * Note: Label is used directly in Cypher query, so it must be
     * a valid label name. Consider validation if accepting user input.
     *
     * @param string $label Entity label/type to get examples for
     * @param int $limit Maximum number of examples
     *
     * @return array Sample entities as associative arrays
     *
     * @throws \InvalidArgumentException If label is empty
     * @throws \RuntimeException If query execution fails
     */
    public function getExampleEntities(string $label, int $limit = 3): array
    {
        if (empty($label)) {
            throw new \InvalidArgumentException('Label cannot be empty');
        }

        // Build Cypher query to retrieve sample entities
        // Note: Using label directly in query - assumes valid label name
        $cypher = "MATCH (n:`{$label}`) RETURN n LIMIT \$limit";

        $results = $this->graphStore->query($cypher, ['limit' => $limit]);

        // Extract node data from query results
        return array_map(function ($row) {
            return $row['n'] ?? [];
        }, $results);
    }
}
