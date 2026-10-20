<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\Digest\DigestSourceResult;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Services\Response\DigestResponseComposer;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Services\SemanticPromptBuilder;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Support\PipelineObserverScope;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\Fixtures\ScriptedUsageLlm;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Phase 4.1 + 4.3 — every LLM-calling pipeline service reports the VERBATIM
 * messages it sends (promptBuilt) and the provider's token usage (usage) to
 * the turn's observer. Observation only: the services' return values are
 * unchanged, and a provider without usage reporting emits nothing.
 */
class PipelineObserverEmissionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('logging.default', 'null');
        $app['config']->set('ai.log_channel', 'null');
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function logger(): AiLogger
    {
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldIgnoreMissing();
        $logger->shouldReceive('stopTimer')->andReturn(0.0);

        return $logger;
    }

    /** @test */
    public function query_understanding_reports_its_verbatim_messages_and_usage(): void
    {
        $llm = new ScriptedUsageLlm(json: ['search_query' => 'units', 'question_type' => 'data_query']);
        $service = new QueryUnderstandingService($llm, $this->logger(), []);
        $observer = new RecordingPipelineObserver();

        $result = $service->analyze('How many units?', [], [], $observer);

        $this->assertSame('units', $result->searchQuery);
        $prompts = $observer->prompts('understanding');
        $this->assertCount(1, $prompts);
        $this->assertSame($llm->calls[0], $prompts[0], 'the emitted messages are exactly what the LLM received');
        $this->assertNotSame('', $prompts[0][0]['content']);

        $this->assertSame([['stage' => 'understanding', 'usage' => ScriptedUsageLlm::USAGE]], $observer->usages());
    }

    /** @test */
    public function query_generation_reports_each_attempts_messages_and_usage(): void
    {
        $llm = new ScriptedUsageLlm(json: ['needs_query' => true, 'cypher' => 'MATCH (n:Invoice) RETURN n LIMIT 100', 'reason' => '']);
        $generator = new QueryGenerator(
            $llm,
            Mockery::mock(GraphStoreInterface::class),
            ['max_retries' => 1],
            new SemanticPromptBuilder()
        );

        $observer = new RecordingPipelineObserver();
        $context = new PipelineContext();
        $context->graphSchema = ['labels' => ['Invoice'], 'relationships' => []];
        $context->observer = $observer;

        $result = $generator->generate('Which residents have overdue invoices this month?', $context);

        $this->assertSame('MATCH (n:Invoice) RETURN n LIMIT 100', $result->cypher);
        $prompts = $observer->prompts('query_generation');
        $this->assertCount(1, $prompts);
        $this->assertSame('system', $prompts[0][0]['role']);
        $this->assertSame('user', $prompts[0][1]['role']);
        $this->assertNotSame('', $prompts[0][1]['content']);
        $this->assertSame($llm->calls[0], $prompts[0]);
        $this->assertSame('query_generation', $observer->usages()[0]['stage']);
    }

    /** @test */
    public function response_generation_reports_its_messages_and_usage_under_composition(): void
    {
        $llm = new ScriptedUsageLlm(text: 'There are 7 overdue invoices.');
        $generator = new ResponseGenerator($llm, ['default_format' => 'text', 'default_style' => 'friendly']);

        $observer = new RecordingPipelineObserver();
        $context = new PipelineContext();
        $context->question = 'How many invoices are overdue?';
        $context->queryResult = new QueryGenerationResult(cypher: 'MATCH (n) RETURN n');
        $context->executionResult = new QueryExecutionResult(success: true, data: [['count' => 7]]);
        $context->observer = $observer;

        $response = $generator->generate($context, ['style' => 'friendly']);

        $this->assertSame('There are 7 overdue invoices.', $response['answer']);
        $prompts = $observer->prompts('composition');
        $this->assertCount(1, $prompts);
        $this->assertSame($llm->calls[0], $prompts[0]);
        $this->assertStringContainsString('How many invoices are overdue?', $prompts[0][1]['content']);
        $this->assertSame([['stage' => 'composition', 'usage' => ScriptedUsageLlm::USAGE]], $observer->usages());
    }

    /** @test */
    public function the_empty_result_abstention_reports_through_the_ambient_turn_observer(): void
    {
        $llm = new ScriptedUsageLlm(text: 'Nothing matched.');
        $generator = new ResponseGenerator($llm, ['default_format' => 'text']);
        $observer = new RecordingPipelineObserver();

        PipelineObserverScope::run($observer, fn () => $generator->generateEmptyResponse('Any leaks?', 'MATCH (n) RETURN n'));

        $this->assertCount(1, $observer->prompts('composition'));
        $this->assertSame('composition', $observer->usages()[0]['stage']);
    }

    /** @test */
    public function the_digest_composer_reports_under_composition(): void
    {
        $llm = new ScriptedUsageLlm(text: 'Your digest.');
        $observer = new RecordingPipelineObserver();
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(searchQuery: 'attention', language: 'en');
        $ctx->observer = $observer;

        (new DigestResponseComposer($llm))->compose('What next?', $ctx, [
            new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [['id' => 1]], rowCount: 1),
        ]);

        $this->assertSame($llm->calls[0], $observer->prompts('composition')[0]);
        $this->assertSame('composition', $observer->usages()[0]['stage']);
    }

    /** @test */
    public function a_provider_without_usage_reporting_emits_no_usage(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->andReturn(['search_query' => 'x']);
        $observer = new RecordingPipelineObserver();

        (new QueryUnderstandingService($llm, $this->logger(), []))->analyze('q', [], [], $observer);

        $this->assertCount(1, $observer->prompts('understanding'));
        $this->assertSame([], $observer->usages());
    }

    /** @test */
    public function the_ambient_scope_restores_the_previous_observer(): void
    {
        $outer = new RecordingPipelineObserver();
        $inner = new RecordingPipelineObserver();

        PipelineObserverScope::run($outer, function () use ($inner) {
            try {
                PipelineObserverScope::run($inner, function () {
                    throw new \RuntimeException('boom');
                });
            } catch (\RuntimeException) {
            }
            PipelineObserverScope::current()->usage('after', ScriptedUsageLlm::USAGE);
        });
        PipelineObserverScope::current()->usage('outside', ScriptedUsageLlm::USAGE);

        $this->assertSame([['stage' => 'after', 'usage' => ScriptedUsageLlm::USAGE]], $outer->usages());
        $this->assertSame([], $inner->usages());
        $this->assertInstanceOf(\Condoedge\Ai\Support\NullPipelineObserver::class, PipelineObserverScope::current());
    }

    /** @test */
    public function a_throwing_observer_never_changes_the_query_understanding_result(): void
    {
        $response = ['search_query' => 'units', 'question_type' => 'data_query', 'entity_types' => []];
        $withNull = (new QueryUnderstandingService(new ScriptedUsageLlm(json: $response), $this->logger(), []))
            ->analyze('How many units?', [], [], new \Condoedge\Ai\Support\NullPipelineObserver());
        $withThrowing = (new QueryUnderstandingService(new ScriptedUsageLlm(json: $response), $this->logger(), []))
            ->analyze('How many units?', [], [], new \Condoedge\Ai\Tests\Fixtures\ThrowingPipelineObserver());

        $this->assertSame('units', $withThrowing->searchQuery, 'a throwing promptBuilt must not degrade QU to empty');
        $this->assertEquals($withNull, $withThrowing);
    }
}
