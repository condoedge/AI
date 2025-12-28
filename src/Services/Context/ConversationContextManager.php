<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Condoedge\Ai\Models\AiConversation;

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
     * Record an AI response and update context with query info
     */
    public function recordResponse(
        AiConversation $conversation,
        string $response,
        string $cypherQuery,
        array $queryResult
    ): void {
        // Extract entities from the Cypher query
        $cypherEntities = $this->entityExtractor->extractFromCypher($cypherQuery);

        // Update context with the entities from the executed query
        $currentEntities = $conversation->getMentionedEntities();
        $newEntities = array_unique(array_merge($currentEntities, $cypherEntities['entities']));

        $focusedEntity = $conversation->getFocusedEntity();
        if ($focusedEntity === null && !empty($cypherEntities['entities'])) {
            $focusedEntity = $cypherEntities['entities'][0];
        }

        $conversation->updateContextSnapshot([
            'focused_entity' => $focusedEntity,
            'mentioned_entities' => $newEntities,
            'last_relationships' => $cypherEntities['relationships'],
            'last_result_count' => count($queryResult['data'] ?? []),
        ]);
    }

    /**
     * Build context data for the prompt builder
     */
    public function buildPromptContext(AiConversation $conversation, int $maxHistory = 5): array
    {
        $snapshot = $conversation->context_snapshot ?? [];
        $lastQuery = $conversation->getLastCypherQuery();

        // Get recent message exchanges
        $recentMessages = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->limit($maxHistory * 2) // User + assistant pairs
            ->get()
            ->reverse()
            ->values();

        $recentExchanges = [];
        foreach ($recentMessages->chunk(2) as $pair) {
            $exchange = [];
            foreach ($pair as $message) {
                $exchange[$message->role] = [
                    'content' => $message->content,
                    'cypher_query' => $message->cypher_query,
                ];
            }
            if (!empty($exchange)) {
                $recentExchanges[] = $exchange;
            }
        }

        // Limit to maxHistory exchanges
        $recentExchanges = array_slice($recentExchanges, -$maxHistory);

        return [
            'focused_entity' => $snapshot['focused_entity'] ?? null,
            'mentioned_entities' => $snapshot['mentioned_entities'] ?? [],
            'last_query_type' => $snapshot['last_query_type'] ?? null,
            'last_cypher_query' => $lastQuery,
            'last_result_count' => $snapshot['last_result_count'] ?? null,
            'recent_exchanges' => $recentExchanges,
        ];
    }
}
