<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\ReportsTokenUsageInterface;

/**
 * A scripted LLM provider that reports a fixed token usage after every call
 * (Phase 4.3 tests). Records every message list it received.
 */
final class ScriptedUsageLlm implements LlmProviderInterface, ReportsTokenUsageInterface
{
    public const USAGE = ['input' => 100, 'output' => 20, 'total' => 120, 'model' => 'scripted-model'];

    /** @var array<int, array> */
    public array $calls = [];

    private ?array $last = null;

    public function __construct(private readonly string $text = '', private readonly array $json = [])
    {
    }

    public function chat(array $messages, array $options = []): string
    {
        $this->calls[] = $messages;
        $this->last = self::USAGE;

        return $this->text;
    }

    public function chatJson(array $messages, array $options = []): object|array
    {
        $this->calls[] = $messages;
        $this->last = self::USAGE;

        return $this->json;
    }

    public function complete(string $prompt, ?string $systemPrompt = null, array $options = []): string
    {
        $messages = [];
        if ($systemPrompt !== null) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $this->chat($messages, $options);
    }

    public function stream(array $messages, callable $callback, array $options = []): void
    {
    }

    public function getModel(): string
    {
        return 'scripted-model';
    }

    public function getProvider(): string
    {
        return 'scripted';
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
