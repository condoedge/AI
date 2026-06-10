<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\ToolCallingLlmInterface;
use Condoedge\Ai\DTOs\LlmToolCall;
use Condoedge\Ai\DTOs\LlmToolTurn;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\ActionToolChannel;
use Condoedge\Ai\Services\Actions\ActionToolGate;
use Condoedge\Ai\Services\Actions\ActionToolSpec;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\ToolLoopRunner;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Services\ResponseSections\ResponseEntityActionsSection;
use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\CapturingAction;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\ScriptedToolProvider;
use Illuminate\Support\Facades\Log;
use Mockery;

/**
 * ActionToolGate + ResponseGenerator channel wiring (P1-C1 / T7): ONE decision point chooses
 * native tools, the markdown link grammar, or nothing — default OFF. The fallback is the
 * regression lock: a non-capable provider (or the kill switch off) must produce a prompt
 * byte-identical to the pre-change buildPrompts() output and exactly one complete() call.
 * The abstention path never consults the gate, so an ungrounded model is never handed tools.
 */
class ResponseGeneratorToolChannelTest extends TestCase
{
    private $discovery;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Dummy keys so container resolution of the per-role LLM never throws in tests.
        $app['config']->set('ai.llm.openai.api_key', 'test-key');
        $app['config']->set('ai.llm.anthropic.api_key', 'test-key');
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->discovery = Mockery::mock(EntityAutoDiscovery::class);
        $this->discovery->allows('hasEntityActions')->andReturnUsing(static fn ($type) => $type === 'Person');
        $this->discovery->allows('getEntityActions')->with('Person')->andReturn([
            ['action_key' => 'profile', 'aliases' => ['profile link'], 'label' => 'View Profile'],
        ]);
        $this->discovery->allows('getGenericActions')->andReturn([
            ['action_key' => 'open_modal', 'label' => 'Open a modal', 'aliases' => []],
        ]);
        $this->app->instance(EntityAutoDiscovery::class, $this->discovery);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // The gate
    // =========================================================================

    /** @test */
    public function native_requires_config_on_a_capable_provider_and_a_non_empty_tool_set(): void
    {
        $gate = new ActionToolGate($this->registry());
        $capable = new ToolChannelCapableProvider();
        $plain = Mockery::mock(LlmProviderInterface::class);
        $specs = $this->specs();
        $options = ['style' => 'friendly'];

        // Default OFF — the kill switch ships disabled.
        $this->assertSame(ActionToolChannel::Markdown, $gate->channel($capable, $specs, $options));

        config()->set('ai.actions.native_tools', true);

        $this->assertSame(ActionToolChannel::Native, $gate->channel($capable, $specs, $options));
        $this->assertSame(
            ActionToolChannel::Markdown,
            $gate->channel($capable, [], $options),
            'an empty tool set is never Native — no tools: [] requests, no loop'
        );
        $this->assertSame(
            ActionToolChannel::Markdown,
            $gate->channel($plain, $specs, $options),
            'instanceof ToolCallingLlmInterface is the entire capability check'
        );
    }

    /** @test */
    public function terse_styles_yield_channel_none(): void
    {
        config()->set('ai.actions.native_tools', true);
        $gate = new ActionToolGate($this->registry());
        $capable = new ToolChannelCapableProvider();
        $specs = $this->specs();

        $this->assertSame(ActionToolChannel::None, $gate->channel($capable, $specs, ['style' => 'minimal']));
        $this->assertSame(ActionToolChannel::None, $gate->channel($capable, $specs, ['style' => 'concise']));
    }

    /** @test */
    public function capability_drift_degrades_to_markdown_with_one_warning(): void
    {
        config()->set('ai.actions.native_tools', true);
        Log::spy();

        $gate = new ActionToolGate($this->registry());
        $plain = Mockery::mock(LlmProviderInterface::class);

        $this->assertSame(ActionToolChannel::Markdown, $gate->channel($plain, $this->specs(), ['style' => 'friendly']));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(static fn ($message) => str_contains((string) $message, 'native_tools'));
    }

    /** @test */
    public function no_drift_warning_while_the_kill_switch_is_off(): void
    {
        Log::spy();

        $gate = new ActionToolGate($this->registry());
        $plain = Mockery::mock(LlmProviderInterface::class);

        $this->assertSame(ActionToolChannel::Markdown, $gate->channel($plain, $this->specs(), ['style' => 'friendly']));

        Log::shouldNotHaveReceived('warning');
    }

    /** @test */
    public function decide_returns_channel_and_specs_in_one_call(): void
    {
        config()->set('ai.actions.native_tools', true);
        $gate = new ActionToolGate($this->registry());

        [$channel, $specs] = $gate->decide(new ToolChannelCapableProvider(), $this->context(), ['style' => 'friendly']);

        $this->assertSame(ActionToolChannel::Native, $channel);
        $this->assertSame(
            ['view', 'Person__profile'],
            array_map(static fn (ActionToolSpec $spec) => $spec->name, $specs)
        );
    }

    /** @test */
    public function the_kill_switch_off_short_circuits_before_any_spec_construction(): void
    {
        $registry = new SpyActionRegistry(registerBuiltIns: false);
        $gate = new ActionToolGate($registry);

        [$channel, $specs] = $gate->decide(new ToolChannelCapableProvider(), $this->context(), ['style' => 'friendly']);

        $this->assertSame(ActionToolChannel::Markdown, $channel);
        $this->assertSame([], $specs);
        $this->assertSame(0, $registry->relevantActionsCalls, 'the registry walk must not be paid while native_tools is off');
    }

    /** @test */
    public function generate_never_consults_the_registry_while_the_kill_switch_is_off(): void
    {
        $registry = new SpyActionRegistry(registerBuiltIns: false);
        $provider = new ToolChannelCapableProvider();
        $generator = new ResponseGenerator(
            $provider,
            ['default_format' => 'text', 'default_style' => 'friendly'],
            new ActionToolGate($registry),
            new ToolLoopRunner(new ActionDispatcher($registry), 2, 5),
        );

        $generator->generate($this->context(), ['style' => 'friendly']);

        $this->assertSame(0, $registry->relevantActionsCalls, 'specs are built only after the cheap channel checks pass');
        $this->assertSame(1, $provider->completeCalls);
    }

    /** @test */
    public function the_tool_set_is_relevance_scoped_from_the_registry_seam(): void
    {
        $registry = new AiActionRegistry(registerBuiltIns: false);
        $registry->registerGeneric(new CapturingAction('view', static fn () => 'viewed'));
        $registry->registerEntity('Person', new CapturingAction('profile', static fn () => 'profile-link'));
        $registry->registerEntity('Unit', new CapturingAction('inspect', static fn () => 'inspected'));

        $gate = new ActionToolGate($registry);

        $context = new PipelineContext();
        $context->conversationContext = ['focused_entity' => 'Person'];
        $context->executionResult = new QueryExecutionResult(success: true, data: [
            ['id' => 1, 'entity_type' => 'Unit'],
        ]);

        $this->assertSame(
            ['view', 'Person__profile', 'Unit__inspect'],
            array_map(static fn (ActionToolSpec $spec) => $spec->name, $gate->specsFor($context))
        );

        // Knowledge/document turns: no entities in scope → generic tools alone.
        $this->assertSame(
            ['view'],
            array_map(static fn (ActionToolSpec $spec) => $spec->name, $gate->specsFor(new PipelineContext()))
        );
    }

    // =========================================================================
    // generate() wiring — native channel
    // =========================================================================

    /** @test */
    public function native_channel_runs_the_tool_loop_and_suppresses_the_link_grammar(): void
    {
        config()->set('ai.actions.native_tools', true);
        $provider = new ToolChannelCapableProvider([$this->textTurn('Native answer.')]);
        $generator = $this->generator($provider);

        $result = $generator->generate($this->context(), ['style' => 'friendly']);

        $this->assertSame('Native answer.', $result['answer']);
        $this->assertSame(0, $provider->completeCalls, 'native mode must not call complete()');
        $this->assertCount(1, $provider->tools->turns);

        $turn = $provider->tools->turns[0];
        $this->assertSame(
            ['view', 'Person__profile'],
            array_map(static fn (ActionToolSpec $spec) => $spec->name, $turn['toolSpecs'])
        );
        $this->assertSame(['temperature' => 0.3, 'max_tokens' => 667], $turn['options']);

        // The loop starts from the SAME role-split section prompt.
        $this->assertSame('system', $turn['messages'][0]['role']);
        $this->assertSame('user', $turn['messages'][1]['role']);
        $this->assertStringContainsString('How many invoices are overdue?', $turn['messages'][1]['content']);

        // No double-advertising: the entire markdown grammar block is suppressed.
        $this->assertStringNotContainsString('Action Links', $turn['messages'][1]['content']);
        $this->assertStringNotContainsString('entity://', $turn['messages'][1]['content']);

        $this->assertSame(
            ['channel' => 'native', 'rounds' => 1, 'tool_calls' => 0, 'degraded' => false],
            $result['metadata']['tool_calling']
        );
        $this->assertSame([], $result['metadata']['invocations']);
    }

    /** @test */
    public function the_native_system_prompt_forbids_writing_the_link_grammar(): void
    {
        config()->set('ai.actions.native_tools', true);
        $provider = new ToolChannelCapableProvider([$this->textTurn('Native answer.')]);
        $generator = $this->generator($provider);

        $generator->generate($this->context(), ['style' => 'friendly']);

        // The backup behind the render-side fingerprint dedupe: with real tools in hand the
        // model must never write the legacy link grammar.
        $this->assertStringContainsString(
            'Never write action:// or entity:// links',
            $provider->tools->turns[0]['messages'][0]['content']
        );
    }

    /** @test */
    public function native_invocations_ride_the_response_metadata_with_filtered_params(): void
    {
        config()->set('ai.actions.native_tools', true);
        $provider = new ToolChannelCapableProvider([
            $this->toolTurn([new LlmToolCall('c1', 'view', ['x' => 1, 'bogus' => 'y'])]),
            $this->textTurn('Done.'),
        ]);
        $generator = $this->generator($provider);
        $user = (object) ['name' => 'approver'];

        $result = $generator->generate($this->context(), ['style' => 'friendly', 'user' => $user]);

        $this->assertSame('Done.', $result['answer']);
        $this->assertSame(
            [['key' => 'view', 'entity_type' => null, 'entity_id' => null, 'params' => ['x' => 1], 'text' => '']],
            $result['metadata']['invocations']
        );
        $this->assertSame(2, $result['metadata']['tool_calling']['rounds']);
        $this->assertSame(1, $result['metadata']['tool_calling']['tool_calls']);

        // The dispatch context threads the acting user + result set into the action.
        $this->assertNotNull($this->capturedContext);
        $this->assertSame($user, $this->capturedContext->user);
        $this->assertSame([['count' => 7]], $this->capturedContext->resultSet);
    }

    /** @test */
    public function a_round_one_loop_failure_propagates_without_a_markdown_fallback_call(): void
    {
        config()->set('ai.actions.native_tools', true);
        $provider = new ToolChannelCapableProvider([new \RuntimeException('breaker open')]);
        $generator = $this->generator($provider);

        try {
            $generator->generate($this->context(), ['style' => 'friendly']);
            $this->fail('a loop failure with nothing accumulated must propagate');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('breaker open', $e->getMessage());
        }

        $this->assertSame(0, $provider->completeCalls, 'never one more LLM call against a possibly-open breaker');
    }

    // =========================================================================
    // generate() wiring — markdown fallback (the byte-identical golden lock)
    // =========================================================================

    /** @test */
    public function a_non_capable_provider_falls_back_to_a_byte_identical_prompt_and_a_single_complete_call(): void
    {
        config()->set('ai.actions.native_tools', true);
        Log::spy();

        $captured = null;
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('complete')
            ->once()
            ->andReturnUsing(static function ($user, $system = null, $options = []) use (&$captured) {
                $captured = [$user, $system, $options];
                return 'ok';
            });

        $generator = $this->generator($llm);
        $context = $this->context();
        $result = $generator->generate($context, ['style' => 'friendly']);

        // Golden: the pre-change options shape (no channel key) must produce the same bytes.
        ['system' => $goldSystem, 'user' => $goldUser] = $generator->buildPrompts($context, [
            'format' => 'text',
            'style' => 'friendly',
            'max_length' => 500,
        ]);

        $this->assertSame($goldUser, $captured[0]);
        $this->assertSame($goldSystem, $captured[1]);
        $this->assertSame(['temperature' => 0.3, 'max_tokens' => 667], $captured[2]);

        // The fallback still teaches the link grammar — and is never told to avoid it.
        $this->assertStringContainsString('Action Links', $captured[0]);
        $this->assertStringContainsString('entity://Person', $captured[0]);
        $this->assertStringNotContainsString('Never write action:// or entity:// links', (string) $captured[1]);

        $this->assertSame('ok', $result['answer']);
        $this->assertArrayNotHasKey('tool_calling', $result['metadata']);
        $this->assertArrayNotHasKey('invocations', $result['metadata']);
    }

    /** @test */
    public function the_kill_switch_off_keeps_markdown_even_for_a_capable_provider(): void
    {
        $provider = new ToolChannelCapableProvider();
        $generator = $this->generator($provider);
        $context = $this->context();

        $result = $generator->generate($context, ['style' => 'friendly']);

        $this->assertSame('completed-answer', $result['answer']);
        $this->assertSame(1, $provider->completeCalls);
        $this->assertSame([], $provider->tools->turns, 'no tool call may be attempted while the switch is off');

        ['system' => $goldSystem, 'user' => $goldUser] = $generator->buildPrompts($context, [
            'format' => 'text',
            'style' => 'friendly',
            'max_length' => 500,
        ]);
        $this->assertSame($goldUser, $provider->lastComplete[0]);
        $this->assertSame($goldSystem, $provider->lastComplete[1]);
    }

    /** @test */
    public function terse_styles_attach_no_tools_and_no_grammar(): void
    {
        config()->set('ai.actions.native_tools', true);
        $provider = new ToolChannelCapableProvider();
        $generator = $this->generator($provider);

        $generator->generate($this->context(), ['style' => 'minimal']);

        $this->assertSame(1, $provider->completeCalls);
        $this->assertSame([], $provider->tools->turns, 'channel None must never attach tools');
        $this->assertStringNotContainsString('Action Links', $provider->lastComplete[0]);
        $this->assertStringNotContainsString('Never write action:// or entity:// links', (string) $provider->lastComplete[1]);
    }

    // =========================================================================
    // Section gating
    // =========================================================================

    /** @test */
    public function the_entity_actions_section_is_gated_by_the_channel_option(): void
    {
        $section = new ResponseEntityActionsSection();
        $context = $this->context();

        // Absent channel = every legacy caller — included exactly as today.
        $this->assertTrue($section->shouldInclude($context, ['style' => 'friendly']));
        $this->assertTrue($section->shouldInclude($context, [
            'style' => 'friendly',
            ActionToolChannel::OPTION => ActionToolChannel::Markdown,
        ]));

        $this->assertFalse($section->shouldInclude($context, [
            'style' => 'friendly',
            ActionToolChannel::OPTION => ActionToolChannel::Native,
        ]));
        $this->assertFalse($section->shouldInclude($context, [
            'style' => 'friendly',
            ActionToolChannel::OPTION => ActionToolChannel::None,
        ]));
    }

    // =========================================================================
    // Abstention
    // =========================================================================

    /** @test */
    public function the_abstention_path_attaches_no_tools_and_keeps_the_markdown_hint(): void
    {
        config()->set('ai.actions.native_tools', true);
        $provider = new ToolChannelCapableProvider();
        $generator = $this->generator($provider);

        $result = $generator->generateEmptyResponse('book a reservation', null, []);

        $this->assertSame('completed-answer', $result['answer']);
        $this->assertSame(1, $provider->completeCalls);
        $this->assertSame([], $provider->tools->turns, 'abstention must never attach tools');

        // The existing markdown hint is unchanged — its mutating targets stay approval-gated —
        // and the native-only link prohibition must not leak into the abstention prompt.
        $this->assertStringContainsString('(action://open_modal)', $provider->lastComplete[0]);
        $this->assertStringNotContainsString('Never write action:// or entity:// links', (string) $provider->lastComplete[1]);
        $this->assertTrue($result['metadata']['empty_result']);
    }

    // =========================================================================
    // T11 — config keys + provider registration
    // =========================================================================

    /** @test */
    public function the_config_ships_native_off_with_the_documented_loop_caps(): void
    {
        $this->assertFalse((bool) config('ai.actions.native_tools'));
        $this->assertSame(2, (int) config('ai.actions.tool_rounds'));
        $this->assertSame(5, (int) config('ai.actions.max_tool_calls'));
    }

    /** @test */
    public function the_provider_registers_the_gate_and_loop_and_wires_them_into_the_generator(): void
    {
        $this->assertSame($this->app->make(ActionToolGate::class), $this->app->make(ActionToolGate::class));
        $this->assertSame($this->app->make(ToolLoopRunner::class), $this->app->make(ToolLoopRunner::class));

        $generator = $this->app->make(ResponseGeneratorInterface::class);
        $this->assertInstanceOf(ResponseGenerator::class, $generator);

        $gate = (new \ReflectionProperty(ResponseGenerator::class, 'toolGate'))->getValue($generator);
        $loop = (new \ReflectionProperty(ResponseGenerator::class, 'toolLoop'))->getValue($generator);
        $this->assertInstanceOf(ActionToolGate::class, $gate);
        $this->assertInstanceOf(ToolLoopRunner::class, $loop);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private ?ActionContext $capturedContext = null;

    private function registry(): AiActionRegistry
    {
        $registry = new AiActionRegistry(registerBuiltIns: false);
        $registry->registerGeneric(new CapturingAction('view', function (array $input, ActionContext $context) {
            $this->capturedContext = $context;
            return 'viewed';
        }, ['x' => ['type' => 'int']]));
        $registry->registerEntity('Person', new CapturingAction('profile', static fn () => 'profile-link'));

        return $registry;
    }

    /** @return ActionToolSpec[] */
    private function specs(): array
    {
        return [ActionToolSpec::fromAction(new CapturingAction('view', static fn () => 'viewed'))];
    }

    private function generator(LlmProviderInterface $llm): ResponseGenerator
    {
        $registry = $this->registry();

        return new ResponseGenerator(
            $llm,
            ['default_format' => 'text', 'default_style' => 'friendly'],
            new ActionToolGate($registry),
            new ToolLoopRunner(new ActionDispatcher($registry), 2, 5),
        );
    }

    private function context(): PipelineContext
    {
        $context = new PipelineContext();
        $context->question = 'How many invoices are overdue?';
        $context->queryResult = new QueryGenerationResult(cypher: 'MATCH (n) RETURN n');
        $context->executionResult = new QueryExecutionResult(success: true, data: [['count' => 7]]);
        $context->conversationContext = ['focused_entity' => 'Person'];

        return $context;
    }

    /** @param LlmToolCall[] $calls */
    private function toolTurn(array $calls): LlmToolTurn
    {
        return new LlmToolTurn(null, $calls, ['role' => 'assistant', 'calls' => count($calls)], 'tool_calls');
    }

    private function textTurn(string $text): LlmToolTurn
    {
        return new LlmToolTurn($text, [], ['role' => 'assistant'], 'stop');
    }
}

/**
 * Registry spy proving the gate's short-circuit: relevantActions() is the spec-construction
 * walk, which must never run when the cheap checks (kill switch, capability) already
 * decided against the native channel.
 */
class SpyActionRegistry extends AiActionRegistry
{
    public int $relevantActionsCalls = 0;

    public function relevantActions(?string $focusedEntity, array $resultTypes = []): array
    {
        $this->relevantActionsCalls++;

        return parent::relevantActions($focusedEntity, $resultTypes);
    }
}

/**
 * Test double that is BOTH a plain LlmProviderInterface and a tool-capable provider, so the
 * same instance can prove the channel decision: complete() traffic vs scripted chatWithTools
 * traffic (delegated to the shared ScriptedToolProvider stub). An empty script makes any
 * unexpected tool call fail loudly.
 */
class ToolChannelCapableProvider implements LlmProviderInterface, ToolCallingLlmInterface
{
    public int $completeCalls = 0;

    /** @var array{0: string, 1: ?string, 2: array}|null */
    public ?array $lastComplete = null;

    public ScriptedToolProvider $tools;

    /** @param array<int, LlmToolTurn|\Throwable> $script */
    public function __construct(array $script = [], private string $completion = 'completed-answer')
    {
        $this->tools = new ScriptedToolProvider($script);
    }

    public function chatWithTools(array $messages, array $toolSpecs, array $options = []): LlmToolTurn
    {
        return $this->tools->chatWithTools($messages, $toolSpecs, $options);
    }

    public function formatToolResults(LlmToolTurn $turn, array $results): array
    {
        return $this->tools->formatToolResults($turn, $results);
    }

    public function complete(string $prompt, ?string $systemPrompt = null, array $options = []): string
    {
        $this->completeCalls++;
        $this->lastComplete = [$prompt, $systemPrompt, $options];

        return $this->completion;
    }

    public function chat(array $messages, array $options = []): string
    {
        return $this->completion;
    }

    public function chatJson(array $messages, array $options = []): object|array
    {
        return [];
    }

    public function stream(array $messages, callable $callback, array $options = []): void
    {
    }

    public function getModel(): string
    {
        return 'stub-model';
    }

    public function getProvider(): string
    {
        return 'stub';
    }

    public function getMaxTokens(): int
    {
        return 4096;
    }

    public function countTokens(string $text): int
    {
        return str_word_count($text);
    }
}
