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
- [ ] For each module:
  - [ ] Dispatch dedicated agent with strict scope
  - [ ] Read every file in module
  - [ ] Prove usage via reference tracing
  - [ ] Detect dead code, duplicates, leakage
  - [ ] Evaluate AI/chat correctness
  - [ ] Update CHECKLIST.md, FINDINGS.md, DOC_UPDATES.md
- [ ] Merge findings into cross-module coherence notes
- [ ] Update STATUS.md after each module
- [ ] User confirmation between modules

### PHASE 5 - Documentation
- [ ] Create/update ARCHITECTURE_GLOBAL.md
- [ ] Create/update QUICK_START.md
- [ ] Create/update EXTENSION_GUIDE.md
- [ ] Create/update INTERNAL_ARCHITECTURE.md
- [ ] Include diagrams (Mermaid format)
- [ ] Update STATUS.md
- [ ] User confirmation to proceed

### PHASE 6 - Consolidation: Cleanup & Improvement Plan
- [ ] Document each issue with:
  - [ ] Description
  - [ ] Evidence
  - [ ] Severity
  - [ ] Root cause
  - [ ] Fix proposal
  - [ ] Impact analysis
  - [ ] Migration steps
  - [ ] Test plan
- [ ] Produce prioritized roadmap
- [ ] Define minimum safe changeset
- [ ] Update STATUS.md
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
