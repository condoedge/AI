<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\LlmProviders;

use Condoedge\Ai\Exceptions\LlmInvalidJsonException;
use Condoedge\Ai\LlmProviders\AnthropicLlmProvider;
use Condoedge\Ai\LlmProviders\OpenAiLlmProvider;
use PHPUnit\Framework\TestCase;

/**
 * Task 1.17 — chatJson must not swallow WHY a JSON reply failed to parse.
 * An invalid-JSON reply surfaces as LlmInvalidJsonException (still an
 * \Exception with the same message, so existing catch sites are unchanged)
 * carrying the raw reply text and whether the provider reported the reply
 * was cut at the output budget (OpenAI finish_reason=length, Anthropic
 * stop_reason=max_tokens). Offline: canned decoded responses, no HTTP.
 */
class ChatJsonTruncationTest extends TestCase
{
    private const CUT = '{"entity_types": ["Unit"], "search_query": "uni';

    /** @test */
    public function openai_length_finish_reason_marks_the_exception_truncated(): void
    {
        $provider = new CannedOpenAiProvider(['api_key' => 'k']);
        $provider->canned = ['choices' => [['message' => ['content' => self::CUT], 'finish_reason' => 'length']]];

        $e = $this->invalidJson(fn () => $provider->chatJson([['role' => 'user', 'content' => 'q']]));

        $this->assertTrue($e->truncated);
        $this->assertSame(self::CUT, $e->rawText);
        $this->assertStringContainsString('not valid JSON', $e->getMessage());
    }

    /** @test */
    public function openai_stop_finish_reason_is_not_truncated(): void
    {
        $provider = new CannedOpenAiProvider(['api_key' => 'k']);
        $provider->canned = ['choices' => [['message' => ['content' => 'not json at all'], 'finish_reason' => 'stop']]];

        $e = $this->invalidJson(fn () => $provider->chatJson([['role' => 'user', 'content' => 'q']]));

        $this->assertFalse($e->truncated);
    }

    /** @test */
    public function anthropic_max_tokens_stop_reason_marks_the_exception_truncated(): void
    {
        $provider = new CannedAnthropicProvider(['api_key' => 'k']);
        $provider->canned = ['content' => [['type' => 'text', 'text' => self::CUT]], 'stop_reason' => 'max_tokens'];

        $e = $this->invalidJson(fn () => $provider->chatJson([['role' => 'user', 'content' => 'q']]));

        $this->assertTrue($e->truncated);
    }

    /** @test */
    public function valid_json_is_unaffected(): void
    {
        $provider = new CannedOpenAiProvider(['api_key' => 'k']);
        $provider->canned = ['choices' => [['message' => ['content' => '{"a": 1}'], 'finish_reason' => 'stop']]];

        $this->assertSame(1, $provider->chatJson([['role' => 'user', 'content' => 'q']])->a);
    }

    private function invalidJson(callable $call): LlmInvalidJsonException
    {
        try {
            $call();
        } catch (LlmInvalidJsonException $e) {
            return $e;
        }

        $this->fail('expected LlmInvalidJsonException');
    }
}

final class CannedOpenAiProvider extends OpenAiLlmProvider
{
    public array $canned = [];

    protected function sendRequest(array $requestData, bool $streaming): array
    {
        return $this->canned;
    }
}

final class CannedAnthropicProvider extends AnthropicLlmProvider
{
    public array $canned = [];

    protected function sendRequest(array $requestData, bool $streaming): array
    {
        return $this->canned;
    }
}
