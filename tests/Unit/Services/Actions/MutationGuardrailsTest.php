<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\ModelMethodMutationAction;
use Condoedge\Ai\Services\Actions\PendingActionToken;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\PromptSections\EntityActionAwarenessSection;
use Condoedge\Ai\Services\Response\ResponseEntityEnricher;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Auth\GenericUser;

/**
 * A1/A2 security guardrails for approval-gated mutations (owner decision 2026-10-01).
 *
 * The live hole: a host wired approve/reject_reservation and close_task through a factory that
 * gated ONLY on the generic CRUD policy ability `update` — a policy that lets the record's OWNER
 * pass (a resident could approve their own reservation) and that checked no state (a rejected
 * reservation could be flipped to approved). Mutations were also advertised to every user.
 *
 *   (a) a mutation MUST declare an explicit authorization; the generic CRUD ability alone is not
 *       one — such an action fails CLOSED (never authorizes, never advertised) with a clear reason;
 *   (b) an optional precondition (model method name or callable) is checked at proposal, at
 *       approval, and again right before the write;
 *   (c) mutations are advertised (link prompt, query-side awareness, per-row chips)
 *       only to principals that pass the row-independent capability — and per-row chips only for
 *       rows that pass the full row gate;
 *   (d) a token without a single-use nonce is rejected.
 */
class MutationGuardrailsTest extends TestCase
{
    /** @var array<int, GuardrailFakeRow> */
    private array $rows = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->rows = [
            1 => new GuardrailFakeRow(1, 'pending', ownerId: 66),
            2 => new GuardrailFakeRow(2, 'rejected', ownerId: 66),
        ];

        config()->set('ai.generic_actions', []);
        config()->set('ai.entity_actions', ['Booking' => ['approve_booking' => $this->approveAction()]]);
    }

    private function approveAction(array $overrides = []): ModelMethodMutationAction
    {
        $rows = &$this->rows;

        return new ModelMethodMutationAction(...$overrides + [
            'key' => 'approve_booking',
            'label' => 'Approve booking',
            'finder' => function ($id) use (&$rows) { return $rows[(int) $id] ?? null; },
            'mutator' => fn ($row, array $input) => $row->approve(),
            'entityType' => 'Booking',
            'permission' => fn ($user) => ($user->role ?? null) === 'manager',
            'authorizer' => fn ($user, $row, array $input) => true, // row-level gate (tenancy stand-in)
            'precondition' => 'isPending',
        ]);
    }

    private function manager(): GenericUser
    {
        return new GenericUser(['id' => 13, 'role' => 'manager']);
    }

    private function resident(): GenericUser
    {
        return new GenericUser(['id' => 66, 'role' => 'contact']);
    }

    // ---------------------------------------------------------------------------------------
    // (a) explicit authorization is mandatory — generic CRUD ability alone fails closed
    // ---------------------------------------------------------------------------------------

    /** @test */
    public function a_mutation_gated_only_on_the_generic_update_ability_fails_closed(): void
    {
        $action = new ModelMethodMutationAction(
            key: 'approve_booking',
            label: 'Approve booking',
            finder: fn ($id) => $this->rows[(int) $id] ?? null,
            mutator: fn ($row) => $row->approve(),
            ability: 'update',
        );

        $this->assertNotNull($action->authorizationError(), 'a generic-ability-only mutation must be flagged');
        $this->assertStringContainsString('approve_booking', $action->authorizationError());
        $this->assertStringContainsString("'update'", $action->authorizationError());

        // Even a user the policy would allow (owner) is denied: the action fails CLOSED.
        $owner = new GuardrailCanUser(66, fn () => true);
        $this->assertFalse($action->authorize($owner, ['entity_id' => '1']));
        $this->assertFalse($action->isAdvertisableTo($owner));
    }

    /** @test */
    public function a_mutation_declaring_no_authorization_at_all_fails_closed(): void
    {
        $action = new ModelMethodMutationAction(
            key: 'approve_booking',
            label: 'Approve booking',
            finder: fn ($id) => $this->rows[(int) $id] ?? null,
            mutator: fn ($row) => $row->approve(),
        );

        $this->assertNotNull($action->authorizationError());
        $this->assertFalse($action->authorize($this->manager(), ['entity_id' => '1']));
        $this->assertFalse($action->isAdvertisableTo($this->manager()));
    }

    /** @test */
    public function the_generic_ability_is_accepted_as_defense_in_depth_beside_an_explicit_permission(): void
    {
        $policyAllows = true;
        $action = new ModelMethodMutationAction(
            key: 'approve_booking',
            label: 'Approve booking',
            finder: fn ($id) => $this->rows[(int) $id] ?? null,
            mutator: fn ($row) => $row->approve(),
            permission: fn ($user) => $user->id === 13,
            authorizer: fn ($user, $row, array $input) => true, // the row-level gate (I2)
            ability: 'update',
        );

        $this->assertNull($action->authorizationError());

        $mgr = new GuardrailCanUser(13, function ($ability) use (&$policyAllows) { return $ability === 'update' && $policyAllows; });
        $this->assertTrue($action->authorize($mgr, ['entity_id' => '1']));

        // BOTH must pass: the permission alone is not enough when the declared ability denies.
        $policyAllows = false;
        $this->assertFalse($action->authorize($mgr, ['entity_id' => '1']));

        // ...and the ability alone is not enough when the permission denies.
        $policyAllows = true;
        $this->assertFalse($action->authorize(new GuardrailCanUser(66, fn () => true), ['entity_id' => '1']));
    }

    /** @test */
    public function a_transition_specific_ability_is_an_explicit_authorization(): void
    {
        $action = new ModelMethodMutationAction(
            key: 'approve_booking',
            label: 'Approve booking',
            finder: fn ($id) => $this->rows[(int) $id] ?? null,
            mutator: fn ($row) => $row->approve(),
            ability: 'approve',
        );

        $this->assertNull($action->authorizationError());
        $this->assertTrue($action->authorize(new GuardrailCanUser(13, fn ($a) => $a === 'approve'), ['entity_id' => '1']));
        $this->assertFalse($action->authorize(new GuardrailCanUser(66, fn ($a) => false), ['entity_id' => '1']));
    }

    /** @test */
    public function a_null_principal_never_authorizes_a_mutation(): void
    {
        $action = new ModelMethodMutationAction(
            key: 'approve_booking',
            label: 'Approve booking',
            finder: fn ($id) => $this->rows[(int) $id] ?? null,
            mutator: fn ($row) => $row->approve(),
            permission: fn ($user) => true,
            rowScoped: false,
        );

        $this->assertFalse($action->authorize(null, ['entity_id' => '1']));
        $this->assertFalse($action->isAdvertisableTo(null));
    }

    /** @test */
    public function a_throwing_gate_fails_closed_instead_of_escaping(): void
    {
        $action = new ModelMethodMutationAction(
            key: 'approve_booking',
            label: 'Approve booking',
            finder: fn ($id) => $this->rows[(int) $id] ?? null,
            mutator: fn ($row) => $row->approve(),
            permission: function ($user) { throw new \RuntimeException('membership row missing'); },
            authorizer: fn ($user, $row, array $input) => true,
        );

        $this->assertFalse($action->authorize($this->manager(), ['entity_id' => '1']));
        $this->assertFalse($action->isAdvertisableTo($this->manager()));
    }

    // ---------------------------------------------------------------------------------------
    // (b) precondition — checked at proposal, approval, and right before the write
    // ---------------------------------------------------------------------------------------

    /** @test */
    public function a_method_name_precondition_gates_authorize(): void
    {
        $action = $this->approveAction();

        $this->assertTrue($action->authorize($this->manager(), ['entity_id' => '1']), 'pending row passes');
        $this->assertFalse($action->authorize($this->manager(), ['entity_id' => '2']), 'a rejected row cannot be flipped');
    }

    /** @test */
    public function a_callable_precondition_receives_the_model_and_input(): void
    {
        $seen = null;
        $action = $this->approveAction(['precondition' => function ($row, array $input) use (&$seen) {
            $seen = [$row->id, $input['entity_id'] ?? null];
            return $row->status === 'pending';
        }]);

        $this->assertTrue($action->authorize($this->manager(), ['entity_id' => '1']));
        $this->assertSame([1, '1'], $seen);
        $this->assertFalse($action->authorize($this->manager(), ['entity_id' => '2']));
    }

    /** @test */
    public function a_missing_precondition_method_fails_closed(): void
    {
        $action = $this->approveAction(['precondition' => 'isSomethingThatDoesNotExist']);

        $this->assertFalse($action->authorize($this->manager(), ['entity_id' => '1']));
    }

    /** @test */
    public function the_precondition_is_rechecked_at_approval_when_state_changed_after_the_proposal(): void
    {
        $action = $this->approveAction();
        $registry = new AiActionRegistry(registerBuiltIns: false);
        $registry->registerEntity('Booking', $action);
        $dispatcher = new ActionDispatcher($registry);

        $deferred = $dispatcher->dispatch($action, [], [
            'entity_type' => 'Booking', 'entity_id' => 1, 'user' => $this->manager(), 'user_id' => 13,
        ]);
        $this->assertTrue($deferred->renderable['pending'] ?? false, 'pending row proposes');

        $this->rows[1]->status = 'rejected'; // someone rejected it in the UI meanwhile

        $approved = $dispatcher->approve($deferred->renderable['token'], ['user' => $this->manager(), 'user_id' => 13]);

        $this->assertTrue($approved->isError);
        $this->assertSame(0, $this->rows[1]->mutations, 'no write when the precondition no longer holds');
    }

    /** @test */
    public function a_non_pending_row_is_refused_at_proposal(): void
    {
        $action = $this->approveAction();
        $dispatcher = new ActionDispatcher(new AiActionRegistry(registerBuiltIns: false));

        $result = $dispatcher->dispatch($action, [], ['entity_type' => 'Booking', 'entity_id' => 2, 'user' => $this->manager()]);

        $this->assertTrue($result->isError, 'no approval card is offered for a row the transition does not apply to');
    }

    /** @test */
    public function handle_rechecks_the_precondition_right_before_the_write(): void
    {
        $action = $this->approveAction();

        $result = $action->handle(['entity_id' => '2'], ActionContext::fromArray(['entity_id' => '2']));

        $this->assertTrue($result->isError);
        $this->assertSame(0, $this->rows[2]->mutations);
    }

    // ---------------------------------------------------------------------------------------
    // (c) advertisement only to principals that pass the capability (and per row, the row gate)
    // ---------------------------------------------------------------------------------------

    /** @test */
    public function is_advertisable_only_to_principals_passing_the_declared_permission(): void
    {
        $action = $this->approveAction();

        $this->assertTrue($action->isAdvertisableTo($this->manager()));
        $this->assertFalse($action->isAdvertisableTo($this->resident()));
    }

    /** @test */
    public function an_authorizer_only_mutation_is_not_advertised_at_type_level(): void
    {
        // A row-level callback cannot be evaluated without a row, so it cannot justify a type-level
        // offer — declare `permission:` to be advertised. Dispatch still works with the row gate.
        $action = new ModelMethodMutationAction(
            key: 'approve_booking',
            label: 'Approve booking',
            finder: fn ($id) => $this->rows[(int) $id] ?? null,
            mutator: fn ($row) => $row->approve(),
            authorizer: fn ($user, $row, $input) => true,
        );

        $this->assertNull($action->authorizationError(), 'an explicit row callback is an explicit authorization');
        $this->assertFalse($action->isAdvertisableTo($this->manager()));
        $this->assertTrue($action->authorize($this->manager(), ['entity_id' => '1']));
    }

    /** @test */
    public function the_entity_action_catalog_hides_mutations_from_users_without_the_capability(): void
    {
        $discovery = app(EntityAutoDiscovery::class);

        $this->assertSame([], array_column($discovery->getAuthorizedEntityActions('Booking', $this->resident()), 'action_key'));
        $this->assertSame(['approve_booking'], array_column($discovery->getAuthorizedEntityActions('Booking', $this->manager()), 'action_key'));
        $this->assertSame([], array_column($discovery->getAuthorizedEntityActions('Booking', null), 'action_key'));
    }

    /** @test */
    public function the_query_side_action_awareness_omits_mutations_for_users_without_the_capability(): void
    {
        $context = new PipelineContext();
        $context->conversationContext = ['focused_entity' => 'Booking'];
        $section = new EntityActionAwarenessSection();

        $this->actingAs($this->resident());
        $this->assertStringNotContainsString('approve_booking', $section->format('approve it', $context, []));

        $this->actingAs($this->manager());
        $this->assertStringContainsString('approve_booking', $section->format('approve it', $context, []));
    }

    /** @test */
    public function per_row_chips_are_only_built_for_rows_passing_the_full_row_gate(): void
    {
        $this->actingAs($this->manager());
        $enricher = app(ResponseEntityEnricher::class);

        $rows = $enricher->enrichEntityResults([
            ['_label' => 'Booking', 'id' => 1, 'name' => 'Pending one'],
            ['_label' => 'Booking', 'id' => 2, 'name' => 'Rejected one'],
        ]);

        $this->assertCount(1, $rows[0]['action_links'], 'pending row gets the approve chip');
        $this->assertStringContainsString('entity://Booking/1/approve_booking', $rows[0]['action_links'][0]);
        $this->assertSame([], $rows[1]['action_links'], 'a rejected row must not be offered an approve chip');
        $this->assertSame([], $rows[1]['available_actions']);

        $this->actingAs($this->resident());
        $rows = $enricher->enrichEntityResults([['_label' => 'Booking', 'id' => 1, 'name' => 'Pending one']]);
        $this->assertSame([], $rows[0]['action_links'], 'a resident never gets the approve chip');
    }

    // ---------------------------------------------------------------------------------------
    // (d) nonce-less tokens are rejected
    // ---------------------------------------------------------------------------------------

    /** @test */
    public function a_token_without_a_nonce_does_not_decode(): void
    {
        $token = (new PendingActionToken('approve_booking', 'Booking', ['entity_id' => '1'], 13, time(), ''))->encode();

        $this->assertNull(PendingActionToken::decode($token));
    }

    /** @test */
    public function approving_a_nonce_less_token_never_writes(): void
    {
        $action = $this->approveAction();
        $registry = new AiActionRegistry(registerBuiltIns: false);
        $registry->registerEntity('Booking', $action);
        $dispatcher = new ActionDispatcher($registry);

        $token = (new PendingActionToken('approve_booking', 'Booking', ['entity_id' => '1'], 13, time(), ''))->encode();

        $first = $dispatcher->approve($token, ['user' => $this->manager(), 'user_id' => 13]);

        $this->assertTrue($first->isError);
        $this->assertSame(0, $this->rows[1]->mutations);
    }
}

/** In-memory stand-in for a host model row — never touches a database. */
class GuardrailFakeRow
{
    public int $mutations = 0;

    public function __construct(public int $id, public string $status, public int $ownerId)
    {
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function approve(): void
    {
        $this->mutations++;
        $this->status = 'approved';
    }
}

/** A principal exposing Laravel's can($ability, $model) contract, answered by a closure. */
class GuardrailCanUser
{
    /** @var callable */
    private $can;

    public function __construct(public int $id, callable $can)
    {
        $this->can = $can;
    }

    public function can($ability, $arguments = []): bool
    {
        return (bool) ($this->can)($ability, $arguments);
    }

    public function getKey(): int
    {
        return $this->id;
    }
}
