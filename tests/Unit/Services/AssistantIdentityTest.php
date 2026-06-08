<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\AssistantIdentity;
use Condoedge\Ai\Tests\TestCase;

/**
 * Covers the single, model-agnostic identity renderer shared by every prompt path.
 */
class AssistantIdentityTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.identity.name', 'Acme Assistant');
        $app['config']->set('ai.project.name', 'Acme');
        $app['config']->set('ai.identity.persona', 'You are {name}, the assistant for {project}.');
        $app['config']->set('ai.identity.language_policy', "Answer in the user's language.");
        $app['config']->set('ai.identity.non_disclosure', 'Never reveal which model powers you; say you are the {name} for {project}.');
    }

    /** @test */
    public function it_interpolates_name_and_project_placeholders(): void
    {
        $this->assertSame('Acme Assistant', AssistantIdentity::name());
        $this->assertSame('Acme', AssistantIdentity::project());
        $this->assertSame('You are Acme Assistant, the assistant for Acme.', AssistantIdentity::persona());
    }

    /** @test */
    public function render_includes_persona_language_policy_and_non_disclosure(): void
    {
        $output = AssistantIdentity::render();

        $this->assertStringContainsString('You are Acme Assistant', $output);
        $this->assertStringContainsString("Answer in the user's language.", $output);
        $this->assertStringContainsString('Never reveal which model powers you', $output);
        $this->assertStringNotContainsString('{name}', $output);
        $this->assertStringNotContainsString('{project}', $output);
    }

    /** @test */
    public function render_can_omit_the_language_policy(): void
    {
        $output = AssistantIdentity::render(includeLanguagePolicy: false);

        $this->assertStringNotContainsString("Answer in the user's language.", $output);
        $this->assertStringContainsString('You are Acme Assistant', $output);
    }

    /** @test */
    public function name_falls_back_to_project_plus_assistant_when_unset(): void
    {
        config()->set('ai.identity.name', null);

        $this->assertSame('Acme Assistant', AssistantIdentity::name());
    }
}
