<?php

namespace Condoedge\Ai\Tests\Unit\Kompo\Modals;

use Condoedge\Ai\Kompo\Modals\EditMessageModal;
use Condoedge\Ai\Kompo\Presenters\ChatPresenter;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\Results\ChatTurnResult;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Orchestra\Testbench\TestCase;
use Mockery;

/**
 * Tests for EditMessageModal::updateMessageAndGetResponse() method.
 *
 * These tests verify that the save action routes through ChatPresenter::handleEditAndRegenerate
 * (the single Kompo<->app seam). Content update + trailing-message truncation + regeneration
 * are owned by EditMessageAndRegenerateUseCase (tested separately); the modal only collects the
 * request input and delegates.
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
    public function test_save_routes_through_presenter_with_edited_content(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Original question',
            'created_at' => now()->subMinute(),
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Original answer',
            'created_at' => now(),
        ]);

        // The presenter must receive the conversation, the edited user message, the new content
        // (truncation + content update is owned by the use case behind the presenter), the
        // configured style, and the acting user.
        $mockPresenter = Mockery::mock(ChatPresenter::class);
        $mockPresenter->shouldReceive('handleEditAndRegenerate')
            ->once()
            ->with(
                Mockery::on(fn($c) => $c->id === $conversation->id),
                Mockery::on(fn($m) => $m->id === $userMsg->id),
                'Edited question',
                'friendly',
                Mockery::on(fn($u) => $u->id === $this->user->id),
            )
            ->andReturn(new ChatTurnResult(answer: 'New answer'));

        $this->app->instance(ChatPresenter::class, $mockPresenter);

        $mockSettings = $this->createMockSettings();
        $this->app->instance(ChatSettingsInterface::class, $mockSettings);

        request()->merge(['content' => 'Edited question']);

        $modal = new TestableEditMessageModal($userMsg, $conversation, $mockSettings);

        $modal->updateMessageAndGetResponse();

        // Presenter was called (verified by Mockery)
        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function test_save_does_nothing_with_empty_content(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Original',
            'created_at' => now(),
        ]);

        // Presenter should NOT be called
        $mockPresenter = Mockery::mock(ChatPresenter::class);
        $mockPresenter->shouldNotReceive('handleEditAndRegenerate');
        $this->app->instance(ChatPresenter::class, $mockPresenter);

        $mockSettings = $this->createMockSettings();
        $this->app->instance(ChatSettingsInterface::class, $mockSettings);

        request()->merge(['content' => '']);

        $modal = new TestableEditMessageModal($userMsg, $conversation, $mockSettings);
        $modal->updateMessageAndGetResponse();

        // Message should NOT be updated
        $this->assertEquals('Original', $userMsg->fresh()->content);
    }

    /**
     * @test
     */
    public function test_save_does_nothing_with_null_message(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);

        // Presenter should NOT be called
        $mockPresenter = Mockery::mock(ChatPresenter::class);
        $mockPresenter->shouldNotReceive('handleEditAndRegenerate');
        $this->app->instance(ChatPresenter::class, $mockPresenter);

        $mockSettings = $this->createMockSettings();
        $this->app->instance(ChatSettingsInterface::class, $mockSettings);

        request()->merge(['content' => 'Some content']);

        // Create modal with null message
        $modal = new TestableEditMessageModal(null, $conversation, $mockSettings);
        $modal->updateMessageAndGetResponse();

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
