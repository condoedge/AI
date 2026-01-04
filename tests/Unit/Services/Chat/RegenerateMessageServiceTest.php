<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Services\Chat\RegenerateMessageService;
use Condoedge\Ai\Services\Chat\AiChatService;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
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
        // Set up in-memory SQLite database for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Run migrations for the AI tables
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
    }

    /** @test */
    public function it_deletes_messages_after_target_user_message(): void
    {
        // Create conversation with messages
        $conversation = AiConversation::create(['user_id' => 1]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'What is 2+2?',
            'created_at' => now()->subMinutes(3),
        ]);
        $assistantMsg1 = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'It is 4.',
            'created_at' => now()->subMinutes(2),
        ]);
        $userMsg2 = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Thanks!',
            'created_at' => now()->subMinute(),
        ]);
        $assistantMsg2 = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'You are welcome.',
            'created_at' => now(),
        ]);

        // Mock AiChatService to avoid actual AI calls
        $mockChatService = Mockery::mock(AiChatService::class);
        $mockChatService->shouldReceive('regenerateResponse')
            ->once()
            ->with($conversation, $userMsg, Mockery::any())
            ->andReturn(['answer' => 'Regenerated: 4']);

        $service = new RegenerateMessageService($mockChatService);

        $result = $service->regenerateFromMessage($conversation, $userMsg, null);

        // Verify messages after userMsg are deleted
        $this->assertDatabaseMissing('ai_messages', ['id' => $assistantMsg1->id]);
        $this->assertDatabaseMissing('ai_messages', ['id' => $userMsg2->id]);
        $this->assertDatabaseMissing('ai_messages', ['id' => $assistantMsg2->id]);

        // Original user message still exists
        $this->assertDatabaseHas('ai_messages', ['id' => $userMsg->id]);
    }

    /** @test */
    public function it_does_not_duplicate_user_message(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Hello',
            'created_at' => now()->subMinute(),
        ]);
        $assistantMsg = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Hi there!',
            'created_at' => now(),
        ]);

        $mockChatService = Mockery::mock(AiChatService::class);
        $mockChatService->shouldReceive('regenerateResponse')
            ->once()
            ->andReturn(['answer' => 'Hello again!']);

        $service = new RegenerateMessageService($mockChatService);
        $service->regenerateFromMessage($conversation, $userMsg, null);

        // After regeneration, the old assistant message should be deleted
        // and we should have exactly 1 user message (the original)
        $this->assertEquals(1, $conversation->messages()->where('role', 'user')->count());
    }

    /** @test */
    public function it_throws_exception_for_non_user_message(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $assistantMsg = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Hello!',
        ]);

        $mockChatService = Mockery::mock(AiChatService::class);
        $service = new RegenerateMessageService($mockChatService);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Can only regenerate from user messages');

        $service->regenerateFromMessage($conversation, $assistantMsg, null);
    }

    /** @test */
    public function it_can_regenerate_from_assistant_message_by_finding_preceding_user_message(): void
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

        $mockChatService = Mockery::mock(AiChatService::class);
        $mockChatService->shouldReceive('regenerateResponse')
            ->once()
            ->with($conversation, Mockery::on(function ($msg) use ($userMsg) {
                return $msg->id === $userMsg->id;
            }), Mockery::any())
            ->andReturn(['answer' => 'Regenerated response']);

        $service = new RegenerateMessageService($mockChatService);
        $service->regenerateFromAssistantMessage($conversation, $assistantMsg, null);

        // Verify the assistant message was deleted
        $this->assertDatabaseMissing('ai_messages', ['id' => $assistantMsg->id]);

        // User message still exists
        $this->assertDatabaseHas('ai_messages', ['id' => $userMsg->id]);
    }

    /** @test */
    public function it_throws_exception_when_no_preceding_user_message_found(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $assistantMsg = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Hello!',
        ]);

        $mockChatService = Mockery::mock(AiChatService::class);
        $service = new RegenerateMessageService($mockChatService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No user message found before assistant message');

        $service->regenerateFromAssistantMessage($conversation, $assistantMsg, null);
    }

    /** @test */
    public function regenerate_from_assistant_throws_for_non_assistant_message(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Hello!',
        ]);

        $mockChatService = Mockery::mock(AiChatService::class);
        $service = new RegenerateMessageService($mockChatService);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected assistant message');

        $service->regenerateFromAssistantMessage($conversation, $userMsg, null);
    }
}
