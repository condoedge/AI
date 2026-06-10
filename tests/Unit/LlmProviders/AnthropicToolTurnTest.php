<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\LlmProviders;

use Condoedge\Ai\Contracts\ToolCallingLlmInterface;
use Condoedge\Ai\LlmProviders\AnthropicLlmProvider;
use Condoedge\Ai\Services\Actions\ActionToolSpec;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportExcelAction;
use Condoedge\Ai\Services\Actions\BuiltIn\RenderTableAction;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Anthropic native tool turns (P1-C1 / T4): tools ride the shaped request as input_schema
 * definitions with system hoisting preserved; extraction walks EVERY content[] block —
 * collecting text + tool_use wherever they appear — which also fixes the latent
 * content[0]['text'] fragility for plain chat (a tool_use block at index 0 used to throw).
 * Tool results go back as tool_result blocks inside a USER message, Anthropic's required
 * shape; stop_reason is mapped to the shared cross-provider vocabulary.
 *
 * Offline on purpose: the capturing subclass pins the exact wire request and feeds canned
 * decoded responses — no HTTP, no resilience machinery engaged.
 */
class AnthropicToolTurnTest extends TestCase
{
    /** @test */
    public function it_is_the_capability_check_itself_via_instanceof(): void
    {
        $this->assertInstanceOf(ToolCallingLlmInterface::class, $this->provider());
    }

    /** @test */
    public function it_shapes_tools_with_input_schema(): void
    {
        $provider = $this->provider($this->response([$this->textBlock('ok')]));
        $specs = $this->specs();

        $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $specs);

        $tools = $provider->lastRequest['tools'];

        $this->assertCount(2, $tools);
        $this->assertSame($specs[0]->name, $tools[0]['name']);
        $this->assertSame($specs[0]->description, $tools[0]['description']);
        $this->assertEquals($specs[0]->parameters, $tools[0]['input_schema']);
        $this->assertSame($specs[1]->name, $tools[1]['name']);

        // Anthropic tool definitions are flat — no OpenAI 'function' wrapper, no 'parameters' key.
        $this->assertArrayNotHasKey('parameters', $tools[0]);
        $this->assertArrayNotHasKey('function', $tools[0]);
    }

    /** @test */
    public function it_omits_the_tools_key_for_an_empty_spec_set(): void
    {
        $provider = $this->provider($this->response([$this->textBlock('ok')]));

        $provider->chatWithTools([['role' => 'user', 'content' => 'hi']], []);

        $this->assertArrayNotHasKey('tools', $provider->lastRequest);
    }

    /** @test */
    public function it_preserves_system_hoisting_when_tools_are_attached(): void
    {
        $provider = $this->provider($this->response([$this->textBlock('ok')]));

        $provider->chatWithTools([
            ['role' => 'system', 'content' => 'Be terse.'],
            ['role' => 'user', 'content' => 'q'],
        ], $this->specs());

        $this->assertSame('Be terse.', $provider->lastRequest['system']);
        $this->assertSame([['role' => 'user', 'content' => 'q']], $provider->lastRequest['messages']);
        $this->assertArrayHasKey('tools', $provider->lastRequest);
    }

    /** @test */
    public function it_walks_every_content_block_for_text_and_tool_use(): void
    {
        $provider = $this->provider($this->response([
            $this->textBlock('Checking your reservations.'),
            $this->toolUseBlock('toolu_1', 'render_table', ['limit' => 5]),
        ], 'tool_use'));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        $this->assertSame('Checking your reservations.', $turn->text);
        $this->assertCount(1, $turn->toolCalls);
        $this->assertSame('toolu_1', $turn->toolCalls[0]->id);
        $this->assertSame('render_table', $turn->toolCalls[0]->name);
        $this->assertSame(['limit' => 5], $turn->toolCalls[0]->args);
        $this->assertNull($turn->toolCalls[0]->argsError);
        $this->assertSame('tool_calls', $turn->stopReason);
    }

    /** @test */
    public function it_extracts_a_tool_use_first_response(): void
    {
        // content[0] is NOT text — exactly the shape the old content[0]['text'] read threw on.
        $provider = $this->provider($this->response([
            $this->toolUseBlock('toolu_1', 'export_excel', []),
            $this->textBlock('Exporting now.'),
        ], 'tool_use'));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        $this->assertSame('Exporting now.', $turn->text);
        $this->assertSame('export_excel', $turn->toolCalls[0]->name);
        $this->assertSame([], $turn->toolCalls[0]->args);
    }

    /** @test */
    public function it_returns_null_text_for_a_tool_use_only_turn_and_keeps_call_order(): void
    {
        $provider = $this->provider($this->response([
            $this->toolUseBlock('toolu_1', 'render_table', ['a' => 1]),
            $this->toolUseBlock('toolu_2', 'export_pdf', ['b' => 'x']),
        ], 'tool_use'));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        $this->assertNull($turn->text);
        $this->assertSame(['toolu_1', 'toolu_2'], array_map(fn ($c) => $c->id, $turn->toolCalls));
        $this->assertSame(['a' => 1], $turn->toolCalls[0]->args);
        $this->assertSame(['b' => 'x'], $turn->toolCalls[1]->args);
    }

    /** @test */
    public function it_joins_multiple_text_blocks_in_api_order(): void
    {
        $provider = $this->provider($this->response([
            $this->textBlock('First.'),
            $this->toolUseBlock('toolu_1', 'render_table', []),
            $this->textBlock('Second.'),
        ], 'tool_use'));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        $this->assertSame("First.\nSecond.", $turn->text);
    }

    /** @test */
    public function it_maps_stop_reasons_to_the_shared_vocabulary(): void
    {
        $cases = [
            'end_turn' => 'stop',
            'stop_sequence' => 'stop',
            'tool_use' => 'tool_calls',
            'max_tokens' => 'length',
            'something_new' => 'something_new',
        ];

        foreach ($cases as $raw => $expected) {
            $provider = $this->provider($this->response([$this->textBlock('x')], $raw));

            $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

            $this->assertSame($expected, $turn->stopReason, "stop_reason {$raw}");
        }
    }

    /** @test */
    public function it_formats_tool_results_as_tool_result_blocks_in_a_user_message(): void
    {
        $provider = $this->provider($this->response([
            $this->toolUseBlock('toolu_1', 'render_table', []),
            $this->toolUseBlock('toolu_2', 'export_excel', []),
        ], 'tool_use'));

        $turn = $provider->chatWithTools([['role' => 'user', 'content' => 'q']], $this->specs());

        $messages = $provider->formatToolResults($turn, [
            'toolu_1' => 'Table rendered.',
            'toolu_2' => ['content' => 'Not authorized.', 'is_error' => true],
        ]);

        $this->assertCount(2, $messages);

        // The assistant turn is echoed first, content blocks intact.
        $this->assertSame($turn->rawAssistantMessage, $messages[0]);
        $this->assertSame('assistant', $messages[0]['role']);

        // tool_result blocks MUST ride a user message — Anthropic's required shape.
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame([
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => 'Table rendered.'],
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_2', 'content' => 'Not authorized.', 'is_error' => true],
        ], $messages[1]['content']);
    }

    /** @test */
    public function it_passes_formatted_history_back_through_the_shaped_request_unchanged(): void
    {
        $provider = $this->provider($this->response([
            $this->toolUseBlock('toolu_1', 'render_table', []),
        ], 'tool_use'));

        $history = [
            ['role' => 'system', 'content' => 'Be helpful.'],
            ['role' => 'user', 'content' => 'q'],
        ];

        $turn = $provider->chatWithTools($history, $this->specs());
        $appended = $provider->formatToolResults($turn, ['toolu_1' => 'done']);

        $provider->cannedResponse = $this->response([$this->textBlock('All set.')]);
        $next = $provider->chatWithTools(array_merge($history, $appended), $this->specs());

        // System hoisted; the opaque assistant echo + tool_result user message untouched.
        $this->assertSame('Be helpful.', $provider->lastRequest['system']);
        $this->assertSame(
            array_merge([['role' => 'user', 'content' => 'q']], $appended),
            $provider->lastRequest['messages']
        );
        $this->assertSame('All set.', $next->text);
    }

    // =========================================================================
    // Plain-chat regression — guards the extractTextResponse() rewrite
    // =========================================================================

    /** @test */
    public function it_still_extracts_plain_text_only_responses_through_chat(): void
    {
        $provider = $this->provider($this->response([$this->textBlock('Bonjour.')]));

        $this->assertSame('Bonjour.', $provider->chat([['role' => 'user', 'content' => 'salut']]));
    }

    /** @test */
    public function it_extracts_plain_chat_text_even_when_a_non_text_block_comes_first(): void
    {
        $provider = $this->provider($this->response([
            $this->toolUseBlock('toolu_1', 'render_table', []),
            $this->textBlock('Voila.'),
        ], 'tool_use'));

        $this->assertSame('Voila.', $provider->chat([['role' => 'user', 'content' => 'salut']]));
    }

    /** @test */
    public function it_tolerates_legacy_blocks_without_a_type_field(): void
    {
        // The pre-rewrite reader never checked 'type'; canned fixtures shaped that way must keep working.
        $provider = $this->provider(['content' => [['text' => 'Hello']]]);

        $this->assertSame('Hello', $provider->chat([['role' => 'user', 'content' => 'hi']]));
    }

    /** @test */
    public function it_still_throws_when_no_text_block_exists_in_plain_chat(): void
    {
        $provider = $this->provider(['content' => [], 'stop_reason' => 'end_turn']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Anthropic API response missing content text field');

        $provider->chat([['role' => 'user', 'content' => 'salut']]);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function provider(array $cannedResponse = []): CapturingAnthropicProvider
    {
        $provider = new CapturingAnthropicProvider(['api_key' => 'sk-ant-test123']);
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

    private function response(array $content, string $stopReason = 'end_turn'): array
    {
        return ['content' => $content, 'stop_reason' => $stopReason];
    }

    private function textBlock(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    private function toolUseBlock(string $id, string $name, array $input): array
    {
        return ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];
    }
}

final class CapturingAnthropicProvider extends AnthropicLlmProvider
{
    public array $lastRequest = [];
    public array $cannedResponse = [];

    protected function sendRequest(array $requestData, bool $streaming): array
    {
        $this->lastRequest = $requestData;

        return $this->cannedResponse;
    }
}
