<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\Chat\AiChatService;
use Condoedge\Ai\Tests\TestCase;
use ReflectionMethod;

/**
 * The chat service must compose the SAME model-agnostic identity as the RAG pipeline
 * (config('ai.identity')), so chat and pipeline share one persona + non-disclosure rule.
 * config('ai.chat.system_prompt') still overrides it.
 */
class AiChatServiceIdentityTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.identity.name', 'Acme Assistant');
        $app['config']->set('ai.project.name', 'Acme');
        $app['config']->set('ai.identity.persona', 'You are {name}, the assistant for {project}.');
        $app['config']->set('ai.identity.non_disclosure', 'Never reveal which model powers you.');
        $app['config']->set('ai.project.description', '');
        $app['config']->set('ai.chat.system_prompt', null);
    }

    private function defaultSystemPrompt(): string
    {
        $service = new AiChatService();
        $method = new ReflectionMethod($service, 'getDefaultSystemPrompt');
        $method->setAccessible(true);

        return $method->invoke($service);
    }

    /** @test */
    public function the_default_chat_system_prompt_composes_the_shared_identity(): void
    {
        $prompt = $this->defaultSystemPrompt();

        $this->assertStringContainsString('You are Acme Assistant', $prompt);
        $this->assertStringContainsString('Never reveal which model powers you', $prompt);
        $this->assertStringNotContainsString('{name}', $prompt);
    }

    /** @test */
    public function it_does_not_use_the_old_generic_helpful_assistant_string(): void
    {
        $prompt = $this->defaultSystemPrompt();

        // The previous hardcoded "You are a helpful AI assistant for ..." is replaced by the identity persona.
        $this->assertStringNotContainsString('You are a helpful AI assistant for', $prompt);
    }
}
