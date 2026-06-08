<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\ConfigAiAction;
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
        // SAFE DEFAULTS: legacy closures never require approval and authorize by default.
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
            public function presentation(): string { return 'link'; }
            public function requiresApproval(): bool { return false; }
            public function authorize(mixed $user, array $input): bool { return true; }
            public function handle(array $input, array $context): mixed { return $input; }
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
        $action = $registry->getGeneric('export_excel'); // title default 'export'

        $clean = $registry->filterParams($action, []);

        $this->assertSame(['title' => 'export'], $clean);
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
