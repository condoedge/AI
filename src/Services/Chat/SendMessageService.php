<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Condoedge\Ai\Models\AiConversation;

/**
 * SendMessageService - Handles sending messages in AI chat conversations.
 *
 * This service provides a clean interface for sending messages that:
 * 1. Validates the message is not empty
 * 2. Delegates to AiChatService for processing and persistence
 *
 * This replaces the anti-pattern of using request()->merge() to manipulate
 * global request state before instantiating form classes.
 */
class SendMessageService
{
    public function __construct(
        private readonly AiChatService $aiChatService
    ) {}

    /**
     * Send a message in a conversation and get AI response.
     *
     * @param AiConversation $conversation The conversation to send message in
     * @param string $message The user's message
     * @param array $options Additional options passed to AiChatService:
     *   - user: The user sending the message
     *   - style: Response style (friendly|professional|concise)
     * @return array The AI response with answer, data, suggestions, sources, etc.
     *
     * @throws \InvalidArgumentException If message is empty
     */
    public function sendMessage(
        AiConversation $conversation,
        string $message,
        array $options = []
    ): array {
        $trimmedMessage = trim($message);

        if ($trimmedMessage === '') {
            throw new \InvalidArgumentException('Message cannot be empty');
        }

        return $this->aiChatService->askWithConversation(
            question: $message,
            conversation: $conversation,
            options: $options
        );
    }
}
