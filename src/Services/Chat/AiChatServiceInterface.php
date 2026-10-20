<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

/**
 * The chat surface's service seam: availability (the online badge).
 *
 * Chat turns run through AskQuestionUseCase (ChatPresenter), never through
 * this interface.
 */
interface AiChatServiceInterface
{
    /**
     * Check if the chat service is available.
     *
     * @return bool True if an LLM provider is configured
     */
    public function isAvailable(): bool;
}
