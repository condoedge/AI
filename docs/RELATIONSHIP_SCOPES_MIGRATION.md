# Relationship-Based Scopes - Migration Guide

## Overview

This guide helps you migrate from simple property filters to relationship-based scopes when your business terms actually require graph traversal.

## When to Migrate

Migrate a scope from simple to relationship pattern when:

1. **The business term is defined by relationships**, not properties
   - Example: "volunteers" = people with a specific role relationship
   - Not: "volunteers" = people with type='volunteer' property

2. **The data model uses junction nodes or intermediate relationships**
   - Example: Person → PersonTeam → Team (role stored on PersonTeam)
   - Not: Person → Team (direct relationship)

3. **The concept requires traversing multiple hops**
   - Example: Person → Role → Team → Department
   - Not: Person with department_id property

4. **Property filters return incomplete or incorrect results**
   - Current query: `MATCH (p:Person {type: 'volunteer'}) RETURN p`
   - Returns: Empty or wrong people
   - Reason: 'volunteer' is actually stored on PersonTeam relationship

## Migration Decision Tree

```
Does the business term...

├─ Filter by node properties only?
│  ├─ YES → Keep as 'simple' pattern
│  └─ NO → Continue...
│
├─ Require traversing relationships?
│  ├─ YES → Does it need aggregation or calculation?
│  │  ├─ YES → Use 'complex' pattern
│  │  └─ NO → Use 'relationship' pattern
│  └─ NO → Keep as 'simple' pattern
```

## Step-by-Step Migration

### Step 1: Identify the Actual Graph Pattern

**Before migrating**, understand how the data is actually stored.

**Example**: Understanding "volunteers"

```cypher
// Check how volunteer information is stored
MATCH (p:Person)
WHERE p.type = 'volunteer'
RETURN count(p)
// Returns 0 or wrong count

// Explore the actual structure
MATCH (p:Person)-[r:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
RETURN p, r, pt, t
LIMIT 5
// Shows role_type is on PersonTeam node

// Verify the correct pattern
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN count(DISTINCT p)
// Returns correct count!
```

### Step 2: Document Current vs. Correct Pattern

Create a mapping document:

```markdown
# Scope Migration Plan

## Volunteers

**Current (Incorrect)**:
- Pattern: Simple property filter
- Query: `MATCH (p:Person) WHERE p.type = 'volunteer' RETURN p`
- Issue: Returns 0 results, property doesn't exist

**Correct**:
- Pattern: Relationship traversal
- Query: `MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team) WHERE pt.role_type = 'volunteer' RETURN DISTINCT p`
- Hops: 2 relationships
- Intermediate nodes: PersonTeam, Team
- Filter location: PersonTeam.role_type

**Pattern Type**: relationship
```

### Step 3: Update Configuration

**Before** (simple pattern - incorrect):

```php
'volunteers' => [
    'description' => 'People who volunteer',
    'filter' => ['type' => 'volunteer'],
    'cypher_pattern' => "type = 'volunteer'",
    'examples' => [
        'Show me all volunteers',
        'How many volunteers do we have?',
    ],
],
```

**After** (relationship pattern - correct):

```php
'volunteers' => [
    'pattern_type' => 'relationship',  // NEW: Specify pattern type
    'description' => 'People who have volunteer role on any team',

    // Option 1: Structured (recommended for maintainability)
    'relationship' => [
        'pattern' => '(p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)',
        'where' => "pt.role_type = 'volunteer'",
        'return_distinct' => true,
    ],

    // Option 2: Complete Cypher (required for LLM)
    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
CYPHER,

    'examples' => [
        'Show me all volunteers',
        'How many volunteers do we have?',
        'List volunteers on teams',
        'Who are our volunteers?',
    ],
],
```

### Step 4: Add Relationship Documentation

Document the relationships for LLM understanding:

```php
'metadata' => [
    // ... existing metadata ...

    'relationships' => [
        'HAS_ROLE' => [
            'description' => 'Person has a role on a team through PersonTeam junction node',
            'target' => 'PersonTeam',
            'properties' => ['role_type', 'since', 'status'],
            'common_patterns' => [
                'Volunteers: (p)-[:HAS_ROLE]->(pt:PersonTeam) WHERE pt.role_type = "volunteer"',
                'Leaders: (p)-[:HAS_ROLE]->(pt:PersonTeam) WHERE pt.role_type = "leader"',
                'Active roles: (p)-[:HAS_ROLE]->(pt:PersonTeam) WHERE pt.status = "active"',
            ],
        ],
    ],
],
```

### Step 5: Test the Migration

#### 5.1 Unit Test - Pattern Detection

```php
public function test_volunteers_detected_as_relationship_pattern()
{
    $metadata = $this->retriever->getEntityMetadata('Show me all volunteers');

    $this->assertArrayHasKey('volunteers', $metadata['detected_scopes']);
    $this->assertEquals('relationship',
        $metadata['detected_scopes']['volunteers']['pattern_type']);
}
```

#### 5.2 Integration Test - Query Generation

```php
public function test_volunteers_query_uses_relationship_pattern()
{
    $question = 'How many volunteers do we have?';
    $context = $this->retriever->retrieveContext($question);
    $result = $this->queryGenerator->generate($question, $context);

    // Must include relationship traversal
    $this->assertStringContainsString('[:HAS_ROLE]->', $result['cypher']);
    $this->assertStringContainsString('PersonTeam', $result['cypher']);
    $this->assertStringContainsString("role_type = 'volunteer'", $result['cypher']);

    // Must use DISTINCT
    $this->assertStringContainsString('DISTINCT', $result['cypher']);
}
```

#### 5.3 Manual Test - Real Query Execution

```php
// Test with real database
$question = 'How many volunteers do we have?';

// Get context
$context = $contextRetriever->retrieveContext($question);

// Generate query
$result = $queryGenerator->generate($question, $context);

// Execute and verify
$cypherQuery = $result['cypher'];
$executionResult = $graphStore->query($cypherQuery);

// Verify results make sense
var_dump($executionResult);
// Should show correct volunteer count
```

### Step 6: Validate Results

Compare old vs new results:

```cypher
-- Old (incorrect) approach
MATCH (p:Person)
WHERE p.type = 'volunteer'
RETURN count(p) as count;
-- Result: 0

-- New (correct) approach
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN count(DISTINCT p) as count;
-- Result: 47 (correct!)
```

### Step 7: Deploy and Monitor

1. **Deploy configuration changes**
2. **Monitor LLM-generated queries** in logs
3. **Verify query patterns** match expectations
4. **Check query performance** (relationship queries are slower)
5. **Adjust as needed** based on results

## Common Migration Scenarios

### Scenario 1: Simple Property to Relationship

**Before**:
```php
'customers' => [
    'description' => 'People who are customers',
    'filter' => ['type' => 'customer'],
    'cypher_pattern' => "type = 'customer'",
],
```

**Problem**: No `type` property exists. Customers are people who have placed orders.

**After**:
```php
'customers' => [
    'pattern_type' => 'relationship',
    'description' => 'People who have placed at least one order',
    'relationship' => [
        'pattern' => '(p:Person)-[:PLACED]->(o:Order)',
        'return_distinct' => true,
    ],
    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:PLACED]->(o:Order)
RETURN DISTINCT p
CYPHER,
],
```

### Scenario 2: Add Relationship Pattern While Keeping Simple

**Scenario**: You have BOTH a property and relationship definition.

**Solution**: Keep both, but prefer the relationship pattern:

```php
'volunteers' => [
    'pattern_type' => 'relationship',
    'description' => 'People with volunteer role (via relationship)',
    'relationship' => [
        'pattern' => '(p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)',
        'where' => "pt.role_type = 'volunteer'",
        'return_distinct' => true,
    ],
    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
CYPHER,
],

// Optional: Keep legacy simple pattern as fallback
'volunteers_legacy' => [
    'pattern_type' => 'simple',
    'description' => 'People with volunteer type property (legacy)',
    'filter' => ['type' => 'volunteer'],
    'cypher_pattern' => "p.type = 'volunteer'",
],
```

### Scenario 3: Complex Pattern with Aggregation

**Before**:
```php
'high_value_customers' => [
    'description' => 'Customers with high order value',
    'filter' => ['total_orders' => '>10000'],  // Property doesn't exist
    'cypher_pattern' => "total_orders > 10000",
],
```

**Problem**: `total_orders` is calculated, not stored.

**After**:
```php
'high_value_customers' => [
    'pattern_type' => 'complex',
    'description' => 'Customers who have placed orders totaling over $10,000',
    'cypher_template' => <<<CYPHER
MATCH (p:Person)-[:PLACED]->(o:Order)
WITH p, sum(o.total) as total_value
WHERE total_value > 10000
RETURN p
CYPHER,
    'examples' => [
        'Show high value customers',
        'List customers with over $10k in orders',
    ],
    'modification_guidance' => 'To change threshold, modify "total_value > 10000"',
],
```

### Scenario 4: Combined Simple + Relationship

**Scenario**: Combine property filter with relationship traversal.

```php
'active_volunteers' => [
    'pattern_type' => 'relationship',
    'description' => 'Active people with volunteer role',
    'relationship' => [
        'pattern' => '(p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)',
        'where' => "pt.role_type = 'volunteer' AND p.status = 'active'",
        'return_distinct' => true,
    ],
    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer' AND p.status = 'active'
RETURN DISTINCT p
CYPHER,
],
```

## Migration Checklist

For each scope being migrated:

- [ ] Identified actual graph pattern via Neo4j query testing
- [ ] Documented current (incorrect) vs correct pattern
- [ ] Updated scope configuration with pattern_type
- [ ] Added complete cypher_pattern for LLM
- [ ] Added relationship structure (optional but recommended)
- [ ] Updated description to reflect relationship concept
- [ ] Added relationship documentation to metadata
- [ ] Created unit test for pattern detection
- [ ] Created integration test for query generation
- [ ] Manually tested with real database
- [ ] Verified results match expectations
- [ ] Checked query performance is acceptable
- [ ] Updated examples with more variations
- [ ] Deployed to testing environment
- [ ] Monitored LLM-generated queries
- [ ] Deployed to production

## Performance Considerations

Relationship patterns are more expensive than simple property filters:

```cypher
-- Simple (fast): Single node lookup with property filter
MATCH (p:Person) WHERE p.type = 'volunteer' RETURN p

-- Relationship (slower): Multiple relationship traversals
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
```

### Optimization Strategies

1. **Create Indexes**:
```cypher
// Index intermediate node properties
CREATE INDEX FOR (pt:PersonTeam) ON (pt.role_type);
CREATE INDEX FOR (pt:PersonTeam) ON (pt.status);

// Composite indexes for common patterns
CREATE INDEX FOR (pt:PersonTeam) ON (pt.role_type, pt.status);
```

2. **Always Use LIMIT**:
```php
'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
LIMIT 100  // ALWAYS include LIMIT
CYPHER,
```

3. **Use DISTINCT to Avoid Duplicates**:
```cypher
// Without DISTINCT: Person appears multiple times if on multiple teams
RETURN p

// With DISTINCT: Person appears once
RETURN DISTINCT p
```

4. **Consider Materialized Views** for frequently accessed patterns:
```cypher
// Create a denormalized volunteer flag
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)
WHERE pt.role_type = 'volunteer'
SET p.is_volunteer = true;

// Then use simple pattern
MATCH (p:Person) WHERE p.is_volunteer = true RETURN p
```

## Rollback Plan

If migration causes issues:

1. **Keep backup** of old configuration:
```bash
cp config/entities.php config/entities-relationship-backup.php
cp config/entities-old.php config/entities.php
```

2. **Revert code changes** if needed:
```bash
git revert <commit-hash>
```

3. **Test simple pattern** still works:
```php
'volunteers' => [
    'pattern_type' => 'simple',  // Revert to simple
    'description' => 'People who volunteer',
    'filter' => ['type' => 'volunteer'],
    'cypher_pattern' => "type = 'volunteer'",
],
```

## Troubleshooting

### Issue: LLM Ignores Relationship Pattern

**Symptom**: LLM generates simple property filter instead of relationship pattern.

**Solution**:
1. Verify `pattern_type` is set to 'relationship'
2. Check `formatScopesForPrompt()` includes "CRITICAL" guidance
3. Strengthen prompt language in QueryGenerator
4. Add more examples showing relationship traversal
5. Verify cypher_pattern is complete and correct

### Issue: Query Returns Duplicates

**Symptom**: Same person appears multiple times in results.

**Solution**:
```php
// Ensure return_distinct is true
'relationship' => [
    'pattern' => '(p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)',
    'where' => "pt.role_type = 'volunteer'",
    'return_distinct' => true,  // Must be true
],
```

### Issue: Query Too Slow

**Symptom**: Relationship queries take > 1 second.

**Solution**:
1. Add indexes on intermediate node properties
2. Reduce LIMIT if returning too many results
3. Check query plan with PROFILE
4. Consider denormalization for hot paths

```cypher
// Check query performance
PROFILE MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
LIMIT 100;
```

### Issue: Pattern Not Detected

**Symptom**: Scope term in question but not in detected_scopes.

**Solution**:
1. Verify scope name matches term in question (case-insensitive)
2. Check metadata section exists in entity config
3. Verify entity is detected (check detected_entities)
4. Test detection directly:

```php
$metadata = $retriever->getEntityMetadata('Show me volunteers');
var_dump($metadata['detected_scopes']);
```

## Real-World Example: Complete Migration

### Before Migration

```php
'Person' => [
    'graph' => [
        'label' => 'Person',
        'properties' => ['id', 'name', 'type'],
    ],
    'metadata' => [
        'aliases' => ['person', 'people'],
        'description' => 'People in the system',
        'scopes' => [
            'volunteers' => [
                'description' => 'People who volunteer',
                'filter' => ['type' => 'volunteer'],
                'cypher_pattern' => "type = 'volunteer'",
            ],
        ],
    ],
],
```

**Problem**: Queries return 0 results because `type` property doesn't exist.

### After Migration

```php
'Person' => [
    'graph' => [
        'label' => 'Person',
        'properties' => ['id', 'name', 'status'],
        'relationships' => [
            [
                'type' => 'HAS_ROLE',
                'target_label' => 'PersonTeam',
                'description' => 'Person has role on team',
            ],
        ],
    ],
    'metadata' => [
        'aliases' => ['person', 'people', 'user', 'users', 'member'],
        'description' => 'Individuals in the system including volunteers and staff',

        'scopes' => [
            // Relationship pattern for volunteers
            'volunteers' => [
                'pattern_type' => 'relationship',
                'description' => 'People who have volunteer role on any team',
                'relationship' => [
                    'pattern' => '(p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)',
                    'where' => "pt.role_type = 'volunteer'",
                    'return_distinct' => true,
                ],
                'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
CYPHER,
                'examples' => [
                    'Show me all volunteers',
                    'How many volunteers do we have?',
                    'List volunteers on teams',
                    'Who are our volunteers?',
                ],
            ],

            // Simple pattern for active status
            'active' => [
                'pattern_type' => 'simple',
                'description' => 'People with active status',
                'filter' => ['status' => 'active'],
                'cypher_pattern' => "p.status = 'active'",
                'examples' => [
                    'Show active people',
                    'List active members',
                ],
            ],
        ],

        'common_properties' => [
            'id' => 'Unique identifier',
            'name' => 'Person name',
            'status' => 'Status: active, inactive, suspended',
        ],

        'relationships' => [
            'HAS_ROLE' => [
                'description' => 'Person has a role on a team',
                'target' => 'PersonTeam',
                'properties' => ['role_type', 'since'],
                'common_patterns' => [
                    'Volunteers: (p)-[:HAS_ROLE]->(pt:PersonTeam) WHERE pt.role_type = "volunteer"',
                    'Leaders: (p)-[:HAS_ROLE]->(pt:PersonTeam) WHERE pt.role_type = "leader"',
                ],
            ],
        ],
    ],
],
```

### Verification

```bash
# Run tests
php vendor/bin/phpunit tests/Unit/Services/RelationshipScopesTest.php

# Check query generation
php artisan tinker
>>> $result = AI::answerQuestion("How many volunteers do we have?");
>>> echo $result['cypher'];
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN count(DISTINCT p)
LIMIT 100
```

## Next Steps

After successful migration:

1. **Document the change** in your project changelog
2. **Update user documentation** if business terms changed
3. **Train team** on new configuration format
4. **Monitor performance** and adjust indexes
5. **Migrate other scopes** following same pattern
6. **Share learnings** with team

## Support

For issues or questions:
- Review: `docs/RELATIONSHIP_SCOPES_DESIGN.md`
- Implementation: `docs/RELATIONSHIP_SCOPES_IMPLEMENTATION.md`
- Examples: `config/entities-with-relationship-patterns.example.php`
- Tests: `tests/Unit/Services/RelationshipScopesTest.php`

---

**Document Version**: 1.0
**Last Updated**: November 2024
