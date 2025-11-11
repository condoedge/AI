<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\EmbeddingProviders;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\EmbeddingProviders\OpenAiEmbeddingProvider;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use RuntimeException;

/**
 * Unit Tests for OpenAiEmbeddingProvider
 *
 * These tests focus on configuration validation and metadata methods.
 * Actual API calls are NOT tested here (those belong in integration tests)
 * to avoid hitting the real OpenAI API which requires a key and costs money.
 */
class OpenAiEmbeddingProviderTest extends TestCase
{
    public function test_constructor_accepts_valid_config()
    {
        $config = [
            'api_key' => 'sk-test123',
            'model' => 'text-embedding-3-small',
            'dimensions' => 1536,
        ];

        $provider = new OpenAiEmbeddingProvider($config);

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $provider);
        $this->assertInstanceOf(OpenAiEmbeddingProvider::class, $provider);
    }

    public function test_constructor_accepts_minimal_config()
    {
        $config = [
            'api_key' => 'sk-test123',
        ];

        $provider = new OpenAiEmbeddingProvider($config);

        $this->assertInstanceOf(OpenAiEmbeddingProvider::class, $provider);
    }

    public function test_constructor_throws_exception_for_missing_api_key()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API key is required');

        new OpenAiEmbeddingProvider([]);
    }

    public function test_constructor_throws_exception_for_empty_api_key()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API key is required');

        new OpenAiEmbeddingProvider(['api_key' => '']);
    }

    public function test_constructor_throws_exception_for_invalid_api_key_type()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API key must be a string');

        new OpenAiEmbeddingProvider(['api_key' => 12345]);
    }

    public function test_constructor_throws_exception_for_null_api_key()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API key is required');

        new OpenAiEmbeddingProvider(['api_key' => null]);
    }

    public function test_get_dimensions_returns_default_value()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiEmbeddingProvider($config);

        $this->assertEquals(1536, $provider->getDimensions());
    }

    public function test_get_dimensions_returns_custom_value()
    {
        $config = [
            'api_key' => 'sk-test123',
            'dimensions' => 512,
        ];
        $provider = new OpenAiEmbeddingProvider($config);

        $this->assertEquals(512, $provider->getDimensions());
    }

    public function test_get_model_returns_default_value()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiEmbeddingProvider($config);

        $this->assertEquals('text-embedding-3-small', $provider->getModel());
    }

    public function test_get_model_returns_custom_value()
    {
        $config = [
            'api_key' => 'sk-test123',
            'model' => 'text-embedding-3-large',
        ];
        $provider = new OpenAiEmbeddingProvider($config);

        $this->assertEquals('text-embedding-3-large', $provider->getModel());
    }

    public function test_get_max_length_returns_8191()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiEmbeddingProvider($config);

        $this->assertEquals(8191, $provider->getMaxLength());
    }

    public function test_embed_throws_exception_for_empty_text()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiEmbeddingProvider($config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Text cannot be empty');

        $provider->embed('');
    }

    public function test_embed_batch_throws_exception_for_empty_array()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiEmbeddingProvider($config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Texts array cannot be empty');

        $provider->embedBatch([]);
    }

    public function test_embed_batch_throws_exception_for_non_string_element()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiEmbeddingProvider($config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Text at index 1 must be a string');

        $provider->embedBatch(['Valid text', 12345, 'Another text']);
    }

    public function test_embed_batch_throws_exception_for_empty_string_element()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiEmbeddingProvider($config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Text at index 1 cannot be empty');

        $provider->embedBatch(['Valid text', '', 'Another text']);
    }

    public function test_implements_embedding_provider_interface()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiEmbeddingProvider($config);

        $reflection = new \ReflectionClass($provider);
        $interfaces = $reflection->getInterfaceNames();

        $this->assertContains(EmbeddingProviderInterface::class, $interfaces);
    }

    public function test_get_dimensions_returns_integer()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiEmbeddingProvider($config);

        $this->assertIsInt($provider->getDimensions());
    }

    public function test_get_model_returns_string()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiEmbeddingProvider($config);

        $this->assertIsString($provider->getModel());
    }

    public function test_get_max_length_returns_integer()
    {
        $config = ['api_key' => 'sk-test123'];
        $provider = new OpenAiEmbeddingProvider($config);

        $this->assertIsInt($provider->getMaxLength());
    }
}
