# Documentation Audit & Update - Master Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Comprehensively audit and update the `resources/docs/1.0` documentation to match the current codebase, creating accurate, developer-friendly documentation with proper diagrams and examples.

**Architecture:** Phase-locked execution with externalized cognition through structured artifacts. Documentation updates driven by code analysis, not assumptions.

**Tech Stack:** Laravel package documentation in Markdown, Mermaid diagrams, LaRecipe-compatible format.

---

## Phase Checklist

- [ ] **PHASE 0** - Master Plan Creation (NO CODE ANALYSIS)
  - [x] Create MASTER_PLAN.md
  - [x] Create MODULE_INDEX.md with hypothesized modules
  - [x] Create STATUS.md with tracking state
  - [ ] User confirmation to proceed

- [ ] **PHASE 1** - Raw Inventory (NO INTERPRETATION)
  - [ ] Complete file inventory of resources/docs/1.0/*
  - [ ] Complete file inventory of src/**/*.php
  - [ ] Complete file inventory of config/*.php
  - [ ] Record all Artisan commands
  - [ ] User confirmation to proceed

- [ ] **PHASE 2** - Module Discovery & Refinement
  - [ ] Map code files to documentation sections
  - [ ] Identify undocumented features
  - [ ] Identify outdated documentation
  - [ ] Refine MODULE_INDEX.md with boundaries
  - [ ] User confirmation to proceed

- [ ] **PHASE 3** - Micro-Plan Creation Per Module
  - [ ] Create plan for each documentation module
  - [ ] Define file-by-file reading plan
  - [ ] Create checklists for each module
  - [ ] User confirmation to proceed

- [ ] **PHASE 4** - Agent Dispatch & Module Execution
  - [ ] Execute module-by-module documentation updates
  - [ ] Update FINDINGS.md per module
  - [ ] Update DOC_UPDATES.md per module
  - [ ] User confirmation after each module

- [ ] **PHASE 5** - Documentation Production
  - [ ] Produce/update ARCHITECTURE_GLOBAL.md
  - [ ] Produce/update QUICK_START.md
  - [ ] Produce/update EXTENSION_GUIDE.md
  - [ ] Produce/update INTERNAL_ARCHITECTURE.md
  - [ ] Create Mermaid diagrams
  - [ ] User confirmation to proceed

- [ ] **PHASE 6** - Consolidation & Cleanup Plan
  - [ ] Prioritized improvement roadmap
  - [ ] Documentation gaps report
  - [ ] Minimum safe changeset

- [ ] **PHASE 7** - Final Synthesis & Resumability
  - [ ] STATUS.md indicates completion
  - [ ] All module folders complete
  - [ ] MODULE_INDEX.md finalized

---

## Documentation Modules (Chat-Centric Anchor)

All documentation flows from the **Chat Interface** as the primary entry point:

```
User -> Chat UI -> AiChatService -> Pipeline -> External Effects
                     |
        +-----------+|-----------+
        |            |           |
   Query Path   File Path   Entity Actions
        |            |           |
   Neo4j/Qdrant  Physical   Response
                 /DB Files  Enrichment
```

### Primary Documentation Sections

1. **Foundations** - Installation, requirements, infrastructure
2. **Chat UI** - Components, pipeline, context management
3. **Configuration** - All config options (ai.php, entities.php)
4. **Usage Guide** - Facade, services, ingestion, file search
5. **Advanced Topics** - Semantic matching, context selection, scopes
6. **Extending** - Custom providers, sections, extractors
7. **Reference** - Commands, facades, interfaces
8. **Internals** - Architecture, flows, storage, resilience

---

## Key Findings (Pre-Analysis)

Based on initial exploration:

### Features Potentially Underdocumented
1. **Entity Actions** - entity_actions and generic_actions config
2. **File Context System** - file_context configuration with physical/DB files
3. **UI Theming** - ChatThemeInterface, theme factories, user themes
4. **Conversation Context** - ConversationContextManager and its modules
5. **Response Enrichers** - ResponseFileEnricher, ResponseEntityEnricher
6. **Content Link Processing** - ActionLinkHandler, FileCitationHandler
7. **Security Services** - InputSanitizer, QueryResultFilter
8. **Chat Settings** - ChatSettingsInterface, UserChatSettings
9. **Conversation Export** - ExportConversationMdService
10. **DiagnoseCommand** and **ValidateConfigCommand** - newer commands

### Documentation That May Need Updates
1. **commands.md** - References commands that may not exist
2. **architecture.md** - Missing many new services from ServiceProvider
3. **chat-ui.md** - May not cover all theming/settings options
4. **configuration.md** - Missing newer config sections

---

## Success Criteria

1. **Completeness**: Every public API, config option, and command is documented
2. **Accuracy**: Documentation matches actual code behavior
3. **Discoverability**: Clear navigation, proper cross-references
4. **Diagrams**: Mermaid diagrams for all major flows
5. **Examples**: Working code examples for all features
6. **Plug-and-Play**: Quick start works in < 5 minutes
7. **Advanced Options**: All customization points documented
