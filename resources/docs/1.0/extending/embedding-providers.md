# Custom Embedding Providers

Create custom embedding providers for vector generation.

---

## Overview

Embedding providers convert text to vectors for semantic search. Built-in providers:
- **OpenAI**: text-embedding-3-small, text-embedding-ada-002

You can create custom providers to integrate with:
- Local models (Ollama, sentence-transformers)
- Cloud providers (Azure, AWS, Cohere)
- Custom embedding services

---

## Provider Interface

All embedding providers implement `EmbeddingProviderInterface`:

```php
<?php

namespace Condoedge\Ai\Contracts;

interface EmbeddingProviderInterface
{
    /**
     * Generate embeddings for text.
     *
     * @param string|array $text Single text or array of texts
     * @return array Single embedding vector or array of vectors
     */
    public function embed(string|array $text): array;

    /**
     * Get the embedding dimensions.
     *
     * @return int
     */
    public function getDimensions(): int;

    /**
     * Get the provider name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Check if the provider is available.
     *
     * @return bool
     */
    public function isAvailable(): bool;
}
```

---

## Creating a Custom Provider

### Step 1: Create Provider Class

```php
<?php

namespace App\Services\Ai\Providers;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Illuminate\Support\Facades\Http;

class OllamaEmbeddingProvider implements EmbeddingProviderInterface
{
    protected string $baseUrl;
    protected string $model;
    protected int $dimensions;

    public function __construct()
    {
        $this->baseUrl = config('ai.embedding.ollama.base_url', 'http://localhost:11434');
        $this->model = config('ai.embedding.ollama.model', 'nomic-embed-text');
        $this->dimensions = config('ai.embedding.ollama.dimensions', 768);
    }

    public function embed(string|array $text): array
    {
        // Handle single text
        if (is_string($text)) {
            return $this->embedSingle($text);
        }

        // Handle batch
        return array_map(fn($t) => $this->embedSingle($t), $text);
    }

    protected function embedSingle(string $text): array
    {
        $response = Http::timeout(60)
            ->post("{$this->baseUrl}/api/embeddings", [
                'model' => $this->model,
                'prompt' => $text,
            ]);

        if (!$response->successful()) {
            throw new \Exception("Ollama embedding error: " . $response->body());
        }

        return $response->json('embedding', []);
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    public function getName(): string
    {
        return 'ollama';
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/tags");
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
```

### Step 2: Add Configuration

```php
// config/ai.php
'embedding' => [
    'default' => env('AI_EMBEDDING_PROVIDER', 'ollama'),

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
        'dimensions' => 768,
    ],
],
```

### Step 3: Register Provider

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use App\Services\Ai\Providers\OllamaEmbeddingProvider;

class AiExtensionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (config('ai.embedding.default') === 'ollama') {
            $this->app->bind(EmbeddingProviderInterface::class, OllamaEmbeddingProvider::class);
        }
    }
}
```

### Step 4: Use Your Provider

```env
AI_EMBEDDING_PROVIDER=ollama
OLLAMA_EMBEDDING_MODEL=nomic-embed-text
```

Embeddings are now generated using Ollama for all operations.

---

## Example: Cohere Provider

```php
<?php

namespace App\Services\Ai\Providers;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Illuminate\Support\Facades\Http;

class CohereEmbeddingProvider implements EmbeddingProviderInterface
{
    protected string $apiKey;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('ai.embedding.cohere.api_key');
        $this->model = config('ai.embedding.cohere.model', 'embed-english-v3.0');
    }

    public function embed(string|array $text): array
    {
        $texts = is_array($text) ? $text : [$text];

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
        ])->post('https://api.cohere.ai/v1/embed', [
            'texts' => $texts,
            'model' => $this->model,
            'input_type' => 'search_document',
        ]);

        if (!$response->successful()) {
            throw new \Exception("Cohere API error: " . $response->body());
        }

        $embeddings = $response->json('embeddings', []);

        return is_array($text) ? $embeddings : $embeddings[0];
    }

    public function getDimensions(): int
    {
        // embed-english-v3.0 = 1024 dimensions
        return 1024;
    }

    public function getName(): string
    {
        return 'cohere';
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }
}
```

---

## Example: Local Sentence Transformers

Using a local Python service:

```php
<?php

namespace App\Services\Ai\Providers;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Illuminate\Support\Facades\Http;

class SentenceTransformersProvider implements EmbeddingProviderInterface
{
    protected string $serviceUrl;
    protected int $dimensions;

    public function __construct()
    {
        $this->serviceUrl = config('ai.embedding.sentence_transformers.url', 'http://localhost:8000');
        $this->dimensions = config('ai.embedding.sentence_transformers.dimensions', 384);
    }

    public function embed(string|array $text): array
    {
        $response = Http::timeout(60)
            ->post("{$this->serviceUrl}/embed", [
                'texts' => is_array($text) ? $text : [$text],
            ]);

        if (!$response->successful()) {
            throw new \Exception("Embedding service error: " . $response->body());
        }

        $embeddings = $response->json('embeddings', []);

        return is_array($text) ? $embeddings : $embeddings[0];
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    public function getName(): string
    {
        return 'sentence_transformers';
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->serviceUrl}/health");
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
```

**Python service example (FastAPI):**

```python
from fastapi import FastAPI
from sentence_transformers import SentenceTransformer
from pydantic import BaseModel

app = FastAPI()
model = SentenceTransformer('all-MiniLM-L6-v2')

class EmbedRequest(BaseModel):
    texts: list[str]

@app.post("/embed")
def embed(request: EmbedRequest):
    embeddings = model.encode(request.texts)
    return {"embeddings": embeddings.tolist()}

@app.get("/health")
def health():
    return {"status": "ok"}
```

---

## Dimension Considerations

### Matching Dimensions

Qdrant collections are created with specific dimensions. Ensure:
1. Your provider's dimensions match the configuration
2. All entities use the same embedding provider
3. Rebuild collections if changing providers

```php
// config/ai.php
'embedding' => [
    'ollama' => [
        'dimensions' => 768,  // Must match model output
    ],
],
```

### Common Dimensions

| Model | Dimensions |
|-------|------------|
| text-embedding-3-small | 1536 |
| text-embedding-ada-002 | 1536 |
| nomic-embed-text | 768 |
| all-MiniLM-L6-v2 | 384 |
| embed-english-v3.0 | 1024 |

---

## Batch Processing

For efficiency, implement batch processing:

```php
public function embed(string|array $text): array
{
    if (is_string($text)) {
        return $this->embedBatch([$text])[0];
    }

    // Process in batches of 100
    $results = [];
    foreach (array_chunk($text, 100) as $batch) {
        $results = array_merge($results, $this->embedBatch($batch));
    }

    return $results;
}

protected function embedBatch(array $texts): array
{
    // Single API call for batch
    $response = Http::post($this->endpoint, ['texts' => $texts]);
    return $response->json('embeddings');
}
```

---

## Caching Embeddings

Reduce API calls by caching:

```php
public function embed(string|array $text): array
{
    if (is_string($text)) {
        return $this->embedWithCache($text);
    }

    return array_map(fn($t) => $this->embedWithCache($t), $text);
}

protected function embedWithCache(string $text): array
{
    $cacheKey = 'embedding:' . md5($text);

    return Cache::remember($cacheKey, 86400, function () use ($text) {
        return $this->embedSingle($text);
    });
}
```

---

## Testing Providers

```php
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;

// Test availability
$provider = app(EmbeddingProviderInterface::class);
$this->assertTrue($provider->isAvailable());

// Test single embedding
$embedding = $provider->embed("Hello world");
$this->assertCount($provider->getDimensions(), $embedding);

// Test batch embedding
$embeddings = $provider->embed(["Hello", "World"]);
$this->assertCount(2, $embeddings);
```

---

## Migration Guide

When switching embedding providers:

```bash
# 1. Update configuration
AI_EMBEDDING_PROVIDER=new_provider

# 2. Delete existing Qdrant collections
# (Collections have dimension mismatch)

# 3. Re-ingest all data
php artisan ai:ingest --force

# 4. Rebuild semantic indexes
php artisan ai:index-semantic --rebuild
php artisan ai:index-context --rebuild
```

---

## Related Documentation

- [Custom LLM Providers](/docs/{{version}}/extending/llm-providers) - LLM integration
- [Overview](/docs/{{version}}/usage/extending) - Extension overview
- [Data Ingestion](/docs/{{version}}/usage/data-ingestion) - Ingestion guide
