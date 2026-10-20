<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;

/**
 * Verifies that the advertised per-role LLM keys are actually live:
 * QueryGenerator must be built from forRole('query_generation') and
 * ResponseGenerator from forRole('response_generation'), so that
 * config('ai.llm_roles.*') overrides take effect (Track G - G4 / L13-1).
 */
class AiServiceProviderRoleWiringTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai', [
            'api_key' => 'sk-default',
            'model' => 'gpt-4o',
        ]);

        // Distinct models per role so we can detect which provider was injected.
        $app['config']->set('ai.llm_roles', [
            'query_generation' => ['model' => 'gpt-4o-querygen'],
            'response_generation' => ['model' => 'gpt-4o-responsegen'],
        ]);
    }

    private function injectedLlm(object $generator): LlmProviderInterface
    {
        $ref = new \ReflectionClass($generator);
        $prop = $ref->getProperty('llm');
        $prop->setAccessible(true);

        return $prop->getValue($generator);
    }

    public function test_query_generator_uses_query_generation_role_provider(): void
    {
        $generator = $this->app->make(QueryGeneratorInterface::class);

        $this->assertSame('gpt-4o-querygen', $this->injectedLlm($generator)->getModel());
    }

    public function test_response_generator_uses_response_generation_role_provider(): void
    {
        $generator = $this->app->make(ResponseGeneratorInterface::class);

        $this->assertSame('gpt-4o-responsegen', $this->injectedLlm($generator)->getModel());
    }

    /**
     * The document-map BUILD (thousands of index-time calls in a backfill) must not
     * be able to open the circuit breaker the live chat's calls go through: its
     * providers fail under a breaker of their own.
     */
    public function test_the_document_map_builder_calls_fail_under_their_own_circuit_breaker(): void
    {
        // The chunk store is not what this test is about (and would need an embedding key).
        $this->app->instance(
            \Condoedge\Ai\Contracts\ChunkStoreInterface::class,
            \Mockery::mock(\Condoedge\Ai\Contracts\ChunkStoreInterface::class)
        );
        $builder = $this->app->make(\Condoedge\Ai\Services\Files\DocumentMapBuilder::class);
        $ref = new \ReflectionClass($builder);

        foreach (['gistLlm' => 'gpt-4o', 'scopeLlm' => 'gpt-4o-querygen'] as $property => $model) {
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $llm = $prop->getValue($builder);

            $this->assertSame($model, $llm->getModel(), "{$property}: the role's own model");
            $this->assertSame('llm_index', $llm->circuitBreakerName(), "{$property}: not the chat's breaker");
        }

        // The chat's providers keep theirs.
        $chat = $this->app->make(\Condoedge\Ai\Services\LlmResolver::class)->forRole('query_generation');
        $this->assertSame('llm', $chat->circuitBreakerName());
    }
}
