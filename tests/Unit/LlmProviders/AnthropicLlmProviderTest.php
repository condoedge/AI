<?php

declare(strict_types=1);

namespace AiSystem\Tests\Unit\LlmProviders;

use AiSystem\Tests\TestCase;
use AiSystem\LlmProviders\AnthropicLlmProvider;
use AiSystem\Contracts\LlmProviderInterface;
use Exception;

/**
 * Unit Tests for AnthropicLlmProvider
 *
 * These tests focus on configuration validation and metadata methods.
 * Actual API calls are NOT tested here (those belong in integration tests)
 * to avoid hitting the real Anthropic API which requires a key and costs money.
 */
class AnthropicLlmProviderTest extends TestCase
{
    public function test_constructor_accepts_valid_config()
    {
        $config = [
            'api_key' => 'sk-ant-test123',
            'model' => 'claude-3-5-sonnet-20241022',
            'temperature' => 0.3,
            'max_tokens' => 2000,
        ];

        $provider = new AnthropicLlmProvider($config);

        $this->assertInstanceOf(LlmProviderInterface::class, $provider);
        $this->assertInstanceOf(AnthropicLlmProvider::class, $provider);
    }

    public function test_constructor_accepts_minimal_config()
    {
        $config = [
            'api_key' => 'sk-ant-test123',
        ];

        $provider = new AnthropicLlmProvider($config);

        $this->assertInstanceOf(AnthropicLlmProvider::class, $provider);
    }

    public function test_constructor_throws_exception_for_missing_api_key()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Anthropic API key is required');

        new AnthropicLlmProvider([]);
    }

    public function test_constructor_throws_exception_for_empty_api_key()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Anthropic API key is required');

        new AnthropicLlmProvider(['api_key' => '']);
    }

    public function test_constructor_throws_exception_for_invalid_api_key_type_integer()
    {
        // PHP will throw TypeError for type mismatch (int to string)
        $this->expectException(\TypeError::class);

        new AnthropicLlmProvider(['api_key' => 12345]);
    }

    public function test_constructor_throws_exception_for_null_api_key()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Anthropic API key is required');

        new AnthropicLlmProvider(['api_key' => null]);
    }

    public function test_get_model_returns_default_value()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        $this->assertEquals('claude-3-5-sonnet-20241022', $provider->getModel());
    }

    public function test_get_model_returns_custom_value()
    {
        $config = [
            'api_key' => 'sk-ant-test123',
            'model' => 'claude-3-opus-20240229',
        ];
        $provider = new AnthropicLlmProvider($config);

        $this->assertEquals('claude-3-opus-20240229', $provider->getModel());
    }

    public function test_get_provider_returns_anthropic()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        $this->assertEquals('anthropic', $provider->getProvider());
    }

    public function test_get_max_tokens_returns_200000()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        $this->assertEquals(200000, $provider->getMaxTokens());
    }

    public function test_count_tokens_returns_approximate_count()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        // "Hello world" = 11 chars / 3.5 = ~4 tokens
        $count = $provider->countTokens('Hello world');

        $this->assertIsInt($count);
        $this->assertGreaterThan(0, $count);
        $this->assertEquals(4, $count); // 11 / 3.5 = 3.14, ceil = 4
    }

    public function test_count_tokens_handles_empty_string()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        $count = $provider->countTokens('');

        $this->assertIsInt($count);
        $this->assertEquals(0, $count);
    }

    public function test_count_tokens_handles_long_text()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        // Create a long text (1000 characters)
        $longText = str_repeat('a', 1000);
        $count = $provider->countTokens($longText);

        $this->assertIsInt($count);
        $this->assertEquals(286, $count); // 1000 / 3.5 = 285.71, ceil = 286
    }

    public function test_chat_throws_exception_for_empty_messages_array()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        // Since Anthropic will fail on empty messages, we expect an exception
        // This test verifies input validation behavior
        $this->expectException(Exception::class);

        $provider->chat([]);
    }

    public function test_chat_json_throws_exception_for_empty_messages_array()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        $this->expectException(Exception::class);

        $provider->chatJson([]);
    }

    public function test_complete_throws_exception_for_empty_prompt()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        // Empty prompt will cause API error
        $this->expectException(Exception::class);

        $provider->complete('');
    }

    public function test_stream_throws_exception_for_empty_messages_array()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        $this->expectException(Exception::class);

        $provider->stream([], function($chunk) {
            // Callback that won't be called
        });
    }

    public function test_implements_llm_provider_interface()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        $reflection = new \ReflectionClass($provider);
        $interfaces = $reflection->getInterfaceNames();

        $this->assertContains(LlmProviderInterface::class, $interfaces);
    }

    public function test_get_model_returns_string()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        $this->assertIsString($provider->getModel());
    }

    public function test_get_provider_returns_string()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        $this->assertIsString($provider->getProvider());
    }

    public function test_get_max_tokens_returns_integer()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        $this->assertIsInt($provider->getMaxTokens());
    }

    public function test_count_tokens_returns_integer()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicLlmProvider($config);

        $count = $provider->countTokens('Test text');

        $this->assertIsInt($count);
    }

    public function test_custom_temperature_is_accepted()
    {
        $config = [
            'api_key' => 'sk-ant-test123',
            'temperature' => 0.8,
        ];

        $provider = new AnthropicLlmProvider($config);

        // If constructor doesn't throw, temperature was accepted
        $this->assertInstanceOf(AnthropicLlmProvider::class, $provider);
    }

    public function test_custom_max_tokens_is_accepted()
    {
        $config = [
            'api_key' => 'sk-ant-test123',
            'max_tokens' => 4000,
        ];

        $provider = new AnthropicLlmProvider($config);

        // If constructor doesn't throw, max_tokens was accepted
        $this->assertInstanceOf(AnthropicLlmProvider::class, $provider);
    }
}
