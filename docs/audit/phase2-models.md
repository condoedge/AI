# Phase 2 Audit: Eloquent Models

**Audit Date:** 2025-12-30
**Auditor:** Claude Code (Task 17)
**Files Reviewed:** 5 models

## Summary

This audit reviews all Eloquent models in the AI package, analyzing their structure, relationships, usage patterns, and identifying any anomalies.

---

## 1. AiConversation

**File:** `src/Models/AiConversation.php`
**Table:** `ai_conversations`
**Migrations:**
- `2025_01_01_000001_create_ai_conversations_table.php` (creates table)
- `2025_01_02_000001_add_context_snapshot_to_ai_conversations.php` (adds context_snapshot)

### Table Schema

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| id | bigint | No | auto | Primary key |
| uuid | uuid | No | - | Unique, auto-generated |
| user_id | foreignId | Yes | NULL | FK to users table, null on delete |
| team_id | foreignId | Yes | NULL | Indexed, no FK constraint |
| title | string | Yes | NULL | Auto-generated from first user message |
| status | string | No | 'active' | Values: active, archived, deleted |
| metadata | json | Yes | NULL | Stores pinned state, etc. |
| context_snapshot | json | Yes | NULL | Added via separate migration |
| last_message_at | timestamp | Yes | NULL | Updated on each new message |
| timestamps | - | - | - | created_at, updated_at |
| soft_deletes | - | Yes | NULL | deleted_at |

### Fillable Attributes

```php
protected $fillable = [
    'uuid', 'user_id', 'team_id', 'title', 'status',
    'metadata', 'context_snapshot', 'last_message_at',
];
```

### Casts

```php
protected $casts = [
    'metadata' => 'array',
    'context_snapshot' => 'array',
    'last_message_at' => 'datetime',
];
```

### Relationships

| Relationship | Type | Target | Notes |
|--------------|------|--------|-------|
| messages() | HasMany | AiMessage | Via conversation_id |
| user() | BelongsTo | User model | Configurable via auth config |

**Note:** No `team()` relationship defined despite having `team_id` column.

### Scopes

| Scope | Parameters | Description |
|-------|------------|-------------|
| scopeForFilter() | string $filter | Filters by 'pinned', 'archived', or default 'active' |
| scopeSearch() | $search | Searches title and message content |

### Business Methods

| Method | Returns | Description |
|--------|---------|-------------|
| getRecentMessages(int $limit = 10) | array | Gets last N messages in chronological order |
| getFocusedEntity() | ?string | Gets focused entity from context_snapshot |
| getLastQueryType() | ?string | Gets last query type from context_snapshot |
| getMentionedEntities() | array | Gets mentioned entities from context_snapshot |
| getLastCypherQuery() | ?string | Gets last Cypher query from most recent assistant message |
| addMessage(string $role, string $content, array $data = []) | AiMessage | Creates message and updates conversation |
| updateContextSnapshot(array $context) | void | Merges context into context_snapshot |

### Boot Logic

- Auto-generates UUID on creating event if not provided

### Usage Locations

| File | Usage |
|------|-------|
| AiChatPanel.php | CRUD operations, conversation selection |
| ConversationListQuery.php | Querying and displaying conversations |
| ChatMessageForm.php | Loading conversation for message sending |
| AiChatService.php | askWithConversation() method |
| ConversationController.php | Export functionality |
| EditMessageModal.php | Loading conversation for message editing |
| ConversationContextManager.php | Context tracking |

### Anomalies/Notes

1. **team_id column without relationship:** The `team_id` column exists but no `team()` BelongsTo relationship is defined. This is used for filtering but relationship navigation is not available.

2. **Soft deletes inconsistency:** Model uses SoftDeletes but status also has 'deleted' value - redundant tracking.

---

## 2. AiMessage

**File:** `src/Models/AiMessage.php`
**Table:** `ai_messages`
**Migration:** `2025_01_01_000001_create_ai_conversations_table.php` (creates both tables)
**Additional Migration:** `2025_01_02_000001_add_context_snapshot_to_ai_conversations.php` (adds extracted_entities)

### Table Schema

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| id | bigint | No | auto | Primary key |
| conversation_id | foreignId | No | - | FK to ai_conversations, cascade delete |
| role | string | No | - | Values: user, assistant, system |
| content | text | No | - | Message content |
| response_data | json | Yes | NULL | AiChatResponseData serialized |
| context_used | json | Yes | NULL | RAG context for debugging |
| cypher_query | string | Yes | NULL | Generated Cypher query |
| execution_time_ms | integer | Yes | NULL | Query execution time |
| confidence_score | float | Yes | NULL | Response confidence |
| metadata | json | Yes | NULL | Additional data (suggestions, feedback, etc.) |
| extracted_entities | json | Yes | NULL | Added via migration, NOT in fillable |
| timestamps | - | - | - | created_at, updated_at |

### Fillable Attributes

```php
protected $fillable = [
    'conversation_id', 'role', 'content', 'response_data',
    'context_used', 'cypher_query', 'execution_time_ms',
    'confidence_score', 'metadata',
];
```

### Casts

```php
protected $casts = [
    'response_data' => 'array',
    'context_used' => 'array',
    'metadata' => 'array',
    'confidence_score' => 'float',
];
```

### Relationships

| Relationship | Type | Target | Notes |
|--------------|------|--------|-------|
| conversation() | BelongsTo | AiConversation | Via conversation_id |

### Business Methods

| Method | Returns | Description |
|--------|---------|-------------|
| getReferencedFiles() | array | Gets referenced files from metadata |
| hasFileReferences() | bool | Checks if message has file references |

### Usage Locations

| File | Usage |
|------|-------|
| AiConversation.php | Creating messages via addMessage() |
| EditMessageModal.php | Loading and editing messages |

### Anomalies/Notes

1. **CRITICAL: extracted_entities column not in fillable:** The migration adds `extracted_entities` column to the table, but it's NOT in the model's `$fillable` array and NOT in `$casts`. This column cannot be mass-assigned and won't be automatically cast.

2. **extracted_entities never used:** Grep search found zero usages of `extracted_entities` anywhere in the codebase. This appears to be dead code/unused feature.

---

## 3. AiQueryLog

**File:** `src/Models/AiQueryLog.php`
**Table:** `ai_query_logs`
**Migration:** `2025_01_01_000002_create_ai_query_logs_table.php`

### Table Schema

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| id | bigint | No | auto | Primary key |
| user_id | foreignId | Yes | NULL | Indexed |
| team_id | foreignId | Yes | NULL | Indexed |
| conversation_id | foreignId | Yes | NULL | Indexed, no FK constraint |
| question | text | No | - | Original question text |
| cypher_query | text | Yes | NULL | Generated query |
| template_used | string | Yes | NULL | Query template name |
| confidence_score | float | Yes | NULL | Query confidence |
| execution_time_ms | integer | Yes | NULL | Execution time |
| result_count | integer | Yes | NULL | Number of results |
| status | string | No | - | Values: success, failed, timeout, rejected |
| error_message | text | Yes | NULL | Error details if failed |
| context_stats | json | Yes | NULL | Tokens used, entities matched |
| metadata | json | Yes | NULL | Additional data |
| timestamps | - | - | - | created_at, updated_at |

### Fillable Attributes

```php
protected $fillable = [
    'user_id', 'team_id', 'conversation_id',
    'question', 'cypher_query', 'template_used',
    'confidence_score', 'execution_time_ms', 'result_count',
    'status', 'error_message', 'context_stats', 'metadata',
];
```

### Casts

```php
protected $casts = [
    'context_stats' => 'array',
    'metadata' => 'array',
    'confidence_score' => 'float',
];
```

### Relationships

None defined.

**Note:** No relationships to User, Team, or AiConversation despite having foreign key columns.

### Business Methods (Static)

| Method | Returns | Description |
|--------|---------|-------------|
| logSuccess(array $data) | self | Creates log entry with status='success' |
| logFailure(array $data, string $error) | self | Creates log entry with status='failed' |

### Usage Locations

| File | Usage |
|------|-------|
| QueryLearner.php | Learning from successful queries |
| QueryAnalytics.php | Analytics and statistics |

### Anomalies/Notes

1. **No relationships defined:** Despite having `user_id`, `team_id`, and `conversation_id` columns, no BelongsTo relationships are defined.

2. **conversation_id without FK constraint:** Unlike other foreign keys, this one has no database-level constraint.

3. **Limited usage:** Only used in analytics and learning services, not in main query flow. May be inconsistently logged.

---

## 4. AiUserSetting

**File:** `src/Models/AiUserSetting.php`
**Table:** `ai_user_settings`
**Migration:** `2025_01_03_000001_create_ai_user_settings_table.php`

### Table Schema

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| id | bigint | No | auto | Primary key |
| user_id | foreignId | No | - | FK to users, cascade delete, unique |
| ui_theme | string | Yes | NULL | Theme name or class |
| ui_colors | json | Yes | NULL | Custom color overrides |
| chat_settings | json | Yes | NULL | Chat preferences (nested JSON) |
| timestamps | - | - | - | created_at, updated_at |

### Fillable Attributes

```php
protected $fillable = [
    'user_id', 'ui_theme', 'ui_colors', 'chat_settings',
];
```

### Casts

```php
protected $casts = [
    'ui_colors' => 'array',
    'chat_settings' => 'array',
];
```

### Relationships

| Relationship | Type | Target | Notes |
|--------------|------|--------|-------|
| user() | BelongsTo | User model | Configurable via auth config |

### Business Methods

| Method | Returns | Description |
|--------|---------|-------------|
| forUser(int $userId) | self | Static: get or create settings for user |
| getThemeName() | ?string | Get current theme name |
| getColorOverrides() | array | Get custom color overrides |
| setTheme(?string, array) | self | Set theme and optional color overrides |
| getSetting(string $key, $default) | mixed | Get specific chat setting |
| getSettings() | array | Get all chat settings |
| setSetting(string $key, $value) | self | Set specific chat setting |
| setSettings(array $settings) | self | Set multiple chat settings |

### Usage Locations

| File | Usage |
|------|-------|
| ChatSettingsModal.php | Loading/saving user settings |
| UserChatSettings.php | Priority chain setting resolution |
| UserChatThemeFactory.php | Resolving user theme preferences |

### Anomalies/Notes

1. **No scopes defined:** Unlike other models, no query scopes are defined.

2. **Nested JSON structure:** `chat_settings` contains nested settings accessed via helper methods. This creates a non-standard data access pattern.

3. **ChatSettingsModal afterSave issue:** The `afterSave()` method in ChatSettingsModal references `$this->model->show_avatars`, `$this->model->show_timestamps`, etc., but these are not actual model attributes - they're keys within `chat_settings` JSON. This appears to be broken code.

---

## 5. FileProcessingPlugin

**File:** `src/Models/Plugins/FileProcessingPlugin.php`
**Table:** N/A (This is a model plugin, not an Eloquent model)

### Type

This is NOT an Eloquent model. It's a `ModelPlugin` that attaches to the File model to handle AI-related file processing.

### Parent Class

`Condoedge\Utils\Models\Plugins\ModelPlugin`

### Events Handled

| Event | Handler | Description |
|-------|---------|-------------|
| created | handleFileCreated() | Syncs to Neo4j and processes content for Qdrant |
| updated | handleFileUpdated() | Re-syncs to Neo4j, reprocesses if path changed |
| deleting | handleFileDeleting() | Removes from both Qdrant and Neo4j |

### Key Methods

| Method | Description |
|--------|-------------|
| syncToNeo4j($file, $operation) | Creates or updates file node in Neo4j via AI facade |
| removeFromNeo4j($file) | Removes file node from Neo4j |
| processFileContent($file) | Processes file for Qdrant vector storage |
| reprocessFileContent($file) | Removes old chunks and reprocesses |
| removeFileContent($file) | Removes file chunks from Qdrant |
| shouldProcessContent($file) | Checks if file should be processed (config, exists, type) |
| isProcessed($file) | Checks if file is already in Qdrant |
| shouldQueueProcessing($file) | Determines if processing should be queued (size threshold) |
| queueFileProcessing($file, $reprocess) | Logs that queueing should happen, falls back to sync |
| handleError($operation, $file, $e) | Error handling with configurable fail_silently |

### Configuration Used

- `ai.file_processing.enabled`
- `ai.file_processing.queue`
- `ai.file_processing.queue_threshold_bytes` (default: 5MB)
- `ai.file_processing.fail_silently`
- `ai.file_processing.log_errors`

### Dependencies

- `FileProcessorInterface` - For processing file content
- `AI` facade - For Neo4j operations (ingest, sync, remove)

### Registration

Registered in `AiServiceProvider::boot()`:
```php
File::setPlugins([
    FileProcessingPlugin::class,
]);
```

### Anomalies/Notes

1. **Queue not implemented:** The `queueFileProcessing()` method logs that queueing should happen but falls back to synchronous processing. Comment states: "Job implementation will be created in a future task."

2. **No actual job class:** There's no corresponding job class for async file processing.

---

## Cross-Cutting Findings

### Unused Database Columns

| Model | Column | Status |
|-------|--------|--------|
| AiMessage | extracted_entities | Added via migration but not in fillable, not cast, never used in code |

### Missing Relationships

| Model | Column | Missing Relationship |
|-------|--------|---------------------|
| AiConversation | team_id | team() BelongsTo |
| AiQueryLog | user_id | user() BelongsTo |
| AiQueryLog | team_id | team() BelongsTo |
| AiQueryLog | conversation_id | conversation() BelongsTo |

### Inconsistent team_id Usage

- `AiConversation`: Has `team_id`, set via `currentTeamId()` in AiChatPanel
- `AiQueryLog`: Has `team_id` in fillable but no clear population pattern
- Both lack relationship definitions for team navigation

### JSON Column Patterns

All models use JSON columns for flexible storage:
- `AiConversation`: metadata, context_snapshot
- `AiMessage`: response_data, context_used, metadata
- `AiQueryLog`: context_stats, metadata
- `AiUserSetting`: ui_colors, chat_settings

### Potential Issues

1. **ChatSettingsModal afterSave bug:** References non-existent model attributes
2. **extracted_entities dead code:** Column exists but never used
3. **Missing FK constraints:** Some foreign keys lack database constraints
4. **No soft deletes on child models:** AiMessage doesn't use SoftDeletes while AiConversation does

---

## Recommendations

1. **Remove extracted_entities column** or implement the feature - currently dead code
2. **Add missing relationships** for proper Eloquent navigation
3. **Fix ChatSettingsModal::afterSave()** to use proper chat_settings access
4. **Consider adding SoftDeletes to AiMessage** to match parent model
5. **Implement the queue job** for FileProcessingPlugin or remove the queueing logic
6. **Add team relationship** to AiConversation if team-based features are needed
7. **Review status vs soft_delete redundancy** in AiConversation

---

## Test Coverage

Models location: `tests/` directory
Specific model tests: Not found in file listing - models may lack dedicated unit tests.

---

*Document generated as part of architectural audit Task 17*
