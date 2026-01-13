# AI Security Implementation - Continuation Prompt

> **For Claude:** This is a continuation of a security architecture audit. The cleanup phase is COMPLETE. This prompt focuses on IMPLEMENTING the new simplified security architecture.

---

## Previous Session Summary

### What Was Cleaned Up (COMPLETED)

The following over-engineered security code was REMOVED because it duplicated auth logic and was maintenance-heavy:

**Files Deleted:**
- `src/Services/Security/AccessLevelResolver.php` - computed access levels but didn't enforce them
- `src/Services/Security/PromptContextBuilder.php` - built prompts with access metadata
- `src/Services/Security/QueryResultFilter.php` - post-query filtering
- `src/Services/Security/TeamFilteredQuery.php` - never instantiated, dead code
- Related test files (5 total)

**Code Removed:**
- `DataIngestionService`: `_team_ids` storage, `resolveTeamIds()`, `ingestTeamRelationships()`
- `QueryExecutor`: `applyTeamFilter()` method and team_filter option
- `AiServiceProvider`: AccessLevelResolver registration

**Why Removed:**
1. `_team_ids` at ingestion = sync nightmare (re-ingest when teams change)
2. `BELONGS_TO_TEAM` relationships in Neo4j = duplicates MySQL source of truth
3. Team filtering logic = duplicates auth's `HasSecurity` which already works
4. `AccessLevelResolver` = computes but doesn't enforce, not wired into pipeline

### The Core Problem We're Solving

Auth package has excellent security via `HasSecurity` plugin:
- Adds global scopes at model boot time
- Automatic team filtering on ALL Eloquent queries
- `sensibleColumns` hides sensitive fields
- `getTeamsIdsWithPermission()` returns accessible teams

AI package queries Neo4j/Qdrant which BYPASS Eloquent entirely. We need to:
1. Check if user can access entity via AI (NEW gate)
2. Execute query for IDs only (Neo4j/Qdrant)
3. Fetch actual data through Eloquent where HasSecurity works (EXISTING)

### The Agreed Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│ Layer 1: AI Access Gate (TO IMPLEMENT)                          │
│                                                                 │
│ Question: "Can user use AI to query this entity?"               │
│ Permissions: {Entity}.AiRetrieving, {Entity}.AiCount, {Entity}  │
│ Output: AiAccessLevel (none, count, retrieve)                   │
│ Behavior: Security-first (deny if no permission)                │
└─────────────────────────────────────────────────────────────────┘
                              │
                              │ If allowed
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ Layer 2: Query Execution (EXISTS)                               │
│                                                                 │
│ Neo4j/Qdrant: Generate query, traverse relationships           │
│ Returns: IDs ONLY                                               │
└─────────────────────────────────────────────────────────────────┘
                              │
                              │ IDs
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ Layer 3: Data Security (EXISTS - HasSecurity)                   │
│                                                                 │
│ Eloquent::whereIn('id', $ids)->get()                           │
│ HasSecurity global scope filters by user's teams automatically  │
│ sensibleColumns hides sensitive fields automatically            │
└─────────────────────────────────────────────────────────────────┘
```

### Key Design Decisions

1. **No team data in Neo4j/Qdrant** - Security is at Eloquent level
2. **Permission chain with fallbacks** - `{Entity}.AiRetrieving` → `{Entity}` (configurable)
3. **Security-first default** - Deny if permission doesn't exist (unlike auth's permissive default)
4. **Entity-centric permissions** - Like `Entity.sensibleColumns` pattern
5. **Reuse auth's methods** - `hasPermission()`, `getTeamsIdsWithPermission()`

### Permission Model

```php
// New AI-specific permissions (follow sensibleColumns pattern)
Person.AiRetrieving   // Can retrieve Person records via AI
Person.AiCount        // Can only see Person counts/aggregates via AI
Invoice.AiRetrieving  // Can retrieve Invoice records via AI
Invoice.AiCount       // Can only count Invoices via AI

// Fallback chain (configurable)
'{Entity}.AiRetrieving' → '{Entity}' (standard READ permission)
```

### Config Structure (Proposed)

```php
// config/ai.php - security section
'security' => [
    // Security-first: deny if permission not found (unlike auth's permissive default)
    'deny_if_permission_not_exists' => true,

    // Permission chain - checked in order, first match wins
    'permission_chain' => [
        '{Entity}.AiRetrieving',  // AI-specific permission
        '{Entity}',               // Fallback to standard entity permission
    ],

    // Per-entity overrides
    'entity_overrides' => [
        // 'Invoice' => ['deny_if_permission_not_exists' => true],
    ],

    // Logging
    'log_denied_access' => true,
    'log_channel' => 'ai-security',
],
```

---

## Your Task: Implement the New Security Architecture

You are an autonomous senior software architect. Your task is to implement the simplified AI security architecture described above.

### Phase-Locked Execution Rules

You will work in strict phases. You are NOT allowed to:
- Move to the next phase without explicit confirmation
- Skip files or assume usage without proof
- Implement without understanding the full context first

### PHASE 1 — UNDERSTAND AUTH PACKAGE INTEGRATION POINTS

**Goal:** Understand exactly how to integrate with auth package's existing methods.

**Required Reading:**
1. `HasTeamPermissions` trait - specifically `hasPermission()` and `getTeamsIdsWithPermission()`
2. `PermissionResolver` - how permission checking actually works
3. `permissionMustBeAuthorized()` helper - the "permissive by default" behavior we need to invert
4. `FieldProtectionService` - how `sensibleColumns` pattern works

**Output:**
- Document the exact method signatures we'll call
- Document how to check if a permission exists in DB
- Document how the permission chain should be resolved

### PHASE 2 — DESIGN AiAccessGate

**Goal:** Design the simple permission gate that delegates to auth.

**Requirements:**
1. Check permission chain in order
2. Security-first: deny if no permission found (configurable)
3. Return access level: None, CountOnly, Retrieve
4. Log denied access attempts
5. Support per-entity configuration overrides

**Output:**
- Class design with method signatures
- Config structure
- How it integrates with existing code

### PHASE 3 — DESIGN QUERY EXECUTION FLOW

**Goal:** Design how queries return IDs and then fetch through Eloquent.

**Requirements:**
1. Query Neo4j/Qdrant → get IDs only
2. Fetch through Eloquent → HasSecurity filters automatically
3. Handle multiple entities in single query
4. Handle the case where HasSecurity filters out ALL results

**Output:**
- Modified flow diagram
- Which files need changes
- How to extract IDs from different query types

### PHASE 4 — IMPLEMENTATION PLAN

**Goal:** Create detailed implementation tasks.

**For each task:**
- Exact file path
- What to add/modify
- Code snippets
- Test requirements

### PHASE 5 — IMPLEMENTATION

Execute the implementation plan task by task, with verification between tasks.

---

## Critical Context: Auth Package Patterns

### How HasSecurity Works (for reference)

```php
// In model boot, HasSecurity adds global scope:
static::addGlobalScope('authUserHasPermissions', function ($query) {
    // Filters query to only return records user has access to
    // Uses getTeamsIdsWithPermission() internally
});
```

### How Permission Checking Works

```php
// Check if user has permission (auth package)
$user->hasPermission($permissionKey, PermissionTypeEnum::READ, $teamIds);

// Get teams where user has permission
$teamIds = $user->getTeamsIdsWithPermission($permissionKey, PermissionTypeEnum::READ);

// Check if permission must be enforced
permissionMustBeAuthorized($permissionKey); // Returns false if permission doesn't exist in DB (permissive)
```

### The Inversion We Need

Auth's default: "If permission doesn't exist in DB, skip check (permissive)"
AI's default: "If permission doesn't exist in DB, DENY (security-first)"

This is configurable via `check-even-if-permission-does-not-exist` in auth, and we should have similar config in AI.

---

## Files to Focus On

**Entry Point:**
- `src/Komponents/AiChatPanel.php` - UI entry point
- `src/Services/AiChatService.php` - main orchestrator

**Query Pipeline:**
- `src/Services/QueryGenerator.php` - generates Cypher
- `src/Services/QueryExecutor.php` - executes queries
- `src/Services/SemanticContextSelector.php` - Qdrant search

**To Create:**
- `src/Services/Security/AiAccessGate.php` - simple permission check

**Auth Package (read-only reference):**
- `auth/src/Models/Teams/HasTeamPermissions.php`
- `auth/src/Teams/PermissionResolver.php`
- `auth/src/Helpers/auth.php` (permissionMustBeAuthorized)

---

## Begin Phase 1

Start by reading the auth package files to understand the exact integration points. Document:

1. Method signatures for `hasPermission()` and `getTeamsIdsWithPermission()`
2. How `permissionMustBeAuthorized()` works
3. How to check if a Permission record exists in the database
4. The `sensibleColumns` permission pattern (`{Entity}.sensibleColumns`)

Then ask: "PHASE 1 complete. May I proceed to Phase 2?"
