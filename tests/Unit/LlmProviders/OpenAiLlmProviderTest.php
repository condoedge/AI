<?php

declare(strict_types=1);

namespace AiSystem\Tests\Unit\LlmProviders;

use AiSystem\Tests\TestCase;
use AiSystem\LlmProviders\OpenAiLlmProvider;
use AiSystem\Contracts\LlmProviderInterface;
use Exception;

/**
 * Unit Tests for OpenAiLlmProvider
 *
 * These tests focus on configuration validation and metadata methods.
 * Actual API calls are NOT tested here (those belong in integration tests)
 * to avoid hitting the real OpenAI API which requires a key and costs money.
 */
class OpenAiLlmProviderTest extends TestCase
{
    public function test_constructor_accepts_valid_config()
    {
        $config = [
            'api_key' => 'sk-test123',
            'model' => 'gpt-4o',
            'temperature' => 0.3,
            'max_tokens' => 2000,
        ];

        $provider = new OpenAiLlmProvider($config);

        $this->assertInstanceOf(LlmProviderInterface::class, $provider);
        $this->assertInstanceOf(OpenAiLlmProvider::class, $provider);
    }

    public function test_constructor_accepts_minimal_config()
    {
        $config = [
            'api_key' => 'sk-test123',
        ];

        $provider = new OpenAiLlmProvider($config);

        $this->assertInstanceOf(OpenAiLlmProvider::class, $provider);
    }

    public function test_constructor_throws_exception_for_missing_api_key()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('OpenAI API key is required');

        new OpenAiLlmProvider([]);
    }

    public function test_constructor_throws_exception_for_empty_api_key()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('OpenAI API key is required');

        new OpenAiLlmProvider(['api_key' => '']);
    }

    public function test_constructor_throws_exception_for_invalid_api_key_type_integer()
    {
        // PHP will throw TypeError for type mismatch (int to string)
        $this->expectException(\TypeError::class);

        new OpenAiLlmProvider(['api_key' => 12345]);
    }

    public function test_constructor_throws_exception_for_null_api_key()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('OpenAI API key is required');

        new OpenAiLlmProvider(['api_key' => null]);
    }

    public function test_get_model_returns_default_value()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        $this->assertEquals('gpt-4o', $provider->getModel());
    }

    public function test_get_model_returns_custom_value()
    {
        $config = [
            'api_key' => 'sk-test123',
            'model' => 'gpt-4-turbo',
        ];
        $provider = new OpenAiLlmProvider($config);

        $this->assertEquals('gpt-4-turbo', $provider->getModel());
    }

    public function test_get_provider_returns_openai()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        $this->assertEquals('openai', $provider->getProvider());
    }

    public function test_get_max_tokens_returns_128000()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        $this->assertEquals(128000, $provider->getMaxTokens());
    }

    public function test_count_tokens_returns_approximate_count()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        // "Hello world" = 11 chars / 4 = ~3 tokens
        $count = $provider->countTokens('Hello world');

        $this->assertIsInt($count);
        $this->assertGreaterThan(0, $count);
        $this->assertEquals(3, $count); // 11 / 4 = 2.75, ceil = 3
    }

    public function test_count_tokens_handles_empty_string()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        $count = $provider->countTokens('');

        $this->assertIsInt($count);
        $this->assertEquals(0, $count);
    }

    public function test_count_tokens_handles_long_text()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        // Create a long text (1000 characters)
        $longText = str_repeat('a', 1000);
        $count = $provider->countTokens($longText);

        $this->assertIsInt($count);
        $this->assertEquals(250, $count); // 1000 / 4 = 250
    }

    public function test_chat_throws_exception_for_empty_messages_array()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        // Since OpenAI will fail on empty messages, we expect an exception
        // This test verifies input validation behavior
        $this->expectException(Exception::class);

        $provider->chat([]);
    }

    public function test_chat_json_throws_exception_for_empty_messages_array()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        $this->expectException(Exception::class);

        $provider->chatJson([]);
    }

    public function test_complete_throws_exception_for_empty_prompt()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        // Empty prompt will cause API error
        $this->expectException(Exception::class);

        $provider->complete('');
    }

    public function test_stream_throws_exception_for_empty_messages_array()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        $this->expectException(Exception::class);

        $provider->stream([], function($chunk) {
            // Callback that won't be called
        });
    }

    public function test_implements_llm_provider_interface()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        $reflection = new \ReflectionClass($provider);
        $interfaces = $reflection->getInterfaceNames();

        $this->assertContains(LlmProviderInterface::class, $interfaces);
    }

    public function test_get_model_returns_string()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        $this->assertIsString($provider->getModel());
    }

    public function test_get_provider_returns_string()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        $this->assertIsString($provider->getProvider());
    }

    public function test_get_max_tokens_returns_integer()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        $this->assertIsInt($provider->getMaxTokens());
    }

    public function test_count_tokens_returns_integer()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiLlmProvider($config);

        $count = $provider->countTokens('Test text');

        $this->assertIsInt($count);
    }

    public function test_custom_temperature_is_accepted()
    {
        $config = [
            'api_key' => 'sk-test123',
            'temperature' => 0.8,
        ];

        $provider = new OpenAiLlmProvider($config);

        // If constructor doesn't throw, temperature was accepted
        $this->assertInstanceOf(OpenAiLlmProvider::class, $provider);
    }

    public function test_custom_max_tokens_is_accepted()
    {
        $config = [
            'api_key' => 'sk-test123',
            'max_tokens' => 4000,
        ];

        $provider = new OpenAiLlmProvider($config);

        // If constructor doesn't throw, max_tokens was accepted
        $this->assertInstanceOf(OpenAiLlmProvider::class, $provider);
    }
}
