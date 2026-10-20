<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\PendingActionToken;
use PHPUnit\Framework\TestCase;

/**
 * Hardening of the deferred-action carrier (L11-6 / P1-C3).
 *
 * The HMAC signature already makes the token tamper-evident (covered by ApprovalFlowTest).
 * These cases pin the THREE remaining replay/abuse holes the signature does NOT close:
 *   1. TTL — an expired token is rejected at decode() even though its signature is valid.
 *   2. Single-use — a confirmed mutating token cannot be replayed (the second approve() is
 *      rejected without re-running handle()), so a non-idempotent "charge $450" cannot fire twice.
 *   3. Null-user binding — a token minted with userId===null must NOT match an authenticated
 *      approver (the old belongsTo(null)===true "matches anyone" hole).
 *
 * Pure-unit (no booted container) on purpose, mirroring ApprovalFlowTest: the token + dispatcher
 * are container-tolerant, so the single-use store must degrade to an in-process guard here.
 */
class PendingActionTokenTest extends TestCase
{
    private function dispatcherWith(TokenSpyAction $action): ActionDispatcher
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric($action);
        return new ActionDispatcher($registry);
    }

    /** @test */
    public function a_fresh_valid_token_still_decodes_and_runs_once(): void
    {
        $action = new TokenSpyAction('charge', requiresApproval: true, authorized: true);
        $dispatcher = $this->dispatcherWith($action);

        $deferred = $dispatcher->dispatch($action, [], ['text' => 'Charge', 'user_id' => 7]);
        $token = $deferred->renderable['token'];

        $this->assertNotNull(PendingActionToken::decode($token), 'fresh token must decode');

        $approved = $dispatcher->approve($token, ['user_id' => 7]);

        $this->assertFalse($approved->isError, 'a fresh, valid, owned token must run once');
        $this->assertSame(1, $action->handledCount, 'handle() runs exactly once on first approve');
    }

    /** @test */
    public function an_expired_token_is_rejected_at_decode(): void
    {
        // Forge a validly-signed token whose issuedAt is older than any sane TTL.
        $stale = new PendingActionToken(
            actionKey: 'charge',
            entityType: null,
            input: [],
            userId: 7,
            issuedAt: time() - (10 * 365 * 24 * 3600), // ~10 years ago
            nonce: 'stale-nonce',
        );

        $token = $stale->encode();

        // The signature is valid, but the age exceeds the TTL → decode() fails closed.
        $this->assertNull(
            PendingActionToken::decode($token),
            'an expired (but correctly-signed) token must be rejected at decode()'
        );
    }

    /** @test */
    public function ttl_window_accepts_recent_and_rejects_aged_tokens(): void
    {
        // A token issued a moment ago is within the default TTL → decodes.
        $recent = new PendingActionToken('charge', null, [], 7, time() - 5, 'recent-nonce');
        $this->assertFalse($recent->isExpired(), 'a 5-second-old token must not be expired');
        $this->assertNotNull(PendingActionToken::decode($recent->encode()));

        // A token issued comfortably past the default 900s window → expired, rejected at decode().
        $aged = new PendingActionToken('charge', null, [], 7, time() - 1000, 'aged-nonce');
        $this->assertTrue($aged->isExpired(), 'a 1000-second-old token must be expired under the 900s default');
        $this->assertNull(PendingActionToken::decode($aged->encode()));
    }

    /** @test */
    public function a_replayed_already_consumed_token_is_rejected(): void
    {
        $action = new TokenSpyAction('charge', requiresApproval: true, authorized: true);
        $dispatcher = $this->dispatcherWith($action);

        $deferred = $dispatcher->dispatch($action, [], ['text' => 'Charge', 'user_id' => 7]);
        $token = $deferred->renderable['token'];

        // First confirm: the charge fires.
        $first = $dispatcher->approve($token, ['user_id' => 7]);
        $this->assertFalse($first->isError);
        $this->assertSame(1, $action->handledCount);

        // Replaying the SAME token must NOT charge again (single-use nonce already consumed).
        $second = $dispatcher->approve($token, ['user_id' => 7]);

        $this->assertTrue($second->isError, 'a consumed token must be rejected on replay');
        $this->assertSame(1, $action->handledCount, 'handle() must not run a second time');
    }

    /** @test */
    public function a_null_user_token_is_rejected_in_an_authenticated_context(): void
    {
        // Token minted WITHOUT a bound user (the unauthenticated/missing-getKey issue path).
        $action = new TokenSpyAction('charge', requiresApproval: true, authorized: true);
        $dispatcher = $this->dispatcherWith($action);

        $deferred = $dispatcher->dispatch($action, [], ['text' => 'Charge']); // no user_id => userId null
        $token = $deferred->renderable['token'];

        $decoded = PendingActionToken::decode($token);
        $this->assertNotNull($decoded);
        $this->assertNull($decoded->userId, 'precondition: token is bound to no user');

        // An AUTHENTICATED approver (user_id present) must NOT be able to consume an unbound token.
        $approved = $dispatcher->approve($token, ['user_id' => 7]);

        $this->assertTrue($approved->isError, 'a null-user token must not match an authenticated approver');
        $this->assertSame(0, $action->handledCount, 'handle() must not run for a null-user token');
    }
}

/**
 * Spy that counts how many times handle() actually executes, so replay is provable.
 */
class TokenSpyAction implements AiActionInterface
{
    public int $handledCount = 0;

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
    public function inputSchema(): array { return []; }
    public function mutates(): bool { return $this->requiresApproval; }

    public function authorize(mixed $user, array $input): bool
    {
        return $this->authorized;
    }

    public function handle(array $input, \Condoedge\Ai\Services\Actions\ActionContext $context): \Condoedge\Ai\Services\Actions\Results\AiActionResult
    {
        $this->handledCount++;
        return \Condoedge\Ai\Services\Actions\Results\AiActionResult::element('charged', 'Charged.');
    }
}
