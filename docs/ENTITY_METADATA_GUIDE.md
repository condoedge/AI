# Entity Metadata System - Configuration Guide

## Overview

The Entity Metadata System enables the AI Text-to-Query system to understand domain-specific business terminology and map it to the correct database filters. This solves the critical problem where users use business terms (like "volunteers", "customers", "pending orders") that don't directly match database schema.

## The Problem

**Before Entity Metadata:**
```
User: "Show me all volunteers"
System: ❌ Doesn't know volunteers = Person WHERE type = 'volunteer'
Result: Query fails or returns wrong data
```

**After Entity Metadata:**
```
User: "Show me all volunteers"
System: ✅ Detects "volunteers" → Person entity with type = 'volunteer' filter
Result: MATCH (p:Person {type: 'volunteer'}) RETURN p
```

## Architecture

### How It Works

1. **Configuration**: Define semantic metadata in `config/entities.php`
2. **Detection**: `ContextRetriever::getEntityMetadata()` detects relevant entities and scopes from user questions
3. **Context Enhancement**: Metadata is included in the context sent to the LLM
4. **Query Generation**: `QueryGenerator` uses metadata to generate accurate Cypher queries with correct filters

### Data Flow

```
User Question
    ↓
ContextRetriever.retrieveContext()
    ↓
getEntityMetadata() → Detects entities & scopes
    ↓
Context with metadata → Sent to LLM
    ↓
QueryGenerator.buildPrompt() → Includes scope mappings
    ↓
LLM generates accurate Cypher query
```

## Configuration Schema

### Basic Structure

```php
'EntityName' => [
    'graph' => [...],    // Existing Neo4j config
    'vector' => [...],   // Existing Qdrant config

    'metadata' => [      // NEW: Semantic metadata
        'aliases' => [...],
        'description' => '...',
        'scopes' => [...],
        'common_properties' => [...],
        'combinations' => [...],  // Optional
    ],
],
```

### Metadata Fields

#### 1. Aliases (Required)
Alternative names for the entity that users might use.

```php
'aliases' => ['person', 'people', 'user', 'users', 'individual', 'member']
```

**Purpose**: Entity detection from natural language
**Example**: "Show me all people" → Detects Person entity via "people" alias

#### 2. Description (Required)
Human-readable description of what the entity represents.

```php
'description' => 'Represents individuals in the system including volunteers, customers, and staff'
```

**Purpose**: Provides context to the LLM about entity purpose
**Best Practice**: Keep it concise (1-2 sentences)

#### 3. Scopes (Required)
Scoped subsets with business terminology and their corresponding filters.

```php
'scopes' => [
    'scope_name' => [
        'description' => 'Human description',
        'filter' => ['property' => 'value'],
        'cypher_pattern' => "property = 'value'",
        'examples' => ['Example question 1', 'Example question 2'],
    ],
]
```

**Fields:**
- `description`: What this scope represents
- `filter`: Associative array of property → value pairs (for structured queries)
- `cypher_pattern`: The actual Cypher WHERE clause pattern
- `examples`: Sample questions that should map to this scope

**Example:**
```php
'volunteers' => [
    'description' => 'People who volunteer their time',
    'filter' => ['type' => 'volunteer'],
    'cypher_pattern' => "type = 'volunteer'",
    'examples' => [
        'Show me all volunteers',
        'How many volunteers do we have?',
        'List active volunteers',
    ],
],
```

#### 4. Common Properties (Required)
Description of each property for LLM understanding.

```php
'common_properties' => [
    'id' => 'Unique identifier for the person',
    'type' => 'Person type: volunteer, customer, staff, etc.',
    'status' => 'Current status: active, inactive, pending, etc.',
]
```

**Purpose**: Helps LLM understand property semantics
**Best Practice**: Include property type and possible values

#### 5. Combinations (Optional)
Pre-defined combinations of multiple filters.

```php
'combinations' => [
    'active_volunteers' => [
        'description' => 'Active volunteers',
        'filters' => ['type' => 'volunteer', 'status' => 'active'],
        'cypher_pattern' => "type = 'volunteer' AND status = 'active'",
        'examples' => ['Show active volunteers'],
    ],
]
```

**Purpose**: Support complex multi-filter scenarios
**When to use**: Common multi-criteria queries in your domain

## Complete Examples

### Example 1: Person Entity with Multiple Scopes

```php
'Person' => [
    'graph' => [
        'label' => 'Person',
        'properties' => ['id', 'name', 'type', 'role', 'status'],
        'relationships' => [
            ['type' => 'MEMBER_OF', 'target_label' => 'Team'],
            ['type' => 'MANAGES', 'target_label' => 'Team'],
        ],
    ],

    'metadata' => [
        'aliases' => ['person', 'people', 'user', 'users', 'individual', 'member'],
        'description' => 'Represents individuals in the system',

        'scopes' => [
            'volunteers' => [
                'description' => 'People who volunteer their time',
                'filter' => ['type' => 'volunteer'],
                'cypher_pattern' => "type = 'volunteer'",
                'examples' => [
                    'Show me all volunteers',
                    'How many volunteers do we have?',
                    'List active volunteers',
                ],
            ],
            'customers' => [
                'description' => 'People who are customers',
                'filter' => ['type' => 'customer'],
                'cypher_pattern' => "type = 'customer'",
                'examples' => [
                    'Show me all customers',
                    'Which customers placed orders?',
                ],
            ],
            'staff' => [
                'description' => 'Staff members or employees',
                'filter' => ['role' => 'staff'],
                'cypher_pattern' => "role = 'staff'",
                'examples' => ['List all staff members', 'Show employees'],
            ],
        ],

        'common_properties' => [
            'id' => 'Unique identifier',
            'name' => 'Person\'s full name',
            'type' => 'Person type: volunteer, customer, staff',
            'role' => 'Person role in organization',
            'status' => 'Current status: active, inactive, pending',
        ],

        'combinations' => [
            'active_volunteers' => [
                'description' => 'Active volunteers',
                'filters' => ['type' => 'volunteer', 'status' => 'active'],
                'cypher_pattern' => "type = 'volunteer' AND status = 'active'",
                'examples' => ['Show active volunteers'],
            ],
        ],
    ],
],
```

### Example 2: Order Entity with Status Scopes

```php
'Order' => [
    'graph' => [
        'label' => 'Order',
        'properties' => ['id', 'total', 'status', 'created_at'],
        'relationships' => [
            ['type' => 'PLACED_BY', 'target_label' => 'Customer'],
            ['type' => 'CONTAINS', 'target_label' => 'Product'],
        ],
    ],

    'metadata' => [
        'aliases' => ['order', 'orders', 'purchase', 'purchases', 'sale'],
        'description' => 'Customer orders and purchases',

        'scopes' => [
            'pending' => [
                'description' => 'Orders awaiting processing',
                'filter' => ['status' => 'pending'],
                'cypher_pattern' => "status = 'pending'",
                'examples' => ['Show pending orders', 'List orders awaiting processing'],
            ],
            'completed' => [
                'description' => 'Orders that have been completed',
                'filter' => ['status' => 'completed'],
                'cypher_pattern' => "status = 'completed'",
                'examples' => ['Show completed orders', 'List fulfilled orders'],
            ],
            'high_value' => [
                'description' => 'Orders with high total value',
                'filter' => [],
                'cypher_pattern' => 'total > 1000',
                'examples' => ['Show high value orders', 'Orders over $1000'],
            ],
        ],

        'common_properties' => [
            'id' => 'Unique order identifier',
            'total' => 'Total order amount in currency',
            'status' => 'Order status: pending, completed, cancelled',
            'created_at' => 'When the order was created',
        ],
    ],
],
```

## Usage Examples

### Basic Scope Detection

```php
// User asks: "How many volunteers do we have?"

// ContextRetriever detects:
$metadata = [
    'detected_entities' => ['Person'],
    'detected_scopes' => [
        'volunteers' => [
            'entity' => 'Person',
            'scope' => 'volunteers',
            'description' => 'People who volunteer their time',
            'cypher_pattern' => "type = 'volunteer'",
            'filter' => ['type' => 'volunteer'],
        ],
    ],
    'entity_metadata' => [...],
];

// QueryGenerator receives enriched context
// LLM generates: MATCH (p:Person {type: 'volunteer'}) RETURN count(p)
```

### Multiple Scopes

```php
// User asks: "Show me pending and completed orders"

// System detects:
$metadata = [
    'detected_entities' => ['Order'],
    'detected_scopes' => [
        'pending' => [...],
        'completed' => [...],
    ],
];

// LLM generates:
// MATCH (o:Order) WHERE o.status IN ['pending', 'completed'] RETURN o
```

### Cross-Entity Scopes

```php
// User asks: "List volunteers who manage teams"

// System detects:
$metadata = [
    'detected_entities' => ['Person'],
    'detected_scopes' => [
        'volunteers' => [
            'entity' => 'Person',
            'cypher_pattern' => "type = 'volunteer'",
        ],
    ],
];

// LLM generates:
// MATCH (p:Person {type: 'volunteer'})-[:MANAGES]->(t:Team) RETURN p, t
```

## Best Practices

### 1. Comprehensive Aliases
Include all variations users might say:
```php
'aliases' => [
    'person', 'people',           // Singular/plural
    'user', 'users',              // Synonyms
    'individual', 'individuals',  // Formal terms
    'member', 'members',          // Domain-specific
]
```

### 2. Clear Cypher Patterns
Make patterns clear and unambiguous:
```php
// Good
'cypher_pattern' => "type = 'volunteer' AND status = 'active'"

// Avoid ambiguity
'cypher_pattern' => "status = 'active'"  // Which status?
```

### 3. Realistic Examples
Use actual user questions:
```php
'examples' => [
    'Show me all volunteers',           // Direct
    'How many volunteers do we have?',  // Count
    'List active volunteers',           // Filtered
    'Who are our volunteers?',          // Alternative phrasing
]
```

### 4. Property Documentation
Include type hints and possible values:
```php
'common_properties' => [
    'status' => 'Current status: active, inactive, pending, suspended',
    'type' => 'Person type: volunteer, customer, staff, contractor',
    'created_at' => 'Timestamp when record was created (ISO 8601 format)',
]
```

### 5. Scope Naming
Use business terms, not technical terms:
```php
// Good (business terms)
'volunteers' => [...],
'customers' => [...],
'pending_orders' => [...],

// Avoid (technical terms)
'type_volunteer' => [...],
'status_pending' => [...],
```

## Advanced Scenarios

### Handling Ambiguity

When a scope term could mean multiple things:

```php
'scopes' => [
    'active' => [
        'description' => 'People with active status',
        'filter' => ['status' => 'active'],
        'cypher_pattern' => "status = 'active'",
        'examples' => [
            'Show active people',
            'List active members',
            'Who is currently active?',
        ],
    ],
    'active_volunteers' => [  // More specific
        'description' => 'Active volunteers',
        'filters' => ['type' => 'volunteer', 'status' => 'active'],
        'cypher_pattern' => "type = 'volunteer' AND status = 'active'",
        'examples' => ['Show active volunteers'],
    ],
],
```

**Strategy**: Define both general and specific scopes. The system will detect the most specific match.

### Complex Filters

For non-equality filters:

```php
'high_value' => [
    'description' => 'Orders with high total value',
    'filter' => [],  // Can't express in simple key-value
    'cypher_pattern' => 'total > 1000',
    'examples' => ['Show high value orders', 'Orders over $1000'],
],
```

### Relationship Scopes

For scopes involving relationships:

```php
'team_managers' => [
    'description' => 'People who manage teams',
    'filter' => [],
    'cypher_pattern' => "(p)-[:MANAGES]->(:Team)",  // Relationship pattern
    'examples' => [
        'Show me team managers',
        'Who manages teams?',
        'List people who manage teams',
    ],
],
```

## Performance Considerations

### Metadata Loading

The system loads entity configs once during `ContextRetriever` instantiation:

```php
// Configs loaded once
$retriever = new ContextRetriever($vectorStore, $graphStore, $embeddingProvider);

// Reused for multiple queries
$context1 = $retriever->retrieveContext("Show volunteers");
$context2 = $retriever->retrieveContext("List customers");
```

**Impact**: Minimal - configs are loaded once and cached in memory.

### Detection Performance

Entity and scope detection uses simple string matching:
- Case-insensitive `strpos()` checks
- O(n) where n = number of entities with metadata

**Optimization**: Most applications have < 50 entities, making this negligible.

### Context Size

Metadata adds to LLM context. Typical sizes:
- Per detected entity: ~500-1000 tokens
- Per detected scope: ~100-200 tokens

**Best Practice**: Only detected entities/scopes are included (not all metadata).

## Troubleshooting

### Scope Not Detected

**Symptom**: User says "volunteers" but scope not detected.

**Checks**:
1. Verify scope is defined in `config/entities.php`
2. Check spelling matches exactly (case-insensitive)
3. Ensure parent entity has metadata section
4. Test detection:
   ```php
   $metadata = $retriever->getEntityMetadata("Show volunteers");
   var_dump($metadata['detected_scopes']);
   ```

### Wrong Filter Applied

**Symptom**: LLM generates query with wrong filter.

**Checks**:
1. Verify `cypher_pattern` syntax is correct
2. Check if there are conflicting scopes
3. Review LLM prompt (debug `buildPrompt()` output)
4. Ensure scope `description` is clear

### Entity Not Detected

**Symptom**: Entity metadata not included in context.

**Checks**:
1. Verify entity name or alias appears in question
2. Check `aliases` array includes all variations
3. Ensure `metadata` key exists in entity config
4. Test:
   ```php
   $metadata = $retriever->getEntityMetadata("Show people");
   var_dump($metadata['detected_entities']);
   ```

## Testing Your Metadata

### Unit Testing

Test scope detection:

```php
public function test_detects_volunteer_scope()
{
    $metadata = $this->retriever->getEntityMetadata('Show me all volunteers');

    $this->assertContains('Person', $metadata['detected_entities']);
    $this->assertArrayHasKey('volunteers', $metadata['detected_scopes']);
    $this->assertEquals("type = 'volunteer'",
        $metadata['detected_scopes']['volunteers']['cypher_pattern']);
}
```

### Integration Testing

Test end-to-end query generation:

```php
public function test_volunteer_query_generation()
{
    $question = 'How many volunteers do we have?';
    $context = $this->retriever->retrieveContext($question);
    $result = $this->queryGenerator->generate($question, $context);

    $this->assertStringContainsString("type = 'volunteer'", $result['cypher']);
}
```

## Migration Guide

### Adding Metadata to Existing Entities

1. **Identify business terms** used by your users
2. **Map terms to filters** in your database
3. **Add metadata section** to entity config
4. **Test with real questions**

**Example Migration:**

```php
// Before
'Person' => [
    'graph' => ['label' => 'Person', 'properties' => ['id', 'type']],
],

// After
'Person' => [
    'graph' => ['label' => 'Person', 'properties' => ['id', 'type']],
    'metadata' => [
        'aliases' => ['person', 'people'],
        'description' => 'Individuals in the system',
        'scopes' => [
            'volunteers' => [
                'description' => 'People who volunteer',
                'filter' => ['type' => 'volunteer'],
                'cypher_pattern' => "type = 'volunteer'",
                'examples' => ['Show volunteers'],
            ],
        ],
        'common_properties' => [
            'type' => 'Person type: volunteer, customer, staff',
        ],
    ],
],
```

### Backward Compatibility

Entities **without** metadata continue to work normally:
- System detects no metadata → skips metadata detection
- Context includes only graph schema (existing behavior)
- No breaking changes to existing queries

## API Reference

### ContextRetriever::getEntityMetadata()

```php
/**
 * Get entity metadata for relevant entities detected in the question
 *
 * @param string $question Natural language question
 * @return array Metadata with detected entities and scopes
 */
public function getEntityMetadata(string $question): array
```

**Returns:**
```php
[
    'detected_entities' => ['Person', 'Order'],
    'entity_metadata' => [
        'Person' => [...full metadata...],
        'Order' => [...full metadata...],
    ],
    'detected_scopes' => [
        'volunteers' => [
            'entity' => 'Person',
            'scope' => 'volunteers',
            'description' => '...',
            'cypher_pattern' => "...",
            'filter' => [...],
        ],
    ],
]
```

### ContextRetriever::getAllEntityMetadata()

```php
/**
 * Get all available entity metadata
 *
 * @return array All entity metadata indexed by entity name
 */
public function getAllEntityMetadata(): array
```

**Returns:**
```php
[
    'Person' => [...metadata...],
    'Order' => [...metadata...],
    // Only entities with metadata key
]
```

## Support and Feedback

For issues, questions, or feedback:
1. Check troubleshooting section above
2. Review test files in `tests/Unit/Services/EntityMetadataTest.php`
3. Consult source code documentation in `src/Services/ContextRetriever.php`

---

**Last Updated**: November 2024
**Version**: 1.0.0
