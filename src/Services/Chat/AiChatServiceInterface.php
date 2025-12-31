<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

/**
 * Contract for AI Chat service implementations.
 * Allows for different chat backends while maintaining consistent interface.
 */
interface AiChatServiceInterface
{
    /**
     * Process a user question and return an AI response.
     *
     * @param string $question The user's question
     * @param array $options Additional options (style, context, etc.)
     * @return AiChatMessage The assistant's response message
     */
    public function ask(string $question, array $options = []): AiChatMessage;

    /**
     * Process a question within a conversation context.
     *
     * @param string $question The user's question
     * @param array $history Previous messages in the conversation
     * @param array $options Additional options
     * @return AiChatMessage The assistant's response message
     */
    public function askWithHistory(string $question, array $history, array $options = []): AiChatMessage;

    /**
     * Get suggested follow-up questions based on context.
     *
     * @param string $question The original question
     * @param string $response The AI's response
     * @return array List of suggested questions
     */
    public function getSuggestions(string $question, string $response): array;

    /**
     * Get example questions for the welcome screen.
     *
     * @return array List of example questions
     */
    public function getExampleQuestions(): array;

    /**
     * Check if the service is available and properly configured.
     *
     * @return bool
     */
    public function isAvailable(): bool;
}
