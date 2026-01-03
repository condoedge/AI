# Module 02: CHAT_ORCHESTRATION - Documentation Updates

> **Status:** COMPLETED
> **Last Updated:** Phase 4 (2026-01-03)

---

## Required Documentation Changes

| Doc Path | Section | Change Type | Description |
|----------|---------|-------------|-------------|
| `resources/docs/1.0/reference/facades.md` | AI Facade | UPDATE | Mark unused methods with deprecation notice (stream, testQuery, sanitizeQuery, detectQueryTemplate, getQueryTemplates, countTokens, ask, askQuestion) |
| `resources/docs/1.0/usage/simple-usage.md` | Chat Methods | UPDATE | Clarify difference between chat(), complete(), ask(), askQuestion(), answerQuestion() - recommend answerQuestion() as primary API |
| `resources/docs/1.0/internals/architecture.md` | AiManager | ADD | Document god object concern and planned refactoring |
| `resources/docs/1.0/usage/llm.md` | Streaming | ADD | Either implement streaming UI or document that stream() is not yet integrated |
| `resources/docs/1.0/chat/chat-ui.md` | Message Flow | UPDATE | Add call graph showing SendMessageService -> AiChatService -> AiManager flow |

---

## New Documentation Needed

| Doc Path | Purpose | Priority |
|----------|---------|----------|
| `resources/docs/1.0/internals/message-flow.md` | Document complete message flow from UI to response | HIGH |
| `resources/docs/1.0/internals/error-handling.md` | Document error handling strategy (currently inconsistent) | MEDIUM |
| `resources/docs/1.0/extending/custom-chat-service.md` | Document how to implement AiChatServiceInterface | LOW |
| `resources/docs/1.0/reference/unused-methods.md` | Catalog of methods that exist but are not used - clarify intent | MEDIUM |

---

## Diagrams to Create/Update

| Diagram | Type | Location | Description |
|---------|------|----------|-------------|
| Chat Orchestration Flow | Sequence Diagram | `resources/docs/1.0/internals/message-flow.md` | Show complete message flow from ChatMessageForm through all services |
| AiManager Responsibility Map | Block Diagram | `resources/docs/1.0/internals/architecture.md` | Visual breakdown of 6 responsibility areas in AiManager |
| Service Dependency Graph | Dependency Diagram | `resources/docs/1.0/internals/architecture.md` | Show all 8 dependencies and how they're used |
| User Context Flow | Data Flow Diagram | `resources/docs/1.0/chat/conversation-context-management.md` | Trace user object from auth() through to FileContextProvider |

---

## API Documentation Issues

| Method | Issue | Fix Required |
|--------|-------|--------------|
| `AiManager::ask()` | Undocumented that it's redundant with askQuestion() | Document or deprecate |
| `AiManager::askQuestion()` | Confusing name (similar to answerQuestion) | Rename or deprecate |
| `AiManager::stream()` | Documented but never used | Either implement or document as "coming soon" |
| `SendMessageService::sendMessage()` | Missing @throws documentation | Add @throws InvalidArgumentException |
| `AiChatService::getContextManager()` | Not in interface but is public | Either add to interface or make protected |

---

## Docblock Improvements Needed

| File | Method | Issue |
|------|--------|-------|
| AiManager.php | Constructor | Missing @throws documentation |
| AiManager.php | answerQuestion() | Document all possible array keys in return |
| AiManager.php | retrieveFileContext() | Missing @param and @return documentation details |
| AiChatService.php | askWithConversation() | Document that it mutates conversation (adds messages) |
| SendMessageService.php | sendMessage() | Document that it delegates to AiChatService |

---

## Configuration Documentation

| Config Key | Current State | Issue |
|------------|---------------|-------|
| `ai.file_context.enabled` | Used in AiManager line 697 | Not documented in config file or docs |
| Collection name 'questions' | Hardcoded in AiManager line 223 | Should be configurable and documented |

---

## Test Coverage Documentation

The following areas need test documentation:

1. **Error Handling Tests** - Document expected behavior when:
   - LLM service unavailable
   - Query generation fails
   - Query execution fails
   - File context retrieval fails

2. **Integration Test Coverage** - Document which flows are covered:
   - [x] ChatMessageForm -> SendMessageService -> AiChatService (partially in SendMessageServiceTest)
   - [ ] Full pipeline with mocked external services
   - [ ] Error recovery scenarios
