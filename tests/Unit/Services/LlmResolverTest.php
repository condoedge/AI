<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\LlmResolver;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\LlmProviders\OpenAiLlmProvider;

class LlmResolverTest extends TestCase
{
    private function defaultProvider(): LlmProviderInterface
    {
        return new OpenAiLlmProvider(['api_key' => 'sk-default', 'model' => 'gpt-4o']);
    }

    public function test_unknown_role_returns_default_provider(): void
    {
        $default = $this->defaultProvider();
        $resolver = new LlmResolver($default, []);

        $this->assertSame($default, $resolver->forRole('query_generation'));
    }

    public function test_empty_role_config_returns_default_provider(): void
    {
        $default = $this->defaultProvider();
        $resolver = new LlmResolver($default, ['query_generation' => null]);

        $this->assertSame($default, $resolver->forRole('query_generation'));
    }

    public function test_role_specific_model_overrides_default(): void
    {
        config()->set('ai.llm.openai', ['api_key' => 'sk-base', 'model' => 'gpt-4o']);

        $resolver = new LlmResolver(
            $this->defaultProvider(),
            ['query_generation' => ['model' => 'gpt-4o-custom']]
        );

        $provider = $resolver->forRole('query_generation');

        $this->assertNotSame($this->defaultProvider(), $provider);
        $this->assertSame('gpt-4o-custom', $provider->getModel());
    }

    public function test_role_provider_is_memoized(): void
    {
        config()->set('ai.llm.openai', ['api_key' => 'sk-base', 'model' => 'gpt-4o']);

        $resolver = new LlmResolver(
            $this->defaultProvider(),
            ['response_generation' => ['model' => 'gpt-4o-mini']]
        );

        $first = $resolver->forRole('response_generation');
        $second = $resolver->forRole('response_generation');

        $this->assertSame($first, $second);
    }
}
