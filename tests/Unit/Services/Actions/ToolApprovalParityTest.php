<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\DTOs\LlmToolCall;
use Condoedge\Ai\DTOs\LlmToolTurn;
use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\ActionToolSpec;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\ModelMethodMutationAction;
use Condoedge\Ai\Services\Actions\PendingActionToken;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Actions\ToolLoopRunner;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\ScriptedToolProvider;
use PHPUnit\Framework\TestCase;

/**
 * Native-tool ↔ approval-gate parity bridge (P1-C1 / T6): a loop-recorded invocation,
 * persisted as metadata and revived at render, goes through the SAME render-time dispatch →
 * fresh signed token → approve() pipeline the markdown link path uses — mirroring
 * ApprovalFlowTest to prove parity, not new behavior. The trust core (ActionDispatcher,
 * PendingActionToken) is exercised unmodified: fresh authorize() at every gate, single-use
 * nonce, TTL, user binding.
 */
class ToolApprovalParityTest extends TestCase
{
    /** @test */
    public function loop_proposal_never_executes_and_render_dispatch_mints_a_fresh_decodable_token(): void
    {
        [$dispatcher, $action, $revived] = $this->loopProposal();

        $this->assertSame(0, $action->handleCalls, 'nothing executes during the loop');

        // The persisted record carries schema-filtered params only (cast, undeclared dropped).
        $this->assertNotNull($revived);
        $this->assertSame('archive', $revived->key);
        $this->assertSame(['x' => 5], $revived->params);

        // Render-time dispatch of the revived invocation mints a fresh signed token.
        $pending = $dispatcher->dispatch($action, $revived->params, ['user_id' => 7]);

        $this->assertSame(0, $action->handleCalls, 'render-time dispatch defers, exactly like a link');
        $this->assertIsArray($pending->renderable);
        $this->assertTrue($pending->renderable['pending']);

        $decoded = PendingActionToken::decode($pending->renderable['token']);
        $this->assertNotNull($decoded);
        $this->assertSame('archive', $decoded->actionKey);
        $this->assertSame(['x' => 5], $decoded->input);
        $this->assertSame('7', (string) $decoded->userId);
    }

    /** @test */
    public function approve_executes_exactly_once_and_replay_is_blocked_by_the_nonce(): void
    {
        [$dispatcher, $action, $revived] = $this->loopProposal();

        $pending = $dispatcher->dispatch($action, $revived->params, ['user_id' => 7]);
        $token = $pending->renderable['token'];

        $approved = $dispatcher->approve($token, ['user_id' => 7]);

        $this->assertFalse($approved->isError);
        $this->assertSame(1, $action->handleCalls, 'handle() runs only after approval, exactly once');
        $this->assertSame(['x' => 5], $action->lastInput);
        // Fresh authorize() at every gate: loop dispatch + render dispatch + approve.
        $this->assertSame(3, $action->authorizeCalls);

        $replayed = $dispatcher->approve($token, ['user_id' => 7]);

        $this->assertTrue($replayed->isError);
        $this->assertStringContainsString('already been confirmed', $replayed->summaryForLlm);
        $this->assertSame(1, $action->handleCalls, 'replay must not re-execute');
    }

    /** @test */
    public function every_render_mints_a_fresh_token(): void
    {
        [$dispatcher, $action, $revived] = $this->loopProposal();

        $first = $dispatcher->dispatch($action, $revived->params, ['user_id' => 7]);
        $second = $dispatcher->dispatch($action, $revived->params, ['user_id' => 7]);

        $this->assertNotSame($first->renderable['token'], $second->renderable['token']);

        $firstDecoded = PendingActionToken::decode($first->renderable['token']);
        $secondDecoded = PendingActionToken::decode($second->renderable['token']);
        $this->assertNotNull($firstDecoded);
        $this->assertNotNull($secondDecoded);
        $this->assertNotSame($firstDecoded->nonce, $secondDecoded->nonce, 'each render gets its own single-use nonce');
    }

    /** @test */
    public function tampered_token_is_rejected_without_executing(): void
    {
        [$dispatcher, $action, $revived] = $this->loopProposal();

        $pending = $dispatcher->dispatch($action, $revived->params, ['user_id' => 7]);

        $rejected = $dispatcher->approve($pending->renderable['token'] . 'x', ['user_id' => 7]);

        $this->assertTrue($rejected->isError);
        $this->assertSame(0, $action->handleCalls);
    }

    /** @test */
    public function expired_token_is_rejected_without_executing(): void
    {
        [$dispatcher, $action] = $this->loopProposal();

        // Signature-valid but aged past the TTL (default 900s) — decode fails closed.
        $expired = new PendingActionToken('archive', null, ['x' => 5], 7, time() - 3600, bin2hex(random_bytes(16)));

        $rejected = $dispatcher->approve($expired->encode(), ['user_id' => 7]);

        $this->assertTrue($rejected->isError);
        $this->assertSame(0, $action->handleCalls);
    }

    /** @test */
    public function foreign_user_cannot_confirm(): void
    {
        [$dispatcher, $action, $revived] = $this->loopProposal();

        $pending = $dispatcher->dispatch($action, $revived->params, ['user_id' => 7]);

        $rejected = $dispatcher->approve($pending->renderable['token'], ['user_id' => 999]);

        $this->assertTrue($rejected->isError);
        $this->assertSame(0, $action->handleCalls);
    }

    /** @test */
    public function entity_invocation_round_trips_through_token_and_approve(): void
    {
        $model = new class {
            public int $approvals = 0;
        };
        $action = new ModelMethodMutationAction(
            'approve_record',
            'Approve record',
            static fn ($id) => (string) $id === '9' ? $model : null,
            static function ($m, array $input) {
                $m->approvals++;
            },
            static fn ($user, $m, array $input) => true,
            entityType: 'Record',
        );
        $registry = new AiActionRegistry();
        $registry->registerEntity('Record', $action);
        $dispatcher = new ActionDispatcher($registry);

        $spec = ActionToolSpec::fromAction($action, 'Record');
        $provider = new ScriptedToolProvider([
            new LlmToolTurn(null, [new LlmToolCall('c1', $spec->name, ['entity_id' => '9'])], ['role' => 'assistant'], 'tool_calls'),
            new LlmToolTurn('Awaiting confirmation.', [], ['role' => 'assistant'], 'stop'),
        ]);

        $result = (new ToolLoopRunner($dispatcher, toolRounds: 2, maxToolCalls: 5))->run(
            $provider,
            [['role' => 'user', 'content' => 'approve record 9']],
            [$spec],
            ['user_id' => 7],
        );

        $this->assertSame(0, $model->approvals);

        $revived = $this->persistAndRevive($result->invocations[0]);
        $this->assertNotNull($revived);

        // Render: the invocation's entity id rides the dispatch context like the entity://
        // path segment, is seeded into the filtered input, and so survives in the token.
        $pending = $dispatcher->dispatch($action, $revived->params, [
            'entity_type' => $revived->entityType,
            'entity_id' => $revived->entityId,
            'user_id' => 7,
        ]);

        $this->assertTrue($pending->renderable['pending']);
        $decoded = PendingActionToken::decode($pending->renderable['token']);
        $this->assertNotNull($decoded);
        $this->assertSame('Record', $decoded->entityType);
        $this->assertSame(['entity_id' => '9'], $decoded->input);

        $approved = $dispatcher->approve($pending->renderable['token'], ['user_id' => 7]);

        $this->assertFalse($approved->isError);
        $this->assertSame(1, $model->approvals, 'the wrapped domain write runs exactly once, after approval');

        $replayed = $dispatcher->approve($pending->renderable['token'], ['user_id' => 7]);
        $this->assertTrue($replayed->isError);
        $this->assertSame(1, $model->approvals);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * Run the loop with a scripted provider proposing a mutating action, then persist + revive
     * the recorded invocation the way the renderer will (JSON round trip of toArray()).
     *
     * @return array{0: ActionDispatcher, 1: BridgeSpyAction, 2: ?ActionInvocation}
     */
    private function loopProposal(): array
    {
        $action = new BridgeSpyAction('archive', ['x' => ['type' => 'int']]);
        $registry = new AiActionRegistry();
        $registry->registerGeneric($action);
        $dispatcher = new ActionDispatcher($registry);

        $provider = new ScriptedToolProvider([
            new LlmToolTurn(null, [new LlmToolCall('c1', 'archive', ['x' => '5', 'evil' => 'drop'])], ['role' => 'assistant'], 'tool_calls'),
            new LlmToolTurn('I have proposed archiving it.', [], ['role' => 'assistant'], 'stop'),
        ]);

        $result = (new ToolLoopRunner($dispatcher, toolRounds: 2, maxToolCalls: 5))->run(
            $provider,
            [['role' => 'user', 'content' => 'archive it']],
            [ActionToolSpec::fromAction($action)],
            ['user_id' => 7],
        );

        $this->assertCount(1, $result->invocations);

        return [$dispatcher, $action, $this->persistAndRevive($result->invocations[0])];
    }

    private function persistAndRevive(ActionInvocation $invocation): ?ActionInvocation
    {
        return ActionInvocation::fromArray(json_decode((string) json_encode($invocation->toArray()), true));
    }
}

/**
 * Mutating spy with a typed schema and a handle counter, mirroring ApprovalFlowTest's
 * SpyAction (named to avoid colliding with it in this namespace).
 */
class BridgeSpyAction implements AiActionInterface
{
    public int $handleCalls = 0;
    public int $authorizeCalls = 0;

    /** @var array<string, mixed> */
    public array $lastInput = [];

    public function __construct(
        private string $key,
        private array $schema = [],
    ) {
    }

    public function key(): string { return $this->key; }
    public function label(): string { return ucfirst($this->key); }
    public function aliases(): array { return []; }
    public function description(): string { return 'Bridge spy for approval parity.'; }
    public function inputSchema(): array { return $this->schema; }
    public function mutates(): bool { return true; }

    public function authorize(mixed $user, array $input): bool
    {
        $this->authorizeCalls++;
        return true;
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $this->handleCalls++;
        $this->lastInput = $input;

        return AiActionResult::element('archived', 'Archived.');
    }
}
