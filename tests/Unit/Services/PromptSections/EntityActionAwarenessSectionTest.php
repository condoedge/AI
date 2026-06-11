<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\PromptSections\EntityActionAwarenessSection;
use Condoedge\Ai\Tests\TestCase;

class EntityActionAwarenessSectionTest extends TestCase
{
    private EntityActionAwarenessSection $section;

    public function setUp(): void
    {
        parent::setUp();

        $this->section = new EntityActionAwarenessSection();
    }

    /** @test */
    public function it_should_include_when_entity_actions_configured(): void
    {
        config(['ai.entity_actions' => ['Person' => ['profile' => []]]]);

        $result = $this->section->shouldInclude('give me the profile link', new PipelineContext(), []);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_formats_action_awareness_with_context_ids(): void
    {
        config(['ai.entity_actions' => [
            'Person' => [
                'profile' => [
                    'aliases' => ['profile link', 'profile page'],
                    'label' => 'View Profile',
                ],
            ],
        ]]);

        $context = new PipelineContext();
        $context->conversationContext = [
            'focused_entity' => 'Person',
            'last_result_sample' => [
                ['id' => '152463', 'name' => 'John Doe'],
            ],
        ];

        $output = $this->section->format('give me the profile link', $context, []);

        $this->assertStringContainsString('ACTION REQUESTS', $output);
        $this->assertStringContainsString('profile link', $output);
        $this->assertStringContainsString('NOT database fields', $output);
        $this->assertStringContainsString('NO QUERY REQUIRED', $output);
        $this->assertStringContainsString('152463', $output);
    }

    /** @test */
    public function it_returns_empty_when_no_actions_exist(): void
    {
        config(['ai.entity_actions' => []]);
        config(['ai.generic_actions' => []]);

        // Truly-no-actions: an empty registry (built-ins disabled) bound for the section to read.
        app()->instance(AiActionRegistry::class, new AiActionRegistry(registerBuiltIns: false));

        $result = $this->section->shouldInclude('show me all people', new PipelineContext(), []);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_formats_generic_actions_correctly(): void
    {
        config(['ai.entity_actions' => []]);
        config(['ai.generic_actions' => [
            'settings' => [
                'aliases' => ['settings page', 'preferences'],
                'label' => 'Settings',
                'description' => 'Open the settings page.',
                'action' => fn () => null,
            ],
        ]]);

        $output = $this->section->format('open settings', new PipelineContext(), []);

        // The action is advertised by its registry key + description, and the aliases the user
        // might say are still surfaced so the query generator won't treat them as DB fields.
        $this->assertStringContainsString('settings', $output);
        $this->assertStringContainsString('Open the settings page.', $output);
        $this->assertStringContainsString('settings page', $output);
        $this->assertStringContainsString('Generic Actions', $output);
    }

    /** @test */
    public function it_has_correct_name_and_priority(): void
    {
        $this->assertEquals('entity_action_awareness', $this->section->getName());
        $this->assertEquals(58, $this->section->getPriority());
    }

    /** @test */
    public function it_omits_the_example_block_when_there_are_no_prior_results(): void
    {
        // With no last_result_sample there are no entity IDs to act on, so the
        // Example/CRITICAL-RULE filler has no referent and must not be emitted.
        config(['ai.entity_actions' => [
            'Person' => [
                'profile' => ['aliases' => ['profile link'], 'label' => 'View Profile'],
            ],
        ]]);

        // Focused on Person but with NO prior results in context.
        $context = new PipelineContext();
        $context->conversationContext = ['focused_entity' => 'Person'];

        $output = $this->section->format('give me the profile link', $context, []);

        // The action awareness block itself is still emitted (actions are advertised).
        $this->assertStringContainsString('ACTION REQUESTS', $output);
        $this->assertStringContainsString('NOT database fields', $output);

        // But the no-referent filler is gone (no prior results to act on).
        $this->assertStringNotContainsString('Example:', $output);
        $this->assertStringNotContainsString('CRITICAL RULE', $output);
    }

    /** @test */
    public function it_includes_the_example_block_when_prior_results_exist(): void
    {
        config(['ai.entity_actions' => [
            'Person' => [
                'profile' => ['aliases' => ['profile link'], 'label' => 'View Profile'],
            ],
        ]]);

        $context = new PipelineContext();
        $context->conversationContext = [
            'focused_entity' => 'Person',
            'last_result_sample' => [['id' => '99', 'name' => 'Jane']],
        ];

        $output = $this->section->format('give me the profile link', $context, []);

        $this->assertStringContainsString('Example:', $output);
        $this->assertStringContainsString('CRITICAL RULE', $output);
    }

    /** @test */
    public function it_advertises_built_in_actions_from_the_registry(): void
    {
        // No config-defined actions at all: the only actions are the registry built-ins.
        config(['ai.entity_actions' => []]);
        config(['ai.generic_actions' => []]);

        // The section must still want to run, because the registry always knows the built-ins.
        $this->assertTrue(
            $this->section->shouldInclude('export this to excel', new PipelineContext(), [])
        );

        $output = $this->section->format('export this to excel', new PipelineContext(), []);

        // Built-in generative-UI actions must be advertised to the query generator by KEY,
        // so "export this to excel" is recognized as an action, not a DB field to query.
        $this->assertStringContainsString('export_excel', $output);
        $this->assertStringContainsString('render_table', $output);
        $this->assertStringContainsString('export_pdf', $output);

        // And their description (registry metadata) must surface, not just the bare key.
        $this->assertStringContainsString('Export as an Excel (.xlsx) file.', $output);
    }

    /** @test */
    public function a_mutation_actions_internal_id_carrier_is_not_advertised_as_a_user_param(): void
    {
        config(['ai.generic_actions' => []]);
        config(['ai.entity_actions' => [
            'Reservation' => [
                'approve_reservation' => new \Condoedge\Ai\Services\Actions\ModelMethodMutationAction(
                    key: 'approve_reservation',
                    label: 'Approve reservation',
                    finder: fn ($id) => null,
                    mutator: fn ($m) => null,
                    authorizer: fn ($user, $model, $input) => true,
                    description: 'Approve a pending reservation.',
                ),
            ],
        ]]);

        $context = new PipelineContext();
        $context->conversationContext = ['focused_entity' => 'Reservation'];

        $output = $this->section->format('approve the reservation', $context, []);

        // The action is advertised by key + description...
        $this->assertStringContainsString('approve_reservation', $output);
        $this->assertStringContainsString('Approve a pending reservation.', $output);

        // ...but the seeded entity-id carrier must NOT make the Reservation line read as a
        // param-taking action (built-in generic actions below legitimately carry params, so we
        // scope the check to the approve_reservation line itself).
        $approveLine = '';
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, 'approve_reservation')) {
                $approveLine = $line;
                break;
            }
        }
        $this->assertNotSame('', $approveLine);
        $this->assertStringNotContainsString('[accepts params]', $approveLine);
    }

    /** @test */
    public function it_reads_actions_from_the_registry_not_raw_config(): void
    {
        config(['ai.entity_actions' => []]);
        config(['ai.generic_actions' => []]);

        // Register an action ONLY on the shared registry (never written to config). If the
        // section read raw config it would never see this; routing through the registry it must.
        app(AiActionRegistry::class)->registerGeneric(
            new RegistrySourcedSpyAction()
        );

        $output = $this->section->format('do the spy thing', new PipelineContext(), []);

        $this->assertStringContainsString('spy_only_in_registry', $output);
        $this->assertStringContainsString('A spy action known only to the registry', $output);
    }
}

/**
 * Generic action registered straight on the registry (never in config) so the test can prove
 * EntityActionAwarenessSection reads the registry, not raw config('ai.generic_actions').
 */
class RegistrySourcedSpyAction implements \Condoedge\Ai\Contracts\AiActionInterface
{
    public function key(): string
    {
        return 'spy_only_in_registry';
    }

    public function label(): string
    {
        return 'Spy Action';
    }

    public function aliases(): array
    {
        return ['spy thing'];
    }

    public function description(): string
    {
        return 'A spy action known only to the registry.';
    }

    public function inputSchema(): array
    {
        return [];
    }

    public function mutates(): bool
    {
        return false;
    }

    public function authorize(mixed $user, array $input): bool
    {
        return true;
    }

    public function handle(array $input, \Condoedge\Ai\Services\Actions\ActionContext $context): \Condoedge\Ai\Services\Actions\Results\AiActionResult
    {
        return \Condoedge\Ai\Services\Actions\Results\AiActionResult::error('noop');
    }
}
