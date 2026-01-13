# Documentation Inventory

> Created as Task 1 of the documentation consolidation plan.
> Purpose: Map all documentation sources and their consolidation targets.

---

## Source: docs/ (to be consolidated)

| File | Lines | Content Summary | Target in resources/docs/1.0/ |
|------|-------|-----------------|-------------------------------|
| architecture.md | 807 | Complete 6-phase flow, ASCII diagrams for config resolution, ingestion, context retrieval, code structure map, developer guide | internals/architecture.md |
| quick-start.md | 143 | Installation, basic usage, model properties reference, commands reference, access control overview | usage/quick-start.md |
| diagrams.md | 460 | ASCII diagrams for all phases: configuration (4-layer), ingestion (Neo4j + Qdrant), scope discovery (simple + complex), context retrieval, prompt building, query generation, response | internals/data-flows.md |

### docs/ Files to Keep

| File | Reason |
|------|--------|
| docs/plans/*.md | Implementation plans directory - retained for ongoing development |

---

## Source: resources/docs/1.0/ (canonical - keep)

| Directory | Files | Status |
|-----------|-------|--------|
| foundations/ | 6 files (index, requirements, installing, infrastructure, configuration, troubleshooting) | Keep - update with audit findings |
| chat/ | 4 files (chat-ui, module-pipeline, conversation-context-management, file-context-system) | Keep - update with audit findings (priority corrections needed) |
| configuration/ | 4 files (entities, environment, response-styles, + basic) | Keep - update with audit findings |
| usage/ | 10 files (index, quick-start, simple-usage, advanced-usage, data-ingestion, file-search, context-retrieval, extending, embeddings, examples, laravel-integration, llm, testing) | Keep - verify against docs/quick-start.md |
| advanced/ | 5 files (semantic-matching, context-selection, scopes, patterns, auto-discovery) | Keep - update with audit findings |
| extending/ | 4 files (llm-providers, embedding-providers, prompt-sections, file-extractors) | Keep - priority ordering needs fix |
| reference/ | 3 files (commands, facades, interfaces) | Keep - update with audit findings |
| internals/ | 6 files (index, architecture, components, data-flows, storage-guide, resilience) | Keep - merge docs/ ASCII diagrams here |

### File Count Summary

| Location | Count |
|----------|-------|
| docs/*.md (to migrate) | 3 |
| docs/plans/*.md (to keep) | 2 |
| resources/docs/1.0/**/*.md | 44 |

---

## Content Migration Map

| docs/ Section | Target Location | Action |
|---------------|-----------------|--------|
| Complete 6-phase flow diagram (architecture.md:23-57) | internals/data-flows.md | Merge - better ASCII visualization |
| Phase 1 configuration resolution (architecture.md:67-129) | internals/data-flows.md | Merge - 4-layer config diagram |
| Phase 2 ingestion flow (architecture.md:225-267) | internals/data-flows.md | Merge - dual-store flow |
| Phase 3 context retrieval (architecture.md:357-439) | internals/data-flows.md | Merge - entity detection + access control |
| Phase 4 prompt building (architecture.md:453-513) | internals/data-flows.md | Merge - prompt assembly |
| Phase 5 query generation (architecture.md:517-556) | internals/data-flows.md | Merge - scope vs custom query |
| Phase 6 response (architecture.md:560-610) | internals/data-flows.md | Merge - execute + filter + format |
| Code structure map (architecture.md:614-674) | internals/components.md | Merge - detailed file tree |
| Developer workflow (architecture.md:680-708) | usage/quick-start.md | Compare & merge if missing |
| Resolution priority table (architecture.md:133-139) | foundations/configuration.md | Merge - clear priority order |
| Scope discovery deep dive (diagrams.md:154-195) | internals/data-flows.md | Merge - CypherQueryBuilderSpy flow |
| Access level table (architecture.md:441-449) | internals/resilience.md | Merge - security reference |

---

## Content Quality Comparison

### docs/architecture.md vs resources/docs/1.0/internals/architecture.md

| Aspect | docs/ Version | resources/ Version | Action |
|--------|---------------|-------------------|--------|
| Flow diagrams | ASCII (comprehensive) | Mermaid (simpler) | Merge ASCII into data-flows.md |
| Config resolution | 4-step visual | Text + ASCII | Keep both - complementary |
| Code structure | Detailed tree | Not present | Add to components.md |
| Examples | Inline PHP | Inline PHP | Compare and merge best |

### docs/diagrams.md vs resources/docs/1.0/internals/data-flows.md

| Aspect | docs/ Version | resources/ Version | Action |
|--------|---------------|-------------------|--------|
| Phase diagrams | All 6 phases | Mermaid for 4 flows | Merge ASCII diagrams |
| Scope discovery | Detailed nested flow | Not present | Add to data-flows.md |
| Access control | Visual user levels | Brief mention | Enhance with visual |

### docs/quick-start.md vs resources/docs/1.0/usage/quick-start.md

| Aspect | docs/ Version | resources/ Version | Action |
|--------|---------------|-------------------|--------|
| Installation | Basic composer | More detailed | Keep resources/ version |
| Model setup | Good examples | Similar examples | No action needed |
| Properties table | Present | Present | Verify identical |
| Commands | Basic list | Similar | No action needed |

---

## Audit Finding Integration Points

Based on Phase 4 audit findings that need documentation updates:

| Finding | Location | Update Needed |
|---------|----------|---------------|
| QG-001: Priority ordering | chat/module-pipeline.md | Fix SemanticPromptBuilder priorities |
| RSP-001: Privacy priority | chat/module-pipeline.md | PrivacyAndSecurityGuidelinesSection is priority 1000, not 15 |
| SEC-001: CypherSanitizer | internals/resilience.md | Document authoritative sanitization location |
| Priority convention | extending/prompt-sections.md | Clarify "lower = earlier" not "higher = earlier" |

---

## Migration Checklist

- [ ] Task 2: Merge ASCII diagrams into internals/data-flows.md
- [ ] Task 3: Update priority values in chat/module-pipeline.md
- [ ] Task 4: Update extending/prompt-sections.md with correct priorities
- [ ] Task 5: Add CypherSanitizer documentation to internals/resilience.md
- [ ] Task 6: Merge code structure map into internals/architecture.md
- [ ] Task 7: Verify quick-start.md content completeness
- [ ] Task 8: Delete redundant docs/ files (architecture.md, quick-start.md, diagrams.md)
- [ ] Task 9: Update analysis/STATUS.md for Phase 5 completion
- [ ] Task 10: Update analysis/MASTER_PLAN.md to mark Phase 5 complete

---

## Notes

1. **ASCII vs Mermaid**: The docs/ files use ASCII art which renders in any markdown viewer. The resources/ files use Mermaid which requires a compatible renderer (LaRecipe supports it). Consider keeping both formats where they complement each other.

2. **Versioning**: resources/docs/1.0/ uses `{{version}}` placeholders for cross-references, enabling version-specific documentation. All migrated content should use this convention.

3. **LaRecipe Structure**: The index.md in resources/docs/1.0/ defines the navigation structure. No changes needed to navigation for this consolidation - all target files already exist.

---

## PHASE 1 RAW INVENTORY (2026-01-13)

### Documentation Files (45 total)

```
resources/docs/1.0/
├── index.md
├── advanced/
│   ├── auto-discovery.md
│   ├── context-selection.md
│   ├── patterns.md
│   ├── scopes.md
│   └── semantic-matching.md
├── chat/
│   ├── chat-ui.md
│   ├── conversation-context-management.md
│   ├── file-context-system.md
│   └── module-pipeline.md
├── configuration/
│   ├── entities.md
│   ├── environment.md
│   └── response-styles.md
├── extending/
│   ├── embedding-providers.md
│   ├── file-extractors.md
│   ├── llm-providers.md
│   └── prompt-sections.md
├── foundations/
│   ├── configuration.md
│   ├── index.md
│   ├── infrastructure.md
│   ├── installing.md
│   ├── requirements.md
│   └── troubleshooting.md
├── internals/
│   ├── architecture.md
│   ├── components.md
│   ├── data-flows.md
│   ├── index.md
│   ├── resilience.md
│   └── storage-guide.md
├── reference/
│   ├── commands.md
│   ├── facades.md
│   └── interfaces.md
└── usage/
    ├── advanced-usage.md
    ├── context-retrieval.md
    ├── data-ingestion.md
    ├── embeddings.md
    ├── examples.md
    ├── extending.md
    ├── file-search.md
    ├── index.md
    ├── laravel-integration.md
    ├── llm.md
    ├── quick-start.md
    ├── simple-usage.md
    └── testing.md
```

### Source Files (191 PHP files)

**By Category:**

| Category | Count | Location |
|----------|-------|----------|
| Service Provider | 1 | src/AiServiceProvider.php |
| Console Commands | 9 | src/Console/Commands/*.php |
| Contracts (Interfaces) | 18 | src/Contracts/*.php |
| Domain Layer | 6 | src/Domain/**/*.php |
| DTOs | 2 | src/DTOs/*.php |
| Embedding Providers | 2 | src/EmbeddingProviders/*.php |
| Exceptions | 7 | src/Exceptions/*.php |
| Facades | 2 | src/Facades/*.php |
| Graph Store | 5 | src/GraphStore/*.php |
| HTTP Controllers | 2 | src/Http/Controllers/*.php |
| Jobs | 4 | src/Jobs/*.php |
| Kompo UI Components | 15 | src/Kompo/**/*.php |
| LLM Providers | 2 | src/LlmProviders/*.php |
| Models | 7 | src/Models/**/*.php |
| Observers | 1 | src/Observers/*.php |
| Policies | 1 | src/Policies/*.php |
| Services | 98 | src/Services/**/*.php |
| Vector Store | 1 | src/VectorStore/*.php |

**Services Breakdown:**

| Service Category | Files |
|-----------------|-------|
| Core Services | 10 (AiManager, ContextRetriever, DataIngestion, FileProcessor, etc.) |
| Chat Services | 5 (AiChatService, SendMessage, Regenerate, Exporters) |
| Context Services | 6 (ConversationContext, EntityExtractor, FileContext, etc.) |
| Discovery Services | 11 (EntityAutoDiscovery, Schema, Properties, Relationships, etc.) |
| Extractors | 4 (Text, Markdown, PDF, Docx) |
| Prompt Sections | 17 (Schema, Query, Entities, Scopes, FileContext, etc.) |
| Response Sections | 12 (Results, Guidelines, Privacy, EntityActions, etc.) |
| Response Processing | 6 (Enrichers, Link Handlers, ContentLink) |
| Resilience | 3 (CircuitBreaker, RateLimiter, RetryPolicy) |
| Security | 6 (InputSanitizer, QueryFilter, AccessLevel, etc.) |
| Semantic Services | 5 (Matcher, Indexer, ContextSelector, ScopeMatcher, PromptBuilder) |
| Settings | 3 (ChatSettings interface and implementations) |
| UI Services | 8 (Themes, Factories, MarkdownRenderer) |

### Config Files (4 total)

| File | Purpose | Lines |
|------|---------|-------|
| config/ai.php | Main configuration (30+ sections) | ~887 |
| config/ai-patterns.php | Query patterns | ~50 |
| config/entities.php | Entity configurations | Variable |
| config/larecipe.php | Documentation system config | ~100 |

### Artisan Commands (9 total)

| Command | Signature | Description |
|---------|-----------|-------------|
| ai:diagnose | `ai:diagnose` | Diagnose AI package configuration and connectivity |
| ai:discover | `ai:discover {--model=} {--force} {--dry-run}` | Discover Nodeable entities and generate config/entities.php |
| ai:ingest | `ai:ingest {--model=} {--fresh} {--chunk=100} {--dry-run} {--docs}` | Bulk ingest all Nodeable entities into Neo4j and Qdrant |
| ai:sync-relationships | `ai:sync-relationships {--model=} {--chunk=100} {--dry-run}` | Synchronize missing relationships in Neo4j |
| ai:process-files | `ai:process-files {--model=} {--force} {--chunk=50} {--types=} {--dry-run}` | Batch process files for semantic search |
| ai:index-semantic | `ai:index-semantic {--rebuild} {--entities} {--scopes} {--templates} {--check}` | Build semantic indexes for fuzzy matching |
| ai:index-scopes | `ai:index-scopes {--force}` | Index scope examples and concepts for semantic matching |
| ai:index-context | `ai:index-context {--scopes-only} {--all} {--force}` | Index entity context for semantic matching |
| ai:config:validate | `ai:config:validate` | Validate entity configuration |

### Commands in Docs vs Code (DISCREPANCY)

**Documented but NOT in code:**
- ai:clear
- ai:status
- ai:test
- ai:query
- ai:config
- ai:publish
- ai:ingest-eager

**In code but NOT documented:**
- ai:diagnose
- ai:config:validate

**Documented with wrong options:**
- Several commands have different options than documented
