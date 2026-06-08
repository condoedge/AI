<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat\UseCases;

use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\Results\ChatTurnResult;
use Condoedge\Ai\Services\Chat\UseCases\EditMessageAndRegenerateUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;
use Mockery;

class EditMessageAndRegenerateUseCaseTest extends TestCase
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

    private function useCase(): EditMessageAndRegenerateUseCase
    {
        return $this->app->make(EditMessageAndRegenerateUseCase::class);
    }

    private function mockAi(): \Mockery\MockInterface
    {
        $mock = Mockery::mock();
        $this->app->instance('ai', $mock);
        AI::swap($mock);

        return $mock;
    }

    /** @test */
    public function it_updates_content_truncates_trailing_and_regenerates(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'What is 2+2?',
            'created_at' => now()->subMinutes(2),
        ]);
        $oldAssistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'It is 4.',
            'created_at' => now()->subMinute(),
        ]);

        $ai = $this->mockAi();
        $ai->shouldReceive('getSchema')->andReturn(['labels' => [], 'relationships' => []]);
        $ai->shouldReceive('answerQuestion')->once()->andReturn([
            'answer' => 'It is 6.',
            'cypher' => null,
            'data' => [],
            'suggestions' => [],
            'referenced_files' => [],
        ]);

        $result = $this->useCase()->execute(
            $conversation,
            $userMsg,
            'What is 3+3?',
            'friendly',
            (object) ['id' => 1]
        );

        $this->assertInstanceOf(ChatTurnResult::class, $result);
        $this->assertFalse($result->failed());

        // Content was updated in place.
        $this->assertSame('What is 3+3?', $userMsg->fresh()->content);

        // Old trailing assistant message was truncated; one new assistant message exists.
        $this->assertDatabaseMissing('ai_messages', ['id' => $oldAssistant->id]);
        $this->assertSame(1, $conversation->messages()->where('role', 'assistant')->count());
        $this->assertSame(1, $conversation->messages()->where('role', 'user')->count());
        $this->assertSame('It is 6.', $result->answer);
    }

    /** @test */
    public function it_throws_for_non_user_message(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $assistant = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Hi']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Can only edit user messages');

        $this->useCase()->execute($conversation, $assistant, 'new content', 'friendly', (object) ['id' => 1]);
    }
}
