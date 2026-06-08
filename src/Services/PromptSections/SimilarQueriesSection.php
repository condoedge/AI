<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\AiManager;

/**
 * SimilarQueriesSection
 *
 * Shows similar past queries for few-shot learning.
 * The LLM can learn patterns from successful past queries.
 * Stored queries are parameterized ($currentUserId) and get
 * deparameterized with the actual current user's ID before display.
 * Priority: 50
 */
class SimilarQueriesSection extends BasePromptSection
{
    protected string $name = 'similar_queries';
    protected int $priority = 50;

    private int $maxQueries = 3;

    /**
     * Set maximum number of similar queries to show
     */
    public function setMaxQueries(int $max): self
    {
        $this->maxQueries = $max;
        return $this;
    }

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $queries = $context->similarQueries;

        if (empty($queries)) {
            return '';
        }

        $output = $this->header('SIMILAR QUERIES (learn from these)');

        foreach (array_slice($queries, 0, $this->maxQueries) as $index => $query) {
            $q = $query['question'] ?? '';
            $cypher = $query['query'] ?? '';
            $score = $query['score'] ?? 0;

            if (empty($q) || empty($cypher)) {
                continue;
            }

            // Replace $currentUserId/$currentTeamId with actual values
            $cypher = AiManager::deparameterizeCypher($cypher);

            $similarity = round($score * 100);
            $output .= "Example " . ($index + 1) . " ({$similarity}% similar):\n";
            $output .= "  Question: {$q}\n";
            $output .= "  Query: {$cypher}\n\n";
        }

        return $output;
    }

    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        return !empty($context->similarQueries);
    }
}
