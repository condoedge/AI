<?php
// tests/Unit/Models/AiConversationTest.php

namespace Condoedge\Ai\Tests\Unit\Models;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

class AiConversationTest extends TestCase
{
    use RefreshDatabase;

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
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    /** @test */
    public function it_creates_conversation_with_uuid(): void
    {
        $conversation = AiConversation::create([
            'status' => 'active',
        ]);

        $this->assertNotNull($conversation->uuid);
        $this->assertEquals('active', $conversation->status);
    }

    /** @test */
    public function it_adds_messages_to_conversation(): void
    {
        $conversation = AiConversation::create(['status' => 'active']);

        $message = $conversation->addMessage('user', 'Hello');

        $this->assertEquals('user', $message->role);
        $this->assertEquals('Hello', $message->content);
        $this->assertNotNull($conversation->fresh()->last_message_at);
    }

    /** @test */
    public function it_auto_generates_title_from_first_message(): void
    {
        $conversation = AiConversation::create(['status' => 'active']);

        $conversation->addMessage('user', 'How many customers do we have?');

        $this->assertEquals('How many customers do we have?', $conversation->fresh()->title);
    }

    /** @test */
    public function it_retrieves_recent_messages_in_order(): void
    {
        $conversation = AiConversation::create(['status' => 'active']);

        $conversation->addMessage('user', 'First');
        $conversation->addMessage('assistant', 'Response to first');
        $conversation->addMessage('user', 'Second');

        $messages = $conversation->getRecentMessages(10);

        $this->assertCount(3, $messages);
        $this->assertEquals('First', $messages[0]['content']);
        $this->assertEquals('Second', $messages[2]['content']);
    }
}
