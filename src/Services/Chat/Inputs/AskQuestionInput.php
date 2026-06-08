<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\Inputs;

use Condoedge\Ai\Models\AiConversation;

/**
 * Immutable input for AskQuestionUseCase::execute().
 *
 * A constructor-promoted readonly DTO (modeled on Sisc's RegisterScoutDTO) that carries
 * everything the use case needs to run a chat turn without reaching into request()/Kompo:
 * the raw question, the conversation it belongs to, the requested response style, the
 * acting user (for file-access authorization + injection logging), and an explicit
 * allow-anonymous opt-in for public queries.
 */
final class AskQuestionInput
{
    /**
     * @param string         $question       The user's raw question text.
     * @param AiConversation $conversation   The conversation for context tracking + persistence.
     * @param string         $style          Response style (friendly|professional|concise|...).
     * @param mixed          $user           The acting user (Authenticatable|null). Null only allowed when $allowAnonymous.
     * @param bool           $allowAnonymous Explicit opt-in to run without a user (public queries).
     */
    public function __construct(
        public readonly string $question,
        public readonly AiConversation $conversation,
        public readonly string $style = 'friendly',
        public readonly mixed $user = null,
        public readonly bool $allowAnonymous = false,
    ) {
    }
}
