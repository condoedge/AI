<?php

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\RegenerateMessageService;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Orchestra\Testbench\TestCase;
use Mockery;

/**
 * Tests for MessagesQuery::regenerate() method.
 *
 * These tests verify that the regenerate() method properly delegates
 * to RegenerateMessageService instead of the old inline implementation.
 */
class MessagesQueryTest extends TestCase
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
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
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
     * Test that regenerate method calls RegenerateMessageService.regenerateFromAssistantMessage.
     *
     * This test uses a subclass to bypass Kompo initialization while testing
     * the actual regenerate() method implementation.
     *
     * @test
     */
    public function regenerate_uses_regenerate_service(): void
    {
        // Create conversation with messages
        $conversation = AiConversation::create(['user_id' => $this->user->id]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Test question',
            'created_at' => now()->subMinute(),
        ]);
        $assistantMsg = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Test answer',
            'created_at' => now(),
        ]);

        // Mock the service
        $mockService = Mockery::mock(RegenerateMessageService::class);
        $mockService->shouldReceive('regenerateFromAssistantMessage')
            ->once()
            ->with(
                Mockery::on(fn($c) => $c->id === $conversation->id),
                Mockery::on(fn($m) => $m->id === $assistantMsg->id),
                Mockery::on(fn($u) => $u->id === $this->user->id),
                Mockery::any()
            )
            ->andReturn(['answer' => 'New answer']);

        $this->app->instance(RegenerateMessageService::class, $mockService);

        // Create mock settings
        $mockSettings = $this->createMockSettings();

        // Create a testable subclass that bypasses Kompo initialization
        $query = new TestableMessagesQuery($conversation, $mockSettings);

        // Call the regenerate method
        $query->regenerate($assistantMsg->id);

        // Explicit assertion - Mockery will verify the expectation in tearDown
        // but we add this to avoid "risky test" warning
        $this->assertTrue(true, 'Service was called (verified by Mockery)');
    }

    /**
     * Test that regenerate returns early when conversation is null.
     *
     * @test
     */
    public function regenerate_returns_early_when_no_conversation(): void
    {
        // Mock the service - should NOT be called
        $mockService = Mockery::mock(RegenerateMessageService::class);
        $mockService->shouldNotReceive('regenerateFromAssistantMessage');

        $this->app->instance(RegenerateMessageService::class, $mockService);

        // Create a testable subclass with null conversation
        $query = new TestableMessagesQuery(null, null);

        // Call the regenerate method
        $query->regenerate(999);

        // No error, and the service was not called (verified by Mockery shouldNotReceive)
        $this->assertTrue(true);
    }

    /**
     * Test that regenerate returns early for user messages.
     *
     * @test
     */
    public function regenerate_returns_early_for_user_message(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Test question',
        ]);

        // Mock the service - should NOT be called for user messages
        $mockService = Mockery::mock(RegenerateMessageService::class);
        $mockService->shouldNotReceive('regenerateFromAssistantMessage');

        $this->app->instance(RegenerateMessageService::class, $mockService);

        // Create a testable subclass
        $query = new TestableMessagesQuery($conversation, null);

        // Call the regenerate method with a user message ID
        $query->regenerate($userMsg->id);

        // No error, and the service was not called
        $this->assertTrue(true);
    }

    /**
     * Test that regenerate returns early for nonexistent messages.
     *
     * @test
     */
    public function regenerate_returns_early_for_nonexistent_message(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);

        // Mock the service - should NOT be called for nonexistent messages
        $mockService = Mockery::mock(RegenerateMessageService::class);
        $mockService->shouldNotReceive('regenerateFromAssistantMessage');

        $this->app->instance(RegenerateMessageService::class, $mockService);

        // Create a testable subclass
        $query = new TestableMessagesQuery($conversation, null);

        // Call the regenerate method with a nonexistent message ID
        $query->regenerate(99999);

        // No error, and the service was not called
        $this->assertTrue(true);
    }
}

/**
 * Testable subclass that bypasses Kompo initialization.
 */
class TestableMessagesQuery extends MessagesQuery
{
    private $testSettings;

    public function __construct($conversation, $settings)
    {
        // Don't call parent constructor to avoid Kompo boot
        $this->conversation = $conversation;
        $this->testSettings = $settings;
    }

    public function settings(): ChatSettingsInterface
    {
        return $this->testSettings;
    }
}
