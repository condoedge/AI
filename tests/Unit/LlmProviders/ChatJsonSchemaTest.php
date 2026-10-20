<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\LlmProviders;

use Condoedge\Ai\Exceptions\LlmBadRequestException;
use Condoedge\Ai\LlmProviders\AnthropicLlmProvider;
use Condoedge\Ai\LlmProviders\OpenAiLlmProvider;
use Condoedge\Ai\LlmProviders\TransientLlmException;
use PHPUnit\Framework\TestCase;

/**
 * chatJson takes an OPTIONAL strict output schema: $options['json_schema'] =
 * ['name' => ..., 'schema' => <JSON Schema>]. A provider with structured outputs
 * (OpenAI: response_format json_schema, strict) sends it, so the reply holds EVERY
 * declared key with a value of its declared type — a routing key cannot be left out
 * or hold a value outside its enum. Every other provider ignores the option and
 * answers as before. Offline: canned decoded responses, no HTTP.
 */
class ChatJsonSchemaTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'navigation_target' => ['type' => ['string', 'null'], 'enum' => ['own_profile', 'own_team', null]],
            'digest_request' => ['type' => 'boolean'],
        ],
        'required' => ['navigation_target', 'digest_request'],
        'additionalProperties' => false,
    ];

    private const MESSAGES = [['role' => 'system', 'content' => 'Return JSON.'], ['role' => 'user', 'content' => 'q']];

    /** @test */
    public function openai_sends_the_schema_as_a_strict_structured_output(): void
    {
        $provider = $this->openAi();

        $reply = $provider->chatJson(self::MESSAGES, ['json_schema' => ['name' => 'understanding', 'schema' => self::SCHEMA], 'max_tokens' => 50]);

        $this->assertSame('own_team', $reply->navigation_target);
        $this->assertCount(1, $provider->requests);
        $this->assertSame(
            ['type' => 'json_schema', 'json_schema' => ['name' => 'understanding', 'strict' => true, 'schema' => self::SCHEMA]],
            $provider->requests[0]['response_format']
        );
        // The option is the package's, not a field of the provider's API.
        $this->assertArrayNotHasKey('json_schema', $provider->requests[0]);
        $this->assertSame(50, $provider->requests[0]['max_tokens']);
    }

    /** @test */
    public function openai_without_a_schema_keeps_plain_json_mode(): void
    {
        $provider = $this->openAi();

        $provider->chatJson(self::MESSAGES);
        $provider->chatJson(self::MESSAGES, ['json_schema' => ['name' => 'no schema here']]);
        $provider->chatJson(self::MESSAGES, ['json_schema' => 'not an array']);

        foreach ($provider->requests as $request) {
            $this->assertSame(['type' => 'json_object'], $request['response_format']);
            $this->assertArrayNotHasKey('json_schema', $request);
        }
    }

    /** @test */
    public function a_provider_without_structured_outputs_ignores_the_schema(): void
    {
        $provider = new SchemaCapturingAnthropic(['api_key' => 'k']);
        $provider->canned = ['content' => [['type' => 'text', 'text' => '{"navigation_target": null, "digest_request": true}']], 'stop_reason' => 'end_turn'];

        $reply = $provider->chatJson(self::MESSAGES, ['json_schema' => ['name' => 'understanding', 'schema' => self::SCHEMA]]);

        $this->assertTrue($reply->digest_request);
        $this->assertCount(1, $provider->requests);
        $this->assertArrayNotHasKey('response_format', $provider->requests[0]);
        $this->assertArrayNotHasKey('json_schema', $provider->requests[0]);
    }

    /**
     * @test A model that has no structured outputs answers the schema with a permanent 400. The
     * call is made again ONCE in plain JSON mode, and the provider stops sending schemas: the
     * turn is answered, and later calls do not pay the rejected request again.
     */
    public function a_rejected_schema_falls_back_to_plain_json_mode_and_is_not_sent_again(): void
    {
        $provider = $this->openAi();
        $provider->failures = [new LlmBadRequestException("OpenAI API bad request: 'response_format' of type 'json_schema' is not supported with this model.")];
        $options = ['json_schema' => ['name' => 'understanding', 'schema' => self::SCHEMA]];

        $reply = $provider->chatJson(self::MESSAGES, $options);

        $this->assertSame('own_team', $reply->navigation_target);
        $this->assertCount(2, $provider->requests);
        $this->assertSame('json_schema', $provider->requests[0]['response_format']['type']);
        $this->assertSame(['type' => 'json_object'], $provider->requests[1]['response_format']);

        $provider->chatJson(self::MESSAGES, $options);

        $this->assertCount(3, $provider->requests);
        $this->assertSame(['type' => 'json_object'], $provider->requests[2]['response_format']);
    }

    /** @test A failure that is not the provider refusing the request (an outage, a rate limit) is never answered by a second call. */
    public function a_transient_failure_with_a_schema_is_not_retried_without_it(): void
    {
        $provider = $this->openAi();
        $provider->failures = [new TransientLlmException('OpenAI API transient failure (HTTP 429): rate limit')];

        try {
            $provider->chatJson(self::MESSAGES, ['json_schema' => ['name' => 'understanding', 'schema' => self::SCHEMA]]);
            $this->fail('expected the transient failure');
        } catch (TransientLlmException $e) {
            $this->assertCount(1, $provider->requests);
        }

        // …and the schema is still sent afterwards.
        $provider->chatJson(self::MESSAGES, ['json_schema' => ['name' => 'understanding', 'schema' => self::SCHEMA]]);
        $this->assertSame('json_schema', $provider->requests[1]['response_format']['type']);
    }

    /** @test A bad request that the plain JSON call gets too is the request's own fault: it propagates, and schemas stay on. */
    public function a_bad_request_that_is_not_about_the_schema_propagates(): void
    {
        $provider = $this->openAi();
        $provider->failures = [
            new LlmBadRequestException('OpenAI API bad request: maximum context length exceeded'),
            new LlmBadRequestException('OpenAI API bad request: maximum context length exceeded'),
        ];
        $options = ['json_schema' => ['name' => 'understanding', 'schema' => self::SCHEMA]];

        try {
            $provider->chatJson(self::MESSAGES, $options);
            $this->fail('expected the bad request');
        } catch (LlmBadRequestException $e) {
            $this->assertStringContainsString('maximum context length', $e->getMessage());
        }

        $provider->chatJson(self::MESSAGES, $options);
        $this->assertSame('json_schema', end($provider->requests)['response_format']['type']);
    }

    /** @test The HTTP 400 of a provider is typed (still an \Exception, same message), so the fallback can tell it from an outage. */
    public function a_400_response_is_a_typed_bad_request(): void
    {
        $map = new \ReflectionMethod(OpenAiLlmProvider::class, 'handleErrorResponse');
        $map->setAccessible(true);

        try {
            $map->invoke($this->openAi(), 400, ['error' => ['message' => 'Invalid schema']]);
            $this->fail('expected a bad request');
        } catch (LlmBadRequestException $e) {
            $this->assertInstanceOf(\Exception::class, $e);
            $this->assertSame('OpenAI API bad request: Invalid schema', $e->getMessage());
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('OpenAI API authentication failed: Invalid API key');
        $map->invoke($this->openAi(), 401, []);
    }

    private function openAi(): SchemaCapturingOpenAi
    {
        $provider = new SchemaCapturingOpenAi(['api_key' => 'k']);
        $provider->canned = ['choices' => [['message' => ['content' => '{"navigation_target":"own_team","digest_request":false}'], 'finish_reason' => 'stop']]];

        return $provider;
    }
}

final class SchemaCapturingOpenAi extends OpenAiLlmProvider
{
    public array $canned = [];
    /** @var list<array<string, mixed>> */
    public array $requests = [];
    /** @var list<\Throwable> thrown in order, one per request, before the canned reply is served */
    public array $failures = [];

    protected function sendRequest(array $requestData, bool $streaming): array
    {
        $this->requests[] = $requestData;
        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        return $this->canned;
    }
}

final class SchemaCapturingAnthropic extends AnthropicLlmProvider
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
