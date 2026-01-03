# Module 05: QUERY_GENERATION - Analysis Plan

> **Module Slug:** query-generation
> **Priority:** CRITICAL (Extensible pipeline - core pattern)
> **Estimated Files:** 19

---

## 1. Responsibility

### Ideal
- Generate Cypher queries from natural language
- Build prompts using extensible section pipeline
- Apply pattern library for common queries
- Integrate all context sources into prompt

### Key Pattern: HasInternalModules
Both QueryGenerator and SemanticPromptBuilder use this trait for extensible pipelines.

---

## 2. Files to Analyze

### Core Files
| File | Purpose |
|------|---------|
| `src/Services/QueryGenerator.php` | Main query generator |
| `src/Services/SemanticPromptBuilder.php` | Prompt building with sections |
| `src/Services/PatternLibrary.php` | Common query patterns |
| `src/Services/HasInternalModules.php` | Shared extensibility trait |

### PromptSections (16 files)
| File | Priority |
|------|----------|
| `BasePromptSection.php` | 0 |
| `ProjectContextSection.php` | 10 |
| `GenericContextSection.php` | 15 |
| `CurrentUserContextSection.php` | 20 |
| `SchemaSection.php` | 25 |
| `RelationshipsSection.php` | 30 |
| `ExampleEntitiesSection.php` | 35 |
| `FileContextSection.php` | 40 |
| `SimilarQueriesSection.php` | 45 |
| `ConversationContextSection.php` | 50 |
| `DetectedEntitiesSection.php` | 55 |
| `DetectedScopesSection.php` | 60 |
| `PatternLibrarySection.php` | 65 |
| `QueryRulesSection.php` | 70 |
| `QuestionSection.php` | 75 |
| `TaskInstructionsSection.php` | 80 |

---

## 3. Key Questions

- How does HasInternalModules work?
- How are sections registered from config?
- What determines section ordering/priority?
- How is PatternLibrary integrated?
- Are all 16 sections actually used?

---

## 4. Dependencies to Trace

- LlmProviderInterface for query generation
- All section dependencies
- Config: `ai.query_generator_sections`

---

## 5. Risk Areas

| Risk | Severity | Check |
|------|----------|-------|
| Unused sections | Medium | Trace all section usage |
| Section ordering wrong | High | Verify priority system |
| Prompt too large | High | Check token limits |
| Pattern not applied | Medium | Verify pattern matching |

---

## 6. Agent Instructions

1. Start with HasInternalModules trait - understand the pattern
2. Read QueryGenerator and SemanticPromptBuilder
3. Trace section registration from config
4. Read each PromptSection in priority order
5. Verify each section's shouldInclude() logic
6. Check for any unused sections
7. Document the complete prompt structure
