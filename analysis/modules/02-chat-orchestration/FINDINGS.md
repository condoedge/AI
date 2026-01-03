# Module 02: CHAT_ORCHESTRATION - Findings

> **Status:** COMPLETED
> **Last Updated:** Phase 4 (2026-01-03)

---

## Summary

The Chat Orchestration module consists of 4 key files that form the main entry points for AI interactions. **AiManager is confirmed as a god object** with 37 methods spanning 6 distinct responsibilities. The chat layer (AiChatService, SendMessageService) provides clean abstractions for conversation-based interactions. User context propagation is properly implemented. **Several public methods are never used in production code**.

---

## Issues Found

| ID | Severity | Category | Description | Evidence | Recommendation |
|----|----------|----------|-------------|----------|----------------|
| CO-001 | HIGH | God Object | AiManager has 37 methods across 6 responsibility areas | Lines 92-796: Data Ingestion, Context, Embedding, LLM, Query, Response methods | Split into focused facades or use trait-based composition |
| CO-002 | MEDIUM | Unused Code | `AI::ask()` never called in production | Only appears in Facade docblock | Remove or deprecate if not planned for use |
| CO-003 | MEDIUM | Unused Code | `AI::askQuestion()` only called in Facade docblock | Grep found only Facade reference | Document intended use case or remove |
| CO-004 | MEDIUM | Unused Code | `AI::stream()` never used | No usages found outside facade | Implement streaming UI or remove |
| CO-005 | MEDIUM | Unused Code | `AI::testQuery()` never used | No usages found | Consider removing or document use case |
| CO-006 | MEDIUM | Unused Code | `AI::sanitizeQuery()` never used in production | Only Facade reference | Document or remove |
| CO-007 | MEDIUM | Unused Code | `AI::detectQueryTemplate()` never used | No usages found | Remove or integrate into generateQuery |
| CO-008 | MEDIUM | Unused Code | `AI::getQueryTemplates()` never used | No usages found | Remove or document admin use case |
| CO-009 | MEDIUM | Unused Code | `AI::countTokens()` never used | No usages found | Remove or integrate into prompt building |
| CO-010 | MEDIUM | Unused Code | `AI::getLlm*()` and `AI::getEmbedding*()` methods never used | No usages found | Remove or document diagnostic use case |
| CO-011 | MEDIUM | Unused Code | `AI::explainQuery()`, `AI::executeCount()`, `AI::executePaginated()` never used | No usages found | Document intended use or remove |
| CO-012 | LOW | Inconsistent Error Handling | Only `answerQuestion()` has try/catch | Compare line 687-758 to other methods | Add consistent error handling across all methods |
| CO-013 | LOW | Service Locator | `retrieveFileContext()` uses `app()` instead of DI | Line 775: `app(\Condoedge\Ai\Services\Context\FileContextProvider::class)` | Inject FileContextProvider via constructor |
| CO-014 | LOW | Service Locator | `enrichResponseWithFiles()` uses `app()` | Line 793: `app(\Condoedge\Ai\Services\Response\ResponseFileEnricher::class)` | Inject ResponseFileEnricher via constructor |
| CO-015 | LOW | Hardcoded Default | Collection name 'questions' hardcoded | Line 223 | Move to config |
| CO-016 | LOW | Missing Interface | AiManager has no interface for mocking | Class has no implementing interface | Consider AiManagerInterface for testability |
| CO-017 | LOW | Duplicate Pipelines | `ask()`, `askQuestion()`, `answerQuestion()` are similar | Lines 516-528, 611-631, 685-759 | Consolidate into single configurable pipeline |

---

## File Analysis

### src/Services/AiManager.php (796 lines)

**Actual Responsibility:** Central facade/orchestrator for ALL AI operations - acts as a god object combining:
1. Data Ingestion (ingest, ingestBatch, remove, sync)
2. Context Retrieval (retrieveContext, searchSimilar, getSchema, getExampleEntities, storeQuery)
3. Embedding (embed, embedBatch, getEmbeddingDimensions, getEmbeddingModel)
4. LLM Chat (chat, chatJson, complete, stream, getLlmModel, getLlmProvider, getLlmMaxTokens, countTokens)
5. Query Generation (generateQuery, validateQuery, sanitizeQuery, getQueryTemplates, detectQueryTemplate, askQuestion)
6. Query Execution (executeQuery, executeCount, executePaginated, explainQuery, testQuery, ask)
7. Response Generation (generateResponse, extractInsights, suggestVisualizations, answerQuestion)
8. File Context (retrieveFileContext, enrichResponseWithFiles)

**Public Methods (35):**
```
ingest(Nodeable): array
ingestBatch(array): array
remove(Nodeable): bool
sync(Nodeable): array
retrieveContext(string, array): array
searchSimilar(string, array): array
getSchema(): array
getExampleEntities(array, int): array
storeQuery(string, string, array, string): array
embed(string): array
embedBatch(array): array
getEmbeddingDimensions(): int
getEmbeddingModel(): string
chat(string|array, array): string
chatJson(string|array, array): object|array
complete(string, ?string, array): string
stream(array, callable, array): void
getLlmModel(): string
getLlmProvider(): string
getLlmMaxTokens(): int
countTokens(string): int
generateQuery(string, array, array): array
validateQuery(string, array): array
sanitizeQuery(string): string
getQueryTemplates(): array
detectQueryTemplate(string): ?string
askQuestion(string, array): array
executeQuery(string, array, array): array
executeCount(string, array, array): int
executePaginated(string, int, int, array, array): array
explainQuery(string, array): array
testQuery(string): bool
ask(string, array): array
generateResponse(string, array, string, array): array
extractInsights(array): array
suggestVisualizations(array, string): array
answerQuestion(string, array): array
```

**Protected Methods (2):**
```
retrieveFileContext(string, mixed): array
enrichResponseWithFiles(array, array, array): array
```

**Constructor Dependencies (8):**
| Dependency | Interface | Used By |
|------------|-----------|---------|
| $ingestion | DataIngestionServiceInterface | ingest, ingestBatch, remove, sync |
| $context | ContextRetrieverInterface | retrieveContext, searchSimilar, getSchema, getExampleEntities |
| $embedding | EmbeddingProviderInterface | embed, embedBatch, getEmbeddingDimensions, getEmbeddingModel, storeQuery |
| $llm | LlmProviderInterface | chat, chatJson, complete, stream, getLlm*, countTokens |
| $queryGenerator | QueryGeneratorInterface | generateQuery, validateQuery, sanitizeQuery, getQueryTemplates, detectQueryTemplate |
| $queryExecutor | QueryExecutorInterface | executeQuery, executeCount, executePaginated, explainQuery, testQuery |
| $responseGenerator | ResponseGeneratorInterface | generateResponse, extractInsights, suggestVisualizations, answerQuestion |
| $vectorStore | VectorStoreInterface | storeQuery |

**Who Uses It:**
- AI Facade (facade accessor)
- AiServiceProvider (registers as singleton 'ai')
- AiChatService (via AI::answerQuestion(), AI::getSchema())
- Jobs (IngestEntityJob, SyncEntityJob, RemoveEntityJob via AI facade)
- Observers (RelatedModelSyncObserver)
- Traits (HasNodeableConfig)
- Tests (Feature tests)

---

### src/Services/Chat/AiChatService.php (233 lines)

**Actual Responsibility:** Bridge between chat UI and core AI system with conversation context management.

**Public Methods (4):**
```
__construct(array $config = [])
getContextManager(): ConversationContextManager
askWithConversation(string $question, AiConversation $conversation, array $options = []): array
isAvailable(): bool
getSchema(): array
```

**Dependencies:**
- AI Facade (via `use Condoedge\Ai\Facades\AI`)
- ConversationContextManager (lazy-loaded)
- EntityExtractor (via ConversationContextManager)
- ReferenceResolver (via ConversationContextManager)
- Log facade

**Who Uses It:**
- SendMessageService (via constructor injection)
- AiServiceProvider (registered as AiChatServiceInterface singleton)
- ChatMessageForm (indirectly via SendMessageService)

**Key Flow (askWithConversation):**
1. Get schema for entity extraction
2. Process question through context system (extract entities, resolve references)
3. Build conversation context for prompt
4. Use enriched question if references were resolved
5. Store user message in conversation
6. Call AI::answerQuestion() with full context
7. Record response via contextManager
8. Store assistant message in conversation
9. Return structured response

---

### src/Services/Chat/AiChatServiceInterface.php (59 lines)

**Actual Responsibility:** Contract for chat service operations.

**Methods Defined:**
```
askWithConversation(string $question, AiConversation $conversation, array $options = []): array
getSchema(): array
isAvailable(): bool
```

**Implementations:**
- AiChatService (single implementation)

---

### src/Services/Chat/SendMessageService.php (55 lines)

**Actual Responsibility:** Thin validation layer for sending messages - validates input then delegates to AiChatService.

**Public Methods (1):**
```
sendMessage(AiConversation $conversation, string $message, array $options = []): array
```

**Dependencies:**
- AiChatService (constructor injection)

**Who Uses It:**
- ChatMessageForm (via app(SendMessageService::class))
- Tests (SendMessageServiceTest)

**Key Flow:**
1. Trim message
2. Validate not empty (throws InvalidArgumentException)
3. Delegate to aiChatService->askWithConversation()

---

## Call Graph

```
User Input (ChatMessageForm)
    |
    v
SendMessageService::sendMessage()
    |-- Validates: message not empty
    |
    v
AiChatService::askWithConversation()
    |-- getSchema() --> AI::getSchema()
    |-- getContextManager() --> ConversationContextManager
    |-- contextManager.processQuestion()
    |-- contextManager.buildPromptContext()
    |-- conversation.addMessage('user', ...)
    |
    v
AI::answerQuestion() [AiManager]
    |-- retrieveContext()
    |   |-- context.retrieveContext()
    |
    |-- retrieveFileContext() [if enabled]
    |   |-- app(FileContextProvider::class)
    |
    |-- generateQuery()
    |   |-- queryGenerator.generate()
    |   |-- storeQuery() [if successful]
    |
    |-- executeQuery()
    |   |-- queryExecutor.execute()
    |
    |-- generateResponse()
    |   |-- responseGenerator.generate()
    |
    |-- enrichResponseWithFiles() [if file_context]
    |   |-- app(ResponseFileEnricher::class)
    |
    v
Return to AiChatService
    |-- contextManager.recordResponse()
    |-- conversation.addMessage('assistant', ...)
    |
    v
Return structured response to UI
```

---

## Dependency Analysis

### Injected Dependencies (Proper DI)
All 8 AiManager constructor dependencies are properly injected via the service provider.

### Service Locator Anti-Pattern
Two methods use `app()` instead of constructor injection:
- `retrieveFileContext()` - resolves FileContextProvider
- `enrichResponseWithFiles()` - resolves ResponseFileEnricher

### Facade Dependencies
- AiChatService depends on AI facade rather than injecting AiManager

---

## User Context Propagation

User context IS properly propagated:
1. ChatMessageForm passes `auth()->user()` in options
2. SendMessageService passes options through to AiChatService
3. AiChatService passes `options['user']` to AI::answerQuestion()
4. AiManager passes to retrieveFileContext() and generateResponse()
5. FileContextProvider uses user for access control

**Evidence:** Lines 112, 170-172, 225-228 in ChatMessageForm; Line 112 in AiChatService; Line 698, 772 in AiManager

---

## Recommendations

### Critical (Address First)
1. **Split AiManager** into focused services or use traits:
   - `AiIngestionFacade` - ingest, ingestBatch, remove, sync
   - `AiContextFacade` - retrieveContext, searchSimilar, getSchema, storeQuery
   - `AiLlmFacade` - chat, chatJson, complete, stream
   - `AiQueryFacade` - generateQuery, executeQuery, ask, answerQuestion
   - Keep AiManager as a composition of these facades

2. **Remove unused methods** (or document intended use):
   - ask(), askQuestion() (if answerQuestion() is the preferred API)
   - stream(), testQuery(), sanitizeQuery()
   - detectQueryTemplate(), getQueryTemplates(), countTokens()
   - getLlm*(), getEmbedding*() info methods

### High Priority
3. **Fix service locator pattern** - inject FileContextProvider and ResponseFileEnricher via constructor

4. **Add consistent error handling** - wrap all public methods in try/catch with proper logging

5. **Consolidate pipelines** - merge ask(), askQuestion(), answerQuestion() into one configurable method

### Medium Priority
6. **Create AiManagerInterface** for better testability
7. **Move hardcoded 'questions' collection** to config
8. **Add debug/info logging** for request tracing

---

## Metrics

| Metric | Value |
|--------|-------|
| Total Lines | ~1,104 (796 + 233 + 59 + 55) |
| Total Public Methods | 41 |
| Unused Public Methods | ~15 (36%) |
| Dependencies (AiManager) | 8 |
| Service Locator Calls | 2 |
| Error Handling Coverage | 1/35 methods (3%) |
