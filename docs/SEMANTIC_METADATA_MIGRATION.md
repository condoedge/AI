# Semantic Metadata Migration Guide

## Overview

This guide walks you through migrating from the concrete Cypher-based metadata system to the new semantic, declarative metadata system.

**Goal**: Eliminate hardcoded Cypher patterns and replace with semantic business descriptions.

---

## Migration Checklist

- [ ] **Phase 1**: Add pattern library to config
- [ ] **Phase 2**: Migrate entity configurations
- [ ] **Phase 3**: Add semantic property descriptions
- [ ] **Phase 4**: Add relationship descriptions
- [ ] **Phase 5**: Test query generation
- [ ] **Phase 6**: Validate business rules
- [ ] **Phase 7**: Update documentation

---

## Phase 1: Add Pattern Library

### Step 1.1: Copy Pattern Library Template

Copy `config/ai-patterns.example.php` to your config:

```bash
cp config/ai-patterns.example.php config/ai-patterns.php
```

### Step 1.2: Add to Main AI Config

Edit `config/ai.php` to load pattern library:

```php
return [
    // ... existing config ...

    /*
    |--------------------------------------------------------------------------
    | Query Pattern Library
    |--------------------------------------------------------------------------
    |
    | Reusable, generic query patterns for semantic metadata system.
    |
    */
    'query_patterns' => require __DIR__ . '/ai-patterns.php',
];
```

### Step 1.3: Verify Pattern Loading

Test that patterns are accessible:

```php
$config = config('ai.query_patterns');
var_dump(array_keys($config)); // Should show pattern names
```

---

## Phase 2: Migrate Entity Configurations

### Step 2.1: Identify Scope Types

For each scope in your entity config, determine its type:

| Old Pattern | New Specification Type | Reason |
|------------|----------------------|--------|
| `cypher_pattern` = `"status = 'active'"` | `property_filter` | Simple property comparison |
| `cypher_pattern` contains `MATCH` with relationships | `relationship_traversal` | Graph traversal required |
| `cypher_pattern` contains `sum()`, `count()`, etc. | `pattern` | Aggregation/complex logic |

### Step 2.2: Migration Templates

#### Template A: Simple Property Filter

**Before:**

```php
'active' => [
    'description' => 'People with active status',
    'filter' => ['status' => 'active'],
    'cypher_pattern' => "status = 'active'",
    'examples' => ['Show active people'],
],
```

**After:**

```php
'active' => [
    'specification_type' => 'property_filter',

    'concept' => 'People with active status',

    'filter' => [
        'property' => 'status',
        'operator' => 'equals',
        'value' => 'active',
    ],

    'business_rules' => [
        'A person is active if their status property equals "active"',
    ],

    'examples' => [
        'Show active people',
        'List active members',
    ],
],
```

**Migration Steps:**
1. Change `'filter' => ['status' => 'active']` to structured format
2. Add `'specification_type' => 'property_filter'`
3. Rename `'description'` to `'concept'`
4. Remove `'cypher_pattern'`
5. Add `'business_rules'` array
6. Keep/expand `'examples'`

#### Template B: Relationship Traversal

**Before:**

```php
'volunteers' => [
    'description' => 'People who volunteer',
    'filter' => ['type' => 'volunteer'], // Incorrect - actually needs relationship
    'cypher_pattern' => "type = 'volunteer'",
    'examples' => ['Show volunteers'],
],
```

**After:**

```php
'volunteers' => [
    'specification_type' => 'relationship_traversal',

    'concept' => 'People who volunteer on teams',

    'relationship_spec' => [
        'start_entity' => 'Person',
        'path' => [
            [
                'relationship' => 'HAS_ROLE',
                'target_entity' => 'PersonTeam',
                'direction' => 'outgoing',
            ],
            [
                'relationship' => 'ON_TEAM',
                'target_entity' => 'Team',
                'direction' => 'outgoing',
            ],
        ],
        'filter' => [
            'entity' => 'PersonTeam',
            'property' => 'role_type',
            'operator' => 'equals',
            'value' => 'volunteer',
        ],
        'return_distinct' => true,
    ],

    'business_rules' => [
        'A person is a volunteer if they have at least one volunteer role on any team',
        'The volunteer role is indicated by PersonTeam.role_type = "volunteer"',
        'Multiple volunteer roles on different teams = still one volunteer',
    ],

    'examples' => [
        'Show me all volunteers',
        'How many volunteers do we have?',
        'List volunteers on teams',
    ],
],
```

**Migration Steps:**
1. Analyze actual graph structure (not the incorrect filter)
2. Add `'specification_type' => 'relationship_traversal'`
3. Build `'relationship_spec'` with path array
4. Define filter on intermediate entity
5. Set `'return_distinct' => true`
6. Document business rules clearly
7. Remove old `'filter'` and `'cypher_pattern'`

#### Template C: Pattern-Based (Aggregation)

**Before:**

```php
'high_value' => [
    'description' => 'Orders with high total value',
    'filter' => [],
    'cypher_pattern' => 'total > 1000',
    'examples' => ['Show high value orders'],
],
```

**After:**

```php
'high_value' => [
    'specification_type' => 'pattern',

    'concept' => 'Customers with high total order value',

    'pattern' => 'entity_with_aggregated_relationship',

    'pattern_params' => [
        'base_entity' => 'Customer',
        'relationship' => 'PLACED',
        'related_entity' => 'Order',
        'aggregate_property' => 'total',
        'aggregate_function' => 'sum',
        'condition_operator' => 'greater_than',
        'condition_value' => 10000,
    ],

    'business_rules' => [
        'High-value customer has sum(Order.total) > $10,000',
        'All orders included regardless of status',
    ],

    'examples' => [
        'Show high value customers',
        'List customers with over $10k in orders',
    ],
],
```

**Migration Steps:**
1. Identify appropriate pattern from library
2. Add `'specification_type' => 'pattern'`
3. Reference pattern name in `'pattern'`
4. Map old config to pattern parameters
5. Document aggregation logic in business rules
6. Remove `'cypher_pattern'`

### Step 2.3: Batch Migration Script

Use this script to help migrate multiple scopes:

```php
<?php

function analyzeScope(array $scope): string
{
    // Has relationship keywords?
    if (!empty($scope['cypher_pattern'])) {
        $cypher = $scope['cypher_pattern'];

        if (stripos($cypher, 'MATCH') !== false) {
            return 'relationship_or_complex';
        }

        if (preg_match('/\b(sum|count|avg|max|min)\b/i', $cypher)) {
            return 'pattern_aggregation';
        }
    }

    // Simple filter?
    if (!empty($scope['filter']) && count($scope['filter']) === 1) {
        return 'property_filter';
    }

    return 'unknown';
}

function suggestMigration(array $scope, string $type): array
{
    switch ($type) {
        case 'property_filter':
            $property = array_key_first($scope['filter']);
            $value = $scope['filter'][$property];

            return [
                'specification_type' => 'property_filter',
                'concept' => $scope['description'] ?? "Filter by {$property}",
                'filter' => [
                    'property' => $property,
                    'operator' => 'equals',
                    'value' => $value,
                ],
                'business_rules' => [
                    "Entity matches when {$property} equals \"{$value}\"",
                ],
                'examples' => $scope['examples'] ?? [],
            ];

        case 'pattern_aggregation':
            return [
                'specification_type' => 'pattern',
                'concept' => $scope['description'] ?? 'Needs description',
                'pattern' => 'entity_with_aggregated_relationship', // Adjust as needed
                'pattern_params' => [
                    // TODO: Extract from cypher_pattern
                    'base_entity' => 'TODO',
                    'relationship' => 'TODO',
                    'related_entity' => 'TODO',
                    'aggregate_function' => 'TODO',
                ],
                'business_rules' => [],
                'examples' => $scope['examples'] ?? [],
                'migration_notes' => 'TODO: Fill in pattern_params manually',
            ];

        default:
            return [
                'specification_type' => 'unknown',
                'concept' => $scope['description'] ?? 'Needs description',
                'migration_notes' => 'Manual migration required',
                'original_config' => $scope,
            ];
    }
}

// Load your entity config
$entities = require 'config/entities.php';

foreach ($entities as $entityName => $config) {
    if (empty($config['metadata']['scopes'])) {
        continue;
    }

    echo "Entity: {$entityName}\n";
    echo str_repeat('-', 50) . "\n";

    foreach ($config['metadata']['scopes'] as $scopeName => $scope) {
        $type = analyzeScope($scope);
        $suggestion = suggestMigration($scope, $type);

        echo "\nScope: {$scopeName}\n";
        echo "Detected Type: {$type}\n";
        echo "Suggested Migration:\n";
        echo json_encode($suggestion, JSON_PRETTY_PRINT) . "\n";
    }

    echo "\n\n";
}
```

---

## Phase 3: Add Semantic Property Descriptions

### Step 3.1: Identify Properties to Document

List all properties used in scopes:

```php
$propertiesUsed = [];

foreach ($entities as $entityName => $config) {
    foreach ($config['metadata']['scopes'] ?? [] as $scope) {
        if (isset($scope['filter']['property'])) {
            $propertiesUsed[$entityName][] = $scope['filter']['property'];
        }
    }
}
```

### Step 3.2: Add Property Metadata

For each property, add semantic description:

```php
'properties' => [
    'status' => [
        'concept' => 'Current state of the entity',
        'type' => 'categorical',
        'possible_values' => ['active', 'inactive', 'pending', 'suspended'],
        'default_value' => 'pending',
        'business_meaning' => 'Active means entity is operational and accessible',
    ],

    'role_type' => [
        'concept' => 'Type of role a person has',
        'type' => 'categorical',
        'location' => 'PersonTeam entity',
        'possible_values' => ['volunteer', 'leader', 'coordinator', 'member'],
        'business_meaning' => 'Determines permissions and responsibilities',
    ],

    'total' => [
        'concept' => 'Total monetary value',
        'type' => 'numeric',
        'unit' => 'currency',
        'business_meaning' => 'Sum of all line items',
    ],

    'created_at' => [
        'concept' => 'Creation timestamp',
        'type' => 'datetime',
        'business_meaning' => 'When entity was first created',
    ],
],
```

### Step 3.3: Property Type Categories

Use these type categories:

- `identifier` - Unique IDs (id, uuid)
- `text` - String values (name, email, description)
- `categorical` - Fixed set of values (status, type, role)
- `numeric` - Numbers (total, count, price)
- `datetime` - Dates/times (created_at, updated_at)
- `boolean` - True/false (is_active, enabled)

---

## Phase 4: Add Relationship Descriptions

### Step 4.1: Document Relationships

For each relationship in your graph config, add semantic metadata:

```php
'relationships' => [
    'HAS_ROLE' => [
        'concept' => 'Person has a role on a team',
        'target_entity' => 'PersonTeam',
        'direction' => 'outgoing',
        'cardinality' => 'one_to_many',
        'business_meaning' => 'Links person to team roles',
        'common_use_cases' => [
            'Finding volunteers: filter PersonTeam.role_type = "volunteer"',
            'Finding leaders: filter PersonTeam.role_type = "leader"',
        ],
    ],

    'PLACED' => [
        'concept' => 'Customer placed an order',
        'target_entity' => 'Order',
        'direction' => 'outgoing',
        'cardinality' => 'one_to_many',
        'business_meaning' => 'Links customer to purchase orders',
        'common_use_cases' => [
            'Finding customers with orders',
            'Calculating customer lifetime value',
        ],
    ],

    'MEMBER_OF' => [
        'concept' => 'Person is a member of a team',
        'target_entity' => 'Team',
        'direction' => 'outgoing',
        'cardinality' => 'one_to_many',
        'business_meaning' => 'Direct team membership',
    ],
],
```

### Step 4.2: Cardinality Options

- `one_to_one` - Single relationship (Person -> Address)
- `one_to_many` - Multiple relationships (Customer -> Orders)
- `many_to_many` - Junction entity (Person -> PersonTeam -> Team)

---

## Phase 5: Testing

### Step 5.1: Unit Tests

Test scope detection:

```php
public function test_detects_semantic_property_filter()
{
    $retriever = new ContextRetriever(...);
    $metadata = $retriever->getEntityMetadata('Show active people');

    $this->assertArrayHasKey('active', $metadata['detected_scopes']);
    $this->assertEquals('property_filter',
        $metadata['detected_scopes']['active']['specification_type']);

    $this->assertEquals('status',
        $metadata['detected_scopes']['active']['filter']['property']);
}

public function test_detects_semantic_relationship_traversal()
{
    $retriever = new ContextRetriever(...);
    $metadata = $retriever->getEntityMetadata('Show volunteers');

    $this->assertArrayHasKey('volunteers', $metadata['detected_scopes']);
    $this->assertEquals('relationship_traversal',
        $metadata['detected_scopes']['volunteers']['specification_type']);

    $path = $metadata['detected_scopes']['volunteers']['relationship_spec']['path'];
    $this->assertCount(2, $path);
    $this->assertEquals('HAS_ROLE', $path[0]['relationship']);
}

public function test_detects_semantic_pattern()
{
    $retriever = new ContextRetriever(...);
    $metadata = $retriever->getEntityMetadata('Show high value customers');

    $this->assertEquals('pattern',
        $metadata['detected_scopes']['high_value']['specification_type']);

    $this->assertEquals('entity_with_aggregated_relationship',
        $metadata['detected_scopes']['high_value']['pattern']);
}
```

### Step 5.2: Integration Tests

Test query generation:

```php
public function test_generates_correct_query_from_semantic_scope()
{
    $question = 'How many volunteers do we have?';

    $retriever = new ContextRetriever(...);
    $context = $retriever->retrieveContext($question);

    $generator = new QueryGenerator(...);
    $result = $generator->generate($question, $context);

    // Verify relationship traversal in generated query
    $this->assertStringContainsString('HAS_ROLE', $result['cypher']);
    $this->assertStringContainsString('PersonTeam', $result['cypher']);
    $this->assertStringContainsString('role_type', $result['cypher']);
    $this->assertStringContainsString('volunteer', $result['cypher']);
    $this->assertStringContainsString('DISTINCT', $result['cypher']);
    $this->assertStringContainsString('count(', $result['cypher']);
}
```

### Step 5.3: Manual Testing

Test with real questions:

```bash
php artisan ai:query "Show me all volunteers"
php artisan ai:query "How many active volunteers?"
php artisan ai:query "List high value customers"
php artisan ai:query "Show recent customers"
```

---

## Phase 6: Validation

### Step 6.1: Configuration Validation

Run validation script:

```php
<?php

function validateEntityConfig(array $config, string $entityName): array
{
    $errors = [];
    $warnings = [];

    // Validate scopes
    foreach ($config['metadata']['scopes'] ?? [] as $scopeName => $scope) {
        // Required fields
        if (empty($scope['specification_type'])) {
            $errors[] = "{$entityName}.{$scopeName}: Missing specification_type";
        }

        if (empty($scope['concept'])) {
            $warnings[] = "{$entityName}.{$scopeName}: Missing concept description";
        }

        // Check for old fields
        if (isset($scope['cypher_pattern'])) {
            $warnings[] = "{$entityName}.{$scopeName}: Still has cypher_pattern (should be removed)";
        }

        // Type-specific validation
        $type = $scope['specification_type'] ?? '';
        switch ($type) {
            case 'property_filter':
                if (empty($scope['filter']['property'])) {
                    $errors[] = "{$entityName}.{$scopeName}: Missing filter.property";
                }
                break;

            case 'relationship_traversal':
                if (empty($scope['relationship_spec']['path'])) {
                    $errors[] = "{$entityName}.{$scopeName}: Missing relationship_spec.path";
                }
                break;

            case 'pattern':
                if (empty($scope['pattern'])) {
                    $errors[] = "{$entityName}.{$scopeName}: Missing pattern reference";
                }
                if (empty($scope['pattern_params'])) {
                    $errors[] = "{$entityName}.{$scopeName}: Missing pattern_params";
                }
                break;
        }

        // Best practices
        if (empty($scope['business_rules'])) {
            $warnings[] = "{$entityName}.{$scopeName}: No business rules documented";
        }

        if (empty($scope['examples'])) {
            $warnings[] = "{$entityName}.{$scopeName}: No example questions provided";
        }
    }

    // Validate properties
    foreach ($config['metadata']['properties'] ?? [] as $propName => $propMeta) {
        if (empty($propMeta['concept'])) {
            $warnings[] = "{$entityName}.properties.{$propName}: Missing concept";
        }
        if (empty($propMeta['type'])) {
            $warnings[] = "{$entityName}.properties.{$propName}: Missing type";
        }
    }

    // Validate relationships
    foreach ($config['metadata']['relationships'] ?? [] as $relName => $relMeta) {
        if (empty($relMeta['concept'])) {
            $warnings[] = "{$entityName}.relationships.{$relName}: Missing concept";
        }
        if (empty($relMeta['target_entity'])) {
            $errors[] = "{$entityName}.relationships.{$relName}: Missing target_entity";
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings,
    ];
}

// Validate all entities
$entities = require 'config/entities.php';

foreach ($entities as $entityName => $config) {
    $validation = validateEntityConfig($config, $entityName);

    if (!$validation['valid'] || !empty($validation['warnings'])) {
        echo "{$entityName}:\n";

        if (!empty($validation['errors'])) {
            echo "  Errors:\n";
            foreach ($validation['errors'] as $error) {
                echo "    - {$error}\n";
            }
        }

        if (!empty($validation['warnings'])) {
            echo "  Warnings:\n";
            foreach ($validation['warnings'] as $warning) {
                echo "    - {$warning}\n";
            }
        }

        echo "\n";
    }
}
```

### Step 6.2: Query Quality Check

Compare old vs new query generation:

```php
// Test same questions with both systems
$questions = [
    'Show active people',
    'How many volunteers?',
    'List high value customers',
];

foreach ($questions as $question) {
    echo "Question: {$question}\n";

    // Old system
    $oldResult = $oldGenerator->generate($question, $oldContext);
    echo "Old Query: {$oldResult['cypher']}\n";

    // New system
    $newResult = $newGenerator->generate($question, $newContext);
    echo "New Query: {$newResult['cypher']}\n";

    echo "\n";
}
```

---

## Phase 7: Documentation

### Step 7.1: Update Team Documentation

Document the new system for your team:

```markdown
# Entity Configuration Guide

## Adding New Scopes

1. Choose specification type:
   - `property_filter` - Simple property comparison
   - `relationship_traversal` - Graph traversal
   - `pattern` - Use pattern from library

2. Fill in semantic fields:
   - `concept` - What does this scope represent?
   - `business_rules` - How is it determined?
   - `examples` - Example questions

3. NO Cypher required!

## Example

```php
'active_volunteers' => [
    'specification_type' => 'relationship_traversal',
    'concept' => 'Active people who volunteer',
    'relationship_spec' => [...],
    'business_rules' => [
        'Must be active (status = "active")',
        'Must have volunteer role',
    ],
    'examples' => [
        'Show active volunteers',
    ],
],
```
```

### Step 7.2: Create Cheat Sheet

Quick reference for common patterns:

```markdown
# Scope Configuration Cheat Sheet

## Simple Filter
```php
'filter' => [
    'property' => 'status',
    'operator' => 'equals',
    'value' => 'active',
]
```

## Relationship Path
```php
'relationship_spec' => [
    'start_entity' => 'Person',
    'path' => [
        ['relationship' => 'HAS_ROLE', 'target_entity' => 'PersonTeam', 'direction' => 'outgoing'],
    ],
    'filter' => [...],
]
```

## Pattern Usage
```php
'pattern' => 'entity_with_aggregated_relationship',
'pattern_params' => [
    'base_entity' => 'Customer',
    'aggregate_function' => 'sum',
    ...
]
```
```

---

## Common Migration Issues

### Issue 1: Incorrect Original Config

**Problem**: Old config has simple filter but actually needs relationship traversal

```php
// WRONG - This won't work for role-based filtering
'volunteers' => [
    'filter' => ['type' => 'volunteer'],
],
```

**Solution**: Analyze actual graph structure

```cypher
// Check actual relationships
MATCH (p:Person)-[r]->(pt:PersonTeam)
WHERE pt.role_type = 'volunteer'
RETURN p, r, pt
LIMIT 5
```

Then configure correctly:

```php
'volunteers' => [
    'specification_type' => 'relationship_traversal',
    'relationship_spec' => [
        'start_entity' => 'Person',
        'path' => [
            ['relationship' => 'HAS_ROLE', 'target_entity' => 'PersonTeam', 'direction' => 'outgoing'],
        ],
        'filter' => [
            'entity' => 'PersonTeam',
            'property' => 'role_type',
            'operator' => 'equals',
            'value' => 'volunteer',
        ],
    ],
],
```

### Issue 2: Missing Pattern in Library

**Problem**: Need pattern that doesn't exist in library

**Solution**: Add custom pattern to `config/ai-patterns.php`

```php
'custom_pattern_name' => [
    'description' => 'What this pattern does',
    'parameters' => [
        'param1' => 'Description',
        'param2' => 'Description',
    ],
    'semantic_template' => 'Find {param1} where {param2}',
],
```

### Issue 3: Complex Cypher Can't Be Expressed

**Problem**: Very complex Cypher with multiple WITH clauses, unions, etc.

**Solution**: Create specific pattern or use composed pattern

```php
'complex_scope' => [
    'specification_type' => 'pattern',
    'pattern' => 'composed',
    'pattern_params' => [
        'base_pattern' => 'relationship_traversal',
        'additional_patterns' => [...],
    ],
],
```

---

## Rollback Plan

If you need to rollback:

1. Keep old config as `config/entities.backup.php`
2. Test new config in parallel
3. Use feature flag to switch between systems:

```php
if (config('ai.use_semantic_metadata', false)) {
    // New semantic system
} else {
    // Old concrete system
}
```

---

## Success Criteria

Migration is complete when:

- ✅ All scopes have `specification_type`
- ✅ No `cypher_pattern` fields in config
- ✅ All properties have semantic descriptions
- ✅ All relationships documented
- ✅ Business rules documented for each scope
- ✅ Example questions provided
- ✅ Tests passing
- ✅ Query generation working correctly
- ✅ Team trained on new system

---

## Support

Questions? Issues?

1. Check example files in `config/`
2. Review pattern library in `config/ai-patterns.php`
3. Run validation script
4. Consult `docs/SEMANTIC_METADATA_REDESIGN.md`

---

**Document Version**: 1.0
**Last Updated**: 2024
