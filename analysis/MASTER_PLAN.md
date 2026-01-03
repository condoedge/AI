# MASTER PLAN - Kompo AI Chat System Audit

> **Orchestrator:** This plan externalizes cognition to defeat large-context limitations.
> **Anchor:** CHAT-CENTRIC - All analysis flows from and to the chat interface.

---

## Phase Checklist

### PHASE 0 - Master Plan Creation
- [x] Create /analysis directory structure
- [x] Create MASTER_PLAN.md (this file)
- [x] Create MODULE_INDEX.md with hypothesized modules
- [x] Create STATUS.md with phase tracking
- [x] User confirmation to proceed

### PHASE 1 - Raw Inventory
- [x] Complete file/folder inventory of entire repo
- [x] Record inventory in MODULE_INDEX.md appendix
- [x] No interpretations, pure factual listing
- [x] Update STATUS.md
- [x] User confirmation to proceed

### PHASE 2 - Module Discovery & Refinement
- [x] Map files/folders to responsibilities
- [x] Identify boundary violations and mixed concerns
- [x] Split/merge modules as needed (20 → 23 modules)
- [x] Define for each module:
  - [x] Responsibility
  - [x] Non-responsibility
  - [x] Entry points
  - [x] External dependencies
  - [x] Key contracts
- [x] Update MODULE_INDEX.md with refined modules
- [x] Update STATUS.md
- [x] User confirmation to proceed

### PHASE 3 - Micro-Plan Creation
- [x] Create /analysis/modules/<slug>/ for each module (23 directories)
- [x] For each module create:
  - [x] PLAN.md (verbose, exhaustive)
  - [x] CHECKLIST.md (binary items)
  - [x] FINDINGS.md (empty initially)
  - [x] DOC_UPDATES.md (empty initially)
- [x] Update STATUS.md
- [x] User confirmation to proceed

### PHASE 4 - Agent Dispatch & Module Execution
- [x] Execute module-by-module analysis
- [x] For each module:
  - [x] Dispatch dedicated agent with strict scope
  - [x] Read every file in module
  - [x] Prove usage via reference tracing
  - [x] Detect dead code, duplicates, leakage
  - [x] Evaluate AI/chat correctness
  - [x] Update CHECKLIST.md, FINDINGS.md, DOC_UPDATES.md
- [x] Merge findings into cross-module coherence notes
- [x] Update STATUS.md after each module
- [ ] User confirmation to proceed to Phase 5

### PHASE 5 - Documentation
- [x] Create/update ARCHITECTURE_GLOBAL.md (merged into internals/architecture.md)
- [x] Create/update QUICK_START.md (verified in usage/quick-start.md)
- [x] Create/update EXTENSION_GUIDE.md (exists in extending/)
- [x] Create/update INTERNAL_ARCHITECTURE.md (merged into internals/)
- [x] Include diagrams (Mermaid format) (ASCII diagrams merged)
- [x] Update STATUS.md
- [ ] User confirmation to proceed

### PHASE 6 - Consolidation: Cleanup & Improvement Plan
- [x] Document each issue with:
  - [x] Description
  - [x] Evidence
  - [x] Severity
  - [x] Root cause
  - [x] Fix proposal
  - [x] Impact analysis
  - [x] Migration steps
  - [x] Test plan
- [x] Produce prioritized roadmap (4 sprints defined)
- [x] Define minimum safe changeset (2 hours critical fixes)
- [x] Update STATUS.md
- [ ] User confirmation to proceed

### PHASE 7 - Final Synthesis & Resumability
- [ ] Verify STATUS.md shows completion state
- [ ] Verify MASTER_PLAN.md fully checked
- [ ] Verify MODULE_INDEX.md is final and consistent
- [ ] Verify all module folders complete
- [ ] Final user confirmation

---

## Chat-Centric Flow (Global Anchor)

```
User Input
    |
    v
+-------------------+
|   Kompo Chat UI   |  (AiChatPanel, ChatMessageForm, MessagesQuery)
+-------------------+
    |
    v
+-------------------+
|  Chat Service     |  (AiChatService, SendMessageService)
+-------------------+
    |
    +-------+-------+
    |               |
    v               v
+----------+   +-----------+
| Context  |   | Query     |
| Pipeline |   | Pipeline  |
+----------+   +-----------+
    |               |
    v               v
+-------------------+
| Response Generator|
+-------------------+
    |
    v
+-------------------+
|   LLM Provider    |  (Anthropic, OpenAI)
+-------------------+
    |
    v
Response to User
```

All modules must be understood relative to this flow.

---

## Non-Negotiable Rules

1. **No shallow summaries** - Every file must be read and recorded
2. **No assumed usage** - Prove usage with reference tracing
3. **No phase skipping** - User must confirm each phase
4. **No agent dispatch without micro-plan** - Plan first, execute second
5. **No closing without docs** - Documentation updates required
6. **Artifacts are truth** - If not written, it doesn't exist
