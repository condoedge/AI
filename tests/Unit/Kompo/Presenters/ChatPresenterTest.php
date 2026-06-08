<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo\Presenters;

use Condoedge\Ai\Exceptions\QueryTimeoutException;
use Condoedge\Ai\Kompo\Presenters\ChatPresenter;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\Inputs\AskQuestionInput;
use Condoedge\Ai\Services\Chat\Results\ChatTurnResult;
use Condoedge\Ai\Services\Chat\UseCases\AskQuestionUseCase;
use Condoedge\Ai\Services\Chat\UseCases\EditMessageAndRegenerateUseCase;
use Condoedge\Ai\Services\Chat\UseCases\RegenerateAnswerUseCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * Tests for ChatPresenter — the single Kompo<->application seam for chat actions.
 *
 * The use cases are mocked via $this->app->instance() so the presenter resolves through the
 * container with fakes; we assert it (a) delegates handleSend to AskQuestionUseCase with the
 * right AskQuestionInput and returns the use case's ChatTurnResult unchanged, and (b) maps
 * exceptions to user-facing translation keys BY TYPE (connection for QueryException, timeout
 * for QueryTimeoutException).
 */
class ChatPresenterTest extends TestCase
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

    /**
     * Don't load the full AI service provider to avoid API key requirements.
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // In-memory SQLite so AiConversation can be persisted for the input assertion.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
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
     * @test
     */
    public function handle_send_delegates_to_ask_question_use_case_and_returns_its_result(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->user->id]);

        $expectedResult = new ChatTurnResult(
            answer: 'You have 3 parking spaces.',
            data: [['id' => 1]],
            suggestions: ['Show details'],
        );

        $mockAsk = Mockery::mock(AskQuestionUseCase::class);
        $mockAsk->shouldReceive('execute')
            ->once()
            ->with(Mockery::on(function ($input) use ($conversation) {
                return $input instanceof AskQuestionInput
                    && $input->question === 'How many parking spaces do I have?'
                    && $input->conversation->id === $conversation->id
                    && $input->style === 'professional'
                    && $input->user === $this->user;
            }))
            ->andReturn($expectedResult);

        $this->app->instance(AskQuestionUseCase::class, $mockAsk);
        $this->app->instance(RegenerateAnswerUseCase::class, Mockery::mock(RegenerateAnswerUseCase::class));
        $this->app->instance(EditMessageAndRegenerateUseCase::class, Mockery::mock(EditMessageAndRegenerateUseCase::class));

        $presenter = $this->app->make(ChatPresenter::class);

        $result = $presenter->handleSend(
            $conversation,
            'How many parking spaces do I have?',
            'professional',
            $this->user,
        );

        // Returns the use case's ChatTurnResult unchanged.
        $this->assertSame($expectedResult, $result);
        $this->assertFalse($result->failed());
        $this->assertSame('You have 3 parking spaces.', $result->answer);
    }

    /**
     * @test
     */
    public function friendly_error_returns_the_connection_key_for_a_query_exception(): void
    {
        $presenter = $this->makePresenterWithMockedUseCases();

        $exception = new QueryException(
            'testing',
            'select * from x',
            [],
            new \RuntimeException('SQLSTATE[HY000] could not connect'),
        );

        $this->assertSame('ai.chat.error-connection', $presenter->friendlyError($exception));
    }

    /**
     * @test
     */
    public function friendly_error_returns_the_timeout_key_for_a_query_timeout_exception(): void
    {
        $presenter = $this->makePresenterWithMockedUseCases();

        $this->assertSame(
            'ai.chat.error-timeout',
            $presenter->friendlyError(new QueryTimeoutException('took too long')),
        );
    }

    /**
     * @test
     */
    public function friendly_error_falls_back_to_the_generic_key_for_an_unknown_exception(): void
    {
        $presenter = $this->makePresenterWithMockedUseCases();

        $this->assertSame(
            'ai.chat.error-generic',
            $presenter->friendlyError(new \RuntimeException('something odd happened')),
        );
    }

    private function makePresenterWithMockedUseCases(): ChatPresenter
    {
        $this->app->instance(AskQuestionUseCase::class, Mockery::mock(AskQuestionUseCase::class));
        $this->app->instance(RegenerateAnswerUseCase::class, Mockery::mock(RegenerateAnswerUseCase::class));
        $this->app->instance(EditMessageAndRegenerateUseCase::class, Mockery::mock(EditMessageAndRegenerateUseCase::class));

        return $this->app->make(ChatPresenter::class);
    }
}
