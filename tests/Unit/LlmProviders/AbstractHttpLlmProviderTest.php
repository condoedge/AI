<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\LlmProviders;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\LlmProviders\AbstractHttpLlmProvider;

/**
 * Unit tests for the shared AbstractHttpLlmProvider base.
 *
 * These tests exercise the provider-agnostic helpers that both concrete
 * providers inherit (JSON extraction from fenced/prose-wrapped text and
 * transient-HTTP classification used by the retry policy) without making
 * any network call. A tiny in-test subclass exposes the protected helpers.
 */
class AbstractHttpLlmProviderTest extends TestCase
{
    private function provider(): object
    {
        return new class(['api_key' => 'test-key']) extends AbstractHttpLlmProvider {
            protected function endpoint(): string
            {
                return 'https://example.test/v1/messages';
            }

            protected function authHeaders(): array
            {
                return ['x-api-key: test'];
            }

            protected function shapeRequest(array $messages, array $options): array
            {
                return ['messages' => $messages];
            }

            protected function extractTextResponse(array $response): string
            {
                return $response['text'] ?? '';
            }

            protected function parseStreamChunk(array $chunk): ?string
            {
                return $chunk['text'] ?? null;
            }

            protected function supportsNativeJsonMode(): bool
            {
                return false;
            }

            protected function defaultModel(): string
            {
                return 'test-model';
            }

            protected function maxTokensLimit(): int
            {
                return 100000;
            }

            protected function charsPerToken(): float
            {
                return 4.0;
            }

            public function getProvider(): string
            {
                return 'test';
            }

            // Expose protected helpers for testing.
            public function callExtractJson(string $text): string
            {
                return $this->extractJsonFromText($text);
            }

            public function callIsTransient(int $code): bool
            {
                return $this->isTransientStatus($code);
            }
        };
    }

    public function test_extracts_json_from_fenced_block(): void
    {
        $text = "```json\n{\"a\": 1, \"b\": \"x\"}\n```";

        $json = $this->provider()->callExtractJson($text);

        $this->assertSame(['a' => 1, 'b' => 'x'], json_decode($json, true));
    }

    public function test_extracts_json_from_generic_fence(): void
    {
        $text = "```\n{\"ok\": true}\n```";

        $json = $this->provider()->callExtractJson($text);

        $this->assertSame(['ok' => true], json_decode($json, true));
    }

    public function test_extracts_json_when_wrapped_in_prose(): void
    {
        $text = 'Here is your result: {"score": 0.9, "reason": "good"} hope that helps!';

        $json = $this->provider()->callExtractJson($text);

        $this->assertSame(['score' => 0.9, 'reason' => 'good'], json_decode($json, true));
    }

    public function test_extracts_balanced_brace_block_ignoring_nested(): void
    {
        $text = 'prefix {"outer": {"inner": [1,2,3]}, "n": 2} suffix';

        $json = $this->provider()->callExtractJson($text);

        $this->assertSame(
            ['outer' => ['inner' => [1, 2, 3]], 'n' => 2],
            json_decode($json, true)
        );
    }

    public function test_passes_through_bare_json(): void
    {
        $text = '{"plain": true}';

        $json = $this->provider()->callExtractJson($text);

        $this->assertSame(['plain' => true], json_decode($json, true));
    }

    public function test_does_not_split_on_brace_inside_string(): void
    {
        $text = '{"text": "a } closing brace inside a string", "n": 1}';

        $json = $this->provider()->callExtractJson($text);

        $this->assertSame(
            ['text' => 'a } closing brace inside a string', 'n' => 1],
            json_decode($json, true)
        );
    }

    public function test_transient_classification_covers_429_and_5xx(): void
    {
        $p = $this->provider();

        $this->assertTrue($p->callIsTransient(429));
        $this->assertTrue($p->callIsTransient(500));
        $this->assertTrue($p->callIsTransient(502));
        $this->assertTrue($p->callIsTransient(503));
        $this->assertTrue($p->callIsTransient(504));
    }

    public function test_non_transient_statuses_are_not_retried(): void
    {
        $p = $this->provider();

        $this->assertFalse($p->callIsTransient(400));
        $this->assertFalse($p->callIsTransient(401));
        $this->assertFalse($p->callIsTransient(404));
        $this->assertFalse($p->callIsTransient(422));
    }
}
