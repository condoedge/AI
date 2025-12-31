# Phase 6: Merge Plan for Consolidating Duplicate Logic

**Date:** 2025-12-30
**Based On:** Phase 2-5 Audit Documents
**Purpose:** Eliminate duplicate code, consolidate overlapping functionality, reduce maintenance burden

---

## 1. Duplicate Logic Identified

### 1.1 Scope Indexing Duplication

**Files Involved:**
- `src/Services/SemanticIndexer.php` - `indexScopes()` method
- `src/Services/ScopeSemanticMatcher.php` - `indexScopes()` method

**What Overlaps:**
Both classes create vector indexes for scope semantic matching with nearly identical logic:

| Feature | SemanticIndexer | ScopeSemanticMatcher |
|---------|-----------------|----------------------|
| Collection Name | `semantic_scopes` | `scope_examples` |
| Indexes scope name | Yes | Yes |
| Indexes description | Yes | No |
| Indexes concept | Yes | Yes |
| Indexes examples | No | Yes |
| Indexes aliases | No | Yes |
| Batch embedding | Yes | Yes |

**Code Evidence:**
```php
// SemanticIndexer::indexScopes()
foreach ($metadata['scopes'] as $scopeName => $scopeConfig) {
    // Index scope name
    $points[] = ['id' => ++$pointId, 'text' => $scopeName, ...];
    // Index description
    if (!empty($scopeConfig['description'])) { $points[] = [...]; }
    // Index concept
    if (!empty($scopeConfig['concept'])) { $points[] = [...]; }
}

// ScopeSemanticMatcher::indexScopes()
foreach ($scopes as $scopeName => $scopeConfig) {
    // Index the concept
    if (!empty($scopeConfig['concept'])) { $points[] = $this->createPoint(...); }
    // Index each example
    foreach ($scopeConfig['examples'] ?? [] as $example) { $points[] = [...]; }
    // Index aliases
    foreach ($scopeConfig['aliases'] ?? [] as $alias) { $points[] = [...]; }
}
```

**Impact:** Two separate collections for scopes with different indexing strategies, wasted embedding API calls, confusion about which to use.

---

### 1.2 Semantic Matching Method Overlap

**Files Involved:**
- `src/Services/SemanticMatcher.php` - `matchScopes()` method
- `src/Services/ScopeSemanticMatcher.php` - `findMatchingScopes()` method

**What Overlaps:**
Both methods find scopes matching a question using semantic similarity:

| Feature | SemanticMatcher::matchScopes() | ScopeSemanticMatcher::findMatchingScopes() |
|---------|-------------------------------|---------------------------------------------|
| Input | question, scopes array | question, entityConfigs |
| Vector search | In-memory or collection | Uses collection first, fallback to string |
| Deduplication | By score sorting | By unique key tracking |
| Threshold | Configurable | Configurable |
| Return format | Array with score, config | Array with score, match_type |

**Code Evidence:**
```php
// SemanticMatcher::matchScopes() - NEVER USED in pipeline
public function matchScopes(string $question, array $scopes, float $threshold = 0.70): array {
    foreach ($scopes as $scopeName => $scopeConfig) {
        // Build candidate texts: name + description + concept
        $match = $this->findBestMatch(query: $question, candidates: [...]);
    }
}

// ScopeSemanticMatcher::findMatchingScopes() - ACTIVELY USED
public function findMatchingScopes(string $question, array $entityConfigs, ...): array {
    $questionEmbedding = $this->embeddingProvider->embed($question);
    $results = $this->vectorStore->search($collectionName, $questionEmbedding, ...);
}
```

**Impact:** `SemanticMatcher::matchScopes()` is dead code, superseded by `ScopeSemanticMatcher`.

---

### 1.3 Physical File Prefix Duplication

**Files Involved:**
- `src/Services/Context/FileAccessResolver.php` - `PHYSICAL_PREFIX` constant, `isPhysicalFile()` method
- `src/Services/Context/FileContextProvider.php` - `PHYSICAL_PREFIX` constant, `isPhysicalFile()` method

**What Overlaps:**
Identical constant and method duplicated across both files:

```php
// FileAccessResolver.php
public const PHYSICAL_PREFIX = 'physical:';

public function isPhysicalFile(int|string $fileId): bool
{
    return is_string($fileId) && str_starts_with($fileId, self::PHYSICAL_PREFIX);
}

// FileContextProvider.php
private const PHYSICAL_PREFIX = 'physical:';

private function isPhysicalFile(int|string $fileId): bool
{
    return is_string($fileId) && str_starts_with($fileId, self::PHYSICAL_PREFIX);
}
```

**Impact:** Maintenance burden, risk of divergence, violates DRY principle.

---

### 1.4 LLM Provider HTTP/cURL Logic Duplication

**Files Involved:**
- `src/LlmProviders/OpenAiLlmProvider.php`
- `src/LlmProviders/AnthropicLlmProvider.php`

**What Overlaps:**
Both providers implement nearly identical HTTP communication patterns:

| Method | OpenAI | Anthropic | Identical? |
|--------|--------|-----------|------------|
| `sendRequest()` | cURL implementation | cURL implementation | ~85% similar |
| `sendStreamingRequest()` | SSE parsing | SSE parsing | ~70% similar |
| `handleErrorResponse()` | HTTP code mapping | HTTP code mapping | ~80% similar |
| Timeout handling | 60s/120s | 60s/120s | Identical |
| JSON decode/encode | Same pattern | Same pattern | Identical |

**Code Evidence:**
```php
// OpenAiLlmProvider::sendRequest() - 45 lines
private function sendRequest(array $requestData): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestData),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->config['api_key'],
        ],
    ]);
    // ... error handling, JSON decode
}

// AnthropicLlmProvider::sendRequest() - 48 lines
private function sendRequest(array $requestData, bool $streaming = false): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.anthropic.com/v1/messages',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestData),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $this->config['api_key'],
            'anthropic-version: 2023-06-01',
        ],
    ]);
    // ... nearly identical error handling, JSON decode
}
```

**Impact:** ~150 lines of near-duplicate HTTP handling code, changes must be made in both places.

---

### 1.5 Embedding Provider HTTP Logic Duplication

**Files Involved:**
- `src/EmbeddingProviders/OpenAiEmbeddingProvider.php`
- `src/EmbeddingProviders/AnthropicEmbeddingProvider.php` (placeholder)

**What Overlaps:**
HTTP request logic duplicated (though Anthropic is non-functional placeholder).

---

### 1.6 Prompt/Response Section Base Class Patterns

**Files Involved:**
- `src/Services/PromptSections/BasePromptSection.php`
- `src/Services/ResponseSections/BaseResponseSection.php`

**What Overlaps:**
Nearly identical base classes for two section systems:

```php
// BasePromptSection.php
abstract class BasePromptSection implements PromptSectionInterface {
    protected string $name;
    protected int $priority;

    public function getName(): string { return $this->name; }
    public function getPriority(): int { return $this->priority; }
    public function shouldInclude(array $context, array $options): bool { return true; }
    protected function header(string $title): string { return "=== {$title} ===\n\n"; }
}

// BaseResponseSection.php
abstract class BaseResponseSection implements ResponseSectionInterface {
    protected string $name;
    protected int $priority;

    public function getName(): string { return $this->name; }
    public function getPriority(): int { return $this->priority; }
    public function shouldInclude(array $context, array $options): bool { return true; }
    protected function header(string $title): string { return "=== {$title} ===\n\n"; }
}
```

**Impact:** Two identical base classes with different interfaces, maintenance overhead.

---

### 1.7 Chat History Retrieval Duplication

**Files Involved:**
- `src/Models/AiConversation.php` - `getRecentMessages()`
- `src/Services/Context/ConversationContextManager.php` - `buildPromptContext()`
- `src/Services/Chat/AiChatService.php` - `buildQuestionWithHistory()`

**What Overlaps:**
Three different approaches to retrieving and formatting conversation history:

| Method | Limit | Truncation | Used By |
|--------|-------|------------|---------|
| `getRecentMessages()` | 10 messages | None | `ChatMessageForm` |
| `buildPromptContext()` | 5 exchanges | 100/150 chars | `AiChatService::askWithConversation()` |
| `buildQuestionWithHistory()` | Via param | 200 chars | `AiChatService::askWithHistory()` |

**Impact:** Inconsistent context provided to AI depending on entry point, hardcoded limits.

---

### 1.8 Unused Shorthand Methods in Traits

**Files Involved:**
- `src/Kompo/Traits/HasChatSettings.php` - 14 shorthand methods
- `src/Kompo/Traits/HasChatTheme.php` - 11 shorthand methods

**What Overlaps:**
Both traits provide shorthand methods that duplicate functionality already available via `$this->settings()` and `$this->theme()`:

```php
// HasChatSettings - NONE of these are ever called
protected function showAvatars(): bool { return $this->settings()->showAvatars(); }
protected function showTimestamps(): bool { return $this->settings()->showTimestamps(); }
// ... 12 more identical delegation patterns

// HasChatTheme - Only mainHexColor() is used
protected function themeGradient(): string { return 'bg-gradient-to-r ' . $this->theme()->primaryGradient(); }
protected function themeSolid(): string { return $this->theme()->primaryColor(); }
// ... 9 more identical delegation patterns
```

**Impact:** 25+ unused methods cluttering the codebase.

---

## 2. Recommended Consolidations

### 2.1 Merge Scope Indexing into Single Service

**Merge Into:** `ScopeSemanticMatcher`

**Before:**
```
SemanticIndexer.indexScopes() -> semantic_scopes collection
ScopeSemanticMatcher.indexScopes() -> scope_examples collection
```

**After:**
```
ScopeSemanticMatcher.indexScopes() -> scope_examples collection (enhanced)
SemanticIndexer.indexScopes() -> REMOVED
```

**Changes Required:**
1. Enhance `ScopeSemanticMatcher::indexScopes()` to also index descriptions (from SemanticIndexer)
2. Remove `SemanticIndexer::indexScopes()` method
3. Update `IndexSemanticCommand` to skip scope indexing (delegated to `IndexScopesCommand`)
4. Delete `semantic_scopes` collection references

**Estimated Lines Changed:** ~80 lines removed, ~10 lines added

---

### 2.2 Delete SemanticMatcher::matchScopes()

**Merge Into:** N/A (just delete)

**Before:**
```php
// SemanticMatcher.php
public function matchScopes(string $question, array $scopes, float $threshold = 0.70): array { ... }
```

**After:**
```php
// Method removed - use ScopeSemanticMatcher::findMatchingScopes() instead
```

**Changes Required:**
1. Delete `SemanticMatcher::matchScopes()` method (~45 lines)
2. Verify no callers exist (confirmed: none in pipeline)
3. Update any documentation referencing this method

**Estimated Lines Changed:** ~45 lines removed

---

### 2.3 Consolidate Physical File Handling

**Merge Into:** `FileAccessResolver` (single source of truth)

**Before:**
```php
// FileAccessResolver.php
public const PHYSICAL_PREFIX = 'physical:';
public function isPhysicalFile($fileId): bool { ... }

// FileContextProvider.php
private const PHYSICAL_PREFIX = 'physical:';
private function isPhysicalFile($fileId): bool { ... }
```

**After:**
```php
// FileAccessResolver.php (unchanged)
public const PHYSICAL_PREFIX = 'physical:';
public function isPhysicalFile($fileId): bool { ... }

// FileContextProvider.php
// Uses: $this->accessResolver->isPhysicalFile($fileId)
// Uses: FileAccessResolver::PHYSICAL_PREFIX
// Local constant and method REMOVED
```

**Changes Required:**
1. Remove `PHYSICAL_PREFIX` constant from `FileContextProvider`
2. Remove `isPhysicalFile()` method from `FileContextProvider`
3. Update calls to use `$this->accessResolver->isPhysicalFile()` or `FileAccessResolver::PHYSICAL_PREFIX`

**Estimated Lines Changed:** ~15 lines removed, ~5 lines changed

---

### 2.4 Extract Abstract HTTP Client for Providers

**Merge Into:** New `AbstractApiClient` base class

**Before:**
```php
// OpenAiLlmProvider.php - 45 lines sendRequest()
// AnthropicLlmProvider.php - 48 lines sendRequest()
// OpenAiEmbeddingProvider.php - similar pattern
```

**After:**
```php
// AbstractApiClient.php (new)
abstract class AbstractApiClient {
    protected function sendRequest(string $url, array $data, array $headers, int $timeout = 60): array { ... }
    protected function sendStreamingRequest(string $url, array $data, array $headers, callable $callback): void { ... }
    abstract protected function getBaseUrl(): string;
    abstract protected function getHeaders(): array;
    abstract protected function handleErrorResponse(int $httpCode, array $response): never;
}

// OpenAiLlmProvider.php
class OpenAiLlmProvider extends AbstractApiClient implements LlmProviderInterface {
    protected function getBaseUrl(): string { return 'https://api.openai.com/v1'; }
    protected function getHeaders(): array { return ['Authorization: Bearer ' . $this->apiKey]; }
}
```

**Changes Required:**
1. Create `src/Support/AbstractApiClient.php` with shared HTTP logic (~80 lines)
2. Refactor `OpenAiLlmProvider` to extend and use parent methods (~30 lines changed)
3. Refactor `AnthropicLlmProvider` to extend and use parent methods (~30 lines changed)
4. Refactor `OpenAiEmbeddingProvider` to extend and use parent methods (~25 lines changed)
5. Add configurable timeout support

**Estimated Lines Changed:** ~150 lines consolidated into ~80 lines

---

### 2.5 Unify Section Base Classes

**Merge Into:** New `AbstractSection` base class

**Before:**
```php
// BasePromptSection.php - implements PromptSectionInterface
// BaseResponseSection.php - implements ResponseSectionInterface
```

**After:**
```php
// AbstractSection.php (new)
abstract class AbstractSection {
    protected string $name;
    protected int $priority;

    public function getName(): string { return $this->name; }
    public function getPriority(): int { return $this->priority; }
    public function shouldInclude(array $context, array $options): bool { return true; }
    protected function header(string $title): string { return "=== {$title} ===\n\n"; }
    protected function divider(): string { return "\n" . str_repeat('-', 40) . "\n\n"; }
}

// BasePromptSection.php
abstract class BasePromptSection extends AbstractSection implements PromptSectionInterface {}

// BaseResponseSection.php
abstract class BaseResponseSection extends AbstractSection implements ResponseSectionInterface {}
```

**Changes Required:**
1. Create `src/Services/Sections/AbstractSection.php` (~35 lines)
2. Refactor `BasePromptSection` to extend and delegate (~20 lines simplified)
3. Refactor `BaseResponseSection` to extend and delegate (~20 lines simplified)

**Estimated Lines Changed:** ~25 lines removed, ~35 lines added (net reduction with better structure)

---

### 2.6 Consolidate Chat History Methods

**Merge Into:** `ConversationContextManager` with configurable options

**Before:**
```php
// AiConversation.php
public function getRecentMessages(int $limit = 10) { ... }

// ConversationContextManager.php
public function buildPromptContext(AiConversation $conversation, int $maxHistory = 5) { ... }

// AiChatService.php
private function buildQuestionWithHistory(string $question, array $history, array $options) { ... }
```

**After:**
```php
// ConversationContextManager.php (enhanced)
public function getFormattedHistory(
    AiConversation $conversation,
    int $maxMessages = 10,
    int $contentTruncateLength = 200,
    string $format = 'prompt' // 'prompt', 'enriched', 'raw'
): array { ... }

// AiChatService.php - delegates to ConversationContextManager
// AiConversation.php - getRecentMessages() remains as data access only
```

**Changes Required:**
1. Add unified `getFormattedHistory()` method to `ConversationContextManager`
2. Refactor `AiChatService::buildQuestionWithHistory()` to use new method
3. Remove inline truncation logic from multiple places
4. Add configuration for truncation lengths

**Estimated Lines Changed:** ~50 lines consolidated

---

## 3. Similar Classes to Unify

### 3.1 SemanticMatcher vs ScopeSemanticMatcher

**Current State:**
- `SemanticMatcher` - General-purpose semantic matching (findBestMatch, matchEntities, matchScopes, matchLabel)
- `ScopeSemanticMatcher` - Specialized for scope matching with fallback

**Recommendation:** Keep both but clarify responsibilities

**After:**
```
SemanticMatcher:
  - findBestMatch() - Core matching logic (KEEP)
  - computeSimilarities() - Batch similarity calculation (KEEP)
  - matchEntities() - Entity detection (KEEP or MOVE to EntityMatcher)
  - matchScopes() - DELETE (use ScopeSemanticMatcher)
  - matchLabel() - EVALUATE (is it used?)

ScopeSemanticMatcher:
  - findMatchingScopes() - Scope detection with vector search + fallback (KEEP)
  - indexScopes() - Scope indexing (KEEP + enhance)
  - explainMatch() - Debug utility (KEEP)
```

---

### 3.2 Query/Response Prompt Builders

**Current State:**
- `SemanticPromptBuilder` - For query generation prompts
- `ResponseGenerator` - For response generation prompts

**Observation:** Both use `HasInternalModules` trait with identical patterns.

**Recommendation:** No merge needed - distinct purposes. Document the parallel architecture.

---

### 3.3 Theme Factories

**Current State:**
- `ConfigChatThemeFactory` - Creates themes from config
- `UserChatThemeFactory` - Creates themes from user preferences

**Observation:** Both implement `ChatThemeFactoryInterface` with similar logic.

**Recommendation:** Keep separate - different data sources. Consider extracting common theme building to a shared trait if needed.

---

## 4. Configuration Duplication

### 4.1 Hardcoded Limits Scattered Across Code

**Current State:**
| Location | What | Value |
|----------|------|-------|
| `AiConversation::getRecentMessages()` | History limit | 10 |
| `ConversationContextManager::buildPromptContext()` | Max history | 5 |
| `ConversationContextSection::format()` | Content truncate | 100/150 chars |
| `AiChatService::buildQuestionWithHistory()` | Content truncate | 200 chars |
| `ResultsDataSection` | Max data items | 10 |
| `SimilarQueriesSection` | Max queries | 3 |

**Recommendation:** Centralize in config

**Add to `config/ai.php`:**
```php
'context' => [
    'max_history_messages' => 10,
    'max_prompt_exchanges' => 5,
    'message_truncate_length' => 200,
    'user_message_truncate' => 100,
    'assistant_message_truncate' => 150,
],

'response_generation' => [
    'max_data_items' => 10,
    'max_similar_queries' => 3,
],
```

---

### 4.2 Collection Names Scattered Across Code

**Current State:**
| Class | Constant | Value |
|-------|----------|-------|
| `SemanticIndexer` | `COLLECTION_ENTITIES` | `semantic_entities` |
| `SemanticIndexer` | `COLLECTION_SCOPES` | `semantic_scopes` |
| `SemanticIndexer` | `COLLECTION_TEMPLATES` | `semantic_templates` |
| `ScopeSemanticMatcher` | `DEFAULT_COLLECTION_NAME` | `scope_examples` |
| `SemanticContextSelector` | `DEFAULT_COLLECTION_NAME` | `context_index` |
| `QdrantChunkStore` | config-based | `file_chunks` |

**Recommendation:** Centralize in config

**Add to `config/ai.php`:**
```php
'vector_collections' => [
    'entities' => 'semantic_entities',
    'scopes' => 'scope_examples',  // Merged, remove semantic_scopes
    'templates' => 'semantic_templates',
    'context' => 'context_index',
    'file_chunks' => 'file_chunks',
    'learned_queries' => 'learned_queries',
],
```

---

### 4.3 Threshold Values Scattered Across Code

**Current State:**
| Class | Constant/Default | Value |
|-------|------------------|-------|
| `SemanticMatcher::findBestMatch()` | parameter | 0.70 |
| `SemanticMatcher::matchEntities()` | parameter | 0.75 |
| `SemanticMatcher::matchScopes()` | parameter | 0.70 |
| `ScopeSemanticMatcher` | `DEFAULT_THRESHOLD` | 0.7 |
| `SemanticContextSelector` | `DEFAULT_THRESHOLD` | 0.65 |

**Recommendation:** Centralize in config

**Add to `config/ai.php`:**
```php
'semantic' => [
    'threshold' => [
        'entity_match' => 0.75,
        'scope_match' => 0.70,
        'context_match' => 0.65,
        'file_match' => 0.70,
    ],
    'top_k' => [
        'scopes' => 5,
        'context' => 10,
        'similar_queries' => 5,
    ],
],
```

---

## 5. Test Duplication

### 5.1 Similar Provider Test Patterns

**Files:**
- `tests/Unit/LlmProviders/OpenAiLlmProviderTest.php`
- `tests/Unit/LlmProviders/AnthropicLlmProviderTest.php`
- `tests/Unit/EmbeddingProviders/OpenAiEmbeddingProviderTest.php`

**What's Duplicated:**
- HTTP mock setup patterns
- Error response testing
- Configuration validation tests
- Timeout testing

**Recommendation:** Create shared test traits

**Create:**
```php
// tests/Traits/MocksHttpResponses.php
trait MocksHttpResponses {
    protected function mockCurlSuccess(string $response): void { ... }
    protected function mockCurlError(int $httpCode, string $message): void { ... }
    protected function assertRateLimitException(): void { ... }
}

// tests/Traits/TestsProviderConfiguration.php
trait TestsProviderConfiguration {
    /** @test */
    public function it_throws_on_missing_api_key(): void { ... }
    /** @test */
    public function it_uses_default_model_when_not_configured(): void { ... }
}
```

---

### 5.2 Section Test Patterns

**Files:**
- `tests/Unit/Services/PromptSections/*.php` (16 test files)
- `tests/Unit/Services/ResponseSections/*.php` (9 test files)

**What Could Be Shared:**
- Section rendering test patterns
- Priority checking tests
- `shouldInclude()` testing patterns

**Recommendation:** Create abstract test case

```php
// tests/TestCase/SectionTestCase.php
abstract class SectionTestCase extends TestCase {
    abstract protected function createSection(): PromptSectionInterface|ResponseSectionInterface;

    /** @test */
    public function it_has_a_name(): void {
        $section = $this->createSection();
        $this->assertNotEmpty($section->getName());
    }

    /** @test */
    public function it_has_a_priority(): void {
        $section = $this->createSection();
        $this->assertIsInt($section->getPriority());
    }
}
```

---

## 6. Step-by-Step Merge Instructions

### Phase 6.1: Quick Wins (Low Risk, High Value)

#### Step 1: Delete SemanticMatcher::matchScopes()
```bash
# Verify no usages
grep -r "matchScopes" src/ --include="*.php"

# Edit src/Services/SemanticMatcher.php
# Remove lines 195-253 (matchScopes method)
```

**Verification:**
```bash
php artisan test --filter=SemanticMatcher
```

#### Step 2: Consolidate Physical File Handling
```bash
# Edit src/Services/Context/FileContextProvider.php
# 1. Remove line 30 (PHYSICAL_PREFIX constant)
# 2. Remove isPhysicalFile() method
# 3. Replace calls to isPhysicalFile() with $this->accessResolver->isPhysicalFile()
# 4. Replace PHYSICAL_PREFIX with FileAccessResolver::PHYSICAL_PREFIX
```

**Verification:**
```bash
php artisan test --filter=FileContextProvider
php artisan test --filter=FileAccessResolver
```

#### Step 3: Remove Unused Trait Methods
```bash
# Edit src/Kompo/Traits/HasChatSettings.php
# Remove lines with methods: showAvatars, showTimestamps, showTyping, etc. (14 methods)

# Edit src/Kompo/Traits/HasChatTheme.php
# Remove lines with methods: themeGradient, themeLightGradient, etc. (10 methods)
# KEEP: mainHexColor() - it's used by ChatSettingsModal
```

**Verification:**
```bash
php artisan test --filter=Kompo
```

---

### Phase 6.2: HTTP Client Consolidation (Medium Risk)

#### Step 1: Create AbstractApiClient
```bash
# Create src/Support/AbstractApiClient.php
```

```php
<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

abstract class AbstractApiClient
{
    protected int $defaultTimeout = 60;
    protected int $streamingTimeout = 120;

    protected function sendRequest(
        string $url,
        array $data,
        array $headers,
        ?int $timeout = null
    ): array {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout ?? $this->defaultTimeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array_merge(
                ['Content-Type: application/json'],
                $headers
            ),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("cURL error: {$error}");
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $this->handleErrorResponse($httpCode, $decoded ?? []);
        }

        return $decoded;
    }

    protected function sendStreamingRequest(
        string $url,
        array $data,
        array $headers,
        callable $callback,
        ?int $timeout = null
    ): void {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_TIMEOUT => $timeout ?? $this->streamingTimeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array_merge(
                ['Content-Type: application/json'],
                $headers
            ),
            CURLOPT_WRITEFUNCTION => function($ch, $data) use ($callback) {
                $this->parseStreamChunk($data, $callback);
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        curl_close($ch);
    }

    abstract protected function handleErrorResponse(int $httpCode, array $response): never;
    abstract protected function parseStreamChunk(string $data, callable $callback): void;
}
```

#### Step 2: Refactor OpenAiLlmProvider
```bash
# Edit src/LlmProviders/OpenAiLlmProvider.php
# 1. Extend AbstractApiClient
# 2. Replace sendRequest() with call to parent
# 3. Implement abstract methods
```

#### Step 3: Refactor AnthropicLlmProvider
```bash
# Same pattern as OpenAI
```

**Verification:**
```bash
php artisan test --filter=LlmProvider
php artisan test --filter=EmbeddingProvider
```

---

### Phase 6.3: Scope Indexing Consolidation (Medium Risk)

#### Step 1: Enhance ScopeSemanticMatcher::indexScopes()
```bash
# Edit src/Services/ScopeSemanticMatcher.php
# Add description indexing from SemanticIndexer
```

```php
// In indexScopes() method, after indexing concept:
// Index description (from SemanticIndexer pattern)
if (!empty($scopeConfig['description'])) {
    $points[] = $this->createPoint(
        $entityName,
        $scopeName,
        $scopeConfig['description'],
        'description'
    );
}
```

#### Step 2: Remove SemanticIndexer::indexScopes()
```bash
# Edit src/Services/SemanticIndexer.php
# Remove indexScopes() method (lines 184-267)
# Update rebuildIndexes() to not call indexScopes()
```

#### Step 3: Update IndexSemanticCommand
```bash
# Edit src/Console/Commands/IndexSemanticCommand.php
# Remove scope indexing reference
# Add note to use ai:index-scopes command instead
```

**Verification:**
```bash
php artisan ai:index-scopes
php artisan test --filter=ScopeSemanticMatcher
```

---

### Phase 6.4: Configuration Centralization (Low Risk)

#### Step 1: Add New Config Keys
```bash
# Edit config/ai.php
# Add context, vector_collections, semantic sections
```

#### Step 2: Update Code to Use Config
```bash
# Each file that uses hardcoded values:
# Replace: const DEFAULT_THRESHOLD = 0.7;
# With:    config('ai.semantic.threshold.scope_match', 0.7)
```

**Files to Update:**
1. `SemanticMatcher.php`
2. `ScopeSemanticMatcher.php`
3. `SemanticContextSelector.php`
4. `ConversationContextManager.php`
5. `AiChatService.php`

---

### Phase 6.5: Section Base Class Unification (Low Risk)

#### Step 1: Create AbstractSection
```bash
# Create src/Services/Sections/AbstractSection.php
```

#### Step 2: Refactor Base Classes
```bash
# Edit src/Services/PromptSections/BasePromptSection.php
# Edit src/Services/ResponseSections/BaseResponseSection.php
# Both extend AbstractSection
```

---

### Phase 6.6: Test Consolidation (Optional)

#### Step 1: Create Test Traits
```bash
# Create tests/Traits/MocksHttpResponses.php
# Create tests/Traits/TestsProviderConfiguration.php
```

#### Step 2: Refactor Provider Tests
```bash
# Update LlmProvider tests to use traits
# Update EmbeddingProvider tests to use traits
```

---

## Summary

### Priority Order

| Priority | Task | Risk | Effort | Lines Affected |
|----------|------|------|--------|----------------|
| 1 | Delete SemanticMatcher::matchScopes() | Low | 10 min | -45 |
| 2 | Consolidate Physical File Handling | Low | 15 min | -15 |
| 3 | Remove Unused Trait Methods | Low | 20 min | -75 |
| 4 | Configuration Centralization | Low | 1 hour | +50, -30 |
| 5 | HTTP Client Consolidation | Medium | 2 hours | +80, -150 |
| 6 | Scope Indexing Consolidation | Medium | 1 hour | -80, +10 |
| 7 | Section Base Class Unification | Low | 30 min | +35, -25 |
| 8 | Test Consolidation | Low | 2 hours | Maintenance |

### Expected Outcomes

- **~300 lines of duplicate code eliminated**
- **5 overlapping collections consolidated to 4**
- **25+ unused methods removed**
- **Single source of truth for configuration values**
- **Consistent patterns for HTTP communication**
- **Reduced maintenance burden for future changes**

---

*Generated: 2025-12-30*
*Based on: Phase 2-5 Audit Documents*
