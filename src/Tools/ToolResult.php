<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tools;

/**
 * Value object representing the result of a tool execution.
 */
class ToolResult
{
    public function __construct(
        public readonly string $toolName,
        public readonly string $toolCallId,
        public readonly string $status, // 'success', 'error', 'requires_confirmation'
        public readonly mixed $data = null,
        public readonly ?string $error = null,
    ) {}

    /**
     * Create a success result.
     */
    public static function success(string $toolName, string $toolCallId, mixed $data): self
    {
        return new self($toolName, $toolCallId, 'success', $data);
    }

    /**
     * Create an error result.
     */
    public static function error(string $toolName, string $toolCallId, string $error): self
    {
        return new self($toolName, $toolCallId, 'error', null, $error);
    }

    /**
     * Create a confirmation-required result.
     */
    public static function requiresConfirmation(string $toolName, string $toolCallId, mixed $data): self
    {
        return new self($toolName, $toolCallId, 'requires_confirmation', $data);
    }

    /**
     * Format as a tool response message for the LLM (OpenAI format).
     */
    public function toToolMessage(): array
    {
        $content = match ($this->status) {
            'success' => is_string($this->data) ? $this->data : json_encode($this->data),
            'error' => json_encode(['error' => $this->error]),
            'requires_confirmation' => json_encode([
                'status' => 'requires_confirmation',
                'message' => 'This operation requires user confirmation before execution.',
                'pending_data' => $this->data,
            ]),
        };

        return [
            'role' => 'tool',
            'tool_call_id' => $this->toolCallId,
            'content' => $content,
        ];
    }
}
