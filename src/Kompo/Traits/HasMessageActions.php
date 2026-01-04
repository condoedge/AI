<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Traits;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\RegenerateMessageService;

/**
 * Provides message action methods (feedback, regenerate, edit) for chat components.
 *
 * These methods are called by staged proxy buttons via selfPost/selfGet.
 * Used by AiChatPanel, ChatMessageForm, EditMessageModal, and MessagesQuery.
 *
 * Requires: HasChatSettings trait to be present.
 */
trait HasMessageActions
{
    /**
     * Handle feedback for assistant message.
     * Called by staged proxy selfPost.
     */
    public function feedback($id, $type)
    {
        $conversation = $this->getConversationForAction();
        if (!$conversation) {
            return;
        }

        $message = $conversation->messages()->find($id);
        if ($message && $message->role === 'assistant') {
            $metadata = $message->metadata ?? [];
            $metadata['feedback'] = $type;
            $message->update(['metadata' => $metadata]);
        }
    }

    /**
     * Handle regenerate for assistant message.
     * Called by staged proxy selfPost.
     */
    public function regenerate($id)
    {
        $conversation = $this->getConversationForAction();
        if (!$conversation) {
            return;
        }

        $message = $conversation->messages()->find($id);
        if (!$message || $message->role !== 'assistant') {
            return;
        }

        try {
            // Use the same method as MessagesQuery
            $service = app(RegenerateMessageService::class);
            $service->regenerateFromAssistantMessage(
                $conversation,
                $message,
                auth()->user(),
                ['style' => $this->settings()->responseStyle()]
            );
        } catch (\Throwable $e) {
            \Log::error('Regenerate failed: ' . $e->getMessage());
        }
    }

    /**
     * Get the conversation for action methods.
     * Override this in your component if needed.
     */
    protected function getConversationForAction(): ?AiConversation
    {
        // Try common property names used across components
        if (property_exists($this, 'conversation') && $this->conversation) {
            return $this->conversation;
        }

        // Try to get from message relationship (EditMessageModal pattern)
        if (property_exists($this, 'message') && $this->message) {
            return $this->message->conversation;
        }

        // Try to get from session or prop
        $conversationId = $this->prop('conversation_id') ?? session('selected_conversation_id');
        if ($conversationId) {
            return AiConversation::where('user_id', auth()->id())->find($conversationId);
        }

        return null;
    }
}
