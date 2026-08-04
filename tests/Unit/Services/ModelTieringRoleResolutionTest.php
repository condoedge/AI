<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\Discovery\AiConfigEnhancer;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Tests\TestCase;

/**
 * Model tiering: the fast/cheap stages must resolve their LLM through
 * LlmResolver::forRole so a host can point them at a small model via
 * config('ai.llm_roles.*'), and fall back to the default provider when the
 * role is unset (default behavior unchanged). See ws-r-research-4 and the
 * llm_roles tiering convention in config/ai.php.
 */
class ModelTieringRoleResolutionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai', [
            'api_key' => 'test-api-key-placeholder-for-testing-that-is-long-enough',
            'model' => 'gpt-default-generation',
        ]);
    }

    private function injectedLlm(object $service, string $class): LlmProviderInterface
    {
        $prop = new \ReflectionProperty($class, 'llm');
        $prop->setAccessible(true);

        return $prop->getValue($service);
    }

    public function test_query_understanding_uses_the_fast_role_when_configured(): void
    {
        $this->app['config']->set('ai.llm_roles', [
            'query_understanding' => ['model' => 'gpt-cheap-fast'],
        ]);

        $service = $this->app->make(QueryUnderstandingService::class);

        $this->assertSame(
            'gpt-cheap-fast',
            $this->injectedLlm($service, QueryUnderstandingService::class)->getModel()
        );
    }

    public function test_query_understanding_falls_back_to_default_provider_when_role_unset(): void
    {
        $this->app['config']->set('ai.llm_roles', []);

        $service = $this->app->make(QueryUnderstandingService::class);

        $this->assertSame(
            'gpt-default-generation',
            $this->injectedLlm($service, QueryUnderstandingService::class)->getModel()
        );
    }

    public function test_config_enhancer_uses_the_config_enhancement_role_when_configured(): void
    {
        $this->app['config']->set('ai.llm_roles', [
            'config_enhancement' => ['model' => 'gpt-accurate-enhancer'],
        ]);

        $enhancer = $this->app->make(AiConfigEnhancer::class);

        $this->assertSame(
            'gpt-accurate-enhancer',
            $this->injectedLlm($enhancer, AiConfigEnhancer::class)->getModel()
        );
    }

    public function test_config_enhancer_falls_back_to_default_provider_when_role_unset(): void
    {
        $this->app['config']->set('ai.llm_roles', []);

        $enhancer = $this->app->make(AiConfigEnhancer::class);

        $this->assertSame(
            'gpt-default-generation',
            $this->injectedLlm($enhancer, AiConfigEnhancer::class)->getModel()
        );
    }
}
