<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tools;

use Condoedge\Ai\Contracts\ToolAwareLlmProviderInterface;
use Illuminate\Support\Facades\Log;

/**
 * Multi-turn tool calling loop.
 *
 * Orchestrates the LLM → tool call → execution → result → LLM cycle
 * until the LLM produces a final text response or max iterations is reached.
 */
class ToolCallLoop
{
    public function __construct(
        private readonly ToolExecutor $executor,
        private readonly int $maxIterations = 10,
    ) {}

    /**
     * Run the tool calling loop.
     *
     * @param ToolAwareLlmProviderInterface $llm The LLM provider with tool support
     * @param array $messages Initial messages
     * @param array $tools Tool schemas (OpenAI format)
     * @param array $context Security context
     * @param array $options LLM options
     * @return ToolCallLoopResult
     */
    public function run(
        ToolAwareLlmProviderInterface $llm,
        array $messages,
        array $tools,
        array $context = [],
        array $options = [],
    ): ToolCallLoopResult {
        $toolCallHistory = [];
        $iteration = 0;

        while ($iteration < $this->maxIterations) {
            $iteration++;

            $response = $this->callLlmWithRetry($llm, $messages, $tools, $options);

            $choice = $response['choices'][0] ?? null;

            if ($choice === null) {
                return new ToolCallLoopResult(
                    finalResponse: 'No response from LLM.',
                    toolCallHistory: $toolCallHistory,
                    iterations: $iteration,
                );
            }

            $message = $choice['message'] ?? [];
            $finishReason = $choice['finish_reason'] ?? 'stop';

            // If the LLM wants to call tools
            if ($finishReason === 'tool_calls' || !empty($message['tool_calls'])) {
                // Add assistant message with tool calls to conversation
                $messages[] = $message;

                $toolCalls = $message['tool_calls'] ?? [];

                foreach ($toolCalls as $toolCall) {
                    $functionName = $toolCall['function']['name'] ?? '';
                    $toolCallId = $toolCall['id'] ?? uniqid('call_');
                    $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $arguments = [];
                    }

                    // Execute the tool
                    $result = $this->executor->execute($functionName, $toolCallId, $arguments, $context);

                    $toolCallHistory[] = [
                        'tool' => $functionName,
                        'arguments' => $arguments,
                        'result' => $result,
                        'iteration' => $iteration,
                    ];

                    // Add tool result to messages
                    $messages[] = $result->toToolMessage();
                }

                continue;
            }

            // LLM produced a final text response
            $finalText = $message['content'] ?? '';

            return new ToolCallLoopResult(
                finalResponse: $finalText,
                toolCallHistory: $toolCallHistory,
                iterations: $iteration,
            );
        }

        // Max iterations reached
        Log::warning('Tool call loop reached max iterations', [
            'max_iterations' => $this->maxIterations,
            'tool_calls_made' => count($toolCallHistory),
        ]);

        return new ToolCallLoopResult(
            finalResponse: 'I was unable to complete the task within the allowed number of steps. Please try a simpler request.',
            toolCallHistory: $toolCallHistory,
            iterations: $iteration,
            maxIterationsReached: true,
        );
    }

    /**
     * Call the LLM with retry for malformed JSON responses.
     */
    private function callLlmWithRetry(
        ToolAwareLlmProviderInterface $llm,
        array $messages,
        array $tools,
        array $options,
        int $maxRetries = 3,
    ): array {
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $llm->chatWithTools($messages, $tools, $options);
            } catch (\Exception $e) {
                $lastError = $e;

                // Only retry on JSON parsing errors
                if (stripos($e->getMessage(), 'json') === false && stripos($e->getMessage(), 'parse') === false) {
                    throw $e;
                }

                Log::warning("Tool call LLM retry attempt {$attempt}/{$maxRetries}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastError;
    }
}
