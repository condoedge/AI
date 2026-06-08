<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\UseCases;

use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Chat\Inputs\AskQuestionInput;
use Condoedge\Ai\Services\Chat\Results\ChatTurnResult;
use Condoedge\Ai\Services\Chat\Support\ChatSecurityGuard;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single home of chat-turn orchestration.
 *
 * Faithfully extracts AiChatService::askWithConversation (AiChatService.php:79-189) and
 * regenerateResponse (:202-291), collapsing the ~80% duplicate flow into one private path
 * parameterized by $persistUserMessage. Compared to the original:
 *  - the security gate runs as STEP 0, BEFORE any context processing or persistence
 *    (fixing the old ordering bug where the user message was processed/stored before the
 *    injection check at AiChatService.php:105-109);
 *  - injection becomes a per-request refusal (decline-and-continue) instead of a stored
 *    assistant message, returned as a failed ChatTurnResult;
 *  - the whole turn runs inside a DB::transaction so a mid-turn failure leaves no partial
 *    user-message / snapshot writes;
 *  - the created assistant AiMessage is returned on the result so callers never re-query.
 *
 * Concrete, container-autowired (not bound, not interfaced) per the Sisc UseCase convention.
 * AI is resolved via the facade exactly as AiChatService does today (AiChatService is
 * config-`new`ed and cannot inject it).
 */
class AskQuestionUseCase
{
    public function __construct(
        private readonly ConversationContextManager $contextManager,
        private readonly ChatSecurityGuard $securityGuard,
    ) {
    }

    /**
     * Ask a question within a conversation: persists the user message, runs RAG,
     * records context, persists the assistant message, returns a ChatTurnResult.
     */
    public function execute(AskQuestionInput $input): ChatTurnResult
    {
        // STEP 0 — security gate FIRST (precondition + injection scan), before any context
        // processing, persistence, or even opening a transaction. A missing-user precondition
        // throws; a detected injection becomes a per-request refusal (decline-and-continue).
        if (!$this->securityGuard->assertSafe($input->question, $input->user, $input->allowAnonymous)) {
            return ChatTurnResult::failure('ai.chat.injection-blocked');
        }

        return DB::transaction(fn () => $this->runTurn(
            conversation: $input->conversation,
            question: $input->question,
            style: $input->style,
            user: $input->user,
            persistUserMessage: true,
            regenerated: false,
        ));
    }

    /**
     * Regenerate an answer for an already-stored user message.
     *
     * Shares the exact same turn flow as execute(), but does NOT re-store the user message
     * (persistUserMessage=false) and tags the assistant message as regenerated. Trailing-message
     * truncation is the caller's responsibility (RegenerateAnswerUseCase owns that rule).
     */
    public function regenerate(
        AiConversation $conversation,
        AiMessage $userMessage,
        string $style,
        mixed $user,
        bool $allowAnonymous = false
    ): ChatTurnResult {
        $question = $userMessage->content;

        // STEP 0 — same security gate, same ordering as execute() (fixes the old inconsistent
        // ordering between AiChatService.php:103-109 and :222-225). Runs before the transaction.
        if (!$this->securityGuard->assertSafe($question, $user, $allowAnonymous)) {
            return ChatTurnResult::failure('ai.chat.injection-blocked');
        }

        return DB::transaction(fn () => $this->runTurn(
            conversation: $conversation,
            question: $question,
            style: $style,
            user: $user,
            persistUserMessage: false,
            regenerated: true,
        ));
    }

    /**
     * The single shared turn flow. Assumes the security gate has already passed.
     *
     * @param bool $persistUserMessage Whether to store the user message (true for ask, false for regenerate).
     * @param bool $regenerated        Whether to tag the assistant message as regenerated.
     */
    private function runTurn(
        AiConversation $conversation,
        string $question,
        string $style,
        mixed $user,
        bool $persistUserMessage,
        bool $regenerated
    ): ChatTurnResult {
        try {
            $schema = $this->getSchema();

            // Process question through the context system: extract entities, resolve
            // references, update the context snapshot. For regenerate we only need the
            // prompt context (the user message already exists), matching the original.
            if ($persistUserMessage) {
                $contextResult = $this->contextManager->processQuestion($conversation, $question, $schema);
                $conversationContext = $this->contextManager->buildPromptContext($conversation);
                $enrichedQuestion = $contextResult['enriched_question'] ?? $question;

                $conversation->addMessage('user', $question, [
                    'context_used' => $conversationContext,
                    'is_follow_up' => $contextResult['is_follow_up'] ?? false,
                    'resolved_entity' => $contextResult['resolved_entity'] ?? null,
                ]);
            } else {
                $conversationContext = $this->contextManager->buildPromptContext($conversation);
                $enrichedQuestion = $question;
            }

            // Call AI with full conversation context.
            $aiResponse = AI::answerQuestion($enrichedQuestion, [
                'style' => $style,
                'conversation_id' => $conversation->id,
                'conversation_context' => $conversationContext,
                'user' => $user,
            ]);

            $answerText = $aiResponse['answer'] ?? '';
            $cypherQuery = $aiResponse['cypher'] ?? null;
            $queryData = $aiResponse['data'] ?? [];
            $referencedFiles = $aiResponse['referenced_files'] ?? [];
            $suggestions = $aiResponse['suggestions'] ?? [];

            // Record response: single context-snapshot write (even without cypher), so
            // follow-ups can resolve referenced files / entities.
            $this->contextManager->recordResponse(
                $conversation,
                $answerText,
                $cypherQuery ?? '',
                [
                    'data' => $queryData,
                    'referenced_files' => $referencedFiles,
                ]
            );

            // Persist the assistant message and keep the row so callers never re-query.
            $assistantMessage = $conversation->addMessage('assistant', $answerText, [
                'response_data' => $queryData,
                'cypher_query' => $cypherQuery,
                'suggestions' => $suggestions,
                'referenced_files' => $referencedFiles,
                'regenerated' => $regenerated,
            ]);

            // ABSTENTION: no data AND no referenced files → flag the turn as degraded so the
            // presenter can surface it as a low-confidence / fallback answer.
            $degraded = empty($queryData) && empty($referencedFiles);

            return new ChatTurnResult(
                answer: $answerText,
                data: $queryData,
                suggestions: $suggestions,
                sources: $referencedFiles,
                cypher: $cypherQuery,
                assistantMessage: $assistantMessage,
                degraded: $degraded,
            );
        } catch (\Throwable $e) {
            Log::error('AI chat turn error', [
                'conversation_id' => $conversation->id,
                'regenerated' => $regenerated,
                'error' => $e->getMessage(),
            ]);

            $errorText = $this->getUserFriendlyError($e);

            // Preserve the original behavior of recording a visible error message in the
            // conversation, and return it as a failed turn carrying that message.
            $assistantMessage = $conversation->addMessage('assistant', $errorText, [
                'error' => true,
                'regenerated' => $regenerated,
            ]);

            return ChatTurnResult::failure($errorText, $assistantMessage);
        }
    }

    /**
     * Fetch the graph schema for context processing (mirrors AiChatService::getSchema()).
     * Returns an empty schema on failure rather than aborting the turn.
     */
    private function getSchema(): array
    {
        try {
            return AI::getSchema();
        } catch (\Throwable $e) {
            Log::warning('Unable to fetch schema for context', ['error' => $e->getMessage()]);

            return ['labels' => [], 'relationships' => []];
        }
    }

    /**
     * Convert exception to a user-friendly message (ported from AiChatService::getUserFriendlyError).
     */
    private function getUserFriendlyError(\Throwable $e): string
    {
        $message = $e->getMessage();

        $errorMap = [
            'connection' => 'I\'m having trouble connecting to the database. Please try again.',
            'timeout' => 'The request took too long. Try a simpler question.',
            'api' => 'There was an issue with the AI service. Please try again.',
        ];

        foreach ($errorMap as $keyword => $friendlyMessage) {
            if (stripos($message, $keyword) !== false) {
                return $friendlyMessage;
            }
        }

        return 'I encountered an error processing your question. Please try rephrasing or try again later.';
    }
}
