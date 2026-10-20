<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

/**
 * Default AI chat service: the chat surface's availability check (the online
 * badge). Chat turns themselves run through AskQuestionUseCase.
 *
 * Swappable via config('ai.chat.service'); constructed with config('ai.chat').
 */
class AiChatService implements AiChatServiceInterface
{
    public function __construct(
        protected array $config = [],
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        try {
            // Check if the AI facade is available and LLM is configured
            return config('ai.llm.default') !== null
                && config('ai.llm.' . config('ai.llm.default') . '.api_key') !== null;
        } catch (\Exception $e) {
            return false;
        }
    }
}
