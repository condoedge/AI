<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Discovery\AiConfigEnhancer;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Services\Retrieval\DocumentProbeWriter;
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

    public function test_the_document_probe_writer_rides_the_query_understanding_role_and_the_host_corpus_config(): void
    {
        $this->app['config']->set('ai.llm_roles', [
            'query_understanding' => ['model' => 'gpt-cheap-fast'],
        ]);
        $this->app['config']->set('ai.file_context.corpus_languages', ['fr', 'en']);
        $this->app['config']->set('ai.file_context.corpus_register', '');
        // Building the whole AiManager graph needs an embedding provider (no call is made).
        $this->app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');

        $writer = $this->app->make(DocumentProbeWriter::class);

        // The focused probe call is a small-model job: same role as Query Understanding.
        $this->assertSame('gpt-cheap-fast', $this->injectedLlm($writer, DocumentProbeWriter::class)->getModel());
        $this->assertTrue($writer->isConfigured(), 'the corpus description under ai.file_context is its input');

        // ... and the pipeline's AiManager is wired with that same writer.
        $manager = $this->app->make(AiManager::class);
        $prop = new \ReflectionProperty(AiManager::class, 'probeWriter');
        $prop->setAccessible(true);
        $this->assertSame($writer, $prop->getValue($manager));
    }

    public function test_the_document_probe_writer_is_inert_when_the_host_described_no_corpus(): void
    {
        $this->app['config']->set('ai.file_context.corpus_languages', []);
        $this->app['config']->set('ai.file_context.corpus_register', '');

        $this->assertFalse($this->app->make(DocumentProbeWriter::class)->isConfigured());
    }

    public function test_the_clarity_checker_rides_the_query_understanding_role_and_is_wired_into_the_pipeline(): void
    {
        $this->app['config']->set('ai.llm_roles', [
            'query_understanding' => ['model' => 'gpt-cheap-fast'],
        ]);
        // Building the whole AiManager graph needs an embedding provider (no call is made).
        $this->app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');

        $checker = $this->app->make(\Condoedge\Ai\Services\Understanding\ClarityChecker::class);

        // The focused clarity call is a small-model job: same role as Query Understanding.
        $this->assertSame(
            'gpt-cheap-fast',
            $this->injectedLlm($checker, \Condoedge\Ai\Services\Understanding\ClarityChecker::class)->getModel()
        );

        $manager = $this->app->make(AiManager::class);
        $prop = new \ReflectionProperty(AiManager::class, 'clarityChecker');
        $prop->setAccessible(true);
        $this->assertSame($checker, $prop->getValue($manager));
    }

    public function test_the_follow_up_writer_rides_the_query_generation_role_by_default_and_is_wired_into_the_pipeline(): void
    {
        $this->app['config']->set('ai.llm_roles', [
            'query_understanding' => ['model' => 'gpt-cheap-fast'],
            'query_generation' => ['model' => 'gpt-accurate-reasoner'],
        ]);
        // Building the whole AiManager graph needs an embedding provider (no call is made).
        $this->app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');

        $writer = $this->app->make(\Condoedge\Ai\Services\Response\FollowUpWriter::class);

        // Judging what a person would naturally ask next is NOT a small-model job (measured
        // live): with no role of its own, the writer rides the query-generation role.
        $this->assertSame(
            'gpt-accurate-reasoner',
            $this->injectedLlm($writer, \Condoedge\Ai\Services\Response\FollowUpWriter::class)->getModel()
        );
        $this->assertTrue($writer->modelDeclines(), 'by default the model declines tangential excerpts itself');

        // Its relevance gate measures with the request's embedding provider (memoized: the
        // user's question was already embedded by retrieval).
        $embedding = new \ReflectionProperty(\Condoedge\Ai\Services\Response\FollowUpWriter::class, 'embedding');
        $embedding->setAccessible(true);
        $this->assertSame(
            $this->app->make(\Condoedge\Ai\Contracts\EmbeddingProviderInterface::class),
            $embedding->getValue($writer)
        );

        // ... and the pipeline's action attacher is the one that holds it.
        $attacher = $this->app->make(\Condoedge\Ai\Services\Actions\Attach\ActionAttacher::class);
        $held = new \ReflectionProperty(\Condoedge\Ai\Services\Actions\Attach\ActionAttacher::class, 'followUpWriter');
        $held->setAccessible(true);
        $this->assertSame($writer, $held->getValue($attacher));

        $manager = $this->app->make(AiManager::class);
        $prop = new \ReflectionProperty(AiManager::class, 'actionAttacher');
        $prop->setAccessible(true);
        $this->assertSame($attacher, $prop->getValue($manager));
    }

    public function test_the_follow_up_writer_uses_its_own_role_when_one_is_configured(): void
    {
        $this->app['config']->set('ai.llm_roles', [
            'query_generation' => ['model' => 'gpt-accurate-reasoner'],
            'follow_up_writer' => ['model' => 'gpt-cheap-fast'],
        ]);
        // A host that points the role at a small model lets CODE gate instead of the model.
        $this->app['config']->set('ai.attached_actions.follow_ups.model_declines', false);
        $this->app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');

        $writer = $this->app->make(\Condoedge\Ai\Services\Response\FollowUpWriter::class);

        $this->assertSame(
            'gpt-cheap-fast',
            $this->injectedLlm($writer, \Condoedge\Ai\Services\Response\FollowUpWriter::class)->getModel()
        );
        $this->assertFalse($writer->modelDeclines());
    }

    public function test_the_follow_up_writer_falls_back_to_the_default_provider_without_any_role(): void
    {
        $this->app['config']->set('ai.llm_roles', []);
        $this->app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');

        $writer = $this->app->make(\Condoedge\Ai\Services\Response\FollowUpWriter::class);

        $this->assertSame(
            $this->app->make(\Condoedge\Ai\Contracts\LlmProviderInterface::class)->getModel(),
            $this->injectedLlm($writer, \Condoedge\Ai\Services\Response\FollowUpWriter::class)->getModel()
        );
    }

    public function test_the_conversational_reply_rides_the_response_generation_role_by_default(): void
    {
        $this->app['config']->set('ai.llm_roles', [
            'response_generation' => ['model' => 'gpt-answer-writer'],
        ]);

        $composer = $this->app->make(\Condoedge\Ai\Services\Response\ConversationalReplyComposer::class);

        $this->assertSame('gpt-answer-writer', $this->injectedLlm($composer, \Condoedge\Ai\Services\Response\ConversationalReplyComposer::class)->getModel());
    }

    public function test_the_conversational_reply_uses_its_own_role_when_one_is_configured(): void
    {
        // CondoEdge 2026-10-25: gpt-4o-mini, gpt-4o and luna judged 4 of 5 how-to questions small talk; a reasoning model judged all 10 right.
        $this->app['config']->set('ai.llm_roles', [
            'response_generation' => ['model' => 'gpt-answer-writer'],
            'conversational_reply' => ['model' => 'gpt-reasoner'],
        ]);

        $composer = $this->app->make(\Condoedge\Ai\Services\Response\ConversationalReplyComposer::class);

        $this->assertSame('gpt-reasoner', $this->injectedLlm($composer, \Condoedge\Ai\Services\Response\ConversationalReplyComposer::class)->getModel());
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
