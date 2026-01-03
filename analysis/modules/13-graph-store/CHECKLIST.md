# Module 13: GRAPH_STORE - Checklist

- [x] Read `src/GraphStore/Neo4jStore.php`
- [x] Read `src/GraphStore/CypherSanitizer.php`
- [x] Compare with `src/Services/Security/CypherSanitizer.php` (DUPLICATE CHECK)
- [x] Verify interface compliance
- [x] Check connection handling
- [x] Verify query execution

## Summary

**Files Analyzed:**
- `src/GraphStore/Neo4jStore.php` (687 lines) - Neo4j HTTP API client with retry/circuit breaker
- `src/GraphStore/CypherSanitizer.php` (177 lines) - Strict validation-based sanitizer with exception throwing
- `src/Services/Security/CypherSanitizer.php` (97 lines) - Permissive strip-based sanitizer

## CRITICAL: Duplicate CypherSanitizer Analysis

### Two Different Implementations With Different Behaviors:

| Aspect | GraphStore Version | Services/Security Version |
|--------|-------------------|---------------------------|
| Namespace | `Condoedge\Ai\GraphStore` | `Condoedge\Ai\Services\Security` |
| Approach | **Strict validation + throws exceptions** | **Permissive stripping + silent sanitization** |
| On invalid input | Throws `CypherInjectionException` | Strips bad chars, returns modified string |
| Empty string handling | Throws exception | Returns `Invalid_` prefix |
| Reserved keywords | Blocks them | Does not check |
| Backtick quoting | Applies defense-in-depth | Does not apply |
| Length validation | Enforces 255 char max | No limit |
| Test coverage | Comprehensive (CypherInjectionTest) | Basic (QueryGeneratorSecurityTest) |

### Usage Analysis:

**GraphStore version used by:**
- `Neo4jStore.php` (13 usages) - Direct reference in same namespace
- `CypherInjectionTest.php` - Dedicated security tests

**Services/Security version used by:**
- `QueryGenerator.php` (1 usage) - Explicit import
- `QueryGeneratorSecurityTest.php` - Tests expect permissive behavior

### RECOMMENDATION: GraphStore version is AUTHORITATIVE

The GraphStore version should be the single source of truth because:
1. **Better security posture** - Throws exceptions rather than silently modifying
2. **More comprehensive** - Validates reserved keywords, length limits, Unicode
3. **Defense in depth** - Applies backtick quoting after validation
4. **Better test coverage** - 27 dedicated injection tests vs 6 basic tests
5. **Used by core Neo4j operations** - All graph operations depend on it

**ACTION REQUIRED:**
- Remove `src/Services/Security/CypherSanitizer.php`
- Update `QueryGenerator.php` to use `Condoedge\Ai\GraphStore\CypherSanitizer`
- Update `QueryGeneratorSecurityTest.php` to expect exception-throwing behavior
