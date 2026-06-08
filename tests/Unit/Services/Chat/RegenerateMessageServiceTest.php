<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Chat\RegenerateMessageService;
use Condoedge\Ai\Services\Chat\Results\ChatTurnResult;
use Condoedge\Ai\Services\Chat\UseCases\RegenerateAnswerUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;
use Mockery;

class RegenerateMessageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Don't load the full AI service provider to avoid API key requirements
     */
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
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
    }

    /** @test */
    public function it_delegates_regenerate_from_message_to_the_use_case(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $userMsg = $conversation->messages()->create(['role' => 'user', 'content' => 'What is 2+2?']);

        $useCase = Mockery::mock(RegenerateAnswerUseCase::class);
        $useCase->shouldReceive('execute')
            ->once()
            ->with(Mockery::on(fn ($c) => $c === $conversation), Mockery::on(fn ($m) => $m->id === $userMsg->id), 'friendly', null, false)
            ->andReturn(new ChatTurnResult(answer: 'Regenerated: 4'));

        $service = new RegenerateMessageService($useCase);

        $result = $service->regenerateFromMessage($conversation, $userMsg, null);

        $this->assertSame('Regenerated: 4', $result['answer']);
    }

    /** @test */
    public function it_finds_preceding_user_message_then_delegates(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'What is 2+2?',
            'created_at' => now()->subMinute(),
        ]);
        $assistantMsg = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'It is 4.',
            'created_at' => now(),
        ]);

        $useCase = Mockery::mock(RegenerateAnswerUseCase::class);
        $useCase->shouldReceive('execute')
            ->once()
            ->with(Mockery::any(), Mockery::on(fn ($m) => $m->id === $userMsg->id), Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(new ChatTurnResult(answer: 'Regenerated'));

        $service = new RegenerateMessageService($useCase);
        $result = $service->regenerateFromAssistantMessage($conversation, $assistantMsg, null);

        $this->assertSame('Regenerated', $result['answer']);
    }

    /** @test */
    public function regenerate_from_assistant_throws_for_non_assistant_message(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $userMsg = $conversation->messages()->create(['role' => 'user', 'content' => 'Hello!']);

        $useCase = Mockery::mock(RegenerateAnswerUseCase::class);
        $service = new RegenerateMessageService($useCase);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected assistant message');

        $service->regenerateFromAssistantMessage($conversation, $userMsg, null);
    }

    /** @test */
    public function it_throws_exception_when_no_preceding_user_message_found(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $assistantMsg = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Hello!']);

        $useCase = Mockery::mock(RegenerateAnswerUseCase::class);
        $service = new RegenerateMessageService($useCase);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No user message found before assistant message');

        $service->regenerateFromAssistantMessage($conversation, $assistantMsg, null);
    }
}
