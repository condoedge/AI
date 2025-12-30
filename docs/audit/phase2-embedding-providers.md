# Phase 2: Embedding Providers Audit

**Audit Date:** 2025-12-30
**Task:** Task 7 - Embedding Providers Review
**Directory:** `src/EmbeddingProviders/`

---

## Overview

The embedding providers layer handles text-to-vector conversion for semantic search and retrieval operations. Two providers exist, both implementing `EmbeddingProviderInterface`.

---

## File Inventory

| File | Lines | Status | Interface |
|------|-------|--------|-----------|
| OpenAiEmbeddingProvider.php | 272 | Fully Functional | EmbeddingProviderInterface |
| AnthropicEmbeddingProvider.php | 142 | Placeholder | EmbeddingProviderInterface |

---

## Detailed Analysis

### 1. OpenAiEmbeddingProvider.php

**Path:** `src/EmbeddingProviders/OpenAiEmbeddingProvider.php`
**Lines:** 272
**Status:** Production-ready

#### Purpose
Implements text embedding generation using OpenAI's Embeddings API. Supports both single and batch embedding operations for efficient processing.

#### API Interactions

| Operation | Endpoint | HTTP Method |
|-----------|----------|-------------|
| embed() | `https://api.openai.com/v1/embeddings` | POST |
| embedBatch() | `https://api.openai.com/v1/embeddings` | POST |

#### Methods

| Method | Visibility | Purpose | Returns |
|--------|------------|---------|---------|
| `__construct(array $config)` | public | Initialize with config (api_key, model, dimensions) | void |
| `validateConfig(array $config)` | private | Validate required config keys | void |
| `embed(string $text)` | public | Generate embedding for single text | array (vector) |
| `embedBatch(array $texts)` | public | Generate embeddings for multiple texts | array of arrays |
| `getDimensions()` | public | Get vector dimensionality | int |
| `getModel()` | public | Get model identifier | string |
| `getMaxLength()` | public | Get max text length (8191 tokens) | int |
| `makeApiRequest(array $payload)` | private | Execute cURL request to OpenAI | array |
| `handleApiError(string $response, int $httpCode)` | private | Parse and throw API errors | never (throws) |

#### Configuration

```php
[
    'api_key' => 'sk-xxx',           // Required - OpenAI API key
    'model' => 'text-embedding-3-small',  // Optional - default: text-embedding-3-small
    'dimensions' => 1536             // Optional - default: 1536
]
```

#### Output Format

- Single embed: `array<float>` - 1536 floats (or configured dimensions)
- Batch embed: `array<array<float>>` - Array of vectors, preserving input order

#### Error Handling

| HTTP Code | Exception Message |
|-----------|-------------------|
| 401 | "Invalid API key: {message}" |
| 429 | "Rate limit exceeded: {message}" |
| 400 | "Bad request: {message}" |
| 500 | "OpenAI server error: {message}" |
| 503 | "OpenAI service unavailable: {message}" |
| Other | "API error (HTTP {code}): {message}" |

#### Dependencies

- PHP cURL extension
- `Condoedge\Ai\Contracts\EmbeddingProviderInterface`
- `RuntimeException`

#### Test Coverage

| Test File | Type | Tests |
|-----------|------|-------|
| tests/Unit/EmbeddingProviders/OpenAiEmbeddingProviderTest.php | Unit | 17 tests |
| tests/Integration/EmbeddingProviders/OpenAiEmbeddingProviderTest.php | Integration | 11 tests |

---

### 2. AnthropicEmbeddingProvider.php

**Path:** `src/EmbeddingProviders/AnthropicEmbeddingProvider.php`
**Lines:** 142
**Status:** Placeholder (NOT functional)

#### Purpose
Placeholder implementation for future Anthropic embeddings support. Anthropic does not currently offer a public embeddings API.

#### Methods

| Method | Visibility | Purpose | Status |
|--------|------------|---------|--------|
| `__construct(array $config)` | public | Initialize with config | Works |
| `validateConfig(array $config)` | private | Validate config | Works |
| `embed(string $text)` | public | Generate embedding | **THROWS RuntimeException** |
| `embedBatch(array $texts)` | public | Batch embeddings | **THROWS RuntimeException** |
| `getDimensions()` | public | Returns 1024 (hypothetical) | Works |
| `getModel()` | public | Returns configured model | Works |
| `getMaxLength()` | public | Returns 200000 (Claude context) | Works |

#### Configuration

```php
[
    'api_key' => 'sk-ant-xxx',       // Required - validated but not used
    'model' => 'claude-3-5-sonnet-20241022',  // Optional - default
    'dimensions' => 1024             // Optional - hypothetical default
]
```

#### Critical Behavior

**Both `embed()` and `embedBatch()` throw RuntimeException:**
```php
throw new RuntimeException(
    'Anthropic embeddings not yet supported. Use OpenAI or another provider.'
);
```

#### Dependencies

- `Condoedge\Ai\Contracts\EmbeddingProviderInterface`
- `RuntimeException`

#### Test Coverage

| Test File | Type | Tests |
|-----------|------|-------|
| tests/Unit/EmbeddingProviders/AnthropicEmbeddingProviderTest.php | Unit | 19 tests |

---

## Interface Contract

**Path:** `src/Contracts/EmbeddingProviderInterface.php`

```php
interface EmbeddingProviderInterface
{
    public function embed(string $text): array;
    public function embedBatch(array $texts): array;
    public function getDimensions(): int;
    public function getModel(): string;
    public function getMaxLength(): int;
}
```

Both providers implement all 5 required methods.

---

## Registration

**Location:** `src/AiServiceProvider.php` (lines 173-181)

```php
$this->app->singleton(EmbeddingProviderInterface::class, function ($app) {
    $defaultProvider = config('ai.embedding.default', 'openai');

    return match ($defaultProvider) {
        'openai' => new OpenAiEmbeddingProvider(config('ai.embedding.openai')),
        'anthropic' => new AnthropicEmbeddingProvider(config('ai.embedding.anthropic')),
        default => throw new \RuntimeException("Unsupported embedding provider: {$defaultProvider}")
    };
});
```

---

## Usage Locations

The `EmbeddingProviderInterface` is injected into these services:

| Service | File | Usage |
|---------|------|-------|
| AiManager | src/Services/AiManager.php | Core AI orchestration |
| DataIngestionService | src/Services/DataIngestionService.php | Document ingestion |
| ContextRetriever | src/Services/ContextRetriever.php | RAG context retrieval |
| SemanticMatcher | src/Services/SemanticMatcher.php | Semantic matching |
| SemanticIndexer | src/Services/SemanticIndexer.php | Indexing operations |
| FileProcessor | src/Services/FileProcessor.php | File processing |
| QdrantChunkStore | src/Services/QdrantChunkStore.php | Vector store operations |
| QueryLearner | src/Services/Learning/QueryLearner.php | Query learning |
| ScopeSemanticMatcher | src/Services/ScopeSemanticMatcher.php | Scope matching |
| SemanticContextSelector | src/Services/SemanticContextSelector.php | Context selection |

---

## Methods/Features Never Exercised

### AnthropicEmbeddingProvider

| Method | Status | Reason |
|--------|--------|--------|
| `embed()` | **NEVER EXERCISED** | Always throws exception - Anthropic has no embedding API |
| `embedBatch()` | **NEVER EXERCISED** | Always throws exception - Anthropic has no embedding API |

### OpenAiEmbeddingProvider

All methods are exercised in tests and production code.

| Feature | Status | Notes |
|---------|--------|-------|
| Custom dimensions | Configurable | Not commonly used in production |
| Alternative models | Configurable | Default `text-embedding-3-small` typically used |

---

## Anomalies and Observations

### 1. Anthropic Provider is Non-Functional
- **Issue:** AnthropicEmbeddingProvider is registered in the service provider but will crash at runtime if selected
- **Risk:** Configuration of `ai.embedding.default = 'anthropic'` will cause application failure
- **Recommendation:** Either remove from service provider match or add clearer documentation warning

### 2. getMaxLength() Returns Characters vs Tokens
- **Issue:** OpenAI returns 8191 (tokens), Anthropic returns 200000 (characters)
- **Inconsistency:** Different units returned by same interface method
- **Recommendation:** Standardize on one unit or rename method to clarify

### 3. Direct cURL Usage
- **Observation:** OpenAiEmbeddingProvider uses raw cURL instead of Guzzle/HTTP client
- **Impact:** Inconsistent with Laravel conventions; harder to mock in tests
- **Note:** This works but could be improved for consistency

### 4. Hardcoded Timeout
- **Location:** `OpenAiEmbeddingProvider::REQUEST_TIMEOUT = 30`
- **Issue:** Not configurable via config array
- **Impact:** Cannot adjust for slow networks or large batches

### 5. No Rate Limiting Integration
- **Observation:** Neither provider integrates with `RateLimiter` service
- **Risk:** Heavy embedding operations could hit API rate limits
- **Note:** Error handling does catch 429 responses

### 6. Missing Retry Logic
- **Observation:** No automatic retry on transient failures (503, network errors)
- **Recommendation:** Consider integration with CircuitBreaker or retry mechanism

---

## Summary

| Metric | Value |
|--------|-------|
| Total Providers | 2 |
| Functional Providers | 1 (OpenAI only) |
| Interface Methods | 5 |
| Production Ready | 1 (OpenAI) |
| Test Files | 3 (2 unit + 1 integration) |
| Usage Locations | 10+ services |

### Health Assessment

| Provider | Status | Production Ready |
|----------|--------|------------------|
| OpenAiEmbeddingProvider | Healthy | Yes |
| AnthropicEmbeddingProvider | Placeholder | No |

### Critical Issues

1. **AnthropicEmbeddingProvider registered but non-functional** - Selecting it crashes the application
2. **No resilience features** - Missing retry logic, circuit breaker integration, rate limiting

### Recommendations

1. Remove AnthropicEmbeddingProvider from service provider match until Anthropic offers embedding API
2. Add configurable timeout option
3. Consider integrating with existing CircuitBreaker/RateLimiter services
4. Standardize getMaxLength() units across providers
5. Add retry logic for transient failures

---

*Audit completed: 2025-12-30*
