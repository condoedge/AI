<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\Support\ConversationActions;
use Condoedge\Ai\Models\AiConversation;
use Illuminate\Contracts\Auth\Authenticatable;
use Orchestra\Testbench\TestCase;

/**
 * Tests for ConversationActions — the single, ownership-scoped home for the chat panel's
 * pin/feedback/archive/delete mutations.
 *
 * The behavior under test is the authorization gate: every mutation must be scoped to the
 * acting user passed in, so a conversation owned by another user is a no-op (the old inline
 * `where('user_id', auth()->id())` rule, now centralized and consistent across all four).
 */
class ConversationActionsTest extends TestCase
{
    protected ConversationActions $actions;
    protected $owner;
    protected $intruder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actions = new ConversationActions();
        $this->owner = $this->makeUser(1);
        $this->intruder = $this->makeUser(2);
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
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    protected function makeUser(int $id): Authenticatable
    {
        return new class($id) implements Authenticatable {
            public function __construct(public $id) {}
            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return $this->id; }
            public function getAuthPassword() { return 'password'; }
            public function getAuthPasswordName() { return 'password'; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return 'remember_token'; }
        };
    }

    /** @test */
    public function toggle_pin_flips_the_pinned_flag_for_the_owner(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->owner->id]);

        $this->assertTrue($this->actions->togglePin($conversation->id, $this->owner));
        $this->assertTrue($conversation->fresh()->metadata['pinned']);

        // Toggling again flips it back off.
        $this->assertTrue($this->actions->togglePin($conversation->id, $this->owner));
        $this->assertFalse($conversation->fresh()->metadata['pinned']);
    }

    /** @test */
    public function toggle_pin_is_a_no_op_for_a_non_owner(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->owner->id]);

        $this->assertFalse($this->actions->togglePin($conversation->id, $this->intruder));
        $this->assertNull($conversation->fresh()->metadata['pinned'] ?? null);
    }

    /** @test */
    public function record_feedback_writes_only_when_the_owner_owns_the_conversation(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->owner->id]);
        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'answer',
        ]);

        // Owner can record feedback.
        $this->assertTrue($this->actions->recordFeedback($conversation->id, $message->id, 'positive', $this->owner));
        $this->assertSame('positive', $message->fresh()->metadata['feedback']);

        // Intruder cannot — the message is untouched.
        $this->assertFalse($this->actions->recordFeedback($conversation->id, $message->id, 'negative', $this->intruder));
        $this->assertSame('positive', $message->fresh()->metadata['feedback']);
    }

    /** @test */
    public function archive_sets_status_only_for_the_owner(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->owner->id, 'status' => 'active']);

        $this->assertFalse($this->actions->archive($conversation->id, $this->intruder));
        $this->assertSame('active', $conversation->fresh()->status);

        $this->assertTrue($this->actions->archive($conversation->id, $this->owner));
        $this->assertSame('archived', $conversation->fresh()->status);
    }

    /** @test */
    public function delete_removes_the_conversation_only_for_the_owner(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->owner->id]);

        $this->assertFalse($this->actions->delete($conversation->id, $this->intruder));
        $this->assertNotNull(AiConversation::find($conversation->id));

        $this->assertTrue($this->actions->delete($conversation->id, $this->owner));
        $this->assertNull(AiConversation::find($conversation->id));
    }
}
