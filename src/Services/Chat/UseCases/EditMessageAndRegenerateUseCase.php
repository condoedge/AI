<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\UseCases;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Chat\Results\ChatTurnResult;
use Illuminate\Support\Facades\DB;

/**
 * Edit an existing user message and regenerate the answer: update the content (short
 * transaction), then delegate to RegenerateAnswerUseCase (which owns trailing-message
 * truncation + the shared turn flow). No transaction is held across the LLM calls.
 *
 * Concrete, container-autowired (not bound, not interfaced) per the Sisc UseCase convention.
 */
class EditMessageAndRegenerateUseCase
{
    public function __construct(
        private readonly RegenerateAnswerUseCase $regenerateAnswerUseCase,
    ) {
    }

    /**
     * @param AiConversation $conversation The conversation containing the message.
     * @param AiMessage      $userMessage  The user message being edited.
     * @param string         $newContent   The new content for the user message.
     * @param string         $style        Response style.
     * @param mixed          $user         The acting user (Authenticatable|null).
     *
     * @throws \InvalidArgumentException If the target is not a user message.
     */
    public function execute(
        AiConversation $conversation,
        AiMessage $userMessage,
        string $newContent,
        string $style,
        mixed $user = null,
        bool $allowAnonymous = false
    ): ChatTurnResult {
        if ($userMessage->role !== 'user') {
            throw new \InvalidArgumentException('Can only edit user messages');
        }

        // Update the edited user message in place — a short write-only transaction. The
        // regeneration below (multi-second LLM calls) must NOT run inside a transaction.
        DB::transaction(fn () => $userMessage->update(['content' => $newContent]));

        // Delegate truncation + regeneration to the single owner of that flow.
        // refresh() so the regenerate path reads the updated content.
        return $this->regenerateAnswerUseCase->execute(
            conversation: $conversation,
            userMessage: $userMessage->refresh(),
            style: $style,
            user: $user,
            allowAnonymous: $allowAnonymous,
        );
    }
}
