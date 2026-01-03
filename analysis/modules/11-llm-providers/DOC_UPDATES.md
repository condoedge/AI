# Module 11: LLM_PROVIDERS - Documentation Updates

> **Status:** COMPLETE

## Existing Documentation Quality

Both provider files have good inline PHPDoc documentation:
- Class-level descriptions with package tags
- Method documentation with `@param`, `@return`, `@throws`
- Private method documentation included

## Recommended Documentation Additions

### 1. Configuration Reference

Add to class docblocks or README:

```
OpenAI Configuration Keys:
- api_key (required): OpenAI API key
- model (optional): Model identifier, default 'gpt-4o'
- temperature (optional): Response randomness 0-2, default 0.3
- max_tokens (optional): Max response tokens, default 2000

Anthropic Configuration Keys:
- api_key (required): Anthropic API key
- model (optional): Model identifier, default 'claude-3-5-sonnet-20241022'
- temperature (optional): Response randomness 0-1, default 0.3
- max_tokens (optional): Max response tokens, default 2000
```

### 2. Rate Limit Behavior

Document current limitation:
```
Note: Rate limit errors (HTTP 429) are detected but not automatically retried.
Callers should implement their own retry logic or wrap provider calls.
```

### 3. Token Counting Caveat

Add to `countTokens()` docblock:
```
Note: This is an approximation using character-based estimation.
OpenAI uses ~4 chars/token, Anthropic uses ~3.5 chars/token.
For precise counts, use tiktoken library (OpenAI) or Claude tokenizer.
```

### 4. Streaming Response Format

Document callback signature:
```
Callback receives string chunks of the response as they arrive.
Example: function(string $chunk): void { echo $chunk; }
```

### 5. JSON Mode Differences

```
OpenAI: Uses native JSON response format (response_format parameter)
Anthropic: Uses prompt instruction only - may occasionally return non-JSON
```

## Files to Update

| File | Update Type | Priority |
|------|-------------|----------|
| `OpenAiLlmProvider.php` | Add config keys to class docblock | LOW |
| `AnthropicLlmProvider.php` | Add config keys to class docblock | LOW |
| Both providers | Add rate limit caveat to error handling section | MEDIUM |
| Both providers | Enhance `countTokens()` docblock with accuracy note | LOW |
| Package README | Add provider comparison table | MEDIUM |

## Code Improvements Suggested

### LLM-001 Fix: Add Type Casts in Anthropic

```php
// Line 81-82 in AnthropicLlmProvider.php
$this->temperature = (float) ($config['temperature'] ?? 0.3);
$this->maxResponseTokens = (int) ($config['max_tokens'] ?? 2000);
```

### LLM-002 Fix: Rate Limit Retry (Future Enhancement)

```php
// Conceptual - add to both providers
private function sendRequestWithRetry(array $requestData, int $maxRetries = 3): array
{
    $attempt = 0;
    while ($attempt < $maxRetries) {
        try {
            return $this->sendRequest($requestData);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'rate limit') !== false && $attempt < $maxRetries - 1) {
                $delay = pow(2, $attempt) * 1000; // Exponential backoff in ms
                usleep($delay * 1000);
                $attempt++;
                continue;
            }
            throw $e;
        }
    }
}
```
