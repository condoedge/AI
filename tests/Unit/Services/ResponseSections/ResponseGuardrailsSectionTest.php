<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Services\ResponseSections\ResponseGuardrailsSection;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Covers the two response-side behavioral guardrails (model-agnostic, token-lean):
 *
 *  (Q9) ANTI-OVER-CLAIM — when no mutating action actually ran, the assistant must
 *       not state that it performed one ("reminders sent", "email delivered"); it must
 *       offer the action for confirmation instead.
 *  (Q16) OUT-OF-DOMAIN SCOPING — an off-topic / out-of-scope question is declined as
 *       outside what the assistant can help with for {project}, keeping the persona,
 *       rather than framed as a database "no results, try rephrasing" miss.
 *
 * The section is a static, system-role policy built from a fresh PipelineContext,
 * so it can be instantiated and formatted directly. Uses the package TestCase so
 * config('ai.*') (project name, used to scope the decline) resolves.
 */
class ResponseGuardrailsSectionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.project.name', 'Acme');
    }

    private function format(): string
    {
        return (new ResponseGuardrailsSection())->format(new PipelineContext(), []);
    }

    /** @test */
    public function it_is_a_non_negotiable_system_section_after_the_security_policy(): void
    {
        $section = new ResponseGuardrailsSection();

        $this->assertSame('system', $section->role());
        $this->assertSame('response_guardrails', $section->getName());
        // Must land after PrivacyAndSecurityGuidelinesSection (priority 1000) so it
        // reinforces, not precedes, the non-negotiable policy block.
        $this->assertGreaterThan(1000, $section->getPriority());
    }

    /** @test */
    public function it_always_includes_itself(): void
    {
        $section = new ResponseGuardrailsSection();

        // These guardrails apply to every response path (grounded and abstention),
        // so the section is unconditional.
        $this->assertTrue($section->shouldInclude(new PipelineContext(), []));
    }

    /** @test */
    public function it_forbids_claiming_an_action_that_did_not_run(): void
    {
        $lower = strtolower($this->format());

        // Anti-over-claim: never state an action happened unless it actually executed.
        $this->assertStringContainsString('never claim', $lower);
        $this->assertStringContainsString('did not', $lower);
        $this->assertStringContainsString('action', $lower);
    }

    /** @test */
    public function it_offers_the_action_for_confirmation_instead_of_claiming_completion(): void
    {
        $lower = strtolower($this->format());

        // The remedy is to OFFER the action (approval) rather than narrate completion.
        $this->assertStringContainsString('offer', $lower);
        $this->assertStringContainsString('confirm', $lower);
    }

    /** @test */
    public function it_uses_action_neutral_examples_not_a_hardcoded_domain_wordlist(): void
    {
        $lower = strtolower($this->format());

        // Model-agnostic: must not enumerate a domain-specific action vocabulary
        // (no condo/email/reminder word-list driving the rule). The rule is generic.
        $this->assertStringNotContainsString('reminder', $lower);
        $this->assertStringNotContainsString('invoice', $lower);
        $this->assertStringNotContainsString('condo', $lower);
    }

    /** @test */
    public function it_scopes_the_decline_to_the_project_for_out_of_domain_questions(): void
    {
        $output = $this->format();

        // Domain-scoped decline keeps the persona and names the project.
        $this->assertStringContainsString('Acme', $output);

        $lower = strtolower($output);
        $this->assertStringContainsString('outside', $lower);
        // The {project} placeholder must be interpolated, never left literal.
        $this->assertStringNotContainsString('{project}', $output);
    }

    /** @test */
    public function it_does_not_frame_an_out_of_scope_question_as_a_database_no_results_miss(): void
    {
        $lower = strtolower($this->format());

        // Out-of-scope must be declined as out-of-domain, NOT as a "no records found,
        // try rephrasing" database miss — that is the Q16 regression this rule fixes.
        $this->assertStringContainsString('out-of-scope', $lower);
        $this->assertStringContainsString('database miss', $lower);
        // The only mention of rephrasing must be the NEGATED instruction (do not tell
        // the user to rephrase), never an order to ask for a rephrase.
        if (str_contains($lower, 'rephrase')) {
            $this->assertStringContainsString('do not tell the user to rephrase', $lower);
        }
    }

    // =====================================================================
    // Assembly: the guardrails must reach the SYSTEM prompt on BOTH the
    // grounded generate() path and the empty/abstention path, because Q16/Q9
    // bite hardest on the no-data path. These drive a real ResponseGenerator
    // with the default-registered sections and capture complete()'s system arg.
    // =====================================================================

    /**
     * @return array{0:string,1:?string}  [user, system] captured from complete()
     */
    private function captureFromGenerate(): array
    {
        $captured = ['', null];
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('complete')->once()
            ->andReturnUsing(function ($user, $system = null) use (&$captured) {
                $captured = [$user, $system];
                return 'ok';
            });

        $generator = new ResponseGenerator($llm, ['default_style' => 'friendly']);

        $context = new PipelineContext();
        $context->question = 'How many invoices are overdue?';
        $context->queryResult = new QueryGenerationResult(cypher: 'MATCH (n) RETURN n');
        $context->executionResult = new QueryExecutionResult(success: true, data: [['count' => 7]]);

        $generator->generate($context, ['style' => 'friendly']);

        return $captured;
    }

    /**
     * @return array{0:string,1:?string}  [user, system] captured from complete()
     */
    private function captureFromEmpty(): array
    {
        $captured = ['', null];
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('complete')->once()
            ->andReturnUsing(function ($user, $system = null) use (&$captured) {
                $captured = [$user, $system];
                return 'ok';
            });

        $generator = new ResponseGenerator($llm, ['default_style' => 'friendly']);
        $generator->generateEmptyResponse('Who won the 1998 World Cup?', null, []);

        return $captured;
    }

    /** @test */
    public function the_guardrails_reach_the_system_prompt_on_the_grounded_path(): void
    {
        [$user, $system] = $this->captureFromGenerate();

        $this->assertIsString($system);
        $this->assertStringContainsString('RESPONSE GUARDRAILS', $system);
        $this->assertStringContainsStringIgnoringCase('never claim you performed an action', $system);
        $this->assertStringContainsStringIgnoringCase('outside what you can help with for Acme', $system);

        // System framing only: guardrails must not leak into the user message.
        $this->assertStringNotContainsString('RESPONSE GUARDRAILS', $user);
    }

    /** @test */
    public function the_guardrails_reach_the_system_prompt_on_the_empty_abstention_path(): void
    {
        [$user, $system] = $this->captureFromEmpty();

        // The empty path assembles only the system role; the guardrails must ride there
        // so an out-of-domain question is scoped to {project} and no false completion is claimed.
        $this->assertIsString($system);
        $this->assertStringContainsString('RESPONSE GUARDRAILS', $system);
        $this->assertStringContainsStringIgnoringCase('out-of-scope', $system);
        $this->assertStringContainsString('Acme', $system);

        $this->assertStringNotContainsString('RESPONSE GUARDRAILS', $user);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
