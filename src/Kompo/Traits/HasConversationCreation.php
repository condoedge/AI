<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Traits;

use Condoedge\Ai\Models\AiConversation;

/**
 * Provides conversation creation functionality for chat components.
 *
 * Components using this trait can create new conversations via createNewConversation()
 * and expose a selfPost-compatible createConversation() method.
 *
 * Used by:
 * - AiChatPanel: sidebar "+" button
 * - MessagesQuery: emptyState "Start New" button
 * - ChatMessageForm: auto-create on first message
 */
trait HasConversationCreation
{
    /**
     * Create a new conversation and set it as the selected conversation in session.
     *
     * @return AiConversation The newly created conversation
     * @throws \RuntimeException If auth is not available
     */
    protected function createNewConversation(): AiConversation
    {
        // Require auth to be available for conversation creation
        if (!$this->isAuthAvailableForConversation()) {
            throw new \RuntimeException('Authentication required to create conversation');
        }

        $conversation = AiConversation::create([
            'user_id' => auth()->id(),
            'team_id' => currentTeamId(),
            'status' => 'active',
            'title' => __('ai.chat.new-conversation'),
        ]);

        session(['selected_conversation_id' => $conversation->id]);

        return $conversation;
    }

    /**
     * Create a new conversation (selfPost-compatible method).
     *
     * This method is called by selfPost('createConversation') from UI buttons.
     * It wraps createNewConversation() for backward compatibility.
     */
    public function createConversation(): void
    {
        $this->createNewConversation();
    }

    /**
     * Check if authentication service is available.
     *
     * During early container resolution (e.g., before AuthServiceProvider),
     * the 'auth' binding may not exist yet.
     */
    protected function isAuthAvailableForConversation(): bool
    {
        return app()->bound('auth');
    }
}
