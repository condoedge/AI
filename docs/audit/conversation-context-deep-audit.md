# Deep Architectural Audit: Conversation Context System

**Date:** 2025-12-30
**Scope:** End-to-end conversation context flow analysis
**Goal:** Identify why conversation context is not being shared between turns

---

## Executive Summary

The conversation context system has **significant architectural gaps** that explain the reported problems:

1. **The UI uses `askWithHistory()` but the context system was designed for `askWithConversation()`** - These are completely separate code paths, and the UI path does NOT use ConversationContextManager.

2. **Entity properties are NEVER persisted** - The system tracks entity *labels* (e.g., "Customer") but never stores entity *properties* (e.g., "John's customer_id = 123").

3. **Previous query results are NOT passed to subsequent queries** - The context only includes the Cypher query text, not the actual data returned.

4. **Return type inconsistency exists** - `askWithHistory()` returns `AiChatMessage` objects but `ChatMessageForm` treats the return as an array.

5. **Multiple services were built but never integrated** - `askWithConversation()`, `prepareQuestionWithContext()`, `AmbiguityDetector`, and others are dead code.

---

## Part 1: Current Message Flow (What Actually Happens)

### The ACTUAL UI Flow (ChatMessageForm -> AI)

```
User types message
       |
       v
ChatMessageForm::sendMessage()
       |
       +---> $this->conversation->addMessage('user', $message)
       |
       v
app(AiChatServiceInterface::class)
       |
       v
AiChatService::askWithHistory($message, $history, $options)
       |
       +---> buildQuestionWithHistory($question, $history)  [TEXT ONLY - no entities]
       |            |
       |            +---> Concatenates previous Q&A as plain text strings
       |            +---> "User asked: X" / "Assistant replied: Y..."
       |            +---> NO entity extraction
       |            +---> NO reference resolution
       |            +---> NO context snapshot usage
       |
       v
AI::answerQuestion($enrichedQuestion, [style, conversation_id])
       |
       +---> ContextRetriever::retrieveContext()  [RAG - no conversation context!]
       +---> QueryGenerator::generate()           [Gets RAG context, NOT conversation]
       +---> QueryExecutor::execute()
       +---> ResponseGenerator::generate()
       |
       v
Response array returned to ChatMessageForm
       |
       v
$this->conversation->addMessage('assistant', $content, [...])
       |
       +---> Stores response_data, cypher_query, etc.
       +---> Does NOT update context_snapshot with entities!
       +---> Does NOT call ConversationContextManager!
```

### The INTENDED Flow (askWithConversation - NEVER CALLED FROM UI)

```
User types message
       |
       v
AiChatService::askWithConversation($question, $conversation, $options)
       |
       v
getContextManager()->processQuestion($conversation, $question, $schema)
       |
       +---> EntityExtractor::extractFromQuestion()    [Detects entity labels]
       +---> ReferenceResolver::isFollowUp()           [Detects "those", "them"]
       +---> ReferenceResolver::resolve()              [Replaces pronouns]
       +---> $conversation->updateContextSnapshot()    [Persists focused_entity]
       |
       v
getContextManager()->buildPromptContext($conversation)
       |
       +---> Returns: focused_entity, mentioned_entities, recent_exchanges
       |
       v
AI::answerQuestion($enrichedQuestion, ['conversation_context' => $context])
       |
       +---> conversation_context IS included in options
       +---> ConversationContextSection WOULD format it for the prompt
       |
       v
getContextManager()->recordResponse($conversation, $answer, $cypher, $result)
       |
       +---> EntityExtractor::extractFromCypher()      [Extracts entities from query]
       +---> Updates context_snapshot with last_result_count
       |
       v
$conversation->addMessage(...) with full context
```

---

## Part 2: Gap Analysis

### GAP 1: askWithHistory() Does Not Use ConversationContextManager

**Location:** `src/Services/Chat/AiChatService.php` lines 124-167

**Problem:** The `askWithHistory()` method that ChatMessageForm uses does NOT:
- Call `getContextManager()`
- Use `EntityExtractor`
- Use `ReferenceResolver`
- Update `context_snapshot`
- Pass `conversation_context` to the AI

**Current code:**
```php
public function askWithHistory(string $question, array $history, array $options = []): AiChatMessage
{
    // ...
    $enrichedQuestion = $this->buildQuestionWithHistory($question, $history, $options);

    $aiResponse = AI::answerQuestion($enrichedQuestion, [
        'style' => $options['style'] ?? 'friendly',
        // NOTE: NO 'conversation_context' key!
    ]);
    // ...
}
```

**What's missing:**
```php
// Should be:
$contextResult = $this->getContextManager()->processQuestion($conversation, $question, $schema);
$conversationContext = $this->getContextManager()->buildPromptContext($conversation);

$aiResponse = AI::answerQuestion($question, [
    'style' => $options['style'] ?? 'friendly',
    'conversation_context' => $conversationContext,  // <-- THIS IS MISSING
]);
```

### GAP 2: Entity Properties Never Stored

**Problem:** When user says "Customer John", the system may detect "Customer" as a label, but it NEVER stores:
- John's actual database ID
- John's properties (name, email, etc.)
- The WHERE clause that identified John

**Evidence:**
```php
// EntityExtractor::extractFromQuestion() only returns:
return [
    'focused_entity' => 'Customer',     // Label only!
    'query_type' => 'detail',
    'mentioned_entities' => ['Customer'],
];
// No actual entity data (ID, properties, filter conditions)
```

**What SHOULD happen:**
When query returns `{id: 123, name: 'John', email: 'john@example.com'}`, the system should store:
```php
$conversation->updateContextSnapshot([
    'focused_entity' => 'Customer',
    'focused_entity_data' => [
        'id' => 123,
        'name' => 'John',
        'identifier_conditions' => "c.name = 'John'"
    ],
]);
```

### GAP 3: Previous Query Results Not Passed

**Problem:** When generating the next query, the system has NO access to what the previous query returned.

**Evidence in buildQuestionWithHistory():**
```php
protected function buildQuestionWithHistory(string $question, array $history, array $options): string
{
    // ...
    foreach ($contextMessages as $message) {
        // Only gets CONTENT (text), not DATA (results)
        $content = $message instanceof AiChatMessage ? $message->content : ($message['content'] ?? '');

        if ($role === 'user') {
            $contextParts[] = "User asked: {$content}";
        } else {
            $truncated = strlen($content) > 200 ? substr($content, 0, 200) . '...' : $content;
            $contextParts[] = "Assistant replied: {$truncated}";
            // NO: "Query returned: [{id: 1, name: 'John'}, ...]"
        }
    }
}
```

**What SHOULD happen:**
```php
// Include previous query results for reference:
$contextParts[] = "Previous query returned: " . json_encode($previousResults);
$contextParts[] = "Entity focus: Customer with id=123 (John)";
```

### GAP 4: ConversationContextSection Exists But Gets No Data

**Location:** `src/Services/PromptSections/ConversationContextSection.php`

**Problem:** This section IS registered in config, but:
1. `askWithHistory()` doesn't pass `conversation_context` option
2. `AiManager::answerQuestion()` only merges it if passed in options
3. Therefore, the section's `shouldInclude()` returns false (no data)

**Code flow:**
```php
// AiManager::answerQuestion()
if (!empty($options['conversation_context'])) {  // <-- This check fails
    $context['conversation_context'] = $options['conversation_context'];
}

// ConversationContextSection::shouldInclude()
public function shouldInclude(string $question, array $context, array $options = []): bool
{
    $conversationContext = $context['conversation_context'] ?? [];
    // Returns false because conversation_context is empty!
    return !empty($conversationContext['focused_entity'])
        || !empty($conversationContext['recent_exchanges']);
}
```

### GAP 5: Return Type Inconsistency

**Problem:** Interface says one thing, implementation returns another.

**Interface (AiChatServiceInterface.php:30):**
```php
public function askWithHistory(string $question, array $history, array $options = []): AiChatMessage;
```

**Implementation returns AiChatMessage correctly, but ChatMessageForm treats it as array:**
```php
// ChatMessageForm::sendMessage() line 142
$responseContent = $response['answer'] ?? $response['content'] ?? 'I could not generate a response.';
// Treating $response as ARRAY when it's actually AiChatMessage object
```

---

## Part 3: Dead Code Inventory

### Methods That Exist But Are Never Called From UI

| Method | Location | Reason Unused |
|--------|----------|---------------|
| `askWithConversation()` | AiChatService:183 | ChatMessageForm uses `askWithHistory()` instead |
| `prepareQuestionWithContext()` | AiChatService:285 | Only has test coverage |
| `getContextManager()` | AiChatService:35 | Only called from unused methods |
| `getSuggestions()` | AiChatService:379 | AI response includes suggestions instead |
| `getExampleQuestions()` | AiChatService:434 | Panel uses settings directly |
| `AiChatMessage::system()` | AiChatMessage | Never created |
| `AiChatResponseData::list()` | AiChatResponseData | Factory never called |
| `AiChatResponseData::metric()` | AiChatResponseData | Factory never called |
| `AiChatResponseData::withActions()` | AiChatResponseData | Never called |

### Classes That Exist But Are Never Integrated

| Class | Location | Reason Unused |
|-------|----------|---------------|
| `AmbiguityDetector` | Services/Chat/ | Built but never called from UI |
| `ConversationContextManager` | Services/Context/ | Only used by `askWithConversation()` which is dead |
| `EntityExtractor` | Services/Context/ | Only used via ConversationContextManager |
| `ReferenceResolver` | Services/Context/ | Only used via ConversationContextManager |

### Methods with Low/No Usage

| Method | Class | Notes |
|--------|-------|-------|
| `detectReferenceType()` | ReferenceResolver | Only used internally by `resolve()` |
| `buildFileReference()` | FileContextProvider | Public but no callers |
| `getPhysicalFilePath()` | FileAccessResolver | Public but no callers |

---

## Part 4: Recommended Architecture

### Option A: Fix askWithHistory() to Use Context System

**Minimal change approach:**

```php
public function askWithHistory(string $question, array $history, array $options = []): AiChatMessage
{
    // NEW: Get or create conversation for context tracking
    $conversation = $options['conversation'] ?? null;

    if ($conversation instanceof AiConversation) {
        // Use the full context management system
        return $this->askWithConversation($question, $conversation, $options);
    }

    // Fallback to simple history concatenation
    // ... existing code ...
}
```

**ChatMessageForm change:**
```php
$response = $aiManager->askWithHistory($message, $history, [
    'style' => $style,
    'conversation' => $this->conversation,  // <-- Pass conversation object
]);
```

### Option B: Remove Dead Code, Use askWithConversation()

1. Update `AiChatServiceInterface` to add `askWithConversation()`
2. Update `ChatMessageForm` to call `askWithConversation()` directly
3. Remove `askWithHistory()` or mark deprecated
4. Remove `buildQuestionWithHistory()` method

### Option C: Store Entity Properties (Enhanced Context)

**Extend context snapshot to include actual entity data:**

```php
// In recordResponse()
$queryResult = ['data' => [['id' => 123, 'name' => 'John', ...]]];

$conversation->updateContextSnapshot([
    'focused_entity' => 'Customer',
    'focused_entity_filter' => "c.name = 'John'",  // NEW
    'last_result_sample' => array_slice($queryResult['data'], 0, 3),  // NEW
    'last_result_count' => count($queryResult['data']),
]);
```

**Use in next query:**

```php
// In SemanticPromptBuilder or ConversationContextSection
if ($context['focused_entity_filter']) {
    $output .= "**Active Filter:** {$context['focused_entity_filter']}\n";
}
if ($context['last_result_sample']) {
    $output .= "**Previous Results Sample:**\n```json\n" .
               json_encode($context['last_result_sample'], JSON_PRETTY_PRINT) . "\n```\n";
}
```

---

## Part 5: Root Cause Summary

| Reported Problem | Root Cause | Fix Required |
|------------------|------------|--------------|
| Context not consistent between turns | `askWithHistory()` doesn't use ConversationContextManager | Use `askWithConversation()` or merge functionality |
| Entity properties lost | System only tracks labels, not actual entity data/filters | Store entity data in context_snapshot |
| Previous results not shared | `buildQuestionWithHistory()` only includes text, not data | Include result samples in context |
| Return type inconsistency | Interface says AiChatMessage, UI treats as array | Fix ChatMessageForm to use object properties |
| Dead code everywhere | `askWithConversation()` built but never wired to UI | Connect UI to the proper method |

---

## Part 6: Files to Modify

### Critical Changes

1. **`src/Kompo/ChatMessageForm.php`**
   - Change from `askWithHistory()` to `askWithConversation()`
   - Pass `$this->conversation` to the method
   - Handle `AiChatMessage` return type correctly

2. **`src/Services/Chat/AiChatServiceInterface.php`**
   - Add `askWithConversation()` to interface
   - Fix return type documentation

3. **`src/Services/Chat/AiChatService.php`**
   - Either integrate context management into `askWithHistory()`
   - Or deprecate `askWithHistory()` in favor of `askWithConversation()`

4. **`src/Services/Context/ConversationContextManager.php`**
   - Extend `recordResponse()` to store entity filter conditions
   - Extend `buildPromptContext()` to include result samples

5. **`src/Models/AiConversation.php`**
   - Add methods for storing/retrieving entity data
   - Add `getFocusedEntityFilter()`, `getLastResultSample()`

### Optional Cleanup

1. Remove or deprecate:
   - `AiChatService::buildQuestionWithHistory()`
   - `AmbiguityDetector` (or integrate it)
   - Unused `AiChatMessage::system()`
   - Unused `AiChatResponseData` methods

2. Consider removing:
   - `AiChatMessage` DTO (UI uses Model directly)
   - `AiChatResponseData` DTO (data goes to Model anyway)

---

## Part 7: Testing Recommendations

### Tests to Verify Fix

1. **Multi-turn conversation test:**
   ```php
   // Turn 1: "Show me customer John"
   // Turn 2: "What are his orders?"
   // Verify: Query includes "John's customer_id" in WHERE clause
   ```

2. **Entity property persistence test:**
   ```php
   // After first query, verify context_snapshot contains:
   // - focused_entity = 'Customer'
   // - focused_entity_filter = "c.name = 'John'"
   ```

3. **Reference resolution test:**
   ```php
   // Turn 1: "Show customers with > 10 orders"
   // Turn 2: "Filter those by country = USA"
   // Verify: "those" resolved to customers from turn 1
   ```

4. **Return type test:**
   ```php
   $response = $service->askWithHistory($question, $history);
   $this->assertInstanceOf(AiChatMessage::class, $response);
   ```

---

## Appendix: Key File Locations

| File | Purpose | Lines |
|------|---------|-------|
| `src/Kompo/ChatMessageForm.php` | UI entry point | 227 |
| `src/Services/Chat/AiChatService.php` | Chat service | 541 |
| `src/Services/Chat/AiChatServiceInterface.php` | Interface | 55 |
| `src/Services/Context/ConversationContextManager.php` | Context orchestration | 153 |
| `src/Services/Context/EntityExtractor.php` | Entity detection | 120 |
| `src/Services/Context/ReferenceResolver.php` | Reference resolution | 166 |
| `src/Services/PromptSections/ConversationContextSection.php` | Prompt section | 113 |
| `src/Services/AiManager.php` | AI facade implementation | 795 |
| `src/Models/AiConversation.php` | Conversation model | 179 |
| `src/Models/AiMessage.php` | Message model | 53 |
