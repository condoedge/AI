<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\Inputs\AskQuestionInput;
use Condoedge\Ai\Services\Chat\UseCases\AskQuestionUseCase;

/**
 * SendMessageService - Thin guard in front of AskQuestionUseCase.
 *
 * This service only:
 * 1. Validates the message is not empty
 * 2. Delegates orchestration to AskQuestionUseCase (the single home of chat-turn logic)
 *
 * It returns the array shape expected by existing Kompo callers; the use case is the
 * source of truth for behavior.
 */
class SendMessageService
{
    public function __construct(
        private readonly AskQuestionUseCase $askQuestionUseCase
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

        $result = $this->askQuestionUseCase->execute(new AskQuestionInput(
            question: $message,
            conversation: $conversation,
            style: $options['style'] ?? 'friendly',
            user: $options['user'] ?? null,
            allowAnonymous: $options['allow_anonymous'] ?? false,
        ));

        return [
            'answer' => $result->answer,
            'data' => $result->data,
            'suggestions' => $result->suggestions,
            'sources' => $result->sources,
            'cypher_query' => $result->cypher,
        ];
    }
}
