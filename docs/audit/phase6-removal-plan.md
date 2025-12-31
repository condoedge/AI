# Phase 6: Dead Code Removal Plan

**Date:** 2025-12-30
**Purpose:** Actionable checklist for removing dead code identified in architectural audit
**Sources:** phase3-dead-code.md, conversation-context-refactor-recommendation.md, phase5-ai-audit.md

---

## Overview

This plan removes 4 dead subsystems, 35+ unused methods, 4 dead files, associated tests, and orphaned database structures. Follow the removal order carefully - dependencies matter.

**Estimated Cleanup:**
- Files to delete: 8 source files + 4 test files
- Methods to remove: 35+
- Traits to delete: 2
- Contracts to delete: 1
- Database: 1 table, 1 column
- Config entries: 3 dead keys

---

## 1. Files to Delete Entirely

Delete these files completely. They contain dead code with no callers.

### 1.1 Dead Subsystem Files (CRITICAL)

| # | File Path | Justification |
|---|-----------|---------------|
| 1 | `src/Services/Cache/QueryResultCache.php` | Not registered in container, no callers, config keys never defined |
| 2 | `src/Services/Analytics/QueryAnalytics.php` | Not registered in container, depends on never-populated AiQueryLog |
| 3 | `src/Services/Learning/QueryLearner.php` | Not registered in container, AiQueryLog::logSuccess/logFailure never called |
| 4 | `src/Console/Commands/LearnFromLogsCommand.php` | Calls QueryLearner which is dead code |

### 1.2 Dead Service Files (HIGH)

| # | File Path | Justification |
|---|-----------|---------------|
| 5 | `src/Services/Chat/AmbiguityDetector.php` | Has tests but never called from UI flow, not integrated |

### 1.3 Dead Trait Files (HIGH)

| # | File Path | Justification |
|---|-----------|---------------|
| 6 | `src/Kompo/Traits/HasChatConfig.php` | Marked as deleted in git status (D), superseded by HasChatSettings |
| 7 | `src/Kompo/Traits/HasTypingIndicator.php` | Included in AiChatPanel but methods never actually called |

### 1.4 Dead Contract Files (MEDIUM)

| # | File Path | Justification |
|---|-----------|---------------|
| 8 | `src/Domain/Contracts/Searchable.php` | Never implemented by any class |

---

## 2. Methods to Delete (Organized by File)

### 2.1 AiChatService.php

**File:** `src/Services/Chat/AiChatService.php`

| # | Method Signature | Lines | Justification |
|---|------------------|-------|---------------|
| 1 | `public function ask(string $question, array $options = []): AiChatMessage` | 78-119 | Superseded by askWithConversation(), no callers |
| 2 | `public function askWithHistory(string $question, array $history, array $options = []): AiChatMessage` | 124-167 | Replaced by askWithConversation() per refactor recommendation |
| 3 | `protected function buildQuestionWithHistory(string $question, array $history, array $options): string` | 334-374 | Only used by askWithHistory() which is being deleted |
| 4 | `public function getSuggestions(string $question, string $response): array` | 379-429 | AI response already includes suggestions |
| 5 | `public function getExampleQuestions(): array` | 434-442 | Panel uses settings() directly |
| 6 | `public function prepareQuestionWithContext(...)` | 285-309 | Only has tests, not used in production flow |

### 2.2 AiChatMessage.php

**File:** `src/Services/Chat/AiChatMessage.php`

| # | Method Signature | Lines | Justification |
|---|------------------|-------|---------------|
| 1 | `public static function system(string $content): self` | 64-72 | Factory method never used |

### 2.3 AiChatResponseData.php

**File:** `src/Services/Chat/AiChatResponseData.php`

| # | Method Signature | Lines | Justification |
|---|------------------|-------|---------------|
| 1 | `public static function list(array $items): self` | 62-68 | Factory method never called |
| 2 | `public static function metric(string $label, mixed $value, ?string $icon = null, ?string $trend = null): self` | 73-84 | Factory method never called |
| 3 | `public function withActions(array $actions): self` | 119-132 | Fluent method never called |

### 2.4 CircuitBreaker.php

**File:** `src/Services/Resilience/CircuitBreaker.php`

| # | Method Signature | Justification |
|---|------------------|---------------|
| 1 | `public function syncToCache(): void` | Dead code - never called |
| 2 | `public function getState(): string` | Diagnostic only, no callers |
| 3 | `public function getFailureCount(): int` | Diagnostic only, no callers |
| 4 | `public function isOpen(): bool` | Diagnostic only, no callers |
| 5 | `public function reset(): void` | Manual override, no admin interface |

### 2.5 RateLimiter.php

**File:** `src/Services/Resilience/RateLimiter.php`

| # | Method Signature | Justification |
|---|------------------|---------------|
| 1 | `public function remaining(): int` | Never used |

### 2.6 RetryPolicy.php

**File:** `src/Services/Resilience/RetryPolicy.php` (if exists)

| # | Method Signature | Justification |
|---|------------------|---------------|
| 1 | `public static function forApiCalls(): self` | Unused factory method |
| 2 | `public static function forNetworkRequests(): self` | Unused factory method |

### 2.7 FileSearchService.php

**File:** `src/Services/Files/FileSearchService.php` (verify path)

| # | Method Signature | Justification |
|---|------------------|---------------|
| 1 | `public function searchByMetadata(...)` | No direct callers found |
| 2 | `public function hybridSearch(...)` | No direct callers found |
| 3 | `public function getRelatedFiles(...)` | No direct callers found |
| 4 | `public function getFilesByUser(...)` | No direct callers found |
| 5 | `public function getFilesByTeam(...)` | No direct callers found |

### 2.8 FileExtractorRegistry.php

**File:** `src/Services/Files/FileExtractorRegistry.php` (verify path)

| # | Method Signature | Justification |
|---|------------------|---------------|
| 1 | `public function extractMetadata(...)` | No usages found |
| 2 | `public function getStats(): array` | No usages found |

### 2.9 FileContextProvider.php

**File:** `src/Services/Context/FileContextProvider.php` (verify path)

| # | Method Signature | Justification |
|---|------------------|---------------|
| 1 | `public function buildFileReference(...)` | Public method with no callers |

### 2.10 SemanticMatcher.php

**File:** `src/Services/SemanticMatcher.php`

| # | Method Signature | Justification |
|---|------------------|---------------|
| 1 | `public function matchScopes(...)` | Superseded by ScopeSemanticMatcher |
| 2 | `public function matchEntities(...)` | No direct usages outside tests |
| 3 | `public function matchLabel(...)` | No direct usages found |

### 2.11 TeamFilteredQuery.php

**File:** `src/Services/Security/TeamFilteredQuery.php` (verify path)

| # | Method Signature | Justification |
|---|------------------|---------------|
| 1 | `public function searchQdrant(...)` | No references found |
| 2 | `public function toCypherWhereClause(): string` | No references found |
| 3 | `public function applyThreshold(...)` | No references found |

### 2.12 ConfigTheme.php

**File:** `src/Services/UI/ConfigTheme.php` (verify path)

| # | Method Signature | Justification |
|---|------------------|---------------|
| 1 | `public function isComplete(): bool` | No usages found |
| 2 | `public function getMissingColors(): array` | No usages found |

---

## 3. Traits to Delete

| # | Trait | File Path | Justification |
|---|-------|-----------|---------------|
| 1 | `HasChatConfig` | `src/Kompo/Traits/HasChatConfig.php` | Already marked deleted (D), superseded by HasChatSettings |
| 2 | `HasTypingIndicator` | `src/Kompo/Traits/HasTypingIndicator.php` | Imported but methods never called in AiChatPanel |

### 3.1 Before Deleting HasTypingIndicator

**Action Required:** Remove the `use` statement from AiChatPanel:

**File:** `src/Kompo/AiChatPanel.php`

```php
// Line 11 - REMOVE:
use Condoedge\Ai\Kompo\Traits\HasTypingIndicator;

// Line 38 - CHANGE FROM:
use HasChatSettings, HasChatTheme, HasAvatars, HasTypingIndicator, HasMethodsAsProperties;

// TO:
use HasChatSettings, HasChatTheme, HasAvatars, HasMethodsAsProperties;
```

---

## 4. Classes to Delete

These are contained within the files listed in Section 1:

| # | Class | Contained In |
|---|-------|--------------|
| 1 | `QueryResultCache` | `src/Services/Cache/QueryResultCache.php` |
| 2 | `QueryAnalytics` | `src/Services/Analytics/QueryAnalytics.php` |
| 3 | `QueryLearner` | `src/Services/Learning/QueryLearner.php` |
| 4 | `LearnFromLogsCommand` | `src/Console/Commands/LearnFromLogsCommand.php` |
| 5 | `AmbiguityDetector` | `src/Services/Chat/AmbiguityDetector.php` |
| 6 | `Searchable` (interface) | `src/Domain/Contracts/Searchable.php` |

---

## 5. Database Columns/Tables to Remove

### 5.1 Tables Never Used

| # | Table | Migration File | Justification |
|---|-------|----------------|---------------|
| 1 | `ai_query_logs` | `database/migrations/2025_01_01_000002_create_ai_query_logs_table.php` | AiQueryLog::logSuccess() and logFailure() never called anywhere - table is never populated |

**Action:** Create a migration to drop this table:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ai_query_logs');
    }

    public function down(): void
    {
        // Recreate if needed - copy from original migration
    }
};
```

### 5.2 Columns Never Written/Read

| # | Table | Column | Justification |
|---|-------|--------|---------------|
| 1 | `ai_messages` | `context_used` | Never passed to addMessage() in ChatMessageForm |

**Action:** Create a migration to drop this column:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn('context_used');
        });
    }

    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->json('context_used')->nullable();
        });
    }
};
```

### 5.3 Dead Data Fields (Never Consumed)

These fields are written but never read. Consider removing in a future cleanup:

| Table | Field | Status |
|-------|-------|--------|
| `ai_messages` | `confidence_score` | Stored but never used |
| `ai_conversations` | `context_snapshot.last_result_count` | Stored but unused |
| `ai_conversations` | `context_snapshot.last_relationships` | Stored but unused |

---

## 6. Config Entries to Remove/Fix

### 6.1 Dead Config Keys (Never Defined)

These keys are referenced in dead code. No action needed once the dead code is deleted:

| # | Config Key | Referenced By | Status |
|---|------------|---------------|--------|
| 1 | `ai.cache.prefix` | QueryResultCache (dead) | N/A after deletion |
| 2 | `ai.cache.ttl` | QueryResultCache (dead) | N/A after deletion |
| 3 | `ai.cache.enabled` | QueryResultCache (dead) | N/A after deletion |

### 6.2 Config Key Mismatches (Fix or Align)

These keys have naming mismatches between code and config:

| # | Code Uses | Config Defines | Fix Required |
|---|-----------|----------------|--------------|
| 1 | `ai.chat.show_typing` | `ai.chat.show_typing_indicator` | Update code to use correct key |
| 2 | `ai.chat.welcome_title` | `ai.chat.welcome.title` | Update code to use nested structure |
| 3 | `ai.chat.welcome_message` | `ai.chat.welcome.message` | Update code to use nested structure |

**Note:** These are in UserChatSettings. Verify before removing.

---

## 7. Removal Order (Dependencies)

Execute deletions in this order to avoid breaking dependencies:

### Phase 1: Remove Test Files First

```bash
# Delete test files for dead code
rm tests/Unit/Services/Learning/QueryLearnerTest.php
rm tests/Unit/Services/Analytics/QueryAnalyticsTest.php
rm tests/Unit/Services/Cache/QueryResultCacheTest.php
rm tests/Unit/Services/Chat/AmbiguityDetectorTest.php
```

### Phase 2: Remove Dead Subsystem Files

```bash
# Learning subsystem (has internal dependency)
rm src/Console/Commands/LearnFromLogsCommand.php  # First - depends on QueryLearner
rm src/Services/Learning/QueryLearner.php          # Second - no more dependents

# Analytics subsystem
rm src/Services/Analytics/QueryAnalytics.php

# Cache subsystem
rm src/Services/Cache/QueryResultCache.php

# Chat subsystem orphan
rm src/Services/Chat/AmbiguityDetector.php
```

### Phase 3: Update AiChatPanel Before Deleting Trait

**Edit:** `src/Kompo/AiChatPanel.php`
- Remove `use Condoedge\Ai\Kompo\Traits\HasTypingIndicator;` from imports
- Remove `HasTypingIndicator` from trait list on line 38

### Phase 4: Remove Dead Traits

```bash
rm src/Kompo/Traits/HasTypingIndicator.php
# HasChatConfig.php is already deleted per git status
```

### Phase 5: Remove Dead Contract

```bash
rm src/Domain/Contracts/Searchable.php
```

### Phase 6: Remove Methods from Existing Files

Edit each file listed in Section 2 to remove the specified methods.

**Order for AiChatService.php:**
1. Remove `buildQuestionWithHistory()` - private, only called by `askWithHistory()`
2. Remove `askWithHistory()` - public, has no callers after step 1
3. Remove `ask()` - public, superseded
4. Remove `getSuggestions()` - public, unused
5. Remove `getExampleQuestions()` - public, unused
6. Remove `prepareQuestionWithContext()` - public, unused

### Phase 7: Update Interface

**Edit:** `src/Services/Chat/AiChatServiceInterface.php`
- Remove method signatures for deleted methods
- Keep only: `askWithConversation()`, `isAvailable()`, `getContextManager()`

### Phase 8: Database Migrations

```bash
# Create and run migrations
php artisan make:migration drop_ai_query_logs_table
php artisan make:migration drop_context_used_from_ai_messages

# After editing migrations with content from Section 5:
php artisan migrate
```

### Phase 9: Empty Directory Cleanup

```bash
# Check if directories are empty, delete if so
rmdir src/Services/Learning    # If empty after QueryLearner deletion
rmdir src/Services/Analytics   # If empty after QueryAnalytics deletion
rmdir src/Services/Cache       # If empty after QueryResultCache deletion
```

---

## 8. Commands to Run After Removal

Execute these commands after all deletions are complete:

### 8.1 Required Commands

```bash
# Regenerate autoloader with updated class map
composer dump-autoload

# Clear all Laravel caches
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan route:clear

# If using IDE helper
php artisan ide-helper:generate
php artisan ide-helper:models --nowrite

# Run tests to verify nothing is broken
php artisan test
```

### 8.2 Optional Verification Commands

```bash
# Check for any remaining references to deleted classes
grep -r "QueryLearner" src/
grep -r "QueryAnalytics" src/
grep -r "QueryResultCache" src/
grep -r "AmbiguityDetector" src/
grep -r "HasTypingIndicator" src/
grep -r "LearnFromLogsCommand" src/

# Verify no broken imports
php artisan tinker --execute="echo 'Autoloading OK';"
```

### 8.3 Static Analysis (If Available)

```bash
# PHPStan or Psalm analysis
./vendor/bin/phpstan analyse src/

# Or if using Larastan
./vendor/bin/phpstan analyse
```

---

## 9. Summary Checklist

### Files to Delete (12 total)

- [ ] `src/Services/Cache/QueryResultCache.php`
- [ ] `src/Services/Analytics/QueryAnalytics.php`
- [ ] `src/Services/Learning/QueryLearner.php`
- [ ] `src/Console/Commands/LearnFromLogsCommand.php`
- [ ] `src/Services/Chat/AmbiguityDetector.php`
- [ ] `src/Kompo/Traits/HasTypingIndicator.php`
- [ ] `src/Domain/Contracts/Searchable.php`
- [ ] `tests/Unit/Services/Learning/QueryLearnerTest.php`
- [ ] `tests/Unit/Services/Analytics/QueryAnalyticsTest.php`
- [ ] `tests/Unit/Services/Cache/QueryResultCacheTest.php`
- [ ] `tests/Unit/Services/Chat/AmbiguityDetectorTest.php`
- [ ] `src/Kompo/Traits/HasChatConfig.php` (already deleted per git status)

### Code Modifications Required

- [ ] Update `src/Kompo/AiChatPanel.php` - remove HasTypingIndicator trait
- [ ] Update `src/Services/Chat/AiChatService.php` - remove 6 methods
- [ ] Update `src/Services/Chat/AiChatServiceInterface.php` - update interface
- [ ] Update `src/Services/Chat/AiChatMessage.php` - remove system() method
- [ ] Update `src/Services/Chat/AiChatResponseData.php` - remove 3 methods
- [ ] Update `src/Services/Resilience/CircuitBreaker.php` - remove 5 methods
- [ ] Update `src/Services/Resilience/RateLimiter.php` - remove remaining() method

### Database Changes

- [ ] Create migration to drop `ai_query_logs` table
- [ ] Create migration to drop `context_used` column from `ai_messages`
- [ ] Run migrations

### Post-Removal Commands

- [ ] `composer dump-autoload`
- [ ] `php artisan cache:clear`
- [ ] `php artisan config:clear`
- [ ] `php artisan test`
- [ ] Verify grep searches return no results

---

## 10. Risk Assessment

| Action | Risk Level | Mitigation |
|--------|------------|------------|
| Delete dead subsystem files | LOW | No callers exist, isolated code |
| Delete AmbiguityDetector | LOW | Has tests but no production usage |
| Remove AiChatService methods | MEDIUM | Verify ChatMessageForm uses askWithConversation() |
| Drop ai_query_logs table | LOW | Never populated, no data loss |
| Drop context_used column | LOW | Never written, no data loss |

### Before Starting

1. Ensure all changes are committed (or stashed) before starting
2. Create a new branch for cleanup: `git checkout -b cleanup/dead-code-removal`
3. Have a full database backup if dropping tables
4. Review `git diff` after each phase

---

## 11. Future Considerations

### Code That Could Be Reactivated

The following dead code represents potentially useful features that were built but never integrated:

1. **QueryLearner** - Learning from successful queries (needs AiQueryLog activation)
2. **QueryAnalytics** - Analytics dashboard (needs AiQueryLog activation)
3. **ConversationContextManager** - Full context tracking (needs UI wiring to askWithConversation)
4. **AmbiguityDetector** - Detecting ambiguous queries (needs UI integration)

If reactivating these features in the future, reference:
- `docs/audit/conversation-context-refactor-recommendation.md`
- `docs/audit/phase5-ai-audit.md`

### HasChatSettings Shorthand Methods

The 15 shorthand methods in `HasChatSettings` (showAvatars(), showTimestamps(), etc.) are unused because components call `$this->settings()->method()` directly. These could be:
- **Deleted** for consistency
- **Kept** for potential future convenience
- **Documented** as optional shortcuts

This is a LOW priority cleanup item.
