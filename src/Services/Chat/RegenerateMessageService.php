<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Service for regenerating AI responses from a specific message.
 *
 * Unlike askWithConversation(), this service:
 * 1. Deletes all messages AFTER the target user message
 * 2. Does NOT create a duplicate user message
 * 3. Only generates a new assistant response
 */
class RegenerateMessageService
{
    public function __construct(
        private readonly AiChatService $chatService
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
        if ($userMessage->role !== 'user') {
            throw new \InvalidArgumentException('Can only regenerate from user messages');
        }

        // Delete all messages after this user message (using ID for reliable ordering)
        $conversation->messages()
            ->where('id', '>', $userMessage->id)
            ->delete();

        // Regenerate without creating duplicate user message
        return $this->chatService->regenerateResponse(
            $conversation,
            $userMessage,
            array_merge($options, ['user' => $user])
        );
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
