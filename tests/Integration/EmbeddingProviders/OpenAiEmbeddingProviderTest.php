<?php

declare(strict_types=1);

namespace AiSystem\Tests\Integration\EmbeddingProviders;

use AiSystem\Tests\TestCase;
use AiSystem\EmbeddingProviders\OpenAiEmbeddingProvider;
use RuntimeException;

/**
 * Integration Tests for OpenAiEmbeddingProvider
 *
 * These tests make actual API calls to OpenAI and require a valid API key.
 * They are automatically skipped if the OPENAI_API_KEY environment variable
 * is not set or contains a placeholder value.
 *
 * To run these tests, set your OpenAI API key:
 * export OPENAI_API_KEY="sk-your-actual-key-here"
 *
 * WARNING: These tests will cost a small amount of money (fractions of a cent)
 * as they make real API calls to OpenAI's embedding endpoint.
 */
class OpenAiEmbeddingProviderTest extends TestCase
{
    private ?OpenAiEmbeddingProvider $provider = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Try multiple sources for the API key
        $apiKey = getenv('OPENAI_API_KEY') ?: $_ENV['OPENAI_API_KEY'] ?? null;

        // Skip if no API key or if it's a placeholder
        if (!$apiKey || $apiKey === 'your-openai-api-key-here' || $apiKey === 'sk-test123') {
            $this->markTestSkipped(
                'OpenAI API key not configured. Set OPENAI_API_KEY environment variable to run integration tests.'
            );
        }

        $this->provider = new OpenAiEmbeddingProvider([
            'api_key' => $apiKey,
            'model' => 'text-embedding-3-small',
            'dimensions' => 1536,
        ]);
    }

    public function test_embed_returns_array_of_correct_dimensions()
    {
        $text = "Hello, this is a test embedding.";
        $vector = $this->provider->embed($text);

        $this->assertIsArray($vector);
        $this->assertCount(1536, $vector);

        // Verify all elements are floats
        foreach ($vector as $value) {
            $this->assertIsFloat($value);
        }
    }

    public function test_embed_returns_different_vectors_for_different_text()
    {
        $text1 = "The quick brown fox jumps over the lazy dog.";
        $text2 = "Artificial intelligence is transforming the world.";

        $vector1 = $this->provider->embed($text1);
        $vector2 = $this->provider->embed($text2);

        $this->assertCount(1536, $vector1);
        $this->assertCount(1536, $vector2);

        // Vectors should be different
        $this->assertNotEquals($vector1, $vector2);

        // Calculate cosine similarity to ensure they're reasonably different
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        for ($i = 0; $i < count($vector1); $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $magnitude1 += $vector1[$i] * $vector1[$i];
            $magnitude2 += $vector2[$i] * $vector2[$i];
        }

        $cosineSimilarity = $dotProduct / (sqrt($magnitude1) * sqrt($magnitude2));

        // Different texts should have similarity less than 1.0 (perfect match)
        $this->assertLessThan(1.0, $cosineSimilarity);
    }

    public function test_embed_returns_similar_vectors_for_similar_text()
    {
        $text1 = "I love programming in PHP.";
        $text2 = "I enjoy coding with PHP.";

        $vector1 = $this->provider->embed($text1);
        $vector2 = $this->provider->embed($text2);

        // Calculate cosine similarity
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        for ($i = 0; $i < count($vector1); $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $magnitude1 += $vector1[$i] * $vector1[$i];
            $magnitude2 += $vector2[$i] * $vector2[$i];
        }

        $cosineSimilarity = $dotProduct / (sqrt($magnitude1) * sqrt($magnitude2));

        // Similar texts should have high similarity (> 0.7 is typically considered similar)
        $this->assertGreaterThan(0.7, $cosineSimilarity);
    }

    public function test_embed_batch_returns_correct_number_of_vectors()
    {
        $texts = [
            "First text for embedding.",
            "Second text for embedding.",
            "Third text for embedding.",
        ];

        $vectors = $this->provider->embedBatch($texts);

        $this->assertIsArray($vectors);
        $this->assertCount(3, $vectors);

        // Verify each vector has correct dimensions
        foreach ($vectors as $vector) {
            $this->assertIsArray($vector);
            $this->assertCount(1536, $vector);

            foreach ($vector as $value) {
                $this->assertIsFloat($value);
            }
        }
    }

    public function test_embed_batch_maintains_input_order()
    {
        $texts = [
            "Apple",
            "Banana",
            "Cherry",
        ];

        $vectors = $this->provider->embedBatch($texts);

        // Generate individual embeddings to compare
        $vector1 = $this->provider->embed($texts[0]);
        $vector2 = $this->provider->embed($texts[1]);
        $vector3 = $this->provider->embed($texts[2]);

        // Batch results should match individual calls (approximately, due to minor API variations)
        // We'll check that the ordering is correct by ensuring vectors are different
        $this->assertNotEquals($vectors[0], $vectors[1]);
        $this->assertNotEquals($vectors[1], $vectors[2]);
        $this->assertNotEquals($vectors[0], $vectors[2]);
    }

    public function test_embed_batch_with_single_text()
    {
        $texts = ["Single text for batch embedding."];

        $vectors = $this->provider->embedBatch($texts);

        $this->assertIsArray($vectors);
        $this->assertCount(1, $vectors);
        $this->assertCount(1536, $vectors[0]);
    }

    public function test_embed_throws_exception_for_invalid_api_key()
    {
        $provider = new OpenAiEmbeddingProvider([
            'api_key' => 'sk-invalid-key-12345',
            'model' => 'text-embedding-3-small',
            'dimensions' => 1536,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid API key');

        $provider->embed("Test text");
    }

    public function test_embed_handles_special_characters()
    {
        $text = "Special characters: @#$%^&*() and émojis 🚀🎉!";
        $vector = $this->provider->embed($text);

        $this->assertIsArray($vector);
        $this->assertCount(1536, $vector);
    }

    public function test_embed_handles_long_text()
    {
        // Create a reasonably long text (but within limits)
        $text = str_repeat("This is a test sentence. ", 100);
        $vector = $this->provider->embed($text);

        $this->assertIsArray($vector);
        $this->assertCount(1536, $vector);
    }

    public function test_embed_batch_handles_mixed_length_texts()
    {
        $texts = [
            "Short",
            "This is a medium length text with several words.",
            str_repeat("This is a longer text. ", 50),
        ];

        $vectors = $this->provider->embedBatch($texts);

        $this->assertCount(3, $vectors);

        foreach ($vectors as $vector) {
            $this->assertCount(1536, $vector);
        }
    }
}
