# Module 15: DATA_MODELS - Findings

> **Status:** COMPLETE

## Summary

The data models layer consists of 4 Eloquent models and 2 model plugins. The models follow Laravel conventions and are reasonably thin, though `AiConversation` contains some accessor methods that border on business logic. The plugins integrate via the `FileModel` facade from `condoedge/utils`.

## Model Overview

### AiConversation (222 lines)
- **Location:** `src/Models/AiConversation.php`
- **Purpose:** Represents a chat conversation with context tracking
- **Key Fields:** uuid, user_id, team_id, title, status, metadata (JSON), context_snapshot (JSON), last_message_at
- **Relationships:** hasMany(AiMessage), belongsTo(User)
- **Traits:** SoftDeletes
- **Notable:** Auto-generates UUID on creation, auto-titles from first user message

### AiMessage (52 lines)
- **Location:** `src/Models/AiMessage.php`
- **Purpose:** Individual messages within a conversation
- **Key Fields:** conversation_id, role, content, response_data (JSON), context_used (JSON), cypher_query, execution_time_ms, confidence_score, metadata (JSON)
- **Relationships:** belongsTo(AiConversation)
- **Assessment:** Very thin model, properly follows data-only pattern

### AiQueryLog (37 lines)
- **Location:** `src/Models/AiQueryLog.php`
- **Purpose:** Analytics/audit logging for AI queries
- **Key Fields:** user_id, team_id, conversation_id, question, cypher_query, template_used, confidence_score, execution_time_ms, result_count, status, error_message, context_stats (JSON), metadata (JSON)
- **Assessment:** Thin model with two static factory methods (logSuccess, logFailure)

### AiUserSetting (123 lines)
- **Location:** `src/Models/AiUserSetting.php`
- **Purpose:** Per-user chat UI preferences
- **Key Fields:** user_id, ui_theme, ui_colors (JSON), show_avatars, show_timestamps, show_metrics, show_suggestions, enable_copy, enable_feedback, enable_regenerate, enable_edit, response_style, enable_animations, animation_speed, typing_animation_style
- **Relationships:** belongsTo(User)
- **Notable:** Uses boot hook to apply config defaults on creation

## Plugin Overview

### FileAccessScopePlugin (53 lines)
- **Location:** `src/Models/Plugins/FileAccessScopePlugin.php`
- **Purpose:** Registers `accessibleBy` scope macro on Eloquent Builder
- **Integration:** Registered via `FileModel::setPlugins()` in AiServiceProvider (line 642-644)
- **Behavior:** Filters files by user_id OR team_id based on config settings

### FileProcessingPlugin (369 lines)
- **Location:** `src/Models/Plugins/FileProcessingPlugin.php`
- **Purpose:** Coordinates dual-storage (Neo4j + Qdrant) for File models
- **Integration:** Registered via `FileModel::setPlugins()` in AiServiceProvider (line 642-644)
- **Events:** Listens to created, updated, deleting events on File model
- **Behavior:**
  - On create: Syncs to Neo4j (if Nodeable), processes content for Qdrant
  - On update: Resyncs Neo4j, reprocesses content if path changed
  - On delete: Removes from both Neo4j and Qdrant
- **Features:** Supports sync/async processing, queue configuration, fail-silently mode

## Context Snapshot Field Documentation

The `context_snapshot` JSON field in `AiConversation` stores conversational context for reference resolution in follow-up questions. It is managed by `ConversationContextManager`.

### Structure
```php
[
    'focused_entity' => string|null,        // Currently discussed entity type (e.g., 'Customer')
    'focused_entity_filter' => string|null, // WHERE clause conditions from last Cypher query
    'focused_entity_data' => array,         // Entity data from query results
    'mentioned_entities' => array,          // All entity types mentioned in conversation
    'last_relationships' => array,          // Relationships from last Cypher query
    'last_cypher_query' => string|null,     // Previous Cypher query for reference
    'last_query_type' => string|null,       // Type: count, list, aggregate, etc.
    'last_result_count' => int,             // Number of results from last query
    'last_result_sample' => array,          // First 3 results for context
    'last_answer_summary' => string|null,   // Truncated last response (200 chars)
    'referenced_files' => array,            // IDs of files referenced in conversation
    'updated_at' => string,                 // ISO8601 timestamp of last update
]
```

### Accessor Methods on AiConversation
- `getFocusedEntity()` - Returns focused_entity
- `getFocusedEntityFilter()` - Returns focused_entity_filter
- `getLastQueryType()` - Returns last_query_type
- `getMentionedEntities()` - Returns mentioned_entities array
- `getLastResultSample()` - Returns last_result_sample array
- `getLastResultCount()` - Returns last_result_count (default 0)
- `getPreviousCypherQuery()` - Returns last_cypher_query
- `getLastCypherQuery()` - Queries messages table for most recent assistant message with cypher_query

### Update Methods
- `updateContextSnapshot(array $snapshot)` - Full replacement
- `updateEntityContext(array $entityData)` - Updates focused_entity_data only

## Issues Found
| ID | Severity | Description | Evidence | Recommendation |
|----|----------|-------------|----------|----------------|
| DM-01 | LOW | AiConversation has ~14 accessor/helper methods | Lines 79-170, 175-213 | Consider extracting to a dedicated ContextAccessor or keeping as-is since they're data accessors |
| DM-02 | LOW | AiConversation.addMessage() contains auto-title logic | Lines 196-199 | Consider moving auto-title to observer or keeping as-is (minimal impact) |
| DM-03 | INFO | FileProcessingPlugin is substantial (369 lines) | Coordinates dual-storage | Appropriate for plugin pattern - keeps File model clean |
| DM-04 | INFO | Plugins depend on condoedge/utils FileModel | AiServiceProvider lines 641-649 | External dependency, gracefully handles missing facade |

## Architecture Assessment

### Thin Models Verification
- **AiMessage:** PASS - Pure data model with 2 accessor methods
- **AiQueryLog:** PASS - Pure data model with 2 factory methods
- **AiUserSetting:** PASS - Mostly accessors and defaults from config
- **AiConversation:** MARGINAL PASS - Has accessors but `addMessage()` does track referenced files and auto-title

### Plugin Integration
The plugins are properly registered in `AiServiceProvider::boot()` (line 638-649):
```php
FileModel::setPlugins([
    FileProcessingPlugin::class,
    FileAccessScopePlugin::class,
]);
```

This uses the plugin system from `condoedge/utils`. The registration is wrapped in try-catch to handle cases where the FileModel facade is unavailable (e.g., isolated package tests).

### Relationships Summary
```
AiConversation 1 ──< N AiMessage
AiConversation N >── 1 User
AiMessage N >── 1 AiConversation
AiUserSetting 1 >── 1 User
AiQueryLog (standalone - references user_id, team_id, conversation_id)
```
