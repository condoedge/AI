<?php

declare(strict_types=1);

namespace AiSystem\Tests\Integration\LlmProviders;

use AiSystem\Tests\TestCase;
use AiSystem\LlmProviders\OpenAiLlmProvider;
use Exception;

/**
 * Integration Tests for OpenAiLlmProvider
 *
 * These tests make actual API calls to OpenAI.
 * They require a valid API key and will be skipped if not available.
 *
 * To run these tests, set the OPENAI_API_KEY environment variable:
 * export OPENAI_API_KEY="sk-your-key"
 * vendor/bin/phpunit tests/Integration/LlmProviders/OpenAiLlmProviderTest.php
 */
class OpenAiLlmProviderTest extends TestCase
{
    private ?OpenAiLlmProvider $provider = null;

    protected function setUp(): void
    {
        parent::setUp();

        $apiKey = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? null);

        if (!$apiKey || $apiKey === 'your-openai-api-key-here') {
            $this->markTestSkipped('OpenAI API key not configured. Set OPENAI_API_KEY environment variable to run integration tests.');
        }

        $this->provider = new OpenAiLlmProvider([
            'api_key' => $apiKey,
            'model' => 'gpt-4o',
            'temperature' => 0.3,
            'max_tokens' => 100, // Use low max for testing to save costs
        ]);
    }

    public function test_complete_returns_non_empty_string()
    {
        $response = $this->provider->complete('Say "Hello" in one word');

        $this->assertIsString($response);
        $this->assertNotEmpty($response);
        $this->assertStringContainsStringIgnoringCase('hello', $response);
    }

    public function test_chat_with_single_message_returns_response()
    {
        $messages = [
            ['role' => 'user', 'content' => 'What is 2+2? Answer with just the number.'],
        ];

        $response = $this->provider->chat($messages);

        $this->assertIsString($response);
        $this->assertNotEmpty($response);
        $this->assertStringContainsString('4', $response);
    }

    public function test_chat_with_conversation_history_works()
    {
        $messages = [
            ['role' => 'user', 'content' => 'My name is Alice.'],
            ['role' => 'assistant', 'content' => 'Hello Alice! Nice to meet you.'],
            ['role' => 'user', 'content' => 'What is my name?'],
        ];

        $response = $this->provider->chat($messages);

        $this->assertIsString($response);
        $this->assertNotEmpty($response);
        $this->assertStringContainsStringIgnoringCase('alice', $response);
    }

    public function test_chat_json_returns_valid_json()
    {
        $messages = [
            ['role' => 'user', 'content' => 'Return a JSON object with a "greeting" key that says "hello"'],
        ];

        $response = $this->provider->chatJson($messages);

        $this->assertTrue(is_object($response) || is_array($response));

        // Convert to array for easier testing
        $data = (array) $response;
        $this->assertArrayHasKey('greeting', $data);
    }

    public function test_stream_calls_callback_with_content_chunks()
    {
        $messages = [
            ['role' => 'user', 'content' => 'Count from 1 to 3 with spaces between numbers.'],
        ];

        $chunks = [];
        $this->provider->stream($messages, function($chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });

        $this->assertNotEmpty($chunks);
        $this->assertIsArray($chunks);

        // Verify we received multiple chunks (streaming)
        $this->assertGreaterThan(1, count($chunks));

        // Combine chunks to verify content
        $fullResponse = implode('', $chunks);
        $this->assertNotEmpty($fullResponse);
    }

    public function test_handles_api_error_for_invalid_key()
    {
        $provider = new OpenAiLlmProvider([
            'api_key' => 'sk-invalid-key-test',
            'model' => 'gpt-4o',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/authentication|invalid|api key/i');

        $provider->complete('Test prompt');
    }

    public function test_complete_with_system_prompt()
    {
        $response = $this->provider->complete(
            'What language should I speak?',
            'You are a helpful assistant that only speaks French.'
        );

        $this->assertIsString($response);
        $this->assertNotEmpty($response);
        // French response expected (though we won't be too strict on validation)
    }

    public function test_chat_with_custom_temperature()
    {
        $messages = [
            ['role' => 'user', 'content' => 'Say "test" in one word'],
        ];

        $response = $this->provider->chat($messages, ['temperature' => 0.1]);

        $this->assertIsString($response);
        $this->assertNotEmpty($response);
    }

    public function test_chat_with_custom_max_tokens()
    {
        $messages = [
            ['role' => 'user', 'content' => 'Tell me a long story'],
        ];

        $response = $this->provider->chat($messages, ['max_tokens' => 20]);

        $this->assertIsString($response);
        $this->assertNotEmpty($response);
        // Response should be short due to max_tokens limit
        $this->assertLessThan(150, strlen($response));
    }
}
