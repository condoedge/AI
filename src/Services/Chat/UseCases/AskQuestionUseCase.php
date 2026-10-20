<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\UseCases;

use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Support\CompositePipelineObserver;
use Condoedge\Ai\Support\GuardedPipelineObserver;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Chat\Exporter\RecordedQueryRerun;
use Condoedge\Ai\Services\Chat\Inputs\AskQuestionInput;
use Condoedge\Ai\Services\Chat\Results\ChatTurnResult;
use Condoedge\Ai\Services\Chat\Support\ChatSecurityGuard;
use Condoedge\Ai\Services\Chat\Support\FriendlyError;
use Condoedge\Ai\Services\Chat\Support\MessageTraceObserver;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single home of chat-turn orchestration for both ask and regenerate, collapsed into one
 * private path parameterized by $persistUserMessage. Key guarantees:
 *  - the security gate runs as STEP 0, BEFORE any context processing or persistence;
 *  - injection becomes a per-request refusal (decline-and-continue) instead of a stored
 *    assistant message, returned as a failed ChatTurnResult;
 *  - NO transaction is held across the (multi-second) LLM calls: only the persistence writes
 *    are wrapped, in short DB::transactions — the user message before the AI call, then the
 *    context snapshot + assistant message together after it. A mid-turn failure is caught and
 *    recorded as a visible error message (the user message stays, so the turn can be retried);
 *  - the created assistant AiMessage is returned on the result so callers never re-query.
 *
 * Concrete, container-autowired (not bound, not interfaced) per the Sisc UseCase convention.
 * AI is resolved via the facade (AiChatService is config-`new`ed and cannot inject it).
 */
class AskQuestionUseCase
{
    /**
     * The ONLY AiManager::answerQuestion options a caller may add through
     * AskQuestionInput::$pipelineOptions. Everything else (skip_security,
     * allow_write, read_only, limit, capture_prompts, ...) is dropped: a chat
     * turn must never be able to weaken the pipeline's security or limits.
     */
    public const ALLOWED_PIPELINE_OPTIONS = ['store_examples'];

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
        // The turn trace (Phase 4.2) starts BEFORE the gate so the gate's timing is on it.
        $trace = $this->newTrace();
        $observer = $this->turnObserver($trace, $input->observer);

        // STEP 0 — security gate FIRST (precondition + injection scan), before any context
        // processing, persistence, or even opening a transaction. A missing-user precondition
        // throws; a detected injection becomes a per-request refusal (decline-and-continue).
        if (!$this->passesGate($observer, $input->question, $input->user, $input->allowAnonymous)) {
            return ChatTurnResult::failure('ai.chat.injection-blocked');
        }

        return $this->runTurn(
            conversation: $input->conversation,
            question: $input->question,
            style: $input->style,
            user: $input->user,
            persistUserMessage: true,
            regenerated: false,
            pageContext: $input->pageContext?->toArray(),
            trace: $trace,
            observer: $observer,
            pipelineOptions: $input->pipelineOptions,
        );
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

        $trace = $this->newTrace();
        $observer = $this->turnObserver($trace, null);

        // STEP 0 — same security gate and ordering as execute(), before any persistence.
        if (!$this->passesGate($observer, $question, $user, $allowAnonymous)) {
            return ChatTurnResult::failure('ai.chat.injection-blocked');
        }

        return $this->runTurn(
            conversation: $conversation,
            question: $question,
            style: $style,
            user: $user,
            persistUserMessage: false,
            regenerated: true,
            trace: $trace,
            observer: $observer,
        );
    }

    /**
     * A fresh production turn trace, or null when ai.trace.enabled is off.
     */
    private function newTrace(): ?MessageTraceObserver
    {
        return config('ai.trace.enabled', true) ? MessageTraceObserver::fromConfig() : null;
    }

    /**
     * The security gate, observed as the trace's 'security_gate' stage. Behavior is
     * exactly assertSafe(): a precondition failure still throws, an injection still
     * returns false.
     */
    private function passesGate(?PipelineObserverInterface $observer, string $question, mixed $user, bool $allowAnonymous): bool
    {
        $observer?->stageStarted('security_gate');
        $start = hrtime(true);

        try {
            $safe = $this->securityGuard->assertSafe($question, $user, $allowAnonymous);
        } catch (\Throwable $e) {
            $observer?->stageError('security_gate', $e);
            throw $e;
        }

        $observer?->stageFinished('security_gate', ['passed' => $safe], $this->elapsedMs($start));

        return $safe;
    }

    /**
     * The turn observer as every pipeline call sees it: the persisted trace plus the
     * caller's optional extra observer, behind the isolation guard, so an observer
     * bug can never break or change the turn. Null when there is nothing to observe.
     */
    private function turnObserver(?MessageTraceObserver $trace, ?PipelineObserverInterface $extra): ?PipelineObserverInterface
    {
        $observer = CompositePipelineObserver::of($trace, $extra);

        return $observer !== null ? GuardedPipelineObserver::wrap($observer) : null;
    }

    /**
     * The persisted trace. Serialization failure never costs the answer.
     *
     * @return array<string, mixed>
     */
    private function traceArray(MessageTraceObserver $trace): array
    {
        try {
            return $trace->toArray();
        } catch (\Throwable $e) {
            Log::debug('AI turn trace could not be serialized', ['error' => $e->getMessage()]);

            return ['version' => 1, 'error' => 'trace_failed'];
        }
    }

    private function elapsedMs(int|float $start): float
    {
        return round((hrtime(true) - $start) / 1e6, 3);
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
        ?array $pageContext = null,
        ?MessageTraceObserver $trace = null,
        ?PipelineObserverInterface $observer = null,
        array $pipelineOptions = []
    ): ChatTurnResult {
        $maxHistory = (int) config('ai.chat.max_history_messages', 10);
        // The trace stage (and its start) a mid-turn failure is attributed to.
        $stage = 'conversation_context';
        $stageStart = hrtime(true);

        try {
            $observer?->stageStarted('conversation_context');

            // The conversation context the pipeline (and its QU call) receives: the
            // snapshot recorded after the PREVIOUS turn plus recent exchanges. No
            // pre-pipeline question analysis runs here (Phase 2.B) — follow-up
            // judgment, entity focus and intent of THIS question are QU's
            // (QueryUnderstandingResult), and the question is never rewritten.
            // For regenerate the user message already exists.
            $conversationContext = $this->contextManager->buildPromptContext($conversation, $maxHistory);
            if ($persistUserMessage) {
                // Short write-only transaction: the user message row + its conversation touch.
                DB::transaction(fn () => $conversation->addMessage('user', $question, [
                    'context_used' => $conversationContext,
                ]));
            }
            $observer?->stageFinished('conversation_context', [], $this->elapsedMs($stageStart));

            // Call AI with full conversation context. page_context (the soft prior for which
            // page/record the chat was opened from) rides alongside as the PageContext array;
            // it is null when the chat was not opened from a page, keeping the turn unchanged.
            // The (guarded) trace rides options['observer'] (observation only — AiManager
            // removes it before any downstream service sees the options).
            $stage = 'pipeline';
            $stageStart = hrtime(true);
            // The question the pipeline runs with (the user's wording, verbatim) rides
            // the stage-start data — in-memory observers only, never persisted.
            $observer?->stageStarted('pipeline', ['question' => $question]);
            // Caller pipeline options: allowlisted keys only (ALLOWED_PIPELINE_OPTIONS),
            // and they never override the turn's own keys.
            $pipelineOptions = array_intersect_key($pipelineOptions, array_flip(self::ALLOWED_PIPELINE_OPTIONS));
            $aiResponse = AI::answerQuestion($question, [
                'style' => $style,
                'conversation_id' => $conversation->id,
                'conversation_context' => $conversationContext,
                'page_context' => $pageContext,
                'user' => $user,
            ] + ($observer !== null ? ['observer' => $observer] : []) + $pipelineOptions);
            $observer?->stageFinished('pipeline', [], $this->elapsedMs($stageStart));
            $stage = 'persistence';
            $stageStart = hrtime(true);

            $answerText = $aiResponse['answer'] ?? '';
            $cypherQuery = $aiResponse['cypher'] ?? null;
            $queryData = $aiResponse['data'] ?? [];
            $referencedFiles = $aiResponse['referenced_files'] ?? [];

            // The links/actions CODE attached to the answer (actions plan B1–B4:
            // ActionAttacher → metadata.response.invocations, ActionInvocation::toArray() rows)
            // are persisted on the assistant message so every render re-dispatches them —
            // fresh authorize(), fresh approval token (MessageBubbleRenderer).
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
            // The document's rows as data (Phase 8 batch A): the Excel document export
            // writes them directly instead of re-parsing the markdown table.
            $exportDocumentRows = $aiResponse['metadata']['response']['export_document_rows'] ?? null;
            if (is_array($exportDocumentRows) && $exportDocumentRows !== []) {
                $messageMetadata['export_document_rows'] = array_values($exportDocumentRows);
                // …and the header each column carries in the document (column key =>
                // header): the file is written later, from this message alone.
                $exportDocumentHeaders = $aiResponse['metadata']['response']['export_document_headers'] ?? null;
                if (is_array($exportDocumentHeaders) && $exportDocumentHeaders !== []) {
                    $messageMetadata['export_document_headers'] = $exportDocumentHeaders;
                }
            }

            // The `$pii_…` parameters this turn's query bound ride the MESSAGE too (its text
            // is the cypher_query column below): the export chip of a capped list re-runs
            // the query recorded with the message it is clicked on (RecordedResultExport) —
            // possibly many turns later, when the snapshot holds another turn's query.
            // Only bindings of the pipeline's own shape are kept (RecordedQueryRerun).
            $cypherParameters = RecordedQueryRerun::boundParameters($aiResponse['cypher_parameters'] ?? null);
            if ($cypherParameters !== []) {
                $messageMetadata['cypher_parameters'] = $cypherParameters;
            }

            // Record response: single context-snapshot write (even without cypher), so
            // follow-ups can resolve referenced files / entities. Metadata rides along
            // so the snapshot contracts the composers stamp actually hold end-to-end:
            // metadata.subject (the distilled subject that titles a follow-up export)
            // and metadata.response.digest (marks the rows as a full display-capped
            // set the snapshot keeps whole for the "export that" follow-up).
            // The snapshot write and the assistant message commit together (short, write-only
            // transaction AFTER the LLM calls) so a turn never records context without its answer.
            $assistantMessage = DB::transaction(function () use (
                $conversation, $answerText, $cypherQuery, $queryData, $referencedFiles,
                $aiResponse, $regenerated, $messageMetadata, $trace
            ) {
                $this->contextManager->recordResponse(
                    $conversation,
                    $answerText,
                    $cypherQuery ?? '',
                    [
                        'data' => $queryData,
                        'referenced_files' => $referencedFiles,
                        'metadata' => is_array($aiResponse['metadata'] ?? null) ? $aiResponse['metadata'] : [],
                        // P-1: `$pii_…` parameters of the recorded cypher (snapshot only).
                        'cypher_parameters' => is_array($aiResponse['cypher_parameters'] ?? null) ? $aiResponse['cypher_parameters'] : [],
                    ]
                );

                // The turn trace (size-capped) rides the assistant message metadata.
                if ($trace !== null) {
                    $messageMetadata['trace'] = $this->traceArray($trace);
                }

                // Persist the assistant message and keep the row so callers never re-query.
                return $conversation->addMessage('assistant', $answerText, [
                    'response_data' => $queryData,
                    'cypher_query' => $cypherQuery,
                    'referenced_files' => $referencedFiles,
                    'regenerated' => $regenerated,
                    'metadata' => $messageMetadata,
                ]);
            });

            // ABSTENTION: no data AND no referenced files → flag the turn as degraded so the
            // presenter can surface it as a low-confidence / fallback answer.
            $degraded = empty($queryData) && empty($referencedFiles);

            return new ChatTurnResult(
                answer: $answerText,
                data: $queryData,
                sources: $referencedFiles,
                cypher: $cypherQuery,
                assistantMessage: $assistantMessage,
                degraded: $degraded,
                metadata: is_array($aiResponse['metadata'] ?? null) ? $aiResponse['metadata'] : [],
            );
        } catch (\Throwable $e) {
            Log::error('AI chat turn error', [
                'conversation_id' => $conversation->id,
                'regenerated' => $regenerated,
                'error' => $e->getMessage(),
            ]);

            // Type-based mapping shared with ChatPresenter, translated in the app locale
            // (this text is STORED as the assistant message).
            $errorText = FriendlyError::message($e);

            // The failed turn keeps its trace: the stage it died in + the error.
            $observer?->stageError($stage, $e);
            $observer?->stageFinished($stage, ['failed' => true], $this->elapsedMs($stageStart));

            // Record a visible error message in the conversation and return it as a failed
            // turn carrying that message.
            $assistantMessage = $conversation->addMessage('assistant', $errorText, [
                'error' => true,
                'regenerated' => $regenerated,
            ] + ($trace !== null ? ['metadata' => ['trace' => $this->traceArray($trace)]] : []));

            return ChatTurnResult::failure($errorText, $assistantMessage);
        }
    }
}
