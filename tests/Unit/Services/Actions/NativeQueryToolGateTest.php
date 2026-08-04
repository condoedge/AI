<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\DTOs\LlmToolCall;
use Condoedge\Ai\DTOs\LlmToolTurn;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\ActionToolGate;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\GraphQueryTool;
use Condoedge\Ai\Services\Actions\ToolLoopRunner;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\RecordingQueryExecutor;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\ScriptedToolProvider;
use Orchestra\Testbench\TestCase;

/**
 * Native run_query tool wiring (C3-multi-query). The read-only GraphQueryTool is offered in the
 * EXISTING native tool loop ONLY behind config('ai.actions.native_query_tool') (default OFF), in
 * ADDITION to native_tools itself. Acceptance:
 *   - flag ON  → the registry resolves run_query and the gate offers its spec;
 *   - flag OFF → byte-identical to today (no run_query anywhere);
 *   - the per-turn call budget halts a runaway sequence of run_query calls.
 *
 * Bare Testbench (no AiServiceProvider) so config is bound without API keys.
 */
class NativeQueryToolGateTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A clean slate so only the flags under test drive the wiring.
        config()->set('ai.actions', []);
        config()->set('ai.entity_actions', []);
        config()->set('ai.generic_actions', []);
        config()->set('ai.response_generation.default_style', 'friendly');
    }

    /** @test */
    public function flag_off_by_default_means_no_run_query_is_registered_or_offered(): void
    {
        // Default: native_tools off AND native_query_tool off.
        $registry = new AiActionRegistry();
        $this->assertNull($registry->getGeneric('run_query'), 'run_query must not register when the flag is off');

        $gate = new ActionToolGate($registry);
        $specs = $gate->specsFor(new PipelineContext());
        $this->assertSame([], $this->toolNames($specs, 'run_query'), 'the gate must not offer run_query when the flag is off');
    }

    /** @test */
    public function native_tools_off_keeps_run_query_out_of_the_offered_set(): void
    {
        // The query tool rides INSIDE the native loop. With native_tools off, the gate never
        // offers ANY native tools — run_query included — even if its own flag is on. specsFor()
        // is only ever consulted by decide() once native_tools has passed, so it must stay inert
        // here for the offered set to be empty.
        config()->set('ai.actions.native_query_tool', true);
        config()->set('ai.actions.native_tools', false);

        $gate = new ActionToolGate(new AiActionRegistry());

        $this->assertSame(
            [],
            $this->toolNames($gate->specsFor(new PipelineContext()), 'run_query'),
            'run_query is not offered while native_tools is off'
        );
    }

    /** @test */
    public function flag_on_registers_run_query_and_the_gate_offers_it(): void
    {
        config()->set('ai.actions.native_tools', true);
        config()->set('ai.actions.native_query_tool', true);

        $registry = new AiActionRegistry();
        $tool = $registry->getGeneric('run_query');
        $this->assertInstanceOf(GraphQueryTool::class, $tool, 'the flag registers run_query so the loop can dispatch it');

        $gate = new ActionToolGate($registry);
        $specs = $gate->specsFor(new PipelineContext());
        $this->assertContains('run_query', $this->toolNames($specs), 'the gate offers run_query when both flags are on');

        // Its advertised spec is read-only (no approval suffix) and requires cypher.
        $spec = $this->specNamed($specs, 'run_query');
        $this->assertFalse($spec->mutates);
        $this->assertContains('cypher', $spec->parameters['required'] ?? []);
    }

    /** @test */
    public function the_call_budget_halts_a_runaway_of_run_query_calls(): void
    {
        config()->set('ai.actions.native_tools', true);
        config()->set('ai.actions.native_query_tool', true);

        $registry = new AiActionRegistry();
        // A recording executor stand-in keeps this test off any graph backend while proving the
        // budget caps DISPATCHES, not just provider rounds.
        $executor = new RecordingQueryExecutor(new \Condoedge\Ai\DTOs\QueryExecutionResult(success: true, data: [['x' => 1]]));
        $registry->registerGeneric(new GraphQueryTool($executor));

        $dispatcher = new ActionDispatcher($registry);
        $runner = new ToolLoopRunner($dispatcher, toolRounds: 2, maxToolCalls: 2);

        $gate = new ActionToolGate($registry);
        $specs = $gate->specsFor(new PipelineContext());

        $provider = new ScriptedToolProvider([
            new LlmToolTurn(null, [
                new LlmToolCall('c1', 'run_query', ['cypher' => 'MATCH (a) RETURN a']),
                new LlmToolCall('c2', 'run_query', ['cypher' => 'MATCH (b) RETURN b']),
                new LlmToolCall('c3', 'run_query', ['cypher' => 'MATCH (c) RETURN c']),
            ], ['role' => 'assistant'], 'tool_calls'),
            new LlmToolTurn('Composed over what I could fetch.', [], ['role' => 'assistant'], 'stop'),
        ]);

        $result = $runner->run($provider, [['role' => 'user', 'content' => 'many things']], $specs);

        // Exactly the budget is dispatched; the over-budget call is told to stop, never executed.
        $this->assertSame(2, $executor->calls, 'the per-turn call budget caps run_query dispatches');
        $overBudget = $provider->results[0]['c3'];
        $this->assertIsArray($overBudget);
        $this->assertTrue($overBudget['is_error']);
        $this->assertStringContainsString('limit', $overBudget['content']);

        $this->assertSame(3, $result->telemetry['tool_calls']);
        $this->assertSame('Composed over what I could fetch.', $result->answer);
    }

    /**
     * @param \Condoedge\Ai\Services\Actions\ActionToolSpec[] $specs
     * @return string[]
     */
    private function toolNames(array $specs, ?string $only = null): array
    {
        $names = array_map(static fn ($spec) => $spec->name, $specs);

        return $only === null ? $names : array_values(array_filter($names, static fn ($n) => $n === $only));
    }

    /** @param \Condoedge\Ai\Services\Actions\ActionToolSpec[] $specs */
    private function specNamed(array $specs, string $name): \Condoedge\Ai\Services\Actions\ActionToolSpec
    {
        foreach ($specs as $spec) {
            if ($spec->name === $name) {
                return $spec;
            }
        }

        $this->fail("No tool spec named {$name}");
    }
}
