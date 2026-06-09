<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Support;

use Condoedge\Ai\Models\AiConversation;

/**
 * The single home for ownership-scoped conversation/message mutations triggered from the chat
 * panel: pin toggle, message feedback, archive, and delete.
 *
 * Before this collaborator existed, MessagesQuery scattered the same authorization rule inline
 * across four action methods as `where('user_id', auth()->id())`, with feedback going through a
 * slightly different `conversation->messages()->find()` scope. Centralizing them here gives a
 * single, consistent ownership gate: every mutation is scoped by the acting user passed in (not
 * the auth() global), so a conversation not owned by that user is silently a no-op rather than
 * mutated. Each method returns whether a row was found+mutated so callers can react if needed.
 *
 * Concrete and container-autowireable (no base class, no interface) per the package's UseCase /
 * presenter convention. MessagesQuery's public Kompo action methods stay as thin entry points
 * (Kompo resolves selfPost('feedback'|'togglePin'|...) against the component) and delegate here.
 */
class ConversationActions
{
    /**
     * Toggle the "pinned" flag on a conversation owned by $user.
     *
     * @return bool True when the conversation was found (and toggled), false otherwise.
     */
    public function togglePin(int $conversationId, mixed $user): bool
    {
        $conversation = $this->ownedConversation($conversationId, $user);
        if (!$conversation) {
            return false;
        }

        $metadata = $conversation->metadata ?? [];
        $metadata['pinned'] = !($metadata['pinned'] ?? false);
        $conversation->update(['metadata' => $metadata]);

        return true;
    }

    /**
     * Record feedback ('positive'|'negative') on a message inside a conversation owned by $user.
     *
     * Scoping the message lookup through the owned conversation prevents writing feedback to a
     * message in a conversation the acting user does not own.
     *
     * @return bool True when the message was found (and updated), false otherwise.
     */
    public function recordFeedback(int $conversationId, int $messageId, string $type, mixed $user): bool
    {
        $conversation = $this->ownedConversation($conversationId, $user);
        if (!$conversation) {
            return false;
        }

        $message = $conversation->messages()->find($messageId);
        if (!$message) {
            return false;
        }

        $metadata = $message->metadata ?? [];
        $metadata['feedback'] = $type;
        $message->update(['metadata' => $metadata]);

        return true;
    }

    /**
     * Archive a conversation owned by $user (sets status = 'archived').
     *
     * @return bool True when a row was archived, false otherwise.
     */
    public function archive(int $conversationId, mixed $user): bool
    {
        return AiConversation::query()
            ->where('user_id', $this->userId($user))
            ->where('id', $conversationId)
            ->update(['status' => 'archived']) > 0;
    }

    /**
     * Delete a conversation owned by $user.
     *
     * @return bool True when a row was deleted, false otherwise.
     */
    public function delete(int $conversationId, mixed $user): bool
    {
        return AiConversation::query()
            ->where('user_id', $this->userId($user))
            ->where('id', $conversationId)
            ->delete() > 0;
    }

    /**
     * Resolve a conversation only if it is owned by $user; null otherwise.
     */
    private function ownedConversation(int $conversationId, mixed $user): ?AiConversation
    {
        return AiConversation::query()
            ->where('user_id', $this->userId($user))
            ->find($conversationId);
    }

    /**
     * The acting user's id, or null when no user is present (gates everything to a no-op).
     */
    private function userId(mixed $user): mixed
    {
        return $user->id ?? null;
    }
}
