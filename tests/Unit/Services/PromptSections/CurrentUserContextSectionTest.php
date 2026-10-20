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
    public function it_includes_identity_anchors_and_contact_wording_guidance(): void
    {
        // The profile entity is derived from the ownership paths (Contact {user_id: $userId}).
        $this->configurePaths();
        $user = new User();
        $user->id = 42;
        $user->name = 'John Doe';
        $user->email = 'john@example.com';

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn(42);

        $context = $this->context();
        $context->qu = new QueryUnderstandingResult(isPossessive: true);

        $output = $this->section->format('Can you create a PDF with my contact information?', $context, []);

        $this->assertStringContainsString('CURRENT IDENTITY ENTITY ANCHORS', $output);
        $this->assertStringContainsString('User.id = 42', $output);
        $this->assertStringContainsString('CONTACT WORDING DISAMBIGUATION', $output);
        $this->assertStringContainsString('my contact information', $output);
        $this->assertStringContainsString('Current user email: john@example.com', $output);
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

    /** @test */
    public function it_renders_the_team_name_from_a_name_column_when_team_name_is_absent(): void
    {
        // Micro-bug A regression: hosts whose Team model exposes `name` (not
        // `team_name`) rendered "Current team name: N/A" although the team exists.
        $user = new User();
        $user->id = 13;
        $user->name = 'Julie Rousseau';
        $user->email = 'j.rousseau@example.com';
        $user->currentTeam = (object) ['id' => 3, 'name' => 'Novo Gestion'];

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn(13);

        $output = $this->section->format('test question', $this->context(), []);

        $this->assertStringContainsString('Current team ID: 3', $output);
        $this->assertStringContainsString('Current team name: Novo Gestion', $output);
        $this->assertStringNotContainsString('Current team name: N/A', $output);
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

        $output = $this->section->format('test question', $this->context(), []);

        $this->assertStringContainsString('Current team name: Primary Name', $output);
    }

    /** @test */
    public function ownership_paths_are_scoped_to_personal_possessives_with_collective_guidance(): void
    {
        // Lever L2-B(i): the ownership-paths block used to be rendered for ANY
        // possessive, pulling collective possessives ("our board") into personal
        // Contact paths. It must now scope itself to PERSONAL possessives and
        // steer COLLECTIVE possessives to the team anchor ($ai_team_ids).
        config(['ai.user_entity_paths' => [
            'Unit' => [
                'description' => "User's units via Contact.",
                'cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(n:Unit)',
            ],
        ]]);

        $user = new User();
        $user->id = 13;
        $user->name = 'Julie Rousseau';
        $user->email = 'j.rousseau@example.com';

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn(13);

        $context = $this->context();
        $context->qu = new QueryUnderstandingResult(isPossessive: true);

        $output = $this->section->format('show me our board of directors', $context, []);

        // Header restricts the paths to personal possessives.
        $this->assertStringContainsString('USER OWNERSHIP PATHS (PERSONAL possessives ONLY)', $output);
        // Explicit personal-vs-collective decision guidance.
        $this->assertStringContainsString('PERSONAL vs COLLECTIVE possessives', $output);
        $this->assertStringContainsString('COLLECTIVE possessives', $output);
        // Collective questions anchor on team scope, not the personal paths.
        $this->assertStringContainsString('$ai_team_ids', $output);
        $this->assertStringContainsString('do NOT route it through the personal paths below', $output);
        // The configured personal path still renders for genuine "my X" questions.
        $this->assertStringContainsString('MATCH (c:Contact {user_id: $userId})', $output);
    }

    /**
     * @test Eval p16 "When is our next meeting?" → "no meetings linked to you": a question QU
     * verified as COLLECTIVE possession gets only the team-scope guidance, never the personal
     * ownership paths (the Cypher model no longer decides personal vs collective itself).
     */
    public function a_collective_possessive_gets_team_guidance_and_no_personal_paths(): void
    {
        $this->loginJulie();
        $this->configurePaths();

        $context = $this->context();
        $context->qu = new QueryUnderstandingResult(
            isPossessive: true,
            possessiveWords: ['our'],
            possessiveScope: QueryUnderstandingResult::SCOPE_COLLECTIVE,
        );

        $output = $this->section->format('When is our next meeting?', $context, []);

        $this->assertStringContainsString('COLLECTIVE POSSESSIVE', $output);
        $this->assertStringContainsString('$ai_team_ids', $output);
        $this->assertStringNotContainsString('USER OWNERSHIP PATHS', $output);
        $this->assertStringNotContainsString('MATCH (c:Contact {user_id: $userId})', $output);

        // Personal possession still gets the paths.
        $context->qu = new QueryUnderstandingResult(
            isPossessive: true,
            possessiveWords: ['my'],
            possessiveScope: QueryUnderstandingResult::SCOPE_PERSONAL,
        );
        $personal = $this->section->format('What is my unit?', $context, []);
        $this->assertStringContainsString('USER OWNERSHIP PATHS', $personal);
        $this->assertStringNotContainsString('COLLECTIVE POSSESSIVE:', $personal);
    }

    private function loginJulie(): void
    {
        $user = new User();
        $user->id = 13;
        $user->name = 'Julie Rousseau';
        $user->email = 'j.rousseau@example.com';

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn(13);
    }

    private function configurePaths(): void
    {
        config(['ai.user_entity_paths' => [
            'Unit' => [
                'description' => "User's units via Contact.",
                'cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(n:Unit)',
            ],
            'BoardMember' => [
                'description' => 'Whether the user is on a board.',
                'cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:BELONGS_TO_CONTACT]-(n:BoardMember)',
            ],
        ]]);
    }

    /** @test */
    public function it_renders_only_the_ownership_paths_of_the_selected_labels(): void
    {
        // Task 5.11: all user_entity_paths were rendered on every possessive turn
        // (~5K chars) whatever the question was about.
        $this->configurePaths();
        $this->loginJulie();

        $context = $this->context();
        $context->qu = new QueryUnderstandingResult(isPossessive: true);
        $context->graphSchema = ['labels' => ['BoardMember', 'Union', 'Contact', 'User', 'Team']];

        $output = $this->section->format('who is on our board?', $context, []);

        $this->assertStringContainsString("To find the current user's BoardMember", $output);
        $this->assertStringNotContainsString("To find the current user's Unit", $output);
        $this->assertStringContainsString('PERSONAL vs COLLECTIVE possessives', $output);
    }

    /** @test */
    public function it_omits_the_ownership_block_when_no_selected_label_has_a_path(): void
    {
        $this->configurePaths();
        $this->loginJulie();

        $context = $this->context();
        $context->qu = new QueryUnderstandingResult(isPossessive: true);
        $context->graphSchema = ['labels' => ['Team', 'TeamRole', 'User']];

        $output = $this->section->format('members of my team with roles', $context, []);

        $this->assertStringNotContainsString('USER OWNERSHIP PATHS', $output);
        $this->assertStringNotContainsString('To find the current user', $output);
        // Identity anchors are still there: they are what "my team" resolves to.
        $this->assertStringContainsString('Team.id', $output);
    }

    /** @test */
    public function it_omits_the_contact_wording_guidance_when_contact_is_not_selected(): void
    {
        $this->configurePaths();
        $this->loginJulie();

        $context = $this->context();
        $context->qu = new QueryUnderstandingResult(isPossessive: true);
        $context->graphSchema = ['labels' => ['Team', 'TeamRole', 'User']];
        $withoutContact = $this->section->format('members of my team', $context, []);

        $context->graphSchema = ['labels' => ['Contact', 'User']];
        $withContact = $this->section->format('my contact information', $context, []);

        $this->assertStringNotContainsString('CONTACT WORDING DISAMBIGUATION', $withoutContact);
        $this->assertStringContainsString('CONTACT WORDING DISAMBIGUATION', $withContact);
    }

    /** @test */
    public function the_paths_of_labels_kept_only_to_join_are_not_rendered(): void
    {
        // Unit is in the prompt only because it lies on BoardMember's ownership path:
        // its own "my unit" path answers a different question.
        $this->configurePaths();
        $this->loginJulie();

        $context = $this->context();
        $context->qu = new QueryUnderstandingResult(isPossessive: true);
        $context->graphSchema = ['labels' => ['BoardMember', 'Contact', 'Unit']];
        $context->selectionInfo = ['selection_tiers' => ['BoardMember' => 'seed', 'Contact' => 'ownership', 'Unit' => 'ownership']];

        $output = $this->section->format('am I on the board?', $context, []);

        $this->assertStringContainsString("To find the current user's BoardMember", $output);
        $this->assertStringNotContainsString("To find the current user's Unit", $output);
    }

    /** @test */
    public function the_profile_entity_is_derived_from_the_ownership_paths(): void
    {
        // Phase 5C review: the gate and the text hardcoded 'Contact'. The profile entity
        // is the label the configured ownership paths start from (bound to $userId).
        config(['ai.user_entity_paths' => [
            'Pet' => ['cypher' => 'MATCH (p:Person {user_id: $userId})-[:OWNS]->(n:Pet)'],
            'Person' => ['cypher' => 'MATCH (n:Person {user_id: $userId})'],
            'Booking' => ['cypher' => 'MATCH (n:Booking)-[:BY]->(:User {id: $userId})'],
        ]]);
        $this->loginJulie();

        $context = $this->context();
        $context->qu = new QueryUnderstandingResult(isPossessive: true);
        $context->graphSchema = ['labels' => ['Person', 'User']];
        $withPerson = $this->section->format('my information', $context, []);

        $context->graphSchema = ['labels' => ['Contact', 'User']];
        $withContactOnly = $this->section->format('my information', $context, []);

        $this->assertStringContainsString('CONTACT WORDING DISAMBIGUATION', $withPerson);
        $this->assertStringContainsString('Do not use the Person entity', $withPerson);
        $this->assertStringNotContainsString('Contact entity', $withPerson);
        // An unrelated host label named Contact no longer triggers it.
        $this->assertStringNotContainsString('CONTACT WORDING DISAMBIGUATION', $withContactOnly);
    }

    /** @test */
    public function no_profile_guidance_without_a_path_starting_from_the_user(): void
    {
        config(['ai.user_entity_paths' => []]);
        $this->loginJulie();

        $context = $this->context();
        $context->qu = new QueryUnderstandingResult(isPossessive: true);

        $output = $this->section->format('my information', $context, []);

        $this->assertStringNotContainsString('CONTACT WORDING DISAMBIGUATION', $output);
        $this->assertStringContainsString('CURRENT IDENTITY ENTITY ANCHORS', $output);
    }
}
