# Module 04: CONVERSATION_CONTEXT - Documentation Updates

> **Status:** COMPLETE

## Required Changes

| Doc Path | Change Type | Description |
|----------|-------------|-------------|
| `docs/architecture/context-tracking.md` | Create | Document the three-class context architecture |
| `docs/api/conversation-context.md` | Create | API reference for ConversationContextManager |
| `docs/guides/multi-turn-conversations.md` | Create | User guide for follow-up question handling |
| `README.md` | Update | Add section on conversation context capabilities |

---

## Detailed Documentation Needs

### 1. Architecture Documentation

**File:** `docs/architecture/context-tracking.md`

Should cover:
- Three-class separation (Manager, Extractor, Resolver)
- Context snapshot schema and persistence
- Integration with AiChatService
- Data flow through the context system

### 2. API Reference

**File:** `docs/api/conversation-context.md`

Document public methods:
- `ConversationContextManager::processQuestion()`
- `ConversationContextManager::recordResponse()`
- `ConversationContextManager::buildPromptContext()`
- `EntityExtractor::extractFromQuestion()`
- `EntityExtractor::extractFromCypher()`
- `ReferenceResolver::isFollowUp()`
- `ReferenceResolver::resolve()`

### 3. Configuration Documentation

Add to existing config documentation:

```php
// Suggested configurable values (currently hardcoded)
'context' => [
    'result_sample_size' => 3,        // Number of results to store in context
    'answer_summary_length' => 200,   // Max chars for answer summary
    'exchange_summary_length' => 100, // Max chars for exchange formatting
    'max_mentioned_entities' => 50,   // Limit entity accumulation
],
```

### 4. Query Type Pattern Documentation

Document the query type detection patterns for customization:

| Type | Pattern | Customization |
|------|---------|---------------|
| aggregate | sum, total, average, avg, max, min, revenue | Domain-specific aggregates |
| count | how many, count, number of | Language variations |
| list | show, list, display, get, find, all | Action verbs |
| detail | detail, specific, particular, information about | Detail indicators |
| compare | compare, versus, vs, difference, between | Comparison terms |

### 5. Follow-up Detection Patterns

Document patterns for extension/customization:

| Category | Patterns | Notes |
|----------|----------|-------|
| Conjunctions | and, but, also | Sentence starters |
| Pronouns | those, them, these, it, they | Reference resolution |
| Quantifiers | top N, first N, last N | Implicit entity |
| Actions | show, filter, sort, group | Continuation commands |
| References | the same, the top, the first | Definite article usage |

---

## Inline Code Documentation Improvements

### ConversationContextManager.php

Line 36: Add note about schema requirement
```php
// Note: Schema must include 'labels' key with entity type names
$extraction = $this->entityExtractor->extractFromQuestion($question, $schema);
```

Line 115: Document magic number
```php
// Store sample of first 3 results for reference resolution context
// TODO: Make configurable via config('ai.context.result_sample_size', 3)
$resultSample = array_slice($queryResult['data'] ?? [], 0, 3);
```

### EntityExtractor.php

Line 81: Document regex complexity
```php
// Regex matches multiple Cypher node label syntaxes:
// - (:Label) - anonymous node
// - (n:Label) - named node
// - [:TYPE] - relationship in brackets
// - :Label{ - label with properties
```

### ReferenceResolver.php

Line 152: Document pluralization assumption
```php
// Note: Appends 's' for pluralization, may not work for irregular nouns
// Consider using Str::plural() for proper pluralization
$enriched = preg_replace('/\b(those|them|these|they|it)\b/i', strtolower($entity) . 's', $question);
```

---

## Example Usage Documentation

Add examples to developer documentation:

### Basic Follow-up Flow
```php
// First question
"Show all customers"
// -> focused_entity: Customer, query_type: list

// Follow-up with pronoun
"How many of them are active?"
// -> isFollowUp: true
// -> resolved_entity: Customer
// -> enriched_question: "How many of customers are active?"
// -> query_type: count

// Follow-up with demonstrative
"Show those who placed orders"
// -> resolved to: "Show customers who placed orders"
```

### Context Snapshot Example
```php
// After query: "Show active customers in New York"
$context = [
    'focused_entity' => 'Customer',
    'focused_entity_filter' => "c.status = 'active' AND c.city = 'New York'",
    'mentioned_entities' => ['Customer'],
    'last_query_type' => 'list',
    'last_result_count' => 42,
    'last_result_sample' => [
        ['name' => 'John Doe', 'city' => 'New York'],
        ['name' => 'Jane Smith', 'city' => 'New York'],
        ['name' => 'Bob Wilson', 'city' => 'New York'],
    ],
];
```

---

## Test Documentation

Recommend adding test cases for:

1. **Entity Extraction**
   - Singular vs plural entity names
   - Multiple entities in one question
   - Entity names with spaces
   - Case sensitivity

2. **Reference Resolution**
   - Each follow-up pattern
   - Each reference type (pronoun, demonstrative, definite, implicit)
   - Resolution with/without context
   - Operation type detection

3. **Context Management**
   - Context snapshot persistence
   - Entity accumulation over turns
   - Result sample storage
   - Answer summary truncation
