<?php

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Kompo\Presenters\ChatPresenter;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\Results\ChatTurnResult;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Orchestra\Testbench\TestCase;
use Mockery;

/**
 * Tests for MessagesQuery::regenerate() method.
 *
 * These tests verify that the regenerate() method routes through ChatPresenter
 * (the single Kompo<->app seam) instead of the old inline / legacy-service implementation.
 * The refresh button lives on the assistant bubble, so regenerate() resolves the user
 * message that the assistant answered and hands it to ChatPresenter::handleRegenerate.
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
     * Test that regenerate() routes through ChatPresenter::handleRegenerate, resolving the
     * USER message that the clicked assistant message answered.
     *
     * @test
     */
    public function regenerate_routes_through_presenter_with_resolved_user_message(): void
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

        // Mock the presenter: handleRegenerate must receive the conversation + the resolved
        // user message (not the assistant message), the style, and the acting user.
        $mockPresenter = Mockery::mock(ChatPresenter::class);
        $mockPresenter->shouldReceive('handleRegenerate')
            ->once()
            ->with(
                Mockery::on(fn($c) => $c->id === $conversation->id),
                Mockery::on(fn($m) => $m->id === $userMsg->id && $m->role === 'user'),
                'friendly',
                Mockery::on(fn($u) => $u->id === $this->user->id),
            )
            ->andReturn(new ChatTurnResult(answer: 'New answer'));

        $this->app->instance(ChatPresenter::class, $mockPresenter);

        // Create mock settings
        $mockSettings = $this->createMockSettings();

        // Create a testable subclass that bypasses Kompo initialization
        $query = new TestableMessagesQuery($conversation, $mockSettings);

        // Call the regenerate method with the ASSISTANT message id
        $query->regenerate($assistantMsg->id);

        $this->assertTrue(true, 'Presenter was called (verified by Mockery)');
    }

    /**
     * Test that regenerate returns early when conversation is null.
     *
     * @test
     */
    public function regenerate_returns_early_when_no_conversation(): void
    {
        // Mock the presenter - should NOT be called
        $mockPresenter = Mockery::mock(ChatPresenter::class);
        $mockPresenter->shouldNotReceive('handleRegenerate');

        $this->app->instance(ChatPresenter::class, $mockPresenter);

        // Create a testable subclass with null conversation
        $query = new TestableMessagesQuery(null, null);

        // Call the regenerate method
        $query->regenerate(999);

        // No error, and the presenter was not called (verified by Mockery shouldNotReceive)
        $this->assertTrue(true);
    }

    /**
     * Test that regenerate returns early for user messages (refresh button is assistant-only).
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

        // Mock the presenter - should NOT be called for user messages
        $mockPresenter = Mockery::mock(ChatPresenter::class);
        $mockPresenter->shouldNotReceive('handleRegenerate');

        $this->app->instance(ChatPresenter::class, $mockPresenter);

        // Create a testable subclass
        $query = new TestableMessagesQuery($conversation, null);

        // Call the regenerate method with a user message ID
        $query->regenerate($userMsg->id);

        // No error, and the presenter was not called
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

        // Mock the presenter - should NOT be called for nonexistent messages
        $mockPresenter = Mockery::mock(ChatPresenter::class);
        $mockPresenter->shouldNotReceive('handleRegenerate');

        $this->app->instance(ChatPresenter::class, $mockPresenter);

        // Create a testable subclass
        $query = new TestableMessagesQuery($conversation, null);

        // Call the regenerate method with a nonexistent message ID
        $query->regenerate(99999);

        // No error, and the presenter was not called
        $this->assertTrue(true);
    }

    /**
     * Test that a clicked suggestion delivers the IDENTICAL PageContext the surface was
     * mounted with — askSuggestion must thread it exactly like the typed-question path
     * (ChatMessageForm::sendMessage), so a suggestion clicked from a floating chat on a
     * record page keeps its "this unit/invoice" binding.
     *
     * @test
     */
    public function ask_suggestion_forwards_the_mounted_page_context_to_the_presenter(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);

        $pageContext = new PageContext(
            route: 'units.show',
            entityType: 'Unit',
            entityId: 402,
            filters: ['status' => 'active'],
            label: 'Unit 402',
        );

        $mockPresenter = Mockery::mock(ChatPresenter::class);
        $mockPresenter->shouldReceive('handleSend')
            ->once()
            ->with(
                Mockery::on(fn($c) => $c->id === $conversation->id),
                'Show this unit payment history',
                'friendly',
                Mockery::on(fn($u) => $u->id === $this->user->id),
                Mockery::on(fn($pc) => $pc === $pageContext),
            )
            ->andReturn(new ChatTurnResult(answer: 'ok'));

        $this->app->instance(ChatPresenter::class, $mockPresenter);

        $query = new TestableMessagesQuery($conversation, $this->createMockSettings(), $pageContext);

        $query->askSuggestion('Show this unit payment history');

        $this->assertTrue(true, 'Presenter received the identical PageContext (verified by Mockery)');
    }

    /**
     * Test that a suggestion clicked on a surface WITHOUT page context stays unchanged:
     * handleSend receives a null pageContext.
     *
     * @test
     */
    public function ask_suggestion_without_page_context_passes_null_to_the_presenter(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);

        $mockPresenter = Mockery::mock(ChatPresenter::class);
        $mockPresenter->shouldReceive('handleSend')
            ->once()
            ->with(
                Mockery::on(fn($c) => $c->id === $conversation->id),
                'Show all units',
                'friendly',
                Mockery::on(fn($u) => $u->id === $this->user->id),
                null,
            )
            ->andReturn(new ChatTurnResult(answer: 'ok'));

        $this->app->instance(ChatPresenter::class, $mockPresenter);

        $query = new TestableMessagesQuery($conversation, $this->createMockSettings());

        $query->askSuggestion('Show all units');

        $this->assertTrue(true, 'Presenter received a null PageContext (verified by Mockery)');
    }
}

/**
 * Testable subclass that bypasses Kompo initialization.
 */
class TestableMessagesQuery extends MessagesQuery
{
    private $testSettings;

    public function __construct($conversation, $settings, ?PageContext $pageContext = null)
    {
        // Don't call parent constructor to avoid Kompo boot
        $this->conversation = $conversation;
        $this->testSettings = $settings;
        $this->pageContext = $pageContext;
    }

    public function settings(): ChatSettingsInterface
    {
        return $this->testSettings;
    }
}
