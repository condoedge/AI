# Semantic Metadata System - Quick Start Guide

> **TL;DR**: Configure scopes using semantic descriptions instead of Cypher. No technical knowledge required.

---

## 5-Minute Quick Start

### Step 1: Choose Specification Type

Ask yourself: "What kind of filter is this?"

| If you want to... | Use this type |
|------------------|---------------|
| Filter by property value (status = 'active') | `property_filter` |
| Traverse relationships (Person → Team) | `relationship_traversal` |
| Use aggregation (sum, count, avg) | `pattern` |

### Step 2: Fill in the Template

#### Template A: Property Filter

```php
'scope_name' => [
    'specification_type' => 'property_filter',
    'concept' => 'What this scope means in business terms',
    'filter' => [
        'property' => 'property_name',
        'operator' => 'equals',  // or: greater_than, less_than, contains
        'value' => 'value_to_match',
    ],
    'business_rules' => [
        'Plain English explanation of the rule',
    ],
    'examples' => [
        'Example question 1',
        'Example question 2',
    ],
],
```

**Real Example:**

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
        'A person is active if their status equals "active"',
    ],
    'examples' => [
        'Show active people',
        'List active members',
    ],
],
```

#### Template B: Relationship Traversal

```php
'scope_name' => [
    'specification_type' => 'relationship_traversal',
    'concept' => 'What this scope means',
    'relationship_spec' => [
        'start_entity' => 'StartingEntity',
        'path' => [
            [
                'relationship' => 'RELATIONSHIP_TYPE',
                'target_entity' => 'TargetEntity',
                'direction' => 'outgoing',  // or: incoming
            ],
            // Add more steps if needed
        ],
        'filter' => [
            'entity' => 'EntityToFilterOn',
            'property' => 'property_name',
            'operator' => 'equals',
            'value' => 'filter_value',
        ],
        'return_distinct' => true,  // Recommended for multi-relationships
    ],
    'business_rules' => [
        'Explain the relationship logic',
    ],
    'examples' => [
        'Example question',
    ],
],
```

**Real Example:**

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
        'Person is volunteer if they have volunteer role on any team',
    ],
    'examples' => [
        'Show me all volunteers',
        'How many volunteers?',
    ],
],
```

#### Template C: Pattern-Based

```php
'scope_name' => [
    'specification_type' => 'pattern',
    'concept' => 'What this scope means',
    'pattern' => 'pattern_name_from_library',
    'pattern_params' => [
        // Parameters specific to the pattern
        // See pattern library for required params
    ],
    'business_rules' => [
        'Explain the logic',
    ],
    'examples' => [
        'Example question',
    ],
],
```

**Real Example:**

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
    ],
    'examples' => [
        'Show high value customers',
    ],
],
```

---

## Common Patterns Quick Reference

### Pattern: Simple Property Filter

**Use when**: Filtering by single property value

```php
'specification_type' => 'property_filter',
'filter' => [
    'property' => 'status',
    'operator' => 'equals',
    'value' => 'active',
]
```

**Operators**: `equals`, `not_equals`, `greater_than`, `less_than`, `contains`, `starts_with`, `ends_with`

---

### Pattern: Relationship Exists

**Use when**: Finding entities that HAVE a relationship

```php
'specification_type' => 'pattern',
'pattern' => 'entity_with_relationship',
'pattern_params' => [
    'entity' => 'Customer',
    'relationship' => 'PLACED',
    'target_entity' => 'Order',
]
```

---

### Pattern: Relationship Doesn't Exist

**Use when**: Finding entities that DON'T have a relationship

```php
'specification_type' => 'pattern',
'pattern' => 'entity_without_relationship',
'pattern_params' => [
    'entity' => 'Person',
    'relationship' => 'MEMBER_OF',
    'target_entity' => 'Team',
]
```

---

### Pattern: Aggregation

**Use when**: Filtering based on sum, count, avg, max, min

```php
'specification_type' => 'pattern',
'pattern' => 'entity_with_aggregated_relationship',
'pattern_params' => [
    'base_entity' => 'Customer',
    'relationship' => 'PLACED',
    'related_entity' => 'Order',
    'aggregate_function' => 'sum',  // or: count, avg, max, min
    'aggregate_property' => 'total',
    'condition_operator' => 'greater_than',
    'condition_value' => 1000,
]
```

---

### Pattern: Date/Time Filter

**Use when**: Filtering by date ranges

```php
'specification_type' => 'pattern',
'pattern' => 'temporal_filter',
'pattern_params' => [
    'entity' => 'Customer',
    'date_property' => 'created_at',
    'temporal_operator' => 'within_last',  // or: before, after, between
    'temporal_value' => '30 days',
]
```

---

## Complete Example: Person Entity

```php
'Person' => [
    'graph' => [
        'label' => 'Person',
        'properties' => ['id', 'first_name', 'last_name', 'status'],
        'relationships' => [
            ['type' => 'HAS_ROLE', 'target_label' => 'PersonTeam'],
            ['type' => 'MEMBER_OF', 'target_label' => 'Team'],
        ],
    ],

    'vector' => [
        'collection' => 'people',
        'embed_fields' => ['first_name', 'last_name', 'bio'],
        'metadata' => ['id', 'email', 'status'],
    ],

    'metadata' => [
        'concept' => 'Individuals in the system',

        'aliases' => ['person', 'people', 'user', 'member'],

        // Property descriptions
        'properties' => [
            'status' => [
                'concept' => 'Current state',
                'type' => 'categorical',
                'possible_values' => ['active', 'inactive'],
            ],
        ],

        // Relationship descriptions
        'relationships' => [
            'HAS_ROLE' => [
                'concept' => 'Person has role on team',
                'target_entity' => 'PersonTeam',
                'direction' => 'outgoing',
            ],
        ],

        // Scopes
        'scopes' => [

            // Simple property filter
            'active' => [
                'specification_type' => 'property_filter',
                'concept' => 'Active people',
                'filter' => [
                    'property' => 'status',
                    'operator' => 'equals',
                    'value' => 'active',
                ],
                'business_rules' => [
                    'Person is active if status = "active"',
                ],
                'examples' => ['Show active people'],
            ],

            // Relationship traversal
            'volunteers' => [
                'specification_type' => 'relationship_traversal',
                'concept' => 'People who volunteer',
                'relationship_spec' => [
                    'start_entity' => 'Person',
                    'path' => [
                        [
                            'relationship' => 'HAS_ROLE',
                            'target_entity' => 'PersonTeam',
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
                    'Person is volunteer if they have volunteer role',
                ],
                'examples' => ['Show volunteers'],
            ],

            // Pattern-based
            'people_without_teams' => [
                'specification_type' => 'pattern',
                'concept' => 'People not on any team',
                'pattern' => 'entity_without_relationship',
                'pattern_params' => [
                    'entity' => 'Person',
                    'relationship' => 'MEMBER_OF',
                    'target_entity' => 'Team',
                ],
                'business_rules' => [
                    'Person has no MEMBER_OF relationship to Team',
                ],
                'examples' => ['Show people without teams'],
            ],

        ],
    ],
],
```

---

## Cheat Sheet

### Must-Have Fields

```php
[
    'specification_type' => '...',  // REQUIRED
    'concept' => '...',             // REQUIRED
    'business_rules' => [...],      // RECOMMENDED
    'examples' => [...],            // RECOMMENDED
]
```

### Operators Reference

**Property Operators:**
- `equals` - Exact match
- `not_equals` - Not equal
- `greater_than` - Numeric >
- `less_than` - Numeric <
- `contains` - String contains
- `starts_with` - String starts with
- `ends_with` - String ends with

**Aggregate Functions:**
- `sum` - Total
- `count` - Count
- `avg` - Average
- `max` - Maximum
- `min` - Minimum

**Temporal Operators:**
- `before` - Before date
- `after` - After date
- `within_last` - Within last N days/months/years
- `within_next` - Within next N days/months/years
- `between` - Between two dates

---

## Testing Your Configuration

### 1. Validate Structure

```php
// Check required fields
$scope = $config['metadata']['scopes']['volunteers'];

assert(!empty($scope['specification_type']), 'Missing specification_type');
assert(!empty($scope['concept']), 'Missing concept');
```

### 2. Test Scope Detection

```php
$retriever = new ContextRetriever(...);
$metadata = $retriever->getEntityMetadata('Show volunteers');

var_dump($metadata['detected_scopes']);
// Should show 'volunteers' scope
```

### 3. Test Query Generation

```php
$generator = new QueryGenerator(...);
$context = $retriever->retrieveContext('Show volunteers');
$result = $generator->generate('Show volunteers', $context);

echo $result['cypher'];
// Should generate correct Cypher query
```

---

## Common Mistakes

### ❌ Mistake 1: Old filter format

```php
// WRONG
'filter' => ['status' => 'active']
```

```php
// CORRECT
'filter' => [
    'property' => 'status',
    'operator' => 'equals',
    'value' => 'active',
]
```

### ❌ Mistake 2: Missing specification_type

```php
// WRONG
'active' => [
    'concept' => 'Active people',
    'filter' => [...],
]
```

```php
// CORRECT
'active' => [
    'specification_type' => 'property_filter',  // ADD THIS
    'concept' => 'Active people',
    'filter' => [...],
]
```

### ❌ Mistake 3: Using simple filter for relationship

```php
// WRONG - volunteers requires relationship traversal
'volunteers' => [
    'specification_type' => 'property_filter',
    'filter' => ['type' => 'volunteer'],
]
```

```php
// CORRECT
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
            'value' => 'volunteer',
        ],
    ],
]
```

---

## Available Patterns

See full list in `config/ai-patterns.php`:

1. `property_filter` - Simple property filtering
2. `property_range` - Numeric range
3. `relationship_traversal` - Graph traversal
4. `entity_with_relationship` - Has relationship
5. `entity_without_relationship` - Lacks relationship
6. `entity_with_aggregated_relationship` - Aggregation
7. `temporal_filter` - Date/time filtering
8. `multi_hop_traversal` - Complex paths
9. `multiple_property_filter` - Multiple conditions
10. `relationship_with_property_filter` - Combined filters

---

## Need Help?

1. **Examples**: Check `config/entities-semantic.example.php`
2. **Patterns**: Review `config/ai-patterns.example.php`
3. **Full Docs**: Read `docs/SEMANTIC_METADATA_REDESIGN.md`
4. **Migration**: See `docs/SEMANTIC_METADATA_MIGRATION.md`

---

## Key Takeaways

✅ **No Cypher Required** - Describe in plain language
✅ **Three Types** - property_filter, relationship_traversal, pattern
✅ **Business Rules** - Document the logic clearly
✅ **Examples Help** - More examples = better LLM understanding
✅ **Pattern Library** - Reusable templates for common cases

---

**Start Simple**: Begin with `property_filter` scopes, then progress to relationships and patterns.

**Document Well**: Good business rules and examples = better query generation.

**Test Often**: Validate as you go to catch issues early.

---

**Document Version**: 1.0
**For**: Developers & Business Analysts
**Difficulty**: Beginner-Friendly
