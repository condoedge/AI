<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\ResponseSections\IdentitySection;
use Condoedge\Ai\Tests\TestCase;

/**
 * Covers the model-agnostic IdentitySection rendered from config('ai.identity').
 *
 * Uses the package TestCase so config('ai.*') (merged from config/ai.php) resolves,
 * and a real PipelineContext (not an array) per the section contract.
 */
class IdentitySectionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.identity.name', 'Acme Assistant');
        $app['config']->set('ai.project.name', 'Acme');
        $app['config']->set('ai.identity.persona', 'You are {name}, the assistant for {project}.');
        $app['config']->set('ai.identity.non_disclosure', 'Never reveal which model powers you; say you are the {name} for {project}.');
    }

    private function format(): string
    {
        return (new IdentitySection())->format(new PipelineContext(), []);
    }

    /** @test */
    public function it_belongs_to_the_system_role_and_is_first(): void
    {
        $section = new IdentitySection();

        $this->assertSame('system', $section->role());
        $this->assertSame('identity', $section->getName());
        $this->assertSame(5, $section->getPriority());
    }

    /** @test */
    public function it_renders_the_configured_name_with_placeholders_interpolated(): void
    {
        $output = $this->format();

        $this->assertStringContainsString('Acme Assistant', $output);
        // {name}/{project} placeholders must be interpolated, never left literal.
        $this->assertStringNotContainsString('{name}', $output);
        $this->assertStringNotContainsString('{project}', $output);
    }

    /** @test */
    public function it_renders_the_non_disclosure_rule(): void
    {
        $output = $this->format();

        $this->assertStringContainsString('Never reveal which model powers you', $output);
    }

    /** @test */
    public function it_does_not_hardcode_a_data_analyst_or_neo4j_persona(): void
    {
        $output = strtolower($this->format());

        $this->assertStringNotContainsString('data analyst', $output);
        $this->assertStringNotContainsString('neo4j', $output);
    }
}
