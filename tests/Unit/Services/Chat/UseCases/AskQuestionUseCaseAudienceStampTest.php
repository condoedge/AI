<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat\UseCases;

use Condoedge\Ai\DTOs\ResolvedAudience;
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\Inputs\AskQuestionInput;
use Condoedge\Ai\Services\Chat\UseCases\AskQuestionUseCase;
use Condoedge\Ai\Tests\Fixtures\FixedAudienceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * Who asked, as the host resolved it, is stamped on the turn: an `audience` stage in
 * the trace (the eval reads it) and `metadata.audience` on the assistant message (the
 * review page filters on it). Nothing is stamped without ai.audiences.
 */
class AskQuestionUseCaseAudienceStampTest extends TestCase
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

    private function ask(): array
    {
        $ai = Mockery::mock();
        $this->app->instance('ai', $ai);
        AI::swap($ai);
        $ai->shouldReceive('getSchema')->andReturn(['labels' => [], 'relationships' => []]);
        $ai->shouldReceive('answerQuestion')->once()->andReturn(['answer' => 'Voici.', 'cypher' => null, 'data' => [], 'suggestions' => [], 'referenced_files' => []]);

        $result = $this->app->make(AskQuestionUseCase::class)->execute(new AskQuestionInput(
            question: 'Comment obtenir mon reçu?',
            conversation: AiConversation::create(['user_id' => 1]),
            style: 'friendly',
            user: (object) ['id' => 7],
        ));
        $metadata = $result->assistantMessage->fresh()->metadata;

        return [$metadata, array_column($metadata['trace']['stages'], null, 'stage')];
    }

    /** @test */
    public function the_resolved_audience_and_role_are_on_the_trace_and_the_message(): void
    {
        FixedAudienceResolver::$next = new ResolvedAudience('organisateur', 'owner');
        config(['ai.audiences' => ['resolver' => FixedAudienceResolver::class, 'map' => ['organisateur' => ['documents' => ['organisateur']]]]]);

        [$metadata, $stages] = $this->ask();

        $this->assertSame(['key' => 'organisateur', 'role' => 'owner'], $metadata['audience']);
        $this->assertSame(['audience' => 'organisateur', 'role' => 'owner'], $stages['audience']['data']);
    }

    /** @test */
    public function an_unresolved_user_is_stamped_as_such(): void
    {
        FixedAudienceResolver::$next = null;
        config(['ai.audiences' => ['resolver' => FixedAudienceResolver::class, 'map' => []]]);

        [$metadata] = $this->ask();

        $this->assertSame(['key' => null, 'role' => null], $metadata['audience']);
    }

    /** @test */
    public function without_audiences_nothing_is_stamped(): void
    {
        [$metadata, $stages] = $this->ask();

        $this->assertArrayNotHasKey('audience', $metadata);
        $this->assertArrayNotHasKey('audience', $stages);
    }
}
