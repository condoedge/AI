<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tools;

/**
 * Result of a tool calling loop execution.
 */
class ToolCallLoopResult
{
    public function __construct(
        public readonly string $finalResponse,
        public readonly array $toolCallHistory = [],
        public readonly int $iterations = 0,
        public readonly bool $maxIterationsReached = false,
    ) {}

    /**
     * Check if any tools were called during the loop.
     */
    public function hasToolCalls(): bool
    {
        return !empty($this->toolCallHistory);
    }

    /**
     * Get the names of all tools that were called.
     */
    public function getToolNames(): array
    {
        return array_unique(array_column($this->toolCallHistory, 'tool'));
    }
}
