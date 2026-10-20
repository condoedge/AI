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
 * Owns the single home of the trailing-message truncation rule: delete every message after
 * the target user message (by id for reliable ordering), then reuse AskQuestionUseCase's
 * shared turn flow with persistUserMessage=false so the user message is not re-stored.
 * The truncation runs in a short transaction; the turn itself runs outside any transaction.
 *
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

        // Short write-only transaction for the truncation; the regenerate turn (multi-second
        // LLM calls) runs OUTSIDE any transaction. Safe: runTurn catches every Throwable and
        // records a visible error assistant message, so a failed turn never leaves the thread
        // without an answer row after the truncation.
        DB::transaction(fn () => $this->truncateTrailingMessages($conversation, $userMessage));

        return $this->askQuestionUseCase->regenerate(
            conversation: $conversation,
            userMessage: $userMessage,
            style: $style,
            user: $user,
            allowAnonymous: $allowAnonymous,
        );
    }

    /**
     * Delete every message after the target user message.
     *
     * Uses id (not created_at) for reliable ordering.
     */
    private function truncateTrailingMessages(AiConversation $conversation, AiMessage $userMessage): void
    {
        $conversation->messages()
            ->where('id', '>', $userMessage->id)
            ->delete();
    }
}
