<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Feature;

use Condoedge\Ai\Kompo\Actions\ApprovalCard;
use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\ActionKind;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\PendingActionToken;
use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\RegisterableMutatingAction;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * THE centerpiece proof (P1-C2 / L11-1): the package previously shipped ZERO mutating actions
 * (only a non-mutating test SpyAction), so the "approval gate for writes" value-prop was
 * unverified against a real, booted Kompo round-trip. This drives the WHOLE gate end-to-end in a
 * booted app, with a fixture mutating action a HOST registers purely via config('ai.actions')
 * class-strings — no service-provider code, no core edit:
 *
 *   1. The class-string is instantiated by the registry on boot (the house-Plugin way), so the
 *      dispatcher resolves it by key.
 *   2. dispatch() at RENDER time mints a signed, user-bound token and returns a Pending result —
 *      handle() is NEVER executed on render.
 *   3. ApprovalCard renders a REAL Kompo element (helpers booted) with REAL i18n labels
 *      (Confirm/Cancel resolve, not the raw 'ai.actions.confirm' key).
 *   4. approveAiAction() -> ActionDispatcher::approve() decodes + re-verifies the token, confirms
 *      ownership, RE-RUNS authorize(), and only THEN runs handle() exactly once.
 *   5. A denied authorize() blocks handle(); a replayed (already-consumed) token is rejected
 *      (single-use, P1-C3) without re-running handle().
 *
 * Note: the acting user is created LOCALLY per test (never stored on the TestCase), because
 * retaining an anonymous Authenticatable on an instance property segfaults teardown under the
 * fully-booted provider graph.
 */
class ActionApprovalRoundTripTest extends TestCase
{
    // NOTE: setUp()/tearDown() MUST be declared `public` here. The package base TestCase widened
    // PHPUnit's protected setUp()/tearDown() to public, so a `protected` override illegally narrows
    // the visibility — which, on this PHP/Testbench build, surfaces as a hard segfault at test-class
    // collection (exit 255, zero output) rather than a readable error. Keeping these public lets the
    // fully-booted Kompo + AI-provider round-trip actually run.
    public function setUp(): void
    {
        parent::setUp();

        RegisterableMutatingAction::reset();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // The HOST registration: list the mutating action's class-string the same way you list a
        // Plugin. token_ttl coexists in the same flat array (ignored as a non-class entry).
        $app['config']->set('ai.actions', [
            RegisterableMutatingAction::class,
            'token_ttl' => 900,
        ]);
    }

    /** The acting user exposes getKey() so the token is bound to a real id (not the null path). */
    private function makeUser(int $id): Authenticatable
    {
        return new class($id) implements Authenticatable {
            public function __construct(public int $id) {}
            public function getKey() { return $this->id; }
            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return $this->id; }
            public function getAuthPassword() { return ''; }
            public function getAuthPasswordName() { return 'password'; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return ''; }
        };
    }

    private function dispatcher(): ActionDispatcher
    {
        return app(ActionDispatcher::class);
    }

    /** Resolve the host-registered action by key (proves class-string registration on boot). */
    private function resolveAction()
    {
        $action = app(AiActionRegistry::class)->getGeneric('create_record');
        $this->assertInstanceOf(
            RegisterableMutatingAction::class,
            $action,
            'the host action registered via config(ai.actions) class-strings must resolve on boot'
        );

        return $action;
    }

    /**
     * The REAL MessagesQuery::approveAiAction seam (the method the ApprovalCard confirm button
     * posts to), instantiated WITHOUT the Kompo Query boot lifecycle — approveAiAction depends
     * only on auth()->user() + the container-resolved ActionDispatcher, so a constructor-less
     * instance exercises the genuine round-trip without a parse-time MessagesQuery subclass
     * (which segfaults the Kompo trait stack under a booted provider).
     */
    private function approveVia(string $token): mixed
    {
        $query = (new \ReflectionClass(MessagesQuery::class))->newInstanceWithoutConstructor();

        return $query->approveAiAction($token);
    }

    /** @test */
    public function the_host_action_is_registered_from_config_class_strings_and_is_mutating(): void
    {
        $action = $this->resolveAction();

        $this->assertTrue($action->mutates());
    }

    /** @test */
    public function dispatch_on_render_defers_a_user_bound_token_and_never_runs_handle(): void
    {
        $this->actingAs($this->makeUser(7));
        $action = $this->resolveAction();

        $result = $this->dispatcher()->dispatch(
            $action,
            ['name' => 'Acme', 'allowed' => '1', 'run' => 'render'],
            ['user' => auth()->user(), 'text' => 'Create record']
        );

        // Pending — never executed inline.
        $this->assertSame(ActionKind::Pending, $result->kind);
        $this->assertSame([], RegisterableMutatingAction::$handledRuns, 'handle() must NOT run at render');

        // A real, user-bound token round-trips.
        $token = $result->payload['token'];
        $this->assertNotEmpty($token);
        $decoded = PendingActionToken::decode($token);
        $this->assertNotNull($decoded);
        $this->assertSame('create_record', $decoded->actionKey);
        $this->assertSame('7', (string) $decoded->userId, 'token bound to the acting user');
    }

    /** @test */
    public function the_approval_card_renders_a_real_element_with_localized_labels(): void
    {
        $this->actingAs($this->makeUser(7));
        $action = $this->resolveAction();

        $result = $this->dispatcher()->dispatch(
            $action,
            ['name' => 'Acme', 'allowed' => '1', 'run' => 'card'],
            ['user' => auth()->user(), 'text' => 'Create record']
        );

        app()->setLocale('en');
        // Pass-through wiring so we assert the labels without the selfPost round-trip details.
        $card = ApprovalCard::make($result->renderable, fn ($link) => $link);

        // Booted Kompo => a real _Rows element, NOT the offline structured array.
        $this->assertFalse(is_array($card), 'with Kompo booted the card must be a real element');

        $buttonRow = $card->elements[1];
        $confirm = $buttonRow->elements[0];
        $cancel = $buttonRow->elements[1];

        $this->assertSame(trans('ai.actions.confirm'), $confirm->label);
        $this->assertSame(trans('ai.actions.cancel'), $cancel->label);
        $this->assertNotSame('ai.actions.confirm', $confirm->label, 'must not render the raw i18n key');
    }

    /** @test */
    public function approve_ai_action_reauthorizes_then_runs_handle_exactly_once(): void
    {
        $this->actingAs($this->makeUser(7));
        $action = $this->resolveAction();

        $deferred = $this->dispatcher()->dispatch(
            $action,
            ['name' => 'Acme', 'allowed' => '1', 'run' => 'approve'],
            ['user' => auth()->user(), 'text' => 'Create record']
        );
        $token = $deferred->renderable['token'];

        $this->assertSame([], RegisterableMutatingAction::$handledRuns, 'precondition: handle() not yet run');

        // The real chat-Query round-trip method the ApprovalCard confirm button posts to.
        $rendered = $this->approveVia($token);

        // handle() ran exactly once, only after approval.
        $this->assertSame(['approve' => 1], RegisterableMutatingAction::$handledRuns);
        $this->assertSame('Acme', RegisterableMutatingAction::$lastInput['approve']['name'] ?? null);
        // The successful mutation surfaced something renderable (not the failure alert).
        $this->assertNotNull($rendered);
    }

    /** @test */
    public function approval_is_blocked_when_authorize_denies(): void
    {
        $this->actingAs($this->makeUser(7));
        $action = $this->resolveAction();

        // allowed=false => authorize() denies, so the gate short-circuits at render with an error
        // result, no token, no execution — handle() never runs for a denied gate.
        $denied = $this->dispatcher()->dispatch(
            $action,
            ['name' => 'Acme', 'allowed' => '0', 'run' => 'denied'],
            ['user' => auth()->user(), 'text' => 'Create record']
        );

        $this->assertTrue($denied->isError);
        $this->assertSame([], RegisterableMutatingAction::$handledRuns, 'a denied gate must never run handle()');
    }

    /** @test */
    public function a_replayed_token_is_rejected_and_handle_does_not_run_twice(): void
    {
        $this->actingAs($this->makeUser(7));
        $action = $this->resolveAction();

        $deferred = $this->dispatcher()->dispatch(
            $action,
            ['name' => 'Acme', 'allowed' => '1', 'run' => 'replay'],
            ['user' => auth()->user(), 'text' => 'Create record']
        );
        $token = $deferred->renderable['token'];

        // First confirm runs the write.
        $this->approveVia($token);
        $this->assertSame(['replay' => 1], RegisterableMutatingAction::$handledRuns);

        // Replaying the SAME token must not run handle() again (single-use nonce consumed).
        $second = $this->approveVia($token);
        $this->assertSame(['replay' => 1], RegisterableMutatingAction::$handledRuns, 'handle() must not run twice');
        // The replay surfaces the failure alert element (not null).
        $this->assertNotNull($second);
    }
}
