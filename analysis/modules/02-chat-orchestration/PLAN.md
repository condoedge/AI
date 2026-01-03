# Module 02: CHAT_ORCHESTRATION - Analysis Plan

> **Module Slug:** chat-orchestration
> **Priority:** CRITICAL (Central orchestrator for all AI operations)
> **Estimated Files:** 4

---

## 1. Ideal vs Actual Responsibility

### Ideal Responsibility
- Orchestrate complete chat flow from user message to AI response
- Coordinate context retrieval, query generation, execution, and response generation
- Manage conversation state and message persistence
- Handle errors gracefully with user-friendly messages
- Delegate to specialized services for each step

### Actual Responsibility Hypotheses (To Verify)
- AiManager may be too large (796 lines) - potential god object
- May contain logic that should be in specialized services
- May have tight coupling to specific implementations

---

## 2. File-by-File Reading Plan

| Order | File | Purpose Hypothesis | Lines |
|-------|------|-------------------|-------|
| 1 | `src/Services/AiManager.php` | Central facade for all AI operations | ~796 |
| 2 | `src/Services/Chat/AiChatService.php` | Chat-specific orchestration with context | ~233 |
| 3 | `src/Services/Chat/AiChatServiceInterface.php` | Interface contract | ~20 |
| 4 | `src/Services/Chat/SendMessageService.php` | Thin validation layer | ~55 |

---

## 3. Dependency Descent Strategy

### AiManager Dependencies (Critical)
1. **QueryGeneratorInterface** - Query generation
2. **QueryExecutorInterface** - Query execution
3. **ResponseGeneratorInterface** - Response generation
4. **ContextRetrieverInterface** - Context retrieval
5. **FileContextProvider** - File context
6. **GraphStoreInterface** - Neo4j access
7. **VectorStoreInterface** - Qdrant access

### AiChatService Dependencies
1. **ConversationContextManager** - Multi-turn context
2. **EntityExtractor** - Entity detection
3. **ReferenceResolver** - Reference resolution
4. **AI Facade** - Calls to AiManager

### Dependency Descent Questions
- Is AiManager properly delegating or doing too much itself?
- Are interface contracts respected or bypassed?
- Is there proper error propagation?

---

## 4. Reference Tracing Plan

### Prove Usage (Inbound)
- Where is AI facade used? (AiChatService, controllers, commands)
- Where is AiChatService instantiated?
- Where is SendMessageService called from?

### Prove Dependencies (Outbound)
- Trace all service calls from AiManager
- Verify each delegated service is actually used
- Check for any unused dependencies

---

## 5. Risk Map

| Category | Risk | Severity | Evidence Needed |
|----------|------|----------|-----------------|
| Correctness | Query/response mismatch | High | Trace data flow |
| Correctness | Context not properly passed | High | Verify context propagation |
| Security | User context not enforced | Critical | Check user parameter usage |
| Performance | Unnecessary service calls | Medium | Profile call graph |
| Maintainability | God object pattern | Medium | Count responsibilities |

---

## 6. Edge Cases and Failure Modes

### Input Edge Cases
- No conversation provided
- Invalid conversation ID
- User without permissions
- Empty question

### Processing Edge Cases
- Query generation fails
- Query execution times out
- LLM rate limited
- Empty query results

### Response Edge Cases
- Response generation fails
- Very large response
- Malformed response from LLM

---

## 7. Contracts/Interfaces Expected

### AiManager Contract
```php
interface AiManager {
    public function answerQuestion(string $question, array $options = []): array;
    public function retrieveContext(string $question, array $options = []): array;
    public function generateQuery(string $question, array $context, array $options = []): string;
    public function executeQuery(string $query, array $options = []): array;
    public function generateResponse(string $question, array $results, string $query, array $options = []): array;
}
```

### AiChatServiceInterface Contract
```php
interface AiChatServiceInterface {
    public function askWithConversation(string $question, AiConversation $conversation, array $options = []): array;
    public function isAvailable(): bool;
    public function getSchema(): array;
}
```

---

## 8. Data Propagation Checks

| Data Point | Source | Through | Final Consumer | Verify? |
|------------|--------|---------|----------------|---------|
| question | User input | ChatService → AiManager | QueryGenerator | YES |
| conversation_id | ChatService | AiManager options | ResponseGenerator | YES |
| user | Options | Throughout | FileContextProvider | CRITICAL |
| conversation_context | ContextManager | AiManager | PromptBuilder | YES |
| file_context | FileContextProvider | AiManager | ResponseGenerator | YES |

---

## 9. Cleanup/Refactor Strategy Options

### Option A: Extract Sub-Managers
- Split AiManager into QueryManager, ResponseManager, ContextManager
- Pros: Cleaner separation, easier testing
- Cons: More classes, more indirection

### Option B: Keep Facade Pattern
- AiManager as thin facade delegating to services
- Pros: Single entry point maintained
- Cons: May still be doing too much

### Option C: Event-Driven Pipeline
- Convert to event-driven with listeners
- Pros: Highly extensible
- Cons: Harder to debug, more complexity

---

## 10. Documentation Impact Plan

### Docs to Update
- `resources/docs/1.0/internals/architecture.md`
- `resources/docs/1.0/internals/data-flows.md`
- `docs/architecture.md`

### New Docs Needed
- Complete data flow diagram with all services
- Error handling documentation
- Extension points documentation

---

## Agent Dispatch Instructions

When analyzing this module:
1. Start with AiManager - understand the full orchestration flow
2. Trace the complete path from question to response
3. Verify all service calls are necessary and used
4. Check for any direct implementations that should be delegated
5. Verify error handling at each step
6. Document the complete call graph
7. Identify any god object patterns
