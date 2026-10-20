# Embeddings API

Generate vector embeddings from text using OpenAI.

---

## Overview

Embeddings convert text into numerical vectors that represent semantic meaning. Similar texts produce similar vectors.

**Supported Providers:**
- OpenAI: text-embedding-3-small (1536 dimensions)

OpenAI is the only built-in embedding provider (Anthropic offers no embeddings API). You can still use Anthropic as the LLM provider, and custom embedding providers can be registered (see Custom Embedding Providers).

---

## Generate Single Embedding

```php
use Condoedge\Ai\Facades\AI;

$text = "Artificial Intelligence and Machine Learning";
$vector = AI::embed($text);

// Returns: Array of 1536 floats
// [0.023, -0.015, 0.042, -0.008, ...]

echo "Dimensions: " . count($vector); // 1536
```

---

## Batch Embedding Generation

More efficient for multiple texts:

```php
$texts = [
    "First document about AI",
    "Second document about ML",
    "Third document about data science"
];

$vectors = AI::embedBatch($texts);

// Returns: Array of vectors
// [
//     [0.023, -0.015, ...],  // Vector 1
//     [0.031, -0.008, ...],  // Vector 2
//     [0.019, -0.012, ...]   // Vector 3
// ]
```

---

## Configuration

Set in `.env`:

```env
# OpenAI (default)
AI_EMBEDDING_PROVIDER=openai
OPENAI_API_KEY=sk-your-key-here
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
```

---

## Vector Dimensions

| Provider | Model | Dimensions |
|----------|-------|------------|
| OpenAI | text-embedding-3-small | 1536 |
| OpenAI | text-embedding-ada-002 | 1536 |

---

## Use Cases

### Semantic Search

```php
$queryVector = AI::embed("Find software developers");
$results = $qdrant->search('people', $queryVector, 5);
```

### Similarity Comparison

```php
$vec1 = AI::embed("Machine learning algorithms");
$vec2 = AI::embed("AI and ML techniques");

$similarity = cosineSimilarity($vec1, $vec2);
// High similarity (vectors are similar)
```

### Document Clustering

```php
$documents = ["Doc 1 text", "Doc 2 text", "Doc 3 text"];
$vectors = AI::embedBatch($documents);

// Use vectors for clustering or classification
```

---

## Advanced: Direct Provider Usage

```php
use Condoedge\Ai\EmbeddingProviders\OpenAiEmbeddingProvider;

$provider = new OpenAiEmbeddingProvider([
    'api_key' => env('OPENAI_API_KEY'),
    'model' => 'text-embedding-3-small',
    'dimensions' => 1536
]);

$vector = $provider->embed("Some text");
$vectors = $provider->embedBatch(["Text 1", "Text 2"]);
```

---

## Performance Tips

### Cache Embeddings

```php
$vector = Cache::remember("embed:{$text}", 3600, function() use ($text) {
    return AI::embed($text);
});
```

### Use Batch Operations

```php
// Slow - multiple API calls
foreach ($texts as $text) {
    $vector = AI::embed($text);
}

// Fast - single batch call
$vectors = AI::embedBatch($texts);
```

---

See also: [Context Retrieval](/docs/{{version}}/usage/context-retrieval) | [Configuration](/docs/{{version}}/foundations/configuration)
