# Phase 2 Audit: Graph Store

**Audit Date:** 2025-12-30
**Files Reviewed:** 2
**Directory:** `src/GraphStore/`

---

## 1. CypherSanitizer.php

### Purpose
Provides validation and escaping for Cypher query identifiers (labels, relationship types, property keys) to prevent injection attacks. Neo4j does not support parameterized labels/types/property keys, so this class validates them against strict patterns before interpolation.

### Public Methods

| Method | Parameters | Return Type | Description |
|--------|-----------|-------------|-------------|
| `validateLabel()` | `string $label` | `string` | Validates a node label name |
| `validateRelationshipType()` | `string $type` | `string` | Validates a relationship type |
| `validatePropertyKey()` | `string $key` | `string` | Validates a property key |
| `validateIdentifiers()` | `array $identifiers, string $type` | `array` | Validates multiple identifiers in batch |
| `escapeLabel()` | `string $label` | `string` | Validates and escapes label with backtick quoting |
| `escapeRelationshipType()` | `string $type` | `string` | Validates and escapes type with backtick quoting |

### Security Validation Rules

1. **Pattern Check:** `/^[a-zA-Z_][a-zA-Z0-9_]*$/`
   - Must start with letter or underscore
   - Only alphanumeric and underscores allowed
   - No spaces, special characters, or Unicode

2. **Length Limit:** Maximum 255 characters (DoS prevention)

3. **Reserved Keywords:** Rejects Cypher reserved keywords (MATCH, CREATE, DELETE, etc.)

4. **Defense-in-Depth:** Escape methods add backtick quoting after validation

### Dependencies
- `Condoedge\Ai\Exceptions\CypherInjectionException`

### Usage Locations
Only used within `Neo4jStore.php` - **NOT used by other services that construct Cypher queries**.

### Security Notes
- **POSITIVE:** All static methods, immutable class
- **POSITIVE:** Strict allowlist pattern, rejects reserved keywords
- **POSITIVE:** Defense-in-depth with backtick escaping
- **POSITIVE:** Length limit prevents DoS attacks
- **ISSUE:** `escapeLabel()` and `escapeRelationshipType()` escape backticks AFTER validation that disallows backticks - the escape step is redundant but harmless

---

## 2. Neo4jStore.php

### Purpose
Neo4j graph database implementation via HTTP API. Provides node/relationship CRUD operations with retry logic, circuit breaker pattern, and connection pooling.

### Interface Implementation
Implements `GraphStoreInterface` (verified).

### Public Methods

| Method | Parameters | Return Type | Description |
|--------|-----------|-------------|-------------|
| `__construct()` | `?array $config` | - | Initializes connection from config or `config('ai.neo4j')` |
| `createNode()` | `string $label, array $properties` | `string\|int` | Creates node with label and properties |
| `updateNode()` | `string $label, string\|int $id, array $properties` | `bool` | Updates node properties |
| `deleteNode()` | `string $label, string\|int $id` | `bool` | Deletes node with DETACH |
| `createRelationship()` | `string $fromLabel, string\|int $fromId, string $toLabel, string\|int $toId, string $type, array $properties` | `bool` | Creates/merges relationship |
| `deleteRelationship()` | `string $fromLabel, string\|int $fromId, string $toLabel, string\|int $toId, string $type` | `bool` | Deletes relationship |
| `query()` | `string $cypher, array $parameters` | `array` | Executes Cypher query |
| `getSchema()` | - | `array` | Returns labels, relationship types, property keys |
| `nodeExists()` | `string $label, string\|int $id` | `bool` | Checks if node exists |
| `relationshipExists()` | `string $fromLabel, string\|int $fromId, string $toLabel, string\|int $toId, string $type` | `bool` | Checks if relationship exists |
| `getNode()` | `string $label, string\|int $id` | `?array` | Gets node properties |
| `beginTransaction()` | - | `array` | Starts Neo4j transaction |
| `commit()` | `$transaction` | `bool` | Commits transaction |
| `rollback()` | `$transaction` | `bool` | Rolls back transaction |
| `queryInTransaction()` | `$transaction, string $cypher, array $parameters` | `array` | Executes query within transaction |
| `testConnection()` | - | `bool` | Tests connection with simple query |

### Dependencies
- `Condoedge\Ai\Contracts\GraphStoreInterface` (implements)
- `Condoedge\Ai\Exceptions\CypherInjectionException`
- `Condoedge\Ai\Services\Resilience\RetryPolicy`
- `Condoedge\Ai\Services\Resilience\CircuitBreaker`
- `Condoedge\Ai\Services\Security\SensitiveDataSanitizer`
- `Illuminate\Support\Facades\Log`

### Sanitization Usage in Neo4jStore
All CRUD methods properly sanitize dynamic identifiers:

```php
// Labels use escapeLabel() - validates AND backtick-quotes
$safeLabel = CypherSanitizer::escapeLabel($label);

// Relationship types use escapeRelationshipType()
$safeType = CypherSanitizer::escapeRelationshipType($type);

// Property keys use validatePropertyKey()
$safeKey = CypherSanitizer::validatePropertyKey($key);
```

### Reference Status

**Called by (via interface or directly):**
- `src/Services/DataIngestionService.php`
- `src/Services/ContextRetriever.php`
- `src/Services/QueryExecutor.php`
- `src/Services/FileSearchService.php`
- `src/Services/Security/TeamFilteredQuery.php`

---

## 3. Security Analysis: Query() Method Usage

### CRITICAL FINDING: Sanitization Bypass in External Callers

The `query()` method accepts raw Cypher strings and does NOT enforce sanitization. While Neo4jStore CRUD methods sanitize properly, external callers construct their own Cypher with varying levels of protection:

### Vulnerability Assessment by Caller

#### DataIngestionService.php - SAFE
```php
$alreadyIngestedIntoGraphsIds = $this->graphStore->query(
    "MATCH (n) WHERE n.id IN $ids RETURN n.id AS id",
    ['ids' => $entityIds]
);
```
- Uses parameterized values (`$ids`)
- No dynamic identifiers

#### ContextRetriever.php - PARTIALLY SAFE
```php
// Has custom isValidLabel() validation
if (!$this->isValidLabel($label)) { throw ... }
$cypher = "MATCH (n:`{$label}`) ...";  // Uses backtick quoting
```
- **ISSUE:** Uses custom `isValidLabel()` with pattern `/^[a-zA-Z_][a-zA-Z0-9_-]*$/`
- Allows HYPHENS which `CypherSanitizer` does NOT allow
- Does NOT check reserved keywords
- Uses backtick quoting (defense-in-depth)

#### QueryExecutor.php - HIGH RISK
```php
$rawResults = $this->graphStore->query($cypherQuery, $parameters);
```
- Executes Cypher passed from external sources
- Has read-only mode check (`containsWriteOperations`)
- **NO identifier sanitization of the Cypher query itself**
- Relies on upstream validation

#### TeamFilteredQuery.php - MEDIUM RISK
```php
// countInNeo4j method:
$cypher = "MATCH (n:{$label})...";  // NO SANITIZATION of $label
foreach ($filters as $field => $value) {
    $filterClauses[] = "n.{$field} = \${$field}";  // NO SANITIZATION of $field
}

// globalCount method:
$cypher = "MATCH (n:{$label}) RETURN count(n) as count";  // NO SANITIZATION
```
- **VULNERABILITY:** Labels and field names interpolated without validation
- No backtick quoting for labels
- Direct injection possible if `$label` or `$field` contains malicious content

#### FileSearchService.php - SAFE
```php
$cypher = $this->buildMetadataQuery($criteria, $limit);
```
- Uses hardcoded property names
- All values are parameterized
- No dynamic identifiers

---

## 4. Security Recommendations

### CRITICAL - Must Fix

1. **TeamFilteredQuery.php** - Add CypherSanitizer validation:
   ```php
   // In countInNeo4j() and globalCount():
   $safeLabel = CypherSanitizer::escapeLabel($label);
   // In countInNeo4j() filter loop:
   $safeField = CypherSanitizer::validatePropertyKey($field);
   ```

2. **toCypherMatchClause()** - Same issue with `$label` parameter

### HIGH - Should Fix

3. **ContextRetriever.php** - Replace custom `isValidLabel()` with `CypherSanitizer::escapeLabel()`:
   - Current pattern allows hyphens
   - No reserved keyword check
   - Inconsistent with rest of codebase

4. **QueryExecutor.php** - Consider adding Cypher syntax validation:
   - Parse and validate the query structure before execution
   - Or restrict to a whitelist of query patterns

### MEDIUM - Consider

5. **Centralize Cypher construction** - Create a CypherQueryBuilder class that enforces sanitization for all dynamic parts

---

## 5. Notes and Anomalies

### Connection Management
- Uses persistent cURL handle for connection pooling
- TCP keep-alive enabled
- Proper cleanup in destructor

### Resilience
- RetryPolicy with exponential backoff for transient failures
- CircuitBreaker to prevent cascading failures (5 failures, 30s recovery)
- SensitiveDataSanitizer used in retry logging

### Transaction Support
- Full transaction support via Neo4j HTTP transaction API
- Proper rollback on failure

### HTTP vs Bolt
- Uses HTTP API (port 7474), not Bolt protocol (7687)
- Comment suggests Bolt for production but HTTP implementation is complete

### Configuration
- Defaults to `bolt://localhost:7687` but converts to HTTP
- Hardcoded HTTP port 7474 (should be configurable)

---

## 6. Summary

| File | Status | Issues |
|------|--------|--------|
| CypherSanitizer.php | GOOD | Well-designed, strict validation |
| Neo4jStore.php | GOOD | Proper sanitization in all CRUD methods |
| External callers | MIXED | TeamFilteredQuery and ContextRetriever have vulnerabilities |

### Overall Assessment
The core graph store implementation is secure. However, the sanitizer is ONLY used within Neo4jStore itself. External services that call `query()` directly can bypass sanitization. This is a design flaw - security should be enforced at the query execution layer, not just in CRUD helpers.

### Recommended Architecture Change
Consider wrapping `query()` to require an explicitly "pre-validated" flag or force all Cypher construction through a builder that enforces sanitization.
