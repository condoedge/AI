# Module 13: GRAPH_STORE - Documentation Updates

> **Status:** COMPLETE

## Required Documentation Changes

### 1. Remove Duplicate CypherSanitizer

**Action:** Delete `src/Services/Security/CypherSanitizer.php`

This file is a duplicate with weaker security posture. The authoritative version is `src/GraphStore/CypherSanitizer.php`.

### 2. Update QueryGenerator Import

**File:** `src/Services/QueryGenerator.php`

**Current (line 12):**
```php
use Condoedge\Ai\Services\Security\CypherSanitizer;
```

**Change to:**
```php
use Condoedge\Ai\GraphStore\CypherSanitizer;
```

### 3. Update QueryGeneratorSecurityTest

**File:** `tests/Unit/Services/QueryGeneratorSecurityTest.php`

**Current (line 10):**
```php
use Condoedge\Ai\Services\Security\CypherSanitizer;
```

**Change to:**
```php
use Condoedge\Ai\GraphStore\CypherSanitizer;
```

**Additionally:** Tests must be updated to expect `CypherInjectionException` instead of silent sanitization. Example:

**Current test behavior:**
```php
$result = CypherSanitizer::escapeLabel('Customer})-[:HACKED]->(');
$this->assertStringNotContainsString('}', $result);  // Expects modified string
```

**Should become:**
```php
$this->expectException(CypherInjectionException::class);
CypherSanitizer::escapeLabel('Customer})-[:HACKED]->(');  // Expects exception
```

### 4. Add HTTP Port Configuration

**File:** `config/ai.php`

Add under `neo4j` section:
```php
'neo4j' => [
    'uri' => env('NEO4J_URI', 'bolt://localhost:7687'),
    'username' => env('NEO4J_USERNAME', 'neo4j'),
    'password' => env('NEO4J_PASSWORD', 'password'),
    'database' => env('NEO4J_DATABASE', 'neo4j'),
    'http_port' => env('NEO4J_HTTP_PORT', 7474),  // Add this
],
```

**File:** `src/GraphStore/Neo4jStore.php`

Update constructor to use config:
```php
$httpPort = $config['http_port'] ?? 7474;
```

### 5. API Documentation Note

**File:** `resources/docs/1.0/internals/resilience.md` (already exists)

Already correctly references:
> All labels, relationship types, and property keys pass through `CypherSanitizer::validate*`.

No change needed here.

---

## Summary of Changes

| File | Action | Priority |
|------|--------|----------|
| `src/Services/Security/CypherSanitizer.php` | DELETE | CRITICAL |
| `src/Services/QueryGenerator.php` | Update import | HIGH |
| `tests/Unit/Services/QueryGeneratorSecurityTest.php` | Update import + test expectations | HIGH |
| `config/ai.php` | Add `http_port` config | LOW |
| `src/GraphStore/Neo4jStore.php` | Use config for http_port | LOW |
