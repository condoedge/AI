# Module 04: CONVERSATION_CONTEXT - Analysis Plan

> **Module Slug:** conversation-context
> **Priority:** HIGH (Multi-turn conversation handling)
> **Estimated Files:** 3

---

## 1. Responsibility

### Ideal
- Manage multi-turn conversation context
- Extract entities from user questions
- Resolve references ("it", "that", "the customer")
- Build prompt context from conversation history

### Files to Analyze
| File | Purpose |
|------|---------|
| `src/Services/Context/ConversationContextManager.php` | Main context manager |
| `src/Services/Context/EntityExtractor.php` | Entity extraction from questions |
| `src/Services/Context/ReferenceResolver.php` | Reference resolution |

---

## 2. Key Questions

- How is conversation history tracked?
- How are entities extracted from questions?
- How are references like "it" resolved?
- How is context stored in AiConversation model?
- How is prompt context built from history?

---

## 3. Dependencies to Trace

- AiConversation model (context_snapshot field)
- Schema from AiManager for entity extraction
- Previous messages for reference resolution

---

## 4. Risk Areas

| Risk | Severity | Check |
|------|----------|-------|
| Reference resolution fails | Medium | Test edge cases |
| Entity extraction misses entities | Medium | Check extraction logic |
| Context grows too large | Medium | Check truncation |
| Context lost between messages | High | Verify persistence |

---

## 5. Agent Instructions

1. Read each file, understand the context flow
2. Trace how context is built and stored
3. Verify entity extraction algorithm
4. Test reference resolution logic
5. Check integration with AiChatService
