<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\Inputs\AskQuestionInput;
use Condoedge\Ai\Services\Chat\Results\ChatTurnResult;
use Condoedge\Ai\Services\Chat\SendMessageService;
use Condoedge\Ai\Services\Chat\UseCases\AskQuestionUseCase;
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
    public function it_delegates_to_ask_question_use_case_and_maps_result_to_array(): void
    {
        $conversation = Mockery::mock(AiConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn(1);

        $result = new ChatTurnResult(
            answer: 'Test response',
            data: [['x' => 1]],
            suggestions: ['Tell me more'],
            sources: [],
            cypher: 'MATCH (n) RETURN n',
        );

        $useCase = Mockery::mock(AskQuestionUseCase::class);
        $useCase->shouldReceive('execute')
            ->once()
            ->with(Mockery::on(function (AskQuestionInput $input) use ($conversation) {
                return $input->question === 'Test message'
                    && $input->conversation === $conversation
                    && $input->style === 'friendly';
            }))
            ->andReturn($result);

        $service = new SendMessageService($useCase);

        $response = $service->sendMessage(
            conversation: $conversation,
            message: 'Test message'
        );

        $this->assertSame([
            'answer' => 'Test response',
            'data' => [['x' => 1]],
            'suggestions' => ['Tell me more'],
            'sources' => [],
            'cypher_query' => 'MATCH (n) RETURN n',
        ], $response);
    }

    /** @test */
    public function it_passes_style_and_user_options_through_the_input(): void
    {
        $conversation = Mockery::mock(AiConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn(1);

        $user = (object) ['id' => 123];

        $useCase = Mockery::mock(AskQuestionUseCase::class);
        $useCase->shouldReceive('execute')
            ->once()
            ->with(Mockery::on(function (AskQuestionInput $input) use ($user) {
                return $input->style === 'professional' && $input->user === $user;
            }))
            ->andReturn(new ChatTurnResult(answer: 'Hi'));

        $service = new SendMessageService($useCase);

        $response = $service->sendMessage(
            conversation: $conversation,
            message: 'Hello',
            options: ['user' => $user, 'style' => 'professional']
        );

        $this->assertSame('Hi', $response['answer']);
    }

    /** @test */
    public function it_throws_exception_for_empty_message(): void
    {
        $conversation = Mockery::mock(AiConversation::class);
        $useCase = Mockery::mock(AskQuestionUseCase::class);
        $useCase->shouldNotReceive('execute');

        $service = new SendMessageService($useCase);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message cannot be empty');

        $service->sendMessage(conversation: $conversation, message: '');
    }

    /** @test */
    public function it_throws_exception_for_whitespace_only_message(): void
    {
        $conversation = Mockery::mock(AiConversation::class);
        $useCase = Mockery::mock(AskQuestionUseCase::class);
        $useCase->shouldNotReceive('execute');

        $service = new SendMessageService($useCase);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message cannot be empty');

        $service->sendMessage(conversation: $conversation, message: '   ');
    }

    /** @test */
    public function it_does_not_use_request_global(): void
    {
        $this->assertEmpty(request()->all());

        $conversation = Mockery::mock(AiConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn(1);

        $useCase = Mockery::mock(AskQuestionUseCase::class);
        $useCase->shouldReceive('execute')->andReturn(new ChatTurnResult(answer: 'Response'));

        $service = new SendMessageService($useCase);
        $service->sendMessage($conversation, 'Test');

        $this->assertEmpty(request()->all());
    }
}
