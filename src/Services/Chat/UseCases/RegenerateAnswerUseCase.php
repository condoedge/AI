<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\UseCases;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Chat\Results\ChatTurnResult;
use Condoedge\Ai\Services\Chat\Support\ChatSecurityGuard;
use Illuminate\Support\Facades\DB;

/**
 * Regenerate the assistant answer for an existing user message.
 *
 * Owns the single home of the trailing-message truncation rule that was duplicated across
 * RegenerateMessageService.php:47-49 and EditMessageModal.php: delete every message after the
 * target user message (by id for reliable ordering), then reuse AskQuestionUseCase's shared
 * turn flow with persistUserMessage=false so the user message is not re-stored.
 *
 * Absorbs RegenerateMessageService's logic (the precondition + truncate + delegate).
 * Concrete, container-autowired (not bound, not interfaced) per the Sisc UseCase convention.
 */
class RegenerateAnswerUseCase
{
    public function __construct(
        private readonly AskQuestionUseCase $askQuestionUseCase,
        private readonly ChatSecurityGuard $securityGuard,
    ) {
    }

    /**
     * @param AiConversation $conversation The conversation containing the message.
     * @param AiMessage      $userMessage  The user message to regenerate from.
     * @param string         $style        Response style.
     * @param mixed          $user         The acting user (Authenticatable|null).
     *
     * @throws \InvalidArgumentException If the target is not a user message.
     */
    public function execute(
        AiConversation $conversation,
        AiMessage $userMessage,
        string $style,
        mixed $user = null,
        bool $allowAnonymous = false
    ): ChatTurnResult {
        if ($userMessage->role !== 'user') {
            throw new \InvalidArgumentException('Can only regenerate from user messages');
        }

        // STEP 0 — security gate FIRST, before truncating anything. This guarantees a
        // detected injection declines the request WITHOUT destroying the existing trailing
        // messages (decline-and-continue). AskQuestionUseCase::regenerate re-checks the gate
        // for callers that reach it directly; that re-check is idempotent and cheap.
        if (!$this->securityGuard->assertSafe($userMessage->content, $user, $allowAnonymous)) {
            return ChatTurnResult::failure('ai.chat.injection-blocked');
        }

        return DB::transaction(function () use ($conversation, $userMessage, $style, $user, $allowAnonymous) {
            $this->truncateTrailingMessages($conversation, $userMessage);

            return $this->askQuestionUseCase->regenerate(
                conversation: $conversation,
                userMessage: $userMessage,
                style: $style,
                user: $user,
                allowAnonymous: $allowAnonymous,
            );
        });
    }

    /**
     * Delete every message after the target user message.
     *
     * Uses id (not created_at) for reliable ordering — the one home of the rule formerly
     * copy-pasted in RegenerateMessageService.php:47-49 and EditMessageModal.php:151-153/186-188.
     */
    private function truncateTrailingMessages(AiConversation $conversation, AiMessage $userMessage): void
    {
        $conversation->messages()
            ->where('id', '>', $userMessage->id)
            ->delete();
    }
}
