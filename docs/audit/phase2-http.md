# Phase 2: HTTP Controllers Audit

## Overview

This document reviews all HTTP controllers in the `src/Http/Controllers/` directory, analyzing endpoints, input validation, dependencies, route registration, and security considerations.

---

## Controller Summary

| Controller | File | Actions | Route Type | Auth Required |
|------------|------|---------|------------|---------------|
| ConversationController | ConversationController.php | 1 (export) | Web | NO (Critical!) |
| HealthController | HealthController.php | 1 (__invoke) | API | No |

---

## 1. ConversationController

**File:** `src/Http/Controllers/ConversationController.php`

### Endpoints/Actions

| Method | Action | Route | HTTP Method |
|--------|--------|-------|-------------|
| export() | Export conversation as markdown | `/export-chat/{id}` | GET |

### Implementation Details

```php
class ConversationController extends Controller
{
    public function export($id)
    {
        $conversation = AiConversation::where('user_id', auth()->id())->find($id);
        if (!$conversation) {
            return;
        }
        // ... generates markdown export
    }
}
```

### Input Validation

| Parameter | Validation | Notes |
|-----------|------------|-------|
| $id | Route parameter (implicit int/string) | No explicit validation |

**Validation Issues:**
- No explicit type validation on `$id` parameter
- No 404 response when conversation not found (just returns `null`)
- Silent failure for missing conversations

### Dependencies Injected

- None (uses static auth() helper and Eloquent model)

### Route Registration

**File:** `routes/web.php`
```php
Route::get('export-chat/{id}', [ConversationController::class, 'export'])
    ->name('ai.export-chat');
```

### Reference Status

| Location | Usage |
|----------|-------|
| src/Kompo/AiChatPanel.php:197 | Link generation for export button |
| routes/web.php:6 | Route definition |

### Security Considerations

| Aspect | Status | Notes |
|--------|--------|-------|
| Authentication | **CRITICAL ISSUE** | No auth middleware on route |
| Authorization | Partial | User ownership check via `where('user_id', auth()->id())` |
| IDOR Protection | Partial | Relies on user_id check, but fails if user not authenticated |
| Input Sanitization | Not applicable | Read-only operation |

**CRITICAL SECURITY ISSUES:**

1. **No Authentication Middleware**: The route in `routes/web.php` does not apply any auth middleware. An unauthenticated user can access this endpoint.

2. **Silent Auth Failure**: If `auth()->id()` returns `null` (unauthenticated), the query becomes `WHERE user_id IS NULL`, which could:
   - Return nothing (safe but wrong error handling)
   - Potentially return system-owned conversations if any exist with null user_id

3. **No Proper Error Response**: Returns `null` instead of proper 404 response when conversation not found.

### Recommended Fixes

```php
// Route should have auth middleware:
Route::middleware(['auth'])->group(function () {
    Route::get('export-chat/{id}', [ConversationController::class, 'export'])
        ->name('ai.export-chat');
});

// Controller should have proper error handling:
public function export($id)
{
    $conversation = AiConversation::where('user_id', auth()->id())
        ->findOrFail($id);
    // ... rest of implementation
}
```

---

## 2. HealthController

**File:** `src/Http/Controllers/HealthController.php`

### Endpoints/Actions

| Method | Action | Route | HTTP Method |
|--------|--------|-------|-------------|
| __invoke() | System health check | `/api/ai/health` | GET |

### Implementation Details

```php
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $services = [];
        $healthy = true;

        // Check Neo4j via GraphStoreInterface
        // Check Qdrant via VectorStoreInterface
        // Check LLM API key configuration

        return response()->json([
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'services' => $services,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
```

### Input Validation

No input parameters - this is a read-only health check endpoint.

### Dependencies Injected

| Dependency | How Resolved | Purpose |
|------------|--------------|---------|
| GraphStoreInterface | `app(GraphStoreInterface::class)` | Neo4j health check |
| VectorStoreInterface | `app(VectorStoreInterface::class)` | Qdrant health check |

**Note:** Dependencies are resolved via service container rather than constructor injection.

### Route Registration

**File:** `routes/api.php`
```php
Route::prefix('api/ai')->group(function () {
    Route::get('health', HealthController::class);
});
```

### Reference Status

| Location | Usage |
|----------|-------|
| routes/api.php:8 | Route definition |
| tests/Feature/HealthEndpointTest.php | Feature tests |
| docs/plans/2025-01-01-ai-package-improvements.md | Documentation |

### Security Considerations

| Aspect | Status | Notes |
|--------|--------|-------|
| Authentication | Intentionally None | Health endpoints typically public |
| Information Disclosure | **CONCERN** | Exposes error messages from services |
| Rate Limiting | Not Applied | Could be DoS vector |

**Security Notes:**

1. **Information Disclosure**: The endpoint exposes service error messages (e.g., `'error' => $e->getMessage()`), which could reveal internal system details to attackers.

2. **No Rate Limiting**: Health endpoints should have rate limiting to prevent abuse.

3. **Intentionally Public**: Health endpoints are typically public for load balancers/monitoring tools - this is acceptable if the above concerns are addressed.

### Recommended Improvements

```php
// Sanitize error messages in production:
$services['neo4j'] = [
    'status' => 'unhealthy',
    'error' => app()->environment('production')
        ? 'Service unavailable'
        : $e->getMessage()
];

// Add rate limiting middleware:
Route::prefix('api/ai')
    ->middleware(['throttle:health'])
    ->group(function () {
        Route::get('health', HealthController::class);
    });
```

---

## Route Files Analysis

### routes/api.php

```php
Route::prefix('api/ai')->group(function () {
    Route::get('health', HealthController::class);
});
```

**Analysis:**
- Single health check endpoint
- No authentication (appropriate for health checks)
- No rate limiting (should be added)

### routes/web.php

```php
Route::get('export-chat/{id}', [ConversationController::class, 'export'])
    ->name('ai.export-chat');
```

**Analysis:**
- Single export endpoint
- **MISSING auth middleware** (critical security issue)
- Named route used by AiChatPanel

---

## Service Provider Route Loading

**File:** `src/AiServiceProvider.php` (lines 580-582)

```php
public function boot(): void
{
    // Load routes
    $this->loadRoutesFrom(__DIR__."/../routes/api.php");
    $this->loadRoutesFrom(__DIR__."/../routes/web.php");
    // ...
}
```

**Note:** Routes are loaded without middleware groups, meaning they don't automatically inherit application-wide middleware (like `web` or `api` middleware groups).

---

## Test Coverage

### HealthEndpointTest

**File:** `tests/Feature/HealthEndpointTest.php`

| Test | Coverage |
|------|----------|
| it_returns_health_status() | Happy path with mocked healthy services |
| it_returns_503_when_service_unhealthy() | Neo4j failure scenario |

**Missing Test Coverage:**
- Qdrant failure scenario
- LLM not configured scenario
- Multiple service failures
- Response structure validation for unhealthy states

### ConversationController Tests

**No test file found!** Critical gap in test coverage.

---

## Issues Summary

### Critical Issues

| ID | Controller | Issue | Severity |
|----|------------|-------|----------|
| HTTP-1 | ConversationController | No authentication middleware on route | CRITICAL |
| HTTP-2 | ConversationController | Returns null instead of 404 | HIGH |
| HTTP-3 | ConversationController | No test coverage | HIGH |

### Medium Issues

| ID | Controller | Issue | Severity |
|----|------------|-------|----------|
| HTTP-4 | HealthController | Exposes raw exception messages | MEDIUM |
| HTTP-5 | HealthController | No rate limiting | MEDIUM |
| HTTP-6 | Both | Dependencies not constructor-injected | LOW |

### Code Quality Issues

| ID | Controller | Issue | Severity |
|----|------------|-------|----------|
| HTTP-7 | ConversationController | No parameter type hints | LOW |
| HTTP-8 | HealthController | Could use constructor injection | LOW |

---

## Recommendations

### Immediate Actions Required

1. **Add auth middleware to export route:**
```php
// routes/web.php
Route::middleware(['auth'])->group(function () {
    Route::get('export-chat/{id}', [ConversationController::class, 'export'])
        ->name('ai.export-chat');
});
```

2. **Fix ConversationController error handling:**
```php
public function export(int $id)
{
    $conversation = AiConversation::where('user_id', auth()->id())
        ->findOrFail($id);
    // ...
}
```

3. **Add rate limiting to health endpoint:**
```php
// routes/api.php
Route::prefix('api/ai')
    ->middleware(['throttle:60,1'])
    ->group(function () {
        Route::get('health', HealthController::class);
    });
```

### Recommended Improvements

1. Create `tests/Feature/ConversationExportTest.php`
2. Sanitize error messages in HealthController for production
3. Use constructor injection for better testability
4. Apply middleware groups in service provider route loading

---

## Architecture Notes

### Minimal Controller Approach

The package has only 2 controllers with 2 total endpoints. This is appropriate for a package that:
- Relies primarily on Kompo forms for UI interactions
- Uses AJAX/form submissions rather than RESTful API
- Provides limited API surface area

### Route Organization

- API routes in `routes/api.php` (prefixed with `api/ai/`)
- Web routes in `routes/web.php` (no prefix)
- Routes loaded in service provider boot method

### Missing RESTful Endpoints

The package lacks standard RESTful conversation endpoints. All conversation CRUD appears to happen through Kompo form components rather than traditional controllers.

---

## Conclusion

The HTTP layer is minimal but has a critical security vulnerability in the ConversationController route that requires immediate attention. The HealthController is well-implemented but could benefit from production hardening. Test coverage for the HTTP layer is incomplete.

**Priority Actions:**
1. Add authentication middleware to export route (CRITICAL)
2. Fix error handling in ConversationController (HIGH)
3. Add test coverage for ConversationController (HIGH)
4. Sanitize health check error messages (MEDIUM)
