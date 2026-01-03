# Module 15: DATA_MODELS - Documentation Updates

> **Status:** COMPLETE

## Existing Documentation Coverage

The data models are already well-documented in the existing documentation:

1. **`resources/docs/1.0/chat/conversation-context-management.md`** (lines 409, 747, 764)
   - Documents `context_snapshot` JSON field structure
   - Provides debugging examples

2. **`resources/docs/1.0/usage/data-ingestion.md`** (line 643)
   - Documents FileProcessingPlugin auto-processing behavior

## Suggested Documentation Improvements

### 1. Add Model Reference Section

Consider adding a dedicated models reference page at `resources/docs/1.0/reference/models.md` with:

```markdown
# Data Models Reference

## AiConversation
Primary model for chat conversations.

### Fields
| Field | Type | Description |
|-------|------|-------------|
| uuid | string | Unique identifier (auto-generated) |
| user_id | int | Owner user |
| team_id | int | Optional team scope |
| title | string | Auto-generated from first message |
| status | string | 'active' or 'archived' |
| metadata | JSON | Flexible metadata storage |
| context_snapshot | JSON | Conversation context for reference resolution |
| last_message_at | datetime | Updated on each message |

### Context Snapshot Structure
[Document the full structure as in FINDINGS.md]

## AiMessage
Individual messages within conversations.

### Fields
| Field | Type | Description |
|-------|------|-------------|
| conversation_id | int | Parent conversation |
| role | string | 'user' or 'assistant' |
| content | string | Message text |
| response_data | JSON | Structured response data |
| context_used | JSON | Context snapshot at time of response |
| cypher_query | string | Generated Cypher query (if any) |
| execution_time_ms | int | Query execution time |
| confidence_score | float | Response confidence |
| metadata | JSON | Additional metadata |

## AiQueryLog
Analytics and audit logging for AI queries.

## AiUserSetting
Per-user UI preferences for the chat interface.
```

### 2. Plugin Documentation

Consider adding to `resources/docs/1.0/usage/data-ingestion.md`:

```markdown
## File Model Plugins

The AI package registers two plugins with the FileModel:

### FileAccessScopePlugin
Provides the `accessibleBy($user)` scope macro for filtering files by ownership.

Configuration:
- `ai.file_context.fallback_filters.use_user_filter` - Filter by user_id
- `ai.file_context.fallback_filters.use_team_filter` - Filter by team_id

### FileProcessingPlugin
Automatically syncs File models to Neo4j and Qdrant on create/update/delete.

Configuration:
- `ai.file_processing.enabled` - Enable/disable processing
- `ai.file_processing.queue` - Process asynchronously
- `ai.file_processing.queue_threshold_bytes` - Queue files larger than this
- `ai.file_processing.fail_silently` - Don't throw on errors
- `ai.file_processing.log_errors` - Log processing errors
```

## Priority

**LOW** - The existing documentation is adequate. These are enhancement suggestions for improved developer experience.
