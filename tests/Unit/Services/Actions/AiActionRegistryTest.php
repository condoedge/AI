<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\ConfigAiAction;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\RegisterableMutatingAction;
use Orchestra\Testbench\TestCase;

/**
 * AiActionRegistry: legacy closure config still loads (backward-compat) and param-schema
 * filtering drops forbidden/unknown params while applying typed casting + defaults.
 *
 * Uses the bare Testbench TestCase (no AiServiceProvider) so no API keys are required.
 */
class AiActionRegistryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    /** @test */
    public function it_loads_legacy_entity_closure_config_via_config_adapter(): void
    {
        config()->set('ai.entity_actions', [
            'Person' => [
                'profile' => [
                    'action' => fn($id, $text = 'View') => "profile:{$id}:{$text}",
                    'aliases' => ['profile link', 'profile page'],
                    'label' => 'View Profile',
                ],
            ],
        ]);

        $registry = new AiActionRegistry();

        $action = $registry->getEntity('Person', 'profile');

        $this->assertInstanceOf(AiActionInterface::class, $action);
        $this->assertInstanceOf(ConfigAiAction::class, $action);
        $this->assertSame('profile', $action->key());
        $this->assertSame('View Profile', $action->label());
        $this->assertSame(['profile link', 'profile page'], $action->aliases());
        // SAFE DEFAULTS: legacy closures never mutate and authorize by default. mutates() is the
        // evolved trigger; requiresApproval() survives as a one-release alias on the adapter.
        $this->assertFalse($action->mutates());
        $this->assertFalse($action->requiresApproval());
        $this->assertTrue($action->authorize(null, []));
    }

    /** @test */
    public function it_loads_bare_closure_config_entries(): void
    {
        config()->set('ai.generic_actions', [
            'settings' => fn($text = 'Settings') => "go:{$text}",
        ]);

        $registry = new AiActionRegistry();
        $action = $registry->getGeneric('settings');

        $this->assertInstanceOf(ConfigAiAction::class, $action);
        $this->assertSame('settings', $action->key());
        $this->assertSame([], $action->inputSchema());
    }

    /** @test */
    public function it_returns_null_for_missing_actions_without_throwing(): void
    {
        config()->set('ai.entity_actions', []);
        config()->set('ai.generic_actions', []);

        $registry = new AiActionRegistry();

        $this->assertNull($registry->getEntity('Nope', 'missing'));
        $this->assertNull($registry->getGeneric('missing'));
    }

    /** @test */
    public function it_registers_class_string_actions_from_config_the_plugin_way(): void
    {
        // The house idiom: a host lists an AiAction class-string in config('ai.actions') exactly
        // as it lists a Plugin — no bespoke service-provider code. The registry instantiates each
        // on boot (new $class()), beside the built-ins. token_ttl coexists in the same key and is
        // ignored as a non-class entry (the registry never throws on it).
        config()->set('ai.actions', [
            'token_ttl' => 900,
            RegisterableMutatingAction::class,
        ]);

        $registry = new AiActionRegistry();

        $action = $registry->getGeneric('create_record');

        $this->assertInstanceOf(RegisterableMutatingAction::class, $action);
        $this->assertTrue($action->mutates(), 'the registered class-string action is mutating');
        // Built-ins still register alongside the config class-string action.
        $this->assertInstanceOf(AiActionInterface::class, $registry->getGeneric('export_excel'));
    }

    /** @test */
    public function it_ignores_non_class_string_entries_in_actions_config_without_throwing(): void
    {
        // Only token_ttl (the approval-gate tuning) — no class-strings. Must not throw, must not
        // invent an action, and built-ins must still be present.
        config()->set('ai.actions', ['token_ttl' => 900]);

        $registry = new AiActionRegistry();

        $this->assertNull($registry->getGeneric('create_record'));
        $this->assertInstanceOf(AiActionInterface::class, $registry->getGeneric('link'));
    }

    /** @test */
    public function class_string_action_does_not_clobber_an_explicitly_registered_one(): void
    {
        // A class-string in config seeds a default; an explicit registerGeneric() of the same key
        // overrides it (the same precedence the built-ins use).
        config()->set('ai.actions', [RegisterableMutatingAction::class]);

        $registry = new AiActionRegistry();
        $override = new ConfigAiAction('create_record', fn ($text = 'x') => $text);
        $registry->registerGeneric($override);

        $this->assertSame($override, $registry->getGeneric('create_record'));
    }

    /** @test */
    public function it_resolves_a_ready_made_action_instance_placed_in_entity_actions_config(): void
    {
        // A host may drop a typed AiActionInterface instance (e.g. ModelMethodMutationAction)
        // straight into entity_actions config instead of a closure — the registry returns it
        // as-is, so mutating entity actions surface to BOTH the awareness section and the
        // dispatcher without a closure adapter losing their mutates()/authorize() behavior.
        $instance = $this->mutatingInstance('approve_reservation');

        config()->set('ai.entity_actions', [
            'Reservation' => ['approve_reservation' => $instance],
        ]);

        $registry = new AiActionRegistry();

        $resolved = $registry->getEntity('Reservation', 'approve_reservation');
        $this->assertSame($instance, $resolved);
        $this->assertTrue($resolved->mutates());

        // It also appears in the entity action list the awareness/relevant lookups read.
        $list = $registry->entityActions('Reservation');
        $this->assertContains($instance, $list);
    }

    /** @test */
    public function it_resolves_a_ready_made_action_instance_placed_in_generic_actions_config(): void
    {
        $instance = $this->mutatingInstance('snooze_notification');

        config()->set('ai.generic_actions', ['snooze_notification' => $instance]);

        $registry = new AiActionRegistry();

        $this->assertSame($instance, $registry->getGeneric('snooze_notification'));
        $this->assertContains($instance, $registry->genericActions());
    }

    private function mutatingInstance(string $key): AiActionInterface
    {
        return new \Condoedge\Ai\Services\Actions\ModelMethodMutationAction(
            key: $key,
            label: ucfirst(str_replace('_', ' ', $key)),
            finder: fn ($id) => null,
            mutator: fn ($m) => null,
            authorizer: fn ($user, $model, $input) => true,
        );
    }

    /** @test */
    public function it_ships_builtin_generative_ui_actions(): void
    {
        config()->set('ai.generic_actions', []);

        $registry = new AiActionRegistry();

        foreach (['open_modal', 'render_table', 'link', 'export_excel', 'export_pdf'] as $key) {
            $this->assertInstanceOf(
                AiActionInterface::class,
                $registry->getGeneric($key),
                "built-in {$key} should be registered"
            );
        }
    }

    /** @test */
    public function it_filters_params_against_input_schema_dropping_unknowns(): void
    {
        $registry = new AiActionRegistry();

        $action = new class implements AiActionInterface {
            public function key(): string { return 'demo'; }
            public function label(): string { return 'Demo'; }
            public function aliases(): array { return []; }
            public function description(): string { return ''; }
            public function inputSchema(): array {
                return [
                    'unit_id' => ['type' => 'int'],
                    'amount' => ['type' => 'float', 'default' => 0.0],
                    'active' => ['type' => 'bool'],
                ];
            }
            public function mutates(): bool { return false; }
            public function authorize(mixed $user, array $input): bool { return true; }
            public function handle(array $input, \Condoedge\Ai\Services\Actions\ActionContext $context): \Condoedge\Ai\Services\Actions\Results\AiActionResult {
                return \Condoedge\Ai\Services\Actions\Results\AiActionResult::element($input);
            }
        };

        $clean = $registry->filterParams($action, [
            'unit_id' => '123',          // cast to int
            'active' => 'true',          // cast to bool
            'evil' => 'DROP TABLE',      // not in schema → dropped
            'amount' => '45.5',          // cast to float
        ]);

        $this->assertSame(['unit_id', 'amount', 'active'], array_keys($clean));
        $this->assertSame(123, $clean['unit_id']);
        $this->assertSame(45.5, $clean['amount']);
        $this->assertTrue($clean['active']);
        $this->assertArrayNotHasKey('evil', $clean);
    }

    /** @test */
    public function it_applies_schema_defaults_for_missing_params(): void
    {
        $registry = new AiActionRegistry();
        $action = $registry->getGeneric('export_excel'); // title default 'export', mode default 'table'

        $clean = $registry->filterParams($action, []);

        $this->assertSame(['title' => 'export', 'mode' => 'table'], $clean);
    }

    /** @test */
    public function empty_schema_yields_no_params(): void
    {
        config()->set('ai.generic_actions', [
            'noparams' => fn($text = 'x') => $text,
        ]);
        $registry = new AiActionRegistry();
        $action = $registry->getGeneric('noparams');

        $this->assertSame([], $registry->filterParams($action, ['anything' => 1]));
    }

    /** @test */
    public function relevant_actions_groups_by_focused_entity_and_result_types(): void
    {
        config()->set('ai.entity_actions', [
            'Invoice' => [
                'view' => ['action' => fn($id, $text = 'View') => $text, 'label' => 'View Invoice'],
            ],
        ]);
        config()->set('ai.generic_actions', []);

        $registry = new AiActionRegistry();
        $relevant = $registry->relevantActions('Invoice', ['Invoice', 'Unit']);

        $this->assertArrayHasKey('Invoice', $relevant['entity']);
        $this->assertCount(1, $relevant['entity']['Invoice']);
        // Built-ins always present in generic.
        $this->assertNotEmpty($relevant['generic']);
    }
}
