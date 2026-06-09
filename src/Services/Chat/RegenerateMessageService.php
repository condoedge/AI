<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Chat\UseCases\RegenerateAnswerUseCase;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Thin guard in front of RegenerateAnswerUseCase.
 *
 * This service only resolves which user message to regenerate from (directly, or by
 * walking back from an assistant message) and enforces the role preconditions. The actual
 * truncate + regenerate flow lives in RegenerateAnswerUseCase (the single home of that rule).
 *
 * It returns the array shape expected by existing Kompo callers.
 */
class RegenerateMessageService
{
    public function __construct(
        private readonly RegenerateAnswerUseCase $regenerateAnswerUseCase
    ) {}

    /**
     * Regenerate response from a user message.
     *
     * @param AiConversation $conversation The conversation containing the message
     * @param AiMessage $userMessage The user message to regenerate from
     * @param Authenticatable|null $user The user initiating the regeneration
     * @param array $options Additional options passed to the chat service
     * @return array The regenerated response
     *
     * @throws \InvalidArgumentException If the message is not a user message
     */
    public function regenerateFromMessage(
        AiConversation $conversation,
        AiMessage $userMessage,
        ?Authenticatable $user = null,
        array $options = []
    ): array {
        // Trailing-message truncation + regeneration is owned by the use case.
        $result = $this->regenerateAnswerUseCase->execute(
            conversation: $conversation,
            userMessage: $userMessage,
            style: $options['style'] ?? 'friendly',
            user: $user,
            allowAnonymous: $options['allow_anonymous'] ?? false,
        );

        return [
            'answer' => $result->answer,
            'data' => $result->data,
            'suggestions' => $result->suggestions,
            'sources' => $result->sources,
            'cypher_query' => $result->cypher,
        ];
    }

    /**
     * Regenerate from an assistant message (finds preceding user message).
     *
     * @param AiConversation $conversation The conversation containing the message
     * @param AiMessage $assistantMessage The assistant message to regenerate
     * @param Authenticatable|null $user The user initiating the regeneration
     * @param array $options Additional options passed to the chat service
     * @return array The regenerated response
     *
     * @throws \InvalidArgumentException If the message is not an assistant message
     * @throws \RuntimeException If no preceding user message is found
     */
    public function regenerateFromAssistantMessage(
        AiConversation $conversation,
        AiMessage $assistantMessage,
        ?Authenticatable $user = null,
        array $options = []
    ): array {
        if ($assistantMessage->role !== 'assistant') {
            throw new \InvalidArgumentException('Expected assistant message');
        }

        $userMessage = $conversation->messages()
            ->where('id', '<', $assistantMessage->id)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->first();

        if (!$userMessage) {
            throw new \RuntimeException('No user message found before assistant message');
        }

        return $this->regenerateFromMessage($conversation, $userMessage, $user, $options);
    }
}
