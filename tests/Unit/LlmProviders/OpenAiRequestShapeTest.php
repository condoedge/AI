<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\LlmProviders;

use Condoedge\Ai\LlmProviders\OpenAiLlmProvider;
use PHPUnit\Framework\TestCase;

/**
 * The chat completions request body: the output budget goes out as max_completion_tokens
 * (the GPT-5.x models refuse max_tokens), and a role's config can leave temperature out or
 * set reasoning_effort. Offline: the request is captured, never sent.
 */
class OpenAiRequestShapeTest extends TestCase
{
    private const MESSAGES = [['role' => 'user', 'content' => 'q']];

    /** @test */
    public function the_output_budget_is_sent_as_max_completion_tokens(): void
    {
        $provider = $this->provider(['max_tokens' => 900]);

        $provider->chat(self::MESSAGES);
        $provider->chat(self::MESSAGES, ['max_tokens' => 50]);

        $this->assertSame(900, $provider->requests[0]['max_completion_tokens']);
        $this->assertSame(50, $provider->requests[1]['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $provider->requests[0]);
        $this->assertArrayNotHasKey('max_tokens', $provider->requests[1]);
    }

    /** @test */
    public function an_unset_temperature_keeps_the_default_and_the_callers_value(): void
    {
        $provider = $this->provider([]);

        $provider->chat(self::MESSAGES);
        $provider->chat(self::MESSAGES, ['temperature' => 0.0]);

        $this->assertSame(0.3, $provider->requests[0]['temperature']);
        $this->assertSame(0.0, $provider->requests[1]['temperature']);
    }

    /** @test */
    public function a_null_temperature_sends_none_even_when_the_caller_passes_one(): void
    {
        $provider = $this->provider(['temperature' => null]);

        $provider->chat(self::MESSAGES);
        $provider->chatJson(self::MESSAGES, ['temperature' => 0.0, 'max_tokens' => 50]);

        $this->assertArrayNotHasKey('temperature', $provider->requests[0]);
        $this->assertArrayNotHasKey('temperature', $provider->requests[1]);
    }

    /** @test */
    public function reasoning_effort_is_sent_only_when_configured(): void
    {
        $plain = $this->provider([]);
        $plain->chat(self::MESSAGES);
        $this->assertArrayNotHasKey('reasoning_effort', $plain->requests[0]);

        $reasoning = $this->provider(['reasoning_effort' => 'none']);
        $reasoning->chat(self::MESSAGES);
        $reasoning->chatJson(self::MESSAGES);
        $this->assertSame('none', $reasoning->requests[0]['reasoning_effort']);
        $this->assertSame('none', $reasoning->requests[1]['reasoning_effort']);
    }

    /** @test The cap also counts hidden reasoning: a 300-token reply budget could be spent thinking, leaving an empty reply. */
    public function a_reasoning_role_gets_a_thinking_allowance_on_top_of_the_reply_budget(): void
    {
        $reasoning = $this->provider(['reasoning_effort' => 'low', 'max_tokens' => 900]);
        $reasoning->chat(self::MESSAGES);
        $reasoning->chatJson(self::MESSAGES, ['max_tokens' => 300]);
        $this->assertSame(900 + OpenAiLlmProvider::REASONING_ALLOWANCE, $reasoning->requests[0]['max_completion_tokens']);
        $this->assertSame(300 + OpenAiLlmProvider::REASONING_ALLOWANCE, $reasoning->requests[1]['max_completion_tokens']);

        $none = $this->provider(['reasoning_effort' => 'none']);
        $none->chat(self::MESSAGES, ['max_tokens' => 300]);
        $this->assertSame(300, $none->requests[0]['max_completion_tokens'], 'no reasoning, no allowance');
    }

    private function provider(array $config): CapturingOpenAi
    {
        $provider = new CapturingOpenAi(['api_key' => 'k'] + $config);
        $provider->canned = ['choices' => [['message' => ['content' => '{"a":1}'], 'finish_reason' => 'stop']]];

        return $provider;
    }
}

final class CapturingOpenAi extends OpenAiLlmProvider
{
    public array $canned = [];
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    protected function sendRequest(array $requestData, bool $streaming): array
    {
        $this->requests[] = $requestData;

        return $this->canned;
    }
}
