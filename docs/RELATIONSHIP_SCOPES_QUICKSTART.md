# Relationship-Based Scopes - Quickstart Guide

Get up and running with relationship-based scopes in 10 minutes.

## What Are Relationship-Based Scopes?

**Problem**: Your business terms don't map to simple properties.

```
User asks: "Show me all volunteers"
Your data: Person -[:HAS_ROLE]-> PersonTeam WHERE role_type = 'volunteer'
Old system: MATCH (p:Person) WHERE p.type = 'volunteer'  ❌ Returns nothing
New system: MATCH (p)-[:HAS_ROLE]->(pt) WHERE pt.role_type = 'volunteer'  ✅ Works!
```

**Solution**: Relationship-based scopes define business concepts through graph traversal.

## Quick Example

### Step 1: Identify Your Pattern

Test in Neo4j to find the correct pattern:

```cypher
// What you thought (doesn't work)
MATCH (p:Person) WHERE p.type = 'volunteer' RETURN count(p);
// → 0 results

// What actually works
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN count(DISTINCT p);
// → 47 results ✅
```

### Step 2: Add to Config

In `config/entities.php`:

```php
'Person' => [
    'graph' => [
        'label' => 'Person',
        'properties' => ['id', 'name', 'status'],
        'relationships' => [
            ['type' => 'HAS_ROLE', 'target_label' => 'PersonTeam'],
        ],
    ],

    'metadata' => [
        'aliases' => ['person', 'people'],
        'description' => 'Individuals in the system',

        'scopes' => [
            'volunteers' => [
                'pattern_type' => 'relationship',  // ← NEW
                'description' => 'People with volunteer role on teams',

                'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
CYPHER,

                'examples' => [
                    'Show me all volunteers',
                    'How many volunteers do we have?',
                ],
            ],
        ],
    ],
],
```

### Step 3: Test It

```php
use AiSystem\Services\ContextRetriever;
use AiSystem\Services\QueryGenerator;

// Get context
$context = $contextRetriever->retrieveContext("How many volunteers?");

// Check detection
var_dump($context['entity_metadata']['detected_scopes']['volunteers']);
// Should show pattern_type: 'relationship'

// Generate query
$result = $queryGenerator->generate("How many volunteers?", $context);

// Verify it uses the relationship pattern
echo $result['cypher'];
// Should contain: [:HAS_ROLE]->(pt:PersonTeam)
```

Done! Your AI now understands "volunteers" requires relationship traversal.

## Three Pattern Types

### 1. Simple (Property Filter)

Use when filtering by node properties:

```php
'active' => [
    'pattern_type' => 'simple',
    'description' => 'People with active status',
    'cypher_pattern' => "p.status = 'active'",
],
```

**When to use**: Direct property comparisons.

### 2. Relationship (Graph Traversal)

Use when concept requires following relationships:

```php
'volunteers' => [
    'pattern_type' => 'relationship',
    'description' => 'People with volunteer role',
    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
CYPHER,
],
```

**When to use**: Junction nodes, multi-hop traversals, relationship properties.

### 3. Complex (Aggregations)

Use when calculating or aggregating:

```php
'high_value_customers' => [
    'pattern_type' => 'complex',
    'description' => 'Customers with orders over $10k',
    'cypher_template' => <<<CYPHER
MATCH (p:Person)-[:PLACED]->(o:Order)
WITH p, sum(o.total) as total
WHERE total > 10000
RETURN p
CYPHER,
],
```

**When to use**: Calculations, aggregations, subqueries.

## Common Patterns

### Volunteers (Junction Node)

```php
'volunteers' => [
    'pattern_type' => 'relationship',
    'description' => 'People with volunteer role',
    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
CYPHER,
],
```

### Customers (Has Relationship)

```php
'customers' => [
    'pattern_type' => 'relationship',
    'description' => 'People who placed orders',
    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:PLACED]->(o:Order)
RETURN DISTINCT p
CYPHER,
],
```

### Team Leaders (Relationship Filter)

```php
'team_leaders' => [
    'pattern_type' => 'relationship',
    'description' => 'People who lead teams',
    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'leader'
RETURN DISTINCT p
CYPHER,
],
```

### Active Volunteers (Combined)

```php
'active_volunteers' => [
    'pattern_type' => 'relationship',
    'description' => 'Active people with volunteer role',
    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer' AND p.status = 'active'
RETURN DISTINCT p
CYPHER,
],
```

### High-Value Customers (Aggregation)

```php
'high_value_customers' => [
    'pattern_type' => 'complex',
    'description' => 'Customers with orders over $10k',
    'cypher_template' => <<<CYPHER
MATCH (p:Person)-[:PLACED]->(o:Order)
WITH p, sum(o.total) as total_value
WHERE total_value > 10000
RETURN p
CYPHER,
    'modification_guidance' => 'Change threshold in WHERE clause',
],
```

### Recent Customers (Time Filter)

```php
'recent_customers' => [
    'pattern_type' => 'relationship',
    'description' => 'Customers who ordered recently',
    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:PLACED]->(o:Order)
WHERE o.created_at > datetime() - duration({days: 30})
RETURN DISTINCT p
CYPHER,
],
```

## Configuration Template

Copy and customize:

```php
'EntityName' => [
    'graph' => [
        'label' => 'EntityName',
        'properties' => ['id', 'name', 'status'],
        'relationships' => [
            ['type' => 'RELATIONSHIP_TYPE', 'target_label' => 'TargetEntity'],
        ],
    ],

    'metadata' => [
        'aliases' => ['entity', 'entities'],
        'description' => 'What this entity represents',

        'scopes' => [
            'scope_name' => [
                'pattern_type' => 'relationship',  // or 'simple' or 'complex'
                'description' => 'What this scope means',

                'cypher_pattern' => <<<CYPHER
MATCH (e:EntityName)-[:RELATIONSHIP]->(other)
WHERE condition
RETURN DISTINCT e
CYPHER,

                'examples' => [
                    'Example question 1',
                    'Example question 2',
                ],
            ],
        ],

        'common_properties' => [
            'id' => 'Description of id',
            'name' => 'Description of name',
        ],

        'relationships' => [
            'RELATIONSHIP_TYPE' => [
                'description' => 'What this relationship means',
                'target' => 'TargetEntity',
                'common_patterns' => [
                    'Example: (e)-[:REL]->(other) WHERE condition',
                ],
            ],
        ],
    ],
],
```

## Testing Your Configuration

### 1. Test Pattern Detection

```php
$metadata = $contextRetriever->getEntityMetadata('Show me all volunteers');

// Check it was detected
assert(isset($metadata['detected_scopes']['volunteers']));

// Check pattern type
assert($metadata['detected_scopes']['volunteers']['pattern_type'] === 'relationship');

// Check it has the pattern
assert(strpos($metadata['detected_scopes']['volunteers']['cypher_pattern'], '[:HAS_ROLE]->') !== false);
```

### 2. Test Query Generation

```php
$context = $contextRetriever->retrieveContext('How many volunteers?');
$result = $queryGenerator->generate('How many volunteers?', $context);

// Check query uses relationship pattern
assert(strpos($result['cypher'], '[:HAS_ROLE]->') !== false);
assert(strpos($result['cypher'], 'PersonTeam') !== false);
assert(strpos($result['cypher'], 'DISTINCT') !== false);

// Check it counts
assert(strpos($result['cypher'], 'count(') !== false);
```

### 3. Test with Real Database

```php
$question = 'How many volunteers do we have?';
$context = $contextRetriever->retrieveContext($question);
$result = $queryGenerator->generate($question, $context);

// Execute the query
$cypherQuery = $result['cypher'];
$executionResult = $graphStore->query($cypherQuery);

// Verify results
var_dump($executionResult);
// Should show correct count
```

## Common Issues

### Issue: Pattern Not Detected

**Check**:
```php
$metadata = $contextRetriever->getEntityMetadata('Show me volunteers');
var_dump($metadata['detected_scopes']);
// Empty? Check scope name matches term in question
```

**Fix**: Ensure scope name appears in question (case-insensitive).

### Issue: LLM Generates Wrong Query

**Check**: Verify pattern_type is set:
```php
'volunteers' => [
    'pattern_type' => 'relationship',  // Must be present
    // ...
],
```

**Fix**: Add or correct pattern_type.

### Issue: Query Returns Duplicates

**Check**: Pattern includes DISTINCT:
```php
'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p  // ← Must have DISTINCT
CYPHER,
```

**Fix**: Add DISTINCT to RETURN clause.

### Issue: Query Too Slow

**Check**: Query performance:
```cypher
PROFILE MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
LIMIT 100;
```

**Fix**: Create indexes:
```cypher
CREATE INDEX FOR (pt:PersonTeam) ON (pt.role_type);
```

## Best Practices

1. **Always use DISTINCT** for relationship patterns
2. **Always include LIMIT** (automatically added by system)
3. **Test pattern in Neo4j** before adding to config
4. **Add multiple examples** for better detection
5. **Document relationships** in metadata section
6. **Index intermediate properties** for performance

## Examples Repository

Complete examples in:
- `config/entities-with-relationship-patterns.example.php`
- `tests/Unit/Services/RelationshipScopesTest.php`

## Cheat Sheet

| Pattern Type | Use Case | Example |
|-------------|----------|---------|
| `simple` | Property filters | `p.status = 'active'` |
| `relationship` | Graph traversals | `(p)-[:HAS_ROLE]->(pt)` |
| `complex` | Aggregations | `WITH p, sum(o.total) WHERE > 10k` |

| Field | Required | Purpose |
|-------|----------|---------|
| `pattern_type` | Yes | Tells system how to use pattern |
| `description` | Yes | Explains what scope means |
| `cypher_pattern` | Yes | Complete query for LLM |
| `examples` | Yes | Sample questions |
| `relationship` | No | Structured pattern (recommended) |

## Next Steps

1. ✅ **Identify** your relationship-based business terms
2. ✅ **Test** patterns in Neo4j
3. ✅ **Configure** scopes in entities.php
4. ✅ **Test** detection and generation
5. ✅ **Deploy** and monitor

## Documentation

- **Design**: `docs/RELATIONSHIP_SCOPES_DESIGN.md`
- **Implementation**: `docs/RELATIONSHIP_SCOPES_IMPLEMENTATION.md`
- **Migration**: `docs/RELATIONSHIP_SCOPES_MIGRATION.md`
- **Examples**: `config/entities-with-relationship-patterns.example.php`

## Support

Questions? Check:
1. Design document for architecture
2. Migration guide for step-by-step
3. Example config for patterns
4. Test files for verification

---

**Need Help?** Review the full design document at `docs/RELATIONSHIP_SCOPES_DESIGN.md`

**Ready to Migrate?** Follow `docs/RELATIONSHIP_SCOPES_MIGRATION.md`
