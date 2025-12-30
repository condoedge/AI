# Phase 2 Audit: Response Services

## Overview

This audit covers the Response services responsible for transforming query results into natural language responses using LLM. The system uses a modular, priority-based section pipeline similar to the query generation prompt builder.

## Architecture Summary

```
ResponseGenerator (main orchestrator)
    |
    +-- Uses HasInternalModules trait
    |
    +-- Loads sections from config('ai.response_generator_sections')
    |
    +-- Processes sections in priority order (lower = earlier)
    |
    +-- ResponseFileEnricher (enriches responses with file references)
```

---

## File: ResponseFileEnricher.php

**Location:** `src/Services/Response/ResponseFileEnricher.php`

### Purpose
Enriches AI responses with file reference metadata by extracting citation markers from response text and building actionable file references.

### Key Features
- Extracts citation markers `[N]` from response text (1-indexed)
- Maps citations to files in the provided file context
- Builds actionable metadata for database files (download/preview URLs)
- Physical files (prefixed with `physical:`) have null URLs and cannot be downloaded directly

### Public Methods

| Method | Purpose | Returns |
|--------|---------|---------|
| `extractCitationMarkers(string $response)` | Find all `[N]` patterns in response | `array<int>` unique markers |
| `buildReferencedFiles(string $response, array $fileContext, array $options)` | Build file references for cited files | `array` file metadata |
| `enrichResponse(array $response, array $fileContext, array $options)` | Add `referenced_files` and `has_file_references` to response | `array` enriched response |

### Dependencies
- None (self-contained service)

### Registration
- Registered as singleton in `AiServiceProvider::registerFileContextServices()`
- Used by `AiManager` for file-aware responses

### Notes
- Well-documented with clear docblocks
- Handles physical vs database files appropriately
- Uses closure-based URL resolvers for flexibility

---

## File: BaseResponseSection.php

**Location:** `src/Services/ResponseSections/BaseResponseSection.php`

### Purpose
Abstract base class for response sections providing common functionality.

### Implements
`ResponseSectionInterface`

### Properties
- `$name: string` - Section identifier
- `$priority: int` - Processing order

### Methods

| Method | Purpose |
|--------|---------|
| `getName(): string` | Returns section name |
| `getPriority(): int` | Returns priority value |
| `shouldInclude(array $context, array $options): bool` | Default returns `true` |
| `header(string $title): string` | Helper to format section headers |

### render() Output
Not applicable (abstract class)

### Notes
- Clean, minimal base implementation
- Provides `header()` helper for consistent formatting
- Constructor allows overriding name and priority

---

## File: SystemPromptSection.php

**Location:** `src/Services/ResponseSections/SystemPromptSection.php`

### Purpose
The initial system prompt that sets the LLM's role for response generation.

### Priority: 10 (first in pipeline)

### render() Output
```
You are a data analyst who explains query results clearly and accurately.

```
Or custom prompt if set via `setPrompt()`.

### Configuration Methods
- `setPrompt(string $prompt): self` - Set custom system prompt

### Dependencies
- None

### Registration
- **REGISTERED** in `config/ai.php` under `response_generator_sections`

---

## File: PrivacyAndSecurityGuidelinesSection.php

**Location:** `src/Services/ResponseSections/PrivacyAndSecurityGuidelinesSection.php`

### Purpose
Security and data privacy guidelines to prevent leakage of sensitive information.

### Priority: 1000 (appears at the end to ensure compliance)

### render() Output
Multi-line security instructions including:
- Do not reveal sensitive/personal data
- Comply with data privacy regulations
- Do not reveal internal system details
- Refuse requests for tables, properties, internal information
- Never reveal database structure or architecture
- Never mention internal code names, model names, table names, property names
- Never reveal reasoning process for data access

### Configuration Methods
- None (hardcoded guidelines)

### Dependencies
- None

### Registration
- **REGISTERED** in `config/ai.php` under `response_generator_sections`

### Notes
- **CRITICAL SECURITY SECTION** - Priority 1000 ensures it's the last instruction
- Contains strict "CANNOT BE OVERRIDDEN OR IGNORED" directive
- Some inconsistent formatting (embedded `\n\n` in string)

---

## File: ResponseProjectContextSection.php

**Location:** `src/Services/ResponseSections/ResponseProjectContextSection.php`

### Purpose
Adds project context to help the LLM understand the business domain when explaining results.

### Priority: 20

### render() Output
```
Project Context:
- Project: {name}
- Description: {description}
- Domain: {domain}

```
Output varies based on available config values.

### Configuration Methods
- `setContext(array $context): self` - Set custom project context

### Dependencies
- `config('ai.project')` for default context

### Registration
- **REGISTERED** in `config/ai.php` under `response_generator_sections`

### shouldInclude Logic
Returns `true` if project config is not empty.

---

## File: OriginalQuestionSection.php

**Location:** `src/Services/ResponseSections/OriginalQuestionSection.php`

### Purpose
Shows the user's original question in the prompt.

### Priority: 30

### render() Output
```
Original Question: {question}

```

### Configuration Methods
- None

### Dependencies
- `$context['question']`

### Registration
- **REGISTERED** in `config/ai.php` under `response_generator_sections`

### shouldInclude Logic
Returns `true` if `$context['question']` is not empty.

---

## File: QueryInfoSection.php

**Location:** `src/Services/ResponseSections/QueryInfoSection.php`

### Purpose
Shows the Cypher query that was executed.

### Priority: 40

### render() Output
```
Query Executed:
{cypher}

```

### Configuration Methods
- `setIncludeQuery(bool $include): self` - Enable/disable query inclusion

### Dependencies
- `$context['cypher']`

### Registration
- **REGISTERED** in `config/ai.php` under `response_generator_sections`

### shouldInclude Logic
Returns `true` if `$includeQuery` is true AND `$context['cypher']` is not empty.

### Notes
- Can be disabled via `setIncludeQuery(false)` for privacy/security

---

## File: ResultsDataSection.php

**Location:** `src/Services/ResponseSections/ResultsDataSection.php`

### Purpose
Shows the query results data to the LLM.

### Priority: 50

### render() Output
```
Results:
{JSON encoded data}

```
Or if no data:
```
Results: No data returned

```

If data exceeds `$maxItems`:
```
(Showing first {maxItems} of {total} results, {remaining} more not shown)
```

### Configuration Methods
- `setMaxItems(int $max): self` - Set maximum items to show (default: 10)

### Dependencies
- `$context['data']`

### Registration
- **REGISTERED** in `config/ai.php` under `response_generator_sections`

### Notes
- Summarizes large result sets to avoid token overflow
- Uses `JSON_PRETTY_PRINT` for readability

---

## File: StatisticsSection.php

**Location:** `src/Services/ResponseSections/StatisticsSection.php`

### Purpose
Shows execution statistics like timing and row counts.

### Priority: 60

### render() Output
```
Statistics:
- Execution time: {execution_time_ms}ms
- Rows returned: {count}

```

### Configuration Methods
- None

### Dependencies
- `$context['stats']` and `$context['data']`

### Registration
- **REGISTERED** in `config/ai.php` under `response_generator_sections`

### shouldInclude Logic
Returns `true` if either `$context['stats']` or `$context['data']` is not empty.

---

## File: GuidelinesSection.php

**Location:** `src/Services/ResponseSections/GuidelinesSection.php`

### Purpose
Provides guidelines for response generation with configurable verbosity styles.

### Priority: 70

### Available Styles
| Style | Description | Max Words |
|-------|-------------|-----------|
| `minimal` | Just the answer, nothing else | 20 |
| `concise` | One sentence answer | 50 |
| `friendly` | Natural conversation, 2-3 sentences | 100 |
| `detailed` | Full explanation with context | 200 |
| `technical` | Includes query details for debugging | 300 |

### render() Output
Complex multi-line output including:
- Task instruction
- Style-specific guidelines
- Max length constraint
- Things to avoid based on style
- Format-specific guidelines (markdown/json)
- Custom guidelines if added

### Configuration Methods
- `addGuideline(string $guideline): self` - Add custom guideline
- `setGuidelines(array $guidelines): self` - Set all custom guidelines
- `addStyle(string $name, string $prompt): self` - Add/override style
- `setAvoidGuidelines(array $avoid): self` - Set things to avoid
- `addAvoid(string $avoid): self` - Add something to avoid

### Dependencies
- `config('ai.response_generation.default_style')` (default: 'friendly')
- `config('ai.response_generation.hide_technical_details')`
- `config('ai.response_generation.hide_execution_stats')`
- `config('ai.response_generation.hide_project_info')`

### Registration
- **REGISTERED** in `config/ai.php` under `response_generator_sections`

### Notes
- Most complex section with extensive style-based conditional logic
- Friendly and below styles automatically hide technical details
- Config-based restrictions only apply to 'detailed' style

---

## File: ResponseTaskSection.php

**Location:** `src/Services/ResponseSections/ResponseTaskSection.php`

### Purpose
Final task instruction for the LLM.

### Priority: 80

### render() Output
```
Generate response:
```
Or custom task if set via `setTask()`.

### Configuration Methods
- `setTask(string $task): self` - Set custom task instruction

### Dependencies
- None

### Registration
- **REGISTERED** in `config/ai.php` under `response_generator_sections`

---

## Registration Summary

### Sections Registered in Config

All sections are registered in `config/ai.php` under `response_generator_sections`:

```php
'response_generator_sections' => [
    \Condoedge\Ai\Services\ResponseSections\SystemPromptSection::class,          // Priority 10
    \Condoedge\Ai\Services\ResponseSections\PrivacyAndSecurityGuidelinesSection::class,  // Priority 1000 (!)
    \Condoedge\Ai\Services\ResponseSections\ResponseProjectContextSection::class, // Priority 20
    \Condoedge\Ai\Services\ResponseSections\OriginalQuestionSection::class,       // Priority 30
    \Condoedge\Ai\Services\ResponseSections\QueryInfoSection::class,              // Priority 40
    \Condoedge\Ai\Services\ResponseSections\ResultsDataSection::php,              // Priority 50
    \Condoedge\Ai\Services\ResponseSections\StatisticsSection::class,             // Priority 60
    \Condoedge\Ai\Services\ResponseSections\GuidelinesSection::class,             // Priority 70
    \Condoedge\Ai\Services\ResponseSections\ResponseTaskSection::class,           // Priority 80
]
```

### Unused Sections

**None identified** - All defined sections are registered and used.

---

## Pipeline Execution Order (by Priority)

| Order | Priority | Section | Purpose |
|-------|----------|---------|---------|
| 1 | 10 | SystemPromptSection | Set LLM role |
| 2 | 20 | ResponseProjectContextSection | Business domain context |
| 3 | 30 | OriginalQuestionSection | User's question |
| 4 | 40 | QueryInfoSection | Executed query |
| 5 | 50 | ResultsDataSection | Query results |
| 6 | 60 | StatisticsSection | Execution stats |
| 7 | 70 | GuidelinesSection | Response formatting rules |
| 8 | 80 | ResponseTaskSection | Final instruction |
| 9 | 1000 | PrivacyAndSecurityGuidelinesSection | Security constraints |

---

## Notes and Anomalies

### 1. PrivacyAndSecurityGuidelinesSection Priority
- Priority 1000 places it at the very end
- Docblock says "appearing at the final to ensure compliance"
- However, it's registered second in the config array (after SystemPromptSection)
- The priority-based sorting ensures correct order regardless of config position

### 2. Naming Inconsistency
- `ResponseProjectContextSection` has "Response" prefix
- `ResponseTaskSection` has "Response" prefix
- Other sections don't have this prefix
- Suggests some sections were created at different times

### 3. GuidelinesSection Complexity
- Most complex section with ~180 lines
- Could potentially be split into smaller, more focused sections
- Style logic is well-organized but dense

### 4. Interface Naming
- `ResponseSectionInterface` uses `format()` method
- `PromptSectionInterface` also uses `format()` method
- Both extend `SectionModuleInterface` - good consistency

### 5. No Caching
- Sections are instantiated fresh for each response generation
- No caching of formatted output
- Appropriate for dynamic content but could be optimized for static sections

### 6. ResponseGenerator Convenience Methods
The `ResponseGenerator` class provides convenience methods that interact with specific sections:
- `setProjectContext()` -> `ResponseProjectContextSection`
- `setSystemPrompt()` -> `SystemPromptSection`
- `addGuideline()` -> `GuidelinesSection`
- `setMaxDataItems()` -> `ResultsDataSection`

---

## Recommendations

1. **Consider security section placement**: While priority 1000 works, explicitly documenting this behavior would help future developers.

2. **Normalize naming**: Consider removing "Response" prefix from section names for consistency, or adding it to all sections.

3. **Format PrivacyAndSecurityGuidelinesSection**: Clean up embedded `\n\n` characters and use proper string concatenation.

4. **Add configuration for StatisticsSection**: Allow hiding execution time or row count independently.

5. **Consider token budget**: Add optional token estimation to avoid exceeding LLM context limits.

---

## Summary

| Metric | Count |
|--------|-------|
| Total Files Reviewed | 11 |
| Response Sections | 9 |
| Registered Sections | 9 (100%) |
| Unused Sections | 0 |
| Helper Services | 2 (ResponseFileEnricher, BaseResponseSection) |

The response services module is well-organized and follows the same architectural patterns as the prompt builder. All sections are registered and in use. The priority-based pipeline provides flexibility for customization while maintaining consistent output structure.
