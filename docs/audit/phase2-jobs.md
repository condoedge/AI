# Phase 2 Audit: Jobs

**Audited:** 2024-12-30
**Directory:** `src/Jobs/`

---

## Overview

The Jobs directory contains three Laravel queue jobs that handle asynchronous synchronization of Nodeable entities with Neo4j (graph database) and Qdrant (vector store). All three jobs share a consistent structure and are dispatched via the `HasNodeableConfig` trait's model event listeners.

---

## Job Summary

| Job | Purpose | Trigger Event | AI Facade Method |
|-----|---------|---------------|------------------|
| `IngestEntityJob` | Create entity in graph/vector stores | Model `created` | `AI::ingest()` |
| `SyncEntityJob` | Update entity in graph/vector stores | Model `updated` | `AI::sync()` |
| `RemoveEntityJob` | Remove entity from graph/vector stores | Model `deleted` | `AI::remove()` |

---

## 1. IngestEntityJob.php

**Purpose:** Queues the ingestion of a Nodeable entity into Neo4j and Qdrant when a model with `HasNodeableConfig` trait is created.

### Constructor Parameters
```php
public function __construct(Nodeable $entity)
```
- `$entity` (Nodeable): The model instance to be ingested

### Queue Configuration
| Setting | Value | Notes |
|---------|-------|-------|
| Tries | 3 | Automatic retry on failure |
| Timeout | 120 seconds | |
| Queue Name | Configurable | Via `config('ai.auto_sync.queue_name')` |
| Connection | Configurable | Via `config('ai.auto_sync.queue_connection')` |

### Handle Method Logic
```php
public function handle(): void
{
    try {
        AI::ingest($this->entity);
        Log::info('AI entity ingested successfully', [...]);
    } catch (\Throwable $e) {
        Log::error('AI entity ingestion failed', [...]);
        throw $e; // Re-throw to trigger job retry
    }
}
```

### Error Handling
- **Try/Catch:** Logs error with full context (model class, ID, message, trace)
- **Re-throw:** Exception is re-thrown to trigger Laravel's retry mechanism
- **failed() Method:** Logs permanent failure after all retries exhausted

### Tags (Horizon Support)
```php
['ai-sync', 'ingest', get_class($this->entity), 'entity:' . $this->entity->getNodeId()]
```

### Dispatched From
- `HasNodeableConfig::dispatchSyncJob('create')` (line 174)
- Triggered via `static::created()` model event listener

---

## 2. SyncEntityJob.php

**Purpose:** Queues the synchronization (update) of a Nodeable entity in Neo4j and Qdrant when a model with `HasNodeableConfig` trait is updated.

### Constructor Parameters
```php
public function __construct(Nodeable $entity)
```
- `$entity` (Nodeable): The model instance to be synced

### Queue Configuration
| Setting | Value | Notes |
|---------|-------|-------|
| Tries | 3 | Automatic retry on failure |
| Timeout | 120 seconds | |
| Queue Name | Configurable | Via `config('ai.auto_sync.queue_name')` |
| Connection | Configurable | Via `config('ai.auto_sync.queue_connection')` |

### Handle Method Logic
```php
public function handle(): void
{
    try {
        AI::sync($this->entity);
        Log::info('AI entity synced successfully', [...]);
    } catch (\Throwable $e) {
        Log::error('AI entity sync failed', [...]);
        throw $e; // Re-throw to trigger job retry
    }
}
```

### Error Handling
- **Try/Catch:** Logs error with full context (model class, ID, message, trace)
- **Re-throw:** Exception is re-thrown to trigger Laravel's retry mechanism
- **failed() Method:** Logs permanent failure after all retries exhausted

### Tags (Horizon Support)
```php
['ai-sync', 'sync', get_class($this->entity), 'entity:' . $this->entity->getNodeId()]
```

### Dispatched From
- `HasNodeableConfig::dispatchSyncJob('update')` (line 175)
- Triggered via `static::updated()` model event listener

---

## 3. RemoveEntityJob.php

**Purpose:** Queues the removal of a Nodeable entity from Neo4j and Qdrant when a model with `HasNodeableConfig` trait is deleted.

### Constructor Parameters
```php
public function __construct(Nodeable $entity)
```
- `$entity` (Nodeable): The model instance to be removed

### Queue Configuration
| Setting | Value | Notes |
|---------|-------|-------|
| Tries | 3 | Automatic retry on failure |
| Timeout | 120 seconds | |
| Queue Name | Configurable | Via `config('ai.auto_sync.queue_name')` |
| Connection | Configurable | Via `config('ai.auto_sync.queue_connection')` |

### Handle Method Logic
```php
public function handle(): void
{
    try {
        AI::remove($this->entity);
        Log::info('AI entity removed successfully', [...]);
    } catch (\Throwable $e) {
        Log::error('AI entity removal failed', [...]);
        throw $e; // Re-throw to trigger job retry
    }
}
```

### Error Handling
- **Try/Catch:** Logs error with full context (model class, ID, message, trace)
- **Re-throw:** Exception is re-thrown to trigger Laravel's retry mechanism
- **failed() Method:** Logs permanent failure after all retries exhausted

### Tags (Horizon Support)
```php
['ai-sync', 'remove', get_class($this->entity), 'entity:' . $this->entity->getNodeId()]
```

### Dispatched From
- `HasNodeableConfig::dispatchSyncJob('delete')` (line 176)
- Triggered via `static::deleted()` model event listener

---

## Dispatch Mechanism

Jobs are NOT dispatched directly via `JobName::dispatch()`. Instead, they are dispatched dynamically through the `HasNodeableConfig` trait:

### Dispatch Flow
```
Model Event (created/updated/deleted)
    |
    v
HasNodeableConfig::bootHasNodeableConfig() (static listeners)
    |
    v
shouldAutoSync($operation) - checks config
    |
    v
performAutoSync($operation, $callback)
    |
    v
shouldQueueSync() - checks if queuing enabled
    |
    v [if queuing enabled]
dispatchSyncJob($operation)
    |
    v
dispatch(new $jobClass($this))
```

### Dispatch Code (`HasNodeableConfig::dispatchSyncJob`)
```php
protected function dispatchSyncJob(string $operation): void
{
    $queueConnection = config('ai.auto_sync.queue_connection');
    $queueName = config('ai.auto_sync.queue_name', 'default');

    $jobClass = match ($operation) {
        'create' => \Condoedge\Ai\Jobs\IngestEntityJob::class,
        'update' => \Condoedge\Ai\Jobs\SyncEntityJob::class,
        'delete' => \Condoedge\Ai\Jobs\RemoveEntityJob::class,
    };

    $job = new $jobClass($this);

    if ($queueConnection) {
        $job->onConnection($queueConnection);
    }

    if ($queueName !== 'default') {
        $job->onQueue($queueName);
    }

    dispatch($job);
}
```

---

## Configuration

Jobs are controlled by the `auto_sync` section in `config/ai.php`:

```php
'auto_sync' => [
    'enabled' => env('AI_AUTO_SYNC_ENABLED', true),        // Global toggle
    'queue' => env('AI_AUTO_SYNC_QUEUE', false),           // Enable queued processing
    'queue_connection' => env('AI_AUTO_SYNC_QUEUE_CONNECTION', null),
    'queue_name' => env('AI_AUTO_SYNC_QUEUE_NAME', 'default'),
    'operations' => [
        'create' => env('AI_AUTO_SYNC_CREATE', true),
        'update' => env('AI_AUTO_SYNC_UPDATE', true),
        'delete' => env('AI_AUTO_SYNC_DELETE', true),
    ],
    'fail_silently' => env('AI_AUTO_SYNC_FAIL_SILENTLY', true),
    'log_errors' => env('AI_AUTO_SYNC_LOG_ERRORS', true),
    'eager_load_relationships' => env('AI_AUTO_SYNC_EAGER_LOAD', true),
]
```

### Important Notes
- **Jobs only execute if `auto_sync.queue = true`** (defaults to `false`)
- When `queue = false`, sync happens synchronously in the model event
- Per-model override: `protected $aiSyncQueue = true;`
- Per-model disable: `protected $aiAutoSync = false;`

---

## Notes and Anomalies

### 1. All Jobs Are Used
All three jobs are actively used in the production flow via the `HasNodeableConfig` trait. None are orphaned.

### 2. Consistent Structure
All jobs share identical:
- Retry configuration (3 tries, 120s timeout)
- Error handling pattern (log + re-throw)
- Tag structure for Horizon
- Laravel traits (Dispatchable, InteractsWithQueue, Queueable, SerializesModels)

### 3. SerializesModels Concern
Jobs use `SerializesModels` trait which serializes only the model ID. This is important because:
- The model is re-fetched from the database when the job runs
- If the model is deleted before the job runs, it will fail
- This is particularly relevant for `RemoveEntityJob` - if the model uses soft deletes, the delete job can still find the model

### 4. No Explicit Queue Name in Class
Jobs don't define `$queue` property. Queue is set dynamically at dispatch time from config, providing flexibility but requiring proper configuration.

### 5. No Rate Limiting
Jobs have no built-in rate limiting. High-volume create/update operations could overwhelm external services (Neo4j, Qdrant). Consider:
- Using Laravel's `Illuminate\Queue\Middleware\RateLimited` middleware
- Implementing batch processing for bulk operations

### 6. No Job Batching
No support for Laravel's Job Batching feature. For bulk imports, users should:
1. Disable auto-sync: `config(['ai.auto_sync.enabled' => false])`
2. Use `AI::ingestBatch()` or similar bulk methods

### 7. Potential Soft Delete Issue
`RemoveEntityJob` may fail if the model was hard-deleted before the job runs (due to `SerializesModels`). The model won't be found. Soft deletes work fine.

---

## Recommendations

1. **Add Rate Limiting Middleware** - Protect external services from queue floods
2. **Consider Job Batching** - For bulk import scenarios
3. **Add ShouldBeUnique Interface** - Prevent duplicate jobs for same entity
4. **Document Soft Delete Behavior** - Clarify interaction with deletions
5. **Add Backoff Configuration** - Exponential backoff between retries

---

## Test Coverage

Jobs should have tests covering:
- [ ] Successful ingest/sync/remove
- [ ] Retry behavior on failure
- [ ] Permanent failure handling
- [ ] Tag generation
- [ ] Queue configuration application

---

## Files Audited

| File | Lines | Status |
|------|-------|--------|
| `src/Jobs/IngestEntityJob.php` | 115 | Active |
| `src/Jobs/RemoveEntityJob.php` | 115 | Active |
| `src/Jobs/SyncEntityJob.php` | 115 | Active |

**Total: 3 files, ~345 lines**
