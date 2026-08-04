<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\Retrieval\LlmReranker;
use Condoedge\Ai\Services\Retrieval\RerankerInterface;
use Condoedge\Ai\Tests\TestCase;

/**
 * The LlmReranker must resolve its LLM via a dedicated role
 * config('ai.llm_roles.reranker') when set, mirroring the forRole pattern used
 * by query_generation/response_generation, and fall back to the default
 * provider when the role is unset. See ws-r-research-4.
 */
class RerankerRoleResolutionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai', [
            'api_key' => 'test-api-key-placeholder-for-testing-that-is-long-enough',
            'model' => 'gpt-default-generation',
        ]);
        $app['config']->set('ai.rag.rerank_enabled', true);
    }

    private function rerankerLlm(RerankerInterface $reranker): LlmProviderInterface
    {
        $this->assertInstanceOf(LlmReranker::class, $reranker);

        $prop = new \ReflectionProperty(LlmReranker::class, 'llm');
        $prop->setAccessible(true);

        return $prop->getValue($reranker);
    }

    public function test_reranker_uses_the_reranker_role_when_configured(): void
    {
        $this->app['config']->set('ai.llm_roles', [
            'reranker' => ['model' => 'gpt-cheap-reranker'],
        ]);

        $reranker = $this->app->make(RerankerInterface::class);

        $this->assertSame('gpt-cheap-reranker', $this->rerankerLlm($reranker)->getModel());
    }

    public function test_reranker_falls_back_to_default_provider_when_role_unset(): void
    {
        $this->app['config']->set('ai.llm_roles', []);

        $reranker = $this->app->make(RerankerInterface::class);

        $this->assertSame('gpt-default-generation', $this->rerankerLlm($reranker)->getModel());
    }
}
