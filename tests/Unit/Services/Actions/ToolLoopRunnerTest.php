<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\DTOs\LlmToolCall;
use Condoedge\Ai\DTOs\LlmToolTurn;
use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\ActionToolSpec;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\ModelMethodMutationAction;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Actions\ToolLoopRunner;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\CapturingAction;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\ScriptedToolProvider;
use PHPUnit\Framework\TestCase;

/**
 * ToolLoopRunner (P1-C1 / T5): the bounded native tool loop is dispatch-only — every call
 * funnels through ActionInvocation into ActionDispatcher::dispatch(), the SAME door as the
 * markdown link path. THE invariant: a mutating action's handle() is never invoked during
 * the loop (Pending is recorded, the loop-time token discarded); a source tripwire pins that
 * the runner has no execution call and no write-branching of its own. Mid-loop failure is
 * deterministic: round-1 failure propagates (never a markdown fallback call), later failure
 * composes from partial text + summaryForLlm lines with zero further LLM calls.
 */
class ToolLoopRunnerTest extends TestCase
{
    /** @test */
    public function first_turn_without_tool_calls_is_the_final_answer(): void
    {
        $action = $this->spy('view');
        $dispatcher = $this->dispatcherFor($action);
        $provider = new ScriptedToolProvider([$this->textTurn('Direct answer.')]);
        $specs = [ActionToolSpec::fromAction($action)];

        $result = $this->runner($dispatcher)->run($provider, $this->messages(), $specs);

        $this->assertSame('Direct answer.', $result->answer);
        $this->assertSame([], $result->invocations);
        $this->assertCount(1, $provider->turns);
        $this->assertSame($specs, $provider->turns[0]['toolSpecs']);
        $this->assertSame(
            ['channel' => 'native', 'rounds' => 1, 'tool_calls' => 0, 'degraded' => false],
            $result->telemetry
        );
    }

    /** @test */
    public function read_only_call_executes_through_the_dispatcher_and_feeds_the_summary_back(): void
    {
        $action = $this->spy('view', schema: ['x' => ['type' => 'int']], result: AiActionResult::element('done', 'Viewed 3 rows.'));
        $dispatcher = $this->dispatcherFor($action);
        $provider = new ScriptedToolProvider([
            $this->toolTurn([new LlmToolCall('c1', 'view', ['x' => 1])]),
            $this->textTurn('Here you go.'),
        ]);

        $result = $this->runner($dispatcher)->run($provider, $this->messages(), [ActionToolSpec::fromAction($action)]);

        $this->assertSame(1, $action->handleCalls, 'read-only call executes during the loop');
        $this->assertSame('Viewed 3 rows.', $provider->results[0]['c1']);
        $this->assertSame('Here you go.', $result->answer);

        // Executed artifacts are recorded too — re-dispatched at render to build the visual.
        $this->assertCount(1, $result->invocations);
        $this->assertSame('view', $result->invocations[0]->key);
        $this->assertSame(['x' => 1], $result->invocations[0]->params);

        // Round 2 history = round 1 history + the provider-shaped appendix, appended untouched.
        $second = $provider->turns[1]['messages'];
        $this->assertCount(3, $second);
        $this->assertSame($this->messages()[0], $second[0]);
        $this->assertSame('Viewed 3 rows.', $second[2]['content']);

        $this->assertSame(
            ['channel' => 'native', 'rounds' => 2, 'tool_calls' => 1, 'degraded' => false],
            $result->telemetry
        );
    }

    /** @test */
    public function mutating_call_is_deferred_recorded_filtered_and_reported_as_not_performed(): void
    {
        $action = $this->spy('delete', mutating: true, schema: ['x' => ['type' => 'int']]);
        $dispatcher = $this->dispatcherFor($action);
        $provider = new ScriptedToolProvider([
            $this->toolTurn([new LlmToolCall('c1', 'delete', ['x' => '5', 'bogus' => 'y'])]),
            $this->textTurn('I have proposed the deletion.'),
        ]);

        $result = $this->runner($dispatcher)->run($provider, $this->messages(), [ActionToolSpec::fromAction($action)]);

        // THE invariant: the write path is never invoked during the loop.
        $this->assertSame(0, $action->handleCalls, 'mutating action must NEVER execute in the loop');
        // The generation-time dispatch still earns its keep: authorize() ran inside the turn.
        $this->assertSame(1, $action->authorizeCalls);

        // Recorded with schema-filtered params only — raw model args never persist.
        $this->assertCount(1, $result->invocations);
        $this->assertSame('delete', $result->invocations[0]->key);
        $this->assertSame(['x' => 5], $result->invocations[0]->params);

        // The model is told the action was NOT performed (a string result, not an error).
        $content = $provider->results[0]['c1'];
        $this->assertIsString($content);
        $this->assertStringContainsString('pending_approval', $content);
        $this->assertStringContainsString('NOT been performed', $content);
        $this->assertStringContainsString('Proposed: Delete', $content);

        $this->assertSame('I have proposed the deletion.', $result->answer);
    }

    /** @test */
    public function entity_call_routes_the_id_into_dispatch_context(): void
    {
        $captured = null;
        $action = new CapturingAction('profile', static function (array $input, ActionContext $context) use (&$captured) {
            $captured = $context;
            return AiActionResult::element('profile-link', 'Profile shown.');
        });
        $registry = new AiActionRegistry();
        $registry->registerEntity('Person', $action);
        $dispatcher = new ActionDispatcher($registry);

        $spec = ActionToolSpec::fromAction($action, 'Person');
        $provider = new ScriptedToolProvider([
            $this->toolTurn([new LlmToolCall('c1', $spec->name, ['entity_id' => '152463'])]),
            $this->textTurn('Done.'),
        ]);

        $result = $this->runner($dispatcher)->run($provider, $this->messages(), [$spec]);

        // The spec-injected entity_id rides the dispatch CONTEXT, mirroring the entity:// path
        // segment — never the schema-filtered params of an action that does not declare it.
        $this->assertNotNull($captured);
        $this->assertSame('152463', $captured->entityId);
        $this->assertSame('Person', $captured->entityType);

        $this->assertCount(1, $result->invocations);
        $this->assertSame('Person', $result->invocations[0]->entityType);
        $this->assertSame('152463', $result->invocations[0]->entityId);
        $this->assertSame([], $result->invocations[0]->params);
    }

    /** @test */
    public function sanitized_tool_names_re_anchor_on_the_spec_identity(): void
    {
        $action = new CapturingAction('profile', static fn () => AiActionResult::element('ok', 'Profile shown.'));
        $registry = new AiActionRegistry();
        $registry->registerEntity('App.Models.Person', $action);
        $dispatcher = new ActionDispatcher($registry);

        $spec = ActionToolSpec::fromAction($action, 'App.Models.Person');
        $this->assertSame('App_Models_Person__profile', $spec->name, 'sanitization is lossy on purpose here');

        $provider = new ScriptedToolProvider([
            $this->toolTurn([new LlmToolCall('c1', $spec->name, ['entity_id' => '3'])]),
            $this->textTurn('Done.'),
        ]);

        $result = $this->runner($dispatcher)->run($provider, $this->messages(), [$spec]);

        // The registry lookup and the persisted record both use the spec's SOURCE identity,
        // not a reverse-parse of the sanitized wire name.
        $this->assertSame('Profile shown.', $provider->results[0]['c1']);
        $this->assertSame('App.Models.Person', $result->invocations[0]->entityType);
        $this->assertSame('profile', $result->invocations[0]->key);
    }

    /** @test */
    public function unauthorized_dispatch_yields_an_error_result_and_records_nothing(): void
    {
        $action = $this->spy('delete', mutating: true, authorized: false);
        $dispatcher = $this->dispatcherFor($action);
        $provider = new ScriptedToolProvider([
            $this->toolTurn([new LlmToolCall('c1', 'delete', [])]),
            $this->textTurn('You cannot do that.'),
        ]);

        $result = $this->runner($dispatcher)->run($provider, $this->messages(), [ActionToolSpec::fromAction($action)]);

        $this->assertSame(0, $action->handleCalls);
        $this->assertSame([], $result->invocations, 'a denied proposal must not persist');

        $content = $provider->results[0]['c1'];
        $this->assertIsArray($content);
        $this->assertTrue($content['is_error']);
        $this->assertSame('You are not authorized to perform this action.', $content['content']);

        $this->assertSame('You cannot do that.', $result->answer);
    }

    /** @test */
    public function unknown_tool_name_is_an_error_and_the_loop_survives(): void
    {
        $action = $this->spy('view');
        $dispatcher = $this->dispatcherFor($action);
        $provider = new ScriptedToolProvider([
            $this->toolTurn([new LlmToolCall('c1', 'delete_all_users', ['sure' => true])]),
            $this->textTurn('No such action exists.'),
        ]);

        $result = $this->runner($dispatcher)->run($provider, $this->messages(), [ActionToolSpec::fromAction($action)]);

        $content = $provider->results[0]['c1'];
        $this->assertIsArray($content);
        $this->assertTrue($content['is_error']);
        $this->assertStringContainsString('Unknown action', $content['content']);

        $this->assertSame(0, $action->handleCalls);
        $this->assertSame([], $result->invocations);
        $this->assertSame('No such action exists.', $result->answer);
    }

    /** @test */
    public function malformed_args_never_reach_dispatch_and_get_a_corrective_result(): void
    {
        $action = $this->spy('view');
        $dispatcher = $this->dispatcherFor($action);
        $provider = new ScriptedToolProvider([
            $this->toolTurn([new LlmToolCall('c1', 'view', [], 'Tool arguments are not valid JSON: Syntax error')]),
            $this->textTurn('Recovered.'),
        ]);

        $result = $this->runner($dispatcher)->run($provider, $this->messages(), [ActionToolSpec::fromAction($action)]);

        $this->assertSame(0, $action->authorizeCalls, 'a malformed call must never reach the dispatcher');
        $this->assertSame(0, $action->handleCalls);

        $content = $provider->results[0]['c1'];
        $this->assertIsArray($content);
        $this->assertTrue($content['is_error']);
        $this->assertStringContainsString('Invalid arguments', $content['content']);

        // The corrective result + the next round IS the bounded retry.
        $this->assertSame('Recovered.', $result->answer);
        $this->assertCount(2, $provider->turns);
    }

    /** @test */
    public function duplicate_calls_within_a_turn_dispatch_once(): void
    {
        $action = $this->spy('view', schema: ['x' => ['type' => 'int']], result: AiActionResult::element('done', 'Viewed.'));
        $dispatcher = $this->dispatcherFor($action);
        $provider = new ScriptedToolProvider([
            $this->toolTurn([
                new LlmToolCall('c1', 'view', ['x' => 1]),
                new LlmToolCall('c2', 'view', ['x' => '1']),
            ]),
            $this->textTurn('Done.'),
        ]);

        $result = $this->runner($dispatcher)->run($provider, $this->messages(), [ActionToolSpec::fromAction($action)]);

        $this->assertSame(1, $action->handleCalls, 'fingerprint-equal calls dispatch once per turn');
        // Every call id still gets a tool_result — providers require one per call.
        $this->assertSame($provider->results[0]['c1'], $provider->results[0]['c2']);
        $this->assertCount(1, $result->invocations);
        $this->assertSame(2, $result->telemetry['tool_calls']);
    }

    /** @test */
    public function round_cap_exhaustion_forces_a_no_tools_close(): void
    {
        $action = $this->spy('view', schema: ['x' => ['type' => 'int']], result: AiActionResult::element('done', 'Viewed.'));
        $dispatcher = $this->dispatcherFor($action);
        $provider = new ScriptedToolProvider([
            $this->toolTurn([new LlmToolCall('c1', 'view', ['x' => 1])]),
            $this->toolTurn([new LlmToolCall('c2', 'view', ['x' => 2])]),
            $this->textTurn('Final.'),
        ]);

        $result = $this->runner($dispatcher, rounds: 2)->run(
            $provider,
            $this->messages(),
            [ActionToolSpec::fromAction($action)],
            [],
            ['tool_choice' => 'auto', 'temperature' => 0.1]
        );

        $this->assertCount(3, $provider->turns);
        $this->assertSame('auto', $provider->turns[0]['options']['tool_choice']);
        $this->assertSame('auto', $provider->turns[1]['options']['tool_choice']);
        // The forced close omits tools — and a dangling tool_choice with them.
        $this->assertSame([], $provider->turns[2]['toolSpecs']);
        $this->assertSame(['temperature' => 0.1], $provider->turns[2]['options']);

        $this->assertSame('Final.', $result->answer);
        $this->assertSame(2, $action->handleCalls, 'the final tool round is still processed before closing');
        $this->assertSame(
            ['channel' => 'native', 'rounds' => 3, 'tool_calls' => 2, 'degraded' => false],
            $result->telemetry
        );
    }

    /** @test */
    public function call_budget_caps_dispatches(): void
    {
        $action = $this->spy('view', schema: ['x' => ['type' => 'int']], result: AiActionResult::element('done', 'Viewed.'));
        $dispatcher = $this->dispatcherFor($action);
        $provider = new ScriptedToolProvider([
            $this->toolTurn([
                new LlmToolCall('c1', 'view', ['x' => 1]),
                new LlmToolCall('c2', 'view', ['x' => 2]),
                new LlmToolCall('c3', 'view', ['x' => 3]),
            ]),
            $this->textTurn('Done.'),
        ]);

        $result = $this->runner($dispatcher, calls: 2)->run($provider, $this->messages(), [ActionToolSpec::fromAction($action)]);

        $this->assertSame(2, $action->handleCalls);

        $content = $provider->results[0]['c3'];
        $this->assertIsArray($content);
        $this->assertTrue($content['is_error']);
        $this->assertStringContainsString('limit', $content['content']);

        $this->assertSame(3, $result->telemetry['tool_calls']);
        $this->assertSame('Done.', $result->answer);
    }

    /** @test */
    public function round_one_failure_propagates_without_fallback(): void
    {
        $action = $this->spy('view');
        $dispatcher = $this->dispatcherFor($action);
        $provider = new ScriptedToolProvider([new \RuntimeException('breaker open')]);

        try {
            $this->runner($dispatcher)->run($provider, $this->messages(), [ActionToolSpec::fromAction($action)]);
            $this->fail('a round-1 failure with nothing accumulated must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('breaker open', $e->getMessage());
        }

        // No markdown fallback, no second call against a possibly-open breaker.
        $this->assertCount(1, $provider->turns);
    }

    /** @test */
    public function later_round_failure_composes_deterministically_with_invocations_intact(): void
    {
        $action = $this->spy('delete', mutating: true, schema: ['x' => ['type' => 'int']]);
        $dispatcher = $this->dispatcherFor($action);
        $provider = new ScriptedToolProvider([
            $this->toolTurn([new LlmToolCall('c1', 'delete', ['x' => 5])], 'Partial thoughts.'),
            new \RuntimeException('outage'),
        ]);

        $result = $this->runner($dispatcher)->run($provider, $this->messages(), [ActionToolSpec::fromAction($action)]);

        // Zero further LLM calls: the answer is composed from partial text + summary lines.
        $this->assertCount(2, $provider->turns);
        $this->assertStringContainsString('Partial thoughts.', $result->answer);
        $this->assertStringContainsString('Proposed (requires approval): Delete', $result->answer);

        // The deferred approval survives the LLM outage — the invocation is already recorded.
        $this->assertCount(1, $result->invocations);
        $this->assertSame(['x' => 5], $result->invocations[0]->params);
        $this->assertSame(0, $action->handleCalls);
        $this->assertTrue($result->telemetry['degraded']);
    }

    /** @test */
    public function failed_forced_close_composes_deterministically(): void
    {
        $action = $this->spy('view', result: AiActionResult::element('done', 'Viewed 3 rows.'));
        $dispatcher = $this->dispatcherFor($action);
        $provider = new ScriptedToolProvider([
            $this->toolTurn([new LlmToolCall('c1', 'view', [])]),
            new \RuntimeException('outage'),
        ]);

        $result = $this->runner($dispatcher, rounds: 1)->run($provider, $this->messages(), [ActionToolSpec::fromAction($action)]);

        $this->assertCount(2, $provider->turns, 'one tool round + the failed forced close');
        $this->assertStringContainsString('Viewed 3 rows.', $result->answer);
        $this->assertSame(
            ['channel' => 'native', 'rounds' => 1, 'tool_calls' => 1, 'degraded' => true],
            $result->telemetry
        );
    }

    /** @test */
    public function tripwire_runner_source_has_no_execution_call_and_no_write_branching(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(ToolLoopRunner::class))->getFileName());

        $this->assertStringNotContainsString('->handle(', $source);
        $this->assertStringNotContainsString('mutates(', $source);
    }

    /** @test */
    public function entity_mutation_action_defers_with_the_id_riding_the_invocation(): void
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
            $this->toolTurn([new LlmToolCall('c1', $spec->name, ['entity_id' => '9'])]),
            $this->textTurn('Awaiting your confirmation.'),
        ]);

        $result = $this->runner($dispatcher)->run($provider, $this->messages(), [$spec], ['user_id' => 7]);

        $this->assertSame(0, $model->approvals, 'the wrapped domain write must not run in the loop');
        $this->assertStringContainsString('pending_approval', (string) $provider->results[0]['c1']);

        $this->assertCount(1, $result->invocations);
        $this->assertSame('approve_record', $result->invocations[0]->key);
        $this->assertSame('Record', $result->invocations[0]->entityType);
        $this->assertSame('9', $result->invocations[0]->entityId);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function spy(
        string $key,
        bool $mutating = false,
        bool $authorized = true,
        array $schema = [],
        ?AiActionResult $result = null,
    ): ToolLoopSpyAction {
        return new ToolLoopSpyAction($key, $mutating, $authorized, $schema, $result);
    }

    private function dispatcherFor(AiActionInterface $action): ActionDispatcher
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric($action);

        return new ActionDispatcher($registry);
    }

    private function runner(ActionDispatcher $dispatcher, int $rounds = 2, int $calls = 5): ToolLoopRunner
    {
        return new ToolLoopRunner($dispatcher, toolRounds: $rounds, maxToolCalls: $calls);
    }

    /** @return array<int, array<string, string>> */
    private function messages(): array
    {
        return [['role' => 'user', 'content' => 'do the thing']];
    }

    /** @param \Condoedge\Ai\DTOs\LlmToolCall[] $calls */
    private function toolTurn(array $calls, ?string $text = null): LlmToolTurn
    {
        return new LlmToolTurn($text, $calls, ['role' => 'assistant', 'calls' => count($calls)], 'tool_calls');
    }

    private function textTurn(string $text): LlmToolTurn
    {
        return new LlmToolTurn($text, [], ['role' => 'assistant'], 'stop');
    }
}

/**
 * Spy action recording authorize/handle traffic so the loop's dispatch-only contract can be
 * asserted precisely (named to avoid colliding with ApprovalFlowTest's SpyAction in this
 * namespace).
 */
class ToolLoopSpyAction implements AiActionInterface
{
    public int $handleCalls = 0;
    public int $authorizeCalls = 0;

    /** @var array<string, mixed> */
    public array $lastInput = [];

    public function __construct(
        private string $key,
        private bool $mutating,
        public bool $authorized = true,
        private array $schema = [],
        private ?AiActionResult $result = null,
    ) {
    }

    public function key(): string { return $this->key; }
    public function label(): string { return ucfirst($this->key); }
    public function aliases(): array { return []; }
    public function description(): string { return 'Spy action for the tool loop.'; }
    public function inputSchema(): array { return $this->schema; }
    public function mutates(): bool { return $this->mutating; }

    public function authorize(mixed $user, array $input): bool
    {
        $this->authorizeCalls++;
        return $this->authorized;
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $this->handleCalls++;
        $this->lastInput = $input;

        return $this->result ?? AiActionResult::element('done', 'Done.');
    }
}
