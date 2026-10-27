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

    public function test_an_isolated_provider_of_a_role_is_the_same_model_under_another_circuit_breaker(): void
    {
        config()->set('ai.llm.default', 'openai');
        config()->set('ai.llm.openai', ['api_key' => 'sk-base', 'model' => 'gpt-4o']);

        $resolver = new LlmResolver($this->defaultProvider(), ['query_understanding' => ['model' => 'gpt-4o-mini']]);
        $chat = $resolver->forRole('query_understanding');
        $index = $resolver->isolated('query_understanding', 'llm_index');

        $this->assertNotSame($chat, $index);
        $this->assertSame('gpt-4o-mini', $index->getModel());
        $this->assertSame('llm', $chat->circuitBreakerName(), 'the chat keeps the shared breaker');
        $this->assertSame('llm_index', $index->circuitBreakerName());
        $this->assertSame($index, $resolver->isolated('query_understanding', 'llm_index'), 'memoized');

        // A role without a configuration of its own: the default model, still isolated.
        $plain = $resolver->isolated('query_generation', 'llm_index');
        $this->assertSame('gpt-4o', $plain->getModel());
        $this->assertSame('llm_index', $plain->circuitBreakerName());
    }

    public function test_a_role_null_temperature_reaches_the_provider_instead_of_the_base_one(): void
    {
        config()->set('ai.llm.default', 'openai');
        config()->set('ai.llm.openai', ['api_key' => 'sk-base', 'model' => 'gpt-4o', 'temperature' => 0.3]);

        $role = ['model' => 'gpt-5.5', 'temperature' => null, 'reasoning_effort' => 'none'];
        $resolver = new LlmResolver($this->defaultProvider(), ['query_generation' => $role]);

        foreach ([$resolver->forRole('query_generation'), $resolver->isolated('query_generation', 'llm_index')] as $provider) {
            $shape = new \ReflectionMethod($provider, 'shapeRequest');
            $request = $shape->invoke($provider, [['role' => 'user', 'content' => 'q']], ['temperature' => 0.0]);

            $this->assertArrayNotHasKey('temperature', $request);
            $this->assertSame('none', $request['reasoning_effort']);
        }
    }

    public function test_a_provider_the_resolver_cannot_rebuild_is_shared_rather_than_lost(): void
    {
        // A host bound its own provider class: there is no configuration to build a second one from.
        config()->set('ai.llm.default', 'in-house');
        $default = $this->defaultProvider();
        $resolver = new LlmResolver($default, []);

        $this->assertSame($default, $resolver->isolated('query_understanding', 'llm_index'));
    }
}
