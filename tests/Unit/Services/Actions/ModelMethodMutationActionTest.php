<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\ModelMethodMutationAction;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use PHPUnit\Framework\TestCase;

/**
 * ModelMethodMutationAction — the ONE reusable approval-gated mutation pattern.
 *
 * It is model-agnostic: the host wires a finder (how to resolve the target from the entity id),
 * a mutator (the EXISTING domain method to call — never a raw write), and an authorizer (the
 * server-side policy re-check). These tests prove, with a fake in-memory target (NEVER a real
 * model / never a live write):
 *   - metadata (key/label/aliases/description/inputSchema) is surfaced from config,
 *   - mutates() is true so the dispatcher defers behind the approval gate,
 *   - authorize() allows + denies based on the configured gate, re-resolving the target,
 *   - handle() delegates to the configured mutator with the SCHEMA-FILTERED input + resolved model,
 *   - a missing target / null id degrades to a graceful error (no exception, no write),
 *   - end-to-end through the dispatcher: defer → approve re-authorizes → mutator runs exactly once.
 */
class ModelMethodMutationActionTest extends TestCase
{
    /** @test */
    public function it_surfaces_configured_metadata(): void
    {
        $action = $this->approveAction();

        $this->assertSame('approve_reservation', $action->key());
        $this->assertSame('Approve reservation', $action->label());
        $this->assertContains('approve booking', $action->aliases());
        $this->assertNotSame('', $action->description());
        // No host-declared params, but the schema carries the seeded entity-id internally so the
        // id rides the approval token and re-resolves the exact target server-side.
        $this->assertSame([], $action->declaredParams());
        $this->assertArrayHasKey(ModelMethodMutationAction::ID_PARAM, $action->inputSchema());
        $this->assertTrue($action->mutates(), 'a write action must be approval-gated');
    }

    /** @test */
    public function authorize_allows_when_the_gate_passes_and_denies_when_it_fails(): void
    {
        $target = new FakeReservation(1);

        $allow = new ModelMethodMutationAction(
            key: 'approve_reservation',
            label: 'Approve reservation',
            finder: fn ($id) => $target,
            mutator: fn ($m) => $m->approve(),
            authorizer: fn ($user, $model, $input) => $user === 'manager',
        );

        // The entity id rides in the SCHEMA-FILTERED input (seeded from the path segment by the
        // dispatcher), so authorize($user, $input) can re-resolve the target server-side.
        $this->assertTrue($allow->authorize('manager', ['entity_id' => 1]));
        $this->assertFalse($allow->authorize('resident', ['entity_id' => 1]));
    }

    /** @test */
    public function authorize_denies_when_the_target_cannot_be_resolved(): void
    {
        $action = new ModelMethodMutationAction(
            key: 'approve_reservation',
            label: 'Approve reservation',
            finder: fn ($id) => null, // not found
            mutator: fn ($m) => $m->approve(),
            authorizer: fn ($user, $model, $input) => true, // would pass IF a model existed
        );

        // No model => fail closed, the authorizer never even runs against a null target.
        $this->assertFalse($action->authorize('manager', ['entity_id' => 999]));
    }

    /** @test */
    public function handle_delegates_to_the_existing_method_with_filtered_input(): void
    {
        $target = new FakeReservation(7);

        $action = new ModelMethodMutationAction(
            key: 'snooze',
            label: 'Snooze',
            finder: fn ($id) => $target,
            mutator: fn ($m, array $input) => $m->snooze((int) ($input['days'] ?? 1)),
            authorizer: fn ($user, $model, $input) => true,
            inputSchema: ['days' => ['type' => 'int', 'default' => 1]],
        );

        $ctx = ActionContext::fromArray(['entity_id' => 7], ['days' => 5]);
        $result = $action->handle(['days' => 5], $ctx);

        $this->assertInstanceOf(AiActionResult::class, $result);
        $this->assertFalse($result->isError);
        $this->assertSame(1, $target->mutations, 'the existing domain method is called exactly once');
        $this->assertSame(5, $target->snoozedDays, 'filtered input reaches the mutator');
    }

    /** @test */
    public function handle_degrades_gracefully_when_target_is_missing(): void
    {
        $action = new ModelMethodMutationAction(
            key: 'approve_reservation',
            label: 'Approve reservation',
            finder: fn ($id) => null,
            mutator: fn ($m) => $m->approve(),
            authorizer: fn ($user, $model, $input) => true,
        );

        $result = $action->handle([], ActionContext::fromArray(['entity_id' => 999]));

        $this->assertTrue($result->isError, 'a missing target must not throw or write');
    }

    /** @test */
    public function summary_for_llm_is_id_free(): void
    {
        $target = new FakeReservation(152463);
        $action = new ModelMethodMutationAction(
            key: 'approve_reservation',
            label: 'Approve reservation',
            finder: fn ($id) => $target,
            mutator: fn ($m) => $m->approve(),
            authorizer: fn ($user, $model, $input) => true,
        );

        $result = $action->handle([], ActionContext::fromArray(['entity_id' => 152463]));

        $this->assertStringNotContainsString('152463', $result->summaryForLlm);
    }

    /** @test */
    public function end_to_end_defers_then_approves_and_mutates_exactly_once(): void
    {
        $target = new FakeReservation(3);

        $action = new ModelMethodMutationAction(
            key: 'approve_reservation',
            label: 'Approve reservation',
            finder: fn ($id) => $target,
            mutator: fn ($m) => $m->approve(),
            authorizer: fn ($user, $model, $input) => $user === 'manager',
            entityType: 'Reservation',
        );

        $registry = new AiActionRegistry();
        $registry->registerEntity('Reservation', $action);
        $dispatcher = new ActionDispatcher($registry);

        // RENDER: a mutating action defers — handle() (and thus approve()) must NOT run yet.
        $deferred = $dispatcher->dispatch($action, [], [
            'text' => 'Approve',
            'entity_type' => 'Reservation',
            'entity_id' => 3,
            'user' => 'manager',
            'user_id' => 'manager',
        ]);

        $this->assertIsArray($deferred->renderable);
        $this->assertTrue($deferred->renderable['pending']);
        $this->assertSame(0, $target->mutations, 'no write at render time');

        // APPROVE: re-authorizes server-side, then runs the existing method exactly once.
        $approved = $dispatcher->approve($deferred->renderable['token'], [
            'user' => 'manager',
            'user_id' => 'manager',
            'entity_id' => 3,
        ]);

        $this->assertFalse($approved->isError);
        $this->assertSame(1, $target->mutations, 'the domain method runs once, only after approval');
    }

    /** @test */
    public function approve_is_blocked_when_authorization_is_lost_before_confirmation(): void
    {
        $target = new FakeReservation(3);
        $allowed = true;

        $action = new ModelMethodMutationAction(
            key: 'approve_reservation',
            label: 'Approve reservation',
            finder: fn ($id) => $target,
            mutator: fn ($m) => $m->approve(),
            authorizer: function ($user, $model, $input) use (&$allowed) {
                return $allowed;
            },
            entityType: 'Reservation',
        );

        $registry = new AiActionRegistry();
        $registry->registerEntity('Reservation', $action);
        $dispatcher = new ActionDispatcher($registry);

        $deferred = $dispatcher->dispatch($action, [], [
            'entity_type' => 'Reservation', 'entity_id' => 3, 'user' => 'm', 'user_id' => 'm',
        ]);

        $allowed = false; // permission revoked between render and confirm

        $approved = $dispatcher->approve($deferred->renderable['token'], ['user' => 'm', 'user_id' => 'm', 'entity_id' => 3]);

        $this->assertTrue($approved->isError);
        $this->assertSame(0, $target->mutations, 'a lost-authorization approval must never write');
    }

    private function approveAction(): ModelMethodMutationAction
    {
        return new ModelMethodMutationAction(
            key: 'approve_reservation',
            label: 'Approve reservation',
            finder: fn ($id) => new FakeReservation((int) $id),
            mutator: fn ($m) => $m->approve(),
            authorizer: fn ($user, $model, $input) => true,
            aliases: ['approve booking', 'confirm reservation'],
            description: 'Approve a pending reservation.',
        );
    }
}

/**
 * In-memory fake standing in for a host model. It NEVER touches a database — approve()/snooze()
 * just record that the existing domain method was invoked, so a test proves delegation without
 * any live write.
 */
class FakeReservation
{
    public int $mutations = 0;
    public ?int $snoozedDays = null;

    public function __construct(public int $id)
    {
    }

    public function approve(): void
    {
        $this->mutations++;
    }

    public function snooze(int $days): void
    {
        $this->mutations++;
        $this->snoozedDays = $days;
    }
}
