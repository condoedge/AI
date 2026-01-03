# Module 12: EMBEDDING_PROVIDERS - Documentation Updates

> **Status:** COMPLETED

## Recommended Documentation Updates

### 1. Interface Documentation Clarification

**File:** `src/Contracts/EmbeddingProviderInterface.php`

**Current:**
```php
/**
 * Get the maximum text length this provider can handle
 *
 * @return int Maximum characters/tokens
 */
public function getMaxLength(): int;
```

**Recommended:**
```php
/**
 * Get the maximum text length this provider can handle
 *
 * Note: Units vary by provider - OpenAI returns token count (8191),
 * other providers may return character count. Consumers should
 * treat this as an approximate limit and handle truncation gracefully.
 *
 * @return int Maximum input length (tokens or characters depending on provider)
 */
public function getMaxLength(): int;
```

### 2. Provider Configuration Documentation

Add to configuration documentation:

```markdown
## Embedding Providers

### Available Providers

| Provider | Status | Model | Dimensions |
|----------|--------|-------|------------|
| OpenAI | Active | text-embedding-3-small | 1536 |
| Anthropic | Placeholder | N/A | N/A |

### OpenAI Configuration

```php
'embedding_provider' => [
    'driver' => 'openai',
    'api_key' => env('OPENAI_API_KEY'),
    'model' => 'text-embedding-3-small', // or 'text-embedding-3-large'
    'dimensions' => 1536, // 1536 for small, 3072 for large
],
```

### Important Notes

1. **Anthropic Embeddings Not Available**: The Anthropic embedding provider is a placeholder. Anthropic does not currently offer a public embeddings API. Attempting to use it will throw a RuntimeException.

2. **Dimension Consistency**: When changing embedding providers, ensure your vector store is recreated or migrated, as different providers produce different dimension vectors.

3. **Rate Limits**: OpenAI has rate limits on embedding requests. For high-volume applications, implement request queuing.
```

### 3. Error Handling Documentation

Add to error handling section:

```markdown
## Embedding Provider Errors

### Common Errors

| Error | Cause | Solution |
|-------|-------|----------|
| "OpenAI API key is required" | Missing api_key in config | Add OPENAI_API_KEY to .env |
| "Invalid API key" | Incorrect or expired key | Verify key in OpenAI dashboard |
| "Rate limit exceeded" | Too many requests | Implement request throttling |
| "Text cannot be empty" | Empty string passed to embed() | Validate input before calling |
| "Anthropic embeddings not yet supported" | Using Anthropic provider | Switch to OpenAI provider |

### Error Recovery

The embedding providers do not implement automatic retry. For production applications, wrap embedding calls in retry logic:

```php
$maxRetries = 3;
$attempt = 0;

while ($attempt < $maxRetries) {
    try {
        return $provider->embed($text);
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'Rate limit') ||
            str_contains($e->getMessage(), 'service unavailable')) {
            $attempt++;
            sleep(pow(2, $attempt)); // Exponential backoff
            continue;
        }
        throw $e;
    }
}
```
```

### 4. API Usage Documentation

Add section on efficient embedding usage:

```markdown
## Efficient Embedding Usage

### Batch vs Single Embeddings

Always prefer `embedBatch()` over multiple `embed()` calls:

```php
// Inefficient: 10 API calls
foreach ($texts as $text) {
    $embeddings[] = $provider->embed($text);
}

// Efficient: 1 API call
$embeddings = $provider->embedBatch($texts);
```

### Checking Provider Capabilities

```php
$provider = app(EmbeddingProviderInterface::class);

// Get vector dimensions (needed for vector store schema)
$dimensions = $provider->getDimensions(); // 1536 for OpenAI

// Get max input length
$maxLength = $provider->getMaxLength(); // 8191 tokens for OpenAI

// Get model name for logging/debugging
$model = $provider->getModel(); // 'text-embedding-3-small'
```
```

## Files Requiring Updates

| File | Update Type | Priority |
|------|-------------|----------|
| `docs/configuration.md` | Add embedding provider configuration | Medium |
| `docs/error-handling.md` | Add embedding error documentation | Low |
| `src/Contracts/EmbeddingProviderInterface.php` | Clarify getMaxLength() units | Low |
| `config/ai.php` | Add comments explaining provider options | Low |

## New Documentation Sections Needed

1. **Embedding Provider Guide** - How to configure and use embedding providers
2. **Provider Comparison** - Differences between available providers
3. **Troubleshooting** - Common embedding-related issues and solutions
