# Module 16: DISCOVERY_SYSTEM - Analysis Plan

> **Module Slug:** discovery-system
> **Priority:** MEDIUM (Auto-discovery from Laravel models)
> **Estimated Files:** 11

## Responsibility
- Auto-discover entities from Laravel models
- Inspect database schemas
- Generate Cypher patterns from scopes
- Discover relationships

## Files
| File | Purpose |
|------|---------|
| `src/Services/Discovery/EntityAutoDiscovery.php` | Main discovery |
| `src/Services/Discovery/SchemaInspector.php` | Schema inspection |
| `src/Services/Discovery/AliasGenerator.php` | Alias generation |
| `src/Services/Discovery/CypherPatternGenerator.php` | Pattern generation |
| `src/Services/Discovery/CypherQueryBuilderSpy.php` | Query builder spy |
| `src/Services/Discovery/CypherScopeAdapter.php` | Scope adaptation |
| `src/Services/Discovery/EmbedFieldDetector.php` | Field detection |
| `src/Services/Discovery/InheritanceResolver.php` | Inheritance handling |
| `src/Services/Discovery/PropertyDiscoverer.php` | Property discovery |
| `src/Services/Discovery/RelationshipDiscoverer.php` | Relationship discovery |
| `src/Services/Discovery/TraversalScopeGenerator.php` | Scope traversal |
