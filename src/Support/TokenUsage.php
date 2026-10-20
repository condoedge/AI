<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\Contracts\ReportsTokenUsageInterface;

/**
 * One-line call-site helpers for the observer seam: forward a provider's
 * last-call usage, and build the verbatim message list of a complete() call.
 */
final class TokenUsage
{
    /**
     * Emit the provider's last-call usage to the observer when it reports one.
     * Never throws — observation must not break a turn.
     */
    public static function report(PipelineObserverInterface $observer, string $stage, mixed $llm): void
    {
        if (!$llm instanceof ReportsTokenUsageInterface) {
            return;
        }

        try {
            $usage = $llm->lastUsage();
            if ($usage !== null) {
                $observer->usage($stage, $usage);
            }
        } catch (\Throwable) {
            // Observation only.
        }
    }

    /**
     * The exact messages LlmProviderInterface::complete() sends for a
     * (prompt, system) pair — system first when present, then the user turn.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public static function completeMessages(string $prompt, ?string $systemPrompt): array
    {
        $messages = [];
        if ($systemPrompt !== null) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $messages;
    }
}
