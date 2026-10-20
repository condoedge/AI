<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Support\AiLogger;

/**
 * Searches the vector store for past questions similar to the input, returning
 * Q -> Cypher pairs for few-shot grounding.
 */
class SimilarQuerySearcher
{
    public function __construct(
        private readonly VectorStoreInterface $vectorStore,
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly AiLogger $logger,
    ) {
    }

    /**
     * Search for similar queries with score threshold filtering.
     *
     * @return array<int, array{question: string, query: string, score: float}>
     *
     * @throws \RuntimeException If embedding generation or vector search fails.
     */
    public function search(
        string $question,
        string $collection,
        int $limit,
        float $scoreThreshold
    ): array {
        // The few-shot collection only exists once at least one Q->Cypher pair has
        // been stored. Before that it is legitimately absent: treat it as "no
        // examples yet" instead of a vector-search error, so context quality is not
        // degraded and the log is not polluted by an expected empty state.
        if (!$this->vectorStore->collectionExists($collection)) {
            return [];
        }

        try {
            $embedding = $this->embeddingProvider->embed($question);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Failed to generate embedding for question: ' . $e->getMessage(),
                0,
                $e
            );
        }

        if (empty($embedding)) {
            throw new \RuntimeException('Embedding provider returned empty vector');
        }

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

        return array_values(array_filter(array_map(function ($result) use ($scoreThreshold) {
            // Enforce the threshold here too: not every vector store applies
            // score_threshold server-side, and a below-threshold example is an
            // unrelated query the generator would copy. An UNSCORED hit cannot
            // prove it clears the threshold, so it is dropped too.
            if (!isset($result['score']) || !is_numeric($result['score'])
                || (float) $result['score'] < $scoreThreshold) {
                return null;
            }

            $question = $result['payload']['question'] ?? $result['metadata']['question'] ?? '';
            $query = $result['payload']['cypher_query'] ?? $result['payload']['query']
                ?? $result['metadata']['cypher_query'] ?? $result['metadata']['query'] ?? '';

            if (empty(trim($question)) || empty(trim($query))) {
                $this->logger->debug('context_retriever', 'similar_query_skipped_empty', [
                    'has_question' => !empty(trim($question)),
                    'has_query' => !empty(trim($query)),
                ]);
                return null;
            }

            return [
                'question' => $question,
                'query' => $query,
                'score' => $result['score'] ?? 0.0,
            ];
        }, $results)));
    }
}
