<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\LlmProviders;

use Condoedge\Ai\LlmProviders\AnthropicLlmProvider;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Anthropic plain-chat text extraction walks EVERY content[] block (never content[0]) —
 * a non-text block at index 0 must not throw, typeless legacy blocks still read as text,
 * and a response with no text block still throws.
 *
 * Offline on purpose: the capturing subclass feeds canned decoded responses — no HTTP,
 * no resilience machinery engaged.
 */
class AnthropicTextBlocksTest extends TestCase
{
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
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'render_table', 'input' => []],
            $this->textBlock('Voila.'),
        ], 'tool_use'));

        $this->assertSame('Voila.', $provider->chat([['role' => 'user', 'content' => 'salut']]));
    }

    /** @test */
    public function it_joins_multiple_text_blocks_in_api_order(): void
    {
        $provider = $this->provider($this->response([$this->textBlock('One.'), $this->textBlock('Two.')]));

        $this->assertSame("One.\nTwo.", $provider->chat([['role' => 'user', 'content' => 'salut']]));
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

    private function provider(array $cannedResponse = []): CapturingAnthropicProvider
    {
        $provider = new CapturingAnthropicProvider(['api_key' => 'sk-ant-test123']);
        $provider->cannedResponse = $cannedResponse;

        return $provider;
    }

    private function response(array $content, string $stopReason = 'end_turn'): array
    {
        return ['content' => $content, 'stop_reason' => $stopReason];
    }

    private function textBlock(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
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
