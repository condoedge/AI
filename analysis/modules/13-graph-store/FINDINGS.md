# Module 13: GRAPH_STORE - Findings

> **Status:** COMPLETE

## Issues Found
| ID | Severity | Description | Evidence | Recommendation |
|----|----------|-------------|----------|----------------|
| GS-001 | **CRITICAL** | Duplicate CypherSanitizer with DIFFERENT security behaviors | `src/GraphStore/CypherSanitizer.php` throws exceptions; `src/Services/Security/CypherSanitizer.php` silently strips characters | Delete Services/Security version; use GraphStore version everywhere |
| GS-002 | **HIGH** | QueryGenerator uses weaker sanitizer | `QueryGenerator.php:12` imports `Services\Security\CypherSanitizer` | Change import to `GraphStore\CypherSanitizer`; update tests |
| GS-003 | MEDIUM | HTTP basic auth credentials in memory | `Neo4jStore.php:39-40` stores username/password as plain strings | Consider using secure credential store or environment vars with lazy loading |
| GS-004 | LOW | Hardcoded HTTP port | `Neo4jStore.php:46` assumes HTTP port 7474 | Make configurable via `ai.neo4j.http_port` |
| GS-005 | INFO | Deprecated method still exists | `Neo4jStore.php:645-659` `arrayToCypherProps()` marked deprecated but not removed | Track deprecation; remove in next major version |

---

## CRITICAL ISSUE DETAIL: GS-001

### Problem: Two CypherSanitizer Classes with Different Security Postures

**Location 1:** `src/GraphStore/CypherSanitizer.php`
- Namespace: `Condoedge\Ai\GraphStore`
- Lines: 177
- Behavior: **STRICT** - validates input and throws `CypherInjectionException` on invalid input
- Features:
  - Reserved keyword blocking (MATCH, DELETE, CREATE, etc.)
  - Length validation (max 255 chars)
  - Backtick quoting defense-in-depth
  - Unicode/null byte protection
  - Comprehensive test suite (27 tests)

**Location 2:** `src/Services/Security/CypherSanitizer.php`
- Namespace: `Condoedge\Ai\Services\Security`
- Lines: 97
- Behavior: **PERMISSIVE** - strips invalid characters silently, never throws
- Features:
  - Strips non-alphanumeric chars
  - Prefixes with `Invalid_` for empty results
  - Forces uppercase for relationship types
  - Has `containsDangerousPatterns()` helper (unused)
  - Limited test coverage (6 tests expecting silent behavior)

### Security Implications

The Services/Security version is **LESS SECURE** because:

1. **Silent failures hide attacks**: An injection attempt like `User}); DELETE (n) //` becomes `UserDELETEn` - the attack is stripped but the system proceeds with a potentially unexpected label.

2. **No reserved keyword protection**: Label `DELETE` would be allowed, potentially causing confusion or issues.

3. **No length limits**: Could be used for DoS via extremely long labels.

4. **Inconsistent behavior**: Two callers get different behavior for the same input - one throws, one succeeds with modified data.

### Root Cause

Likely copy-paste evolution: one version was created for Neo4j operations (strict), another for QueryGenerator (permissive). They diverged independently.

### References to Each Version

**GraphStore version (authoritative):**
```
src/GraphStore/Neo4jStore.php (13 references) - in-namespace, no import needed
tests/Unit/Security/CypherInjectionTest.php - explicit import
```

**Services/Security version (should be removed):**
```
src/Services/QueryGenerator.php:12 - explicit import
tests/Unit/Services/QueryGeneratorSecurityTest.php:10 - explicit import
```

---

## Neo4jStore.php Analysis

### Positive Findings

1. **Implements GraphStoreInterface** - Proper contract adherence
2. **Connection pooling** - cURL handle reuse for performance (`getCurlHandle()`)
3. **Retry policy** - Uses `RetryPolicy::forDatabaseOperations()` for transient failures
4. **Circuit breaker** - Prevents cascading failures with 5-failure threshold
5. **Parameter binding** - Uses `$parameters` for query values, not string interpolation
6. **Transaction support** - Full `beginTransaction()`, `commit()`, `rollback()` API
7. **Sensitive data sanitization** - Uses `SensitiveDataSanitizer::forLogging()` for logs

### Architecture Notes

- Uses Neo4j HTTP API (not Bolt protocol) - see recommendation in docblock
- Converts `bolt://` URIs to `http://` for API access
- HTTP port hardcoded to 7474
- 30-second query timeout, 5-second connect timeout
- TCP keep-alive enabled for connection reuse

### Methods

| Method | Purpose | Security |
|--------|---------|----------|
| `createNode()` | Create node with label/properties | Label escaped via CypherSanitizer |
| `updateNode()` | Update node properties | Label + property keys escaped |
| `deleteNode()` | Delete node and relationships | Label escaped |
| `createRelationship()` | Create typed relationship | Labels + type escaped |
| `deleteRelationship()` | Delete relationship | Labels + type escaped |
| `query()` | Execute raw Cypher | Uses parameter binding |
| `nodeExists()` | Check node existence | Label escaped |
| `relationshipExists()` | Check relationship existence | Labels + type escaped |
| `getNode()` | Retrieve node by ID | Label escaped |
| `getSchema()` | Get database schema | Safe (uses CALL procedures) |
| `beginTransaction()` | Start transaction | Safe |
| `commit()` | Commit transaction | Safe |
| `rollback()` | Rollback transaction | Safe |
| `queryInTransaction()` | Query within transaction | Uses parameter binding |

---

## Test Coverage

| Test File | Target | Tests |
|-----------|--------|-------|
| `tests/Unit/Security/CypherInjectionTest.php` | GraphStore\CypherSanitizer | 27 tests |
| `tests/Unit/Services/QueryGeneratorSecurityTest.php` | Services\Security\CypherSanitizer | 6 tests |

The CypherInjectionTest provides excellent coverage of attack vectors including:
- Command injection (DELETE, DROP)
- Comment injection (//, /*)
- Quote/backtick escaping
- Unicode exploits
- Null byte injection
- Path traversal
- Reserved keyword abuse
- Length-based DoS
