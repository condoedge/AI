# Module Index

This document defines the documentation modules, their responsibilities, boundaries, and dependencies.

---

## Module Overview

| Module | Responsibility | Primary Code Location | Doc Location |
|--------|----------------|----------------------|--------------|
| foundations | Installation, requirements, setup | AiServiceProvider, config/* | resources/docs/1.0/foundations/ |
| chat-ui | Chat components and pipeline | src/Kompo/*, src/Services/Chat/* | resources/docs/1.0/chat/ |
| configuration | All config options | config/ai.php, config/entities.php | resources/docs/1.0/configuration/ |
| usage | Core API usage | src/Facades/*, src/Services/AiManager.php | resources/docs/1.0/usage/ |
| advanced | Advanced features | src/Services/Semantic*, src/Services/Scope* | resources/docs/1.0/advanced/ |
| extending | Extension points | src/Contracts/*, src/Services/PromptSections/* | resources/docs/1.0/extending/ |
| reference | Commands, facades, interfaces | src/Console/Commands/*, src/Facades/* | resources/docs/1.0/reference/ |
| internals | Architecture, flows | All src/*, especially Services/ | resources/docs/1.0/internals/ |

---

## Module Definitions

### 1. foundations

**Responsibility:**
- Guide users through installation
- Document system requirements (PHP, Laravel, extensions)
- Explain infrastructure setup (Neo4j, Qdrant)
- Provide troubleshooting guidance

**Key Files:**
- AiServiceProvider.php (boot, register)
- config/ai.php (all sections)
- database/migrations/*

---

### 2. chat-ui

**Responsibility:**
- Document chat components (AiChatModal, AiChatFloating)
- Explain chat pipeline and message flow
- Cover conversation context management
- Document file context system
- Document theming and customization

**Key Files:**
- src/Kompo/AiChatModal.php
- src/Kompo/AiChatFloating.php
- src/Services/Chat/AiChatService.php
- src/Services/Context/ConversationContextManager.php
- src/Services/UI/* (themes)
- src/Services/Settings/* (user settings)

---

### 3. configuration

**Responsibility:**
- Document all config/ai.php sections (30+ sections)
- Document config/entities.php structure
- Explain environment variables
- Cover configuration priority

**Key Config Sections:**
project, discovery, auto_sync, access_control, sync_triggers, model_namespaces, graph, vector, llm, embedding, query_generation, query_execution, rate_limits, response_generation, rag, relationship_weights, semantic_matching, semantic_context, scope_matching, file_processing, file_context, chat, ui, query_patterns, entities, entity_id_fields, entity_actions, generic_actions, query_generator_sections, response_generator_sections

---

### 4. usage

**Responsibility:**
- Document AI facade methods
- Document direct service usage
- Explain data ingestion flow
- Cover file search capabilities

**Key Files:**
- src/Facades/AI.php
- src/Facades/FileSearch.php
- src/Services/AiManager.php
- src/Services/DataIngestionService.php
- src/Services/FileSearchService.php

---

### 5. advanced

**Responsibility:**
- Document semantic matching system
- Explain context selection optimization
- Cover scope system and business logic
- Document query patterns
- Explain auto-discovery system

**Key Files:**
- src/Services/SemanticMatcher.php
- src/Services/SemanticContextSelector.php
- src/Services/ScopeSemanticMatcher.php
- src/Services/PatternLibrary.php
- src/Services/Discovery/*

---

### 6. extending

**Responsibility:**
- Document extension interfaces
- Explain custom LLM/embedding providers
- Document custom prompt/response sections
- Explain custom file extractors

**Key Files:**
- src/Contracts/*.php
- src/Services/PromptSections/BasePromptSection.php
- src/Services/ResponseSections/BaseResponseSection.php
- src/Services/Extractors/*.php

---

### 7. reference

**Responsibility:**
- Complete Artisan command reference
- Facade API documentation
- Interface documentation

**Commands (from code):**
1. ai:diagnose
2. ai:discover
3. ai:ingest
4. ai:sync-relationships
5. ai:process-files
6. ai:index-semantic
7. ai:index-scopes
8. ai:index-context
9. ai:validate-config

---

### 8. internals

**Responsibility:**
- Document system architecture
- Explain core components
- Detail data and control flows
- Cover resilience and security

**Key Files:**
- src/AiServiceProvider.php
- src/Services/QueryGenerator.php
- src/Services/QueryExecutor.php
- src/Services/ResponseGenerator.php
- src/Services/Resilience/*.php
- src/Services/Security/*.php

---

## PHASE 2: Documentation Gap Analysis

### Commands Documentation (reference/commands.md)

**DELETE (fictional):** ai:clear, ai:status, ai:test, ai:query, ai:config, ai:publish, ai:ingest-eager

**ADD (undocumented):** ai:diagnose, ai:config:validate

**UPDATE:** ai:ingest missing --docs option

### Facades Documentation (reference/facades.md)

**Rename in docs:**
- query() -> executeQuery()
- bulkIngest() -> ingestBatch()
- getContext() -> retrieveContext()

**Delete (don't exist):** search(), searchFiles(), neo4j(), qdrant(), llm(), getConfig(), isEnabled(), status()

**Add (37 undocumented methods):** sync, searchSimilar, getExampleEntities, storeQuery, embedBatch, getEmbeddingDimensions, getEmbeddingModel, chatJson, complete, stream, getLlmModel, getLlmProvider, getLlmMaxTokens, countTokens, validateQuery, sanitizeQuery, getQueryTemplates, detectQueryTemplate, askQuestion, executeCount, executePaginated, explainQuery, testQuery, ask, extractInsights, suggestVisualizations, answerQuestion

### Prompt/Response Sections (extending/prompt-sections.md)

**Current coverage:** 5 sections documented
**Actual sections:** 17 prompt + 12 response = 29 total
**Gap:** 24 sections undocumented

### Undocumented Features (Priority Order)

| Priority | Feature | Config/Code | Target Doc |
|----------|---------|-------------|------------|
| HIGH | Entity Actions | entity_actions, ActionLinkHandler | NEW: configuration/entity-actions.md |
| HIGH | Generic Actions | generic_actions, ActionLinkHandler | NEW: configuration/entity-actions.md |
| HIGH | Chat Theming | ui section, Services/UI/* | NEW: chat/theming.md |
| HIGH | Facades API (37 methods) | AI.php, AiManager.php | UPDATE: reference/facades.md |
| HIGH | Commands (fictional removal) | Commands/*.php | UPDATE: reference/commands.md |
| MEDIUM | User Chat Settings | ChatSettingsInterface | NEW: chat/settings.md |
| MEDIUM | Conversation Export | Exporter/* | NEW: chat/conversation-export.md |
| MEDIUM | Response Enrichers | ResponseFileEnricher, EntityEnricher | UPDATE: internals/components.md |
| MEDIUM | Content Link Processors | ActionLinkHandler, FileCitationHandler | UPDATE: internals/components.md |
| MEDIUM | Prompt Sections (17) | PromptSections/* | UPDATE: extending/prompt-sections.md |
| MEDIUM | Response Sections (12) | ResponseSections/* | UPDATE: extending/prompt-sections.md |
| LOW | Security Services | Security/* | NEW: advanced/security.md |
| LOW | Resilience Services | Resilience/* | UPDATE: internals/resilience.md |

### Config Sections Undocumented

| Section | Description | Target Doc |
|---------|-------------|------------|
| access_control | Access level configuration | configuration.md |
| sync_triggers | Related model sync | configuration.md |
| model_namespaces | Model namespace resolution | configuration.md |
| rate_limits | Rate limiting config | configuration.md |
| relationship_weights | Relationship importance | configuration.md |
| semantic_context | Context selection | configuration.md |
| scope_matching | Scope semantic matching | configuration.md |
| file_context | File context config | configuration.md |
| ui | UI theming config | configuration.md |
| entity_id_fields | ID field resolution | configuration.md |
| entity_actions | Entity action definitions | NEW: entity-actions.md |
| generic_actions | Generic action definitions | NEW: entity-actions.md |
| query_generator_sections | Prompt section pipeline | configuration.md |
| response_generator_sections | Response section pipeline | configuration.md |

---

## Documentation Work Estimate

| Task | Files Affected | Effort |
|------|----------------|--------|
| Fix commands.md | 1 file | Small |
| Fix facades.md | 1 file | Medium |
| Create entity-actions.md | 1 new file | Medium |
| Create theming.md | 1 new file | Small |
| Create settings.md | 1 new file | Small |
| Create conversation-export.md | 1 new file | Small |
| Update prompt-sections.md | 1 file | Large |
| Update configuration.md | 1 file | Large |
| Update architecture.md | 1 file | Medium |
| Create security.md | 1 new file | Medium |
| Update resilience.md | 1 file | Small |
| Update components.md | 1 file | Medium |

**Total: 12 documentation tasks**
