# Phase 2: LLM Providers Audit

**Audit Date:** 2025-12-30
**Task:** Task 16 - LLM Providers Review
**Directory:** `src/LlmProviders/`

---

## Overview

The LLM providers layer handles Large Language Model API integrations for text generation, chat completions, and structured (JSON) outputs. Two providers exist, both implementing `LlmProviderInterface`. Unlike the embedding providers, **both LLM providers are fully functional**.

---

## File Inventory

| File | Lines | Status | Interface |
|------|-------|--------|-----------|
| OpenAiLlmProvider.php | 409 | Fully Functional | LlmProviderInterface |
| AnthropicLlmProvider.php | 449 | Fully Functional | LlmProviderInterface |

---

## Detailed Analysis

### 1. OpenAiLlmProvider.php

**Path:** `src/LlmProviders/OpenAiLlmProvider.php`
**Lines:** 409
**Status:** Production-ready

#### Purpose
Implements text generation and chat completion using OpenAI's Chat Completions API. Supports GPT-4o and related models with 128K context window.

#### API Interactions

| Operation | Endpoint | HTTP Method |
|-----------|----------|-------------|
| chat() | `https://api.openai.com/v1/chat/completions` | POST |
| chatJson() | `https://api.openai.com/v1/chat/completions` | POST |
| complete() | `https://api.openai.com/v1/chat/completions` | POST |
| stream() | `https://api.openai.com/v1/chat/completions` | POST (SSE) |

#### Methods

| Method | Visibility | Purpose | Returns |
|--------|------------|---------|---------|
| `__construct(array $config)` | public | Initialize with config | void |
| `chat(array $messages, array $options)` | public | Send chat messages, get response | string |
| `chatJson(array $messages, array $options)` | public | Chat with JSON response format | object/array |
| `complete(string $prompt, ?string $systemPrompt, array $options)` | public | Simple prompt completion | string |
| `stream(array $messages, callable $callback, array $options)` | public | Stream response via SSE | void |
| `getModel()` | public | Get model identifier | string |
| `getProvider()` | public | Returns 'openai' | string |
| `getMaxTokens()` | public | Returns 128000 | int |
| `countTokens(string $text)` | public | Approximate token count | int |
| `buildChatRequest(array $messages, array $options)` | private | Build API request payload | array |
| `sendRequest(array $requestData)` | private | Execute cURL request | array |
| `sendStreamingRequest(array $requestData, callable $callback)` | private | Execute streaming cURL request | void |
| `extractTextResponse(array $response)` | private | Extract content from API response | string |
| `ensureJsonSystemMessage(array $messages)` | private | Add JSON instruction to system | array |
| `handleErrorResponse(int $httpCode, array $response)` | private | Parse and throw API errors | never |

#### Configuration

```php
[
    'api_key' => 'sk-xxx',           // Required - OpenAI API key
    'model' => 'gpt-4o',             // Optional - default: gpt-4o
    'temperature' => 0.3,            // Optional - default: 0.3
    'max_tokens' => 2000             // Optional - default: 2000 (response tokens)
]
```

#### Token Counting

- **Method:** Character-based approximation
- **Ratio:** 4 characters per token (`strlen($text) / 4`)
- **Note:** Less accurate than tiktoken but performant

#### JSON Response Handling

OpenAI supports native JSON mode via `response_format`:
```php
$options['response_format'] = ['type' => 'json_object'];
```

Plus system message injection for reliability.

#### Streaming Implementation

- Uses cURL `CURLOPT_WRITEFUNCTION` for real-time streaming
- Parses Server-Sent Events (SSE) format
- Extracts content from `choices[0].delta.content`
- Handles `data: [DONE]` termination signal
- Timeout: 120 seconds for streaming

#### Error Handling

| HTTP Code | Exception Message |
|-----------|-------------------|
| 401 | "OpenAI API authentication failed: Invalid API key" |
| 429 | "OpenAI API rate limit exceeded. Please try again later." |
| 400 | "OpenAI API bad request: {message}" |
| 500, 503 | "OpenAI API server error: {message}" |
| Other | "OpenAI API request failed (HTTP {code}): {message}" |

#### Test Coverage

| Test File | Type | Tests |
|-----------|------|-------|
| tests/Unit/LlmProviders/OpenAiLlmProviderTest.php | Unit | 25+ tests |
| tests/Integration/LlmProviders/OpenAiLlmProviderTest.php | Integration | 10+ tests |

---

### 2. AnthropicLlmProvider.php

**Path:** `src/LlmProviders/AnthropicLlmProvider.php`
**Lines:** 449
**Status:** Production-ready

#### Purpose
Implements text generation and chat completion using Anthropic's Messages API. Supports Claude 3.5 Sonnet and related models with 200K context window.

#### API Interactions

| Operation | Endpoint | HTTP Method |
|-----------|----------|-------------|
| chat() | `https://api.anthropic.com/v1/messages` | POST |
| chatJson() | `https://api.anthropic.com/v1/messages` | POST |
| complete() | `https://api.anthropic.com/v1/messages` | POST |
| stream() | `https://api.anthropic.com/v1/messages` | POST (SSE) |

#### Required Headers

```http
Content-Type: application/json
x-api-key: {api_key}
anthropic-version: 2023-06-01
```

#### Methods

| Method | Visibility | Purpose | Returns |
|--------|------------|---------|---------|
| `__construct(array $config)` | public | Initialize with config | void |
| `chat(array $messages, array $options)` | public | Send chat messages, get response | string |
| `chatJson(array $messages, array $options)` | public | Chat expecting JSON response | object/array |
| `complete(string $prompt, ?string $systemPrompt, array $options)` | public | Simple prompt completion | string |
| `stream(array $messages, callable $callback, array $options)` | public | Stream response via SSE | void |
| `getModel()` | public | Get model identifier | string |
| `getProvider()` | public | Returns 'anthropic' | string |
| `getMaxTokens()` | public | Returns 200000 | int |
| `countTokens(string $text)` | public | Approximate token count | int |
| `buildChatRequest(array $messages, array $options)` | private | Build API request payload | array |
| `sendRequest(array $requestData, bool $streaming)` | private | Execute cURL request | array |
| `sendStreamingRequest(array $requestData, callable $callback)` | private | Execute streaming cURL request | void |
| `extractTextResponse(array $response)` | private | Extract content from API response | string |
| `ensureJsonSystemMessage(array $messages)` | private | Add JSON instruction to system | array |
| `handleErrorResponse(int $httpCode, array $response)` | private | Parse and throw API errors | never |

#### Configuration

```php
[
    'api_key' => 'sk-ant-xxx',                  // Required - Anthropic API key
    'model' => 'claude-3-5-sonnet-20241022',   // Optional - default
    'temperature' => 0.3,                       // Optional - default: 0.3
    'max_tokens' => 2000                        // Optional - default: 2000 (response)
]
```

#### Token Counting

- **Method:** Character-based approximation
- **Ratio:** 3.5 characters per token (`strlen($text) / 3.5`)
- **Note:** Claude has slightly different tokenization than GPT

#### Anthropic-Specific Message Format

Anthropic requires:
1. System messages passed as separate `system` field (not in messages array)
2. First message in conversation must be `user` role
3. No native JSON mode - relies on prompt instruction

```php
// Anthropic request format
[
    'model' => 'claude-3-5-sonnet-20241022',
    'max_tokens' => 2000,
    'messages' => [/* user/assistant messages only */],
    'system' => 'System prompt here',  // Separate field
    'temperature' => 0.3
]
```

#### Streaming Implementation

- Uses cURL `CURLOPT_WRITEFUNCTION` for real-time streaming
- Parses Anthropic SSE format with `event:` and `data:` lines
- Extracts content from `content_block_delta` events
- Looks for `delta.text` in chunks
- Timeout: 120 seconds for streaming

#### Error Handling

| HTTP Code | Exception Message |
|-----------|-------------------|
| 401 | "Anthropic API authentication failed: Invalid API key" |
| 429 | "Anthropic API rate limit exceeded. Please try again later." |
| 400 | "Anthropic API bad request: {message}" |
| 500, 503 | "Anthropic API server error: {message}" |
| Other | "Anthropic API request failed (HTTP {code}): {message}" |

#### Test Coverage

| Test File | Type | Tests |
|-----------|------|-------|
| tests/Unit/LlmProviders/AnthropicLlmProviderTest.php | Unit | 25+ tests |
| tests/Integration/LlmProviders/AnthropicLlmProviderTest.php | Integration | 10+ tests |

---

## Interface Contract

**Path:** `src/Contracts/LlmProviderInterface.php`

```php
interface LlmProviderInterface
{
    public function chat(array $messages, array $options = []): string;
    public function chatJson(array $messages, array $options = []): object|array;
    public function complete(string $prompt, ?string $systemPrompt = null, array $options = []): string;
    public function stream(array $messages, callable $callback, array $options = []): void;
    public function getModel(): string;
    public function getProvider(): string;
    public function getMaxTokens(): int;
    public function countTokens(string $text): int;
}
```

Both providers implement all 8 required methods.

---

## Registration

**Location:** `src/AiServiceProvider.php` (lines 183-192)

```php
$this->app->singleton(LlmProviderInterface::class, function ($app) {
    $defaultProvider = config('ai.llm.default', 'openai');

    return match ($defaultProvider) {
        'openai' => new OpenAiLlmProvider(config('ai.llm.openai')),
        'anthropic' => new AnthropicLlmProvider(config('ai.llm.anthropic')),
        default => throw new \RuntimeException("Unsupported LLM provider: {$defaultProvider}")
    };
});
```

---

## Configuration File

**Location:** `config/ai.php` (lines 192-208)

```php
'llm' => [
    'default' => env('AI_LLM_PROVIDER', 'openai'), // 'openai' or 'anthropic'

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        'temperature' => env('OPENAI_TEMPERATURE', 0.3),
        'max_tokens' => env('OPENAI_MAX_TOKENS', 2000),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-20241022'),
        'temperature' => env('ANTHROPIC_TEMPERATURE', 0.3),
        'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 2000),
    ],
],
```

---

## Usage Locations

### Direct Interface Injection

| Service | File | Usage |
|---------|------|-------|
| AiManager | src/Services/AiManager.php | Core AI orchestration, exposes chat/complete/stream |
| QueryGenerator | src/Services/QueryGenerator.php | Generates Cypher queries from natural language |
| ResponseGenerator | src/Services/ResponseGenerator.php | Generates natural language responses |

### Via AiManager/AI Facade

The `AiManager` wraps the LLM provider and exposes these methods via the `AI` facade:

| Facade Method | Internal Call | Purpose |
|---------------|---------------|---------|
| `AI::chat($input, $options)` | `$llm->chat()` | Chat completion |
| `AI::chatJson($input, $options)` | `$llm->chatJson()` | JSON response |
| `AI::complete($prompt, $system, $options)` | `$llm->complete()` | Simple completion |
| `AI::stream($messages, $callback, $options)` | `$llm->stream()` | Streaming response |
| `AI::getLlmModel()` | `$llm->getModel()` | Get model name |
| `AI::getLlmProvider()` | `$llm->getProvider()` | Get provider name |
| `AI::getLlmMaxTokens()` | `$llm->getMaxTokens()` | Get context limit |
| `AI::countTokens($text)` | `$llm->countTokens()` | Token estimation |

### Test Usage

- Unit tests mock `LlmProviderInterface` for isolated testing
- Integration tests require API keys set in environment

---

## Comparison: OpenAI vs Anthropic

| Feature | OpenAI | Anthropic |
|---------|--------|-----------|
| Default Model | gpt-4o | claude-3-5-sonnet-20241022 |
| Max Context | 128,000 tokens | 200,000 tokens |
| Token Ratio | 4 chars/token | 3.5 chars/token |
| API Version Header | Not required | Required (2023-06-01) |
| Auth Header | Authorization: Bearer | x-api-key |
| System Message | In messages array | Separate `system` field |
| JSON Mode | Native `response_format` | Prompt-based only |
| First Message | Any role | Must be `user` |
| Streaming Events | `choices[0].delta.content` | `content_block_delta.delta.text` |

---

## Resilience Features

### Current Implementation

| Feature | Status | Notes |
|---------|--------|-------|
| Request Timeout | Yes | 60s standard, 120s streaming |
| Error Classification | Yes | HTTP code-based error handling |
| Rate Limit Detection | Yes | Catches 429 responses |
| Retry Logic | **No** | No automatic retries |
| Circuit Breaker | **No** | Not integrated |
| Rate Limiter | **No** | Not integrated |

### Missing Features

Both providers could benefit from:
1. Integration with `CircuitBreaker` service
2. Integration with `RateLimiter` service
3. Automatic retry on transient failures (503, network errors)
4. Configurable timeout options

---

## Anomalies and Observations

### 1. Both Providers Fully Functional (Unlike Embeddings)

Unlike the embedding providers where Anthropic is a placeholder, **both LLM providers are production-ready**. This is because Anthropic does offer a Messages API for LLM operations (just not embeddings).

### 2. JSON Mode Differences

- **OpenAI:** Uses native `response_format: { type: "json_object" }` parameter
- **Anthropic:** Relies only on prompt instruction "Respond with valid JSON only"
- **Risk:** Anthropic may occasionally return non-JSON despite instruction

### 3. System Message Handling Differences

```php
// OpenAI - system in messages array
$messages = [
    ['role' => 'system', 'content' => 'You are helpful'],
    ['role' => 'user', 'content' => 'Hello']
];

// Anthropic - system extracted to separate field
$request = [
    'system' => 'You are helpful',
    'messages' => [
        ['role' => 'user', 'content' => 'Hello']
    ]
];
```

The Anthropic provider handles this transformation automatically in `buildChatRequest()`.

### 4. First Message Validation (Anthropic Only)

```php
// In AnthropicLlmProvider::buildChatRequest()
if (!empty($chatMessages) && $chatMessages[0]['role'] !== 'user') {
    throw new Exception('Anthropic API requires first message to be user role');
}
```

OpenAI does not have this restriction.

### 5. Direct cURL Usage

Both providers use raw cURL instead of Guzzle/HTTP client. This is consistent within the package but differs from Laravel conventions. Makes mocking more difficult.

### 6. Hardcoded Timeouts

| Provider | Standard Request | Streaming Request |
|----------|------------------|-------------------|
| OpenAI | 60 seconds | 120 seconds |
| Anthropic | 60 seconds | 120 seconds |

These are not configurable via the config array.

### 7. Token Counting Approximation

Both providers use simple character division for token estimation:
- OpenAI: `strlen / 4`
- Anthropic: `strlen / 3.5`

For accurate counting, external libraries like tiktoken would be needed.

### 8. No Request/Response Logging

Neither provider logs API requests or responses. This could hinder debugging production issues.

---

## Summary

| Metric | Value |
|--------|-------|
| Total Providers | 2 |
| Functional Providers | 2 (both fully functional) |
| Interface Methods | 8 |
| Production Ready | 2 (both) |
| Test Files | 4 (2 unit + 2 integration) |
| Usage Locations | 3+ services + facade |

### Health Assessment

| Provider | Status | Production Ready |
|----------|--------|------------------|
| OpenAiLlmProvider | Healthy | Yes |
| AnthropicLlmProvider | Healthy | Yes |

### Critical Issues

None - both providers are functional and well-tested.

### Minor Issues

1. **No resilience features** - Missing retry logic, circuit breaker, rate limiter integration
2. **Hardcoded timeouts** - Not configurable
3. **No logging** - Difficult to debug production issues
4. **JSON mode disparity** - Anthropic relies on prompt-based JSON (less reliable)

### Recommendations

1. Add configurable timeout option to config array
2. Consider integrating with existing CircuitBreaker/RateLimiter services
3. Add optional request/response logging for debugging
4. Consider using tiktoken or similar for accurate token counting (OpenAI)
5. Add retry logic for transient failures (503, network timeouts)
6. Consider adding response validation for JSON mode on Anthropic

---

*Audit completed: 2025-12-30*
