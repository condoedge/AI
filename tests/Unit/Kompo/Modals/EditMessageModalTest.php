<?php

namespace Condoedge\Ai\Tests\Unit\Kompo\Modals;

use Condoedge\Ai\Kompo\Modals\EditMessageModal;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\RegenerateMessageService;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Orchestra\Testbench\TestCase;
use Mockery;

/**
 * Tests for EditMessageModal::updateMessage() method.
 *
 * These tests verify that the updateMessage() method properly:
 * 1. Updates the message content
 * 2. Deletes subsequent messages
 * 3. Calls RegenerateMessageService to regenerate AI response
 */
class EditMessageModalTest extends TestCase
{
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createTestUser();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function createTestUser(): Authenticatable
    {
        return new class implements Authenticatable {
            public $id = 1;
            public $name = 'Test User';
            public $email = 'test@example.com';

            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return $this->id; }
            public function getAuthPassword() { return 'password'; }
            public function getAuthPasswordName() { return 'password'; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return 'remember_token'; }
        };
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

    /**
     * Create a mock settings object.
     */
    protected function createMockSettings(): ChatSettingsInterface
    {
        $mock = Mockery::mock(ChatSettingsInterface::class);
        $mock->shouldReceive('responseStyle')->andReturn('friendly');
        return $mock;
    }

    /**
     * @test
     */
    public function test_update_message_triggers_regeneration(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Original question',
            'created_at' => now()->subMinute(),
        ]);
        $assistantMsg = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Original answer',
            'created_at' => now(),
        ]);

        // Mock the regenerate service
        $mockService = Mockery::mock(RegenerateMessageService::class);
        $mockService->shouldReceive('regenerateFromMessage')
            ->once()
            ->with(
                Mockery::on(fn($c) => $c->id === $conversation->id),
                Mockery::on(fn($m) => $m->id === $userMsg->id && $m->content === 'Edited question'),
                Mockery::any(),
                Mockery::any()
            )
            ->andReturn(['answer' => 'New answer']);

        $this->app->instance(RegenerateMessageService::class, $mockService);

        // Mock settings
        $mockSettings = $this->createMockSettings();
        $this->app->instance(ChatSettingsInterface::class, $mockSettings);

        // Simulate request
        request()->merge(['content' => 'Edited question']);

        // Create a testable subclass that bypasses Kompo initialization
        $modal = new TestableEditMessageModal($userMsg, $conversation, $mockSettings);

        $modal->updateMessage();

        // Verify the message was updated
        $this->assertEquals('Edited question', $userMsg->fresh()->content);

        // Service was called (verified by Mockery)
        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function test_update_message_deletes_subsequent_messages(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Q1',
            'created_at' => now()->subMinutes(3),
        ]);
        $assistantMsg1 = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'A1',
            'created_at' => now()->subMinutes(2),
        ]);
        $userMsg2 = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Q2',
            'created_at' => now()->subMinute(),
        ]);

        $mockService = Mockery::mock(RegenerateMessageService::class);
        $mockService->shouldReceive('regenerateFromMessage')->andReturn(['answer' => 'New']);
        $this->app->instance(RegenerateMessageService::class, $mockService);

        // Mock settings
        $mockSettings = $this->createMockSettings();
        $this->app->instance(ChatSettingsInterface::class, $mockSettings);

        request()->merge(['content' => 'Edited Q1']);

        $modal = new TestableEditMessageModal($userMsg, $conversation, $mockSettings);

        $modal->updateMessage();

        // Messages after edited one should be deleted
        $this->assertDatabaseMissing('ai_messages', ['id' => $assistantMsg1->id]);
        $this->assertDatabaseMissing('ai_messages', ['id' => $userMsg2->id]);
    }

    /**
     * @test
     */
    public function test_update_message_does_nothing_with_empty_content(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Original',
            'created_at' => now(),
        ]);

        // Service should NOT be called
        $mockService = Mockery::mock(RegenerateMessageService::class);
        $mockService->shouldNotReceive('regenerateFromMessage');
        $this->app->instance(RegenerateMessageService::class, $mockService);

        // Mock settings
        $mockSettings = $this->createMockSettings();
        $this->app->instance(ChatSettingsInterface::class, $mockSettings);

        request()->merge(['content' => '']);

        $modal = new TestableEditMessageModal($userMsg, $conversation, $mockSettings);
        $modal->updateMessage();

        // Message should NOT be updated
        $this->assertEquals('Original', $userMsg->fresh()->content);
    }

    /**
     * @test
     */
    public function test_update_message_does_nothing_with_null_message(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);

        // Service should NOT be called
        $mockService = Mockery::mock(RegenerateMessageService::class);
        $mockService->shouldNotReceive('regenerateFromMessage');
        $this->app->instance(RegenerateMessageService::class, $mockService);

        // Mock settings
        $mockSettings = $this->createMockSettings();
        $this->app->instance(ChatSettingsInterface::class, $mockSettings);

        request()->merge(['content' => 'Some content']);

        // Create modal with null message
        $modal = new TestableEditMessageModal(null, $conversation, $mockSettings);
        $modal->updateMessage();

        // No error should occur
        $this->assertTrue(true);
    }
}

/**
 * Testable subclass that bypasses Kompo initialization.
 */
class TestableEditMessageModal extends EditMessageModal
{
    private $testSettings;

    public function __construct($message, $conversation, $settings)
    {
        // Don't call parent constructor to avoid Kompo boot
        $this->message = $message;
        $this->testSettings = $settings;
    }

    protected function settings(): ChatSettingsInterface
    {
        return $this->testSettings;
    }
}
