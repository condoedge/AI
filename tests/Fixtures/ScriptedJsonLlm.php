<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures;

use Condoedge\Ai\Contracts\LlmProviderInterface;

/**
 * A scripted LLM for the structured query-generation contract (Tasks 5.4-5.6):
 * every chatJson() call pops the next scripted reply — an array/object (returned
 * as decoded JSON) or a \Throwable (thrown, e.g. LlmInvalidJsonException) — and
 * records the messages it received. complete()/chat() pop the same queue and
 * must receive a string.
 */
final class ScriptedJsonLlm implements LlmProviderInterface
{
    /** @var array<int, array{method: string, messages: array, options: array}> */
    public array $calls = [];

    /** @param list<array|object|string|\Throwable> $replies */
    public function __construct(private array $replies)
    {
    }

    /** A structured draft reply. */
    public static function draft(?string $cypher, bool $needsQuery = true, string $reason = ''): object
    {
        return (object) ['needs_query' => $needsQuery, 'cypher' => $cypher, 'reason' => $reason];
    }

    public function chatJson(array $messages, array $options = []): object|array
    {
        $this->calls[] = ['method' => 'chatJson', 'messages' => $messages, 'options' => $options];
        $reply = $this->next();
        if ($reply instanceof \Throwable) {
            throw $reply;
        }

        return is_string($reply) ? json_decode($reply) : $reply;
    }

    public function chat(array $messages, array $options = []): string
    {
        $this->calls[] = ['method' => 'chat', 'messages' => $messages, 'options' => $options];
        $reply = $this->next();
        if ($reply instanceof \Throwable) {
            throw $reply;
        }

        return (string) $reply;
    }

    public function complete(string $prompt, ?string $systemPrompt = null, array $options = []): string
    {
        $messages = $systemPrompt !== null ? [['role' => 'system', 'content' => $systemPrompt]] : [];
        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $this->chat($messages, $options);
    }

    /** The user message of the Nth call (0-based). */
    public function userMessage(int $call): string
    {
        foreach ($this->calls[$call]['messages'] ?? [] as $message) {
            if (($message['role'] ?? null) === 'user') {
                return (string) $message['content'];
            }
        }

        return '';
    }

    public function stream(array $messages, callable $callback, array $options = []): void
    {
    }

    public function getModel(): string
    {
        return 'scripted-json';
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

    private function next(): mixed
    {
        if ($this->replies === []) {
            throw new \RuntimeException('ScriptedJsonLlm: no scripted reply left');
        }

        return array_shift($this->replies);
    }
}
