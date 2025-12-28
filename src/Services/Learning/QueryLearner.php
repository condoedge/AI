<?php
// src/Services/Learning/QueryLearner.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Learning;

use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Models\AiQueryLog;

class QueryLearner
{
    private const COLLECTION = 'learned_queries';

    public function __construct(
        private VectorStoreInterface $vectorStore,
        private EmbeddingProviderInterface $embeddingProvider
    ) {}

    /**
     * Learn from successful queries in the logs
     */
    public function learnFromLogs(int $minConfidence = 80, int $limit = 100): array
    {
        $successfulQueries = AiQueryLog::where('status', 'success')
            ->where('confidence_score', '>=', $minConfidence / 100)
            ->whereNotNull('cypher_query')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $learned = 0;
        $skipped = 0;

        foreach ($successfulQueries as $log) {
            // Check if similar query already learned
            if ($this->isAlreadyLearned($log->question)) {
                $skipped++;
                continue;
            }

            // Add to learned queries collection
            $this->addLearnedQuery(
                $log->question,
                $log->cypher_query,
                [
                    'template' => $log->template_used,
                    'confidence' => $log->confidence_score,
                    'learned_from_log_id' => $log->id,
                ]
            );
            $learned++;
        }

        return [
            'processed' => $successfulQueries->count(),
            'learned' => $learned,
            'skipped' => $skipped,
        ];
    }

    public function addLearnedQuery(string $question, string $cypherQuery, array $metadata = []): void
    {
        $embedding = $this->embeddingProvider->embed($question);

        $this->vectorStore->upsert(self::COLLECTION, [[
            'id' => md5($question),
            'vector' => $embedding,
            'payload' => array_merge($metadata, [
                'question' => $question,
                'cypher_query' => $cypherQuery,
                'learned_at' => now()->toIso8601String(),
            ]),
        ]]);
    }

    public function findSimilarLearnedQuery(string $question, float $threshold = 0.85): ?array
    {
        $embedding = $this->embeddingProvider->embed($question);

        $results = $this->vectorStore->search(
            self::COLLECTION,
            $embedding,
            limit: 1,
            filter: [],
            scoreThreshold: $threshold
        );

        if (empty($results)) {
            return null;
        }

        return [
            'question' => $results[0]['payload']['question'],
            'cypher_query' => $results[0]['payload']['cypher_query'],
            'score' => $results[0]['score'],
            'metadata' => $results[0]['payload'],
        ];
    }

    private function isAlreadyLearned(string $question): bool
    {
        return $this->findSimilarLearnedQuery($question, 0.95) !== null;
    }
}
