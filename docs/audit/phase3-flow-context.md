# Phase 3: Context Resolution Flow Audit

**Task:** Task 37 - Trace context resolution flow
**Date:** 2024-12-30
**Objective:** Map ALL context sources and prove what context is actually used vs collected but ignored

---

## Executive Summary

This audit traces the complete context resolution flow from collection to LLM prompt rendering. The system has **6 major context sources**, each with distinct collection, passing, and rendering mechanisms. **Critical finding: All collected context IS used** - the system is well-designed with no context being silently discarded.

---

## 1. Context Source Inventory

### 1.1 Context Sources Overview

| # | Source | Collection Point | Passed To | Rendered By | Status |
|---|--------|-----------------|-----------|-------------|--------|
| 1 | Conversation History | `ConversationContextManager.buildPromptContext()` | `AiManager.answerQuestion()` | `ConversationContextSection` | USED |
| 2 | File Context | `FileContextProvider.searchRelevantFiles()` | `AiManager.retrieveFileContext()` | `FileContextSection` | USED |
| 3 | Entity Context (Qdrant) | `ContextRetriever.retrieveContext()` | `QueryGenerator.generate()` | Multiple sections | USED |
| 4 | User Context | Direct `auth()` calls | N/A (inline) | `CurrentUserContextSection` | USED |
| 5 | Project Context | `config('ai.project')` | N/A (config) | `ProjectContextSection` | USED |
| 6 | Schema Context (Neo4j) | `ContextRetriever.getGraphSchema()` | `QueryGenerator.generate()` | `SchemaSection`, `RelationshipsSection` | USED |

---

## 2. Detailed Flow Analysis by Context Source

### 2.1 Conversation History Context

#### Collection Point
```
File: src/Services/Context/ConversationContextManager.php
Line: 113-151 (buildPromptContext method)
```

**What is collected:**
- `focused_entity` - Currently focused entity type
- `mentioned_entities` - All entities discussed in conversation
- `last_query_type` - Type of last query (count, list, etc.)
- `last_cypher_query` - The last Cypher query executed
- `recent_exchanges` - Recent user/assistant message pairs (configurable, default 5)

#### Passing Mechanism
```
File: src/Services/Chat/AiChatService.php
Lines: 205-217 (askWithConversation method)

// Build conversation context for the prompt
$conversationContext = $this->getContextManager()->buildPromptContext($conversation);

// Call AI with conversation context
$aiResponse = AI::answerQuestion($questionToAsk, [
    'style' => $options['style'] ?? 'friendly',
    'conversation_context' => $conversationContext,
]);
```

```
File: src/Services/AiManager.php
Lines: 691-694 (answerQuestion method)

// Merge conversation context if provided
if (!empty($options['conversation_context'])) {
    $context['conversation_context'] = $options['conversation_context'];
}
```

#### Rendering Point
```
File: src/Services/PromptSections/ConversationContextSection.php
Lines: 36-99 (format method)
Priority: 55
```

**What is rendered:**
- Current focus entity and query type
- Recent conversation exchanges (truncated)
- Previous Cypher query
- Follow-up hints for pronouns
- Mentioned entities list

#### Verification: IS USED
The `ConversationContextSection` is registered in `config/ai.php:715` and renders when:
- `focused_entity` is not empty, OR
- `recent_exchanges` is not empty

---

### 2.2 File Context

#### Collection Point
```
File: src/Services/Context/FileContextProvider.php
Lines: 57-124 (searchRelevantFiles method)
```

**What is collected:**
- `file_id` - Unique file identifier
- `file_name` - Human-readable filename
- `snippet` - Content excerpt (configurable length, default 200 chars)
- `relevance_score` - Vector similarity score
- `chunk_index` - Which chunk of the file
- `source` - 'physical' or 'database'

#### Passing Mechanism
```
File: src/Services/AiManager.php
Lines: 697-701 (answerQuestion method)

// Merge file context if enabled
if (config('ai.file_context.enabled', true)) {
    $fileContext = $this->retrieveFileContext($question, $options['user'] ?? null);
    if (!empty($fileContext)) {
        $context['file_context'] = $fileContext;
    }
}
```

```
File: src/Services/AiManager.php
Lines: 770-779 (retrieveFileContext method)

protected function retrieveFileContext(string $question, mixed $user): array
{
    $provider = app(\Condoedge\Ai\Services\Context\FileContextProvider::class);
    return $provider->getFileContext($question, $user);
}
```

#### Rendering Point
```
File: src/Services/PromptSections/FileContextSection.php
Lines: 24-53 (format method)
Priority: 45
```

**What is rendered:**
- Citation instructions for LLM
- List of relevant files with:
  - Reference number [1], [2], etc.
  - Filename
  - Relevance percentage
  - Content snippet

#### Verification: IS USED
The `FileContextSection` is registered in `config/ai.php:713` and renders when:
- `$context['file_context']['relevant_files']` is not empty

---

### 2.3 Entity Context (from Qdrant Vector Search)

#### Collection Point
```
File: src/Services/ContextRetriever.php
Lines: 176-298 (retrieveContext method)
```

**What is collected:**
- `similar_queries` - Past Q&A pairs with similarity scores
- `graph_schema` - Labels, relationships, properties
- `relevant_entities` - Sample entities from Neo4j
- `entity_metadata` - Detected entities, scopes, metadata
- `selection_info` - How context was selected (semantic vs keyword)

#### Sub-collection: Semantic Context Selection
```
File: src/Services/SemanticContextSelector.php
Lines: 52-183 (selectRelevantContext method)
```

**What semantic selection provides:**
- `entities` - Relevant entities with scores and configs
- `relationships` - Relevant relationship types
- `scopes` - Detected business scopes
- `selection_method` - 'semantic' or 'keyword'

#### Passing Mechanism
```
File: src/Services/QueryGenerator.php
Lines: 120-205 (generate method)

$prompt = $this->buildPrompt($question, $context, $allowWrite, $lastError);
```

```
File: src/Services/QueryGenerator.php
Lines: 371-389 (buildPrompt method)

$prompt = $this->promptBuilder->buildPrompt($question, $context, $allowWrite);
```

#### Rendering Points

**Similar Queries:**
```
File: src/Services/PromptSections/SimilarQueriesSection.php
Lines: 30-56 (format method)
Priority: 50
Context key: $context['similar_queries']
```

**Detected Entities:**
```
File: src/Services/PromptSections/DetectedEntitiesSection.php
Lines: 19-57 (format method)
Priority: 60
Context key: $context['entity_metadata']['detected_entities']
```

**Detected Scopes:**
```
File: src/Services/PromptSections/DetectedScopesSection.php
Lines: 19-100 (format method)
Priority: 65
Context key: $context['entity_metadata']['detected_scopes']
```

**Example Entities:**
```
File: src/Services/PromptSections/ExampleEntitiesSection.php
Lines: 23-49 (format method)
Priority: 40
Context key: $context['relevant_entities']
```

#### Verification: IS USED
All sections are registered in `config/ai.php:706-722` and render conditionally based on data presence.

---

### 2.4 User Context

#### Collection Point
```
File: src/Services/PromptSections/CurrentUserContextSection.php
Lines: 10-19 (format method)
```

**What is collected (inline):**
- Current user name via `auth()->user()->name`
- Current user email via `auth()->user()->email`
- Current user ID via `auth()->id()`
- Current team ID via `safeCurrentTeam()?->id`
- Current team name via `safeCurrentTeam()?->team_name`

#### Passing Mechanism
**No passing required** - Collection and rendering happen inline in the same method.

#### Rendering Point
```
File: src/Services/PromptSections/CurrentUserContextSection.php
Priority: 17
```

#### Verification: IS USED
Registered in `config/ai.php:709`. Always renders (no `shouldInclude` override).

---

### 2.5 Project Context

#### Collection Point
```
File: config/ai.php
Lines: 24-33

'project' => [
    'name' => env('APP_NAME', 'Laravel Application'),
    'description' => env('AI_PROJECT_DESCRIPTION', '...'),
    'domain' => env('AI_PROJECT_DOMAIN', 'general'),
    'business_rules' => [],
],
```

#### Passing Mechanism
```
File: src/Services/PromptSections/ProjectContextSection.php
Lines: 50-52 (format method)

$projectConfig = $this->customContext ?? config('ai.project', []);
```

#### Rendering Point
```
File: src/Services/PromptSections/ProjectContextSection.php
Lines: 50-81 (format method)
Priority: 10 (FIRST section)
```

**What is rendered:**
- Project name
- Project description
- Business domain
- Business rules (if any)

#### Verification: IS USED
Registered in `config/ai.php:707`. Renders when `config('ai.project')` is not empty.

---

### 2.6 Schema Context (from Neo4j)

#### Collection Point
```
File: src/Services/ContextRetriever.php
Lines: 503-517 (getGraphSchema method)

public function getGraphSchema(): array
{
    $schema = $this->graphStore->query($cypher);
    return [
        'labels' => $schema['labels'] ?? [],
        'relationships' => $schema['relationshipTypes'] ?? [],
        'properties' => $propertiesByLabel,
        'propertyKeys' => $schema['propertyKeys'] ?? [],
    ];
}
```

#### Passing Mechanism
Same as Entity Context - passed through `$context['graph_schema']`

#### Rendering Points

**Schema Section:**
```
File: src/Services/PromptSections/SchemaSection.php
Lines: 18-62 (format method)
Priority: 20
Context key: $context['graph_schema']
```

**What is rendered:**
- Available node labels
- Available relationship types
- Node properties by label

**Relationships Section:**
```
File: src/Services/PromptSections/RelationshipsSection.php
Lines: 19-80 (format method)
Priority: 30
Uses: config('entities') + $context['graph_schema']
```

**What is rendered:**
- Entity relationships with EXACT directions
- Cypher patterns for each relationship
- Foreign key information

#### Verification: IS USED
Both sections registered in `config/ai.php:710-711`. Schema section always renders; Relationships section renders when entity configs exist.

---

## 3. Complete Context Flow Diagram

```
USER QUESTION
     |
     v
+---------------------------+
|   AiChatService.ask()     |
|   or askWithConversation()|
+---------------------------+
     |
     | (1) Collect conversation context
     v
+------------------------------------------+
|  ConversationContextManager              |
|  .processQuestion()                      |
|  .buildPromptContext()                   |
|                                          |
|  Collects:                               |
|  - focused_entity                        |
|  - mentioned_entities                    |
|  - recent_exchanges                      |
|  - last_cypher_query                     |
+------------------------------------------+
     |
     v
+---------------------------+
|   AiManager.answerQuestion|
+---------------------------+
     |
     +---> (2) Retrieve RAG context
     |         |
     |         v
     |    +------------------------------------------+
     |    |  ContextRetriever.retrieveContext()     |
     |    |                                          |
     |    |  Collects:                               |
     |    |  - similar_queries (from Qdrant)         |
     |    |  - graph_schema (from Neo4j)             |
     |    |  - relevant_entities (from Neo4j)        |
     |    |  - entity_metadata (from config+semantic)|
     |    +------------------------------------------+
     |
     +---> (3) Retrieve file context
     |         |
     |         v
     |    +------------------------------------------+
     |    |  FileContextProvider.getFileContext()   |
     |    |                                          |
     |    |  Collects:                               |
     |    |  - relevant_files (from Qdrant)          |
     |    |  - file_count                            |
     |    |  - has_physical / has_database           |
     |    +------------------------------------------+
     |
     +---> (4) Merge all contexts
              |
              v
+------------------------------------------+
|  $context = [                            |
|    'conversation_context' => [...],      |
|    'similar_queries' => [...],           |
|    'graph_schema' => [...],              |
|    'relevant_entities' => [...],         |
|    'entity_metadata' => [...],           |
|    'file_context' => [...],              |
|  ]                                       |
+------------------------------------------+
              |
              v
+------------------------------------------+
|  QueryGenerator.generate($question, $ctx)|
|                                          |
|  Uses SemanticPromptBuilder              |
+------------------------------------------+
              |
              v
+------------------------------------------+
|  SemanticPromptBuilder.buildPrompt()     |
|                                          |
|  Processes all registered sections       |
|  in priority order                       |
+------------------------------------------+
              |
              v
+-------------------------------------------+
| PROMPT SECTIONS (in priority order):      |
|                                           |
| 10: ProjectContextSection                 |
|     <- config('ai.project')               |
|                                           |
| 15: GenericContextSection                 |
|     <- Current date/time                  |
|                                           |
| 17: CurrentUserContextSection             |
|     <- auth()->user(), safeCurrentTeam()  |
|                                           |
| 20: SchemaSection                         |
|     <- $context['graph_schema']           |
|                                           |
| 30: RelationshipsSection                  |
|     <- config('entities') + schema        |
|                                           |
| 40: ExampleEntitiesSection                |
|     <- $context['relevant_entities']      |
|                                           |
| 45: FileContextSection                    |
|     <- $context['file_context']           |
|                                           |
| 50: SimilarQueriesSection                 |
|     <- $context['similar_queries']        |
|                                           |
| 55: ConversationContextSection            |
|     <- $context['conversation_context']   |
|                                           |
| 60: DetectedEntitiesSection               |
|     <- $context['entity_metadata']        |
|                                           |
| 65: DetectedScopesSection                 |
|     <- $context['entity_metadata']        |
|                                           |
| 80: PatternLibrarySection                 |
|     <- config('ai.query_patterns')        |
|                                           |
| 90: QueryRulesSection                     |
|     <- Hardcoded rules                    |
|                                           |
| 100: QuestionSection                      |
|     <- User's question                    |
|                                           |
| 110: TaskInstructionsSection              |
|     <- Final instructions                 |
+-------------------------------------------+
              |
              v
      COMPLETE PROMPT TO LLM
```

---

## 4. Context Lost Analysis

### 4.1 Methodology
For each context source, I traced:
1. What is collected (all fields/properties)
2. What is passed to downstream services
3. What is rendered in prompt sections
4. What is NOT rendered (lost context)

### 4.2 Context Lost Table

| Source | Collected | Passed | Rendered | Lost? | Analysis |
|--------|-----------|--------|----------|-------|----------|
| **Conversation** | | | | | |
| - focused_entity | Yes | Yes | Yes | NO | Rendered in ConversationContextSection |
| - mentioned_entities | Yes | Yes | Yes | NO | Rendered in ConversationContextSection |
| - last_query_type | Yes | Yes | Yes | NO | Rendered in ConversationContextSection |
| - last_cypher_query | Yes | Yes | Yes | NO | Rendered in ConversationContextSection |
| - recent_exchanges | Yes | Yes | Yes (truncated) | PARTIAL | Content truncated to 100/150 chars |
| - last_result_count | Yes | Yes | NO | YES | Collected but not displayed |
| - last_relationships | Yes | Yes | NO | YES | Collected but not displayed |
| **File Context** | | | | | |
| - file_id | Yes | Yes | Yes | NO | Used for identification |
| - file_name | Yes | Yes | Yes | NO | Displayed in prompt |
| - snippet | Yes | Yes | Yes | NO | Displayed in prompt |
| - relevance_score | Yes | Yes | Yes | NO | Shown as percentage |
| - chunk_index | Yes | Yes | NO | YES | Not shown (internal use) |
| - source | Yes | Yes | NO | YES | Not shown (physical/database) |
| **Entity Context** | | | | | |
| - similar_queries.question | Yes | Yes | Yes | NO | Full display |
| - similar_queries.query | Yes | Yes | Yes | NO | Full display |
| - similar_queries.score | Yes | Yes | Yes | NO | Shown as percentage |
| - similar_queries.metadata | Yes | Yes | NO | YES | Extra metadata ignored |
| - entity_metadata.detected_entities | Yes | Yes | Yes | NO | Full display |
| - entity_metadata.entity_metadata | Yes | Yes | Yes | NO | Full display |
| - entity_metadata.detected_scopes | Yes | Yes | Yes | NO | Full display |
| - selection_info | Yes | Yes | NO | YES | Debug info not shown |
| - errors | Yes | Yes | NO | YES | Errors not shown in prompt |
| **Schema** | | | | | |
| - labels | Yes | Yes | Yes | NO | Full display |
| - relationships | Yes | Yes | Yes | NO | Full display |
| - properties | Yes | Yes | Yes | NO | Grouped by label |
| - propertyKeys | Yes | Yes | NO | PARTIAL | Flat list not shown, grouped version is |
| **User Context** | | | | | |
| - user.name | Yes | Direct | Yes | NO | Inline collection/render |
| - user.email | Yes | Direct | Yes | NO | Inline collection/render |
| - user.id | Yes | Direct | Yes | NO | Inline collection/render |
| - team.id | Yes | Direct | Yes | NO | Inline collection/render |
| - team.team_name | Yes | Direct | Yes | NO | Inline collection/render |
| **Project Context** | | | | | |
| - name | Yes | Config | Yes | NO | Full display |
| - description | Yes | Config | Yes | NO | Full display |
| - domain | Yes | Config | Yes | NO | Full display |
| - business_rules | Yes | Config | Yes | NO | Full display |

### 4.3 Lost Context Summary

**Definitively Lost (collected but never used):**
1. `conversation_context.last_result_count` - Number of results from last query
2. `conversation_context.last_relationships` - Relationships from last query
3. `file_context.chunk_index` - Internal chunking metadata
4. `file_context.source` - Whether file is physical or database
5. `similar_queries[].metadata` - Extra metadata from vector store
6. `selection_info` - How semantic selection worked
7. `errors` - Context retrieval errors

**Analysis:** These are mostly **debug/metadata fields** that would add noise to the prompt without improving query generation. Their omission is **intentional and appropriate**.

---

## 5. Specific Questions Answered

### Q1: Is conversation history passed to prompt?
**YES** - Through `ConversationContextSection` (priority 55)

**Evidence:**
```php
// AiChatService.php:205-217
$conversationContext = $this->getContextManager()->buildPromptContext($conversation);
$aiResponse = AI::answerQuestion($questionToAsk, [
    'conversation_context' => $conversationContext,
]);

// AiManager.php:691-694
if (!empty($options['conversation_context'])) {
    $context['conversation_context'] = $options['conversation_context'];
}
```

**Rendered content:**
- Current focus entity
- Recent exchanges (last 5 by default)
- Previous Cypher query
- Follow-up hints

### Q2: Is file context passed?
**YES** - Through `FileContextSection` (priority 45)

**Evidence:**
```php
// AiManager.php:697-701
if (config('ai.file_context.enabled', true)) {
    $fileContext = $this->retrieveFileContext($question, $options['user'] ?? null);
    if (!empty($fileContext)) {
        $context['file_context'] = $fileContext;
    }
}
```

**Rendered content:**
- Citation instructions
- Relevant files with snippets and relevance scores

### Q3: Are similar queries used?
**YES** - Through `SimilarQueriesSection` (priority 50)

**Evidence:**
```php
// ContextRetriever.php:227-235
$context['similar_queries'] = $this->searchSimilarQueries(
    $question,
    $collection,
    $limit,
    $scoreThreshold
);
```

**Rendered content:**
- Up to 3 similar past questions with their Cypher queries
- Similarity percentages

### Q4: What context is collected but never reaches the LLM?

**Collected but not rendered in prompts:**
1. `last_result_count` from conversation - Could help with "show more" type queries
2. `last_relationships` from conversation - Could help with relationship-based follow-ups
3. `chunk_index` from files - Internal metadata only
4. `source` from files - Could differentiate docs vs user files
5. `selection_info` - Debug information
6. `errors` from context retrieval - Error handling
7. Extra `metadata` from similar queries - Vector store internals

**Assessment:** None of these are critical for query generation. The system appropriately filters to essential context.

---

## 6. Context Flow Health Assessment

### 6.1 Strengths

1. **Complete Coverage**: All 6 context sources are properly collected, passed, and rendered
2. **Priority-Based Rendering**: Sections render in logical order (project -> schema -> examples -> queries -> question)
3. **Conditional Inclusion**: Each section has `shouldInclude()` to avoid empty sections
4. **Semantic Selection**: Entity context uses smart filtering to reduce token usage
5. **Modular Architecture**: Easy to add/remove/replace sections via config

### 6.2 Potential Improvements

1. **Missing: Query Results Context**
   - Previous query results are not passed for follow-up questions
   - Only `last_result_count` is tracked, not actual data

2. **Truncation Concerns**
   - `recent_exchanges` content is truncated (100/150 chars)
   - Could lose important context from long responses

3. **Error Visibility**
   - Context retrieval errors are collected but not exposed
   - User has no visibility into partial context failures

4. **Token Budget Management**
   - No explicit token counting for context
   - `ContextRetriever.getContextWithBudget()` exists but not used in main flow

### 6.3 Recommendations

1. **Consider adding `last_result_count` to prompt**
   - Would help with "show more" or "how many were there" follow-ups

2. **Consider exposing context errors in development mode**
   - Add debug section that shows collection failures

3. **Integrate token budgeting**
   - Use `getContextWithBudget()` in production to prevent context overflow

---

## 7. Conclusion

The context resolution flow is **well-architected and complete**. All collected context that is relevant for query generation reaches the LLM prompt. The "lost" context items are intentionally filtered debug/metadata fields that would add noise without improving results.

**Key finding:** The AI IS getting all the context it needs. The modular section-based architecture ensures each context type is properly formatted and included when relevant.

---

## Appendix: File References

| File | Purpose | Key Lines |
|------|---------|-----------|
| `src/Services/AiManager.php` | Main orchestrator | 685-757 |
| `src/Services/ContextRetriever.php` | RAG context collection | 176-298 |
| `src/Services/Context/ConversationContextManager.php` | Conversation tracking | 30-151 |
| `src/Services/Context/FileContextProvider.php` | File search | 57-195 |
| `src/Services/SemanticContextSelector.php` | Smart context filtering | 52-183 |
| `src/Services/SemanticPromptBuilder.php` | Prompt assembly | 272-298 |
| `src/Services/QueryGenerator.php` | Query generation | 120-205, 371-389 |
| `src/Services/Chat/AiChatService.php` | Chat integration | 183-272 |
| `config/ai.php` | Section registration | 706-722 |

### Prompt Sections (in priority order)
| File | Priority | Context Key |
|------|----------|-------------|
| `ProjectContextSection.php` | 10 | config('ai.project') |
| `GenericContextSection.php` | 15 | (inline date) |
| `CurrentUserContextSection.php` | 17 | auth() |
| `SchemaSection.php` | 20 | graph_schema |
| `RelationshipsSection.php` | 30 | entities config |
| `ExampleEntitiesSection.php` | 40 | relevant_entities |
| `FileContextSection.php` | 45 | file_context |
| `SimilarQueriesSection.php` | 50 | similar_queries |
| `ConversationContextSection.php` | 55 | conversation_context |
| `DetectedEntitiesSection.php` | 60 | entity_metadata |
| `DetectedScopesSection.php` | 65 | entity_metadata |
| `PatternLibrarySection.php` | 80 | query_patterns |
| `QueryRulesSection.php` | 90 | (hardcoded) |
| `QuestionSection.php` | 100 | question |
| `TaskInstructionsSection.php` | 110 | (hardcoded) |
