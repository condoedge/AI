<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;

/**
 * Resolves the LLM provider for a given pipeline role — the seam for
 * fast-vs-accurate model tiering (config('ai.llm_roles'), see config/ai.php).
 * A role with no config SAFELY falls back to the default provider, so an empty
 * llm_roles map leaves every stage on the single default model.
 *
 *   $resolver->forRole('query_understanding')    // FAST: small model
 *   $resolver->forRole('query_generation')        // ACCURATE: reasoning model
 *   $resolver->forRole('response_generation')     // ACCURATE: answer writer
 */
class LlmResolver
{
    private array $cache = [];

    public function __construct(
        private readonly LlmProviderInterface $defaultProvider,
        private readonly array $roleConfig = []
    ) {}

    public function forRole(string $role): LlmProviderInterface
    {
        if (empty($this->roleConfig[$role])) {
            return $this->defaultProvider;
        }

        if (isset($this->cache[$role])) {
            return $this->cache[$role];
        }

        $config = $this->roleConfig[$role];
        $provider = $config['provider'] ?? config('ai.llm.default', 'openai');
        $baseConfig = config("ai.llm.{$provider}", []);
        $mergedConfig = array_merge($baseConfig, $this->overrides($config));

        $this->cache[$role] = match ($provider) {
            'openai' => new \Condoedge\Ai\LlmProviders\OpenAiLlmProvider($mergedConfig),
            'anthropic' => new \Condoedge\Ai\LlmProviders\AnthropicLlmProvider($mergedConfig),
            default => $this->defaultProvider,
        };

        return $this->cache[$role];
    }

    /**
     * The role's model under a circuit breaker OF ITS OWN — for background work
     * that makes many calls in a row (the document-map build: one call per stored
     * passage). Every provider of forRole() fails under the one breaker the live
     * chat goes through; a backfill that runs into the provider's rate limit would
     * open it for the users. An isolated provider is a second instance of the same
     * model whose failures are counted under $breaker instead.
     *
     * Built from configuration (ai.llm.<provider> merged with the role's own). A
     * provider this resolver cannot rebuild — a class the host bound itself — is
     * returned as forRole() gives it: shared, rather than lost.
     */
    public function isolated(string $role, string $breaker): LlmProviderInterface
    {
        $key = $role . '|' . $breaker;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $config = is_array($this->roleConfig[$role] ?? null) ? $this->roleConfig[$role] : [];
        $provider = $config['provider'] ?? config('ai.llm.default', 'openai');
        $merged = array_merge(
            (array) config("ai.llm.{$provider}", []),
            $this->overrides($config),
            ['circuit_breaker' => $breaker]
        );

        try {
            $isolated = match ($provider) {
                'openai' => new \Condoedge\Ai\LlmProviders\OpenAiLlmProvider($merged),
                'anthropic' => new \Condoedge\Ai\LlmProviders\AnthropicLlmProvider($merged),
                default => null,
            };
        } catch (\Throwable) {
            $isolated = null; // no usable configuration (a missing key): share
        }

        return $this->cache[$key] = $isolated ?? $this->forRole($role);
    }

    /** A role's null value falls back to the base config, except `temperature => null`: send none. */
    private function overrides(array $config): array
    {
        return array_filter($config, fn ($v, $k) => $v !== null || $k === 'temperature', ARRAY_FILTER_USE_BOTH);
    }

    public function default(): LlmProviderInterface
    {
        return $this->defaultProvider;
    }
}
