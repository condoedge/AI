<?php

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

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

        $result = $this->section->shouldInclude('How many orders?', [], []);

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

        $result = $this->section->shouldInclude('How many orders?', [], []);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_includes_section_when_current_user_in_context(): void
    {
        // Even without auth, if context has current_user, include the section
        Auth::shouldReceive('check')->andReturn(false);

        $context = [
            'current_user' => [
                'id' => 1,
                'name' => 'Test User',
                'email' => 'test@example.com',
            ],
        ];

        $result = $this->section->shouldInclude('How many orders?', $context, []);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_excludes_section_when_no_user_and_no_context(): void
    {
        Auth::shouldReceive('check')->andReturn(false);

        $result = $this->section->shouldInclude('What is the total revenue?', [], []);

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

        $output = $this->section->format('test question', [], []);

        $this->assertStringContainsString('CURRENT USER CONTEXT', $output);
        $this->assertStringContainsString('John Doe', $output);
        $this->assertStringContainsString('john@example.com', $output);
        $this->assertStringContainsString('42', $output);
    }

    /** @test */
    public function it_formats_output_for_guest(): void
    {
        Auth::shouldReceive('check')->andReturn(false);

        $output = $this->section->format('test question', [], []);

        $this->assertStringContainsString('Guest', $output);
        $this->assertStringContainsString('N/A', $output);
    }
}
