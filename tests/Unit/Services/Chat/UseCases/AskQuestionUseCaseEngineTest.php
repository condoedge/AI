<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat\UseCases;

use Condoedge\Ai\Contracts\AnswerEngineInterface;
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\Inputs\AskQuestionInput;
use Condoedge\Ai\Services\Chat\UseCases\AskQuestionUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * ai.engine: a chat turn is answered by the question pipeline unless its caller, or the
 * default, names a registered engine; the engine that answered is on the trace.
 */
class AskQuestionUseCaseEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('entities', []);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../../database/migrations');
    }

    /** @return array{0: string, 1: ?string} the answer and the engine on the trace */
    private function ask(array $pipelineOptions, bool $pipelineAnswers): array
    {
        $ai = Mockery::mock();
        $this->app->instance('ai', $ai);
        AI::swap($ai);
        $ai->shouldReceive('getSchema')->andReturn(['labels' => [], 'relationships' => []]);
        $ai->shouldReceive('answerQuestion')->times($pipelineAnswers ? 1 : 0)
            ->andReturn(['answer' => 'From the pipeline.', 'cypher' => null, 'data' => [], 'suggestions' => [], 'referenced_files' => []]);

        $result = $this->app->make(AskQuestionUseCase::class)->execute(new AskQuestionInput(
            question: 'Who answers?',
            conversation: AiConversation::create(['user_id' => 1]),
            style: 'friendly',
            user: (object) ['id' => 7],
            pipelineOptions: $pipelineOptions,
        ));
        $stages = array_column($result->assistantMessage->fresh()->metadata['trace']['stages'], null, 'stage');

        return [$result->answer, $stages['engine']['data']['engine'] ?? null];
    }

    private function registerOtherEngine(): void
    {
        $engine = new class implements AnswerEngineInterface {
            public function answer(string $question, array $options = []): array
            {
                return ['answer' => 'From the other engine.', 'cypher' => null, 'data' => [], 'suggestions' => [], 'referenced_files' => []];
            }
        };
        config(['ai.engine.engines' => ['v2' => $engine::class]]);
    }

    /** @test */
    public function without_another_engine_the_pipeline_answers_and_nothing_is_stamped(): void
    {
        $this->assertSame(['From the pipeline.', null], $this->ask(['engine' => 'v2'], true));
    }

    /** @test */
    public function the_engine_the_caller_names_answers(): void
    {
        $this->registerOtherEngine();

        $this->assertSame(['From the other engine.', 'v2'], $this->ask(['engine' => 'v2'], false));
    }

    /** @test */
    public function the_default_engine_answers_and_the_caller_can_still_name_the_pipeline(): void
    {
        $this->registerOtherEngine();
        config(['ai.engine.default' => 'v2']);

        $this->assertSame(['From the other engine.', 'v2'], $this->ask([], false));
        $this->assertSame(['From the pipeline.', 'v1'], $this->ask(['engine' => 'v1'], true));
    }

    /** @test */
    public function an_engine_that_is_not_registered_falls_back_to_the_pipeline(): void
    {
        $this->registerOtherEngine();

        $this->assertSame(['From the pipeline.', 'v1'], $this->ask(['engine' => 'v3'], true));
    }
}
