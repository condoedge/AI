<?php

declare(strict_types=1);

namespace Condoedge\Ai\LlmProviders;

use Condoedge\Ai\Contracts\LlmProviderInterface;

/**
 * The LLM provider while the master switch is off (AI_ENABLED=false): no call, no API key
 * needed, empty replies, nothing thrown. See AiFeature.
 */
final class DisabledLlmProvider implements LlmProviderInterface
{
    public function chat(array $messages, array $options = []): string
    {
        return '';
    }

    public function chatJson(array $messages, array $options = []): object|array
    {
        return [];
    }

    public function complete(string $prompt, ?string $systemPrompt = null, array $options = []): string
    {
        return '';
    }

    public function stream(array $messages, callable $callback, array $options = []): void
    {
    }

    public function getModel(): string
    {
        return 'disabled';
    }

    public function getProvider(): string
    {
        return 'disabled';
    }

    public function getMaxTokens(): int
    {
        return 0;
    }

    public function countTokens(string $text): int
    {
        return 0;
    }
}
