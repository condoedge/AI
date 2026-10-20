<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat\UseCases;

use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\Inputs\AskQuestionInput;
use Condoedge\Ai\Services\Chat\UseCases\AskQuestionUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * Phase 4.2 — every chat turn persists a compact pipeline trace on the
 * assistant message (metadata.trace): stage timings, errors, token usage;
 * prompts only when ai.trace.capture_prompts.
 */
class AskQuestionUseCaseTraceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('entities', []);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../../database/migrations');
    }

    private function mockAi(): \Mockery\MockInterface
    {
        $mock = Mockery::mock();
        $this->app->instance('ai', $mock);
        AI::swap($mock);
        $mock->shouldReceive('getSchema')->andReturn(['labels' => [], 'relationships' => []]);

        return $mock;
    }

    /**
     * An answerQuestion() stand-in that drives the observer like production does.
     */
    private function answeringThroughObserver(\Mockery\MockInterface $ai, ?array &$receivedOptions = null): void
    {
        $ai->shouldReceive('answerQuestion')->once()->andReturnUsing(function ($question, $options) use (&$receivedOptions) {
            $receivedOptions = $options;
            $observer = $options['observer'];
            $observer->stageStarted('understanding');
            $observer->promptBuilt('understanding', [['role' => 'system', 'content' => 'QU SYSTEM PROMPT']]);
            $observer->usage('understanding', ['input' => 90, 'output' => 10, 'total' => 100, 'model' => 'mini']);
            $observer->stageFinished('understanding', ['qu' => ['question_type' => 'data_query']], 400.0);
            $observer->stageStarted('composition');
            $observer->usage('composition', ['input' => 200, 'output' => 40, 'total' => 240, 'model' => 'big']);
            $observer->stageFinished('composition', ['branch' => 'data', 'answer_length' => 9], 900.0);

            return [
                'answer' => '42 units.',
                'cypher' => 'MATCH (u:Unit) RETURN count(u)',
                'data' => [['n' => 42]],
                'suggestions' => [],
                'referenced_files' => [],
            ];
        });
    }

    private function ask(AiConversation $conversation): \Condoedge\Ai\Services\Chat\Results\ChatTurnResult
    {
        return $this->app->make(AskQuestionUseCase::class)->execute(new AskQuestionInput(
            question: 'How many units?',
            conversation: $conversation,
            style: 'friendly',
            user: (object) ['id' => 7],
        ));
    }

    /** @test */
    public function the_assistant_message_carries_the_turn_trace_with_stage_timings_and_usage(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $ai = $this->mockAi();
        $options = null;
        $this->answeringThroughObserver($ai, $options);

        $result = $this->ask($conversation);

        $this->assertInstanceOf(PipelineObserverInterface::class, $options['observer']);

        $trace = $result->assistantMessage->fresh()->metadata['trace'] ?? null;
        $this->assertIsArray($trace, 'the trace is persisted on the assistant message');

        $stages = array_column($trace['stages'], null, 'stage');
        foreach (['security_gate', 'conversation_context', 'understanding', 'composition', 'pipeline'] as $stage) {
            $this->assertArrayHasKey($stage, $stages, "stage {$stage} recorded");
            $this->assertIsNumeric($stages[$stage]['ms']);
        }
        $this->assertSame(400.0, (float) $stages['understanding']['ms']);
        $this->assertTrue($stages['security_gate']['data']['passed']);
        $this->assertSame(340, $trace['usage']['total']['total']);
        $this->assertSame([], $trace['errors']);
        $this->assertArrayNotHasKey('prompts', $trace, 'prompts are absent unless capture_prompts');
    }

    /** @test */
    public function prompts_are_persisted_when_capture_prompts_is_on(): void
    {
        config(['ai.trace.capture_prompts' => true]);
        $conversation = AiConversation::create(['user_id' => 1]);
        $this->answeringThroughObserver($this->mockAi());

        $result = $this->ask($conversation);

        $trace = $result->assistantMessage->fresh()->metadata['trace'];
        $this->assertSame('QU SYSTEM PROMPT', $trace['prompts']['understanding'][0][0]['content']);
    }

    /** @test */
    public function a_failed_turn_still_gets_a_trace_with_the_error_stage(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $ai = $this->mockAi();
        $ai->shouldReceive('answerQuestion')->once()->andThrow(new \RuntimeException('pipeline blew up'));

        $result = $this->ask($conversation);

        $this->assertTrue($result->failed());
        $metadata = $result->assistantMessage->fresh()->metadata;
        $this->assertTrue((bool) $metadata['error']);
        $trace = $metadata['trace'];
        $this->assertSame('pipeline', $trace['errors'][0]['stage']);
        $this->assertSame('pipeline blew up', $trace['errors'][0]['message']);
        $this->assertContains('pipeline', array_column($trace['stages'], 'stage'));
    }

    /** @test */
    public function a_trace_that_cannot_be_serialized_never_costs_the_answer(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $ai = $this->mockAi();
        $ai->shouldReceive('answerQuestion')->once()->andReturnUsing(function ($q, $options) {
            $options['observer']->stageFinished('understanding', ['poison' => new class implements \JsonSerializable {
                public function jsonSerialize(): mixed
                {
                    throw new \RuntimeException('cannot serialize');
                }
            }], 1.0);

            return ['answer' => 'ok', 'cypher' => null, 'data' => [['x' => 1]], 'suggestions' => [], 'referenced_files' => []];
        });

        $result = $this->ask($conversation);

        $this->assertFalse($result->failed());
        $this->assertSame('ok', $result->assistantMessage->content);
        $this->assertSame(['version' => 1, 'error' => 'trace_failed'], $result->assistantMessage->fresh()->metadata['trace']);
    }

    /** @test */
    public function the_observer_handed_to_the_pipeline_is_guarded(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $ai = $this->mockAi();
        $options = null;
        $ai->shouldReceive('answerQuestion')->once()->andReturnUsing(function ($q, $o) use (&$options) {
            $options = $o;

            return ['answer' => 'ok', 'cypher' => null, 'data' => [['x' => 1]], 'suggestions' => [], 'referenced_files' => []];
        });

        $this->ask($conversation);

        $this->assertInstanceOf(\Condoedge\Ai\Support\GuardedPipelineObserver::class, $options['observer']);
    }

    /** @test */
    public function an_extra_observer_sees_the_whole_turn_beside_the_persisted_trace(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $ai = $this->mockAi();
        $options = null;
        $this->answeringThroughObserver($ai, $options);
        $recorder = new \Condoedge\Ai\Support\RecordingPipelineObserver();

        $result = $this->app->make(AskQuestionUseCase::class)->execute(new AskQuestionInput(
            question: 'How many units?',
            conversation: $conversation,
            style: 'friendly',
            user: (object) ['id' => 7],
            observer: $recorder,
            pipelineOptions: ['store_examples' => false, 'style' => 'concise', 'observer' => 'smuggled'],
        ));

        $this->assertSame(
            ['security_gate', 'conversation_context', 'understanding', 'composition', 'pipeline'],
            $recorder->finishedStages()
        );
        $this->assertSame('QU SYSTEM PROMPT', $recorder->prompts('understanding')[0][0]['content']);
        $pipelineStart = array_values(array_filter(
            $recorder->events(),
            static fn (array $e) => $e['event'] === 'stage_started' && $e['stage'] === 'pipeline'
        ))[0] ?? [];
        $this->assertSame('How many units?', $pipelineStart['data']['question'] ?? null, 'the pipeline stage start carries the question the pipeline ran with');
        $this->assertFalse($options['store_examples'], 'caller pipeline options reach answerQuestion');
        $this->assertSame('friendly', $options['style'], "caller options never override the turn's own keys");
        $this->assertInstanceOf(\Condoedge\Ai\Support\GuardedPipelineObserver::class, $options['observer']);
        $this->assertIsArray($result->assistantMessage->fresh()->metadata['trace'] ?? null, 'the persisted trace still records');
    }

    /** @test */
    public function only_allowlisted_pipeline_options_reach_the_pipeline(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $ai = $this->mockAi();
        $options = null;
        $this->answeringThroughObserver($ai, $options);

        $this->app->make(AskQuestionUseCase::class)->execute(new AskQuestionInput(
            question: 'How many units?',
            conversation: $conversation,
            style: 'friendly',
            user: (object) ['id' => 7],
            pipelineOptions: [
                'store_examples' => false,
                'skip_security' => true,
                'allow_write' => true,
                'read_only' => false,
                'limit' => 100000,
                'capture_prompts' => true,
            ],
        ));

        $this->assertSame(['store_examples'], AskQuestionUseCase::ALLOWED_PIPELINE_OPTIONS);
        $this->assertFalse($options['store_examples'], 'store_examples passes');
        foreach (['skip_security', 'allow_write', 'read_only', 'limit', 'capture_prompts'] as $key) {
            $this->assertArrayNotHasKey($key, $options, "{$key} must never reach the pipeline from a caller");
        }
    }

    /** @test */
    public function the_turn_result_carries_the_pipeline_response_metadata(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $ai = $this->mockAi();
        $ai->shouldReceive('answerQuestion')->once()->andReturn([
            'answer' => 'ok', 'cypher' => null, 'data' => [['x' => 1]], 'suggestions' => [], 'referenced_files' => [],
            'metadata' => ['response' => ['style' => 'friendly', 'result_count' => 1]],
        ]);

        $result = $this->ask($conversation);

        $this->assertSame(['response' => ['style' => 'friendly', 'result_count' => 1]], $result->metadata);
    }

    /** @test */
    public function no_trace_is_recorded_when_disabled(): void
    {
        config(['ai.trace.enabled' => false]);
        $conversation = AiConversation::create(['user_id' => 1]);
        $ai = $this->mockAi();
        $options = null;
        $ai->shouldReceive('answerQuestion')->once()->andReturnUsing(function ($q, $o) use (&$options) {
            $options = $o;

            return ['answer' => 'ok', 'cypher' => null, 'data' => [['x' => 1]], 'suggestions' => [], 'referenced_files' => []];
        });

        $result = $this->ask($conversation);

        $this->assertArrayNotHasKey('observer', $options);
        $this->assertArrayNotHasKey('trace', $result->assistantMessage->fresh()->metadata ?? []);
    }
}
