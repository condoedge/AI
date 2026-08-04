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
        $mergedConfig = array_merge($baseConfig, array_filter($config, fn($v) => $v !== null));

        $this->cache[$role] = match ($provider) {
            'openai' => new \Condoedge\Ai\LlmProviders\OpenAiLlmProvider($mergedConfig),
            'anthropic' => new \Condoedge\Ai\LlmProviders\AnthropicLlmProvider($mergedConfig),
            default => $this->defaultProvider,
        };

        return $this->cache[$role];
    }

    public function default(): LlmProviderInterface
    {
        return $this->defaultProvider;
    }
}
