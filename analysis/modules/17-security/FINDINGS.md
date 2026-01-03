# Module 17: SECURITY - Findings

> **Status:** COMPLETE

## Executive Summary

The security module provides multi-layered protection against Cypher injection, access control enforcement, and sensitive data leakage. However, a **critical issue** exists: **duplicate CypherSanitizer classes** with different implementations that must be resolved.

---

## CRITICAL ISSUE: Duplicate CypherSanitizer

### Location of Duplicates

1. **`src/Services/Security/CypherSanitizer.php`** (Security version)
   - Namespace: `Condoedge\Ai\Services\Security`
   - 97 lines

2. **`src/GraphStore/CypherSanitizer.php`** (GraphStore version)
   - Namespace: `Condoedge\Ai\GraphStore`
   - 177 lines

### Implementation Comparison

| Feature | Security Version | GraphStore Version |
|---------|-----------------|-------------------|
| **Approach** | Sanitize/strip dangerous chars | Validate/reject + throw exception |
| **Label handling** | `escapeLabel()` strips chars | `validateLabel()` throws CypherInjectionException |
| **Empty input** | Returns `Invalid_` prefix | Throws exception |
| **Invalid input** | Silently fixes | Throws CypherInjectionException |
| **Reserved keywords** | Not checked | Blocks MATCH, DELETE, etc. |
| **Length limits** | No limit | 255 char max (DoS protection) |
| **Backtick quoting** | No | Yes (defense-in-depth) |
| **Pattern check** | `containsDangerousPatterns()` | Not present |

### Which Is Used Where?

**Security Version (`Services\Security\CypherSanitizer`):**
- `src/Services/QueryGenerator.php` (line 12, 392)
- `tests/Unit/Services/QueryGeneratorSecurityTest.php` (line 10)

**GraphStore Version (`GraphStore\CypherSanitizer`):**
- `src/GraphStore/Neo4jStore.php` (lines 69, 87, 102, 120-122, 150-152, 211, 227-229, 245, 655, 669)
- `tests/Unit/Security/CypherInjectionTest.php` (line 8)

### RECOMMENDATION: Keep GraphStore Version

**The GraphStore version is authoritative and should be the single source.**

**Reasoning:**
1. **Fail-fast security**: Throwing exceptions is safer than silent sanitization
2. **More comprehensive**: Includes reserved keyword blocking, length limits
3. **Defense-in-depth**: Uses backtick quoting even after validation
4. **Production use**: Used by Neo4jStore for all database operations
5. **Test coverage**: Has dedicated injection test suite (CypherInjectionTest)

**Migration Steps:**
1. Update `QueryGenerator.php` to use `Condoedge\Ai\GraphStore\CypherSanitizer`
2. Update tests to use GraphStore version
3. Delete `src/Services/Security/CypherSanitizer.php`
4. Update any imports that reference the Security version

**Impact:**
- `QueryGenerator::generateFromTemplate()` will now throw exceptions instead of silently sanitizing
- This is correct behavior: invalid labels should be rejected, not "fixed"

---

## Issues Found

| ID | Severity | Description | Evidence | Recommendation |
|----|----------|-------------|----------|----------------|
| SEC-001 | CRITICAL | Duplicate CypherSanitizer classes with conflicting implementations | Two files: `Services/Security/CypherSanitizer.php` and `GraphStore/CypherSanitizer.php` | Keep GraphStore version, delete Security version, update imports |
| SEC-002 | HIGH | TeamFilteredQuery doesn't sanitize label/field parameters | `countInNeo4j()` line 132: `$cypher = "MATCH (n:{$label})"` - no sanitization | Add CypherSanitizer calls for $label and $field |
| SEC-003 | MEDIUM | AccessLevelResolver instantiates models without class validation | `resolveModel()` at line 217 calls `new $entityClass` | Validate against allowed namespaces before instantiation |
| SEC-004 | LOW | AWS secret regex pattern too broad | Pattern `([A-Za-z0-9+\/]{40})` in SensitiveDataSanitizer | Narrow pattern or add context requirements |
| SEC-005 | LOW | No permission caching in AccessLevelResolver | Multiple `hasPermission()` calls without caching | Add short-term caching for permission lookups |

---

## File Analysis

### 1. AccessLevelResolver.php

**Purpose:** Resolves multi-level access tags for RAG queries based on user permissions.

**Access Levels:**
- Level 0: `global_count` - Total counts (public)
- Level 1: `team_count` - Team member counts
- Level 2: `team_filtered_count` - Filtered counts (READ permission)
- Level 3: `team_details` - Record data excluding sensibleColumns
- Level 4: `team_sensitive` - Full data including sensibleColumns

**Security Properties:**
- Integrates with Kompo Auth via `hasPermission()` checks
- Team-based isolation via `getUserTeamIds()`
- Threshold protection for filtered counts (prevents identifying individuals)
- sensibleColumns integration for field-level access control

**Potential Gaps:**
- No caching of permission lookups (potential performance issue under load)
- `resolveModel()` instantiates models without sanitization of `$entityClass`

### 2. PromptContextBuilder.php

**Purpose:** Builds access-aware prompt context for RAG queries.

**Security Properties:**
- Injects access control instructions into AI prompts
- Filters sensitive content from semantic results
- Redacts sensibleColumns for users without `team_sensitive` access
- Threshold rules embedded in prompts

**Protection Against:**
- AI revealing unauthorized data through prompt injection
- Sensitive field exposure in RAG context

**Gaps:**
- Redaction relies on regex patterns that may miss edge cases
- AI could potentially be manipulated to reveal redacted info through indirect questions

### 3. SensitiveDataSanitizer.php

**Purpose:** Removes/masks sensitive information from logs and exceptions.

**Patterns Protected:**
- API keys (OpenAI `sk-`, Anthropic `sk-ant-`, generic)
- Bearer tokens
- Passwords/secrets
- AWS credentials (AKIA...)
- Basic Auth headers
- URL credentials

**Methods:**
- `sanitizeString()` - Pattern-based redaction
- `sanitizeArray()` - Recursive key-based redaction
- `sanitizeException()` - Safe exception logging
- `forLogging()` - Main entry point

**Gaps:**
- AWS secret pattern `([A-Za-z0-9+\/]{40})` is too broad (false positives)
- No protection for custom API key formats
- Stack trace limited to 10 frames (may hide relevant info)

### 4. TeamFilteredQuery.php

**Purpose:** Builds team-filtered queries for Neo4j and Qdrant.

**Security Properties:**
- Native database-level filtering (not post-processing)
- Uses parameterized queries for IDs
- Owner-bypass support via `ownerId`
- Threshold application via `applyThreshold()`

**Methods:**
- `toQdrantFilter()` - Qdrant filter structure with team/owner OR logic
- `toCypherWhereClause()` - Cypher WHERE with team relationships
- `countInNeo4j()` - Team-filtered counts

**SECURITY CONCERN in TeamFilteredQuery:**
```php
// Line 132: Label is NOT sanitized!
$cypher = "MATCH (n:{$label})";

// Line 141-143: Field names are NOT sanitized!
foreach ($filters as $field => $value) {
    $filterClauses[] = "n.{$field} = \${$field}";
}
```

**This is a potential injection vector** if `$label` or `$field` come from user input.

### 5. CypherSanitizer.php (Security version)

**Purpose:** Sanitize Cypher query components (labels, properties, relationship types).

**Methods:**
- `escapeLabel()` - Strips non-alphanumeric, ensures valid start
- `escapeProperty()` - Alias for escapeLabel
- `escapeRelationshipType()` - Uppercase label escaping
- `containsDangerousPatterns()` - Detects DELETE, DROP, etc.

**Issues:**
- Silent sanitization instead of failing
- No reserved keyword protection
- No length limits
- Should be deprecated in favor of GraphStore version

---

## Security Gaps Identified

### HIGH Priority

1. **Duplicate CypherSanitizer** - Must resolve to single authoritative implementation
2. **TeamFilteredQuery injection** - `$label` and `$field` parameters not sanitized in `countInNeo4j()` and `toCypherMatchClause()`

### MEDIUM Priority

3. **AccessLevelResolver model instantiation** - `resolveModel()` creates instances without validating class names
4. **Prompt injection potential** - AI could be manipulated to reveal redacted data through indirect questioning

### LOW Priority

5. **AWS secret pattern too broad** - May cause false positive redactions
6. **No permission caching** - Repeated permission checks may impact performance

---

## Test Coverage Analysis

**Well-tested:**
- CypherInjectionTest.php (30 test cases for GraphStore sanitizer)
- QueryGeneratorSecurityTest.php (tests template injection protection)

**Missing tests:**
- TeamFilteredQuery injection scenarios
- AccessLevelResolver edge cases
- PromptContextBuilder redaction bypass attempts
- SensitiveDataSanitizer false positive/negative cases

---

## Recommendations

### Immediate Actions

1. **Resolve CypherSanitizer duplicate:**
   - Keep `src/GraphStore/CypherSanitizer.php`
   - Update `QueryGenerator.php` imports
   - Delete `src/Services/Security/CypherSanitizer.php`

2. **Fix TeamFilteredQuery injection:**
   ```php
   // Add to countInNeo4j() and related methods:
   $safeLabel = CypherSanitizer::escapeLabel($label);
   $safeField = CypherSanitizer::validatePropertyKey($field);
   ```

### Short-term

3. Add permission caching to AccessLevelResolver
4. Add tests for TeamFilteredQuery with malicious inputs
5. Review and narrow AWS secret pattern

### Long-term

6. Consider AI prompt hardening against indirect data extraction
7. Implement audit logging for sensitive data access
8. Add rate limiting for permission-intensive operations
