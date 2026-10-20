<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\Inputs;

use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\Models\AiConversation;

/**
 * Immutable input for AskQuestionUseCase::execute().
 *
 * A constructor-promoted readonly DTO (modeled on Sisc's RegisterScoutDTO) that carries
 * everything the use case needs to run a chat turn without reaching into request()/Kompo:
 * the raw question, the conversation it belongs to, the requested response style, the
 * acting user (for file-access authorization + injection logging), an explicit
 * allow-anonymous opt-in for public queries, and the optional page context (the soft prior
 * for "which page/record the chat was opened from").
 */
final class AskQuestionInput
{
    /**
     * @param string            $question       The user's raw question text.
     * @param AiConversation    $conversation   The conversation for context tracking + persistence.
     * @param string            $style          Response style (friendly|professional|concise|...).
     * @param mixed             $user           The acting user (Authenticatable|null). Null only allowed when $allowAnonymous.
     * @param bool              $allowAnonymous Explicit opt-in to run without a user (public queries).
     * @param PageContext|null  $pageContext    The page/record the chat was opened from. Null (default)
     *                                          keeps every existing call site working unchanged.
     * @param PipelineObserverInterface|null $observer An ADDITIONAL observer of the whole turn
     *                                          (security gate through persistence), fed beside the
     *                                          persisted turn trace — observation only (e.g. the
     *                                          dev-only eval harness recording verbatim prompts).
     * @param array<string, mixed> $pipelineOptions Extra AiManager::answerQuestion options, filtered
     *                                          to AskQuestionUseCase::ALLOWED_PIPELINE_OPTIONS
     *                                          (today only store_examples); every other key
     *                                          (skip_security, allow_write, read_only, limit, ...)
     *                                          is silently dropped. They never override the turn's
     *                                          own keys (style, user, conversation, page context).
     */
    public function __construct(
        public readonly string $question,
        public readonly AiConversation $conversation,
        public readonly string $style = 'friendly',
        public readonly mixed $user = null,
        public readonly bool $allowAnonymous = false,
        public readonly ?PageContext $pageContext = null,
        public readonly ?PipelineObserverInterface $observer = null,
        public readonly array $pipelineOptions = [],
    ) {
    }
}
