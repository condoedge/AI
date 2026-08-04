<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Services\ResponseSections\SelfReferenceNoticeSection;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;

/**
 * Micro-fix B (mechanism, not prose): when a value in the execution data sample
 * IS the current user's own email or full name, an explicit unmissable note is
 * injected into the response prompt so the model cannot present the user's own
 * contact details back to them as "how to reach their team".
 *
 * Detection is generic — comparison against the auth user's email/name only,
 * no hardcoded strings — and the section is completely silent otherwise.
 */
class SelfReferenceNoticeSectionTest extends TestCase
{
    private SelfReferenceNoticeSection $section;

    public function setUp(): void
    {
        parent::setUp();
        $this->section = new SelfReferenceNoticeSection();
    }

    private function authenticateUser(
        int $id = 13,
        string $name = 'Julie Rousseau',
        string $email = 'j.rousseau@example.com'
    ): void {
        $user = new User();
        $user->id = $id;
        $user->name = $name;
        $user->email = $email;

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn($id);
    }

    private function contextWithData(array $data): PipelineContext
    {
        $context = new PipelineContext();
        $context->executionResult = new QueryExecutionResult(success: true, data: $data);

        return $context;
    }

    /** @test */
    public function it_has_correct_name_and_priority(): void
    {
        $this->assertSame('self_reference_notice', $this->section->getName());
        // Right after ResultsDataSection (50), before entity actions (55).
        $this->assertSame(52, $this->section->getPriority());
    }

    /** @test */
    public function it_fires_when_the_data_contains_the_current_users_email(): void
    {
        $this->authenticateUser();
        $context = $this->contextWithData([
            ['team_name' => 'Novo Gestion', 'contact_email' => 'j.rousseau@example.com'],
        ]);

        $this->assertTrue($this->section->shouldInclude($context, []));

        $output = $this->section->format($context, []);

        $this->assertStringContainsString('SELF-REFERENCE IN THE RESULTS', $output);
        $this->assertStringContainsString("'j.rousseau@example.com'", $output);
        $this->assertStringContainsString("current user's own email address", $output);
        $this->assertStringContainsString("that's you", $output);
        $this->assertStringContainsString('Never tell the user to contact themselves', $output);
    }

    /** @test */
    public function the_email_match_is_case_insensitive_exact(): void
    {
        $this->authenticateUser(email: 'j.rousseau@example.com');
        $context = $this->contextWithData([
            ['email' => 'J.Rousseau@Example.COM'],
        ]);

        $this->assertTrue($this->section->shouldInclude($context, []));
        // The note quotes the value exactly as it appears in the data.
        $this->assertStringContainsString("'J.Rousseau@Example.COM'", $this->section->format($context, []));
    }

    /** @test */
    public function it_fires_when_the_data_contains_the_current_users_full_name(): void
    {
        $this->authenticateUser(name: 'Julie Rousseau');
        $context = $this->contextWithData([
            ['contact_name' => 'julie rousseau', 'unit' => '310'],
        ]);

        $this->assertTrue($this->section->shouldInclude($context, []));

        $output = $this->section->format($context, []);

        $this->assertStringContainsString("current user's own name", $output);
        $this->assertStringContainsString("that's you", $output);
    }

    /** @test */
    public function it_is_silent_when_no_data_value_matches(): void
    {
        $this->authenticateUser();
        $context = $this->contextWithData([
            ['contact_email' => 'someone.else@example.com', 'contact_name' => 'Marc Tremblay'],
            // Exact-match only: a longer string CONTAINING the name must not fire.
            ['note' => 'Julie Rousseau Smith attended'],
        ]);

        $this->assertFalse($this->section->shouldInclude($context, []));
        $this->assertSame('', $this->section->format($context, []));
    }

    /** @test */
    public function it_is_silent_when_the_result_set_is_empty(): void
    {
        $this->authenticateUser();
        $context = $this->contextWithData([]);

        $this->assertFalse($this->section->shouldInclude($context, []));
    }

    /** @test */
    public function it_is_silent_when_unauthenticated_even_with_matching_looking_data(): void
    {
        Auth::shouldReceive('check')->andReturn(false);
        $context = $this->contextWithData([
            ['email' => 'j.rousseau@example.com'],
        ]);

        $this->assertFalse($this->section->shouldInclude($context, []));
        $this->assertSame('', $this->section->format($context, []));
    }

    /** @test */
    public function it_detects_identity_values_nested_inside_result_rows(): void
    {
        $this->authenticateUser();
        $context = $this->contextWithData([
            ['team' => ['lead' => ['contact' => ['email' => 'j.rousseau@example.com']]]],
        ]);

        $this->assertTrue($this->section->shouldInclude($context, []));
    }

    /** @test */
    public function it_only_scans_the_visible_result_sample(): void
    {
        $this->authenticateUser();

        // Match only on row 11 — beyond the 10-row sample the response model sees.
        $rows = array_fill(0, 10, ['email' => 'other@example.com']);
        $rows[] = ['email' => 'j.rousseau@example.com'];

        $this->assertFalse($this->section->shouldInclude($this->contextWithData($rows), []));
    }

    /** @test */
    public function it_notes_both_email_and_name_when_both_appear(): void
    {
        $this->authenticateUser(name: 'Julie Rousseau', email: 'j.rousseau@example.com');
        $context = $this->contextWithData([
            ['name' => 'Julie Rousseau', 'email' => 'j.rousseau@example.com'],
        ]);

        $output = $this->section->format($context, []);

        $this->assertStringContainsString("current user's own email address", $output);
        $this->assertStringContainsString("current user's own name", $output);
    }
}
