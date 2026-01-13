# Cleanup: AccessLevelResolver and _team_ids Storage

## Overview

This plan documents the removal of:
1. **AccessLevelResolver** - Over-engineered access level computation that isn't properly integrated
2. **_team_ids storage** - Team IDs stored at ingestion time that create maintenance burden

## Rationale

### Why Remove AccessLevelResolver

- Computes access levels but doesn't enforce them
- Only used for metadata in prompts, not actual security
- Will be replaced by simpler `AiAccessGate` that delegates to auth package's existing `hasPermission()`

### Why Remove _team_ids Storage

- Requires re-ingestion when team assignments change
- Duplicates source of truth (MySQL has this via relationships)
- Creates sync issues
- **New approach**: Fetch data through Eloquent where `HasSecurity` already handles team filtering

---

## Part 1: AccessLevelResolver Cleanup

### File to Remove

```
src/Services/Security/AccessLevelResolver.php
```

### Files That Reference AccessLevelResolver

#### 1. PromptContextBuilder.php
**Path:** `src/Services/Security/PromptContextBuilder.php`
**Usage:** Calls `AccessLevelResolver::resolve()` to add access metadata to prompts
**Action:** Remove access level resolution, simplify to basic user context

#### 2. QueryResultFilter.php
**Path:** `src/Services/Security/QueryResultFilter.php`
**Usage:** Uses access levels for post-query filtering
**Action:** Simplify - will be replaced by Eloquent fetch with HasSecurity

#### 3. AiServiceProvider.php
**Path:** `src/AiServiceProvider.php`
**Usage:** May register AccessLevelResolver as singleton
**Action:** Remove registration

#### 4. Tests
**Path:** `tests/Unit/Services/Security/AccessLevelResolverTest.php`
**Action:** Delete test file

### Search Commands to Find All References

```bash
# Find all imports
grep -r "use.*AccessLevelResolver" src/
grep -r "use.*AccessLevelResolver" tests/

# Find all usages
grep -r "AccessLevelResolver" src/ --include="*.php"
grep -r "accessLevelResolver" src/ --include="*.php"
grep -r "access_level" src/ --include="*.php"
```

---

## Part 2: _team_ids Storage Cleanup

### Files to Modify

#### 1. DataIngestionService.php
**Path:** `src/Services/DataIngestionService.php`

**Remove from `buildVectorMetadata()` (around line 669-691):**
```php
// REMOVE THIS BLOCK:
$metadata['_team_ids'] = $this->resolveTeamIds($entity);
```

**Remove method `resolveTeamIds()`:**
```php
// REMOVE THIS METHOD:
private function resolveTeamIds($entity): array
{
    // ... team resolution logic
}
```

**Remove from Neo4j ingestion - BELONGS_TO_TEAM relationships (around line 1154-1198):**
```php
// REMOVE: Code that creates BELONGS_TO_TEAM relationships
```

#### 2. QdrantStore.php
**Path:** `src/VectorStore/QdrantStore.php`

**No changes needed** - _team_ids was just metadata, store doesn't care about content

#### 3. SemanticContextSelector.php
**Path:** `src/Services/SemanticContextSelector.php`

**Check if filtering by _team_ids:**
```php
// REMOVE any filter code like:
$filter = ['must' => [['key' => '_team_ids', 'match' => ...]]];
```

#### 4. Config files
**Path:** `config/ai.php`

**Remove any _team_ids related config**

### Search Commands

```bash
# Find all _team_ids references
grep -r "_team_ids" src/ --include="*.php"
grep -r "_team_ids" config/ --include="*.php"
grep -r "team_ids" src/ --include="*.php"

# Find BELONGS_TO_TEAM relationship creation
grep -r "BELONGS_TO_TEAM" src/ --include="*.php"

# Find resolveTeamIds method
grep -r "resolveTeamIds" src/ --include="*.php"
```

---

## Part 3: Related Files to Review

### TeamFilteredQuery.php
**Path:** `src/Services/Security/TeamFilteredQuery.php`
**Status:** May become unused after cleanup
**Action:** Review if still needed, possibly remove

### QueryResultFilter.php
**Path:** `src/Services/Security/QueryResultFilter.php`
**Status:** Post-query filtering becomes redundant if fetching through Eloquent
**Action:** Simplify or remove - HasSecurity handles filtering

### SensitiveDataSanitizer.php
**Path:** `src/Services/Security/SensitiveDataSanitizer.php`
**Status:** May still be useful for logging sanitization
**Action:** Keep but review

---

## Part 4: New Architecture (Replace With)

### Simple AiAccessGate

```php
// src/Services/Security/AiAccessGate.php
class AiAccessGate
{
    /**
     * Check if user can access entity via AI.
     * Delegates to auth package's hasPermission().
     */
    public function check(Authenticatable $user, string $entityClass): AiAccessLevel
    {
        $entity = class_basename($entityClass);

        // Check AI-specific permission first, fallback to standard
        $chain = config('ai.security.permission_chain', [
            '{Entity}.AiRetrieving',
            '{Entity}',
        ]);

        foreach ($chain as $pattern) {
            $key = str_replace('{Entity}', $entity, $pattern);
            if ($user->hasPermission($key, PermissionTypeEnum::READ)) {
                return $this->mapToLevel($key);
            }
        }

        return AiAccessLevel::None;
    }
}
```

### Fetch Through Eloquent Pattern

```php
// In query execution:

// 1. Neo4j returns IDs only
$ids = $neo4j->run("MATCH (p:Person) RETURN p.id")->pluck('id');

// 2. Fetch through Eloquent - HasSecurity applies automatically!
$results = Person::whereIn('id', $ids)->get();
// ^ Team filtering via HasSecurity global scope
// ^ sensibleColumns hides sensitive fields
// ^ All existing auth logic applies
```

---

## Execution Checklist

### Step 1: Remove _team_ids from Ingestion ✅ COMPLETED
- [x] Remove `_team_ids` from `buildVectorMetadata()` in DataIngestionService
- [x] Remove `resolveTeamIds()` method
- [x] Remove `ingestTeamRelationships()` method (BELONGS_TO_TEAM relationship creation)
- [ ] Run tests to ensure ingestion still works

### Step 2: Remove AccessLevelResolver ✅ COMPLETED
- [x] Remove `AccessLevelResolver.php`
- [x] Delete `PromptContextBuilder.php` (fully dependent on AccessLevelResolver)
- [x] Delete `QueryResultFilter.php` (fully dependent on AccessLevelResolver)
- [x] Simplified `filterSensitiveResults()` in `AiManager.php` to be a no-op
- [x] Remove from `AiServiceProvider.php`
- [x] Delete `AccessLevelResolverTest.php`
- [x] Delete `PromptContextBuilderTest.php`
- [x] Delete `QueryResultFilterTest.php`

### Step 3: Review Related Security Files ✅ COMPLETED
- [x] Review `TeamFilteredQuery.php` - DELETED (was never instantiated, dead code)
- [x] Removed `applyTeamFilter()` from QueryExecutor (used TeamFilteredQuery)
- [x] Keep `InputSanitizer.php` - still needed for prompt injection

### Step 4: Create New AiAccessGate (TODO - Future Work)
- [ ] Create simple `AiAccessGate.php`
- [ ] Add config for permission chain
- [ ] Integrate into query execution flow

### Step 5: Update Query Execution (TODO - Future Work)
- [ ] Modify to return IDs from Neo4j
- [ ] Add Eloquent fetch step with HasSecurity
- [ ] Test end-to-end security

---

## Files Summary

### Deleted
- `src/Services/Security/AccessLevelResolver.php` ✅
- `src/Services/Security/PromptContextBuilder.php` ✅
- `src/Services/Security/QueryResultFilter.php` ✅
- `src/Services/Security/TeamFilteredQuery.php` ✅ (was unused - never instantiated)
- `tests/Unit/Services/Security/AccessLevelResolverTest.php` ✅
- `tests/Unit/Services/Security/PromptContextBuilderTest.php` ✅
- `tests/Unit/Services/Security/QueryResultFilterTest.php` ✅
- `tests/Unit/Services/Security/TeamFilteredQueryTest.php` ✅
- `tests/Unit/Services/QueryExecutorSecurityTest.php` ✅ (tested team filtering)

### Modified
- `src/Services/DataIngestionService.php` - removed _team_ids, resolveTeamIds(), ingestTeamRelationships() ✅
- `src/Services/AiManager.php` - simplified filterSensitiveResults() to no-op ✅
- `src/AiServiceProvider.php` - removed AccessLevelResolver registration ✅
- `src/Services/QueryExecutor.php` - removed applyTeamFilter() and team_filter option ✅

### To Create (Future Work)
- `src/Services/Security/AiAccessGate.php` - simple permission check that delegates to auth package

---

## Next Steps

**Continuation document:** `docs/plans/2026-01-13-ai-security-implementation-continuation.md`

This document contains the full context and implementation plan for the new simplified security architecture.
