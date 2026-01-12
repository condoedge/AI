<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Policies;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Policies\AiConversationPolicy;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class AiConversationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private AiConversationPolicy $policy;

    /**
     * Define environment setup for these tests.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Set up API keys to prevent OpenAI validation errors during service provider boot
        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('ai.embedding.default', 'openai');
        $app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
    }

    public function setUp(): void
    {
        parent::setUp();

        // Run migrations
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');

        $this->policy = new AiConversationPolicy();
    }

    public function test_user_can_view_own_conversation(): void
    {
        $user = $this->createUser(1);
        $conversation = AiConversation::create([
            'user_id' => 1,
            'title' => 'Test Conversation',
        ]);

        $this->assertTrue($this->policy->view($user, $conversation));
    }

    public function test_user_cannot_view_other_users_conversation(): void
    {
        $user = $this->createUser(1);
        $conversation = AiConversation::create([
            'user_id' => 999, // Different user
            'title' => 'Test Conversation',
        ]);

        $this->assertFalse($this->policy->view($user, $conversation));
    }

    public function test_team_member_can_view_team_conversation(): void
    {
        $user = $this->createUserInTeam(1, 5);
        $conversation = AiConversation::create([
            'user_id' => 999, // Different user
            'team_id' => 5,   // Same team
            'title' => 'Test Conversation',
        ]);

        $this->assertTrue($this->policy->view($user, $conversation));
    }

    public function test_user_cannot_view_other_teams_conversation(): void
    {
        $user = $this->createUserInTeam(1, 5);
        $conversation = AiConversation::create([
            'user_id' => 999, // Different user
            'team_id' => 10,  // Different team
            'title' => 'Test Conversation',
        ]);

        $this->assertFalse($this->policy->view($user, $conversation));
    }

    public function test_owner_can_send_message_to_conversation(): void
    {
        $user = $this->createUser(1);
        $conversation = AiConversation::create([
            'user_id' => 1,
            'title' => 'Test Conversation',
        ]);

        $this->assertTrue($this->policy->sendMessage($user, $conversation));
    }

    public function test_team_member_can_send_message_to_team_conversation(): void
    {
        $user = $this->createUserInTeam(1, 5);
        $conversation = AiConversation::create([
            'user_id' => 999, // Different user
            'team_id' => 5,   // Same team
            'title' => 'Test Conversation',
        ]);

        $this->assertTrue($this->policy->sendMessage($user, $conversation));
    }

    public function test_non_team_member_cannot_send_message(): void
    {
        $user = $this->createUserInTeam(1, 5);
        $conversation = AiConversation::create([
            'user_id' => 999, // Different user
            'team_id' => 10,  // Different team
            'title' => 'Test Conversation',
        ]);

        $this->assertFalse($this->policy->sendMessage($user, $conversation));
    }

    public function test_owner_can_delete_conversation(): void
    {
        $user = $this->createUser(1);
        $conversation = AiConversation::create([
            'user_id' => 1,
            'title' => 'Test Conversation',
        ]);

        $this->assertTrue($this->policy->delete($user, $conversation));
    }

    public function test_non_owner_cannot_delete_conversation(): void
    {
        $user = $this->createUser(1);
        $conversation = AiConversation::create([
            'user_id' => 999, // Different user
            'title' => 'Test Conversation',
        ]);

        $this->assertFalse($this->policy->delete($user, $conversation));
    }

    public function test_team_member_cannot_delete_team_conversation_they_dont_own(): void
    {
        $user = $this->createUserInTeam(1, 5);
        $conversation = AiConversation::create([
            'user_id' => 999, // Different user
            'team_id' => 5,   // Same team, but user doesn't own it
            'title' => 'Test Conversation',
        ]);

        // Team members can view but NOT delete conversations they don't own
        $this->assertFalse($this->policy->delete($user, $conversation));
    }

    /**
     * Create a mock user with given ID
     *
     * @param int $userId
     * @return Authenticatable
     */
    private function createUser(int $userId): Authenticatable
    {
        return new TestUser($userId);
    }

    /**
     * Create a mock user that belongs to a team
     *
     * @param int $userId
     * @param int $teamId
     * @return Authenticatable
     */
    private function createUserInTeam(int $userId, int $teamId): Authenticatable
    {
        return new TestUserWithTeam($userId, $teamId);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

/**
 * Simple test user class for policy testing
 */
class TestUser implements Authenticatable
{
    public int $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}

/**
 * Test user class with team membership support
 */
class TestUserWithTeam extends TestUser
{
    private int $teamId;

    public function __construct(int $id, int $teamId)
    {
        parent::__construct($id);
        $this->teamId = $teamId;
    }

    public function belongsToTeam(int $teamId): bool
    {
        return $this->teamId === $teamId;
    }
}
