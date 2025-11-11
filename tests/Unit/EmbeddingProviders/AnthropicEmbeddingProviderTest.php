<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\EmbeddingProviders;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\EmbeddingProviders\AnthropicEmbeddingProvider;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use RuntimeException;

/**
 * Unit Tests for AnthropicEmbeddingProvider
 *
 * Tests the placeholder implementation for Anthropic embeddings.
 * Since Anthropic doesn't currently offer an embeddings API, this provider
 * throws RuntimeException for embed operations but supports configuration
 * and metadata methods for future compatibility.
 */
class AnthropicEmbeddingProviderTest extends TestCase
{
    public function test_constructor_accepts_valid_config()
    {
        $config = [
            'api_key' => 'sk-ant-test123',
            'model' => 'claude-3-5-sonnet-20241022',
            'dimensions' => 1024,
        ];

        $provider = new AnthropicEmbeddingProvider($config);

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $provider);
        $this->assertInstanceOf(AnthropicEmbeddingProvider::class, $provider);
    }

    public function test_constructor_accepts_minimal_config()
    {
        $config = [
            'api_key' => 'sk-ant-test123',
        ];

        $provider = new AnthropicEmbeddingProvider($config);

        $this->assertInstanceOf(AnthropicEmbeddingProvider::class, $provider);
    }

    public function test_constructor_throws_exception_for_missing_api_key()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anthropic API key is required');

        new AnthropicEmbeddingProvider([]);
    }

    public function test_constructor_throws_exception_for_empty_api_key()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anthropic API key is required');

        new AnthropicEmbeddingProvider(['api_key' => '']);
    }

    public function test_constructor_throws_exception_for_invalid_api_key_type()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anthropic API key must be a string');

        new AnthropicEmbeddingProvider(['api_key' => 12345]);
    }

    public function test_constructor_throws_exception_for_null_api_key()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anthropic API key is required');

        new AnthropicEmbeddingProvider(['api_key' => null]);
    }

    public function test_get_dimensions_returns_default_value()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicEmbeddingProvider($config);

        $this->assertEquals(1024, $provider->getDimensions());
    }

    public function test_get_dimensions_returns_custom_value()
    {
        $config = [
            'api_key' => 'sk-ant-test123',
            'dimensions' => 512,
        ];
        $provider = new AnthropicEmbeddingProvider($config);

        $this->assertEquals(512, $provider->getDimensions());
    }

    public function test_get_model_returns_default_value()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicEmbeddingProvider($config);

        $this->assertEquals('claude-3-5-sonnet-20241022', $provider->getModel());
    }

    public function test_get_model_returns_custom_value()
    {
        $config = [
            'api_key' => 'sk-ant-test123',
            'model' => 'claude-3-opus-20240229',
        ];
        $provider = new AnthropicEmbeddingProvider($config);

        $this->assertEquals('claude-3-opus-20240229', $provider->getModel());
    }

    public function test_get_max_length_returns_200000()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicEmbeddingProvider($config);

        $this->assertEquals(200000, $provider->getMaxLength());
    }

    public function test_embed_throws_runtime_exception()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicEmbeddingProvider($config);

        $this->expectException(RuntimeException::class);

        $provider->embed('Test text');
    }

    public function test_embed_exception_contains_not_yet_supported_message()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicEmbeddingProvider($config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not yet supported');

        $provider->embed('Test text');
    }

    public function test_embed_exception_suggests_alternative_provider()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicEmbeddingProvider($config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Use OpenAI or another provider');

        $provider->embed('Test text');
    }

    public function test_embed_batch_throws_runtime_exception()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicEmbeddingProvider($config);

        $this->expectException(RuntimeException::class);

        $provider->embedBatch(['Test text 1', 'Test text 2']);
    }

    public function test_embed_batch_exception_contains_not_yet_supported_message()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicEmbeddingProvider($config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not yet supported');

        $provider->embedBatch(['Test text 1', 'Test text 2']);
    }

    public function test_embed_batch_exception_suggests_alternative_provider()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicEmbeddingProvider($config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Use OpenAI or another provider');

        $provider->embedBatch(['Test text 1', 'Test text 2']);
    }

    public function test_implements_embedding_provider_interface()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicEmbeddingProvider($config);

        $reflection = new \ReflectionClass($provider);
        $interfaces = $reflection->getInterfaceNames();

        $this->assertContains(EmbeddingProviderInterface::class, $interfaces);
    }

    public function test_get_dimensions_returns_integer()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicEmbeddingProvider($config);

        $this->assertIsInt($provider->getDimensions());
    }

    public function test_get_model_returns_string()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicEmbeddingProvider($config);

        $this->assertIsString($provider->getModel());
    }

    public function test_get_max_length_returns_integer()
    {
        $config = ['api_key' => 'sk-ant-test123'];
        $provider = new AnthropicEmbeddingProvider($config);

        $this->assertIsInt($provider->getMaxLength());
    }
}
