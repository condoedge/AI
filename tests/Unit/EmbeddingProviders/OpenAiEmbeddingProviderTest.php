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

    // =========================================================================
    // Transient-failure retry (the embedding endpoint gates ALL retrieval, so a
    // lone SSL/network blip or 429 must not silently drop a document from the
    // answer — mirror the chat path's retry resilience).
    // =========================================================================

    private function okBody(): string
    {
        return json_encode(['data' => [['index' => 0, 'embedding' => array_fill(0, 1536, 0.01)]]]);
    }

    public function test_retries_transient_network_error_then_succeeds(): void
    {
        // First attempt: a transient SSL/connection failure (curl returns false) — the
        // exact failure mode seen in production logs ("unexpected eof while reading").
        $provider = new ScriptedEmbeddingProvider(
            ['api_key' => 'sk-test', 'retry_base_delay_ms' => 0],
            [
                ['code' => 0, 'body' => false, 'error' => 'error:0A000126:SSL routines::unexpected eof while reading'],
                ['code' => 200, 'body' => $this->okBody(), 'error' => ''],
            ]
        );

        $result = $provider->embed('Can I tear down a wall?');

        $this->assertCount(1536, $result);
        $this->assertCount(2, $provider->calls, 'should retry once after a transient network error');
    }

    public function test_retries_transient_429_then_succeeds(): void
    {
        $provider = new ScriptedEmbeddingProvider(
            ['api_key' => 'sk-test', 'retry_base_delay_ms' => 0],
            [
                ['code' => 429, 'body' => '{"error":{"message":"slow down"}}', 'error' => ''],
                ['code' => 200, 'body' => $this->okBody(), 'error' => ''],
            ]
        );

        $result = $provider->embed('hello');

        $this->assertCount(1536, $result);
        $this->assertCount(2, $provider->calls);
    }

    public function test_does_not_retry_permanent_400(): void
    {
        $provider = new ScriptedEmbeddingProvider(
            ['api_key' => 'sk-test', 'retry_base_delay_ms' => 0],
            [
                ['code' => 400, 'body' => '{"error":{"message":"bad input"}}', 'error' => ''],
                ['code' => 200, 'body' => $this->okBody(), 'error' => ''],
            ]
        );

        try {
            $provider->embed('hello');
            $this->fail('expected a RuntimeException for HTTP 400');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Bad request', $e->getMessage());
        }

        $this->assertCount(1, $provider->calls, 'a permanent 400 must NOT be retried');
    }

    public function test_gives_up_after_max_attempts_on_persistent_failure(): void
    {
        $provider = new ScriptedEmbeddingProvider(
            ['api_key' => 'sk-test', 'max_retries' => 3, 'retry_base_delay_ms' => 0],
            [
                ['code' => 503, 'body' => 'unavailable', 'error' => ''],
                ['code' => 503, 'body' => 'unavailable', 'error' => ''],
                ['code' => 503, 'body' => 'unavailable', 'error' => ''],
            ]
        );

        $threw = false;
        try {
            $provider->embed('hello');
        } catch (RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'persistent 503 should surface as an exception');
        $this->assertCount(3, $provider->calls, 'should attempt exactly max_retries times');
    }
}

/**
 * Test double that scripts the HTTP transport so retry behaviour can be unit-tested
 * without hitting the real OpenAI endpoint. Each entry is one transport result:
 * ['code' => int, 'body' => string|false, 'error' => string].
 */
class ScriptedEmbeddingProvider extends OpenAiEmbeddingProvider
{
    /** @var array<int, string> */
    public array $calls = [];

    /** @var array<int, array{code:int, body:string|false, error:string}> */
    private array $script;

    public function __construct(array $config, array $script)
    {
        parent::__construct($config);
        $this->script = $script;
    }

    protected function executeCurl(string $jsonBody): array
    {
        $this->calls[] = $jsonBody;
        $step = array_shift($this->script);
        if ($step === null) {
            throw new RuntimeException('ScriptedEmbeddingProvider: transport script exhausted');
        }

        return $step;
    }
}
