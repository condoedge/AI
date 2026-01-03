# Module 11: LLM_PROVIDERS - Findings

> **Status:** COMPLETE

## Overview

Both `OpenAiLlmProvider.php` (409 lines) and `AnthropicLlmProvider.php` (449 lines) implement `LlmProviderInterface` with parallel structure. They handle API communication via cURL with support for both standard and streaming requests.

## API Call Patterns

### OpenAI Provider
- **Endpoint:** `https://api.openai.com/v1/chat/completions`
- **Authentication:** Bearer token in Authorization header
- **Default Model:** `gpt-4o` (128K context window)
- **Timeout:** 60s standard, 120s streaming

### Anthropic Provider
- **Endpoint:** `https://api.anthropic.com/v1/messages`
- **Authentication:** `x-api-key` header + `anthropic-version: 2023-06-01`
- **Default Model:** `claude-3-5-sonnet-20241022` (200K context window)
- **Timeout:** 60s standard, 120s streaming

### Request Building Differences
| Feature | OpenAI | Anthropic |
|---------|--------|-----------|
| System message | In messages array | Separate `system` field |
| JSON mode | `response_format: {type: json_object}` | Prompt instruction only |
| First message | Any role | Must be `user` role |

## Error Handling

Both providers handle errors consistently with switch-based HTTP status code handling:

| HTTP Code | Both Providers |
|-----------|----------------|
| 401 | "API authentication failed: Invalid API key" |
| 429 | "API rate limit exceeded. Please try again later." |
| 400 | "API bad request: {message}" |
| 500/503 | "API server error: {message}" |
| Other | "API request failed (HTTP {code}): {message}" |

### Error Scenarios Covered
- cURL execution failure (network errors)
- Invalid JSON response parsing
- Missing expected fields in response
- HTTP error codes with error message extraction

## Rate Limit Handling

**Current Implementation:** Basic detection only
- HTTP 429 is caught and throws exception with user-friendly message
- **No retry logic** - exceptions propagate immediately
- **No exponential backoff** implemented
- **No Retry-After header parsing**

## Response Normalization

### Standard Response Extraction
| Provider | Response Path |
|----------|--------------|
| OpenAI | `$response['choices'][0]['message']['content']` |
| Anthropic | `$response['content'][0]['text']` |

### Streaming Response Extraction
| Provider | Chunk Path | Event Format |
|----------|------------|--------------|
| OpenAI | `choices[0].delta.content` | `data: {json}` lines |
| Anthropic | `delta.text` (in content_block_delta) | `event:` + `data:` lines |

### Token Counting (Approximate)
| Provider | Characters per Token |
|----------|---------------------|
| OpenAI | 4 |
| Anthropic | 3.5 |

## Interface Compliance

Both providers fully implement `LlmProviderInterface`:
- `chat(array $messages, array $options = []): string`
- `chatJson(array $messages, array $options = []): object|array`
- `complete(string $prompt, ?string $systemPrompt = null, array $options = []): string`
- `stream(array $messages, callable $callback, array $options = []): void`
- `getModel(): string`
- `getProvider(): string`
- `getMaxTokens(): int`
- `countTokens(string $text): int`

## Issues Found

| ID | Severity | Description | Evidence | Recommendation |
|----|----------|-------------|----------|----------------|
| LLM-001 | LOW | Missing type casts in Anthropic constructor | Line 81-82: `$this->temperature = $config['temperature'] ?? 0.3;` vs OpenAI Line 76-77: `(float)` and `(int)` casts | Add explicit type casts for consistency |
| LLM-002 | MEDIUM | No retry logic for rate limits | HTTP 429 throws immediately without retry | Implement exponential backoff with configurable max retries |
| LLM-003 | LOW | Token counting is approximate only | Both use character-based estimation | Consider integrating tiktoken for OpenAI, note limitation in docs |
| LLM-004 | LOW | Streaming errors lose context | Streaming requests only report HTTP code on failure, not response body | Buffer and parse error response in streaming mode |
| LLM-005 | INFO | Anthropic lacks native JSON mode | Uses prompt instruction only vs OpenAI's `response_format` | Document behavior difference; consider JSON parsing fallback |
| LLM-006 | LOW | Hardcoded API versions | Anthropic: `2023-06-01`, both have hardcoded endpoints | Make configurable or document update process |
| LLM-007 | INFO | No request/response logging | Neither provider logs API interactions | Consider adding debug logging option for troubleshooting |

## Positive Observations

1. **Clean interface implementation** - Both providers follow identical public API contract
2. **Comprehensive error handling** - All HTTP error codes are mapped to meaningful messages
3. **Streaming support** - Both handle SSE (Server-Sent Events) parsing correctly
4. **Configuration validation** - API key requirement is enforced in constructor
5. **Provider-specific adaptations** - Anthropic correctly handles system message as separate field
6. **JSON response mode** - Both ensure JSON instruction is in system message for structured output
