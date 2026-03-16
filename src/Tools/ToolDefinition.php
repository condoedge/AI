<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tools;

/**
 * Value object representing a tool that can be called by the LLM.
 */
class ToolDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        /** @var callable */
        private $handler,
        public readonly array $requiredPermissions = [],
        public readonly bool $requiresConfirmation = false,
    ) {}

    /**
     * Convert to OpenAI function calling schema (compatible with Ollama).
     */
    public function toOpenAiFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->parameters,
            ],
        ];
    }

    /**
     * Get the handler callable.
     */
    public function getHandler(): callable
    {
        return $this->handler;
    }
}
