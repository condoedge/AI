<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Services\Chat\AiChatService;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Mockery;
use Orchestra\Testbench\TestCase;

class AiChatServiceContextTest extends TestCase
{
    private AiChatService $service;

    /**
     * Don't load the full AI service provider to avoid API key requirements
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Set up AI config
        $app['config']->set('ai.response_generation.default_style', 'friendly');
        $app['config']->set('ai.chat.show_metrics', false);
        $app['config']->set('ai.chat.max_history_messages', 10);
        $app['config']->set('app.name', 'Test App');
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new AiChatService();
    }

    /** @test */
    public function it_has_ask_with_conversation_method(): void
    {
        $this->assertTrue(
            method_exists($this->service, 'askWithConversation'),
            'AiChatService should have askWithConversation method'
        );
    }

    /** @test */
    public function it_has_context_manager_property(): void
    {
        // Test that getContextManager returns a ConversationContextManager
        $manager = $this->service->getContextManager();

        $this->assertInstanceOf(ConversationContextManager::class, $manager);
    }

    /** @test */
    public function get_schema_for_context_returns_schema_from_options(): void
    {
        $customSchema = ['labels' => ['CustomEntity', 'AnotherEntity']];

        // Use reflection to test protected method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getSchemaForContext');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, ['schema' => $customSchema]);

        $this->assertEquals($customSchema, $result);
    }

    /** @test */
    public function context_manager_is_lazy_loaded(): void
    {
        // Create two services
        $service1 = new AiChatService();
        $service2 = new AiChatService();

        // Get context managers
        $manager1 = $service1->getContextManager();
        $manager2 = $service2->getContextManager();

        // They should be different instances (not shared)
        $this->assertNotSame($manager1, $manager2);

        // But calling again on same service returns same instance
        $manager1Again = $service1->getContextManager();
        $this->assertSame($manager1, $manager1Again);
    }

    /** @test */
    public function askWithConversation_throws_when_user_missing_for_authenticated_route(): void
    {
        // Arrange
        $conversation = Mockery::mock(\Condoedge\Ai\Models\AiConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn(1);

        $service = new AiChatService();

        // Act & Assert: Should throw when user is null
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User context required');

        $service->askWithConversation(
            question: 'What is the revenue?',
            conversation: $conversation,
            options: ['user' => null] // Explicitly null user
        );
    }

    /** @test */
    public function askWithConversation_throws_when_user_not_provided(): void
    {
        // Arrange
        $conversation = Mockery::mock(\Condoedge\Ai\Models\AiConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn(1);

        $service = new AiChatService();

        // Act & Assert: Should throw when user is not provided at all
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User context required');

        $service->askWithConversation(
            question: 'What is the revenue?',
            conversation: $conversation,
            options: [] // No user provided
        );
    }

    /** @test */
    public function askWithConversation_allows_null_user_when_explicitly_public(): void
    {
        // Arrange - test that allow_anonymous bypasses the user check
        // We test by verifying the method proceeds past the user check
        // (will fail at a later step since we haven't mocked AI, but that's fine)
        $conversation = Mockery::mock(\Condoedge\Ai\Models\AiConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn(1);
        // Allow any calls that happen after the user check
        $conversation->shouldReceive('addMessage')->andReturn(
            Mockery::mock(\Condoedge\Ai\Models\AiMessage::class)
        );

        $service = new AiChatService();

        // When allow_anonymous=true, the method should NOT throw InvalidArgumentException
        // It will throw a different error later (e.g., when trying to call AI facade),
        // but the important thing is it doesn't throw InvalidArgumentException for missing user
        try {
            $service->askWithConversation(
                question: 'What is 2 + 2?',
                conversation: $conversation,
                options: [
                    'user' => null,
                    'allow_anonymous' => true, // Explicit opt-in
                ]
            );
            // If we get here without exception, the user check passed
            // Any other failure would be from downstream code which is fine
            $this->assertTrue(true);
        } catch (\InvalidArgumentException $e) {
            // This should NOT happen - if we get InvalidArgumentException, the test fails
            $this->fail('Should not throw InvalidArgumentException when allow_anonymous=true: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Any other exception/error is fine - it means we passed the user check
            // and failed somewhere downstream (which we haven't mocked)
            $this->assertTrue(true, 'Passed user check, failed at: ' . get_class($e));
        }
    }

    /** @test */
    public function askWithConversation_allows_user_when_provided(): void
    {
        // Arrange - test that providing a user bypasses the check
        $conversation = Mockery::mock(\Condoedge\Ai\Models\AiConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $conversation->shouldReceive('addMessage')->andReturn(
            Mockery::mock(\Condoedge\Ai\Models\AiMessage::class)
        );

        $user = (object) ['id' => 123, 'name' => 'Test User'];

        $service = new AiChatService();

        // When user is provided, should NOT throw InvalidArgumentException
        try {
            $service->askWithConversation(
                question: 'What is the revenue?',
                conversation: $conversation,
                options: ['user' => $user]
            );
            $this->assertTrue(true);
        } catch (\InvalidArgumentException $e) {
            // This should NOT happen
            $this->fail('Should not throw InvalidArgumentException when user is provided: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Any other exception/error is fine - we passed the user check
            $this->assertTrue(true, 'Passed user check, failed at: ' . get_class($e));
        }
    }

    /** @test */
    public function regenerateResponse_throws_when_user_missing(): void
    {
        // Arrange
        $conversation = Mockery::mock(\Condoedge\Ai\Models\AiConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn(1);

        // Mock message with content property accessible
        $userMessage = Mockery::mock(\Condoedge\Ai\Models\AiMessage::class)->makePartial();
        $userMessage->shouldReceive('getAttribute')->andReturnUsing(function ($key) {
            if ($key === 'content') {
                return 'Test question';
            }
            return null;
        });
        $userMessage->shouldReceive('setAttribute')->andReturnSelf();
        $userMessage->shouldReceive('__get')->with('content')->andReturn('Test question');

        $service = new AiChatService();

        // Act & Assert: Should throw when user is null
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User context required');

        $service->regenerateResponse(
            conversation: $conversation,
            userMessage: $userMessage,
            options: ['user' => null]
        );
    }

    /** @test */
    public function regenerateResponse_allows_null_user_when_explicitly_public(): void
    {
        // Arrange
        $conversation = Mockery::mock(\Condoedge\Ai\Models\AiConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $conversation->shouldReceive('addMessage')->andReturn(
            Mockery::mock(\Condoedge\Ai\Models\AiMessage::class)
        );

        // Mock message with content property accessible
        $userMessage = Mockery::mock(\Condoedge\Ai\Models\AiMessage::class)->makePartial();
        $userMessage->shouldReceive('getAttribute')->andReturnUsing(function ($key) {
            if ($key === 'content') {
                return 'Test question';
            }
            return null;
        });
        $userMessage->shouldReceive('setAttribute')->andReturnSelf();
        $userMessage->shouldReceive('__get')->with('content')->andReturn('Test question');

        $service = new AiChatService();

        // When allow_anonymous=true, should NOT throw InvalidArgumentException
        try {
            $service->regenerateResponse(
                conversation: $conversation,
                userMessage: $userMessage,
                options: [
                    'user' => null,
                    'allow_anonymous' => true,
                ]
            );
            $this->assertTrue(true);
        } catch (\InvalidArgumentException $e) {
            $this->fail('Should not throw InvalidArgumentException when allow_anonymous=true: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Other errors are fine - we passed the user check
            $this->assertTrue(true, 'Passed user check, failed at: ' . get_class($e));
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
