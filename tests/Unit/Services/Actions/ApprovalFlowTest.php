<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\PendingActionToken;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Kompo\Actions\ApprovalCard;
use PHPUnit\Framework\TestCase;

/**
 * Approval-required primitive:
 *  - A read-only action renders/executes immediately.
 *  - A mutating action DEFERS at dispatch time (never runs handle()) and yields a signed
 *    pending token + approval card.
 *  - approve() re-decodes + re-verifies the token, RE-RUNS authorize() server-side, and only
 *    THEN executes handle().
 *  - Tampered tokens and lost-authorization are rejected without mutating anything.
 */
class ApprovalFlowTest extends TestCase
{
    private function registryWith(AiActionInterface $action, bool $entity = false): AiActionRegistry
    {
        $registry = new AiActionRegistry();
        if ($entity) {
            $registry->registerEntity('Record', $action);
        } else {
            $registry->registerGeneric($action);
        }
        return $registry;
    }

    /** @test */
    public function read_only_action_executes_immediately(): void
    {
        $action = new SpyAction('view', requiresApproval: false, authorized: true);
        $dispatcher = new ActionDispatcher($this->registryWith($action));

        $result = $dispatcher->dispatch($action, [], ['text' => 'View']);

        $this->assertInstanceOf(AiActionResult::class, $result);
        $this->assertFalse($result->isError);
        $this->assertTrue($action->handled, 'read-only action should run inline');
        $this->assertFalse((bool) (is_array($result->renderable) && ($result->renderable['pending'] ?? false)));
    }

    /** @test */
    public function mutating_action_defers_and_does_not_run_handle(): void
    {
        $action = new SpyAction('delete', requiresApproval: true, authorized: true);
        $dispatcher = new ActionDispatcher($this->registryWith($action));

        $result = $dispatcher->dispatch($action, [], ['text' => 'Delete', 'user_id' => 42]);

        $this->assertFalse($action->handled, 'mutating action must NOT run inline');
        $this->assertIsArray($result->renderable);
        $this->assertTrue($result->renderable['pending']);
        $this->assertNotEmpty($result->renderable['token']);

        // The token round-trips and is bound to the user + action.
        $decoded = PendingActionToken::decode($result->renderable['token']);
        $this->assertNotNull($decoded);
        $this->assertSame('delete', $decoded->actionKey);
        $this->assertSame('42', (string) $decoded->userId);
    }

    /** @test */
    public function approval_card_renders_structured_payload_offline(): void
    {
        $action = new SpyAction('delete', requiresApproval: true, authorized: true);
        $dispatcher = new ActionDispatcher($this->registryWith($action));
        $result = $dispatcher->dispatch($action, [], ['text' => 'Delete', 'user_id' => 1]);

        $card = ApprovalCard::make($result->renderable);

        $this->assertIsArray($card);
        $this->assertSame('approval_card', $card['type']);
        $this->assertSame($result->renderable['token'], $card['token']);
    }

    /** @test */
    public function approve_reauthorizes_then_runs_handle(): void
    {
        $action = new SpyAction('delete', requiresApproval: true, authorized: true);
        $registry = $this->registryWith($action);
        $dispatcher = new ActionDispatcher($registry);

        $deferred = $dispatcher->dispatch($action, ['x' => 1], ['text' => 'Delete', 'user_id' => 7]);
        $token = $deferred->renderable['token'];

        $this->assertFalse($action->handled);

        $approved = $dispatcher->approve($token, ['user_id' => 7]);

        $this->assertFalse($approved->isError);
        $this->assertTrue($action->handled, 'handle() runs only after approval');
        $this->assertSame(2, $action->authorizeCalls, 'authorize re-checked at render AND approval');
    }

    /** @test */
    public function approve_is_rejected_when_authorization_was_lost(): void
    {
        // Authorized at render time, but flips to denied before approval (e.g. role revoked).
        $action = new SpyAction('delete', requiresApproval: true, authorized: true);
        $registry = $this->registryWith($action);
        $dispatcher = new ActionDispatcher($registry);

        $deferred = $dispatcher->dispatch($action, [], ['user_id' => 7]);
        $token = $deferred->renderable['token'];

        $action->authorized = false; // permission lost

        $approved = $dispatcher->approve($token, ['user_id' => 7]);

        $this->assertTrue($approved->isError);
        $this->assertFalse($action->handled, 'must not mutate when authorization is lost');
    }

    /** @test */
    public function approve_rejects_tampered_token(): void
    {
        $action = new SpyAction('delete', requiresApproval: true, authorized: true);
        $dispatcher = new ActionDispatcher($this->registryWith($action));

        $deferred = $dispatcher->dispatch($action, [], ['user_id' => 7]);
        $tampered = $deferred->renderable['token'] . 'x';

        $approved = $dispatcher->approve($tampered, ['user_id' => 7]);

        $this->assertTrue($approved->isError);
        $this->assertFalse($action->handled);
    }

    /** @test */
    public function approve_rejects_token_belonging_to_a_different_user(): void
    {
        $action = new SpyAction('delete', requiresApproval: true, authorized: true);
        $dispatcher = new ActionDispatcher($this->registryWith($action));

        $deferred = $dispatcher->dispatch($action, [], ['user_id' => 7]);
        $token = $deferred->renderable['token'];

        $approved = $dispatcher->approve($token, ['user_id' => 999]);

        $this->assertTrue($approved->isError);
        $this->assertFalse($action->handled);
    }

    /** @test */
    public function render_time_authorization_denial_short_circuits(): void
    {
        $action = new SpyAction('delete', requiresApproval: true, authorized: false);
        $dispatcher = new ActionDispatcher($this->registryWith($action));

        $result = $dispatcher->dispatch($action, [], ['user_id' => 7]);

        $this->assertTrue($result->isError);
        $this->assertFalse($action->handled);
    }

    /** @test */
    public function pending_token_signature_is_tamper_evident(): void
    {
        $token = PendingActionToken::issue('delete', 'Record', ['id' => 1], 7)->encode();

        $this->assertNotNull(PendingActionToken::decode($token));

        // Flip a character in the payload portion → signature mismatch → null.
        $broken = substr_replace($token, 'A', 0, 1);
        $this->assertNull(PendingActionToken::decode($broken === $token ? $token . 'A' : $broken));
    }
}

/**
 * Spy action that records authorize/handle calls so the flow can be asserted precisely.
 */
class SpyAction implements AiActionInterface
{
    public bool $handled = false;
    public int $authorizeCalls = 0;

    public function __construct(
        private string $key,
        private bool $requiresApproval,
        public bool $authorized,
    ) {
    }

    public function key(): string { return $this->key; }
    public function label(): string { return ucfirst($this->key); }
    public function aliases(): array { return []; }
    public function description(): string { return ''; }
    public function inputSchema(): array { return ['x' => ['type' => 'int']]; }
    public function presentation(): string { return $this->requiresApproval ? 'approval' : 'link'; }
    public function requiresApproval(): bool { return $this->requiresApproval; }

    public function authorize(mixed $user, array $input): bool
    {
        $this->authorizeCalls++;
        return $this->authorized;
    }

    public function handle(array $input, array $context): mixed
    {
        $this->handled = true;
        return 'done';
    }
}
