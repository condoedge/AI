# Module 22: INFRASTRUCTURE - Analysis Plan

> **Module Slug:** infrastructure
> **Priority:** HIGH (Laravel integration)
> **Estimated Files:** 11

## Responsibility
- Service provider registration
- Facades
- Observers
- Jobs
- HTTP controllers

## Files
| File | Purpose |
|------|---------|
| `src/AiServiceProvider.php` | Main provider (770 lines!) |
| `src/Facades/AI.php` | AI facade |
| `src/Facades/FileSearch.php` | FileSearch facade |
| `src/Observers/RelatedModelSyncObserver.php` | Model observer |
| `src/Jobs/IngestEntityJob.php` | Entity ingestion job |
| `src/Jobs/ProcessFileJob.php` | File processing job |
| `src/Jobs/RemoveEntityJob.php` | Entity removal job |
| `src/Jobs/SyncEntityJob.php` | Entity sync job |
| `src/Http/Controllers/ConversationController.php` | API controller |
| `src/Http/Controllers/HealthController.php` | Health check |

## Key Issue
- AiServiceProvider is 770 lines - may need decomposition
