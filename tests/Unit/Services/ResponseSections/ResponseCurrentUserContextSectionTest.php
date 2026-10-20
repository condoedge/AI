<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\ResponseSections\ResponseCurrentUserContextSection;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;

/**
 * Response-stage CURRENT USER CONTEXT (lever L1-A).
 *
 * The answering LLM must know WHO it is talking to — the same identity facts the
 * query-generation prompt already ships (auth user + safeCurrentTeam()) — plus a
 * generic INSIDER-VOICE rule so it never refers the user to their own team as an
 * external party (e.g. telling a team's own lead to contact herself).
 */
class ResponseCurrentUserContextSectionTest extends TestCase
{
    private ResponseCurrentUserContextSection $section;

    public function setUp(): void
    {
        parent::setUp();
        $this->section = new ResponseCurrentUserContextSection();
    }

    private function context(bool $possessive = false): PipelineContext
    {
        $context = new PipelineContext();
        if ($possessive) {
            $context->qu = new QueryUnderstandingResult(isPossessive: true);
        }

        return $context;
    }

    private function authenticateUser(int $id = 42, string $name = 'Jane Rousseau', string $email = 'j.rousseau@example.com'): void
    {
        $user = new User();
        $user->id = $id;
        $user->name = $name;
        $user->email = $email;

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn($id);
    }

    /** @test */
    public function it_has_correct_name_and_priority(): void
    {
        $this->assertEquals('current_user_context', $this->section->getName());
        // After project context (20) / page description (22), before the original question (30).
        $this->assertEquals(25, $this->section->getPriority());
    }

    /** @test */
    public function it_is_excluded_when_no_authenticated_user(): void
    {
        Auth::shouldReceive('check')->andReturn(false);

        $this->assertFalse($this->section->shouldInclude($this->context(), []));
    }

    /** @test */
    public function it_is_included_when_user_is_authenticated(): void
    {
        $this->authenticateUser();

        $this->assertTrue($this->section->shouldInclude($this->context(), []));
    }

    /** @test */
    public function it_renders_nothing_gracefully_when_unauthenticated(): void
    {
        Auth::shouldReceive('check')->andReturn(false);

        $this->assertSame('', $this->section->format($this->context(), []));
    }

    /** @test */
    public function it_renders_identity_facts_and_insider_voice_rule(): void
    {
        $this->authenticateUser();

        $output = $this->section->format($this->context(), []);

        $this->assertStringContainsString('CURRENT USER CONTEXT', $output);
        $this->assertStringContainsString('Jane Rousseau', $output);
        // Same identity anchors query-gen ships: user id + team id (team resolves to
        // N/A in the test container where safeCurrentTeam() has no team).
        $this->assertStringContainsString('42', $output);
        // The insider-voice rule: answer from inside the user's organization.
        $this->assertStringContainsString('INSIDER VOICE', $output);
        $this->assertStringContainsString('external party', $output);
        $this->assertStringContainsString('own contact details', $output);
    }

    /** @test */
    public function it_omits_user_email_by_default(): void
    {
        // Same PII-minimization gate as the query-generation side
        // (config 'ai.prompt.include_user_email' OR possessive question).
        $this->authenticateUser();

        $output = $this->section->format($this->context(), []);

        $this->assertStringNotContainsString('j.rousseau@example.com', $output);
    }

    /** @test */
    public function it_includes_user_email_when_the_config_flag_is_enabled(): void
    {
        config(['ai.prompt.include_user_email' => true]);
        config(['ai.access_control.mask_identifying_fields' => false]);
        $this->authenticateUser();

        $output = $this->section->format($this->context(), []);

        $this->assertStringContainsString('j.rousseau@example.com', $output);
    }

    /** @test */
    public function it_includes_user_email_for_possessive_ownership_questions(): void
    {
        config(['ai.access_control.mask_identifying_fields' => false]);
        $this->authenticateUser();

        $output = $this->section->format($this->context(possessive: true), []);

        $this->assertStringContainsString('j.rousseau@example.com', $output);
    }

    /** @test Phase 8 batch A: email is an identifying field — it ships as the turn token. */
    public function a_masked_email_ships_as_the_turn_token_shared_with_the_rows(): void
    {
        config(['ai.access_control.identifying_fields' => ['*' => ['email']]]);
        $this->authenticateUser();
        $context = $this->context(possessive: true);

        $output = $this->section->format($context, []);

        $this->assertStringContainsString('- Email: [email#1]', $output);
        $this->assertStringNotContainsString('j.rousseau@example.com', $output);
        // The rows of the same turn reuse that token for the same address.
        $this->assertSame(
            [['email' => '[email#1]']],
            \Condoedge\Ai\Services\Response\PiiMasker::for($context)->maskRows([['email' => 'J.Rousseau@example.com']])
        );
    }

    /** @test */
    public function it_rides_the_user_role_not_the_system_role(): void
    {
        // Identity facts are per-turn context, not non-negotiable policy.
        $this->assertSame('user', $this->section->role());
    }

    /** @test */
    public function it_renders_the_team_name_from_a_name_column_when_team_name_is_absent(): void
    {
        // Micro-bug A regression (response side, same sources as query-gen): hosts
        // whose Team model exposes `name` (not `team_name`) rendered
        // "Current team name: N/A" although the team exists — and the insider-voice
        // rule lost its concrete organization name.
        $user = new User();
        $user->id = 13;
        $user->name = 'Julie Rousseau';
        $user->email = 'j.rousseau@example.com';
        $user->currentTeam = (object) ['id' => 3, 'name' => 'Novo Gestion'];

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn(13);

        $output = $this->section->format($this->context(), []);

        $this->assertStringContainsString('Current team name: Novo Gestion', $output);
        $this->assertStringNotContainsString('Current team name: N/A', $output);
        // The insider-voice rule now names the organization.
        $this->assertStringContainsString('member of "Novo Gestion"', $output);
    }

    /**
     * @test eval p20-A roster: the identity values this section GIVES the model — the user's own
     * name and current team name — are exposed, so code can treat them as known words when it
     * judges whether a lead only restates (DataAnswerPlan::knownIdentity).
     */
    public function it_exposes_the_identity_values_it_prints(): void
    {
        $user = new User();
        $user->id = 13;
        $user->name = 'Julie Rousseau';
        $user->email = 'j.rousseau@example.com';
        $user->currentTeam = (object) ['id' => 3, 'name' => 'Novo Gestion'];

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn(13);

        $this->assertSame(['Julie Rousseau', 'Novo Gestion'], $this->section->identityValues());
        // Exactly what the prompt prints — never the email (PII-gated) or an id.
        $output = $this->section->format($this->context(), []);
        foreach ($this->section->identityValues() as $value) {
            $this->assertStringContainsString($value, $output);
        }
    }

    /** @test */
    public function without_an_authenticated_user_there_are_no_identity_values(): void
    {
        Auth::shouldReceive('check')->andReturn(false);

        $this->assertSame([], $this->section->identityValues());
    }

    /** @test */
    public function a_team_name_column_still_wins_over_the_name_fallback(): void
    {
        $user = new User();
        $user->id = 13;
        $user->name = 'Julie Rousseau';
        $user->email = 'j.rousseau@example.com';
        $user->currentTeam = (object) ['id' => 3, 'team_name' => 'Primary Name', 'name' => 'Fallback Name'];

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn(13);

        $output = $this->section->format($this->context(), []);

        $this->assertStringContainsString('Current team name: Primary Name', $output);
    }
}
