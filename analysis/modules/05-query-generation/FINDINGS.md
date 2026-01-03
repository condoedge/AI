# Module 05: Query Generation - Findings

> **Status:** COMPLETED
> **Date:** 2026-01-03
> **Analyst:** Claude Code

## Executive Summary

The query generation module is well-architected with a clean, extensible design using the `HasInternalModules` trait. All 15 registered prompt sections are actively used, with a single base class (`BasePromptSection`) providing shared functionality. The priority ordering system is correctly implemented, and the PatternLibrary integrates cleanly via a closure in the config.

**Overall Assessment: HEALTHY - No critical issues found**

---

## Architecture Overview

### Core Components

| Component | Location | Purpose |
|-----------|----------|---------|
| QueryGenerator | `src/Services/QueryGenerator.php` | Main entry point - NL to Cypher |
| SemanticPromptBuilder | `src/Services/SemanticPromptBuilder.php` | Prompt pipeline orchestrator |
| PatternLibrary | `src/Services/PatternLibrary.php` | Reusable query patterns |
| HasInternalModules | `src/Services/HasInternalModules.php` | Extensibility trait |

### Data Flow

```
User Question
    |
    v
QueryGenerator.generate()
    |
    +--> Template Match? --> generateFromTemplate() --> Result
    |
    +--> No Match --> SemanticPromptBuilder.buildPrompt()
                          |
                          v
                     ProcessModules (sorted by priority)
                          |
                          +--> [ProjectContextSection (10)]
                          +--> [GenericContextSection (15)]
                          +--> [CurrentUserContextSection (17)]
                          +--> [SchemaSection (20)]
                          +--> [RelationshipsSection (30)]
                          +--> [ExampleEntitiesSection (40)]
                          +--> [FileContextSection (45)]
                          +--> [SimilarQueriesSection (50)]
                          +--> [ConversationContextSection (55)]
                          +--> [DetectedEntitiesSection (60)]
                          +--> [DetectedScopesSection (65)]
                          +--> [PatternLibrarySection (70)]
                          +--> [QueryRulesSection (75)]
                          +--> [QuestionSection (80)]
                          +--> [TaskInstructionsSection (90)]
                          |
                          v
                     Complete Prompt --> LLM --> Cypher Query
```

---

## Issues Found

| ID | Severity | Description | Evidence | Recommendation |
|----|----------|-------------|----------|----------------|
| QG-001 | MEDIUM | SemanticPromptBuilder docblock shows outdated priorities | Lines 44-47 show current_user: 95, question: 100, task: 110 but actual code has 17, 80, 90 | Update docblock to match actual priorities |
| QG-002 | MEDIUM | GenericContextSection always included | Only provides current date, no shouldInclude check | Make conditional or merge into another section |
| QG-003 | LOW | CurrentUserContextSection exposes IDs | User/team IDs sent to LLM in prompt | Document behavior, consider optional masking |
| QG-004 | LOW | Missing strict_types declaration | CurrentUserContextSection.php lacks `declare(strict_types=1);` | Add declaration for consistency |
| QG-005 | LOW | DetectedScopesSection complexity | Multiple legacy format handlers in formatRelationshipSpec/formatPropertyFilter | Consider refactoring to separate handlers |
| QG-006 | LOW | No token budget tracking | Large contexts could exceed LLM limits | Consider adding token tracking to builder |

---

## HasInternalModules Pattern Analysis

### Pattern Overview

The `HasInternalModules` trait provides a flexible, priority-based module pipeline:

```php
trait HasInternalModules
{
    private static array $globalExtensions = [];  // Applied to ALL instances
    private array $sections = [];                  // Module registry
    private array $beforeCallbacks = [];           // Pre-module hooks
    private array $afterCallbacks = [];            // Post-module hooks
}
```

### Key Methods

| Method | Purpose |
|--------|---------|
| `registerDefaultModules()` | Load modules from config key |
| `applyGlobalExtensions()` | Run static extensions on this instance |
| `addModule($section)` | Add module to registry |
| `removeModule($name)` | Remove module by name |
| `replaceModule($name, $section)` | Replace existing module |
| `getModule($name)` | Retrieve module by name |
| `hasModule($name)` | Check module existence |
| `extendBefore($name, $callback)` | Insert content before module |
| `extendAfter($name, $callback)` | Insert content after module |
| `extendBuild($callback)` | Static - affects all instances |
| `processModules(...)` | Execute pipeline in priority order |

### Closure Support

Config supports closure-based instantiation:

```php
fn($promptBuilder) => new PatternLibrarySection($promptBuilder->getPatternLibrary())
```

This enables dependency injection for sections needing builder context.

---

## Section Usage Analysis

| Section | Registered | Priority | shouldInclude Logic | Status |
|---------|------------|----------|---------------------|--------|
| BasePromptSection | No (abstract) | 0 | Always true (default) | N/A |
| ProjectContextSection | Yes | 10 | Has project config | ACTIVE |
| GenericContextSection | Yes | 15 | Always | ACTIVE |
| CurrentUserContextSection | Yes | 17 | Always | ACTIVE |
| SchemaSection | Yes | 20 | Always | ACTIVE |
| RelationshipsSection | Yes | 30 | Has entity configs | ACTIVE |
| ExampleEntitiesSection | Yes | 40 | Has relevant_entities | ACTIVE |
| FileContextSection | Yes | 45 | Has file_context | ACTIVE |
| SimilarQueriesSection | Yes | 50 | Has similar_queries | ACTIVE |
| ConversationContextSection | Yes | 55 | Has conversation context | ACTIVE |
| DetectedEntitiesSection | Yes | 60 | Has detected_entities | ACTIVE |
| DetectedScopesSection | Yes | 65 | Has detected_scopes | ACTIVE |
| PatternLibrarySection | Yes (closure) | 70 | Has patterns | ACTIVE |
| QueryRulesSection | Yes | 75 | Always | ACTIVE |
| QuestionSection | Yes | 80 | Always | ACTIVE |
| TaskInstructionsSection | Yes | 90 | Always | ACTIVE |

**Result:** All 15 sections listed in scope are registered and active. No unused sections.

---

## Section Details

### Priority 10: ProjectContextSection
- **File:** `src/Services/PromptSections/ProjectContextSection.php`
- **Content:** Project name, description, domain, business rules
- **Source:** `config('ai.project')` or custom context via `setContext()`
- **Customizable:** Yes - `setContext()`, `addBusinessRule()`

### Priority 15: GenericContextSection
- **File:** `src/Services/PromptSections/GenericContextSection.php`
- **Content:** Current date/time
- **Note:** Always included - consider making conditional

### Priority 17: CurrentUserContextSection
- **File:** `src/Services/PromptSections/CurrentUserContextSection.php`
- **Content:** User name, email, ID; Team ID, name
- **Dependencies:** `auth()`, `safeCurrentTeam()`
- **Security:** Exposes IDs in prompt

### Priority 20: SchemaSection
- **File:** `src/Services/PromptSections/SchemaSection.php`
- **Content:** Graph schema labels, relationships, properties
- **Source:** `context['graph_schema']`

### Priority 30: RelationshipsSection
- **File:** `src/Services/PromptSections/RelationshipsSection.php`
- **Content:** Entity relationships with Cypher patterns
- **Critical:** Relationship direction is essential for correct queries
- **Source:** `config('entities')`, `context['graph_schema']`

### Priority 40: ExampleEntitiesSection
- **File:** `src/Services/PromptSections/ExampleEntitiesSection.php`
- **Content:** Real data samples with type hints
- **Purpose:** Helps LLM understand data formats (dates, types)
- **Source:** `context['relevant_entities']`

### Priority 45: FileContextSection
- **File:** `src/Services/PromptSections/FileContextSection.php`
- **Content:** File context with citation instructions
- **Source:** `context['file_context']['relevant_files']`

### Priority 50: SimilarQueriesSection
- **File:** `src/Services/PromptSections/SimilarQueriesSection.php`
- **Content:** Past successful queries (few-shot learning)
- **Customizable:** `setMaxQueries()` (default: 3)
- **Source:** `context['similar_queries']`

### Priority 55: ConversationContextSection
- **File:** `src/Services/PromptSections/ConversationContextSection.php`
- **Content:** Conversation history, previous query, result samples
- **Purpose:** Follow-up question support
- **Source:** `context['conversation_context']`

### Priority 60: DetectedEntitiesSection
- **File:** `src/Services/PromptSections/DetectedEntitiesSection.php`
- **Content:** Detected entities with descriptions, aliases, properties
- **Source:** `context['entity_metadata']['detected_entities']`

### Priority 65: DetectedScopesSection
- **File:** `src/Services/PromptSections/DetectedScopesSection.php`
- **Content:** Business concepts/scopes with Cypher patterns
- **Note:** Complex formatting with legacy format handlers
- **Source:** `context['entity_metadata']['detected_scopes']`

### Priority 70: PatternLibrarySection
- **File:** `src/Services/PromptSections/PatternLibrarySection.php`
- **Content:** Available query patterns for LLM reference
- **Dependencies:** Requires `PatternLibrary` injection via closure
- **Source:** `PatternLibrary.getAllPatterns()`

### Priority 75: QueryRulesSection
- **File:** `src/Services/PromptSections/QueryRulesSection.php`
- **Content:** 6-7 rule categories for query generation
- **Customizable:** `addRule()`, `setRules()`
- **Note:** Enforces read-only based on `allowWrite` option

### Priority 80: QuestionSection
- **File:** `src/Services/PromptSections/QuestionSection.php`
- **Content:** User's question text
- **Simple:** Header + question

### Priority 90: TaskInstructionsSection
- **File:** `src/Services/PromptSections/TaskInstructionsSection.php`
- **Content:** Final LLM instructions, "NO QUERY REQUIRED" fallback
- **Customizable:** `setInstructions()`

---

## PatternLibrary Integration

### Patterns Available (11)

| Pattern | Purpose |
|---------|---------|
| property_filter | Filter by property value |
| property_range | Numeric range filtering |
| relationship_traversal | Graph traversal with filters |
| entity_with_relationship | Existence check |
| entity_without_relationship | Absence check |
| entity_with_aggregated_relationship | Aggregation-based filtering |
| temporal_filter | Date/time conditions |
| multi_hop_traversal | Complex multi-step paths |
| multiple_property_filter | AND/OR property logic |
| relationship_with_property_filter | Combined traversal + filters |
| composed | Pattern composition |

### Integration Flow

1. `QueryGenerator` creates `SemanticPromptBuilder` with `PatternLibrary`
2. Config closure receives builder, calls `getPatternLibrary()`
3. `PatternLibrarySection` instantiated with library reference
4. Section formats all patterns for LLM in prompt

---

## Token Usage Considerations

### Current State

**No explicit token limit handling.** All sections generate content without budget tracking.

### Mitigation Factors

- `shouldInclude()` conditionals reduce unnecessary sections
- `SimilarQueriesSection.setMaxQueries()` limits examples
- RAG config limits similar query counts

### Risk Areas

| Factor | Risk Level |
|--------|------------|
| Many detected entities | Medium |
| Long conversation history | Medium |
| Many patterns | Low |
| Large schema | Medium |

### Recommendation

Consider adding token budget tracking to `SemanticPromptBuilder.buildPrompt()`.

---

## Recommendations

### High Priority

1. **Update SemanticPromptBuilder docblock** (QG-001)
   - Fix priority values in class documentation
   - Minimal effort, high documentation value

### Medium Priority

2. **Make GenericContextSection conditional** (QG-002)
   - Add config option to enable/disable
   - Or merge into ProjectContextSection

3. **Consider token tracking** (QG-006)
   - Track approximate token count during build
   - Warn or truncate if approaching limits

### Low Priority

4. **Add strict_types to CurrentUserContextSection** (QG-004)
   - Consistency improvement

5. **Document security behavior** (QG-003)
   - Add note about user/team ID exposure
   - Consider optional masking feature

6. **Refactor DetectedScopesSection** (QG-005)
   - Extract format handlers to separate methods/classes
   - Improve maintainability

---

## Summary

| Metric | Value |
|--------|-------|
| Total Sections in Scope | 16 (1 abstract + 15 concrete) |
| Sections Registered | 15 |
| Unused Sections | 0 |
| Critical Issues | 0 |
| High Issues | 0 |
| Medium Issues | 2 |
| Low Issues | 4 |
| Extension Points | 7+ |
| Token Handling | None explicit |

**Module Status: HEALTHY**
