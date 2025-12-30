# Phase 2 Audit: Security Services

**Audit Date:** 2025-12-30
**Task:** 32
**Files Reviewed:** 4
**Directory:** `src/Services/Security/`

---

## Executive Summary

The Security services directory contains four components for access control, prompt context building, data sanitization, and team-based query filtering. While the architecture demonstrates thoughtful security design, **critical Cypher injection vulnerabilities** exist in `TeamFilteredQuery.php` (previously identified in Task 10 and confirmed here).

| File | Risk Level | Status |
|------|------------|--------|
| AccessLevelResolver.php | LOW | Well-designed, no vulnerabilities |
| PromptContextBuilder.php | LOW | Effective prompt injection defense |
| SensitiveDataSanitizer.php | LOW | Comprehensive credential protection |
| TeamFilteredQuery.php | **CRITICAL** | Cypher injection vulnerability |

---

## 1. AccessLevelResolver.php

### Purpose
Resolves multi-level access tags for RAG queries based on user permissions. Integrates with Kompo Auth package to determine what data the AI can reveal to each user.

### Security Mechanism
Implements a five-tier access hierarchy:

| Level | Tag | Description | Requirement |
|-------|-----|-------------|-------------|
| 0 | `global_count` | Total counts, no filters | Public (anyone) |
| 1 | `team_count` | Counts within user's teams | Team membership |
| 2 | `team_filtered_count` | Filtered counts (threshold protected) | READ permission |
| 3 | `team_details` | Record data (non-sensitive) | READ permission |
| 4 | `team_sensitive` | Record data (including sensibleColumns) | sensibleColumns permission |

### Data Protected
- Entity record counts (with threshold protection for small counts)
- Entity details (non-sensitive fields)
- Sensitive columns (salary, SSN, etc.) - highest tier

### Public Methods

| Method | Parameters | Return | Description |
|--------|-----------|--------|-------------|
| `resolveForEntity()` | `?Authenticatable $user, string $entityClass` | `array` | Returns access tags for user/entity |
| `buildContextForEntity()` | `?Authenticatable $user, string $entityClass` | `array` | Full context with tags, thresholds, teams |
| `getThreshold()` | `string $entity` | `int` | Returns count threshold for entity |
| `hasAccessLevel()` | `?Authenticatable $user, string $entity, string $level` | `bool` | Checks specific access level |

### Pipeline Integration
- **Registered:** `AiServiceProvider.php` as singleton
- **Used by:** `PromptContextBuilder` (via constructor injection)

### Security Effectiveness: HIGH
- Proper null-user handling (returns only Level 0)
- Graceful fallback when permission methods don't exist
- Config-driven thresholds with sensible defaults
- Entity-specific identifying fields support

### Unused Methods
None - all methods serve the access control pipeline.

### Notes/Anomalies
1. **Reflection usage:** Uses `ReflectionProperty` to access `$sensibleColumns` - could fail on private properties in some PHP versions (though `setAccessible(true)` mitigates)
2. **Model instantiation:** `resolveModel()` instantiates models to get sensibleColumns - this could trigger constructors with side effects
3. **No caching:** Access resolution is computed per-request; could benefit from request-scoped caching for heavy workloads

---

## 2. PromptContextBuilder.php

### Purpose
Builds access-aware prompt context for RAG queries. Injects access control instructions into prompts to guide the AI on what data it can reveal based on user permissions.

### Security Mechanism
- Generates structured prompt sections with ALLOWED/RESTRICTED directives
- Applies count thresholds to prevent identification via small counts
- Redacts sensitive fields from semantic search results
- Warns about identifying fields (e.g., exact names, IDs)

### Data Protected
- Sensitive columns (redacted from context if user lacks `team_sensitive` access)
- Small counts (threshold protection prevents "only 1 person" disclosure)
- Team boundaries (ensures AI responses respect team isolation)

### Public Methods

| Method | Parameters | Return | Description |
|--------|-----------|--------|-------------|
| `setEntitySensibleColumns()` | `string $entity, array $columns` | `self` | Override sensible columns per entity |
| `buildAccessSection()` | `array $entities` | `string` | Builds access control prompt section |
| `buildFullContext()` | `array $entities, array $semanticResults, array $aggregates` | `string` | Complete context with all sections |
| `buildSystemPrompt()` | `array $entities` | `string` | System prompt with access instructions |

### Pipeline Integration
- Not directly registered in service provider
- Instantiated with user context when needed
- Uses `AccessLevelResolver` via `app()` helper

### Security Effectiveness: HIGH
- **Prompt injection defense:** Structured format with clear ALLOWED/RESTRICTED sections
- **Defense-in-depth:** Both prompt-level and content-level filtering
- **Pattern-based redaction:** Catches field: value formats in content

### Unused Methods
None - all methods contribute to context building.

### Notes/Anomalies
1. **Regex redaction:** `filterSensitiveContent()` uses regex patterns that may miss edge cases:
   - JSON-encoded values
   - Multi-line values
   - Non-standard formatting
2. **No integration observed:** Grep shows `PromptContextBuilder` only references itself - may be unused in current pipeline
3. **Hardcoded instruction format:** The prompt structure is embedded in code rather than configurable

---

## 3. SensitiveDataSanitizer.php

### Purpose
Removes or masks sensitive information from logs, error messages, and exceptions to prevent accidental exposure of API keys, passwords, and other credentials.

### Security Mechanism
Two-pronged approach:
1. **Pattern matching:** Regex patterns detect and redact known credential formats
2. **Key-based filtering:** Array keys matching sensitive names are automatically redacted

### Data Protected

**API Keys & Tokens:**
- OpenAI keys (`sk-...`)
- Anthropic keys (`sk-ant-...`)
- AWS access keys (`AKIA...`)
- Bearer tokens
- Generic API keys/tokens

**Credentials:**
- Passwords (password, passwd, pwd)
- Database passwords
- Client secrets
- Private keys

**Other:**
- Basic auth headers
- URLs with embedded credentials
- File paths (converted to relative)

### Public Methods

| Method | Parameters | Return | Description |
|--------|-----------|--------|-------------|
| `sanitizeString()` | `string $input` | `string` | Redacts sensitive patterns from string |
| `sanitizeArray()` | `array $data, int $maxDepth` | `array` | Recursively sanitizes array values |
| `sanitizeObject()` | `object $obj, int $maxDepth` | `array` | Converts object to sanitized array |
| `sanitizeException()` | `\Throwable $exception` | `array` | Safe exception data for logging |
| `forLogging()` | `mixed $data` | `mixed` | Main entry point for any data |

### Pipeline Integration
- **Used by:** `Neo4jStore.php` (retry logging), `DataIngestionService.php` (error logging)
- **Purpose:** Ensures API keys never appear in logs

### Security Effectiveness: HIGH
- Comprehensive pattern coverage for common credential formats
- Recursion depth limit prevents DoS
- Stack trace sanitization removes argument data
- Path sanitization prevents directory structure leakage

### Unused Methods
None - all methods are part of the sanitization API.

### Notes/Anomalies
1. **AWS secret pattern too broad:** Pattern `([A-Za-z0-9+\/]{40})` matches any 40-char base64 string - high false positive rate
2. **No custom pattern support:** Patterns are hardcoded, no config extension
3. **Static methods only:** No instance state - could be a trait or set of functions
4. **Trace limit:** Only keeps 10 stack frames - may lose context for deep call stacks

---

## 4. TeamFilteredQuery.php

### Purpose
Builds team-filtered queries for Neo4j and Qdrant. Enables native database-level security filtering rather than post-processing.

### Security Mechanism
- Constructs queries that filter results to user's accessible teams
- Supports both Qdrant vector filter format and Cypher WHERE clauses
- Provides owner-bypass for personal data access

### Data Protected
- All entity data is filtered to team boundaries
- Prevents cross-team data access at query level

### Public Methods

| Method | Parameters | Return | Description |
|--------|-----------|--------|-------------|
| `__construct()` | `array $teamIds, ?int $ownerId` | - | Initialize with team/owner context |
| `toQdrantFilter()` | - | `array` | Build Qdrant filter structure |
| `toCypherWhereClause()` | `string $nodeAlias` | `string` | Build Cypher WHERE clause |
| `toCypherMatchClause()` | `string $label, string $nodeAlias` | `string` | Build full MATCH clause |
| `countInNeo4j()` | `GraphStoreInterface $graph, string $label, array $filters` | `int` | Count nodes with team filter |
| `searchQdrant()` | `VectorStoreInterface $vectorStore, ...` | `array` | Search with team filter |
| `globalCount()` | `GraphStoreInterface $graph, string $label` | `int` | Count without team filter (static) |
| `applyThreshold()` | `int $count, int $threshold` | `string\|int` | Apply threshold protection |
| `getTeamIds()` | - | `array` | Get team IDs |
| `getOwnerId()` | - | `?int` | Get owner ID |
| `hasFilters()` | - | `bool` | Check if filtering is active |

### Pipeline Integration
- Standalone utility class
- **Not currently integrated** into main pipeline (grep shows only self-reference)
- Intended for use by RAG context retrieval

### Security Effectiveness: COMPROMISED

#### CRITICAL VULNERABILITY: Cypher Injection (Confirmed from Task 10)

**Location 1: `countInNeo4j()` method (lines 130-157)**
```php
$cypher = "MATCH (n:{$label})";  // NO SANITIZATION

foreach ($filters as $field => $value) {
    $filterClauses[] = "n.{$field} = \${$field}";  // NO SANITIZATION
}
```

**Location 2: `globalCount()` method (lines 189-193)**
```php
$cypher = "MATCH (n:{$label}) RETURN count(n) as count";  // NO SANITIZATION
```

**Location 3: `toCypherMatchClause()` method (lines 110-119)**
```php
$match = "MATCH ({$nodeAlias}:{$label})";  // NO SANITIZATION
```

**Attack Vector:**
If `$label` or `$field` contains malicious Cypher, it will be executed:
```php
// Malicious label
$query = new TeamFilteredQuery([1]);
$query->countInNeo4j($graph, "Person) MATCH (x) DETACH DELETE x //", []);
// Results in: MATCH (n:Person) MATCH (x) DETACH DELETE x //)-[:BELONGS_TO_TEAM]...
```

**Why CypherSanitizer is NOT used:**
- `CypherSanitizer` exists in `src/GraphStore/`
- It is only used within `Neo4jStore.php` CRUD methods
- External callers like `TeamFilteredQuery` bypass sanitization

### Unused Methods
- `searchQdrant()` - No references found
- `toCypherWhereClause()` - No references found
- `applyThreshold()` - No references found (though `AccessLevelResolver` has similar logic)

### Notes/Anomalies
1. **Constructor sanitizes teamIds:** `array_map('intval', array_filter($teamIds))` prevents injection via team IDs - good
2. **Qdrant filter is safe:** Array structure prevents injection in `toQdrantFilter()`
3. **Unused in pipeline:** Despite being a security service, grep shows no external usage
4. **Duplicated threshold logic:** `applyThreshold()` duplicates logic from `AccessLevelResolver`

---

## 5. Cross-Reference: Task 10 Findings

Task 10 (Phase 2 Graph Store audit) identified the Cypher injection risk in `TeamFilteredQuery.php`. This audit confirms:

| Finding | Task 10 | Task 32 (Current) | Status |
|---------|---------|-------------------|--------|
| `countInNeo4j()` label injection | Identified | Confirmed | **UNFIXED** |
| `countInNeo4j()` field injection | Identified | Confirmed | **UNFIXED** |
| `globalCount()` label injection | Identified | Confirmed | **UNFIXED** |
| `toCypherMatchClause()` injection | Identified | Confirmed | **UNFIXED** |

**Recommended Fix (from Task 10):**
```php
// In countInNeo4j() and globalCount():
$safeLabel = CypherSanitizer::escapeLabel($label);

// In countInNeo4j() filter loop:
$safeField = CypherSanitizer::validatePropertyKey($field);
```

---

## 6. Security Architecture Analysis

### Positive Patterns

1. **Defense-in-depth:** Multiple layers of protection
   - Database-level (TeamFilteredQuery)
   - Prompt-level (PromptContextBuilder)
   - Log-level (SensitiveDataSanitizer)

2. **Principle of least privilege:** Access tags start minimal and expand with permissions

3. **Threshold protection:** Prevents statistical re-identification attacks

4. **Config-driven:** Thresholds and identifying fields configurable per entity

### Security Gaps

1. **Cypher injection:** Critical vulnerability in TeamFilteredQuery (documented above)

2. **Incomplete integration:** PromptContextBuilder appears unused in main pipeline

3. **No input validation layer:** Security services don't form a cohesive validation pipeline

4. **Missing rate limiting:** No protection against brute-force permission probing

### Recommended Architecture Improvements

1. **Enforce sanitization at query layer:**
   ```php
   // In GraphStoreInterface or Neo4jStore::query()
   public function query(string $cypher, array $parameters, bool $sanitized = false): array
   {
       if (!$sanitized) {
           throw new \InvalidArgumentException('Queries must be marked as sanitized');
       }
       // ...
   }
   ```

2. **Create CypherQueryBuilder:**
   ```php
   class CypherQueryBuilder
   {
       public function match(string $label): self
       {
           $this->clauses[] = 'MATCH (n:' . CypherSanitizer::escapeLabel($label) . ')';
           return $this;
       }
       // Forces sanitization through builder pattern
   }
   ```

3. **Integrate PromptContextBuilder:** Connect to chat/RAG pipeline for consistent access control

---

## 7. Summary

### Files Status

| File | Lines | Security Risk | Integration |
|------|-------|---------------|-------------|
| AccessLevelResolver.php | 246 | LOW | Active (singleton) |
| PromptContextBuilder.php | 286 | LOW | Unused? |
| SensitiveDataSanitizer.php | 265 | LOW | Active (logging) |
| TeamFilteredQuery.php | 243 | **CRITICAL** | Unused? |

### Critical Actions Required

1. **IMMEDIATE:** Fix Cypher injection in `TeamFilteredQuery.php` by adding `CypherSanitizer` calls
2. **HIGH:** Verify if `PromptContextBuilder` and `TeamFilteredQuery` are intentionally unused or missing integration
3. **MEDIUM:** Add custom pattern support to `SensitiveDataSanitizer`
4. **LOW:** Add request-scoped caching to `AccessLevelResolver`

### Test Coverage Needed

```
tests/Unit/Services/Security/
  - AccessLevelResolverTest.php (access tier verification)
  - PromptContextBuilderTest.php (redaction verification)
  - SensitiveDataSanitizerTest.php (pattern coverage)
  - TeamFilteredQueryTest.php (exists - verify injection tests)
```

---

## Appendix: File Locations

- `src/Services/Security/AccessLevelResolver.php`
- `src/Services/Security/PromptContextBuilder.php`
- `src/Services/Security/SensitiveDataSanitizer.php`
- `src/Services/Security/TeamFilteredQuery.php`
- `src/GraphStore/CypherSanitizer.php` (related - provides injection protection)
- `src/Exceptions/CypherInjectionException.php` (related - thrown on injection detection)
