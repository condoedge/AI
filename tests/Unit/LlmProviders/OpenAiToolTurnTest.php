<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\LlmProviders;

use Condoedge\Ai\Contracts\ToolCallingLlmInterface;
use Condoedge\Ai\DTOs\LlmToolTurn;
use Condoedge\Ai\LlmProviders\AbstractHttpLlmProvider;
use Condoedge\Ai\LlmProviders\OpenAiLlmProvider;
use Condoedge\Ai\Services\Actions\ActionToolSpec;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportExcelAction;
use Condoedge\Ai\Services\Actions\BuiltIn\RenderTableAction;
use PHPUnit\Framework\TestCase;

/**
 * OpenAI native tool turns (P1-C1 / T3): tools/tool_choice ride the shaped request the same
 * way response_format does; extraction reads choices[0].message.tool_calls and TOLERATES
 * content:null — a tool-calls-only response would die in extractTextResponse()'s missing-content
 * throw, which the tool path deliberately bypasses. Malformed `arguments` JSON becomes
 * argsError, never an exception, so a bad call turns into a corrective tool_result for the
 * model instead of a failed turn.
 *
 * Offline on purpose: the capturing subclass pins the exact wire request and feeds canned
 * decoded responses — no HTTP, no resilience machinery engaged.
 */
class OpenAiToolTurnTest extends TestCase
{
    /** @test */
    public function it_is_the_capability_check_itself_via_instanceof(): void
    {
        $this->assertInstanceOf(ToolCallingLlmInterface::class, $this->provider());

        // The interface lands on CONCRETE providers only — a future tool-less provider
        // extending the shared base must not falsely claim support.
        $this->assertNotContains(
            ToolCallingLlmInterface::class,
            class_implements(AbstractHttpLlmProvider::class)
        );
    }

    /** @test */
    public function it_passes_tools_and_tool_choice_through_the_shaped_request(): void
    {
        $provider = $this->provider($this->textResponse('ok'));
        $specs = $this->specs();

        $provider->chatWithTools(
            [['role' => 'user', 'content' => 'show me a table']],
            $specs,
            ['tool_choice' => 'auto']
        );

        $request = $provider->lastRequest;

        $this->assertSame('auto', $request['tool_choice']);
        $this->assertCount(2, $request['tools']);

        $this->assertSame('function', $request['tools'][0]['type']);
        $this->assertSame($specs[0]->name, $request['tools'][0]['function']['name']);
        $this->assertSame($specs[0]->description, $request['tools'][0]['function']['description']);
        $this->assertEquals($specs[0]->parameters, $request['tools'][0]['function']['parameters']);
        $this->assertSame($specs[1]->name, $request['tools'][1]['function']['name']);

        // The base request shape is untouched around the new keys.
        $this->assertSame('gpt-4o', $request['model']);
        $this->assertSame([['role' => 'user', 'content' => 'show me a table']], $request['messages']);
        $this->assertArrayHasKey('temperature', $request);
        $this->assertArrayHasKey('max_tokens', $request);
    }

    /** @test */
    public function it_omits_the_tools_key_for_an_empty_spec_set(): void
    {
        $provider = $this->provider($this->textResponse('ok'));

        $provider->chatWithTools([['role' => 'user', 'content' => 'hi']], []);

        $this->assertArrayNotHasKey('tools', $provider->lastRequest);
        $this->assertArrayNotHasKey('tool_choice', $provider->lastRequest);
    }

    /** @test */
    public function it_extracts_a_text_only_turn(): void
    {
        $provider = $this->provider($this->textResponse('The answer.'));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        $this->assertInstanceOf(LlmToolTurn::class, $turn);
        $this->assertSame('The answer.', $turn->text);
        $this->assertSame([], $turn->toolCalls);
        $this->assertSame('stop', $turn->stopReason);
    }

    /** @test */
    public function it_tolerates_null_content_on_a_tool_calls_only_turn(): void
    {
        $provider = $this->provider($this->toolCallResponse([
            $this->rawCall('call_1', 'render_table', '{"limit":10}'),
        ]));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        $this->assertNull($turn->text);
        $this->assertCount(1, $turn->toolCalls);
        $this->assertSame('call_1', $turn->toolCalls[0]->id);
        $this->assertSame('render_table', $turn->toolCalls[0]->name);
        $this->assertSame(['limit' => 10], $turn->toolCalls[0]->args);
        $this->assertNull($turn->toolCalls[0]->argsError);
        $this->assertSame('tool_calls', $turn->stopReason);
    }

    /** @test */
    public function it_extracts_text_alongside_tool_calls(): void
    {
        $provider = $this->provider($this->toolCallResponse(
            [$this->rawCall('call_1', 'export_excel', '{}')],
            'Let me export that for you.'
        ));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        $this->assertSame('Let me export that for you.', $turn->text);
        $this->assertCount(1, $turn->toolCalls);
        $this->assertSame([], $turn->toolCalls[0]->args);
        $this->assertNull($turn->toolCalls[0]->argsError);
    }

    /** @test */
    public function it_preserves_parallel_tool_calls_in_order(): void
    {
        $provider = $this->provider($this->toolCallResponse([
            $this->rawCall('call_1', 'render_table', '{"a":1}'),
            $this->rawCall('call_2', 'export_pdf', '{"b":"x"}'),
        ]));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        $this->assertSame(['call_1', 'call_2'], array_map(fn ($c) => $c->id, $turn->toolCalls));
        $this->assertSame(['render_table', 'export_pdf'], array_map(fn ($c) => $c->name, $turn->toolCalls));
        $this->assertSame(['a' => 1], $turn->toolCalls[0]->args);
        $this->assertSame(['b' => 'x'], $turn->toolCalls[1]->args);
    }

    /** @test */
    public function it_reports_malformed_arguments_json_as_args_error_without_throwing(): void
    {
        $provider = $this->provider($this->toolCallResponse([
            $this->rawCall('call_1', 'render_table', '{"limit": 10'),
        ]));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        $call = $turn->toolCalls[0];
        $this->assertSame('render_table', $call->name);
        $this->assertSame([], $call->args);
        $this->assertNotNull($call->argsError);
    }

    /** @test */
    public function it_reports_non_object_arguments_as_args_error(): void
    {
        $provider = $this->provider($this->toolCallResponse([
            $this->rawCall('call_1', 'render_table', '"not an object"'),
        ]));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        $this->assertSame([], $turn->toolCalls[0]->args);
        $this->assertNotNull($turn->toolCalls[0]->argsError);
    }

    /** @test */
    public function it_reports_json_list_arguments_as_args_error(): void
    {
        $provider = $this->provider($this->toolCallResponse([
            $this->rawCall('call_1', 'render_table', '[1,2]'),
        ]));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        // Valid JSON, but a LIST is not an args object — same corrective path as a scalar.
        $this->assertSame([], $turn->toolCalls[0]->args);
        $this->assertSame('Tool arguments must be a JSON object', $turn->toolCalls[0]->argsError);
    }

    /** @test */
    public function it_accepts_an_empty_json_array_as_a_zero_arg_call(): void
    {
        $provider = $this->provider($this->toolCallResponse([
            $this->rawCall('call_1', 'export_excel', '[]'),
        ]));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        // [] decodes ambiguously (empty object and empty list are the same) and means no args.
        $this->assertSame([], $turn->toolCalls[0]->args);
        $this->assertNull($turn->toolCalls[0]->argsError);
    }

    /** @test */
    public function it_treats_empty_arguments_as_a_zero_arg_call(): void
    {
        $provider = $this->provider($this->toolCallResponse([
            $this->rawCall('call_1', 'export_excel', ''),
        ]));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        $this->assertSame([], $turn->toolCalls[0]->args);
        $this->assertNull($turn->toolCalls[0]->argsError);
    }

    /** @test */
    public function it_formats_tool_results_as_the_assistant_echo_then_tool_messages(): void
    {
        $provider = $this->provider($this->toolCallResponse([
            $this->rawCall('call_1', 'render_table', '{"limit":5}'),
            $this->rawCall('call_2', 'export_excel', '{}'),
        ]));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        $messages = $provider->formatToolResults($turn, [
            'call_1' => 'Table rendered: 5 rows.',
            'call_2' => ['content' => 'You are not authorized to export.', 'is_error' => true],
        ]);

        $this->assertCount(3, $messages);

        // The assistant message is echoed VERBATIM — OpenAI requires the original
        // tool_calls entry to precede its role:tool results.
        $this->assertSame($turn->rawAssistantMessage, $messages[0]);

        $this->assertSame(
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Table rendered: 5 rows.'],
            $messages[1]
        );
        $this->assertSame(
            ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => 'You are not authorized to export.'],
            $messages[2]
        );
    }

    /** @test */
    public function it_passes_formatted_history_back_through_the_shaped_request_unchanged(): void
    {
        $provider = $this->provider($this->toolCallResponse([
            $this->rawCall('call_1', 'render_table', '{}'),
        ]));

        $history = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'q'],
        ];

        $turn = $provider->chatWithTools($history, $this->specs());
        $appended = $provider->formatToolResults($turn, ['call_1' => 'done']);

        $provider->cannedResponse = $this->textResponse('All set.');
        $next = $provider->chatWithTools(array_merge($history, $appended), $this->specs());

        $this->assertSame(array_merge($history, $appended), $provider->lastRequest['messages']);
        $this->assertSame('All set.', $next->text);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function provider(array $cannedResponse = []): CapturingOpenAiProvider
    {
        $provider = new CapturingOpenAiProvider(['api_key' => 'sk-test123']);
        $provider->cannedResponse = $cannedResponse;

        return $provider;
    }

    /** @return ActionToolSpec[] */
    private function specs(): array
    {
        return [
            ActionToolSpec::fromAction(new RenderTableAction()),
            ActionToolSpec::fromAction(new ExportExcelAction()),
        ];
    }

    private function textResponse(string $text, string $finishReason = 'stop'): array
    {
        return [
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => $text],
                'finish_reason' => $finishReason,
            ]],
        ];
    }

    private function toolCallResponse(array $toolCalls, ?string $content = null, string $finishReason = 'tool_calls'): array
    {
        return [
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => $content, 'tool_calls' => $toolCalls],
                'finish_reason' => $finishReason,
            ]],
        ];
    }

    private function rawCall(string $id, string $name, string $arguments): array
    {
        return ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $arguments]];
    }
}

final class CapturingOpenAiProvider extends OpenAiLlmProvider
{
    public array $lastRequest = [];
    public array $cannedResponse = [];

    protected function sendRequest(array $requestData, bool $streaming): array
    {
        $this->lastRequest = $requestData;

        return $this->cannedResponse;
    }
}
