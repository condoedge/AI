<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Condoedge\Ai\Models\AiConversation;

/**
 * Interface for AI chat service operations.
 *
 * This is the primary interface for chat interactions. All chat requests
 * should go through askWithConversation() to ensure proper context tracking.
 */
interface AiChatServiceInterface
{
    /**
     * Ask a question within a conversation context.
     *
     * This method:
     * - Processes the question through ConversationContextManager
     * - Resolves references ("those", "them") to previous results
     * - Extracts and tracks focused entities
     * - Calls the AI with full conversation context
     * - Records the response for future reference
     *
     * @param string $question The user's question
     * @param AiConversation $conversation The conversation for context tracking
     * @param array $options Options including:
     *   - 'style' => string (friendly|professional|concise)
     *   - 'user' => User model for file access authorization
     * @return array{
     *   answer: string,
     *   data: array,
     *   suggestions: array<string>,
     *   sources: array,
     *   cypher_query: ?string
     * }
     */
    public function askWithConversation(
        string $question,
        AiConversation $conversation,
        array $options = []
    ): array;

    /**
     * Get the graph schema for context building.
     *
     * @return array The schema with 'labels', 'relationships', 'properties'
     */
    public function getSchema(): array;

    /**
     * Check if the chat service is available.
     *
     * @return bool True if LLM and graph store are accessible
     */
    public function isAvailable(): bool;
}
