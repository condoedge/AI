<?php

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Services\Chat\AiChatService;
use Orchestra\Testbench\TestCase;

/**
 * AiChatService is reduced to the chat surface's availability check; chat turns
 * run through AskQuestionUseCase (covered by the use-case tests).
 */
class AiChatServiceContextTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    /** @test */
    public function it_is_available_when_the_default_llm_has_an_api_key(): void
    {
        config(['ai.llm.default' => 'openai', 'ai.llm.openai.api_key' => 'sk-test']);

        $this->assertTrue((new AiChatService())->isAvailable());
    }

    /** @test */
    public function it_is_unavailable_without_an_api_key(): void
    {
        config(['ai.llm.default' => 'openai', 'ai.llm.openai.api_key' => null]);

        $this->assertFalse((new AiChatService())->isAvailable());
    }

    /** @test */
    public function it_no_longer_exposes_a_second_chat_turn_path(): void
    {
        $this->assertFalse(method_exists(AiChatService::class, 'askWithConversation'));
    }
}
