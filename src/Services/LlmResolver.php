<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;

/**
 * Resolves the LLM provider for a given pipeline role.
 * Falls back to the default provider when no role-specific config exists.
 *
 *   $resolver->forRole('query_understanding')   // gpt-4o-mini
 *   $resolver->forRole('query_generation')       // gpt-4o
 *   $resolver->forRole('response_generation')    // gpt-4o-mini
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
