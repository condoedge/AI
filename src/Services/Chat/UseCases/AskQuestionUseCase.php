<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\UseCases;

use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Models\AiTask;
use Condoedge\Ai\Services\Actions\BuiltIn\StartTaskAction;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Tasks\TaskOffer;
use Condoedge\Ai\Services\Tasks\TaskOrchestrator;
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
use Condoedge\Ai\Support\Audiences;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single home of chat-turn orchestration for both ask and regenerate, collapsed into one
 * private path parameterized by $persistUserMessage. Key guarantees:
 *  - the security gate runs as STEP 0, BEFORE any context processing or persistence;
 *  - injection becomes a per-request refusal (decline-and-continue): a translated assistant
 *    message the user sees, returned as a failed ChatTurnResult; the blocked text itself is
 *    never stored, so no later turn reads it;
 *  - NO transaction is held across the (multi-second) LLM calls: only the persistence writes
 *    are wrapped, in short DB::transactions — the user message before the AI call, then the
 *    context snapshot + assistant message together after it. A mid-turn failure is caught and
 *    recorded as a visible error message (the user message stays, so the turn can be retried);
 *  - the created assistant AiMessage is returned on the result so callers never re-query.
 *
 * Tasks (ai.tasks): an active task takes the turn (TaskOrchestrator), except a message its step
 * reads as about something else, which the pipeline answers with the task's pending question put
 * under the answer. An answer whose chips start tasks gets the offer line (TaskOffer, no model
 * call); the next message only, while no task runs, makes ONE small call that may start the task
 * with that message as its first answer, else the turn runs the pipeline as usual. A task past its
 * lifetime is expired first: the pipeline answers the message, with one line saying the task had
 * stopped and a chip that starts it again.
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

    /** @param ?TaskOrchestrator $tasks null unless ai.tasks is on with a definition (AiServiceProvider); $offers likewise */
    public function __construct(
        private readonly ConversationContextManager $contextManager,
        private readonly ChatSecurityGuard $securityGuard,
        private readonly ?TaskOrchestrator $tasks = null,
        private readonly ?TaskOffer $offers = null,
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
            return $this->refusal($input->conversation);
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
    /** Without a stored reply the panel refresh dropped the user's bubble and showed nothing. */
    private function refusal(AiConversation $conversation): ChatTurnResult
    {
        $text = __('ai.chat.injection-blocked');
        $message = $conversation->addMessage('assistant', $text, ['error' => true, 'metadata' => ['status' => 'injection_blocked']]);

        return new ChatTurnResult(answer: $text, assistantMessage: $message, error: 'ai.chat.injection-blocked');
    }

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
     * A turn of an active task, admitted against the same rate budget as a pipeline turn
     * (AiManager::admitTurn); a full budget answers "busy" before any model call.
     *
     * @return array<string, mixed> the standard response array
     */
    private function taskTurn(AiTask $task, string $question, mixed $user, ?PipelineObserverInterface $observer): array
    {
        return $this->busy($question, $user) ?? $this->tasks->handleTurn($task, $question, ['user' => $user, 'observer' => $observer]);
    }

    /** The "busy" response when the turn's rate budget is full, else null (admitted). */
    private function busy(string $question, mixed $user): ?array
    {
        $executor = app(QueryExecutorInterface::class);
        if ($executor instanceof QueryExecutor) {
            $admission = $executor->admit(
                ['user' => $user, 'rate_limit_batch' => 'task:' . bin2hex(random_bytes(8))],
                max(0, (int) config('ai.rate_limits.max_wait_seconds', 20))
            );
            if (!$admission['admitted']) {
                $seconds = max(1, (int) $admission['retry_after']);

                return [
                    'question' => $question,
                    'answer' => trans('ai.error.busy_try_again', ['seconds' => $seconds]),
                    'cypher' => null,
                    'data' => [],
                    'referenced_files' => [],
                    'metadata' => ['query' => ['source' => 'admission'], 'response' => ['status' => 'rate_limited', 'retry_after' => $seconds, 'error' => true]],
                ];
            }
        }

        return null;
    }

    /** @return list<string> the tasks the conversation's last message, an answer, offered that this user may still start */
    private function offeredBefore(AiConversation $conversation, mixed $user): array
    {
        $last = AiMessage::where('conversation_id', $conversation->getKey())->orderByDesc('id')->first();

        return $last?->role === 'assistant' && is_array($last->metadata['invocations'] ?? null) ? TaskOffer::keys($last->metadata['invocations'], $user) : [];
    }

    /**
     * The reply to an offer: the task it takes up starts, reading the reply as its first answer (the reply
     * is marked as the task's); null when it takes none up, and the pipeline answers.
     *
     * @param list<string> $offered
     */
    private function offerReply(AiConversation $conversation, ?AiMessage $reply, array $offered, string $question, mixed $user,
        ?PipelineObserverInterface $observer): ?array
    {
        $observer?->stageStarted(TaskOffer::STAGE);
        $start = hrtime(true);
        $key = $this->offers->taken($offered, $user, $question, $observer);
        $observer?->stageFinished(TaskOffer::STAGE, ['offered' => $offered, 'taken' => $key], $this->elapsedMs($start));
        if ($key === null) {
            return null;
        }
        if ($busy = $this->busy($question, $user)) {
            return $busy;
        }
        try {
            $response = $this->tasks->start($user, $conversation, $key, ['observer' => $observer, 'message' => $question, 'reason' => 'offer_reply']);
        } catch (AuthorizationException) {
            return null;
        }
        if ($reply !== null && is_string($uuid = $response['metadata']['task']['uuid'] ?? null)) {
            $reply->update(['metadata' => ['task' => ['uuid' => $uuid]] + (array) $reply->metadata]);
        }

        return $response;
    }

    /** An answer whose chips start tasks the user may start says so, in one line under it. */
    private function withOffer(array $response, mixed $user): array
    {
        $language = $response['metadata']['question_language'] ?? null;
        $line = TaskOffer::line(TaskOffer::keys((array) ($response['metadata']['response']['invocations'] ?? []), $user), is_string($language) ? $language : null);
        if ($line !== '' && trim((string) ($response['answer'] ?? '')) !== '') {
            $response['answer'] = rtrim((string) $response['answer']) . "\n\n" . $line;
        }

        return $response;
    }

    /**
     * The answer to the first message after a task stopped past its lifetime: one line says it stopped and nothing
     * was changed, and a chip starts it again (unless the answer already offers it), while this user may start it.
     */
    private function withStopped(array $response, AiTask $task, mixed $user): array
    {
        $definition = $this->tasks->definition($task->definition_key);
        if ($definition === null || trim((string) ($response['answer'] ?? '')) === '') {
            return $response;
        }
        $language = $response['metadata']['question_language'] ?? null;
        $locale = is_string($language) ? $language : null;
        $response['answer'] = rtrim((string) $response['answer']) . "\n\n"
            . __('ai.tasks.expired-on-return', ['task' => __($definition->label(), [], $locale)], $locale);
        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        $metadata['response'] = is_array($metadata['response'] ?? null) ? $metadata['response'] : [];
        $invocations = is_array($metadata['response']['invocations'] ?? null) ? $metadata['response']['invocations'] : [];
        $chip = StartTaskAction::invocation($task->definition_key, $user);
        if ($chip !== null && !in_array($task->definition_key, TaskOffer::keys($invocations, $user), true)) {
            $metadata['response']['invocations'] = [...$invocations, $chip->toArray()];
            $response['metadata'] = $metadata;
        }

        return $response;
    }

    /** A question asked during a task: the pipeline's answer, then the question the task waits on, with the task's state. */
    private function withPending(array $response, array $task): array
    {
        $response['answer'] = trim(rtrim((string) ($response['answer'] ?? '')) . "\n\n" . $task['answer']);
        $response['metadata'] = (is_array($response['metadata'] ?? null) ? $response['metadata'] : []);
        $response['metadata']['task'] = $task['metadata']['task'];

        return $response;
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
            // A task past its lifetime ends here, so this message goes to the pipeline (with one line saying so).
            $stopped = $this->tasks?->expireStale($conversation, $user) ?? [];
            $task = $this->tasks?->claims($conversation);
            // Read before this message is stored: the answer it replies to may have offered tasks.
            $offered = $task === null && $persistUserMessage && $this->tasks !== null && $this->offers !== null ? $this->offeredBefore($conversation, $user) : [];
            $userMessage = null;
            if ($persistUserMessage) {
                // Short write-only transaction: the user message row + its conversation touch.
                // An answer to a task is marked, so the chat hides edit on it.
                $userMessage = DB::transaction(fn () => $conversation->addMessage('user', $question, [
                    'context_used' => $conversationContext,
                ] + ($task !== null ? ['metadata' => ['task' => ['uuid' => $task->uuid]]] : [])));
            }
            $observer?->stageFinished('conversation_context', [], $this->elapsedMs($stageStart));

            // Call AI with full conversation context. page_context (the soft prior for which
            // page/record the chat was opened from) rides alongside as the PageContext array;
            // it is null when the chat was not opened from a page, keeping the turn unchanged.
            // The (guarded) trace rides options['observer'] (observation only — AiManager
            // removes it before any downstream service sees the options).
            // Who asked, as the host resolved it (ai.audiences): on the trace and the message.
            $audience = Audiences::configured() ? Audiences::of($user) : null;
            $audienceStamp = Audiences::configured() ? ['key' => $audience?->key, 'role' => $audience?->role] : null;
            if ($audienceStamp !== null) {
                $observer?->stageStarted('audience');
                $observer?->stageFinished('audience', ['audience' => $audienceStamp['key'], 'role' => $audienceStamp['role']]);
            }

            $stage = 'pipeline';
            $stageStart = hrtime(true);
            // The question the pipeline runs with (the user's wording, verbatim) rides
            // the stage-start data — in-memory observers only, never persisted.
            $observer?->stageStarted('pipeline', ['question' => $question]);
            // Caller pipeline options: allowlisted keys only (ALLOWED_PIPELINE_OPTIONS),
            // and they never override the turn's own keys.
            $pipelineOptions = array_intersect_key($pipelineOptions, array_flip(self::ALLOWED_PIPELINE_OPTIONS));
            $pipeline = fn () => AI::answerQuestion($question, [
                'style' => $style,
                'conversation_id' => $conversation->id,
                'conversation_context' => $conversationContext,
                'page_context' => $pageContext,
                'user' => $user,
            ] + ($observer !== null ? ['observer' => $observer] : []) + $pipelineOptions);
            if ($task !== null) {
                // An active task takes the whole turn (never QU): its own step call, its own audit.
                $aiResponse = $this->taskTurn($task, $question, $user, $observer);
                if (($aiResponse['metadata']['task']['side_question'] ?? false) === true) {
                    $aiResponse = $this->withPending($pipeline(), $aiResponse);
                }
            } else {
                $aiResponse = ($offered !== [] ? $this->offerReply($conversation, $userMessage, $offered, $question, $user, $observer) : null)
                    ?? $this->withOffer($pipeline(), $user);
                if ($stopped !== [] && !is_array($aiResponse['metadata']['task'] ?? null)) {
                    $aiResponse = $this->withStopped($aiResponse, end($stopped), $user);
                }
            }
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
            $messageMetadata = $audienceStamp !== null ? ['audience' => $audienceStamp] : [];
            if ($invocations !== []) {
                $messageMetadata['invocations'] = $invocations;
            }
            if (is_array($aiResponse['metadata']['task'] ?? null)) {
                $messageMetadata['task'] = $aiResponse['metadata']['task'];
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
                $aiResponse, $regenerated, $messageMetadata, $trace, $question
            ) {
                $this->contextManager->recordResponse(
                    $conversation,
                    $answerText,
                    $cypherQuery ?? '',
                    [
                        'question' => $question,
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
