# Module 05: QUERY_GENERATION - Documentation Updates

> **Status:** COMPLETED
> **Date:** 2026-01-03

## Required Changes

| ID | Doc Path | Change Type | Description | Priority |
|----|----------|-------------|-------------|----------|
| DOC-001 | `src/Services/SemanticPromptBuilder.php` | Fix | Update docblock priority values (lines 44-47) | High |
| DOC-002 | `src/Services/PromptSections/CurrentUserContextSection.php` | Add | Document security implications of ID exposure | Medium |
| DOC-003 | `config/ai.php` | Add | Add inline comments explaining section priority order | Low |
| DOC-004 | `docs/` (new) | Create | PromptSections architecture guide | Low |

---

## Detailed Changes

### DOC-001: Fix SemanticPromptBuilder Docblock

**File:** `src/Services/SemanticPromptBuilder.php`

**Current (Incorrect):**
```php
 * - current_user (95): Current user context
 * - question (100): The user's actual question
 * - task_instructions (110): Final instructions for the LLM
```

**Should Be:**
```php
 * - current_user_context (17): Current user context
 * - schema (25): Database schema and node types
 * - relationships (30): Relationship types between nodes
 * - example_entities (35): Sample data for reference
 * - file_context (40): File context from vector search
 * - similar_queries (45): Previously successful similar queries
 * - conversation_context (50): Conversation history for follow-ups
 * - detected_entities (55): Entities detected in the user's question
 * - detected_scopes (60): Scopes/filters detected in the question
 * - pattern_library (65): Query patterns from the pattern library
 * - query_rules (70): Rules for query generation
 * - question (75): The user's actual question
 * - task_instructions (80): Final instructions for the LLM
```

**Note:** The full list with correct priorities should be:
```
project_context (10)
generic_context (15)
current_user_context (17)
schema (20)
relationships (30)
example_entities (40)
file_context (45)
similar_queries (50)
conversation_context (55)
detected_entities (60)
detected_scopes (65)
pattern_library (70)
query_rules (75)
question (80)
task_instructions (90)
```

---

### DOC-002: Document CurrentUserContextSection Security

**File:** `src/Services/PromptSections/CurrentUserContextSection.php`

**Add to class docblock:**
```php
/**
 * CurrentUserContextSection
 *
 * Adds current authenticated user and team context to the prompt.
 * Priority: 17 (after generic context, before schema)
 *
 * ## Security Note
 *
 * This section exposes the following information to the LLM:
 * - User ID, name, and email
 * - Team ID and name
 *
 * These values are included in the prompt sent to the LLM provider.
 * If this is a concern, consider:
 * - Using the config to disable this section
 * - Creating a custom section that masks sensitive values
 * - Removing this section via $builder->removeModule('current_user_context')
 */
```

---

### DOC-003: Add Config Comments for Section Order

**File:** `config/ai.php`

**Add comments to query_generator_sections:**
```php
'query_generator_sections' => [
    // Priority 10: Project name, description, business rules
    \Condoedge\Ai\Services\PromptSections\ProjectContextSection::class,

    // Priority 15: Current date/time
    \Condoedge\Ai\Services\PromptSections\GenericContextSection::class,

    // Priority 17: Current user/team context (exposes IDs)
    \Condoedge\Ai\Services\PromptSections\CurrentUserContextSection::class,

    // Priority 20: Graph schema (labels, relationships, properties)
    \Condoedge\Ai\Services\PromptSections\SchemaSection::class,

    // Priority 30: Entity relationships with Cypher patterns
    \Condoedge\Ai\Services\PromptSections\RelationshipsSection::class,

    // Priority 40: Real data samples with type hints
    \Condoedge\Ai\Services\PromptSections\ExampleEntitiesSection::class,

    // Priority 45: File context from vector search
    \Condoedge\Ai\Services\PromptSections\FileContextSection::class,

    // Priority 50: Similar past queries (few-shot learning)
    \Condoedge\Ai\Services\PromptSections\SimilarQueriesSection::class,

    // Priority 55: Conversation history for follow-ups
    \Condoedge\Ai\Services\PromptSections\ConversationContextSection::class,

    // Priority 60: Detected entities with metadata
    \Condoedge\Ai\Services\PromptSections\DetectedEntitiesSection::class,

    // Priority 65: Detected business scopes with Cypher patterns
    \Condoedge\Ai\Services\PromptSections\DetectedScopesSection::class,

    // Priority 70: Available query patterns (requires closure for DI)
    fn(SemanticPromptBuilder $promptBuilder) => new \Condoedge\Ai\Services\PromptSections\PatternLibrarySection($promptBuilder->getPatternLibrary()),

    // Priority 75: Query generation rules (enforces read-only)
    \Condoedge\Ai\Services\PromptSections\QueryRulesSection::class,

    // Priority 80: The user's question
    \Condoedge\Ai\Services\PromptSections\QuestionSection::class,

    // Priority 90: Final LLM task instructions
    \Condoedge\Ai\Services\PromptSections\TaskInstructionsSection::class,
],
```

---

### DOC-004: Create PromptSections Architecture Guide

**File:** `docs/architecture/prompt-sections.md` (NEW)

**Suggested Content:**
```markdown
# PromptSections Architecture Guide

## Overview

The query generation system uses a modular pipeline to build LLM prompts.
Each section contributes a portion of the final prompt, processed in priority order.

## How It Works

1. `SemanticPromptBuilder` loads sections from config
2. Sections are sorted by priority (lower = earlier)
3. For each section:
   - `shouldInclude()` determines if section runs
   - `format()` generates section content
4. All content is concatenated into final prompt

## Creating Custom Sections

```php
class MyCustomSection extends BasePromptSection
{
    protected string $name = 'my_custom';
    protected int $priority = 55; // Pick appropriate slot

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        return !empty($context['my_data']);
    }

    public function format(string $question, array $context, array $options = []): string
    {
        return $this->header('MY CUSTOM SECTION') .
               "Custom content here\n\n";
    }
}
```

## Registering Sections

### Via Config (Recommended)
```php
// config/ai.php
'query_generator_sections' => [
    // ... existing sections
    \App\Ai\Sections\MyCustomSection::class,
],
```

### Via Code
```php
$builder->addModule(new MyCustomSection());
```

### Via Global Extension
```php
// In a service provider
SemanticPromptBuilder::extendBuild(function($builder) {
    $builder->addModule(new MyCustomSection());
});
```

## Priority Slots

| Range | Purpose |
|-------|---------|
| 0-19 | Project/system context |
| 20-39 | Schema/structure |
| 40-59 | Examples/history |
| 60-79 | Detection/patterns |
| 80-99 | Question/instructions |

## Best Practices

1. Use `shouldInclude()` to skip when data is missing
2. Return empty string from `format()` if nothing to add
3. Use `$this->header()` for consistent formatting
4. Keep priorities in appropriate slots
5. Consider token budget - don't add unnecessary content
```

---

## Implementation Notes

- DOC-001 is highest priority - code/doc mismatch causes confusion
- DOC-002 is security documentation - important for compliance
- DOC-003 and DOC-004 are nice-to-have improvements
- All changes are documentation-only, no code changes required
