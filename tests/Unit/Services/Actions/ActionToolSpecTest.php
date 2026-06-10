<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionToolSpec;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportExcelAction;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportPdfAction;
use Condoedge\Ai\Services\Actions\BuiltIn\LinkAction;
use Condoedge\Ai\Services\Actions\BuiltIn\OpenModalAction;
use Condoedge\Ai\Services\Actions\BuiltIn\RenderTableAction;
use Condoedge\Ai\Services\Actions\ConfigAiAction;
use Condoedge\Ai\Services\Actions\ModelMethodMutationAction;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use PHPUnit\Framework\TestCase;

/**
 * ActionToolSpec (P1-C1 / T1): the native tool definition is a PURE PROJECTION of what every
 * action already declares — key/label/aliases/description/inputSchema/mutates. No second,
 * hand-maintained schema exists anywhere, and the falsifiable form of that claim is the
 * round-trip property at the bottom: every advertised tool parameter survives the registry's
 * filterParams() and vice versa, modulo the context-routed entity_id.
 *
 * Pure-unit (no booted container) on purpose, mirroring PendingActionTokenTest: the registry
 * and the spec factory are container-tolerant.
 */
class ActionToolSpecTest extends TestCase
{
    /** @test */
    public function it_maps_schema_types_exactly_as_cast_value_enumerates_them(): void
    {
        $action = new SpecStubAction('typed', [
            'a' => ['type' => 'int'],
            'b' => ['type' => 'integer'],
            'c' => ['type' => 'float'],
            'd' => ['type' => 'double'],
            'e' => ['type' => 'bool'],
            'f' => ['type' => 'boolean'],
            'g' => ['type' => 'array'],
            'h' => ['type' => 'string'],
            'i' => ['type' => 'something_else'],
            'j' => [], // no type declared → string, like filterParams' default
        ]);

        $props = $this->props(ActionToolSpec::fromAction($action));

        $this->assertSame('integer', $props['a']['type']);
        $this->assertSame('integer', $props['b']['type']);
        $this->assertSame('number', $props['c']['type']);
        $this->assertSame('number', $props['d']['type']);
        $this->assertSame('boolean', $props['e']['type']);
        $this->assertSame('boolean', $props['f']['type']);
        $this->assertSame('array', $props['g']['type']);
        $this->assertSame('string', $props['h']['type']);
        $this->assertSame('string', $props['i']['type']);
        $this->assertSame('string', $props['j']['type']);
    }

    /** @test */
    public function it_carries_required_defaults_and_labels_into_json_schema(): void
    {
        $action = new SpecStubAction('mixed', [
            'modal' => ['type' => 'string', 'required' => true, 'label' => 'Modal target'],
            'limit' => ['type' => 'int', 'default' => 10, 'label' => 'Max rows'],
            'free' => ['type' => 'string'],
        ]);

        $spec = ActionToolSpec::fromAction($action);
        $props = $this->props($spec);

        $this->assertSame(['modal'], $spec->parameters['required']);
        $this->assertSame('Modal target', $props['modal']['description']);
        $this->assertSame(10, $props['limit']['default']);
        $this->assertSame('Max rows', $props['limit']['description']);
        $this->assertArrayNotHasKey('default', $props['free']);
        $this->assertFalse($spec->parameters['additionalProperties']);
        $this->assertSame('object', $spec->parameters['type']);
    }

    /** @test */
    public function it_appends_the_approval_suffix_iff_the_action_mutates(): void
    {
        $readOnly = ActionToolSpec::fromAction(new RenderTableAction());
        $mutating = ActionToolSpec::fromAction($this->mutationAction(), 'Reservation');

        $this->assertFalse($readOnly->mutates);
        $this->assertStringNotContainsString('REQUIRES USER APPROVAL', $readOnly->description);

        $this->assertTrue($mutating->mutates);
        $this->assertStringContainsString('REQUIRES USER APPROVAL', $mutating->description);
        $this->assertStringContainsString('Never claim it was performed', $mutating->description);
    }

    /** @test */
    public function it_advertises_aliases_as_user_may_say(): void
    {
        $spec = ActionToolSpec::fromAction(new ExportExcelAction());

        $this->assertStringContainsString('Export the current result set as an Excel (.xlsx) file.', $spec->description);
        $this->assertStringContainsString('may say', $spec->description);
        $this->assertStringContainsString('export excel', $spec->description);
        $this->assertStringContainsString('spreadsheet', $spec->description);

        // No aliases → no "may say" filler.
        $this->assertStringNotContainsString('may say', ActionToolSpec::fromAction(new RenderTableAction())->description);
    }

    /** @test */
    public function it_names_generic_tools_by_key_and_entity_tools_by_type_and_key(): void
    {
        $this->assertSame('render_table', ActionToolSpec::fromAction(new RenderTableAction())->name);
        $this->assertSame(
            'Reservation__approve_reservation',
            ActionToolSpec::fromAction($this->mutationAction(), 'Reservation')->name
        );
    }

    /** @test */
    public function it_sanitizes_names_to_the_provider_charset_and_length(): void
    {
        $weird = ActionToolSpec::fromAction(new SpecStubAction('weird.key!', []));
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/', $weird->name);
        $this->assertSame('weird_key_', $weird->name);

        $long = ActionToolSpec::fromAction(new SpecStubAction(str_repeat('a', 80), []));
        $this->assertSame(64, strlen($long->name));
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/', $long->name);
    }

    /** @test */
    public function a_post_sanitization_collision_is_skipped_not_shadowed(): void
    {
        $first = new SpecStubAction('dup.key', ['kept' => ['type' => 'string']]);
        $second = new SpecStubAction('dup!key', ['shadow' => ['type' => 'string']]);

        $specs = ActionToolSpec::fromRelevantActions(['entity' => [], 'generic' => [$first, $second]]);

        $this->assertCount(1, $specs);
        $this->assertSame('dup_key', $specs[0]->name);
        // The FIRST registration wins; the collider is skipped, never silently shadows it.
        $this->assertArrayHasKey('kept', $this->props($specs[0]));
    }

    /** @test */
    public function it_builds_specs_for_the_whole_relevant_set(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerEntity('Reservation', $this->mutationAction());

        $specs = ActionToolSpec::fromRelevantActions($registry->relevantActions('Reservation'));
        $names = array_map(static fn (ActionToolSpec $s) => $s->name, $specs);

        // The 5 built-ins plus the focused entity's action, one spec each.
        foreach (['open_modal', 'render_table', 'link', 'export_excel', 'export_pdf', 'Reservation__approve_reservation'] as $expected) {
            $this->assertContains($expected, $names);
        }
        $this->assertSame(count($names), count(array_unique($names)));
    }

    /** @test */
    public function entity_tools_require_an_entity_id_generic_tools_never_get_one(): void
    {
        // Read-only entity action with an EMPTY schema: entity_id is injected into the
        // TOOL SPEC ONLY (the loop routes it into the dispatch context, like the link path).
        $entitySpec = ActionToolSpec::fromAction(new SpecStubAction('profile', []), 'Person');
        $props = $this->props($entitySpec);

        $this->assertArrayHasKey('entity_id', $props);
        $this->assertSame('string', $props['entity_id']['type']);
        $this->assertStringContainsString('Person', $props['entity_id']['description']);
        $this->assertStringContainsString('never invented', $props['entity_id']['description']);
        $this->assertContains('entity_id', $entitySpec->parameters['required']);

        $genericSpec = ActionToolSpec::fromAction(new RenderTableAction());
        $this->assertArrayNotHasKey('entity_id', $this->props($genericSpec));
    }

    /** @test */
    public function a_declared_entity_id_keeps_its_declaration_and_is_still_required(): void
    {
        // ModelMethodMutationAction already declares entity_id in inputSchema() so it rides
        // the approval token — the factory must not duplicate it, only force required-ness.
        $spec = ActionToolSpec::fromAction($this->mutationAction(), 'Reservation');
        $props = $this->props($spec);

        $this->assertSame('string', $props['entity_id']['type']);
        $this->assertSame('Target id', $props['entity_id']['description']);
        $this->assertContains('entity_id', $spec->parameters['required']);
        $this->assertArrayHasKey('reason', $props);
    }

    /** @test */
    public function a_closure_config_action_yields_a_valid_zero_param_tool(): void
    {
        $spec = ActionToolSpec::fromAction(new ConfigAiAction('quick_note', static fn () => 'x'));

        $this->assertSame('quick_note', $spec->name);
        $this->assertFalse($spec->mutates);
        $this->assertNotSame('', trim($spec->description));
        $this->assertSame('object', $spec->parameters['type']);
        $this->assertFalse($spec->parameters['additionalProperties']);
        $this->assertArrayNotHasKey('required', $spec->parameters);
        // An empty property map must JSON-encode as {} (object), never [] (list).
        $this->assertInstanceOf(\stdClass::class, $spec->parameters['properties']);
        $this->assertStringContainsString('"properties":{}', json_encode($spec->parameters));
    }

    /**
     * THE anti-drift guarantee (falsifiable): for every action, every advertised tool
     * parameter survives the filterParams() round-trip and vice versa — modulo the
     * context-routed entity_id. This property IS the "no hand-maintained duplicate
     * schemas" claim.
     *
     * @test
     */
    public function every_advertised_parameter_survives_the_filter_params_round_trip_and_vice_versa(): void
    {
        $registry = new AiActionRegistry();

        $fixtures = [
            [new OpenModalAction(), null],
            [new RenderTableAction(), null],
            [new LinkAction(), null],
            [new ExportExcelAction(), null],
            [new ExportPdfAction(), null],
            [$this->mutationAction(), 'Reservation'],
            [new ConfigAiAction('quick_note', static fn () => 'x'), null],
        ];

        foreach ($fixtures as [$action, $entityType]) {
            $spec = ActionToolSpec::fromAction($action, $entityType);
            $props = $this->props($spec);
            $declared = array_keys($action->inputSchema());

            // Advertised params, minus an entity_id the action did NOT declare itself
            // (that one is context-routed by the loop, never part of filtered input).
            $advertised = array_keys($props);
            if ($entityType !== null && !in_array('entity_id', $declared, true)) {
                $advertised = array_values(array_diff($advertised, ['entity_id']));
            }

            sort($advertised);
            sort($declared);
            $this->assertSame(
                $declared,
                $advertised,
                "{$spec->name}: advertised tool parameters must mirror inputSchema() exactly"
            );

            // Forward: every advertised param, fed link-style, survives filtering.
            $raw = [];
            foreach ($advertised as $name) {
                $raw[$name] = $this->sampleValue($props[$name]['type']);
            }
            $filtered = $registry->filterParams($action, $raw);
            foreach ($advertised as $name) {
                $this->assertArrayHasKey(
                    $name,
                    $filtered,
                    "{$spec->name}: advertised param '{$name}' must survive filterParams()"
                );
            }

            // Backward: filterParams() can never emit a key the tool spec did not advertise.
            foreach (array_keys($filtered) as $name) {
                $this->assertContains(
                    $name,
                    array_keys($props),
                    "{$spec->name}: filterParams() emitted '{$name}' which the tool spec never advertised"
                );
            }
        }
    }

    private function mutationAction(): ModelMethodMutationAction
    {
        return new ModelMethodMutationAction(
            key: 'approve_reservation',
            label: 'Approve reservation',
            finder: static fn ($id) => (object) ['id' => $id],
            mutator: static fn ($model, array $input) => null,
            authorizer: static fn ($user, $model, array $input) => true,
            aliases: ['approve', 'confirm reservation'],
            description: 'Approve the reservation.',
            inputSchema: ['reason' => ['type' => 'string', 'label' => 'Reason']],
            entityType: 'Reservation',
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function props(ActionToolSpec $spec): array
    {
        return (array) $spec->parameters['properties'];
    }

    /** A link-style raw value for a JSON-schema type, so casting is exercised too. */
    private function sampleValue(string $jsonType): mixed
    {
        return match ($jsonType) {
            'integer' => '7',
            'number' => '4.5',
            'boolean' => '1',
            'array' => ['x'],
            default => 'sample',
        };
    }
}

/**
 * Configurable stub so each case declares only the schema/flags it is about.
 */
class SpecStubAction implements AiActionInterface
{
    /** @param array<string, array<string, mixed>> $schema @param string[] $aliases */
    public function __construct(
        private string $key,
        private array $schema = [],
        private bool $mutates = false,
        private array $aliases = [],
        private string $description = '',
    ) {
    }

    public function key(): string { return $this->key; }
    public function label(): string { return ucfirst($this->key); }
    public function aliases(): array { return $this->aliases; }
    public function description(): string { return $this->description; }
    public function inputSchema(): array { return $this->schema; }
    public function mutates(): bool { return $this->mutates; }
    public function authorize(mixed $user, array $input): bool { return true; }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        return AiActionResult::element('ok', 'ok');
    }
}
