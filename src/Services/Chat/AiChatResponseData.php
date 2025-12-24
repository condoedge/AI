<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Rich response data for AI chat messages.
 * Contains structured data, actions, suggestions, and metrics.
 */
class AiChatResponseData implements Arrayable, JsonSerializable
{
    public const TYPE_TEXT = 'text';
    public const TYPE_TABLE = 'table';
    public const TYPE_LIST = 'list';
    public const TYPE_CARD = 'card';
    public const TYPE_METRIC = 'metric';
    public const TYPE_MIXED = 'mixed';
    public const TYPE_ERROR = 'error';

    public function __construct(
        public readonly string $type = self::TYPE_TEXT,
        public readonly mixed $data = null,
        public readonly array $actions = [],
        public readonly array $suggestions = [],
        public readonly ?int $executionTimeMs = null,
        public readonly ?int $rowsReturned = null,
        public readonly ?string $query = null,
        public readonly bool $success = true,
        public readonly ?string $errorMessage = null,
    ) {
    }

    /**
     * Create a text response.
     */
    public static function text(): self
    {
        return new self(type: self::TYPE_TEXT);
    }

    /**
     * Create a table response.
     */
    public static function table(array $headers, array $rows): self
    {
        return new self(
            type: self::TYPE_TABLE,
            data: [
                'headers' => $headers,
                'rows' => $rows,
            ],
        );
    }

    /**
     * Create a list response.
     */
    public static function list(array $items): self
    {
        return new self(
            type: self::TYPE_LIST,
            data: ['items' => $items],
        );
    }

    /**
     * Create a card/metric response.
     */
    public static function metric(string $label, mixed $value, ?string $icon = null, ?string $trend = null): self
    {
        return new self(
            type: self::TYPE_METRIC,
            data: [
                'label' => $label,
                'value' => $value,
                'icon' => $icon,
                'trend' => $trend,
            ],
        );
    }

    /**
     * Create an error response.
     */
    public static function error(string $message): self
    {
        return new self(
            type: self::TYPE_ERROR,
            success: false,
            errorMessage: $message,
        );
    }

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? self::TYPE_TEXT,
            data: $data['data'] ?? null,
            actions: $data['actions'] ?? [],
            suggestions: $data['suggestions'] ?? [],
            executionTimeMs: $data['executionTimeMs'] ?? null,
            rowsReturned: $data['rowsReturned'] ?? null,
            query: $data['query'] ?? null,
            success: $data['success'] ?? true,
            errorMessage: $data['errorMessage'] ?? null,
        );
    }

    /**
     * Add actions to the response.
     */
    public function withActions(array $actions): self
    {
        return new self(
            type: $this->type,
            data: $this->data,
            actions: array_merge($this->actions, $actions),
            suggestions: $this->suggestions,
            executionTimeMs: $this->executionTimeMs,
            rowsReturned: $this->rowsReturned,
            query: $this->query,
            success: $this->success,
            errorMessage: $this->errorMessage,
        );
    }

    /**
     * Add suggestions to the response.
     */
    public function withSuggestions(array $suggestions): self
    {
        return new self(
            type: $this->type,
            data: $this->data,
            actions: $this->actions,
            suggestions: $suggestions,
            executionTimeMs: $this->executionTimeMs,
            rowsReturned: $this->rowsReturned,
            query: $this->query,
            success: $this->success,
            errorMessage: $this->errorMessage,
        );
    }

    /**
     * Add execution metrics.
     */
    public function withMetrics(int $executionTimeMs, int $rowsReturned, ?string $query = null): self
    {
        return new self(
            type: $this->type,
            data: $this->data,
            actions: $this->actions,
            suggestions: $this->suggestions,
            executionTimeMs: $executionTimeMs,
            rowsReturned: $rowsReturned,
            query: $query,
            success: $this->success,
            errorMessage: $this->errorMessage,
        );
    }

    /**
     * Check if response has tabular data.
     */
    public function hasTable(): bool
    {
        return $this->type === self::TYPE_TABLE && !empty($this->data['rows']);
    }

    /**
     * Check if response has actions.
     */
    public function hasActions(): bool
    {
        return !empty($this->actions);
    }

    /**
     * Check if response has suggestions.
     */
    public function hasSuggestions(): bool
    {
        return !empty($this->suggestions);
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'data' => $this->data,
            'actions' => $this->actions,
            'suggestions' => $this->suggestions,
            'executionTimeMs' => $this->executionTimeMs,
            'rowsReturned' => $this->rowsReturned,
            'query' => $this->query,
            'success' => $this->success,
            'errorMessage' => $this->errorMessage,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
