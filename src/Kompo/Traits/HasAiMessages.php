<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Traits;

use Condoedge\Ai\Services\Chat\AiChatMessage;

/**
 * Trait for managing chat message history.
 */
trait HasAiMessages
{
    protected array $messages = [];

    /**
     * Load conversation history from session.
     */
    protected function loadHistory(): void
    {
        if (!$this->persistHistory) {
            return;
        }

        $sessionKey = $this->getSessionKey();
        $history = session($sessionKey, []);

        $this->messages = array_map(
            fn(array $data) => AiChatMessage::fromArray($data),
            $history
        );

        // Trim to max messages
        if (count($this->messages) > $this->maxMessages) {
            $this->messages = array_slice($this->messages, -$this->maxMessages);
            $this->saveHistory();
        }
    }

    /**
     * Save conversation history to session.
     */
    protected function saveHistory(): void
    {
        if (!$this->persistHistory) {
            return;
        }

        $sessionKey = $this->getSessionKey();
        $history = array_map(
            fn(AiChatMessage $msg) => $msg->toArray(),
            $this->messages
        );

        session([$sessionKey => $history]);
    }

    /**
     * Add a message to the conversation.
     */
    protected function addMessage(AiChatMessage $message): void
    {
        $this->messages[] = $message;

        // Trim old messages
        if (count($this->messages) > $this->maxMessages) {
            $this->messages = array_slice($this->messages, -$this->maxMessages);
        }

        $this->saveHistory();
    }

    /**
     * Clear conversation history.
     */
    protected function clearHistory(): void
    {
        $this->messages = [];

        if ($this->persistHistory) {
            session()->forget($this->getSessionKey());
        }
    }

    /**
     * Get all messages.
     */
    protected function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Check if conversation has messages.
     */
    protected function hasMessages(): bool
    {
        return !empty($this->messages);
    }

    /**
     * Get messages as array for API/serialization.
     */
    protected function getMessagesArray(): array
    {
        return array_map(
            fn(AiChatMessage $msg) => $msg->toArray(),
            $this->messages
        );
    }
}
