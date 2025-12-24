<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Represents a single message in the AI chat conversation.
 */
class AiChatMessage implements Arrayable, JsonSerializable
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM = 'system';

    public function __construct(
        public readonly string $id,
        public readonly string $role,
        public readonly string $content,
        public readonly ?string $timestamp = null,
        public readonly array $metadata = [],
        public readonly ?AiChatResponseData $responseData = null,
    ) {
    }

    /**
     * Create a user message.
     */
    public static function user(string $content, array $metadata = []): self
    {
        return new self(
            id: self::generateId(),
            role: self::ROLE_USER,
            content: $content,
            timestamp: now()->toIso8601String(),
            metadata: $metadata,
        );
    }

    /**
     * Create an assistant message.
     */
    public static function assistant(
        string $content,
        ?AiChatResponseData $responseData = null,
        array $metadata = []
    ): self {
        return new self(
            id: self::generateId(),
            role: self::ROLE_ASSISTANT,
            content: $content,
            timestamp: now()->toIso8601String(),
            metadata: $metadata,
            responseData: $responseData,
        );
    }

    /**
     * Create a system message.
     */
    public static function system(string $content): self
    {
        return new self(
            id: self::generateId(),
            role: self::ROLE_SYSTEM,
            content: $content,
            timestamp: now()->toIso8601String(),
        );
    }

    /**
     * Create from array (for deserialization).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? self::generateId(),
            role: $data['role'],
            content: $data['content'],
            timestamp: $data['timestamp'] ?? null,
            metadata: $data['metadata'] ?? [],
            responseData: isset($data['responseData'])
                ? AiChatResponseData::fromArray($data['responseData'])
                : null,
        );
    }

    /**
     * Generate a unique message ID.
     */
    protected static function generateId(): string
    {
        return 'msg_' . uniqid() . '_' . bin2hex(random_bytes(4));
    }

    /**
     * Check if this is a user message.
     */
    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    /**
     * Check if this is an assistant message.
     */
    public function isAssistant(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;
    }

    /**
     * Get formatted timestamp.
     */
    public function getFormattedTime(string $format = 'g:i A'): string
    {
        if (!$this->timestamp) {
            return '';
        }

        return \Carbon\Carbon::parse($this->timestamp)->format($format);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'timestamp' => $this->timestamp,
            'metadata' => $this->metadata,
            'responseData' => $this->responseData?->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
