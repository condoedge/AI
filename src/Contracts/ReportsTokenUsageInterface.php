<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Token-usage reporting capability for an LLM provider.
 *
 * Deliberately separate from LlmProviderInterface (public API hosts implement —
 * adding methods there is a BC break):
 * `instanceof ReportsTokenUsageInterface` IS the capability check, and a provider
 * without it simply reports nothing. Implemented by AbstractHttpLlmProvider.
 */
interface ReportsTokenUsageInterface
{
    /**
     * Normalized usage of the MOST RECENT non-streaming call made on this
     * instance: ['input' => int, 'output' => int, 'total' => int, 'model' => string].
     * Null before any call, after a failed call, after a streaming call, or
     * when the provider response carried no usage block.
     */
    public function lastUsage(): ?array;
}
