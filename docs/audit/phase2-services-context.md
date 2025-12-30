# Phase 2 Audit: Context Services

**Task:** 26 of 30
**Date:** 2024-12-30
**Auditor:** AI Architectural Review

## Overview

This document reviews all context services in `src/Services/Context/`. These services manage different types of context for the AI chat pipeline:

1. **Conversation Context** - Entity tracking, follow-up detection, reference resolution
2. **File Context** - Physical and database file discovery with access control

---

## 1. ConversationContextManager

**Location:** `src/Services/Context/ConversationContextManager.php`
**Lines:** 153

### Purpose
Orchestrates conversation context tracking by combining entity extraction and reference resolution. This is the main entry point for conversation context handling, enabling the AI to understand follow-up questions and maintain entity focus across a conversation.

### Context Managed
- **Focused Entity**: The main entity currently being discussed
- **Mentioned Entities**: All entities mentioned throughout the conversation
- **Query Type**: Type of query (count, list, aggregate, etc.)
- **Last Cypher Query**: The most recent executed Cypher query
- **Recent Exchanges**: History of user/assistant message pairs

### Dependencies
| Dependency | Type | Purpose |
|------------|------|---------|
| `EntityExtractor` | Service | Extract entities from questions/Cypher |
| `ReferenceResolver` | Service | Resolve pronouns and follow-up references |
| `AiConversation` | Model | Conversation state storage |

### Integration with Chat/AI Pipeline
The `AiChatService` uses this manager via `askWithConversation()`:

```
User Question -> AiChatService.askWithConversation()
                        |
                        v
            ConversationContextManager.processQuestion()
                        |
                        v
            [EntityExtractor + ReferenceResolver]
                        |
                        v
            AiConversation.updateContextSnapshot()
                        |
                        v
            AI::answerQuestion() with enriched context
                        |
                        v
            ConversationContextManager.recordResponse()
```

### Public Methods

| Method | Used | Where Used |
|--------|------|------------|
| `processQuestion(AiConversation, string, array)` | YES | `AiChatService::askWithConversation()`, `AiChatService::prepareQuestionWithContext()` |
| `recordResponse(AiConversation, string, string, array)` | YES | `AiChatService::askWithConversation()` |
| `buildPromptContext(AiConversation, int)` | YES | `AiChatService::askWithConversation()`, `AiChatService::prepareQuestionWithContext()`, internally by `processQuestion()` |

### Notes/Anomalies
1. **All methods used**: No unused public methods
2. **Lazy instantiation in AiChatService**: The chat service creates its own instance rather than using DI
3. **Model dependency**: Relies on `AiConversation` model methods (`getMentionedEntities()`, `getFocusedEntity()`, `getLastCypherQuery()`)

---

## 2. EntityExtractor

**Location:** `src/Services/Context/EntityExtractor.php`
**Lines:** 120

### Purpose
Extracts entity types and query patterns from natural language questions and Cypher queries. Used to track what entities are being discussed in a conversation.

### Context Managed
- **Entity Types**: Labels from schema (e.g., Customer, Order, Product)
- **Query Type**: Classification of question intent (count, list, aggregate, detail, compare)

### Dependencies
| Dependency | Type | Purpose |
|------------|------|---------|
| `Illuminate\Support\Str` | Helper | Pluralization for entity matching |

### Query Type Detection
The service detects query types using regex patterns:

| Type | Pattern | Example Triggers |
|------|---------|------------------|
| `aggregate` | sum, total, average, avg, max, min, revenue | "What is the total revenue?" |
| `count` | how many, count, number of | "How many customers?" |
| `list` | show, list, display, get, find, all | "Show all orders" |
| `detail` | detail, specific, particular, information about | "Details about order #123" |
| `compare` | compare, versus, vs, difference, between | "Compare sales vs last month" |

### Public Methods

| Method | Used | Where Used |
|--------|------|------------|
| `extractFromQuestion(string, array)` | YES | `ConversationContextManager::processQuestion()` |
| `extractFromCypher(string)` | YES | `ConversationContextManager::recordResponse()` |

### Private Methods
- `detectQueryType(string)` - Match question against query type patterns

### Notes/Anomalies
1. **All methods used**: Both public methods are actively used
2. **Plural matching**: Automatically checks both singular and plural forms (e.g., "customer" and "customers")
3. **First-match focus**: First mentioned entity becomes the focused entity
4. **Case insensitive**: All matching is done in lowercase

---

## 3. ReferenceResolver

**Location:** `src/Services/Context/ReferenceResolver.php`
**Lines:** 166

### Purpose
Resolves conversational references like "those", "them", "the same" by using conversation context to understand what entities are being discussed. Enables natural follow-up questions without restating the entity.

### Context Managed
- **Follow-up Detection**: Determines if a question refers to previous context
- **Reference Type**: Classifies the type of reference (pronoun, demonstrative, definite, implicit)
- **Operation Type**: Determines modification intent (filter, modify, extend)

### Dependencies
None - standalone utility class

### Follow-up Detection Patterns
| Pattern | Example |
|---------|---------|
| `^and\s+` | "and show their orders" |
| `^but\s+` | "but only the active ones" |
| `^also\s+` | "also include their address" |
| `^what about\s+` | "what about their payments" |
| `\b(those\|them\|these\|it)\b` | "show those" |
| `^(show\|filter\|sort\|group)\s+(me\s+)?the\s+` | "show me the top 5" |
| `^the\s+(same\|top\|first\|last)\b` | "the same customers" |
| `^(top\|first\|last)\s+\d+` | "top 10 by revenue" |

### Reference Type Classification
| Type | Pattern | Example |
|------|---------|---------|
| `pronoun` | them, they, it | "filter them by status" |
| `demonstrative` | those, these, that, this | "show those details" |
| `definite` | the same, the top, the first, the last, the [plural] | "the same customers" |
| `implicit` | Commands without explicit entity | "top 5 by revenue" |

### Operation Detection
| Operation | Pattern | Purpose |
|-----------|---------|---------|
| `modify` | sort, order, group | Change result ordering/grouping |
| `extend` | same, also, include | Add to previous results |
| `filter` | filter, where, in, with, by | Narrow down results |

### Public Methods

| Method | Used | Where Used |
|--------|------|------------|
| `isFollowUp(string)` | YES | `ConversationContextManager::processQuestion()` |
| `detectReferenceType(string)` | LOW | Only used internally by `resolve()` |
| `resolve(string, array)` | YES | `ConversationContextManager::processQuestion()` |

### Private Methods
- `determineOperation(string)` - Classify the modification intent
- `buildEnrichedQuestion(string, ?string, string)` - Replace pronouns with entity names

### Notes/Anomalies
1. **detectReferenceType() exposure**: Public but only used internally
2. **Enrichment examples**:
   - "show those" -> "show customers" (pronoun replacement)
   - "and filter by status" -> "Show Customers filter by status" (prefix addition)
   - "top 5" -> "Customers: top 5" (context addition)

---

## 4. FileAccessResolver

**Location:** `src/Services/Context/FileAccessResolver.php`
**Interface:** `Condoedge\Ai\Contracts\FileAccessResolverInterface`
**Lines:** 201

### Purpose
Resolves file access permissions for the AI context system. Supports both physical files (documentation) and database-backed files with security enforcement.

### Context Managed
- **Physical vs Database Files**: Physical files (prefixed `physical:`) bypass security
- **Access Control**: Database files filtered by user permissions
- **Security Toggle**: Can be globally enabled/disabled

### Dependencies
| Dependency | Type | Purpose |
|------------|------|---------|
| Config (`ai.file_context.*`) | Configuration | Security settings and resolvers |

### Configuration Options
| Key | Purpose | Default |
|-----|---------|---------|
| `ai.file_context.security_enabled` | Enable security enforcement | `true` |
| `ai.file_context.access_resolver` | Closure `fn($user) => array` | `null` |
| `ai.file_context.file_model` | Eloquent model class | `null` |
| `ai.file_context.access_scope` | Scope method name | `'accessibleBy'` |

### Access Resolution Priority
1. **Closure-based resolver** (if configured)
2. **File model with scope** (if configured)
3. **Empty array** (no access)

### Public Methods

| Method | Used | Where Used |
|--------|------|------------|
| `shouldEnforceSecurity()` | YES | `filterAccessibleFileIds()`, `canAccessFile()` |
| `getAccessibleFileIds(mixed)` | YES | `filterAccessibleFileIds()`, `canAccessFile()` |
| `filterAccessibleFileIds(array, mixed)` | YES | `FileContextProvider::searchRelevantFiles()` |
| `canAccessFile(int\|string, mixed)` | YES | Tests, potential external use |
| `isPhysicalFile(int\|string)` | YES | `filterAccessibleFileIds()`, `canAccessFile()` |
| `makePhysicalFileId(string)` | YES | `PhysicalFileIndexer::generateFileId()` |
| `getPhysicalFilePath(int\|string)` | LOW | Available but no direct callers found |

### Physical File ID Format
```
physical:/path/to/documentation/file.md
```

### Notes/Anomalies
1. **Physical prefix constant**: `PHYSICAL_PREFIX = 'physical:'`
2. **Graceful degradation**: Returns empty array on scope failures
3. **No caching**: Each call re-queries accessible files
4. **getPhysicalFilePath() unused**: Available for extracting path from physical IDs but not actively used

---

## 5. FileContextProvider

**Location:** `src/Services/Context/FileContextProvider.php`
**Lines:** 227

### Purpose
Provides unified file search across both physical documentation files and database-backed files. Applies access control filtering and transforms results into a standard format for AI context building.

### Context Managed
- **Relevant Files**: Files matching the user's question
- **Source Type**: Physical vs database origin
- **File Metadata**: Name, snippet, relevance score, chunk index

### Dependencies
| Dependency | Type | Purpose |
|------------|------|---------|
| `FileSearchService` | Service | Semantic search across file chunks |
| `FileAccessResolverInterface` | Contract | Access control filtering |

### Configuration Options
| Key | Purpose | Default |
|-----|---------|---------|
| `ai.file_context.min_relevance_score` | Minimum relevance threshold | `0.7` |
| `ai.file_context.max_references` | Maximum files to return | `5` |
| `ai.file_context.snippet_length` | Truncation length for snippets | `200` |

### Integration with AI Pipeline
Used by `AiManager::answerQuestion()`:

```
User Question -> AiManager::answerQuestion()
                        |
                        v
            AiManager::retrieveFileContext()
                        |
                        v
            FileContextProvider::getFileContext()
                        |
                        v
            FileSearchService::searchByContent()
                        |
                        v
            FileAccessResolver::filterAccessibleFileIds()
                        |
                        v
            Filtered & Sorted Results -> AI Context
```

### Public Methods

| Method | Used | Where Used |
|--------|------|------------|
| `searchRelevantFiles(string, mixed, array)` | YES | `getFileContext()` |
| `buildFileReference(int, int\|string, string, string, float, int, string)` | NO | Not used anywhere |
| `getFileContext(string, mixed)` | YES | `AiManager::retrieveFileContext()` |

### Private Methods
- `isPhysicalFile(int|string)` - Duplicate of FileAccessResolver logic
- `truncateSnippet(string, int)` - Truncate content with ellipsis

### Output Format
```php
[
    'relevant_files' => [
        [
            'file_id' => 'physical:/docs/guide.md',
            'file_name' => 'guide.md',
            'snippet' => 'First 200 chars...',
            'relevance_score' => 0.85,
            'chunk_index' => 0,
            'source' => 'physical',
        ],
        // ...
    ],
    'file_count' => 3,
    'has_physical' => true,
    'has_database' => true,
]
```

### Notes/Anomalies
1. **buildFileReference() unused**: Public method with no callers
2. **Duplicate isPhysicalFile()**: Same logic as FileAccessResolver
3. **Duplicate PHYSICAL_PREFIX**: Constant defined in both classes
4. **Over-fetching**: Requests 3x the limit to allow for filtering

---

## 6. Context Flow Diagram

```
                        CONVERSATION CONTEXT FLOW
                        ==========================

User Message ---------> AiChatService.askWithConversation()
                               |
                               v
                    ConversationContextManager.processQuestion()
                               |
                    +----------+----------+
                    |                     |
                    v                     v
            EntityExtractor      ReferenceResolver
            .extractFromQuestion() .isFollowUp()
                    |                     |
                    |                     v
                    |           ReferenceResolver.resolve()
                    |                     |
                    +----------+----------+
                               |
                               v
                    AiConversation.updateContextSnapshot()
                               |
                               v
                    ConversationContextManager.buildPromptContext()
                               |
                               v
                    +----------+----------+
                    |                     |
                    v                     v
            Enriched Question    Conversation Context
                    |                     |
                    +----------+----------+
                               |
                               v
                    AI::answerQuestion() (with context)
                               |
                               v
                    ConversationContextManager.recordResponse()
                               |
                               v
                    EntityExtractor.extractFromCypher()
                               |
                               v
                    AiConversation.updateContextSnapshot()


                        FILE CONTEXT FLOW
                        =================

User Question --------> AiManager.answerQuestion()
                               |
            (if ai.file_context.enabled)
                               |
                               v
                    AiManager.retrieveFileContext()
                               |
                               v
                    FileContextProvider.getFileContext()
                               |
                               v
                    FileSearchService.searchByContent()
                               |
                               v
                    FileAccessResolver.filterAccessibleFileIds()
                               |
              +----------------+----------------+
              |                                 |
              v                                 v
       Physical Files                    Database Files
       (always accessible)              (permission checked)
              |                                 |
              +----------------+----------------+
                               |
                               v
                    Merged & Sorted by Score
                               |
                               v
                    Added to AI Context as 'file_context'
                               |
                               v
                    ResponseFileEnricher.enrichResponse()
                               |
                               v
                    Final Response with referenced_files
```

---

## 7. Service Provider Registration

```php
// In AiServiceProvider::registerContextServices()
EntityExtractor::class              -> Singleton
ReferenceResolver::class            -> Singleton
ConversationContextManager::class   -> Singleton (with dependencies)

// In AiServiceProvider::registerFileContextServices()
FileAccessResolverInterface::class  -> FileAccessResolver (Singleton)
FileAccessResolver::class           -> Singleton
FileContextProvider::class          -> Singleton (with dependencies)
```

---

## 8. Usage Summary

### Integration Points

| Service | Consumer | Method |
|---------|----------|--------|
| ConversationContextManager | AiChatService | `askWithConversation()`, `prepareQuestionWithContext()` |
| EntityExtractor | ConversationContextManager | `processQuestion()`, `recordResponse()` |
| ReferenceResolver | ConversationContextManager | `processQuestion()` |
| FileAccessResolver | FileContextProvider | `searchRelevantFiles()` |
| FileContextProvider | AiManager | `retrieveFileContext()` |

### Conditional Usage
- **FileContextProvider**: Only used when `config('ai.file_context.enabled', true)` is true
- **ConversationContextManager**: Only used with `askWithConversation()`, not with `ask()` or `askWithHistory()`

---

## 9. Issues and Recommendations

### 9.1 Unused Code

| Service | Unused Items |
|---------|--------------|
| FileContextProvider | `buildFileReference()` method |
| ReferenceResolver | `detectReferenceType()` only used internally |
| FileAccessResolver | `getPhysicalFilePath()` method |

**Recommendation:** Mark `detectReferenceType()` as `protected` or document its public availability for external use. Consider removing `buildFileReference()` if not planned for future use.

### 9.2 Code Duplication

| Duplication | Location |
|-------------|----------|
| `PHYSICAL_PREFIX` constant | FileAccessResolver, FileContextProvider |
| `isPhysicalFile()` method | FileAccessResolver, FileContextProvider |

**Recommendation:** FileContextProvider should use FileAccessResolver's methods instead of duplicating.

### 9.3 Lazy Instantiation in AiChatService

**Issue:** `AiChatService::getContextManager()` creates instances directly instead of using DI:
```php
$this->contextManager = new ConversationContextManager(
    new EntityExtractor(),
    new ReferenceResolver()
);
```

**Recommendation:** Inject via constructor or use `app()->make()`:
```php
$this->contextManager = app(ConversationContextManager::class);
```

### 9.4 Missing Interface

| Class | Recommendation |
|-------|----------------|
| FileContextProvider | Create `FileContextProviderInterface` |

### 9.5 Performance Consideration

**Issue:** `FileAccessResolver::getAccessibleFileIds()` is called for each filtering operation without caching.

**Recommendation:** Add short-term caching for user's accessible file list, similar to DataIngestionService's collection caching.

---

## 10. Test Coverage

### Existing Tests
- `tests/Unit/Services/Context/ConversationContextManagerTest.php`
- `tests/Unit/Services/Context/EntityExtractorTest.php`
- `tests/Unit/Services/Context/ReferenceResolverTest.php`
- `tests/Unit/Services/Context/FileAccessResolverTest.php`
- `tests/Unit/Services/Context/FileContextProviderTest.php`
- `tests/Integration/ConversationContextIntegrationTest.php`
- `tests/Unit/Services/Chat/AiChatServiceContextTest.php`

### Recommended Additional Tests
1. Edge cases for entity extraction with similar labels (e.g., "Order" vs "OrderItem")
2. Follow-up detection with complex patterns
3. Security enforcement edge cases (null user, disabled security)
4. File context with mixed physical/database results
5. Reference resolution with missing context

---

## 11. Summary

The Context services provide two distinct but complementary context systems:

| System | Services | Purpose |
|--------|----------|---------|
| **Conversation Context** | ConversationContextManager, EntityExtractor, ReferenceResolver | Enable natural multi-turn conversations with entity tracking and follow-up detection |
| **File Context** | FileContextProvider, FileAccessResolver | Provide relevant file content for RAG with security enforcement |

**Key Observations:**
1. All services are properly registered in the service provider
2. Conversation context is well-integrated via AiChatService
3. File context is conditionally integrated via AiManager
4. Some code duplication exists between file context services
5. Good test coverage exists for all services
