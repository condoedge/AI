# Module 15: DATA_MODELS - Analysis Plan

> **Module Slug:** data-models
> **Priority:** MEDIUM (Eloquent models)
> **Estimated Files:** 6

## Responsibility
- Define data structures (thin models)
- Handle persistence
- Provide relationships

## Files
| File | Purpose |
|------|---------|
| `src/Models/AiConversation.php` | Conversation model |
| `src/Models/AiMessage.php` | Message model |
| `src/Models/AiQueryLog.php` | Query logging |
| `src/Models/AiUserSetting.php` | User settings |
| `src/Models/Plugins/FileAccessScopePlugin.php` | File access plugin |
| `src/Models/Plugins/FileProcessingPlugin.php` | File processing plugin |

## Key Questions
- Are models thin (no business logic)?
- How do plugins integrate?
- What is context_snapshot in AiConversation?
