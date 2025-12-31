<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Condoedge\Ai\Models\AiConversation;
use Illuminate\Support\Str;

/**
 * ConversationContextManager
 *
 * Orchestrates conversation context tracking by combining:
 * - Entity extraction from questions and responses
 * - Reference resolution for follow-up questions
 * - Context snapshot management on AiConversation
 *
 * This is the main entry point for conversation context handling.
 */
class ConversationContextManager
{
    public function __construct(
        private readonly EntityExtractor $entityExtractor,
        private readonly ReferenceResolver $referenceResolver
    ) {
    }

    /**
     * Process an incoming question and update conversation context
     */
    public function processQuestion(
        AiConversation $conversation,
        string $question,
        array $schema
    ): array {
        // Extract entities from the new question
        $extraction = $this->entityExtractor->extractFromQuestion($question, $schema);

        // Check if this is a follow-up
        $isFollowUp = $this->referenceResolver->isFollowUp($question);

        $result = [
            'is_follow_up' => $isFollowUp,
            'focused_entity' => $extraction['focused_entity'],
            'query_type' => $extraction['query_type'],
            'mentioned_entities' => $extraction['mentioned_entities'],
            'enriched_question' => $question,
            'resolved_entity' => null,
        ];

        // If follow-up, try to resolve references
        if ($isFollowUp) {
            $currentContext = $this->buildPromptContext($conversation);
            $resolution = $this->referenceResolver->resolve($question, $currentContext);

            if ($resolution['resolved']) {
                $result['resolved_entity'] = $resolution['resolved_entity'];
                $result['enriched_question'] = $resolution['enriched_question'];

                // Use resolved entity as focus if extraction didn't find one
                if ($result['focused_entity'] === null) {
                    $result['focused_entity'] = $resolution['resolved_entity'];
                }
            }
        }

        // Update conversation context (only if we have new info)
        if ($result['focused_entity'] !== null) {
            $conversation->updateContextSnapshot([
                'focused_entity' => $result['focused_entity'],
                'last_query_type' => $result['query_type'],
                'mentioned_entities' => array_unique(array_merge(
                    $conversation->getMentionedEntities(),
                    $result['mentioned_entities']
                )),
            ]);
        }

        return $result;
    }

    /**
     * Record an AI response and update context with enhanced entity data.
     *
     * Stores query results, entity filters, and answer summaries to enable
     * resolution of references like "those", "them", "the same" in follow-up questions.
     */
    public function recordResponse(
        AiConversation $conversation,
        string $response,
        ?string $cypherQuery,
        array $queryResult
    ): void {
        $snapshot = $conversation->context_snapshot ?? [];

        // Extract entities from the Cypher query (if available)
        $cypherEntities = ['entities' => [], 'relationships' => []];
        if ($cypherQuery) {
            $cypherEntities = $this->entityExtractor->extractFromCypher($cypherQuery);
        }

        // Update context with the entities from the executed query
        $currentEntities = $conversation->getMentionedEntities();
        $newEntities = array_unique(array_merge($currentEntities, $cypherEntities['entities']));

        $focusedEntity = $conversation->getFocusedEntity();
        if ($focusedEntity === null && !empty($cypherEntities['entities'])) {
            $focusedEntity = $cypherEntities['entities'][0];
        }

        // Extract entity filter from Cypher WHERE clause
        $entityFilter = $this->extractEntityFilter($cypherQuery);

        // Store result sample (first 3 results for context)
        $resultSample = array_slice($queryResult['data'] ?? [], 0, 3);

        // Update snapshot with enhanced data
        $conversation->updateContextSnapshot([
            'focused_entity' => $focusedEntity,
            'mentioned_entities' => $newEntities,
            'last_relationships' => $cypherEntities['relationships'],
            'last_cypher_query' => $cypherQuery,
            'last_result_count' => count($queryResult['data'] ?? []),
            'last_result_sample' => $resultSample,
            'focused_entity_filter' => $entityFilter,
            'last_answer_summary' => Str::limit($response, 200),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Extract WHERE clause conditions from Cypher query.
     *
     * Used to track entity filters for reference resolution in follow-up questions.
     */
    protected function extractEntityFilter(?string $cypherQuery): ?string
    {
        if (!$cypherQuery) {
            return null;
        }

        // Extract WHERE clause
        if (preg_match('/WHERE\s+(.+?)(?:RETURN|ORDER|LIMIT|$)/is', $cypherQuery, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Build enhanced prompt context with entity data.
     *
     * Provides comprehensive context for prompt building including:
     * - Current focused entity and filter conditions
     * - Sample results from last query for reference resolution
     * - Recent conversation exchanges for continuity
     */
    public function buildPromptContext(AiConversation $conversation, int $maxHistory = 5): array
    {
        $snapshot = $conversation->context_snapshot ?? [];

        // Get recent messages for exchange formatting
        $recentMessages = $conversation->getRecentMessages($maxHistory);

        return [
            'focused_entity' => $snapshot['focused_entity'] ?? null,
            'focused_entity_filter' => $snapshot['focused_entity_filter'] ?? null,
            'last_result_sample' => $snapshot['last_result_sample'] ?? [],
            'last_result_count' => $snapshot['last_result_count'] ?? 0,
            'last_cypher_query' => $snapshot['last_cypher_query'] ?? null,
            'last_query_type' => $snapshot['last_query_type'] ?? null,
            'last_relationships' => $snapshot['last_relationships'] ?? [],
            'mentioned_entities' => $snapshot['mentioned_entities'] ?? [],
            'last_answer_summary' => $snapshot['last_answer_summary'] ?? null,
            'recent_exchanges' => $this->formatRecentExchanges($recentMessages),
        ];
    }

    /**
     * Format recent messages as exchanges for prompt context.
     *
     * Structures messages into a format suitable for context injection,
     * with summarized answers to keep prompt size manageable.
     */
    protected function formatRecentExchanges(array $messages): array
    {
        $exchanges = [];
        foreach ($messages as $message) {
            $role = $message['role'] ?? 'unknown';
            $content = $message['content'] ?? '';

            $exchanges[] = [
                'role' => $role,
                'question' => $role === 'user' ? $content : null,
                'answer_summary' => $role === 'assistant'
                    ? Str::limit($content, 100)
                    : null,
            ];
        }
        return $exchanges;
    }
}
