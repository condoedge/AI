# Phase 2 - Prompt Sections Audit

**Date:** 2025-12-30
**Task:** Task 29 - Architectural audit of PromptSections

## Overview

The PromptSections system provides modular, priority-based prompt building for LLM query generation. Sections are registered via config (`ai.query_generator_sections`) and processed by `SemanticPromptBuilder` using the `HasInternalModules` trait.

---

## Section Inventory

| Section | Priority | Registered | Status |
|---------|----------|------------|--------|
| BasePromptSection | N/A (abstract) | N/A | Base class |
| ProjectContextSection | 10 | Yes | Active |
| GenericContextSection | 15 | Yes | Active |
| CurrentUserContextSection | 17 | Yes | Active |
| SchemaSection | 20 | Yes | Active |
| RelationshipsSection | 30 | Yes | Active |
| ExampleEntitiesSection | 40 | Yes | Active |
| FileContextSection | 45 | Yes | Active |
| SimilarQueriesSection | 50 | Yes | Active |
| ConversationContextSection | 55 | Yes | Active |
| DetectedEntitiesSection | 60 | Yes | Active |
| DetectedScopesSection | 65 | Yes | Active |
| PatternLibrarySection | 70 | Yes | Active |
| QueryRulesSection | 75 | Yes | Active |
| QuestionSection | 80 | Yes | Active |
| TaskInstructionsSection | 90 | Yes | Active |

**All 16 sections are registered and used.**

---

## Section Details

### 1. BasePromptSection (Abstract)

**File:** `src/Services/PromptSections/BasePromptSection.php`

**Purpose:** Abstract base class providing common functionality for all prompt sections.

**Key Features:**
- Implements `PromptSectionInterface`
- Constructor allows name/priority override
- Default `shouldInclude()` returns true
- Helper methods: `header()`, `divider()`

**Dependencies:**
- `Condoedge\Ai\Contracts\PromptSectionInterface`

---

### 2. ProjectContextSection

**File:** `src/Services/PromptSections/ProjectContextSection.php`

**Purpose:** Adds project-level context (name, description, domain, business rules) to help LLM understand business domain.

**Priority:** 10 (first section)

**render() Output:**
```
=== PROJECT CONTEXT ===

Project: {name}
Description: {description}
Domain: {domain}

Business Rules:
  - {rule1}
  - {rule2}
```

**Dependencies:**
- `config('ai.project')` for project metadata
- Custom context can be set via `setContext()`

**Extension Points:**
- `setContext(array $context)` - Set custom project context
- `addBusinessRule(string $rule)` - Add individual rules

**shouldInclude:** True when project config is non-empty

---

### 3. GenericContextSection

**File:** `src/Services/PromptSections/GenericContextSection.php`

**Purpose:** Adds generic context like current date/time.

**Priority:** 15

**render() Output:**
```
=== CONTEXT INFORMATION ===

Current date: 2025-12-30 14:32:45
```

**Dependencies:** None (uses PHP `date()`)

**shouldInclude:** Always true (default)

---

### 4. CurrentUserContextSection

**File:** `src/Services/PromptSections/CurrentUserContextSection.php`

**Purpose:** Adds current authenticated user and team context for scoped queries.

**Priority:** 17

**render() Output:**
```
=== CURRENT USER CONTEXT. USE IT TO HAVE CURRENT TEAM AND CURRENT USER CONTEXT ===

Current user name: John Doe
Current user email: john@example.com
Current user ID: 123
Current team ID: 456
Current team name: Engineering
```

**Dependencies:**
- `auth()` facade for user info
- `safeCurrentTeam()` helper for team info

**shouldInclude:** Always true (default)

**Notes:**
- Missing `declare(strict_types=1)` statement (inconsistent with other sections)
- Uses global helper `safeCurrentTeam()` - potential coupling issue

---

### 5. SchemaSection

**File:** `src/Services/PromptSections/SchemaSection.php`

**Purpose:** Adds graph schema information (node labels, relationship types, properties).

**Priority:** 20

**render() Output:**
```
=== GRAPH SCHEMA ===

Available Node Labels:
  - Person
  - Team
  - Order

Available Relationship Types:
  - BELONGS_TO
  - PURCHASED

Node Properties by Label:
  Person: name, email, created_at
  Team: name, description
```

**Dependencies:**
- `$context['graph_schema']` array

**shouldInclude:** Always true (shows "No schema information available" if empty)

---

### 6. RelationshipsSection

**File:** `src/Services/PromptSections/RelationshipsSection.php`

**Purpose:** Adds entity relationships with EXACT directions - critical for correct query generation.

**Priority:** 30

**render() Output:**
```
=== ENTITY RELATIONSHIPS (with directions) ===
IMPORTANT: Use these EXACT directions in your queries!

Person:
  (Person)-[:BELONGS_TO]->(Team)
    Cypher: MATCH (x:Person)-[:BELONGS_TO]->(y:Team)
    Foreign key: team_id
    Description: Person belongs to a team

  (Person)<-[:CREATED_BY]-(Order)
    Cypher: MATCH (x:Person)<-[:CREATED_BY]-(y:Order)
    Foreign key: person_id
```

**Dependencies:**
- `config('entities')` for entity configurations
- `$context['graph_schema']['relationships']` for filtering

**shouldInclude:** True when entity configs exist

**Notes:**
- Skips pivot and non-inferred relationships for clarity
- Shows both visual pattern and Cypher syntax

---

### 7. ExampleEntitiesSection

**File:** `src/Services/PromptSections/ExampleEntitiesSection.php`

**Purpose:** Shows actual data from Neo4j to help LLM understand data types and formats. Critical for correct type handling.

**Priority:** 40

**render() Output:**
```
=== EXAMPLE ENTITIES (actual data format) ===
IMPORTANT: Use these to understand data types! Dates as strings need string comparison.

Person examples:
  Example 1:
    id: 123 (integer)
    name: 'John Doe' (string)
    created_at: '2024-01-15' (string date - compare as: property < '2024-01-15')
    is_active: true (boolean)
```

**Dependencies:**
- `$context['relevant_entities']` array

**shouldInclude:** True when relevant_entities exist

**Notes:**
- Provides type hints for all values
- Special formatting for date strings to guide comparisons

---

### 8. FileContextSection

**File:** `src/Services/PromptSections/FileContextSection.php`

**Purpose:** Provides file context from vector search results with citation instructions.

**Priority:** 45

**render() Output:**
```
=== FILE CONTEXT ===

**Citation Instructions:**
When using information from the files below, cite your sources using inline markers like [1], [2], etc.
Place the citation marker at the end of the relevant sentence or phrase.
Only cite files when you actually use their content in your response.

**Example:**
"Authentication can be configured using middleware [1]. The guard system handles sessions [2]."

**Relevant Files:**

**[1] auth-guide.md** (relevance: 85%)
  This document explains how to configure authentication...

**[2] session-management.md** (relevance: 72%)
  Sessions are managed using Laravel's built-in...
```

**Dependencies:**
- `$context['file_context']['relevant_files']` array

**shouldInclude:** True when relevant_files exist

---

### 9. SimilarQueriesSection

**File:** `src/Services/PromptSections/SimilarQueriesSection.php`

**Purpose:** Shows similar past queries for few-shot learning. LLM learns patterns from successful queries.

**Priority:** 50

**render() Output:**
```
=== SIMILAR QUERIES (learn from these) ===

Example 1 (85% similar):
  Question: How many active customers?
  Query: MATCH (c:Customer {status: 'active'}) RETURN count(c)

Example 2 (72% similar):
  Question: List all premium users
  Query: MATCH (u:User {tier: 'premium'}) RETURN u
```

**Dependencies:**
- `$context['similar_queries']` array

**Extension Points:**
- `setMaxQueries(int $max)` - Limit examples shown (default: 3)

**shouldInclude:** True when similar_queries exist

---

### 10. ConversationContextSection

**File:** `src/Services/PromptSections/ConversationContextSection.php`

**Purpose:** Adds conversation history and context for follow-up questions and pronoun resolution.

**Priority:** 55

**render() Output:**
```
=== CONVERSATION CONTEXT ===

**Current Focus:** Person (list query)

**Recent Conversation:**
  [1] User: Show me all volunteers
      Assistant: Here are the volunteers...
      Query: MATCH (p:Person)-[:HAS_ROLE]->...
  [2] User: How many of them are active?
      Assistant: There are 42 active...

**Note:** This is a continuation of the previous conversation. Consider building upon or modifying the previous query. Pronouns like 'those', 'them', 'it' refer to the Person entity.

**Entities discussed:** Person, Team
```

**Dependencies:**
- `$context['conversation_context']` array
  - `focused_entity`
  - `recent_exchanges`
  - `last_cypher_query`
  - `is_follow_up`
  - `mentioned_entities`

**shouldInclude:** True when focused_entity or recent_exchanges exist

---

### 11. DetectedEntitiesSection

**File:** `src/Services/PromptSections/DetectedEntitiesSection.php`

**Purpose:** Shows entities detected in the user's question with metadata.

**Priority:** 60

**render() Output:**
```
=== DETECTED ENTITIES IN QUESTION ===

Person:
  Description: A person in the system
  Also known as: user, member, individual
  Key properties:
    - name: Full name of the person
    - email: Email address

Team:
  Description: An organizational team
  Also known as: group, department
```

**Dependencies:**
- `$context['entity_metadata']['detected_entities']`
- `$context['entity_metadata']['entity_metadata']`

**shouldInclude:** True when detected_entities exist

---

### 12. DetectedScopesSection

**File:** `src/Services/PromptSections/DetectedScopesSection.php`

**Purpose:** Shows business concepts (scopes) detected in the question with full semantic specifications.

**Priority:** 65

**render() Output:**
```
=== DETECTED BUSINESS CONCEPTS ===
The user's question mentions these business concepts:

---------------------------------------------
SCOPE: VOLUNTEERS
ENTITY: Person
TYPE: relationship_traversal
---------------------------------------------

CONCEPT:
People who have a volunteer role in the organization

CYPHER PATTERN:
  (Person)-[:HAS_ROLE]->(:Role {type: 'volunteer'})

RELATIONSHIP PATH:
  (Person) -[:HAS_ROLE]-> (Role)

CONDITIONS:
  role.type = 'volunteer'

BUSINESS RULES:
  1. Volunteers must have active status
  2. Volunteer role requires approval

EXAMPLE QUESTIONS:
  - Show me all volunteers
  - List volunteer members
```

**Dependencies:**
- `$context['entity_metadata']['detected_scopes']` array

**Format Support:**
- `relationship_traversal` - Multi-hop relationship patterns
- `property_filter` - Simple property-based filters
- `pattern` - Named pattern references

**shouldInclude:** True when detected_scopes exist

**Notes:**
- Most complex section (268 lines)
- Handles multiple specification formats (parsed_structure, legacy)
- Generates both visual and executable Cypher patterns

---

### 13. PatternLibrarySection

**File:** `src/Services/PromptSections/PatternLibrarySection.php`

**Purpose:** Shows available reusable query patterns from the pattern library.

**Priority:** 70

**render() Output:**
```
=== AVAILABLE QUERY PATTERNS ===
You can use these reusable patterns to construct queries:

PATTERN: count_by_property
  Purpose: Count entities grouped by a property
  Template: MATCH (n:{entity}) RETURN n.{property}, count(n)
  Parameters: entity, property

PATTERN: list_related
  Purpose: List entities related to another entity
  Template: MATCH (a:{source})-[:{relationship}]->(b:{target}) RETURN b
  Parameters: source, relationship, target
```

**Dependencies:**
- `PatternLibrary` service (injected via constructor)

**shouldInclude:** True when pattern library has patterns

---

### 14. QueryRulesSection

**File:** `src/Services/PromptSections/QueryRulesSection.php`

**Purpose:** Provides rules for query generation including schema compliance, data types, relationships, and output format.

**Priority:** 75

**render() Output:**
```
=== QUERY GENERATION RULES ===
When generating Cypher queries, you MUST follow these rules:

1. SCHEMA COMPLIANCE:
   - Use only labels and relationships from the schema above
   - Use only properties that exist in the schema
   - CRITICAL: Use the EXACT relationship directions shown (arrows matter!)
   - CRITICAL: Use the EXACT data formats from example entities (strings vs dates)

2. DATA TYPE RULES:
   - Look at example entities to determine if dates are stored as strings or Neo4j dates
   - If date looks like '2020-01-15' (quoted string), compare as string: property < '2020-01-01'
   - If date looks like date('2020-01-15'), use date() function: property < date('2020-01-01')
   - String comparisons work for ISO date format (YYYY-MM-DD)

3. RELATIONSHIP DIRECTION RULES:
   - ALWAYS check the relationship direction in the schema
   - (a)-[:REL]->(b) means relationship goes FROM a TO b
   - (a)<-[:REL]-(b) means relationship goes FROM b TO a
   - Getting the direction wrong will return zero results!

4. BUSINESS RULES:
   - Respect all business rules from detected concepts
   - Apply filters exactly as specified in scope definitions
   - Use DISTINCT when specified to avoid duplicates

5. QUERY BEST PRACTICES:
   - Always include LIMIT clause (default LIMIT 100)
   - Use DISTINCT when traversing relationships
   - Use descriptive variable names (p for Person, o for Order, etc.)
   - Optimize for performance (use indexes, avoid cartesian products)

6. OUTPUT FORMAT:
   - Return ONLY the Cypher query
   - NO markdown code blocks
   - NO explanations or comments
   - NO formatting or decorations
   - Just clean, executable Cypher

7. READ-ONLY CONSTRAINT:
   - NO write operations (CREATE, MERGE, SET, DELETE, REMOVE, etc.)
   - Only generate read queries (MATCH, RETURN, WHERE, etc.)
```

**Dependencies:**
- `$options['allowWrite']` to toggle read-only constraint

**Extension Points:**
- `addRule(string $category, string $rule)` - Add custom rules
- `setRules(array $rules)` - Replace all custom rules

**shouldInclude:** Always true (default)

---

### 15. QuestionSection

**File:** `src/Services/PromptSections/QuestionSection.php`

**Purpose:** Adds the user's question to the prompt.

**Priority:** 80

**render() Output:**
```
=== USER QUESTION ===

Show me all active volunteers from the Engineering team
```

**Dependencies:**
- `$question` parameter

**shouldInclude:** Always true (default)

---

### 16. TaskInstructionsSection

**File:** `src/Services/PromptSections/TaskInstructionsSection.php`

**Purpose:** Provides final task instructions for the LLM.

**Priority:** 90 (last section)

**render() Output:**
```
=== YOUR TASK ===

Generate a Cypher query that:
1. Accurately answers the user's question
2. Uses the EXACT relationship directions shown in the schema
3. Uses the EXACT data formats shown in the example entities
4. Respects all business rules from the detected concepts above
5. Uses the appropriate query patterns from the library
6. Follows all query generation rules
7. Returns clean Cypher only (no markdown, no explanations, no formatting)

If the question cannot be answered with a Cypher query based on the provided context or a query it's not required to answer the question, return an strict text: 'NO QUERY REQUIRED'.

CYPHER QUERY:
```

**Dependencies:** None

**Extension Points:**
- `setInstructions(string $instructions)` - Override default instructions

**shouldInclude:** Always true (default)

---

## Registration Mechanism

Sections are registered in `config/ai.php` under `query_generator_sections`:

```php
'query_generator_sections' => [
    ProjectContextSection::class,
    GenericContextSection::class,
    CurrentUserContextSection::class,
    SchemaSection::class,
    RelationshipsSection::class,
    ExampleEntitiesSection::class,
    FileContextSection::class,
    SimilarQueriesSection::class,
    ConversationContextSection::class,
    DetectedEntitiesSection::class,
    DetectedScopesSection::class,
    fn($builder) => new PatternLibrarySection($builder->getPatternLibrary()),
    QueryRulesSection::class,
    QuestionSection::class,
    TaskInstructionsSection::class,
],
```

The `HasInternalModules` trait loads these via `registerDefaultModules()` and processes them in priority order.

---

## Unused Sections

**None found.** All 15 concrete sections are registered in the config.

---

## Dependencies Summary

| Section | External Dependencies |
|---------|----------------------|
| CurrentUserContextSection | `auth()`, `safeCurrentTeam()` |
| RelationshipsSection | `config('entities')` |
| ProjectContextSection | `config('ai.project')` |
| PatternLibrarySection | `PatternLibrary` service |
| All others | Context arrays only |

---

## Notes and Anomalies

### Code Quality Issues

1. **CurrentUserContextSection** - Missing `declare(strict_types=1)` statement (inconsistent with other sections)

2. **DetectedScopesSection** - Very complex (268 lines) with multiple format handlers. Consider splitting into separate formatters.

3. **Header text inconsistency** in CurrentUserContextSection:
   ```php
   // Very verbose header
   'CURRENT USER CONTEXT. USE IT TO HAVE CURRENT TEAM AND CURRENT USER CONTEXT'
   ```

### Documentation Discrepancy

The `SemanticPromptBuilder` docblock lists priorities that don't match actual implementations:
- Docblock says pattern_library is 80, but actual is 70
- Docblock says query_rules is 90, but actual is 75
- Docblock says current_user is 95, but actual is 17
- Docblock says question is 100, but actual is 80
- Docblock says task_instructions is 110, but actual is 90

### Extensibility

The system is well-designed for extension:
- Global extensions via `SemanticPromptBuilder::extendBuild()`
- Instance extensions via `addModule()`, `removeModule()`, `replaceModule()`
- Before/after callbacks via `extendBefore()`, `extendAfter()`
- Several sections expose configuration methods

### Potential Improvements

1. **Update docblock** - Sync priority documentation with actual values

2. **Add validation** - Could validate context arrays have expected keys

3. **Caching** - Some sections (SchemaSection, RelationshipsSection) could cache formatted output

4. **Metrics** - Add optional timing/size metrics for debugging large prompts

---

## Conclusion

The PromptSections system is well-architected with:
- Clear separation of concerns (one section per context type)
- Consistent interface (`PromptSectionInterface`)
- Priority-based ordering
- Conditional inclusion (`shouldInclude`)
- Extension points for customization

All 16 sections are registered and actively used. No orphaned or unused sections found.

The main action items are:
1. Add `declare(strict_types=1)` to CurrentUserContextSection
2. Update SemanticPromptBuilder docblock with correct priorities
3. Consider refactoring DetectedScopesSection into smaller components
