# Phase 3: Dead Code Inventory

**Date:** 2025-12-30
**Task:** 39 - Comprehensive Dead Code Inventory
**Source:** Phase 2 Audit Documents + Phase 3 Flow Traces

---

## Executive Summary

This document compiles all dead code identified across Phase 2 service audits and Phase 3 flow traces. Dead code is categorized by severity and type, with evidence from flow traces proving non-usage.

**Total Items Identified:** 78+
- **Critical (Classes/Files to DELETE):** 4
- **High (Methods to DELETE):** 35+
- **Medium (Unused but potentially useful):** 25+
- **Low (Debug/diagnostic methods):** 14+

---

## 1. FILES to DELETE Entirely

These files contain classes that are never instantiated or used in the main pipeline.

| # | File | Evidence | Dependencies | Priority |
|---|------|----------|--------------|----------|
| 1 | `src/Services/Learning/QueryLearner.php` | Not registered in container, `AiQueryLog` never populated, no callers (phase2-services-learning.md) | `AiQueryLog` model | CRITICAL |
| 2 | `src/Services/Analytics/QueryAnalytics.php` | Not registered in container, no usages found, depends on never-populated `AiQueryLog` (phase2-services-analytics-cache.md) | `AiQueryLog` model | CRITICAL |
| 3 | `src/Services/Cache/QueryResultCache.php` | Not registered in container, no callers, config keys missing (phase2-services-analytics-cache.md) | None | CRITICAL |
| 4 | `src/Console/Commands/LearnFromLogsCommand.php` | Calls `QueryLearner` which is dead code (phase2-services-learning.md) | `QueryLearner` | HIGH |

### Dependency Note
Files #1, #2, and #4 all depend on `AiQueryLog` being populated. Since `AiQueryLog::logSuccess()` and `AiQueryLog::logFailure()` are **never called anywhere**, the entire learning/analytics subsystem is non-functional.

---

## 2. CLASSES to DELETE

These classes exist but are never instantiated in the main flow.

| # | Class | File | Evidence | Priority |
|---|-------|------|----------|----------|
| 1 | `QueryLearner` | `src/Services/Learning/QueryLearner.php` | See Files section above | CRITICAL |
| 2 | `QueryAnalytics` | `src/Services/Analytics/QueryAnalytics.php` | See Files section above | CRITICAL |
| 3 | `QueryResultCache` | `src/Services/Cache/QueryResultCache.php` | See Files section above | CRITICAL |
| 4 | `AmbiguityDetector` | `src/Services/Chat/AmbiguityDetector.php` | Has tests but never called from UI flow (phase2-services-chat.md, phase3-flow-chat.md) | HIGH |

---

## 3. METHODS to DELETE

### 3.1 Completely Unused Methods (Never Called)

| # | Class | Method | Evidence | Priority |
|---|-------|--------|----------|----------|
| 1 | `QueryLearner` | `findSimilarLearnedQuery()` | ORPHANED - never called by any service (phase2-services-learning.md) | CRITICAL |
| 2 | `CircuitBreaker` | `syncToCache()` | DEAD CODE - never called (phase2-services-resilience.md) | HIGH |
| 3 | `CircuitBreaker` | `getState()` | Diagnostic method, no callers | MEDIUM |
| 4 | `CircuitBreaker` | `getFailureCount()` | Diagnostic method, no callers | MEDIUM |
| 5 | `CircuitBreaker` | `isOpen()` | Diagnostic method, no callers | MEDIUM |
| 6 | `CircuitBreaker` | `reset()` | Manual override, no admin interface | MEDIUM |
| 7 | `RateLimiter` | `remaining()` | Never used | MEDIUM |
| 8 | `RetryPolicy` | `forApiCalls()` | Unused factory method | LOW |
| 9 | `RetryPolicy` | `forNetworkRequests()` | Unused factory method | LOW |
| 10 | `AiChatService` | `ask()` | Superseded by `askWithHistory()` (phase2-services-chat.md) | HIGH |
| 11 | `AiChatService` | `askWithConversation()` | NEVER CALLED from UI - sophisticated context tracking bypassed (phase3-flow-chat.md) | CRITICAL |
| 12 | `AiChatService` | `prepareQuestionWithContext()` | Only has tests, not in UI (phase2-services-chat.md) | HIGH |
| 13 | `AiChatService` | `getSuggestions()` | Not called - suggestions come from AI response (phase2-services-chat.md) | HIGH |
| 14 | `AiChatService` | `getExampleQuestions()` | Not called - panel uses settings (phase2-services-chat.md) | HIGH |
| 15 | `AiChatMessage` | `system()` | Factory method never used | LOW |
| 16 | `AiChatResponseData` | `list()` | Factory method never called | LOW |
| 17 | `AiChatResponseData` | `metric()` | Factory method never called | LOW |
| 18 | `AiChatResponseData` | `withActions()` | Fluent method never called | LOW |
| 19 | `FileSearchService` | `searchByMetadata()` | No direct callers found (phase2-services-files.md) | MEDIUM |
| 20 | `FileSearchService` | `hybridSearch()` | No direct callers found (phase2-services-files.md) | MEDIUM |
| 21 | `FileSearchService` | `getRelatedFiles()` | No direct callers found (phase2-services-files.md) | MEDIUM |
| 22 | `FileSearchService` | `getFilesByUser()` | No direct callers found (phase2-services-files.md) | MEDIUM |
| 23 | `FileSearchService` | `getFilesByTeam()` | No direct callers found (phase2-services-files.md) | MEDIUM |
| 24 | `FileExtractorRegistry` | `extractMetadata()` | No usages found (phase2-services-files.md) | MEDIUM |
| 25 | `FileExtractorRegistry` | `getStats()` | No usages found (phase2-services-files.md) | LOW |
| 26 | `FileContextProvider` | `buildFileReference()` | Public method with no callers (phase2-services-context.md) | MEDIUM |
| 27 | `FileAccessResolver` | `getPhysicalFilePath()` | Available but no direct callers (phase2-services-context.md) | LOW |
| 28 | `SemanticMatcher` | `matchEntities()` | No direct usages outside tests (phase2-services-semantic.md) | MEDIUM |
| 29 | `SemanticMatcher` | `matchScopes()` | Superseded by `ScopeSemanticMatcher` (phase2-services-semantic.md) | HIGH |
| 30 | `SemanticMatcher` | `matchLabel()` | No direct usages found (phase2-services-semantic.md) | MEDIUM |
| 31 | `TeamFilteredQuery` | `searchQdrant()` | No references found (phase2-services-security.md) | MEDIUM |
| 32 | `TeamFilteredQuery` | `toCypherWhereClause()` | No references found (phase2-services-security.md) | MEDIUM |
| 33 | `TeamFilteredQuery` | `applyThreshold()` | No references found (phase2-services-security.md) | MEDIUM |
| 34 | `ConfigTheme` | `isComplete()` | No usages found (phase2-services-ui-settings.md) | LOW |
| 35 | `ConfigTheme` | `getMissingColors()` | No usages found (phase2-services-ui-settings.md) | LOW |

### 3.2 Discovery Services - Underutilized Methods

| # | Class | Method | Evidence | Priority |
|---|-------|--------|----------|----------|
| 1 | `CypherQueryBuilderSpy` | `whereNotIn()` | Test helper, no production use (phase2-services-discovery.md) | LOW |
| 2 | `CypherQueryBuilderSpy` | `whereNotNull()` | Test helper, no production use | LOW |
| 3 | `CypherQueryBuilderSpy` | `whereDoesntHave()` | Test helper, no production use | LOW |
| 4 | `CypherQueryBuilderSpy` | `whereTime()` | Test helper, no production use | LOW |
| 5 | `CypherQueryBuilderSpy` | `whereBetween()` | Test helper, no production use | LOW |
| 6 | `CypherQueryBuilderSpy` | `whereNotBetween()` | Test helper, no production use | LOW |
| 7 | `CypherQueryBuilderSpy` | `whereColumn()` | Test helper, no production use | LOW |
| 8 | `CypherQueryBuilderSpy` | `getModelClass()` | Test helper, no production use | LOW |
| 9 | `CypherQueryBuilderSpy` | `clearCalls()` | Test helper, no production use | LOW |
| 10 | `CypherQueryBuilderSpy` | `countCalls()` | Test helper, no production use | LOW |
| 11 | `CypherScopeAdapter` | `getSpy()` | Test helper only | LOW |
| 12 | `CypherScopeAdapter` | `getGenerator()` | Test helper only | LOW |
| 13 | `PropertyDiscoverer` | `discoverWithTypes()` | May be unused | LOW |
| 14 | `RelationshipDiscoverer` | `detectDiscriminatorInRelation()` | May be unused | LOW |
| 15 | `SchemaInspector` | `clearCache()` | Utility method | LOW |
| 16 | `SchemaInspector` | `clearAllCaches()` | Utility method | LOW |
| 17 | `TraversalScopeGenerator` | `getDiscriminatorFields()` | Test helper | LOW |
| 18 | `TraversalScopeGenerator` | `isDiscriminatorField()` | Test helper | LOW |
| 19 | `EntityAutoDiscovery` | `discoverAndMerge()` | May be unused | LOW |
| 20 | `EntityAutoDiscovery` | `shouldDiscover()` | May be unused | LOW |

### 3.3 HasChatSettings Trait - Redundant Shorthand Methods

All 15 shorthand methods are never called (components use `$this->settings()->method()` directly):

| # | Method | Default Value | Priority |
|---|--------|---------------|----------|
| 1 | `showAvatars()` | `true` | LOW |
| 2 | `showTimestamps()` | `false` | LOW |
| 3 | `showTyping()` | `true` | LOW |
| 4 | `showSuggestions()` | `true` | LOW |
| 5 | `showMetrics()` | `false` | LOW |
| 6 | `enableCopy()` | `true` | LOW |
| 7 | `enableFeedback()` | `true` | LOW |
| 8 | `enableEdit()` | `true` | LOW |
| 9 | `enableRegenerate()` | `true` | LOW |
| 10 | `welcomeTitle()` | `'AI Assistant'` | LOW |
| 11 | `welcomeMessage()` | `'Ask me anything...'` | LOW |
| 12 | `inputPlaceholder()` | `'Ask a question...'` | LOW |
| 13 | `exampleQuestions()` | `[]` | LOW |
| 14 | `maxSuggestions()` | `3` | LOW |
| 15 | `responseStyle()` | `'friendly'` | LOW |

---

## 4. TRAITS to DELETE

| # | Trait | File | Evidence | Priority |
|---|-------|------|----------|----------|
| 1 | `HasChatConfig` | `src/Kompo/Traits/HasChatConfig.php` | File marked as deleted in git status | HIGH |

---

## 5. EXCEPTIONS to DELETE

No unused exceptions identified. All defined exceptions are thrown by active code.

---

## 6. DB COLUMNS to DELETE

### 6.1 Never Written/Read

| # | Table | Column | Evidence | Priority |
|---|-------|--------|----------|----------|
| 1 | `ai_messages` | `context_used` | Never passed to `addMessage()` in ChatMessageForm (phase3-flow-chat.md) | MEDIUM |

### 6.2 Never Populated (Source Table Dead)

| # | Table | All Columns | Evidence | Priority |
|---|-------|-------------|----------|----------|
| 1 | `ai_query_logs` | ALL | `AiQueryLog::logSuccess()` and `logFailure()` never called (phase2-services-analytics-cache.md) | HIGH |

---

## 7. CONFIG KEYS to DELETE

### 7.1 Never Accessed

| # | Config Key | File | Evidence | Priority |
|---|------------|------|----------|----------|
| 1 | `ai.cache.prefix` | Not defined | Referenced in `QueryResultCache` which is dead (phase2-services-analytics-cache.md) | HIGH |
| 2 | `ai.cache.ttl` | Not defined | Referenced in `QueryResultCache` which is dead | HIGH |
| 3 | `ai.cache.enabled` | Not defined | Referenced in `QueryResultCache` which is dead | HIGH |

### 7.2 Key Mismatches (Dead Due to Wrong Name)

| # | Used Key | Actual Config Key | Evidence | Priority |
|---|----------|------------------|----------|----------|
| 1 | `ai.chat.show_typing` | `ai.chat.show_typing_indicator` | UserChatSettings looks for wrong key (phase3-flow-settings.md) | HIGH |
| 2 | `ai.chat.welcome_title` | `ai.chat.welcome.title` | Nested structure mismatch | MEDIUM |
| 3 | `ai.chat.welcome_message` | `ai.chat.welcome.message` | Nested structure mismatch | MEDIUM |

---

## 8. ENTIRE SUBSYSTEMS Identified as Dead

### 8.1 Learning Subsystem (DEAD)

**Reason:** Data source (`AiQueryLog`) is never populated.

| Component | File | Status |
|-----------|------|--------|
| `QueryLearner` | `src/Services/Learning/QueryLearner.php` | DEAD |
| `LearnFromLogsCommand` | `src/Console/Commands/LearnFromLogsCommand.php` | DEAD |
| `AiQueryLog` model methods | `src/Models/AiQueryLog.php` | DEAD (static logging methods never called) |

### 8.2 Analytics Subsystem (DEAD)

**Reason:** Depends on `AiQueryLog` which is never populated.

| Component | File | Status |
|-----------|------|--------|
| `QueryAnalytics` | `src/Services/Analytics/QueryAnalytics.php` | DEAD |

### 8.3 Query Result Caching (DEAD)

**Reason:** Not registered in container, config not defined.

| Component | File | Status |
|-----------|------|--------|
| `QueryResultCache` | `src/Services/Cache/QueryResultCache.php` | DEAD |

### 8.4 Conversation Context Manager (BYPASSED)

**Reason:** `ChatMessageForm.sendMessage()` calls `askWithHistory()` instead of `askWithConversation()`.

| Component | File | Status |
|-----------|------|--------|
| `ConversationContextManager` | `src/Services/Context/ConversationContextManager.php` | BYPASSED in UI flow |
| `EntityExtractor` | `src/Services/Context/EntityExtractor.php` | BYPASSED in UI flow |
| `ReferenceResolver` | `src/Services/Context/ReferenceResolver.php` | BYPASSED in UI flow |

**Note:** These are well-designed and tested components that ARE registered in the container, but the UI flow bypasses them. This may be intentional simplification or an incomplete integration.

---

## 9. Priority-Based Action Plan

### CRITICAL (Delete Immediately - No Dependencies)

1. Delete `src/Services/Cache/QueryResultCache.php`
2. Delete `src/Services/Analytics/QueryAnalytics.php`
3. Delete `src/Services/Learning/QueryLearner.php`
4. Delete `src/Console/Commands/LearnFromLogsCommand.php`

### HIGH (Delete After Verification)

1. Delete `src/Services/Chat/AmbiguityDetector.php` (or integrate it)
2. Remove `AiChatService::ask()` method
3. Remove `AiChatService::getSuggestions()` method
4. Remove `AiChatService::getExampleQuestions()` method
5. Remove `CircuitBreaker::syncToCache()` method
6. Remove `SemanticMatcher::matchScopes()` method (use ScopeSemanticMatcher)

### MEDIUM (Consider Removal or Integration)

1. Integrate or remove `AiChatService::askWithConversation()` and related context system
2. Remove unused `FileSearchService` methods
3. Remove unused `FileContextProvider::buildFileReference()` method
4. Remove `TeamFilteredQuery` unused methods (or integrate class into pipeline)
5. Fix config key mismatches in settings system

### LOW (Cleanup When Convenient)

1. Remove discovery service test helper methods from production code
2. Remove `HasChatSettings` shorthand methods (or use them)
3. Remove unused factory methods in `RetryPolicy`
4. Remove unused `AiChatMessage::system()` and `AiChatResponseData` factory methods

---

## 10. Impact Analysis

### If CRITICAL Items Deleted

- **Risk:** None - these components are completely isolated
- **Tests:** Unit tests for these classes should also be deleted
- **Migration:** `ai_query_logs` table can remain (may be used later)

### If Context Manager System Integrated

- **Benefit:** Natural follow-up questions would work ("show me more about those")
- **Change Required:** `ChatMessageForm` to call `askWithConversation()` instead of `askWithHistory()`
- **Risk:** Medium - needs thorough testing

### If Analytics/Learning System Activated

- **Benefit:** Query improvement over time, success rate tracking
- **Change Required:** Add `AiQueryLog::logSuccess()` and `logFailure()` calls to `QueryExecutor`
- **Risk:** Low - straightforward integration

---

## Appendix: Source Documents

| Document | Path |
|----------|------|
| Learning Services Audit | `docs/audit/phase2-services-learning.md` |
| Analytics/Cache Audit | `docs/audit/phase2-services-analytics-cache.md` |
| Resilience Services Audit | `docs/audit/phase2-services-resilience.md` |
| Chat Services Audit | `docs/audit/phase2-services-chat.md` |
| Context Services Audit | `docs/audit/phase2-services-context.md` |
| File Services Audit | `docs/audit/phase2-services-files.md` |
| Semantic Services Audit | `docs/audit/phase2-services-semantic.md` |
| Security Services Audit | `docs/audit/phase2-services-security.md` |
| Discovery Services Audit | `docs/audit/phase2-services-discovery.md` |
| UI/Settings Services Audit | `docs/audit/phase2-services-ui-settings.md` |
| Chat Flow Trace | `docs/audit/phase3-flow-chat.md` |
| Query Flow Trace | `docs/audit/phase3-flow-query.md` |
| Context Flow Trace | `docs/audit/phase3-flow-context.md` |
| Settings Flow Trace | `docs/audit/phase3-flow-settings.md` |
| Ingestion Flow Trace | `docs/audit/phase3-flow-ingestion.md` |
