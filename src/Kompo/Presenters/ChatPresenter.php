<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Presenters;

use Condoedge\Ai\Exceptions\CircuitBreakerOpenException;
use Condoedge\Ai\Exceptions\QueryExecutionException;
use Condoedge\Ai\Exceptions\QueryGenerationException;
use Condoedge\Ai\Exceptions\QueryTimeoutException;
use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Chat\Inputs\AskQuestionInput;
use Condoedge\Ai\Services\Chat\Results\ChatTurnResult;
use Condoedge\Ai\Services\Chat\UseCases\AskQuestionUseCase;
use Condoedge\Ai\Services\Chat\UseCases\EditMessageAndRegenerateUseCase;
use Condoedge\Ai\Services\Chat\UseCases\RegenerateAnswerUseCase;
use Illuminate\Database\QueryException;

/**
 * The single Kompo <-> application seam for chat actions (modeled on Sisc's
 * InscriptionApprovalPresenter): a plain, container-autowired class with NO base and NO
 * interface, resolved via app(ChatPresenter::class).
 *
 * The presenter keeps the use cases (domain orchestration) isolated from Kompo components.
 * Components call one handleX() per action; the presenter delegates to a use case and hands
 * back the immutable ChatTurnResult. The presenter is also the home of user-facing error
 * mapping (friendlyError), which classifies failures by EXCEPTION TYPE — not by message
 * substring like the old AiChatService::getUserFriendlyError, whose English-keyword matching
 * silently breaks for French (and any non-English) exception messages.
 *
 * Unlike Sisc's presenter, chat send does not return a Kompo response array: the live UI
 * shows an optimistic JS placeholder and then re-renders the messages panel from the DB via
 * ->refresh(). So handleSend returns the ChatTurnResult and lets the component drive the
 * Kompo refresh; on a failed() result the component surfaces friendlyError.
 */
class ChatPresenter
{
    public function __construct(
        protected AskQuestionUseCase $askQuestionUseCase,
        protected RegenerateAnswerUseCase $regenerateAnswerUseCase,
        protected EditMessageAndRegenerateUseCase $editMessageAndRegenerateUseCase,
    ) {
    }

    /**
     * Handle a "send message" action.
     *
     * Runs AskQuestionUseCase within the conversation and returns the ChatTurnResult so the
     * calling component can drive the Kompo refresh (the user/assistant messages are persisted
     * and re-rendered from the DB). On $result->failed() the caller surfaces friendlyError.
     *
     * @param AiConversation   $conversation The conversation to append the turn to.
     * @param string           $message      The user's raw message text.
     * @param string           $style        Response style (friendly|professional|concise|...).
     * @param mixed            $user         The acting user (Authenticatable|null).
     * @param PageContext|null $pageContext  The page/record the chat was opened from. Null
     *                                       (default) keeps every existing caller working unchanged.
     */
    public function handleSend(
        AiConversation $conversation,
        string $message,
        string $style,
        mixed $user,
        ?PageContext $pageContext = null
    ): ChatTurnResult {
        return $this->askQuestionUseCase->execute(new AskQuestionInput(
            question: $message,
            conversation: $conversation,
            style: $style,
            user: $user,
            pageContext: $pageContext,
        ));
    }

    /**
     * Handle a "regenerate answer" action for an existing user message.
     *
     * Delegates to RegenerateAnswerUseCase (which owns trailing-message truncation) and
     * returns the ChatTurnResult unchanged.
     *
     * @param AiConversation $conversation The conversation containing the message.
     * @param AiMessage      $userMessage  The user message to regenerate from.
     * @param string         $style        Response style.
     * @param mixed          $user         The acting user (Authenticatable|null).
     */
    public function handleRegenerate(
        AiConversation $conversation,
        AiMessage $userMessage,
        string $style,
        mixed $user
    ): ChatTurnResult {
        return $this->regenerateAnswerUseCase->execute(
            conversation: $conversation,
            userMessage: $userMessage,
            style: $style,
            user: $user,
        );
    }

    /**
     * Handle an "edit message and regenerate" action.
     *
     * Delegates to EditMessageAndRegenerateUseCase (update content + truncate + regenerate in
     * one transaction) and returns the ChatTurnResult unchanged.
     *
     * @param AiConversation $conversation The conversation containing the message.
     * @param AiMessage      $userMessage  The user message being edited.
     * @param string         $newContent   The new content for the user message.
     * @param string         $style        Response style.
     * @param mixed          $user         The acting user (Authenticatable|null).
     */
    public function handleEditAndRegenerate(
        AiConversation $conversation,
        AiMessage $userMessage,
        string $newContent,
        string $style,
        mixed $user
    ): ChatTurnResult {
        return $this->editMessageAndRegenerateUseCase->execute(
            conversation: $conversation,
            userMessage: $userMessage,
            newContent: $newContent,
            style: $style,
            user: $user,
        );
    }

    /**
     * Map an exception to a user-facing translation KEY by EXCEPTION TYPE.
     *
     * This is the new home of AiChatService::getUserFriendlyError, reimplemented to classify
     * by exception type instead of by message substring. The old substring approach
     * (stripos($message, 'connection'|'timeout'|'api')) only works for English exception
     * messages; under a French locale (or any localized/driver-translated message) it
     * silently falls through to the generic case. Mapping by type is locale-independent.
     *
     * Returns a translation key ('ai.chat.error-connection' | 'ai.chat.error-timeout' |
     * 'ai.chat.error-generic') so the view (or caller) translates it via __() at render time.
     */
    public function friendlyError(\Throwable $e): string
    {
        // Database connection / query failures -> "having trouble connecting".
        // QueryException covers driver errors; ConnectionException / PDOException cover the
        // lower-level connect failures that may surface before a query is built.
        if (
            $e instanceof QueryException
            || $e instanceof \Illuminate\Database\ConnectionException
            || $e instanceof \PDOException
        ) {
            return 'ai.chat.error-connection';
        }

        // Query / request timeout -> "took too long, try a simpler question".
        if ($e instanceof QueryTimeoutException) {
            return 'ai.chat.error-timeout';
        }

        // Provider / LLM / query-pipeline failures and everything else -> generic guidance.
        // (QueryGenerationException, QueryExecutionException, CircuitBreakerOpenException, and
        // any unrecognized Throwable all resolve to the generic key.)
        if (
            $e instanceof QueryGenerationException
            || $e instanceof QueryExecutionException
            || $e instanceof CircuitBreakerOpenException
        ) {
            return 'ai.chat.error-generic';
        }

        return 'ai.chat.error-generic';
    }
}
