<?php

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\PromptSections\CurrentUserContextSection;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;

class CurrentUserContextSectionTest extends TestCase
{
    private CurrentUserContextSection $section;

    public function setUp(): void
    {
        parent::setUp();
        $this->section = new CurrentUserContextSection();
    }

    private function context(): PipelineContext
    {
        return new PipelineContext();
    }

    /** @test */
    public function it_has_correct_name_and_priority(): void
    {
        $this->assertEquals('current_user_context', $this->section->getName());
        $this->assertEquals(17, $this->section->getPriority());
    }

    /** @test */
    public function it_excludes_section_when_no_authenticated_user(): void
    {
        // Ensure no user is authenticated
        Auth::shouldReceive('check')->andReturn(false);

        $result = $this->section->shouldInclude('How many orders?', $this->context(), []);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_includes_section_when_user_is_authenticated(): void
    {
        // Mock authenticated user
        $user = new User();
        $user->id = 1;
        $user->name = 'Test User';
        $user->email = 'test@example.com';

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);

        $result = $this->section->shouldInclude('How many orders?', $this->context(), []);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_excludes_section_when_unauthenticated_even_with_populated_context(): void
    {
        // Inclusion is driven solely by the auth gate, not by data carried in the
        // pipeline context. A populated context (e.g. a possessive question) must
        // NOT smuggle the user section in when there is no authenticated user.
        Auth::shouldReceive('check')->andReturn(false);

        $context = $this->context();
        $context->qu = new QueryUnderstandingResult(isPossessive: true);

        $result = $this->section->shouldInclude('How many orders do I have?', $context, []);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_excludes_section_when_no_user_and_no_context(): void
    {
        Auth::shouldReceive('check')->andReturn(false);

        $result = $this->section->shouldInclude('What is the total revenue?', $this->context(), []);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_formats_output_for_authenticated_user(): void
    {
        $user = new User();
        $user->id = 42;
        $user->name = 'John Doe';
        $user->email = 'john@example.com';

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn(42);

        $output = $this->section->format('test question', $this->context(), []);

        $this->assertStringContainsString('CURRENT USER CONTEXT', $output);
        $this->assertStringContainsString('John Doe', $output);
        $this->assertStringContainsString('42', $output);
    }

    /** @test */
    public function it_omits_user_email_by_default(): void
    {
        // PII minimization: the user's email is NOT shipped to the LLM unless explicitly
        // enabled or the question is about the user's own data.
        $user = new User();
        $user->id = 42;
        $user->name = 'John Doe';
        $user->email = 'john@example.com';

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn(42);

        $output = $this->section->format('How many orders are there?', $this->context(), []);

        $this->assertStringNotContainsString('john@example.com', $output);
        $this->assertStringNotContainsString('Current user email', $output);
        // Identity that the query actually needs (id + team) is still present.
        $this->assertStringContainsString('Current user ID: 42', $output);
    }

    /** @test */
    public function it_includes_user_email_when_the_config_flag_is_enabled(): void
    {
        config(['ai.prompt.include_user_email' => true]);

        $user = new User();
        $user->id = 42;
        $user->name = 'John Doe';
        $user->email = 'john@example.com';

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn(42);

        $output = $this->section->format('test question', $this->context(), []);

        $this->assertStringContainsString('john@example.com', $output);
    }

    /** @test */
    public function it_includes_user_email_for_possessive_ownership_questions(): void
    {
        // When the question is about the user's own data, the email is allowed even with
        // the flag off, because ownership resolution may need it.
        $user = new User();
        $user->id = 42;
        $user->name = 'John Doe';
        $user->email = 'john@example.com';

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn(42);

        $context = $this->context();
        $context->qu = new QueryUnderstandingResult(isPossessive: true);

        $output = $this->section->format('How many orders do I have?', $context, []);

        $this->assertStringContainsString('john@example.com', $output);
    }

    /** @test */
    public function it_formats_output_for_guest(): void
    {
        Auth::shouldReceive('check')->andReturn(false);

        $output = $this->section->format('test question', $this->context(), []);

        $this->assertStringContainsString('Guest', $output);
        $this->assertStringContainsString('N/A', $output);
    }
}
