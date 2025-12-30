# Phase 2 Audit: Chat Services

**Task:** 25 - Chat Services Review
**Date:** 2025-12-30
**Directory:** `src/Services/Chat/`

---

## Overview

The Chat services layer provides the bridge between the Kompo UI components and the core AI pipeline. It handles message processing, conversation context management, response formatting, and user interaction features like suggestions and error handling.

---

## Files Reviewed

### 1. AiChatServiceInterface.php

**Location:** `src/Services/Chat/AiChatServiceInterface.php`

**Purpose:**
Contract interface defining the required methods for any AI chat service implementation. Enables dependency injection and testability through interface-based design.

**Methods Defined:**
| Method | Return Type | Description |
|--------|-------------|-------------|
| `ask(string $question, array $options = [])` | `AiChatMessage` | Process a single question |
| `askWithHistory(string $question, array $history, array $options = [])` | `array` | Process with conversation history |
| `getSuggestions(string $question, string $response)` | `array` | Get follow-up suggestions |
| `getExampleQuestions()` | `array` | Get welcome screen examples |
| `isAvailable()` | `bool` | Check service availability |

**Integration:**
- Bound in `AiServiceProvider::registerChatServices()` to `AiChatService`
- Injected into `AiChatPanel::created()` and `ChatMessageForm::sendMessage()`

**Notes:**
- Clean interface design following ISP (Interface Segregation Principle)
- Return type inconsistency: `askWithHistory()` returns `array` but docblock says `AiChatMessage`

---

### 2. AiChatService.php

**Location:** `src/Services/Chat/AiChatService.php`

**Purpose:**
Default implementation of `AiChatServiceInterface`. Acts as the primary bridge between chat UI and the AI system (via `AI` facade).

**Key Methods:**

| Method | Used In UI | Description |
|--------|-----------|-------------|
| `ask()` | No | Simple question without history |
| `askWithHistory()` | Yes (ChatMessageForm) | Question with conversation history |
| `askWithConversation()` | No (tests only) | Full conversation context with entity tracking |
| `prepareQuestionWithContext()` | No (tests only) | Preview question enrichment |
| `getSuggestions()` | No | Generate follow-up suggestions |
| `getExampleQuestions()` | No | Get example questions from config |
| `isAvailable()` | Yes (AiChatPanel) | Check if AI service is configured |
| `getContextManager()` | Tests only | Get lazy-loaded ConversationContextManager |

**Dependencies:**
- `Condoedge\Ai\Facades\AI` - Main AI facade for `answerQuestion()`
- `ConversationContextManager` - For conversation context tracking
- `EntityExtractor` - For entity detection in questions
- `ReferenceResolver` - For resolving pronouns like "those", "them"

**Message Flow (askWithHistory):**
```
1. Merge config with options
2. buildQuestionWithHistory() - Enrich question with conversation context
3. AI::answerQuestion() - Full RAG pipeline
4. Return raw response array
```

**Configuration (from config/ai.php):**
```php
[
    'style' => 'friendly',           // Response style
    'include_suggestions' => true,   // Add follow-ups
    'include_metrics' => false,      // Show execution time
    'max_suggestions' => 3,          // Limit suggestions
    'max_history_messages' => 10,    // History window
    'system_prompt' => '...',        // Custom system prompt
]
```

**Anomalies:**
1. **Commented code in askWithHistory()**: Lines 140-153 contain commented-out response processing code
2. **Return type mismatch**: `askWithHistory()` returns `array` but interface docblock says `AiChatMessage`
3. **Error handling inconsistency**: Returns `AiChatMessage` on error but `array` on success

---

### 3. AiChatMessage.php

**Location:** `src/Services/Chat/AiChatMessage.php`

**Purpose:**
Value object representing a single message in a chat conversation. Immutable data transfer object with factory methods.

**Properties:**
| Property | Type | Description |
|----------|------|-------------|
| `id` | string | Unique message identifier |
| `role` | string | ROLE_USER, ROLE_ASSISTANT, ROLE_SYSTEM |
| `content` | string | Message text content |
| `timestamp` | ?string | ISO8601 timestamp |
| `metadata` | array | Additional metadata |
| `responseData` | ?AiChatResponseData | Rich response data |

**Factory Methods:**
| Method | Description |
|--------|-------------|
| `user(string $content)` | Create user message |
| `assistant(string $content, ?AiChatResponseData $responseData)` | Create assistant message |
| `system(string $content)` | Create system message |
| `fromArray(array $data)` | Deserialize from array |

**Used By:**
- `AiChatService::ask()` - Returns AiChatMessage
- `AiChatService::askWithHistory()` - Returns on error
- `AiChatService::askWithConversation()` - Returns AiChatMessage
- `AiChatService::buildQuestionWithHistory()` - Checks role property

**Usage in UI:** Limited - The `ChatMessageForm` and `AiChatPanel` work directly with `AiConversationMessage` model instead.

---

### 4. AiChatResponseData.php

**Location:** `src/Services/Chat/AiChatResponseData.php`

**Purpose:**
Rich response data container for structured AI responses. Supports multiple display types (text, table, list, metric, card).

**Response Types:**
| Type | Description | Factory Method |
|------|-------------|----------------|
| `TYPE_TEXT` | Plain text response | `text()` |
| `TYPE_TABLE` | Tabular data with headers/rows | `table()` |
| `TYPE_LIST` | List of items | `list()` |
| `TYPE_METRIC` | Single metric with label/value/trend | `metric()` |
| `TYPE_CARD` | Card display | - |
| `TYPE_MIXED` | Multiple types combined | - |
| `TYPE_ERROR` | Error response | `error()` |

**Properties:**
| Property | Type | Description |
|----------|------|-------------|
| `type` | string | Response type constant |
| `data` | mixed | Type-specific data |
| `actions` | array | Action buttons |
| `suggestions` | array | Follow-up questions |
| `executionTimeMs` | ?int | Query execution time |
| `rowsReturned` | ?int | Result count |
| `query` | ?string | Cypher query |
| `success` | bool | Success flag |
| `errorMessage` | ?string | Error message |

**Fluent Methods:**
- `withActions(array $actions)` - Add action buttons
- `withSuggestions(array $suggestions)` - Add follow-ups
- `withMetrics(int $time, int $rows, ?string $query)` - Add metrics

**Used By:**
- `AiChatService::buildResponseData()` - Creates response data
- `AiChatService::enrichResponseData()` - Enriches with AI response
- `AiChatMessage` - As optional responseData property
- `AiChatPanel::renderRichData()` - Renders TYPE_TABLE, TYPE_METRIC, TYPE_LIST

---

### 5. AmbiguityDetector.php

**Location:** `src/Services/Chat/AmbiguityDetector.php`

**Purpose:**
Analyzes questions to detect ambiguous or vague queries that may need clarification. Provides confidence scoring and clarification suggestions.

**Analysis Method:**
```php
analyze(string $question, array $availableEntities = []): array
```

**Returns:**
```php
[
    'is_ambiguous' => bool,
    'confidence' => float,  // 0.4 if ambiguous, 0.9 if specific
    'reasons' => array,
    'clarification_questions' => array,
]
```

**Detection Rules:**
1. **Vague terms without specifics**: data, stuff, things, info, it, them
2. **Very short questions**: Less than 3 words
3. **Missing entity reference**: No match against available entity labels

**Specific Indicators (need 2+):**
- how many, count, total, sum, average
- customers, orders, products, users, teams
- active, pending, completed, recent, last
- by, grouped, sorted, filtered, where

**Usage:** NOT USED in UI flow. Only has test coverage.

---

## Message Flow: UI to AI Pipeline

```
                                    ChatMessageForm
                                          |
                                          v
                              sendMessage() called
                                          |
                    +-----------+---------+----------+
                    |                                |
                    v                                v
           Add user message              app(AiChatServiceInterface)
           to conversation                         |
                                                   v
                                        askWithHistory()
                                                   |
                                                   v
                                    buildQuestionWithHistory()
                                    (enriches with history context)
                                                   |
                                                   v
                                       AI::answerQuestion()
                                                   |
                    +---------------+--------------+
                    |               |              |
                    v               v              v
             retrieveContext   generateQuery   executeQuery
             (RAG + files)     (LLM + Cypher)  (Neo4j)
                    |               |              |
                    +---------------+--------------+
                                    |
                                    v
                            generateResponse()
                            (LLM + insights)
                                    |
                                    v
                             Response Array
                                    |
                                    v
                          ChatMessageForm extracts:
                          - answer, data, suggestions
                          - sources, entities, cypher
                                    |
                                    v
                         Add assistant message
                         to conversation
                                    |
                                    v
                           AiChatPanel refresh
                           (renderMessages)
```

---

## Service Provider Registration

**Location:** `src/AiServiceProvider.php::registerChatServices()`

```php
private function registerChatServices(): void
{
    $this->app->singleton(AiChatServiceInterface::class, function ($app) {
        return new AiChatService(
            config: config('ai.chat', [])
        );
    });

    $this->app->alias(AiChatServiceInterface::class, AiChatService::class);
}
```

---

## Integration with Kompo UI Components

### AiChatPanel

**Uses:**
- `AiChatServiceInterface::isAvailable()` - Check online status in header
- Does NOT use `AiChatMessage` or `AiChatResponseData` directly
- Renders messages from `AiConversationMessage` model

**Renders:**
- `renderRichData()` - Uses response_data from message model (table, metric, list types)
- `renderSuggestions()` - Uses suggestions from message metadata
- `renderMetrics()` - Uses execution_time_ms, confidence_score from message

### ChatMessageForm

**Uses:**
- `AiChatServiceInterface::askWithHistory()` - Main chat flow
- Extracts: answer, data, suggestions, sources, entities, cypher_query
- Stores in `AiConversation::addMessage()` with metadata

---

## Unused Methods and Classes

### Completely Unused (Not in UI Flow):

| Item | Type | Location | Notes |
|------|------|----------|-------|
| `AmbiguityDetector` | Class | `src/Services/Chat/` | Has tests but never called from UI |
| `ask()` | Method | `AiChatService` | Superseded by `askWithHistory()` |
| `askWithConversation()` | Method | `AiChatService` | Has tests, not in UI |
| `prepareQuestionWithContext()` | Method | `AiChatService` | Has tests, not in UI |
| `getSuggestions()` | Method | `AiChatService` | Not called - suggestions come from AI response |
| `getExampleQuestions()` | Method | `AiChatService` | Not called - panel uses settings |
| `AiChatMessage::system()` | Method | `AiChatMessage` | Never used |
| `AiChatResponseData::list()` | Method | `AiChatResponseData` | Rendering exists but factory never called |
| `AiChatResponseData::metric()` | Method | `AiChatResponseData` | Rendering exists but factory never called |
| `AiChatResponseData::withActions()` | Method | `AiChatResponseData` | Never called |

### Partially Used:

| Item | Notes |
|------|-------|
| `AiChatMessage` | Used in service returns but UI works with model directly |
| `AiChatResponseData` | Created by service but UI reads from model's response_data |
| `getContextManager()` | Public method, only used in tests |

---

## Notes and Anomalies

### 1. Return Type Inconsistency
`askWithHistory()` signature and interface docblock disagree:
- Interface docblock: `@return AiChatMessage`
- Actual signature: `array`
- Implementation: Returns array on success, `AiChatMessage` on error

### 2. Commented Code
Lines 140-153 in `AiChatService::askWithHistory()` contain commented-out response processing that was likely replaced with direct array return.

### 3. Message Object Duplication
Two representations of chat messages exist:
- `AiChatMessage` (DTO in Services/Chat/)
- `AiConversationMessage` (Eloquent Model in Models/)

The UI exclusively uses the model, making the DTO underutilized.

### 4. Suggestion System Disconnect
- `AiChatService::getSuggestions()` generates keyword-based suggestions
- UI reads suggestions from AI response metadata instead
- The method is effectively dead code

### 5. Ambiguity Detection Not Integrated
`AmbiguityDetector` provides useful query validation but is never called in the chat flow. Could improve UX by catching vague questions before sending to AI.

### 6. Context Methods Not Exposed
`askWithConversation()` and `prepareQuestionWithContext()` provide advanced context tracking but:
- Not exposed in the interface
- Not used by ChatMessageForm
- Only have test coverage

---

## Test Coverage

| File | Test File | Coverage |
|------|-----------|----------|
| AiChatService | `tests/Unit/Services/Chat/AiChatServiceContextTest.php` | Context methods only |
| AmbiguityDetector | `tests/Unit/Services/Chat/AmbiguityDetectorTest.php` | Full coverage |
| AiChatMessage | None | No tests |
| AiChatResponseData | None | No tests |

---

## Recommendations

1. **Resolve return type inconsistency** in `askWithHistory()` - either update interface or fix implementation

2. **Remove or integrate AmbiguityDetector** - Currently unused, could validate questions in ChatMessageForm

3. **Clean up commented code** in `askWithHistory()`

4. **Consider consolidating message representations** - Either use `AiChatMessage` throughout or remove it

5. **Expose context methods in interface** if they provide value beyond testing

6. **Add tests for AiChatMessage and AiChatResponseData** - Core DTOs lack test coverage

7. **Document the suggestion system** - Clarify that `getSuggestions()` is deprecated in favor of AI-generated suggestions

---

## Summary

The Chat services provide a clean abstraction layer between UI and AI, but several components have become unused as the system evolved:
- **AmbiguityDetector** - Never integrated
- **getSuggestions()** - Replaced by AI-generated suggestions
- **Context methods** - Not exposed to UI
- **AiChatMessage DTO** - Underutilized, UI uses models directly

The core flow (`askWithHistory()` -> `AI::answerQuestion()`) works correctly, but cleanup would improve maintainability.
