# Documentation Consolidation Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Consolidate documentation from `docs/` into `resources/docs/1.0/`, update with Phase 4 audit corrections, and remove redundant files.

**Architecture:** The canonical documentation lives in `resources/docs/1.0/` following Laravel package versioned docs standard (compatible with LaRecipe). The `docs/` folder will only retain `docs/plans/` for implementation plans. All content from `docs/*.md` will be migrated to appropriate locations in `resources/docs/1.0/`.

**Tech Stack:** Markdown files, LaRecipe doc structure

---

## Pre-requisites

- Phase 4 audit complete with `analysis/CROSS_MODULE_FINDINGS.md` available
- All 23 module FINDINGS.md files contain corrections to make

---

## Task 1: Inventory and Map Documentation

**Files:**
- Read: `docs/architecture.md`
- Read: `docs/quick-start.md`
- Read: `docs/diagrams.md`
- Read: `resources/docs/1.0/index.md`
- Create: `analysis/DOCS_INVENTORY.md`

**Step 1: Create documentation inventory**

Create `analysis/DOCS_INVENTORY.md` with the following structure:

```markdown
# Documentation Inventory

## Source: docs/ (to be consolidated)

| File | Lines | Content Summary | Target in resources/docs/1.0/ |
|------|-------|-----------------|-------------------------------|
| architecture.md | 807 | 6-phase flow, ASCII diagrams, code structure | internals/architecture.md |
| quick-start.md | 143 | Installation, basic usage | usage/quick-start.md |
| diagrams.md | 460 | ASCII diagrams for all phases | internals/data-flows.md |

## Source: resources/docs/1.0/ (canonical - keep)

| Directory | Files | Status |
|-----------|-------|--------|
| foundations/ | 6 | Keep - update with audit findings |
| chat/ | 4 | Keep - update with audit findings |
| configuration/ | 4 | Keep - update with audit findings |
| usage/ | 10 | Keep - update with audit findings |
| advanced/ | 5 | Keep - update with audit findings |
| extending/ | 4 | Keep - update with audit findings |
| reference/ | 3 | Keep - update with audit findings |
| internals/ | 5 | Keep - merge docs/ content here |

## Content Migration Map

| docs/ Section | Target Location | Action |
|---------------|-----------------|--------|
| Phase 1-6 flow diagrams | internals/data-flows.md | Merge (better ASCII art) |
| Code structure map | internals/components.md | Merge |
| Developer workflow | usage/quick-start.md | Compare & merge |
| Config resolution diagram | foundations/configuration.md | Merge |
```

**Step 2: Verify the inventory is complete**

Run: `dir docs/*.md` and `dir resources/docs/1.0/ /s /b`
Expected: All files accounted for in inventory

**Step 3: Commit**

```bash
git add analysis/DOCS_INVENTORY.md
git commit -m "docs: create documentation inventory for consolidation"
```

---

## Task 2: Merge ASCII Diagrams into internals/data-flows.md

**Files:**
- Read: `docs/architecture.md` (lines 23-57, 67-129, 225-267, etc.)
- Read: `docs/diagrams.md` (entire file)
- Modify: `resources/docs/1.0/internals/data-flows.md`

**Step 1: Read current data-flows.md**

Read the existing content to understand current structure.

**Step 2: Merge the better ASCII diagrams**

The `docs/architecture.md` and `docs/diagrams.md` have comprehensive ASCII diagrams for:
- Complete 6-phase flow
- Configuration resolution (4-layer)
- Ingestion flow (Neo4j + Qdrant)
- Scope discovery (simple + complex)
- Context retrieval (entity detection, semantic search, access control)
- Prompt building → Query generation → Response

Merge these into `resources/docs/1.0/internals/data-flows.md`, replacing any inferior diagrams.

**Step 3: Verify the merge**

Read the updated file and confirm all major diagrams are present.

**Step 4: Commit**

```bash
git add resources/docs/1.0/internals/data-flows.md
git commit -m "docs: merge ASCII diagrams from docs/ into internals/data-flows.md"
```

---

## Task 3: Update Priority Values Based on Audit Findings

**Files:**
- Read: `analysis/modules/05-query-generation/FINDINGS.md`
- Read: `analysis/modules/07-response-generation/FINDINGS.md`
- Modify: `resources/docs/1.0/chat/module-pipeline.md`

**Step 1: Read audit findings for priority corrections**

From QG-001: SemanticPromptBuilder docblock shows outdated priorities
From RSP-001: PrivacyAndSecurityGuidelinesSection priority mismatch (docstring says 15, actual is 1000)

**Step 2: Update module-pipeline.md with correct priorities**

Current incorrect table in module-pipeline.md (lines 59-73):
```markdown
| Priority | Name | Description |
|----------|------|-------------|
| 10 | project_context | ... |
| 95 | current_user | ... |  <-- WRONG: actual is 17
| 100 | question | ... |       <-- WRONG: actual is 80
| 110 | task_instructions | ... | <-- WRONG: actual is 90
```

Update to correct values based on actual code:
```markdown
| Priority | Name | Description |
|----------|------|-------------|
| 10 | project_context | Project name, description, business rules |
| 15 | generic_context | Current date/time |
| 17 | current_user | Current user and team context |
| 20 | schema | Database schema and node types |
| 30 | relationships | Relationship types between nodes |
| 40 | example_entities | Sample data for reference |
| 45 | file_context | Relevant file content |
| 50 | similar_queries | Previously successful similar queries |
| 55 | conversation_context | Conversation history |
| 60 | detected_entities | Entities detected in the question |
| 65 | detected_scopes | Scopes/filters detected in the question |
| 70 | pattern_library | Query patterns from the pattern library |
| 75 | query_rules | Rules for query generation |
| 80 | question | The user's actual question |
| 90 | task_instructions | Final instructions for the LLM |
```

Also update ResponseGenerator section (lines 137-148) with correct priorities:
```markdown
| Priority | Name | Description |
|----------|------|-------------|
| 10 | system | System prompt setting the LLM's role |
| 20 | project_context | Project name and description |
| 30 | question | The user's original question |
| 40 | query_info | The Cypher query that was executed |
| 45 | file_context | Relevant file content with citations |
| 50 | data | The actual results data |
| 60 | statistics | Statistics about the results |
| 70 | guidelines | Guidelines for response formatting |
| 80 | task | Final task instructions for the LLM |
| 1000 | privacy_security | Privacy and security guidelines |
```

**Step 3: Verify the update**

Read the file and confirm priorities match actual code.

**Step 4: Commit**

```bash
git add resources/docs/1.0/chat/module-pipeline.md
git commit -m "docs: fix section priority values to match actual code"
```

---

## Task 4: Update extending/prompt-sections.md with Correct Priorities

**Files:**
- Read: `analysis/modules/05-query-generation/FINDINGS.md`
- Modify: `resources/docs/1.0/extending/prompt-sections.md`

**Step 1: Read current priority table**

The file has a priority table (around line 269-281) that may be incorrect.

**Step 2: Update priority table**

Current table shows:
```markdown
| 100 | System | AI role and capabilities |
| 80 | Schema | Database structure |
```

This uses "higher = earlier" which is OPPOSITE of the actual implementation (lower = earlier).

Update to clarify and use correct values:
```markdown
## Section Priority

Sections are ordered by priority (lower = earlier in prompt):

| Priority | Section | Purpose |
|----------|---------|---------|
| 10 | project_context | Project context and business rules |
| 15 | generic_context | Date/time context |
| 17 | current_user | User and team context |
| 20 | schema | Database structure |
| 30 | relationships | Entity relationships |
| ... | ... | ... |
| 80 | question | User's question |
| 90 | task_instructions | Final LLM instructions |
```

**Step 3: Commit**

```bash
git add resources/docs/1.0/extending/prompt-sections.md
git commit -m "docs: fix priority ordering explanation (lower = earlier)"
```

---

## Task 5: Add Security Notes Based on Audit Findings

**Files:**
- Read: `analysis/modules/17-security/FINDINGS.md`
- Read: `analysis/CROSS_MODULE_FINDINGS.md`
- Modify: `resources/docs/1.0/internals/resilience.md`

**Step 1: Read current resilience.md**

Check what security content exists.

**Step 2: Add section about CypherSanitizer**

Add a section documenting the authoritative CypherSanitizer location:

```markdown
## Cypher Injection Prevention

The package uses `Condoedge\Ai\GraphStore\CypherSanitizer` for all Cypher injection prevention.

### Key Features

- **Fail-fast validation**: Invalid input throws `CypherInjectionException`
- **Reserved keyword blocking**: Blocks MATCH, DELETE, CREATE, DROP, etc. as labels
- **Length limits**: 255 character maximum (DoS protection)
- **Backtick quoting**: Defense-in-depth for all identifiers
- **Unicode/null byte protection**: Blocks encoding-based attacks

### Usage

```php
use Condoedge\Ai\GraphStore\CypherSanitizer;

// Validate label (throws on invalid)
$safeLabel = CypherSanitizer::escapeLabel($userInput);

// Validate property key
$safeKey = CypherSanitizer::validatePropertyKey($field);

// Escape string value for queries
$safeValue = CypherSanitizer::escapeValue($value);
```

### Important

Always use parameterized queries for values. CypherSanitizer is for structural elements (labels, property keys, relationship types) that cannot be parameterized.
```

**Step 3: Commit**

```bash
git add resources/docs/1.0/internals/resilience.md
git commit -m "docs: add CypherSanitizer security documentation"
```

---

## Task 6: Update Architecture Diagram in internals/architecture.md

**Files:**
- Read: `docs/architecture.md` (code structure section, lines 614-674)
- Modify: `resources/docs/1.0/internals/architecture.md`

**Step 1: Read both files**

Compare the code structure maps.

**Step 2: Merge the detailed code structure map**

The `docs/architecture.md` has a more detailed file tree (lines 616-673) showing:
- Domain layer (Contracts, Traits, ValueObjects)
- Services layer (Discovery, Security, Ingestion, Context, Query, Response)
- Stores layer (Neo4j, Qdrant)
- Console Commands

Ensure this is present in `resources/docs/1.0/internals/architecture.md`.

**Step 3: Commit**

```bash
git add resources/docs/1.0/internals/architecture.md
git commit -m "docs: merge detailed code structure map into architecture.md"
```

---

## Task 7: Verify quick-start.md Content

**Files:**
- Read: `docs/quick-start.md`
- Read: `resources/docs/1.0/usage/quick-start.md`

**Step 1: Compare both files**

Check if `resources/docs/1.0/usage/quick-start.md` has all content from `docs/quick-start.md`.

**Step 2: Merge any missing content**

If the versioned doc is missing anything, add it.

**Step 3: Commit if changes made**

```bash
git add resources/docs/1.0/usage/quick-start.md
git commit -m "docs: ensure quick-start.md has complete content"
```

---

## Task 8: Delete Redundant docs/ Files

**Files:**
- Delete: `docs/architecture.md`
- Delete: `docs/quick-start.md`
- Delete: `docs/diagrams.md`
- Keep: `docs/plans/` directory

**Step 1: Verify all content has been migrated**

Review the commits from Tasks 2-7 to confirm all unique content is now in `resources/docs/1.0/`.

**Step 2: Delete redundant files**

```bash
del docs\architecture.md
del docs\quick-start.md
del docs\diagrams.md
```

**Step 3: Verify docs/plans/ still exists**

```bash
dir docs\plans\
```

Expected: Plan files still present

**Step 4: Commit**

```bash
git add -A
git commit -m "docs: remove redundant docs/ files after consolidation

Content migrated to resources/docs/1.0/:
- ASCII diagrams → internals/data-flows.md
- Code structure → internals/architecture.md
- Quick start → usage/quick-start.md

docs/plans/ retained for implementation plans."
```

---

## Task 9: Update Analysis STATUS.md

**Files:**
- Modify: `analysis/STATUS.md`

**Step 1: Update phase status**

Update STATUS.md to reflect Phase 5 completion:

```markdown
## Current State

| Field | Value |
|-------|-------|
| **Active Phase** | 5 - Documentation |
| **Phase Status** | COMPLETE |
| **Last Completed Step** | Documentation consolidated |
| **Next Step** | User confirmation to proceed to Phase 6 |

---

## Phase Progress

| Phase | Status | Notes |
|-------|--------|-------|
| 0 - Master Plan | COMPLETE | All artifacts created |
| 1 - Raw Inventory | COMPLETE | 274 PHP files, 73 other files inventoried |
| 2 - Module Discovery | COMPLETE | 23 modules defined with verified boundaries |
| 3 - Micro-Plan Creation | COMPLETE | 92 files created (4 per module x 23 modules) |
| 4 - Agent Dispatch | COMPLETE | All 23 modules analyzed, cross-module findings merged |
| 5 - Documentation | COMPLETE | Consolidated docs/, updated with audit findings |
| 6 - Consolidation | NOT STARTED | Awaiting user confirmation |
| 7 - Final Synthesis | NOT STARTED | - |
```

**Step 2: Add session log entry**

Add to session log:
```markdown
| 2026-01-03 | Phase 5: Documentation consolidated | SUCCESS |
```

**Step 3: Commit**

```bash
git add analysis/STATUS.md
git commit -m "docs: update STATUS.md for Phase 5 completion"
```

---

## Task 10: Update MASTER_PLAN.md

**Files:**
- Modify: `analysis/MASTER_PLAN.md`

**Step 1: Mark Phase 5 items complete**

Update the Phase 5 checklist:

```markdown
### PHASE 5 - Documentation
- [x] Create/update ARCHITECTURE_GLOBAL.md (merged into internals/architecture.md)
- [x] Create/update QUICK_START.md (verified in usage/quick-start.md)
- [x] Create/update EXTENSION_GUIDE.md (exists in extending/)
- [x] Create/update INTERNAL_ARCHITECTURE.md (merged into internals/)
- [x] Include diagrams (Mermaid format) (ASCII diagrams merged)
- [x] Update STATUS.md
- [ ] User confirmation to proceed
```

**Step 2: Commit**

```bash
git add analysis/MASTER_PLAN.md
git commit -m "docs: mark Phase 5 complete in MASTER_PLAN.md"
```

---

## Summary

After completing all tasks:

1. **Consolidated**: All content from `docs/` migrated to `resources/docs/1.0/`
2. **Corrected**: Priority values updated based on Phase 4 audit
3. **Enhanced**: Security documentation added for CypherSanitizer
4. **Cleaned**: Redundant `docs/*.md` files removed
5. **Retained**: `docs/plans/` for implementation plans
6. **Updated**: STATUS.md and MASTER_PLAN.md reflect Phase 5 completion

Total commits: 10
Files deleted: 3
Files modified: 7+
Files created: 1 (DOCS_INVENTORY.md)
