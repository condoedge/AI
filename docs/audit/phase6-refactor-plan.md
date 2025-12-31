# Phase 6: Architectural Refactor Plan

**Date:** 2025-12-30
**Based on:** Phase 2-5 Audit Documents
**Status:** Actionable Implementation Plan

---

## Executive Summary

This document provides a prioritized refactoring plan to address architectural issues, security vulnerabilities, and technical debt identified during the comprehensive audit. The plan is organized by priority: critical security fixes first, followed by architectural improvements, and finally cleanup tasks.

**Total Refactors Identified:** 42
- **Critical (Security):** 6
- **High (Architectural):** 12
- **Medium (Technical Debt):** 14
- **Low (Cleanup):** 10

---

## 1. Architectural Boundary Violations

### 1.1 UI Layer Directly Accessing Persistence Layer

| Violation | File | Crosses To | Severity |
|-----------|------|------------|----------|
| V1.1 | `AiChatPanel.php` | `AiConversation` model | HIGH |
| V1.2 | `ChatMessageForm.php` | `AiMessage` model | HIGH |
| V1.3 | `ChatSettingsModal.php` | `AiUserSetting` model | MEDIUM |
| V1.4 | `ConversationController.php` | All models | MEDIUM |

**Current State:**
```php
// AiChatPanel.php - UI component directly queries database
$this->conversation = AiConversation::where('user_id', auth()->id())
    ->where('id', $this->conversationId)
    ->first();
```

**Recommended Fix:**
Introduce a `ConversationRepository` interface and implementation to encapsulate all conversation persistence operations.

```php
// ConversationRepositoryInterface.php
interface ConversationRepositoryInterface
{
    public function findForUser(int $userId, int $conversationId): ?AiConversation;
    public function create(int $userId, array $attributes): AiConversation;
    public function archive(AiConversation $conversation): bool;
    public function delete(AiConversation $conversation): bool;
}

// AiChatPanel.php - Clean dependency
$this->conversation = app(ConversationRepositoryInterface::class)
    ->findForUser(auth()->id(), $this->conversationId);
```

**Rationale:** UI components should not know about Eloquent queries. Repository pattern enables testing, caching, and consistent access control.

---

### 1.2 ChatMessageForm Bypasses ConversationContextManager

**Current State:**
The `ChatMessageForm::sendMessage()` method calls `AiChatService::askWithHistory()` which uses simple string concatenation for history, completely bypassing the sophisticated `ConversationContextManager` that provides:
- Entity focus tracking
- Reference resolution ("those", "them", "the same")
- Automatic message storage
- Context enrichment

**Evidence from phase3-flow-chat.md:**
```
CALLED: askWithHistory() at line 124
NOT CALLED: askWithConversation() at line 183 - This method exists but is NEVER invoked from the UI!
```

**Fix:** Update `ChatMessageForm` to use `askWithConversation()` (see Section 4 - Conversation Context Refactor).

---

### 1.3 God Class: AiChatPanel

**Responsibilities Currently Mixed:**
1. UI rendering (bubbles, layout, styling)
2. Conversation lifecycle (create, archive, delete)
3. Message regeneration logic
4. Settings access and theme management
5. Suggestion handling
6. Export functionality

**Recommended Split:**

| Component | Responsibilities |
|-----------|-----------------|
| `AiChatPanel` | UI rendering only |
| `ConversationService` | Lifecycle management |
| `MessageService` | Regeneration, quick actions |
| Existing: `ChatSettingsInterface` | Already handles settings |

**Implementation Approach:**
1. Create `ConversationService` with methods: `create()`, `archive()`, `delete()`, `pin()`
2. Move regeneration logic to `MessageService::regenerate()`
3. AiChatPanel becomes thin UI layer calling services

---

### 1.4 God Class: AiServiceProvider

**Current State:** 600+ lines registering every service in the package.

**Recommended Split:**

| Provider | Services Registered |
|----------|-------------------|
| `AiCoreServiceProvider` | AiManager, LLM/Embedding providers |
| `AiPersistenceServiceProvider` | GraphStore, VectorStore, DataIngestionService |
| `AiContextServiceProvider` | ContextRetriever, ConversationContextManager |
| `AiChatServiceProvider` | AiChatService, Settings, Themes |
| `AiDiscoveryServiceProvider` | Discovery services, commands |

**Rationale:** Follows Laravel convention of focused providers, enables partial loading.

---

## 2. Security Fixes Required

### 2.1 CRITICAL: Potential Cypher Injection in Templates

**Location:** `QueryGenerator.php:129-134` (template bypass)

**Issue:**
Template-matched queries bypass LLM and RAG context entirely. If template patterns extract user input into queries without sanitization, injection is possible.

**Current Code:**
```php
// Template extraction - user input directly used
$label = $this->extractLabelFromQuestion($question); // From user input
$cypher = "MATCH (n:{$label}) RETURN n LIMIT 100"; // Direct interpolation
```

**Evidence from phase3-flow-query.md:**
> "Templates match simple patterns and **completely bypass LLM and RAG context**...No injection protection - Relies on LLM not generating malicious queries"

**Fix Required:**
```php
// Use CypherSanitizer for all template-generated queries
$safeLabel = CypherSanitizer::escapeLabel($label);
$cypher = "MATCH (n:{$safeLabel}) RETURN n LIMIT 100";

// Validate label exists in schema before using
if (!in_array($label, $this->graphStore->getSchema()['labels'])) {
    throw new InvalidArgumentException("Unknown label: {$label}");
}
```

**Priority:** CRITICAL - External input flows into Cypher queries

---

### 2.2 CRITICAL: Missing Authentication in File Access

**Location:** `FileContextProvider.php:57-124`

**Issue:**
`AiManager::answerQuestion()` passes `$options['user'] ?? null` to file context retrieval. When null, file access security is bypassed.

**Current Code:**
```php
// AiManager.php:698
$fileContext = $this->retrieveFileContext($question, $options['user'] ?? null);
```

**Evidence from phase3-flow-chat.md:**
> "User Not Passed to File Context...`options['user']` is never set in the chat flow"

**Fix Required:**
```php
// ChatMessageForm.php - Pass authenticated user
$response = $aiManager->askWithConversation($message, $this->conversation, [
    'style' => $style,
    'user' => auth()->user(), // ADD THIS
]);

// FileContextProvider.php - Require user when security enabled
if ($this->accessResolver->shouldEnforceSecurity() && !$user) {
    throw new \RuntimeException('User required for file context retrieval');
}
```

**Priority:** CRITICAL - Potential unauthorized file access

---

### 2.3 HIGH: Neo4j Query Without Parameter Binding

**Location:** `Neo4jStore.php` - Multiple methods

**Issue:**
Some queries use string interpolation instead of parameterized queries.

**Evidence from phase2-graph-store.md:**
Node creation uses `arrayToCypherProps()` which builds property strings:
```php
$propsStr = $this->arrayToCypherProps($properties);
$cypher = "CREATE (n:{$safeLabel} {$propsStr}) RETURN id(n)";
```

**Fix Required:**
Always use parameter binding:
```php
$cypher = "CREATE (n:{$safeLabel}) SET n = \$properties RETURN id(n)";
$result = $this->query($cypher, ['properties' => $properties]);
```

**Priority:** HIGH - Potential injection vector

---

### 2.4 HIGH: Missing Rate Limit on Query Execution

**Location:** `QueryExecutor.php`

**Issue:**
While `QueryGenerator` has rate limiting, the execution path does not. Malicious actors could flood the database with expensive queries.

**Fix Required:**
```php
// QueryExecutor.php
public function execute(string $cypherQuery, array $parameters = [], array $options = []): array
{
    // Add rate limiting before execution
    if (!$this->rateLimiter->waitAndAttempt(10)) {
        throw new QueryExecutionException('Rate limit exceeded for query execution');
    }
    // ... existing logic
}
```

**Priority:** HIGH - DoS prevention

---

### 2.5 MEDIUM: Sensitive Data in Logs

**Location:** Multiple services log full queries and parameters

**Issue:**
Query logs may contain sensitive data (customer names, emails, etc.)

**Fix Required:**
```php
// Use SensitiveDataSanitizer before logging
$sanitized = SensitiveDataSanitizer::sanitize($parameters);
Log::info('Executing query', ['params' => $sanitized]);
```

**Priority:** MEDIUM - Privacy/compliance concern

---

### 2.6 MEDIUM: No CSRF on Chat Endpoints

**Location:** `ChatMessageForm.php` - selfPost endpoints

**Recommendation:** Verify Kompo's CSRF protection is active. Add explicit token validation if needed.

**Priority:** MEDIUM - Standard web security

---

## 3. Return Type Inconsistencies

### 3.1 Interface vs Implementation Mismatches

| Interface | Method | Declared Return | Implementation Returns |
|-----------|--------|-----------------|----------------------|
| `VectorStoreInterface` | `listCollections()` | Not declared | `array` |
| `GraphStoreInterface` | `beginTransaction()` | `mixed` | Transaction object |
| `AiChatServiceInterface` | `askWithConversation()` | `AiChatMessage` | Returns `array` in practice |
| `FileProcessorInterface` | `processFile()` | `ProcessingResult` | Sometimes `bool` |

**Fix for VectorStoreInterface:**
```php
// Add return type
public function listCollections(): array;
```

**Fix for AiChatServiceInterface:**
```php
// Interface declares array return (current actual behavior)
public function askWithConversation(
    string $question,
    AiConversation $conversation,
    array $options = []
): array; // Not AiChatMessage
```

**Rationale:** Type safety prevents runtime errors and enables static analysis.

---

### 3.2 ProcessingResult.fileId Type Mismatch

**Location:** `src/DTOs/ProcessingResult.php:27`

**Issue:**
```php
public readonly int $fileId,  // But physical files have string IDs like "physical:docs/readme.md"
```

**Fix:**
```php
public readonly string|int $fileId,
```

**Evidence from phase3-flow-ingestion.md:**
> "Physical file IDs are strings (e.g., 'physical:docs/readme.md'), but `ProcessingResult->fileId` is typed as `int`"

---

## 4. Conversation Context Refactor

**Source:** `conversation-context-refactor-recommendation.md`

This is the primary architectural refactor that addresses the bypassed context system.

### 4.1 Phase 1: Delete Dead Code

**Files to DELETE:**
| File | Reason |
|------|--------|
| `src/Services/Chat/AmbiguityDetector.php` | Built but never integrated |
| `src/Domain/Contracts/Searchable.php` | Never implemented |

**Methods to DELETE from `AiChatService.php`:**
| Method | Reason |
|--------|--------|
| `askWithHistory()` | Replaced by `askWithConversation()` |
| `buildQuestionWithHistory()` | Only used by deleted method |
| `ask()` | Redundant with `askWithConversation()` |
| `getSuggestions()` | AI response includes suggestions |
| `getExampleQuestions()` | Panel uses settings directly |

**Traits to DELETE:**
| Trait | Reason |
|-------|--------|
| `src/Kompo/Traits/HasTypingIndicator.php` | Included but never used |

---

### 4.2 Phase 2: Wire UI to askWithConversation()

**Update ChatMessageForm.php:**

```php
// CURRENT (broken context):
$history = $this->conversation->getRecentMessages(10);
$response = app(AiChatServiceInterface::class)->askWithHistory(
    $message,
    $history,
    ['style' => $style]
);

// REFACTORED (full context):
$response = app(AiChatServiceInterface::class)->askWithConversation(
    $message,
    $this->conversation,
    [
        'style' => $style,
        'user' => auth()->user(),
    ]
);

// Response is now array with documented structure:
$responseContent = $response['answer'] ?? 'I could not generate a response.';
$responseData = $response['data'] ?? [];
$suggestions = $response['suggestions'] ?? [];
$cypherQuery = $response['cypher_query'] ?? null;
```

---

### 4.3 Phase 3: Enhanced Context Storage

**Extend AiConversation Model:**

```php
// Store result samples for "those customers" type references
public function updateEntityContext(array $entityData): void
{
    $snapshot = $this->context_snapshot ?? [];
    $snapshot['focused_entity_data'] = $entityData;
    $snapshot['last_result_sample'] = array_slice($entityData, 0, 5);
    $snapshot['updated_at'] = now()->toIso8601String();
    $this->update(['context_snapshot' => $snapshot]);
}

public function getLastResultSample(): array
{
    return $this->context_snapshot['last_result_sample'] ?? [];
}
```

**Extend ConversationContextManager:**

```php
public function recordResponse(
    AiConversation $conversation,
    string $answer,
    ?string $cypherQuery,
    array $queryResult
): void {
    $snapshot = $conversation->context_snapshot ?? [];

    // Extract filter from WHERE clause for follow-up queries
    $entityFilter = $this->extractEntityFilter($cypherQuery);

    // Store result sample (enables "those", "them" references)
    $resultSample = array_slice($queryResult['data'] ?? [], 0, 3);

    $snapshot['last_cypher_query'] = $cypherQuery;
    $snapshot['last_result_count'] = count($queryResult['data'] ?? []);
    $snapshot['last_result_sample'] = $resultSample;
    $snapshot['focused_entity_filter'] = $entityFilter;

    $conversation->updateContextSnapshot($snapshot);
}
```

---

### 4.4 Phase 4: Update Interface

**New AiChatServiceInterface.php:**

```php
interface AiChatServiceInterface
{
    /**
     * Ask a question within a conversation context.
     *
     * @param string $question The user's question
     * @param AiConversation $conversation The conversation for context tracking
     * @param array $options Options including 'style', 'user'
     * @return array{
     *   answer: string,
     *   data: array,
     *   suggestions: array,
     *   sources: array,
     *   cypher_query: ?string
     * }
     */
    public function askWithConversation(
        string $question,
        AiConversation $conversation,
        array $options = []
    ): array;

    public function getSchema(): array;
}
```

---

## 5. Missing Resilience Patterns

### 5.1 Services Without CircuitBreaker

| Service | Risk | Fix |
|---------|------|-----|
| `QueryExecutor` | Neo4j outage cascades | Wrap graph queries with CircuitBreaker |
| `EmbeddingProviders` | API failures cascade | Add circuit breaker to embed calls |
| `FileContextProvider` | Vector store failures | Graceful degradation needed |

**Implementation for QueryExecutor:**

```php
public function __construct(
    private GraphStoreInterface $graphStore,
    private CircuitBreaker $circuitBreaker,
    private RetryPolicy $retryPolicy
) {}

public function execute(string $cypherQuery, ...): array
{
    return $this->circuitBreaker->call(function () use ($cypherQuery, $parameters) {
        return $this->retryPolicy->execute(
            fn() => $this->graphStore->query($cypherQuery, $parameters),
            fn($e, $attempt) => Log::warning("Query retry {$attempt}", ['error' => $e->getMessage()])
        );
    });
}
```

---

### 5.2 Services Without RetryPolicy

| Service | Operation | Retry Needed |
|---------|-----------|--------------|
| `OpenAiLlmProvider` | API calls | Yes - transient failures common |
| `OpenAiEmbeddingProvider` | embed/embedBatch | Yes - rate limits |
| `QdrantStore` | All operations | Yes - network issues |

**Standard Pattern:**

```php
$this->retryPolicy = RetryPolicy::forApiCalls(); // 3 attempts, exponential backoff
```

---

### 5.3 Missing Graceful Degradation

| Component | Failure Mode | Current | Should Be |
|-----------|--------------|---------|-----------|
| File context | Vector search fails | Exception | Return empty context |
| Similar queries | No matches | Empty array | Works correctly |
| Semantic context | Embedding fails | Exception | Skip semantic, use keyword |

**Fix for FileContextProvider:**

```php
public function getFileContext(string $question, mixed $user): array
{
    try {
        return $this->searchRelevantFiles($question, $user);
    } catch (\Exception $e) {
        Log::warning('File context retrieval failed', ['error' => $e->getMessage()]);
        return ['relevant_files' => [], 'error' => 'File context unavailable'];
    }
}
```

---

## 6. Integration Points to Wire Up

### 6.1 QueryLearner - Never Connected

**Status:** Class exists, tests exist, but never called from pipeline.

**What it does:**
- Stores successful Q&A pairs
- Enables finding similar past queries
- Could improve accuracy over time

**Wire-up point in AiManager:**

```php
// After successful query generation and execution
if ($generation['confidence'] >= 0.8 && $executionResult['success']) {
    $this->queryLearner->learn(
        $question,
        $generation['cypher'],
        $executionResult['data']
    );
}
```

**Dependency:** Requires `AiQueryLog` to be populated first.

---

### 6.2 AiQueryLog - Never Populated

**Status:** Model exists, table exists, but `logSuccess()`/`logFailure()` never called.

**Wire-up point in QueryExecutor:**

```php
public function execute(...): array
{
    $startTime = microtime(true);

    try {
        $result = $this->doExecute($cypherQuery, $parameters);

        AiQueryLog::logSuccess(
            question: $options['original_question'] ?? 'N/A',
            cypherQuery: $cypherQuery,
            executionTime: (microtime(true) - $startTime) * 1000,
            resultCount: count($result['data']),
            templateUsed: $options['template'] ?? null
        );

        return $result;
    } catch (\Exception $e) {
        AiQueryLog::logFailure(
            question: $options['original_question'] ?? 'N/A',
            cypherQuery: $cypherQuery,
            error: $e->getMessage()
        );
        throw $e;
    }
}
```

---

### 6.3 QueryResultCache - Never Registered

**Status:** Class exists, but not bound in service provider.

**Wire-up in AiServiceProvider:**

```php
$this->app->singleton(QueryResultCache::class, function ($app) {
    return new QueryResultCache(
        $app->make(Cache::class),
        config('ai.cache.ttl', 3600)
    );
});
```

**Add config keys:**
```php
// config/ai.php
'cache' => [
    'enabled' => env('AI_CACHE_ENABLED', true),
    'ttl' => env('AI_CACHE_TTL', 3600),
    'prefix' => 'ai_query_',
],
```

---

### 6.4 TeamFilteredQuery - Built But Not Integrated

**Status:** Methods exist for team-based security filtering, but not called from main pipeline.

**Wire-up in ContextRetriever:**

```php
public function retrieveContext(string $question, array $options = []): array
{
    $user = $options['user'] ?? auth()->user();

    // Apply team filtering to entity retrieval
    $teamFilter = TeamFilteredQuery::forUser($user);

    // Use filter in example entity retrieval
    $exampleEntities = $this->getExampleEntities($label, 3, $teamFilter);

    // ...
}
```

---

### 6.5 Config Key Mismatches to Fix

| Used Key | Actual Config Key | Fix |
|----------|------------------|-----|
| `ai.chat.show_typing` | `ai.chat.show_typing_indicator` | Rename config to `show_typing` |
| `ai.chat.welcome_title` | `ai.chat.welcome.title` | Flatten to `welcome_title` |
| `ai.chat.welcome_message` | `ai.chat.welcome.message` | Flatten to `welcome_message` |

**Evidence from phase3-flow-settings.md:**
> "UserChatSettings looks for `ai.chat.show_typing` but Config defines `ai.chat.show_typing_indicator`"

---

### 6.6 ChatSettingsModal - Wrong Column Access

**Issue:** Modal saves to individual columns that don't exist.

**Current (broken):**
```php
session()->put('ai_chat_settings', [
    'show_avatars' => $this->model->show_avatars,  // Column doesn't exist!
]);
```

**Fix:**
```php
// Fields should save to chat_settings JSON column
_Toggle()->name('chat_settings.show_avatars')

// Session sync should read from JSON
$chatSettings = $this->model->chat_settings ?? [];
session()->put('ai_chat_settings', $chatSettings);
```

---

## 7. Priority Ordering

### 7.1 Critical - Security Fixes (Week 1)

| # | Item | Effort | Impact |
|---|------|--------|--------|
| S1 | Cypher injection in templates (2.1) | 4h | Prevents data breach |
| S2 | Missing user in file context (2.2) | 2h | Prevents unauthorized access |
| S3 | Neo4j parameter binding (2.3) | 4h | Prevents injection |
| S4 | Rate limit on execution (2.4) | 2h | Prevents DoS |

---

### 7.2 High - Architectural Fixes (Week 2-3)

| # | Item | Effort | Impact |
|---|------|--------|--------|
| A1 | Wire ChatMessageForm to askWithConversation (4.2) | 4h | Enables context tracking |
| A2 | Delete dead code (4.1) | 2h | Reduces confusion |
| A3 | Fix return type mismatches (3.1, 3.2) | 2h | Type safety |
| A4 | Add CircuitBreaker to QueryExecutor (5.1) | 3h | Resilience |
| A5 | Wire AiQueryLog population (6.2) | 3h | Enables analytics |
| A6 | Fix ChatSettingsModal columns (6.6) | 2h | Settings actually work |
| A7 | Fix config key mismatches (6.5) | 1h | Settings cascade works |

---

### 7.3 Medium - Technical Debt (Week 4-5)

| # | Item | Effort | Impact |
|---|------|--------|--------|
| T1 | Create ConversationRepository (1.1) | 8h | Clean architecture |
| T2 | Enhanced context storage (4.3) | 6h | Better follow-up queries |
| T3 | Add RetryPolicy to providers (5.2) | 4h | Reliability |
| T4 | Graceful degradation (5.3) | 4h | User experience |
| T5 | Wire QueryLearner (6.1) | 4h | Continuous improvement |
| T6 | Wire TeamFilteredQuery (6.4) | 4h | Multi-tenant security |
| T7 | Register QueryResultCache (6.3) | 2h | Performance |

---

### 7.4 Low - Cleanup (Ongoing)

| # | Item | Effort | Impact |
|---|------|--------|--------|
| C1 | Split AiServiceProvider (1.4) | 6h | Maintainability |
| C2 | Extract ConversationService (1.3) | 8h | SRP compliance |
| C3 | Remove HasChatSettings shortcuts | 1h | Cleaner code |
| C4 | Remove discovery test helpers | 1h | Cleaner production code |
| C5 | Update interface docblocks | 2h | Documentation |

---

## 8. Implementation Checklist

### Week 1: Security Sprint

- [ ] S1: Add CypherSanitizer to template generation
- [ ] S1: Validate labels against schema before use
- [ ] S2: Pass `auth()->user()` in ChatMessageForm
- [ ] S2: Add user requirement check in FileContextProvider
- [ ] S3: Refactor Neo4jStore to use parameter binding everywhere
- [ ] S4: Add RateLimiter to QueryExecutor
- [ ] Write security regression tests

### Week 2: Context Integration

- [ ] A1: Update ChatMessageForm to call askWithConversation()
- [ ] A2: Delete AmbiguityDetector.php, Searchable.php
- [ ] A2: Remove dead methods from AiChatService
- [ ] A3: Fix VectorStoreInterface::listCollections() return type
- [ ] A3: Fix ProcessingResult::fileId type
- [ ] A6: Fix ChatSettingsModal to use chat_settings JSON
- [ ] A7: Rename config keys for consistency

### Week 3: Resilience

- [ ] A4: Inject CircuitBreaker into QueryExecutor
- [ ] A5: Add AiQueryLog::logSuccess/logFailure calls
- [ ] T3: Add RetryPolicy to OpenAiLlmProvider
- [ ] T3: Add RetryPolicy to OpenAiEmbeddingProvider
- [ ] T4: Add try-catch with graceful degradation to FileContextProvider

### Week 4-5: Architecture

- [ ] T1: Create ConversationRepositoryInterface
- [ ] T1: Create EloquentConversationRepository
- [ ] T1: Update AiChatPanel to use repository
- [ ] T2: Implement enhanced context storage
- [ ] T2: Update ConversationContextSection for new format
- [ ] T5: Wire QueryLearner into AiManager
- [ ] T6: Integrate TeamFilteredQuery into ContextRetriever

### Ongoing: Cleanup

- [ ] C1: Split AiServiceProvider into feature providers
- [ ] C2: Extract ConversationService from AiChatPanel
- [ ] C3: Remove unused shorthand methods
- [ ] C4: Remove test helpers from production code
- [ ] C5: Update interface documentation

---

## 9. Success Metrics

| Metric | Current | Target |
|--------|---------|--------|
| Static analysis errors | Unknown | 0 |
| Security vulnerabilities | 4 critical | 0 |
| Dead code files | 4 | 0 |
| Dead code methods | 35+ | 0 |
| Return type mismatches | 4 | 0 |
| Context system usage | Bypassed | Active |
| Query logging | 0% | 100% |
| Cache hit rate | N/A | >50% |

---

## 10. Risk Mitigation

| Risk | Mitigation |
|------|------------|
| Breaking changes to ChatMessageForm | Feature flag for old vs new flow |
| AiQueryLog table grows too large | Add retention policy, archive old logs |
| CircuitBreaker opens too aggressively | Start with lenient thresholds, tune based on monitoring |
| Deleting dead code removes needed functionality | Comprehensive test coverage before deletion |

---

## Appendix: Source Documents

| Document | Key Findings |
|----------|--------------|
| `conversation-context-refactor-recommendation.md` | Main context system refactor |
| `phase3-flow-chat.md` | askWithConversation never called |
| `phase3-flow-query.md` | Template injection risk |
| `phase3-flow-settings.md` | Config key mismatches |
| `phase3-dead-code.md` | 78+ dead code items |
| `phase4-categories.md` | Boundary violations |
| `phase5-ai-audit.md` | Missing feedback, unused data |
| `phase2-contracts.md` | Return type issues |

---

*Generated: 2025-12-30*
*Total Refactors: 42*
*Estimated Total Effort: 80-100 hours*
