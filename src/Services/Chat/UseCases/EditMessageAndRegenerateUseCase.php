<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\UseCases;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Chat\Results\ChatTurnResult;
use Illuminate\Support\Facades\DB;

/**
 * Edit an existing user message and regenerate the answer in one transaction.
 *
 * Collapses EditMessageModal's two near-duplicate methods (updateMessage /
 * updateMessageAndGetResponse, EditMessageModal.php:137-167 / :173-...) and their double
 * truncate-then-regenerate into a single atomic flow: update the content, then delegate to
 * RegenerateAnswerUseCase (which owns trailing-message truncation + the shared turn flow).
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

        return DB::transaction(function () use ($conversation, $userMessage, $newContent, $style, $user, $allowAnonymous) {
            // Update the edited user message in place.
            $userMessage->update(['content' => $newContent]);

            // Delegate truncation + regeneration to the single owner of that flow.
            // refresh() so the regenerate path reads the updated content.
            return $this->regenerateAnswerUseCase->execute(
                conversation: $conversation,
                userMessage: $userMessage->refresh(),
                style: $style,
                user: $user,
                allowAnonymous: $allowAnonymous,
            );
        });
    }
}
