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
 * Single home of chat-turn orchestration for both ask and regenerate, collapsed into one
 * private path parameterized by $persistUserMessage. Key guarantees:
 *  - the security gate runs as STEP 0, BEFORE any context processing or persistence;
 *  - injection becomes a per-request refusal (decline-and-continue) instead of a stored
 *    assistant message, returned as a failed ChatTurnResult;
 *  - the whole turn runs inside a DB::transaction so a mid-turn failure leaves no partial
 *    user-message / snapshot writes;
 *  - the created assistant AiMessage is returned on the result so callers never re-query.
 *
 * Concrete, container-autowired (not bound, not interfaced) per the Sisc UseCase convention.
 * AI is resolved via the facade (AiChatService is config-`new`ed and cannot inject it).
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
            pageContext: $input->pageContext?->toArray(),
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

        // STEP 0 — same security gate and ordering as execute(), before the transaction.
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
        bool $regenerated,
        ?array $pageContext = null
    ): ChatTurnResult {
        try {
            $schema = $this->getSchema();

            // Process question through the context system: extract entities, resolve
            // references, update the context snapshot. For regenerate we only need the
            // prompt context (the user message already exists).
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

            // Call AI with full conversation context. page_context (the soft prior for which
            // page/record the chat was opened from) rides alongside as the PageContext array;
            // it is null when the chat was not opened from a page, keeping the turn unchanged.
            $aiResponse = AI::answerQuestion($enrichedQuestion, [
                'style' => $style,
                'conversation_id' => $conversation->id,
                'conversation_context' => $conversationContext,
                'page_context' => $pageContext,
                'user' => $user,
            ]);

            $answerText = $aiResponse['answer'] ?? '';
            $cypherQuery = $aiResponse['cypher'] ?? null;
            $queryData = $aiResponse['data'] ?? [];
            $referencedFiles = $aiResponse['referenced_files'] ?? [];
            $suggestions = $aiResponse['suggestions'] ?? [];

            // Native tool-loop invocations (already schema-FILTERED, toArray() rows) ride the
            // response metadata; persisted on the assistant message so every render can
            // re-dispatch them — fresh authorize(), fresh approval token.
            $invocations = $aiResponse['metadata']['response']['invocations'] ?? [];
            if (!is_array($invocations)) {
                $invocations = [];
            }
            $messageMetadata = [];
            if ($invocations !== []) {
                $messageMetadata['invocations'] = $invocations;
            }
            $exportDocumentMarkdown = $aiResponse['metadata']['response']['export_document_markdown'] ?? null;
            if (is_string($exportDocumentMarkdown) && trim($exportDocumentMarkdown) !== '') {
                $messageMetadata['export_document_markdown'] = $exportDocumentMarkdown;
            }

            // Record response: single context-snapshot write (even without cypher), so
            // follow-ups can resolve referenced files / entities. Metadata rides along
            // so the snapshot contracts the composers stamp actually hold end-to-end:
            // metadata.subject (the distilled subject that titles a follow-up export)
            // and metadata.response.digest (marks the rows as a full display-capped
            // set the snapshot keeps whole for the "export that" follow-up).
            $this->contextManager->recordResponse(
                $conversation,
                $answerText,
                $cypherQuery ?? '',
                [
                    'data' => $queryData,
                    'referenced_files' => $referencedFiles,
                    'metadata' => is_array($aiResponse['metadata'] ?? null) ? $aiResponse['metadata'] : [],
                ]
            );

            // Persist the assistant message and keep the row so callers never re-query.
            $assistantMessage = $conversation->addMessage('assistant', $answerText, [
                'response_data' => $queryData,
                'cypher_query' => $cypherQuery,
                'suggestions' => $suggestions,
                'referenced_files' => $referencedFiles,
                'regenerated' => $regenerated,
                'metadata' => $messageMetadata,
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

            // Record a visible error message in the conversation and return it as a failed
            // turn carrying that message.
            $assistantMessage = $conversation->addMessage('assistant', $errorText, [
                'error' => true,
                'regenerated' => $regenerated,
            ]);

            return ChatTurnResult::failure($errorText, $assistantMessage);
        }
    }

    /**
     * Fetch the graph schema for context processing.
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
     * Convert exception to a user-friendly message.
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
