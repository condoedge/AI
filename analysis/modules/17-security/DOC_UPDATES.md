# Module 17: SECURITY - Documentation Updates

> **Status:** COMPLETE

## Documentation Needs Identified

### 1. CypherSanitizer Usage Guide (HIGH PRIORITY)

**What:** Create clear documentation on which CypherSanitizer to use and when.

**Location:** `docs/security/cypher-sanitization.md`

**Content:**
- Explain that `GraphStore\CypherSanitizer` is the authoritative version
- Document the fail-fast approach (throws exceptions)
- Examples of proper usage for labels, relationship types, property keys
- Migration guide from Security version

**Sample:**
```markdown
# Cypher Query Sanitization

## Authoritative Implementation
Use `Condoedge\Ai\GraphStore\CypherSanitizer` for all Cypher query sanitization.

## Key Methods
- `validateLabel($label)` - Validates label, throws CypherInjectionException if invalid
- `escapeLabel($label)` - Validates AND wraps in backticks
- `validateRelationshipType($type)` - Validates relationship type
- `validatePropertyKey($key)` - Validates property key

## Example
```php
use Condoedge\Ai\GraphStore\CypherSanitizer;

$safeLabel = CypherSanitizer::escapeLabel($userInput); // Returns `ValidLabel`
$cypher = "MATCH (n:{$safeLabel}) RETURN n";
```
```

---

### 2. Access Level Documentation (MEDIUM PRIORITY)

**What:** Document the 5-level access control system.

**Location:** `docs/security/access-levels.md`

**Content:**
- Level 0-4 descriptions with examples
- How thresholds work
- sensibleColumns integration
- Permission mapping to Kompo Auth

**Sample:**
```markdown
# Access Level System

| Level | Tag Suffix | Description | Required Permission |
|-------|-----------|-------------|---------------------|
| 0 | `_global_count` | Total counts | None (public) |
| 1 | `_team_count` | Team counts | Team membership |
| 2 | `_team_filtered_count` | Filtered counts | READ on entity |
| 3 | `_team_details` | Record data | READ on entity |
| 4 | `_team_sensitive` | Sensitive fields | READ on entity.sensibleColumns |
```

---

### 3. SensitiveDataSanitizer API Reference (LOW PRIORITY)

**What:** Document the logging sanitizer for developers.

**Location:** `docs/security/sensitive-data-sanitization.md`

**Content:**
- Protected patterns list
- How to use `forLogging()` method
- Extending with custom patterns
- Known limitations (AWS pattern breadth)

---

### 4. TeamFilteredQuery Usage (MEDIUM PRIORITY)

**What:** Document team-based query filtering.

**Location:** `docs/security/team-filtering.md`

**Content:**
- Qdrant filter structure
- Cypher clause generation
- Threshold application
- WARNING about sanitizing inputs before calling methods

---

### 5. Security Best Practices Guide (HIGH PRIORITY)

**What:** Overall security guide for AI chat system.

**Location:** `docs/security/best-practices.md`

**Content:**
- Always use CypherSanitizer for user input in queries
- Never interpolate raw user input into Cypher
- Use PromptContextBuilder for AI prompt construction
- Log with SensitiveDataSanitizer
- Apply threshold protection for counts

---

## Inline Documentation Updates Needed

### AccessLevelResolver.php

Add class-level docblock explaining:
- The 5-level access system
- Integration with Kompo Auth
- Threshold protection rationale

### PromptContextBuilder.php

Add docblock explaining:
- How access rules are injected into prompts
- The redaction mechanism for sensitive content
- Limitations of prompt-based security

### TeamFilteredQuery.php

**CRITICAL:** Add warnings about input sanitization:
```php
/**
 * @param string $label Node label - MUST be validated/sanitized before passing
 *                      Use CypherSanitizer::escapeLabel() to prevent injection
 */
public function countInNeo4j(GraphStoreInterface $graph, string $label, array $filters = []): int
```

---

## README Updates

Add security section to main README:

```markdown
## Security

### Cypher Injection Prevention
All user input interpolated into Cypher queries MUST be sanitized using `GraphStore\CypherSanitizer`.

### Access Control
The system uses 5-level access control. See `docs/security/access-levels.md`.

### Logging
Use `SensitiveDataSanitizer::forLogging()` for all data that might contain secrets.
```

---

## Migration Documentation

Create `docs/migration/cyphersanitizer-consolidation.md`:

```markdown
# CypherSanitizer Consolidation Migration

## Background
Two CypherSanitizer classes existed:
- `Services\Security\CypherSanitizer` (deprecated)
- `GraphStore\CypherSanitizer` (authoritative)

## Changes Required

### In QueryGenerator.php
Change:
```php
use Condoedge\Ai\Services\Security\CypherSanitizer;
```
To:
```php
use Condoedge\Ai\GraphStore\CypherSanitizer;
```

### Behavior Change
The GraphStore version throws `CypherInjectionException` for invalid input instead of silently sanitizing. Update error handling accordingly.

### Tests
Update test imports similarly. Tests expecting silent sanitization should expect exceptions instead.
```
