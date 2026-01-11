# Comprehensive AI Response Features - Improvement Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Audit and improve all interconnected AI response features: file retrieval, conversation context, action links, and data/table rendering.

**Architecture:** Multi-phase improvement covering 5 major subsystems with critical bugs, gaps, and enhancements.

**Tech Stack:** Laravel, Kompo, Neo4j, Qdrant, LLM APIs

---

## Executive Summary

After comprehensive exploration of the codebase, we've identified:
- **2 Critical Bugs** requiring immediate fix
- **8 High-Priority Gaps** affecting core functionality
- **15 Medium-Priority Enhancements** for robustness
- **Cross-cutting concerns** affecting multiple subsystems

---

## System Architecture Overview

```
User Message → AiChatService → ConversationContextManager
                                      ↓
                              FileContextProvider
                                      ↓
                              AiManager.answerQuestion()
                                      ↓
              ┌─────────────────────────────────────────┐
              │  QueryGenerator → QueryExecutor →       │
              │  ResponseEntityEnricher →               │
              │  ResponseGenerator →                    │
              │  ResponseFileEnricher                   │
              └─────────────────────────────────────────┘
                                      ↓
                              MessagesQuery (UI)
                                      ↓
                              Action Links / Tables / Files
```

---

## PHASE 1: CRITICAL BUGS (Immediate Fix)

### CRIT-001: Debug Statement in Production Code
**File:** `src/Services/ResponseGenerator.php:317`
**Issue:** `dd($prompt);` halts execution
**Impact:** Breaks all response generation
**Fix:** Remove debug statement

### CRIT-002: Frontend Action Link Click Handlers Missing
**File:** `resources/js/` (missing implementation)
**Issue:** Action links render as `<span>` with data attributes but no click handlers
**Impact:** Action links are completely non-functional
**Fix:** Implement JavaScript click handlers to trigger Kompo methods

---

## PHASE 2: FILE RETRIEVAL SYSTEM

### Current Architecture
- **Storage:** Neo4j (metadata/relationships) + Qdrant (content vectors)
- **Search:** FileContextProvider with filename + semantic search
- **Access:** FileAccessResolver with scope/fallback filtering
- **Enrichment:** ResponseFileEnricher extracts citations [1], [2]

### Gaps Identified

| ID | Gap | Priority | Impact |
|----|-----|----------|--------|
| FILE-001 | FilePreviewModal deleted without replacement | HIGH | No file preview UI |
| FILE-002 | Physical file path security (no validation) | HIGH | Potential directory traversal |
| FILE-003 | No chunk refresh strategy | MEDIUM | Stale content on file updates |
| FILE-004 | Embedding dimension mismatch risk | MEDIUM | Silent failures on model change |
| FILE-005 | Search limit multipliers undocumented | LOW | Performance impact |
| FILE-006 | Access control scope coupling | LOW | Limited permission models |

### Recommended Improvements

**Task 2.1: Restore or Replace FilePreviewModal**
- Either restore `src/Kompo/Modals/FilePreviewModal.php` with improvements
- Or document external solution pattern for applications

**Task 2.2: Add Physical File Path Validation**
- Canonicalize paths before access
- Validate against configured whitelist patterns
- Prevent `../` traversal attacks

**Task 2.3: Implement File Reference Schema Normalization**
- Standardize: `id`, `name`, `snippet`, `relevance`, `source`
- Remove inconsistencies: `file_id` vs `id`, `filename` vs `name`
- Create FileReference value object

---

## PHASE 3: CONVERSATION CONTEXT PERSISTENCE

### Current Architecture
- **Manager:** ConversationContextManager orchestrates context flow
- **Storage:** `context_snapshot` JSON column on AiConversation
- **Sections:** ConversationContextSection (prompt), ResponseConversationContextSection (response)
- **Extraction:** EntityExtractor + ReferenceResolver for follow-ups

### Context Snapshot Fields
```php
[
    'focused_entity' => string,
    'focused_entity_filter' => string,
    'mentioned_entities' => array,
    'last_result_sample' => array (3 items),
    'last_result_count' => int,
    'last_cypher_query' => string,
    'last_query_type' => string,
    'last_detected_template' => string,
    'last_relationships' => array,
    'last_answer_summary' => string (200 chars),
    'last_referenced_files' => array,
    'last_insights' => array,
    'last_visualizations' => array, // NEVER POPULATED
    'last_execution_stats' => array,
    'updated_at' => ISO8601,
]
```

### Gaps Identified

| ID | Gap | Priority | Impact |
|----|-----|----------|--------|
| CTX-001 | Entity actions not persisted | HIGH | No action awareness across turns |
| CTX-002 | last_visualizations never populated | HIGH | No chart reference in follow-ups |
| CTX-003 | Only last_referenced_files preserved | MEDIUM | Can't reference "file from Q1" in Q5 |
| CTX-004 | No context size monitoring | MEDIUM | Unbounded JSON growth |
| CTX-005 | No context confidence scoring | MEDIUM | Stale context treated as fresh |
| CTX-006 | Entity filter extraction limited | LOW | Complex WHERE clauses missed |

### Recommended Improvements

**Task 3.1: Persist Entity Actions in Context**
- Add `last_available_actions` to context snapshot
- Update ResponseEntityEnricher to call ConversationContextManager
- Enable "What actions can I do with this?" follow-ups

**Task 3.2: Populate Visualization Metadata**
- Extract chart type, data shape from ResponseGenerator
- Store in `last_visualizations`
- Enable "Show me that chart again" follow-ups

**Task 3.3: Implement File Reference History**
- Add `previous_referenced_files` array (rotate last 3 turns)
- Enable "Show me the file from the first question"
- Validate file existence on retrieval

**Task 3.4: Add Context Archival/Pruning**
- Monitor context_snapshot size
- Archive after N turns
- Compress archived context

---

## PHASE 4: ACTION LINK RENDERING

### Current Architecture
- **Config:** `entity_actions`, `generic_actions` in config/ai.php
- **Discovery:** EntityAutoDiscovery resolves action closures
- **Query Phase:** EntityActionAwarenessSection teaches AI about actions
- **Response Phase:** ResponseEntityActionsSection formats action links
- **Processing:** ResponseActionLinkProcessor extracts action links
- **Rendering:** MessagesQuery.processActionLinks() → `<span>` with data attrs

### Link Formats
- Entity: `[text](entity://EntityType/id/action_key)`
- Generic: `[text](action://action_key)`

### Gaps Identified

| ID | Gap | Priority | Impact |
|----|-----|----------|--------|
| ACT-001 | Frontend click handlers missing | CRITICAL | Links non-functional |
| ACT-002 | No error handling for missing resolvers | HIGH | Silent failures |
| ACT-003 | No action caching in Discovery | MEDIUM | Repeated config() calls |
| ACT-004 | No conditional action support | MEDIUM | Can't hide by permission |
| ACT-005 | ResponseActionLinkProcessor not integrated | MEDIUM | Missing metadata enrichment |
| ACT-006 | No frontend tests | LOW | Integration untested |

### Recommended Improvements

**Task 4.1: Implement Frontend Click Handlers (CRITICAL)**
```javascript
// resources/js/action-link-handler.js
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('action-link')) {
        const actionType = e.target.dataset.actionType;
        if (actionType === 'entity') {
            // Trigger Kompo selfGet for entityActionLink()
        } else if (actionType === 'generic') {
            // Trigger Kompo selfGet for genericActionLink()
        }
    }
});
```

**Task 4.2: Add Error Handling for Missing Resolvers**
- Validate action exists before generating link
- Show user-friendly error if action not configured
- Log missing action for debugging

**Task 4.3: Integrate ResponseActionLinkProcessor**
- Call in ResponseGenerator after LLM response
- Add `action_links`, `has_action_links` to response metadata
- Enable UI to show action count/preview

**Task 4.4: Add Action Visibility Control**
- Allow action closures to return null (hidden)
- Add permission check callback option
- Support state-based visibility

---

## PHASE 5: TABLE/DATA RENDERING

### Current Architecture
- **Generator:** ResponseGenerator with modular sections
- **Sections:** ResultsDataSection (JSON), StatisticsSection (basic)
- **UI:** MessagesQuery with renderTableData(), renderListData(), renderMetricData()
- **Markdown:** SafeMarkdownRenderer with XSS prevention

### Gaps Identified

| ID | Gap | Priority | Impact |
|----|-----|----------|--------|
| TBL-001 | dd($prompt) debug statement | CRITICAL | Breaks generation |
| TBL-002 | Hardcoded table limit (10 rows) | HIGH | Not configurable |
| TBL-003 | Hardcoded list limit (5 items) | HIGH | Not configurable |
| TBL-004 | No pagination UI | HIGH | Large tables truncated silently |
| TBL-005 | ResultsDataSection uses JSON | MEDIUM | Not human-readable for LLM |
| TBL-006 | No column statistics | MEDIUM | Missing avg/min/max |
| TBL-007 | No table sorting | LOW | Tables not interactive |
| TBL-008 | No search in tables | LOW | Large tables not searchable |
| TBL-009 | No markdown table support | LOW | Only HTML tables |

### Recommended Improvements

**Task 5.1: Remove Debug Statement (CRITICAL)**
- Delete `dd($prompt);` at ResponseGenerator.php:317

**Task 5.2: Make Table/List Limits Configurable**
- Add to config: `max_table_rows`, `max_list_items`
- Pass to MessagesQuery via options
- Show "View all X items" link

**Task 5.3: Add Pagination to Tables**
- Implement client-side pagination
- Show page controls: prev/next, page numbers
- Store page state in component

**Task 5.4: Add Column Statistics**
- Detect numeric columns
- Calculate min, max, avg, sum
- Show in StatisticsSection output

**Task 5.5: Improve ResultsDataSection Format**
- Convert JSON to markdown table for LLM
- Show column headers and types
- Truncate long values with ellipsis

---

## PHASE 6: CROSS-CUTTING CONCERNS

### Identified Issues

| ID | Issue | Affected Systems | Priority |
|----|-------|-----------------|----------|
| XC-001 | No transaction management | Context, Messages | HIGH |
| XC-002 | Silent failures in context | Context, Files | MEDIUM |
| XC-003 | No concurrent update tests | All | MEDIUM |
| XC-004 | File reference lifecycle | Files, Context | MEDIUM |

### Recommended Improvements

**Task 6.1: Add Transaction Wrappers**
- Wrap processQuestion → recordResponse in DB transaction
- Ensure message storage + context update atomic
- Rollback on failure

**Task 6.2: Add Error Logging for Silent Failures**
- Log reference resolution failures
- Log file access errors
- Enable debugging without breaking UX

---

## Implementation Priority Matrix

| Priority | Tasks | Estimated Complexity |
|----------|-------|---------------------|
| P0 (Critical) | CRIT-001, CRIT-002, TBL-001 | Low |
| P1 (High) | ACT-001, FILE-001, FILE-002, CTX-001, TBL-002, TBL-003, TBL-004 | Medium |
| P2 (Medium) | CTX-002, CTX-003, CTX-004, ACT-002, ACT-003, TBL-005, TBL-006 | Medium-High |
| P3 (Low) | FILE-005, FILE-006, CTX-006, ACT-006, TBL-007, TBL-008, TBL-009 | Variable |

---

## Recommended Execution Order

### Sprint 1: Critical Fixes (Day 1)
1. Remove dd($prompt) debug statement
2. Implement frontend action link click handlers
3. Basic testing to verify fixes

### Sprint 2: High-Priority Gaps (Days 2-3)
1. Make table/list limits configurable
2. Add basic pagination to tables
3. Add error handling for missing action resolvers
4. Physical file path validation

### Sprint 3: Context Improvements (Days 4-5)
1. Persist entity actions in context
2. Populate visualization metadata
3. File reference history (rotate 3 turns)

### Sprint 4: Enhancements (Days 6-7)
1. Column statistics
2. Improve ResultsDataSection format
3. Action visibility control
4. Transaction wrappers

---

## Files to Modify

### Critical (P0)
- `src/Services/ResponseGenerator.php` - Remove dd()
- `resources/js/action-link-handler.js` - NEW FILE

### High Priority (P1)
- `src/Kompo/MessagesQuery.php` - Configurable limits, pagination
- `src/Services/Response/ResponseActionLinkProcessor.php` - Error handling
- `src/Services/Context/FileContextProvider.php` - Path validation
- `config/ai.php` - New config options

### Medium Priority (P2)
- `src/Services/Context/ConversationContextManager.php` - Action persistence
- `src/Services/ResponseGenerator.php` - Visualization metadata
- `src/Services/ResponseSections/ResultsDataSection.php` - Better format
- `src/Services/ResponseSections/StatisticsSection.php` - Column stats

---

## Success Criteria

1. **Action links work end-to-end** - Click handler triggers Kompo method
2. **Tables paginate** - Large result sets navigable
3. **Context persists actions** - "What actions?" follow-up works
4. **Files secure** - No path traversal possible
5. **No debug statements** - Clean production code
6. **Configurable limits** - Apps can customize display
