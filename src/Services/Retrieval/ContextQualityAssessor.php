<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Support\AiLogger;

/**
 * Scores the completeness of a retrieved PipelineContext across four signals:
 * similar queries, schema labels, detected entities, and absence of errors.
 */
class ContextQualityAssessor
{
    public function __construct(
        private readonly AiLogger $logger,
    ) {
    }

    /**
     * Assess the quality of retrieved context.
     *
     * @return array{level: string, score: int, max: int, details: array<int, string>}
     */
    public function assess(PipelineContext $context): array
    {
        $score = 0;
        $max = 4;
        $details = [];

        if (!empty($context->similarQueries)) {
            $score++;
            $details[] = 'has_similar_queries';
        }
        if (!empty($context->graphSchema['labels'] ?? [])) {
            $score++;
            $details[] = 'has_schema';
        }
        if (!empty($context->entityMetadata['detected_entities'] ?? [])) {
            $score++;
            $details[] = 'has_detected_entities';
        }
        if (empty($context->errors)) {
            $score++;
            $details[] = 'no_errors';
        }

        $level = match(true) {
            $score === $max => 'high',
            $score >= 2 => 'medium',
            default => 'low',
        };

        $this->logger->info('context_retriever', "context_quality: {$level}", [
            'score' => "{$score}/{$max}",
            'details' => $details,
            'errors' => $context->errors,
        ]);

        return ['level' => $level, 'score' => $score, 'max' => $max, 'details' => $details];
    }
}
