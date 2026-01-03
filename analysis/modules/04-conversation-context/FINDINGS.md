# Module 04: CONVERSATION_CONTEXT - Findings

> **Status:** COMPLETE

## Architecture Overview

The conversation context module consists of three classes that work together to track multi-turn conversation state:

```
ConversationContextManager (Orchestrator)
    |
    +-- EntityExtractor (Entity/Query Detection)
    |
    +-- ReferenceResolver (Pronoun/Reference Resolution)
```

All classes are registered as singletons in `AiServiceProvider`.

---

## File Analysis

### 1. ConversationContextManager.php (202 lines)

**Purpose:** Orchestrates conversation context tracking by combining entity extraction, reference resolution, and context snapshot management.

**Key Methods:**

| Method | Lines | Description |
|--------|-------|-------------|
| `processQuestion()` | 31-80 | Main entry point for processing user questions |
| `recordResponse()` | 88-129 | Records AI responses and updates context with query results |
| `buildPromptContext()` | 158-177 | Builds context array for prompt generation |
| `extractEntityFilter()` | 136-148 | Extracts WHERE clause from Cypher for filter tracking |
| `formatRecentExchanges()` | 185-201 | Formats message history for context injection |

**`processQuestion()` Flow:**
1. Extract entities from question using schema labels
2. Check if question is a follow-up (pronouns, continuation patterns)
3. If follow-up, resolve references using conversation context
4. Update conversation context snapshot with new entities
5. Return enriched question and resolution metadata

**`recordResponse()` Stores:**
- Focused entity (first entity from Cypher)
- Mentioned entities (merged with existing)
- Last relationships from Cypher
- Last Cypher query
- Result count and sample (first 3 results)
- Entity filter (WHERE clause)
- Answer summary (truncated to 200 chars)

---

### 2. EntityExtractor.php (120 lines)

**Purpose:** Extracts entity types and query patterns from natural language questions and Cypher queries.

**Query Type Patterns (ordered by specificity):**
| Type | Pattern | Examples |
|------|---------|----------|
| `aggregate` | `/\b(sum\|total\|average\|avg\|max\|min\|revenue)\b/i` | "total revenue", "average price" |
| `count` | `/\b(how many\|count\|number of)\b/i` | "how many customers" |
| `list` | `/\b(show\|list\|display\|get\|find\|all)\b/i` | "show all orders" |
| `detail` | `/\b(detail\|specific\|particular\|information about)\b/i` | "details about customer" |
| `compare` | `/\b(compare\|versus\|vs\|difference\|between)\b/i` | "compare sales vs last year" |

**Entity Extraction Algorithm (`extractFromQuestion()`):**
1. Convert question to lowercase
2. Iterate through schema labels
3. Check for singular and plural forms using `Str::plural()`
4. First matched entity becomes `focused_entity`
5. All matches collected in `mentioned_entities`

**Cypher Extraction (`extractFromCypher()`):**
- Node labels: `/\(:?(\w+)\)|\[:\s*(\w+)\s*\]|:(\w+)\s*[{)\]]/`
- Precise labels: `/\((\w+):(\w+)/`
- Relationships: `/\[:(\w+)\]/`

---

### 3. ReferenceResolver.php (166 lines)

**Purpose:** Resolves conversational references like "those", "them", "the same" using conversation context.

**Follow-up Detection Patterns:**
| Pattern | Example |
|---------|---------|
| `/^and\s+/i` | "and filter by date" |
| `/^but\s+/i` | "but only active ones" |
| `/^also\s+/i` | "also show their orders" |
| `/^what about\s+/i` | "what about last month" |
| `/\b(those\|them\|these\|it)\b/i` | "show them sorted" |
| `/^(show\|filter\|sort\|group)\s+(me\s+)?the\s+/i` | "show me the top 10" |
| `/^the\s+(same\|top\|first\|last)\b/i` | "the same customers" |
| `/^(top\|first\|last)\s+\d+/i` | "top 5 by revenue" |

**Reference Types:**
| Type | Pattern | Description |
|------|---------|-------------|
| `pronoun` | `them`, `they`, `it` | Personal/object pronouns |
| `demonstrative` | `those`, `these`, `that`, `this` | Pointing references |
| `definite` | `the same`, `the top`, `the first` | Definite article references |
| `implicit` | Commands without entity | "sort by date" (implicit entity) |

**Resolution Algorithm (`resolve()`):**
1. Detect reference type from question
2. Get focused entity and last query from context
3. If no context available, return unresolved
4. Determine operation type (filter/modify/extend)
5. Build enriched question with resolved references

**Operation Types:**
| Operation | Triggers | Purpose |
|-----------|----------|---------|
| `modify` | sort, order, group | Modify result ordering |
| `extend` | same, also, include | Extend previous query |
| `filter` | filter, where, in, with, by | Apply additional filters |

**Enrichment Logic:**
1. Replace pronouns (`those`, `them`, etc.) with entity name + "s"
2. Convert "and ..." starts to "Show {Entity}s ..."
3. Prepend entity context if question is unchanged

---

## Context Storage (AiConversation Model)

The `context_snapshot` JSON column stores:

```php
[
    'focused_entity' => 'Customer',           // Current entity focus
    'focused_entity_filter' => 'c.status = "active"', // WHERE clause
    'mentioned_entities' => ['Customer', 'Order'],    // All entities discussed
    'last_query_type' => 'list',              // count/list/aggregate/etc
    'last_cypher_query' => 'MATCH (c:Customer)...',
    'last_result_count' => 42,
    'last_result_sample' => [...],            // First 3 results
    'last_relationships' => ['PLACED', 'HAS'],
    'last_answer_summary' => 'Found 42 customers...', // Truncated to 200 chars
    'updated_at' => '2026-01-03T...',
    'referenced_files' => [1, 2, 3],          // File IDs for RAG context
]
```

---

## Integration with AiChatService

The `AiChatService.askWithConversation()` method:
1. Gets schema for entity extraction
2. Processes question through `ConversationContextManager::processQuestion()`
3. Builds prompt context with `buildPromptContext()`
4. Uses enriched question for AI call
5. Records response with `recordResponse()`
6. Stores messages in conversation

Context manager is **lazy-loaded** in `AiChatService::getContextManager()`.

---

## Issues Found

| ID | Severity | Description | Evidence | Recommendation |
|----|----------|-------------|----------|----------------|
| CTX-01 | Low | No context size limit | `context_snapshot` can grow unbounded with `mentioned_entities` | Add max entity limit or periodic pruning |
| CTX-02 | Low | Result sample hardcoded to 3 | Line 115: `array_slice($queryResult['data'] ?? [], 0, 3)` | Make configurable via config |
| CTX-03 | Info | Pronoun replacement adds 's' unconditionally | Line 152: replaces with `strtolower($entity) . 's'` | May produce incorrect plurals for irregular nouns |
| CTX-04 | Low | WHERE extraction regex is greedy | Line 143: May capture beyond WHERE clause in complex queries | Consider more precise Cypher parsing |
| CTX-05 | Info | No error handling in EntityExtractor | `extractFromCypher()` returns empty arrays on parse failure | Silent failures may hide issues |
| CTX-06 | Low | Schema labels not validated | `extractFromQuestion()` trusts `$schema['labels']` | Add type checking for labels array |
| CTX-07 | Info | Implicit reference detection checks capitalization | Line 68: `/\b[A-Z][a-z]+s?\b/` assumes PascalCase | May not match all entity naming conventions |

---

## Code Quality Assessment

**Strengths:**
- Clean separation of concerns (extractor, resolver, manager)
- Well-documented with PHPDoc comments
- Uses Laravel's `Str` helper for pluralization
- Immutable constructor injection
- Type declarations throughout

**Areas for Improvement:**
- Magic numbers (3 results, 200 char limit, 100 char limit)
- No unit tests visible for regex patterns
- Hardcoded patterns could be configurable

---

## Data Flow Diagram

```
User Question
      |
      v
+---------------------+
| processQuestion()   |
|   |                 |
|   +-> extractFromQuestion() ---> entities, query_type
|   |                 |
|   +-> isFollowUp() ---> boolean
|   |                 |
|   +-> resolve() ---> enriched_question (if follow-up)
|   |                 |
|   +-> updateContextSnapshot()
+---------------------+
      |
      v
AI Processing (enriched question + context)
      |
      v
+---------------------+
| recordResponse()    |
|   |                 |
|   +-> extractFromCypher() ---> entities from query
|   |                 |
|   +-> extractEntityFilter() ---> WHERE clause
|   |                 |
|   +-> updateContextSnapshot() ---> persist to DB
+---------------------+
```
