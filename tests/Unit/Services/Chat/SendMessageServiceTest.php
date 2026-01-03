<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Services\Chat\SendMessageService;
use Condoedge\Ai\Services\Chat\AiChatService;
use Condoedge\Ai\Models\AiConversation;
use Orchestra\Testbench\TestCase;
use Mockery;

class SendMessageServiceTest extends TestCase
{
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
        $app['config']->set('ai.response_generation.default_style', 'friendly');
        $app['config']->set('ai.chat.show_metrics', false);
        $app['config']->set('app.name', 'Test App');
    }

    /** @test */
    public function it_calls_ai_chat_service_with_conversation_and_message(): void
    {
        // Arrange
        $conversation = Mockery::mock(AiConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn(1);

        $expectedResponse = [
            'answer' => 'Test response',
            'data' => [],
            'suggestions' => [],
            'sources' => [],
            'cypher_query' => null,
        ];

        $aiChatService = Mockery::mock(AiChatService::class);
        $aiChatService->shouldReceive('askWithConversation')
            ->once()
            ->with('Test message', $conversation, [])
            ->andReturn($expectedResponse);

        $service = new SendMessageService($aiChatService);

        // Act
        $result = $service->sendMessage(
            conversation: $conversation,
            message: 'Test message'
        );

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals($expectedResponse, $result);
    }

    /** @test */
    public function it_passes_options_to_ai_chat_service(): void
    {
        // Arrange
        $conversation = Mockery::mock(AiConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn(1);

        $options = ['user' => (object) ['id' => 123], 'style' => 'professional'];

        $aiChatService = Mockery::mock(AiChatService::class);
        $aiChatService->shouldReceive('askWithConversation')
            ->once()
            ->with('Hello', $conversation, $options)
            ->andReturn(['answer' => 'Hi']);

        $service = new SendMessageService($aiChatService);

        // Act
        $result = $service->sendMessage(
            conversation: $conversation,
            message: 'Hello',
            options: $options
        );

        // Assert
        $this->assertEquals(['answer' => 'Hi'], $result);
    }

    /** @test */
    public function it_throws_exception_for_empty_message(): void
    {
        // Arrange
        $conversation = Mockery::mock(AiConversation::class);
        $aiChatService = Mockery::mock(AiChatService::class);

        // AiChatService should NOT be called for empty messages
        $aiChatService->shouldNotReceive('askWithConversation');

        $service = new SendMessageService($aiChatService);

        // Assert & Act
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message cannot be empty');

        $service->sendMessage(
            conversation: $conversation,
            message: ''
        );
    }

    /** @test */
    public function it_throws_exception_for_whitespace_only_message(): void
    {
        // Arrange
        $conversation = Mockery::mock(AiConversation::class);
        $aiChatService = Mockery::mock(AiChatService::class);

        $aiChatService->shouldNotReceive('askWithConversation');

        $service = new SendMessageService($aiChatService);

        // Assert & Act
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message cannot be empty');

        $service->sendMessage(
            conversation: $conversation,
            message: '   '
        );
    }

    /** @test */
    public function it_does_not_use_request_global(): void
    {
        // This test ensures we don't rely on request() global
        $this->assertEmpty(request()->all());

        $conversation = Mockery::mock(AiConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn(1);

        $aiChatService = Mockery::mock(AiChatService::class);
        $aiChatService->shouldReceive('askWithConversation')->andReturn([
            'answer' => 'Response',
        ]);

        $service = new SendMessageService($aiChatService);
        $service->sendMessage($conversation, 'Test');

        // Request should still be empty - we didn't pollute it
        $this->assertEmpty(request()->all());
    }
}
