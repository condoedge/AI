<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\FileProcessorInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\EmbeddingProviders\DisabledEmbeddingProvider;
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\GraphStore\DisabledGraphStore;
use Condoedge\Ai\LlmProviders\DisabledLlmProvider;
use Condoedge\Ai\Services\BatchDataIngestionService;
use Condoedge\Ai\Services\LlmResolver;
use Condoedge\Ai\VectorStore\DisabledVectorStore;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Finder\Finder;

/**
 * AI_ENABLED=false, as a host ships it: no API key, no server. The container hands out
 * clients that leave nothing behind, so whatever code holds one (a host's, a stale chat
 * request's, tomorrow's) cannot reach Neo4j, Qdrant or a provider, and nothing fails at boot.
 */
class AiMasterSwitchDisabledClientsTest extends AiMasterSwitchTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.enabled', false);
        $app['config']->set('ai.llm.openai.api_key', null);
        $app['config']->set('ai.embedding.openai.api_key', null);
        $app['config']->set('ai.llm_roles', ['query_generation' => ['provider' => 'openai', 'model' => 'a-role-model']]);
    }

    /** @test */
    public function the_container_hands_out_clients_that_do_nothing(): void
    {
        $this->assertInstanceOf(DisabledGraphStore::class, app(GraphStoreInterface::class));
        $this->assertInstanceOf(DisabledVectorStore::class, app(VectorStoreInterface::class));
        $this->assertInstanceOf(DisabledEmbeddingProvider::class, app(EmbeddingProviderInterface::class));
        $this->assertInstanceOf(DisabledLlmProvider::class, app(LlmProviderInterface::class));
    }

    /** @test */
    public function no_role_gets_a_real_provider(): void
    {
        $resolver = app(LlmResolver::class);

        $this->assertInstanceOf(DisabledLlmProvider::class, $resolver->forRole('query_generation'));
        $this->assertInstanceOf(DisabledLlmProvider::class, $resolver->forRole('query_understanding'));
        $this->assertInstanceOf(DisabledLlmProvider::class, $resolver->isolated('query_generation', 'llm_index'));
    }

    /** @test */
    public function nothing_needs_an_api_key(): void
    {
        foreach ([FileProcessorInterface::class, ChunkStoreInterface::class, BatchDataIngestionService::class, 'ai'] as $service) {
            $this->assertIsObject(app($service));
        }

        // Every artisan run builds the package's commands, whatever the command.
        $this->assertSame(0, Artisan::call('list'));
    }

    /** @test */
    public function the_facade_answers_without_leaving_the_process(): void
    {
        $this->assertSame('', AI::chat('hello'));
        $this->assertSame('', AI::complete('hello'));
        $this->assertSame([], AI::embed('hello'));
        $this->assertFalse(AI::storeQuery('a question', 'MATCH (n) RETURN n')['success']);
        $this->assertTrue(AI::clearStoredQueries()['success']);
        $this->assertSame([], AI::getSchema()['labels'] ?? []);
    }

    /** @test */
    public function a_stale_chat_turn_gets_an_answer_array_without_leaving_the_process(): void
    {
        // A tab opened before the switch: the surfaces render nothing now, its form can still post.
        $this->assertIsArray(AI::answerQuestion('How many records are there?'));
    }

    /** @test */
    public function only_the_real_clients_open_a_connection(): void
    {
        $connecting = [];
        $finder = (new Finder())->files()->name('*.php')->in(dirname(__DIR__, 2) . '/src');
        foreach ($finder as $file) {
            if (preg_match('/curl_init\(|ClientBuilder::create|GuzzleHttp|Http::|fsockopen\(|stream_socket_client\(/', $file->getContents())) {
                $connecting[] = $file->getFilename();
            }
        }
        sort($connecting);

        // Each is built only through the bindings (or LlmResolver) that give a Disabled* instead.
        $this->assertSame([
            'AbstractHttpLlmProvider.php',
            'BoltNeo4jStore.php',
            'Neo4jStore.php',
            'OpenAiEmbeddingProvider.php',
            'QdrantStore.php',
        ], $connecting);
    }
}
