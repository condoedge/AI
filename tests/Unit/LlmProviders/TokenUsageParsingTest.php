<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\LlmProviders;

use Condoedge\Ai\Contracts\ReportsTokenUsageInterface;
use Condoedge\Ai\LlmProviders\AnthropicLlmProvider;
use Condoedge\Ai\LlmProviders\OpenAiLlmProvider;
use Condoedge\Ai\Tests\TestCase;

/**
 * Phase 4.3 — providers parse the wire `usage` block into the normalized
 * ['input', 'output', 'total', 'model'] shape exposed by lastUsage(). Offline:
 * canned decoded responses (fixtures of the real wire shapes), no HTTP.
 */
class TokenUsageParsingTest extends TestCase
{
    /** @test */
    public function openai_usage_is_normalized_after_chat(): void
    {
        $provider = new UsageCannedOpenAiProvider(['api_key' => 'k', 'model' => 'gpt-4o-mini']);
        $provider->canned = [
            'model' => 'gpt-4o-mini-2024-07-18',
            'choices' => [['message' => ['content' => 'hello'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30, 'total_tokens' => 150],
        ];

        $this->assertInstanceOf(ReportsTokenUsageInterface::class, $provider);
        $this->assertNull($provider->lastUsage(), 'no call yet → no usage');

        $this->assertSame('hello', $provider->chat([['role' => 'user', 'content' => 'q']]));

        $this->assertSame(
            ['input' => 120, 'output' => 30, 'total' => 150, 'model' => 'gpt-4o-mini-2024-07-18'],
            $provider->lastUsage()
        );
    }

    /** @test */
    public function openai_usage_is_parsed_for_chat_json_and_complete(): void
    {
        $provider = new UsageCannedOpenAiProvider(['api_key' => 'k', 'model' => 'gpt-4o']);
        $provider->canned = [
            'choices' => [['message' => ['content' => '{"a": 1}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $provider->chatJson([['role' => 'user', 'content' => 'q']]);
        // No model echoed on the wire → falls back to the configured model.
        $this->assertSame(['input' => 10, 'output' => 5, 'total' => 15, 'model' => 'gpt-4o'], $provider->lastUsage());

        $provider->canned['usage'] = ['prompt_tokens' => 1, 'completion_tokens' => 2, 'total_tokens' => 3];
        $provider->complete('q', 'sys');
        $this->assertSame(3, $provider->lastUsage()['total']);
    }

    /** @test */
    public function anthropic_usage_is_normalized_with_a_computed_total(): void
    {
        $provider = new UsageCannedAnthropicProvider(['api_key' => 'k', 'model' => 'claude-x']);
        $provider->canned = [
            'model' => 'claude-x-20250101',
            'content' => [['type' => 'text', 'text' => 'bonjour']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 200, 'output_tokens' => 45],
        ];

        $this->assertSame('bonjour', $provider->chat([['role' => 'user', 'content' => 'q']]));
        $this->assertSame(
            ['input' => 200, 'output' => 45, 'total' => 245, 'model' => 'claude-x-20250101'],
            $provider->lastUsage()
        );
    }

    /** @test */
    public function a_response_without_usage_reports_null_and_never_keeps_a_stale_value(): void
    {
        $provider = new UsageCannedOpenAiProvider(['api_key' => 'k']);
        $provider->canned = [
            'choices' => [['message' => ['content' => 'a'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
        $provider->chat([['role' => 'user', 'content' => 'q']]);
        $this->assertNotNull($provider->lastUsage());

        unset($provider->canned['usage']);
        $provider->chat([['role' => 'user', 'content' => 'q']]);
        $this->assertNull($provider->lastUsage());
    }

    /** @test */
    public function a_failed_call_clears_the_previous_usage(): void
    {
        $provider = new UsageCannedOpenAiProvider(['api_key' => 'k']);
        $provider->canned = [
            'choices' => [['message' => ['content' => 'a'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
        $provider->chat([['role' => 'user', 'content' => 'q']]);

        $provider->fail = true;
        try {
            $provider->chat([['role' => 'user', 'content' => 'q']]);
            $this->fail('expected the canned failure');
        } catch (\RuntimeException) {
        }

        $this->assertNull($provider->lastUsage());
    }

    /** @test */
    public function a_call_that_fails_while_shaping_the_request_clears_the_previous_usage(): void
    {
        $provider = new UsageCannedAnthropicProvider(['api_key' => 'k']);
        $provider->canned = [
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 4],
        ];
        $provider->chat([['role' => 'user', 'content' => 'q']]);
        $this->assertNotNull($provider->lastUsage());

        // Anthropic's shapeRequest() throws when the first non-system message is not a user turn.
        foreach ([
            fn () => $provider->chat([['role' => 'assistant', 'content' => 'x']]),
            fn () => $provider->chatJson([['role' => 'assistant', 'content' => 'x']]),
        ] as $i => $call) {
            $provider->chat([['role' => 'user', 'content' => 'q']]);
            try {
                $call();
                $this->fail("call {$i} should have thrown");
            } catch (\Exception $e) {
                $this->assertStringContainsString('first message to be user role', $e->getMessage());
            }
            $this->assertNull($provider->lastUsage(), "call {$i} left a stale usage");
        }
    }

    /** @test */
    public function per_role_providers_from_the_resolver_all_report_usage(): void
    {
        $default = new UsageCannedOpenAiProvider(['api_key' => 'k']);
        $resolver = new \Condoedge\Ai\Services\LlmResolver($default, [
            'passage_selector' => ['provider' => 'anthropic', 'api_key' => 'k', 'model' => 'claude-x'],
            'query_generation' => ['provider' => 'openai', 'api_key' => 'k', 'model' => 'gpt-x'],
        ]);

        foreach (['passage_selector', 'query_generation', 'response_generation', 'query_understanding'] as $role) {
            $this->assertInstanceOf(
                ReportsTokenUsageInterface::class,
                $resolver->forRole($role),
                "role {$role} must not hide usage behind a non-reporting wrapper"
            );
        }
    }

    /** @test */
    public function streaming_reports_no_usage(): void
    {
        $provider = new UsageCannedOpenAiProvider(['api_key' => 'k']);
        $provider->canned = [
            'choices' => [['message' => ['content' => 'a'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
        $provider->chat([['role' => 'user', 'content' => 'q']]);

        $provider->stream([['role' => 'user', 'content' => 'q']], static fn () => null);

        $this->assertNull($provider->lastUsage());
    }
}

final class UsageCannedOpenAiProvider extends OpenAiLlmProvider
{
    public array $canned = [];
    public bool $fail = false;

    protected function sendRequest(array $requestData, bool $streaming): array
    {
        if ($this->fail) {
            throw new \RuntimeException('canned failure');
        }

        return $this->canned;
    }

    protected function sendStreamingRequest(array $requestData, callable $callback): void
    {
        $callback('chunk');
    }
}

final class UsageCannedAnthropicProvider extends AnthropicLlmProvider
{
    public array $canned = [];

    protected function sendRequest(array $requestData, bool $streaming): array
    {
        return $this->canned;
    }
}
