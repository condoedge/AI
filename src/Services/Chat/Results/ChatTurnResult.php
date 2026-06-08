<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\Results;

use Condoedge\Ai\Models\AiMessage;

/**
 * Immutable result of a single chat turn (ask / regenerate / edit-and-regenerate).
 *
 * Carries everything the presentation layer must branch on, so a Presenter never has
 * to re-query the database to recover the assistant message the use case just produced
 * (today ChatMessageForm.php:182-192 and EditMessageModal.php:200-204 re-query "latest
 * assistant message", which is race-prone). Soft/expected failures are represented as a
 * failed() result with a translation-key error; only programmer/precondition errors throw.
 *
 * Modeled on Sisc's readonly Result DTOs (e.g. App\Services\Inscriptions\Results\ApprovalResult).
 */
final class ChatTurnResult
{
    /**
     * @param string          $answer           The assistant answer text (or the user-facing error text when failed).
     * @param array            $data             Structured query/result data for rich rendering.
     * @param array            $suggestions      Follow-up suggestions.
     * @param array            $sources          Referenced files / citations.
     * @param string|null      $cypher           The executed Cypher (for metadata/debug only — never shown to users).
     * @param AiMessage|null   $assistantMessage The persisted assistant message row, so the view renders it directly.
     * @param string|null      $error            Translation key / message when this turn failed; null on success.
     * @param bool             $degraded         True when answered from an abstention/empty-context fallback rather than data.
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $data = [],
        public readonly array $suggestions = [],
        public readonly array $sources = [],
        public readonly ?string $cypher = null,
        public readonly ?AiMessage $assistantMessage = null,
        public readonly ?string $error = null,
        public readonly bool $degraded = false,
    ) {
    }

    /**
     * Build a failed result carrying a user-facing/i18n error string.
     */
    public static function failure(string $error, ?AiMessage $assistantMessage = null): self
    {
        return new self(
            answer: $error,
            assistantMessage: $assistantMessage,
            error: $error,
        );
    }

    /**
     * Whether this turn represents a soft failure the UI must surface as an error.
     */
    public function failed(): bool
    {
        return $this->error !== null;
    }
}
