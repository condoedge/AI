<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ModelMethodMutationAction;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hardening after the A1/A2 adversarial review (2026-10-01).
 *
 *  I1 — check-then-write is atomic: handle() re-fetches the row under a write lock INSIDE a
 *       transaction, evaluates the precondition on that locked row and runs the mutator in the same
 *       transaction. Two approval cards on the same pending row: the second sees the first's state
 *       change and reports not-applied (no last-write-wins). A throwing mutator rolls back.
 *  I2 — a model mutation must declare a ROW-LEVEL gate (authorizer, or a transition-specific
 *       ability) beside a row-independent permission — a current-team-scoped permission alone would
 *       let a manager of team A act on team B's row by id. Explicit opt-out: rowScoped: false.
 *  M1 — handle() acts on the AUTHORIZED id (the input id the token carries) and refuses when the
 *       context names a different row.
 */
class MutationHardeningTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Schema::create('hardening_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('status');
        });
    }

    private function eloquentAction(string $key, string $targetStatus, array $overrides = []): ModelMethodMutationAction
    {
        return new ModelMethodMutationAction(...$overrides + [
            'key' => $key,
            'label' => ucfirst($key),
            'finder' => HardeningBooking::class,
            'mutator' => function (HardeningBooking $row, array $input) use ($targetStatus) {
                $row->status = $targetStatus;
                $row->save();
            },
            'permission' => fn ($user) => true,
            'authorizer' => fn ($user, $row, array $input) => true,
            'precondition' => 'isPending',
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // I1 — atomic check-then-write
    // ---------------------------------------------------------------------------------------

    /** @test */
    public function the_second_of_two_concurrent_cards_fails_its_precondition_and_reports_not_applied(): void
    {
        $row = HardeningBooking::create(['status' => 'pending']);
        $approve = $this->eloquentAction('approve', 'approved');
        $reject = $this->eloquentAction('reject', 'rejected');

        // Both cards were authorized while the row was pending (proposal-time gate passed for both).
        $this->assertTrue($approve->authorize('manager', ['entity_id' => (string) $row->id]));
        $this->assertTrue($reject->authorize('manager', ['entity_id' => (string) $row->id]));

        $first = $approve->handle(['entity_id' => (string) $row->id], ActionContext::fromArray([]));
        $second = $reject->handle(['entity_id' => (string) $row->id], ActionContext::fromArray([]));

        $this->assertFalse($first->isError);
        $this->assertTrue($second->isError, 'the loser must report not-applied, not overwrite');
        $this->assertSame('approved', $row->fresh()->status, 'no last-write-wins');
    }

    /** @test */
    public function the_precondition_and_the_write_run_inside_one_transaction(): void
    {
        $row = HardeningBooking::create(['status' => 'pending']);
        $levels = [];

        $action = $this->eloquentAction('approve', 'approved', [
            'precondition' => function ($model, array $input) use (&$levels) {
                $levels['precondition'] = DB::transactionLevel();
                return $model->isPending();
            },
            'mutator' => function ($model, array $input) use (&$levels) {
                $levels['mutator'] = DB::transactionLevel();
                $model->status = 'approved';
                $model->save();
            },
        ]);

        $result = $action->handle(['entity_id' => (string) $row->id], ActionContext::fromArray([]));

        $this->assertFalse($result->isError);
        $this->assertGreaterThan(0, $levels['precondition'] ?? 0, 'precondition evaluated inside the transaction');
        $this->assertGreaterThan(0, $levels['mutator'] ?? 0, 'write inside the same transaction');
    }

    /** @test */
    public function a_class_string_finder_re_fetches_the_row_with_a_write_lock(): void
    {
        $row = HardeningBooking::create(['status' => 'pending']);
        $locked = null;

        $action = $this->eloquentAction('approve', 'approved', [
            'precondition' => function ($model, array $input) use (&$locked) {
                $locked = HardeningBooking::$lastLock;
                return $model->isPending();
            },
        ]);

        HardeningBooking::$lastLock = null;
        $action->handle(['entity_id' => (string) $row->id], ActionContext::fromArray([]));

        $this->assertTrue($locked, 'the row the precondition sees was fetched with lockForUpdate()');
    }

    /** @test */
    public function a_throwing_mutator_rolls_the_transaction_back(): void
    {
        $row = HardeningBooking::create(['status' => 'pending']);

        $action = $this->eloquentAction('approve', 'approved', [
            'mutator' => function ($model, array $input) {
                $model->status = 'approved';
                $model->save();
                throw new \RuntimeException('downstream failure');
            },
        ]);

        $result = $action->handle(['entity_id' => (string) $row->id], ActionContext::fromArray([]));

        $this->assertTrue($result->isError);
        $this->assertSame('pending', $row->fresh()->status, 'a failed mutation leaves no partial write');
    }

    /** @test */
    public function a_callable_finder_uses_the_declared_locking_finder_inside_the_transaction(): void
    {
        $row = HardeningBooking::create(['status' => 'pending']);
        $lockingCalls = [];

        $action = $this->eloquentAction('approve', 'approved', [
            'finder' => fn ($id) => HardeningBooking::find($id),
            'lockingFinder' => function ($id) use (&$lockingCalls) {
                $lockingCalls[] = DB::transactionLevel();
                return HardeningBooking::query()->lockForUpdate()->find($id);
            },
        ]);

        $result = $action->handle(['entity_id' => (string) $row->id], ActionContext::fromArray([]));

        $this->assertFalse($result->isError);
        $this->assertCount(1, $lockingCalls);
        $this->assertGreaterThan(0, $lockingCalls[0], 'the locking finder runs inside the transaction');
    }

    /** @test */
    public function a_callable_finder_returning_an_eloquent_model_is_re_fetched_locked_through_its_class(): void
    {
        $row = HardeningBooking::create(['status' => 'pending']);
        $locked = null;

        $action = $this->eloquentAction('approve', 'approved', [
            'finder' => fn ($id) => HardeningBooking::find($id),
            'precondition' => function ($model, array $input) use (&$locked) {
                $locked = HardeningBooking::$lastLock;
                return $model->isPending();
            },
        ]);

        HardeningBooking::$lastLock = null;
        $action->handle(['entity_id' => (string) $row->id], ActionContext::fromArray([]));

        $this->assertTrue($locked);
    }

    // ---------------------------------------------------------------------------------------
    // I2 — a row-level gate is mandatory beside a row-independent permission
    // ---------------------------------------------------------------------------------------

    /** @test */
    public function a_permission_alone_is_not_a_row_level_gate_and_fails_closed(): void
    {
        $action = new ModelMethodMutationAction(
            key: 'approve_booking',
            label: 'Approve booking',
            finder: HardeningBooking::class,
            mutator: fn ($row) => null,
            permission: fn ($user) => true,
        );

        $this->assertNotNull($action->authorizationError());
        $this->assertStringContainsString('row', $action->authorizationError());

        $row = HardeningBooking::create(['status' => 'pending']);
        $this->assertFalse($action->authorize('manager', ['entity_id' => (string) $row->id]));
        $this->assertFalse($action->isAdvertisableTo('manager'));
    }

    /** @test */
    public function a_permission_plus_a_generic_crud_ability_is_still_not_a_row_level_gate(): void
    {
        $action = new ModelMethodMutationAction(
            key: 'approve_booking',
            label: 'Approve booking',
            finder: HardeningBooking::class,
            mutator: fn ($row) => null,
            permission: fn ($user) => true,
            ability: 'update',
        );

        $this->assertNotNull($action->authorizationError());
        $this->assertFalse($action->isAdvertisableTo('manager'));
    }

    /** @test */
    public function a_permission_with_an_authorizer_or_a_transition_ability_is_accepted(): void
    {
        $withAuthorizer = new ModelMethodMutationAction(
            key: 'a', label: 'A', finder: HardeningBooking::class, mutator: fn ($row) => null,
            permission: fn ($user) => true,
            authorizer: fn ($user, $row, array $input) => true,
        );
        $withAbility = new ModelMethodMutationAction(
            key: 'b', label: 'B', finder: HardeningBooking::class, mutator: fn ($row) => null,
            permission: fn ($user) => true,
            ability: 'approve',
        );

        $this->assertNull($withAuthorizer->authorizationError());
        $this->assertNull($withAbility->authorizationError());
    }

    /** @test */
    public function row_scoped_false_is_the_explicit_opt_out_for_genuinely_global_models(): void
    {
        $action = new ModelMethodMutationAction(
            key: 'approve_booking',
            label: 'Approve booking',
            finder: HardeningBooking::class,
            mutator: fn ($row) => null,
            permission: fn ($user) => $user === 'manager',
            rowScoped: false,
        );

        $row = HardeningBooking::create(['status' => 'pending']);
        $this->assertNull($action->authorizationError());
        $this->assertTrue($action->authorize('manager', ['entity_id' => (string) $row->id]));
        $this->assertFalse($action->authorize('resident', ['entity_id' => (string) $row->id]));
        $this->assertTrue($action->isAdvertisableTo('manager'));
    }

    /** @test */
    public function row_scoped_false_does_not_rescue_a_mutation_with_no_authorization(): void
    {
        $action = new ModelMethodMutationAction(
            key: 'approve_booking',
            label: 'Approve booking',
            finder: HardeningBooking::class,
            mutator: fn ($row) => null,
            rowScoped: false,
        );

        $this->assertNotNull($action->authorizationError());
    }

    // ---------------------------------------------------------------------------------------
    // M1 — handle() acts only on the authorized id
    // ---------------------------------------------------------------------------------------

    /** @test */
    public function handle_refuses_when_the_context_names_a_different_row_than_the_authorized_input(): void
    {
        $authorized = HardeningBooking::create(['status' => 'pending']);
        $other = HardeningBooking::create(['status' => 'pending']);
        $action = $this->eloquentAction('approve', 'approved');

        $result = $action->handle(
            ['entity_id' => (string) $authorized->id],
            ActionContext::fromArray(['entity_id' => (string) $other->id])
        );

        $this->assertTrue($result->isError);
        $this->assertSame('pending', $authorized->fresh()->status);
        $this->assertSame('pending', $other->fresh()->status, 'the unauthorized row is never touched');
    }

    /** @test */
    public function handle_acts_on_the_input_id_and_ignores_a_context_only_id(): void
    {
        $row = HardeningBooking::create(['status' => 'pending']);
        $action = $this->eloquentAction('approve', 'approved');

        // No authorized (input) id → nothing to act on, even though the context names a row.
        $result = $action->handle([], ActionContext::fromArray(['entity_id' => (string) $row->id]));

        $this->assertTrue($result->isError);
        $this->assertSame('pending', $row->fresh()->status);
    }

    /** @test */
    public function handle_accepts_a_matching_context_id(): void
    {
        $row = HardeningBooking::create(['status' => 'pending']);
        $action = $this->eloquentAction('approve', 'approved');

        $result = $action->handle(
            ['entity_id' => (string) $row->id],
            ActionContext::fromArray(['entity_id' => $row->id]) // int vs string: same row
        );

        $this->assertFalse($result->isError);
        $this->assertSame('approved', $row->fresh()->status);
    }
}

/** Minimal Eloquent row on the in-memory sqlite test connection; records lockForUpdate() use. */
class HardeningBooking extends Model
{
    public static ?bool $lastLock = null;

    protected $table = 'hardening_bookings';
    protected $guarded = [];
    public $timestamps = false;

    public function newEloquentBuilder($query)
    {
        return new HardeningLockSpyBuilder($query);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}

/** Builder spy: sqlite compiles no FOR UPDATE, so record that the lock was requested. */
class HardeningLockSpyBuilder extends \Illuminate\Database\Eloquent\Builder
{
    public function lockForUpdate()
    {
        HardeningBooking::$lastLock = true;

        return parent::lockForUpdate();
    }
}
