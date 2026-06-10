<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Stubs;

use Condoedge\Ai\Contracts\ToolCallingLlmInterface;
use Condoedge\Ai\DTOs\LlmToolTurn;

/**
 * Test stub: a scripted tool-calling provider for ToolLoopRunner tests. Each chatWithTools()
 * call pops the next scripted step — an LlmToolTurn to return or a Throwable to throw — and
 * records exactly what the loop sent (messages, specs, options) plus every $results bag the
 * loop passed to formatToolResults(), so tests can assert tool_result contents and the
 * append-only history rule without any provider wire shapes.
 */
final class ScriptedToolProvider implements ToolCallingLlmInterface
{
    /** @var array<int, array{messages: array, toolSpecs: array, options: array}> */
    public array $turns = [];

    /** @var array<int, array<string, string|array{content: string, is_error?: bool}>> */
    public array $results = [];

    private int $cursor = 0;

    /** @param array<int, LlmToolTurn|\Throwable> $script */
    public function __construct(private array $script)
    {
        $this->script = array_values($script);
    }

    public function chatWithTools(array $messages, array $toolSpecs, array $options = []): LlmToolTurn
    {
        $this->turns[] = ['messages' => $messages, 'toolSpecs' => $toolSpecs, 'options' => $options];

        $step = $this->script[$this->cursor++] ?? null;
        if ($step === null) {
            throw new \LogicException('ScriptedToolProvider: ran past the end of the script.');
        }
        if ($step instanceof \Throwable) {
            throw $step;
        }

        return $step;
    }

    public function formatToolResults(LlmToolTurn $turn, array $results): array
    {
        $this->results[] = $results;

        $messages = [['role' => 'assistant', 'raw' => $turn->rawAssistantMessage]];
        foreach ($results as $callId => $result) {
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => (string) $callId,
                'content' => is_array($result) ? (string) ($result['content'] ?? '') : (string) $result,
                'is_error' => is_array($result) && !empty($result['is_error']),
            ];
        }

        return $messages;
    }
}
