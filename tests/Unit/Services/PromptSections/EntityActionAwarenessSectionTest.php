<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
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

    /** @test Actions plan B5: no previous-result ids, no example — a record link is a data question. */
    public function it_never_lists_previous_result_ids_and_routes_record_links_to_a_query(): void
    {
        config(['ai.entity_actions' => [
            'Person' => [
                'profile' => [
                    'aliases' => ['profile link', 'profile page'],
                    'label' => 'View Profile',
                    'action' => fn () => null,
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
        $this->assertStringContainsString('NOT database fields', $output);
        $this->assertStringContainsString('`profile` (View Profile)', $output);
        // Task 5.6: the pure-navigation escape hatch is the structured needs_query=false.
        $this->assertStringContainsString('"needs_query": false', $output);
        $this->assertStringContainsString('query it (returning its id)', $output);
        $this->assertStringNotContainsString('152463', $output);
        $this->assertStringNotContainsString('John Doe', $output);
        $this->assertStringNotContainsString('Example:', $output);
        $this->assertStringNotContainsString('CRITICAL RULE', $output);
        $this->assertStringNotContainsString('profile link', $output, 'aliases are not shipped');
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
    public function it_lists_generic_actions_by_key_and_label(): void
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

        $this->assertStringContainsString('`settings` (Settings)', $output);
        $this->assertStringNotContainsString('settings page', $output);
        $this->assertStringContainsString('- Generic:', $output);
    }

    /** @test */
    public function it_has_correct_name_and_priority(): void
    {
        $this->assertEquals('entity_action_awareness', $this->section->getName());
        $this->assertEquals(58, $this->section->getPriority());
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

        // Described in full only when QU asked for that export (see the export test).
        $this->assertStringNotContainsString('Export as an Excel (.xlsx) file.', $output);
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
                    permission: fn ($user) => true,
                ),
            ],
        ]]);

        // A1/A2: mutations are only listed for a principal passing their capability.
        $this->actingAs(new \Illuminate\Auth\GenericUser(['id' => 13]));

        $context = new PipelineContext();
        $context->conversationContext = ['focused_entity' => 'Reservation'];

        $output = $this->section->format('approve the reservation', $context, []);

        // The action is advertised by key + label...
        $this->assertStringContainsString('`approve_reservation` (Approve reservation)', $output);

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

    private function configureCatalog(): void
    {
        config(['ai.entity_actions' => [
            'Unit' => [
                'statement' => ['aliases' => ['account statement', 'unit statement'], 'label' => 'Account statement', 'action' => fn () => null],
            ],
            'Meeting' => [
                'agenda' => ['aliases' => ['open agenda'], 'label' => 'Agenda', 'action' => fn () => null],
            ],
        ]]);
        config(['ai.generic_actions' => [
            'my_statement' => [
                'aliases' => ['my statement', 'my balance', 'how much do I owe'],
                'label' => 'My account statement',
                'description' => 'My account statement.',
                'action' => fn () => null,
            ],
            'calendar' => [
                'aliases' => ['calendar', 'open calendar', 'schedule'],
                'label' => 'Calendar',
                'description' => 'Calendar.',
                'action' => fn () => null,
            ],
        ]]);
    }

    private function dataQuestion(string $intent = 'lookup', ?string $exportFormat = null): PipelineContext
    {
        $context = new PipelineContext();
        $context->qu = new QueryUnderstandingResult(
            intent: $intent,
            questionType: 'data_query',
            exportFormat: $exportFormat,
        );
        $context->graphSchema = ['labels' => ['Unit', 'Invoice', 'Union']];

        return $context;
    }

    /** @test */
    public function a_data_question_gets_the_compact_action_list(): void
    {
        // Task 5.11: the full catalog with every alias (~5K chars) shipped on every
        // turn. A question QU judged an informational data lookup only needs to know
        // WHICH phrases are UI actions — the keys — plus the data-question guardrail.
        $this->configureCatalog();

        $output = $this->section->format('What does unit 310 owe?', $this->dataQuestion(), []);

        $this->assertStringContainsString('ACTION REQUESTS', $output);
        $this->assertStringContainsString('`my_statement`', $output);
        $this->assertStringContainsString('`calendar`', $output);
        $this->assertStringNotContainsString('user may say', $output);
        $this->assertStringNotContainsString('how much do I owe', $output);
        $this->assertStringContainsString('ACTIONS NEVER REPLACE DATA QUESTIONS', $output);
        // The selected labels' entity actions are named (Unit is selected, Meeting is not).
        $this->assertStringContainsString('`statement`', $output);
        $this->assertStringNotContainsString('`agenda`', $output);
    }

    /** @test */
    public function an_export_request_keeps_the_export_actions_described(): void
    {
        $this->configureCatalog();

        $output = $this->section->format('Excel of unit 310 invoices', $this->dataQuestion('list', 'excel'), []);

        $this->assertStringContainsString('Export as an Excel (.xlsx) file.', $output);
        $this->assertStringNotContainsString('how much do I owe', $output);
    }

    /** @test Every turn (navigation asks and QU failures included) gets the same keys-only list. */
    public function a_non_informational_question_gets_the_same_keys_only_list(): void
    {
        $this->configureCatalog();

        $output = $this->section->format('open the calendar', $this->dataQuestion('other'), []);
        $this->assertStringContainsString('`calendar` (Calendar)', $output);
        $this->assertStringNotContainsString('user may say', $output);

        $failed = new PipelineContext();
        $failed->qu = QueryUnderstandingResult::failed();
        $output = $this->section->format('open the calendar', $failed, []);
        $this->assertStringContainsString('`calendar` (Calendar)', $output);
        $this->assertStringNotContainsString('user may say', $output);
    }

    /** @test */
    public function it_always_emits_the_data_question_guardrail_after_the_action_list(): void
    {
        // REGRESSION PIN (iter-3 ambiguous-balance t0): a host page-link action
        // whose aliases named the same domain concept as a data question ("Show
        // me the balance." vs an action aliased "my balance") led the generator
        // to answer NO QUERY REQUIRED instead of generating the query. The
        // section must always instruct that actions NEVER replace data questions
        // — with and without prior results in context.
        config(['ai.entity_actions' => []]);
        config(['ai.generic_actions' => [
            'my_statement' => [
                'aliases' => ['my statement', 'my balance', 'how much do I owe'],
                'label' => 'My account statement',
                'description' => 'My account statement.',
                'action' => fn () => null,
            ],
        ]]);

        // Without prior results.
        $output = $this->section->format('Show me the balance.', new PipelineContext(), []);
        $this->assertStringContainsString('ACTIONS NEVER REPLACE DATA QUESTIONS', $output);
        $this->assertStringContainsString('MUST still generate the Cypher query', $output);

        // With prior results.
        $context = new PipelineContext();
        $context->conversationContext = [
            'focused_entity' => 'Account',
            'last_result_sample' => [['id' => '1', 'name' => 'Ops account']],
        ];
        $output = $this->section->format('Show me the balance.', $context, []);
        $this->assertStringContainsString('ACTIONS NEVER REPLACE DATA QUESTIONS', $output);
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

        $this->assertStringContainsString('`spy_only_in_registry` (Spy Action)', $output);
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
