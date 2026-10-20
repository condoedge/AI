<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\ReportsTokenUsageInterface;

/**
 * An LLM provider whose JSON reply is computed by a callback from the messages
 * and options of each call (document-map tests: the reply depends on the passage
 * shown). Records every call, reports a fixed usage after a successful call and
 * none after a call that threw — like AbstractHttpLlmProvider.
 */
final class CallbackUsageLlm implements LlmProviderInterface, ReportsTokenUsageInterface
{
    /** @var list<array{messages: array, options: array}> */
    public array $calls = [];

    private ?array $last = null;

    /** @var callable(array $messages, array $options, int $call): (array|object) */
    private $handler;

    public function __construct(callable $handler, private readonly string $model = 'callback-model', private readonly int $input = 100, private readonly int $output = 20)
    {
        $this->handler = $handler;
    }

    public function chat(array $messages, array $options = []): string
    {
        return (string) json_encode($this->chatJson($messages, $options));
    }

    public function chatJson(array $messages, array $options = []): object|array
    {
        $this->calls[] = ['messages' => $messages, 'options' => $options];
        $this->last = null;

        $reply = ($this->handler)($messages, $options, count($this->calls));

        $this->last = ['input' => $this->input, 'output' => $this->output, 'total' => $this->input + $this->output, 'model' => $this->model];

        return $reply;
    }

    public function complete(string $prompt, ?string $systemPrompt = null, array $options = []): string
    {
        return $this->chat([['role' => 'user', 'content' => $prompt]], $options);
    }

    public function stream(array $messages, callable $callback, array $options = []): void
    {
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getProvider(): string
    {
        return 'callback';
    }

    public function getMaxTokens(): int
    {
        return 100000;
    }

    public function countTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    public function lastUsage(): ?array
    {
        return $this->last;
    }
}
