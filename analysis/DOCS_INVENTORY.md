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
