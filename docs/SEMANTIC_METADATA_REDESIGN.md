# Semantic Metadata System - Declarative Redesign

## Executive Summary

This document presents a complete redesign of the metadata system to be **maximally configurable and minimally concrete**. The new approach eliminates hardcoded Cypher patterns in favor of semantic descriptions that the LLM interprets intelligently.

### Core Philosophy

> **"Configuration describes WHAT the business concept means. The system and LLM figure out HOW to query it."**

### Key Improvements

- ✅ **Zero Cypher in config** - No technical query syntax required
- ✅ **Semantic descriptions** - Natural language business rules
- ✅ **Pattern library** - Reusable, generic query patterns
- ✅ **LLM-powered interpretation** - AI figures out the query structure
- ✅ **Schema-aware** - Leverages Neo4j schema automatically
- ✅ **Domain-agnostic** - Works for ANY business domain
- ✅ **Self-documenting** - Configuration explains itself

---

## Problem Analysis

### Current Approach (Too Concrete)

```php
'volunteers' => [
    'description' => 'People who volunteer their time',
    'filter' => ['type' => 'volunteer'],
    'cypher_pattern' => "type = 'volunteer'",  // ❌ Hardcoded Cypher
],
```

**Issues:**
1. **Technical Knowledge Required** - Config writer must know Cypher
2. **Domain-Specific** - Hardcodes "volunteers" concept
3. **Brittle** - Schema changes break config
4. **Not Reusable** - Every entity needs custom patterns
5. **Low Flexibility** - Adding new patterns = code changes

### Proposed Approach (Declarative)

```php
'volunteers' => [
    'concept' => 'People who volunteer',
    'definition' => [
        'base_entity' => 'Person',
        'qualifying_condition' => 'has at least one volunteer role on any team',
        'relationship_path' => ['PersonTeam', 'Team'],
        'filter_on' => 'PersonTeam.role_type',
        'filter_value' => 'volunteer',
    ],
    'business_rules' => [
        'A person is a volunteer if they have at least one volunteer role',
        'Multiple volunteer roles = still one volunteer (use DISTINCT)',
    ],
],
```

**Advantages:**
1. ✅ No Cypher syntax - pure business logic
2. ✅ Self-documenting - anyone can understand
3. ✅ Reusable - pattern applies to any domain
4. ✅ Flexible - LLM adapts to schema changes
5. ✅ Extensible - new patterns = config only

---

## Architecture Overview

### Three-Layer System

```
┌─────────────────────────────────────────────────────┐
│  1. DECLARATIVE CONFIGURATION LAYER                 │
│     - Semantic descriptions                         │
│     - Business rules in plain English               │
│     - Pattern references (not implementations)      │
└─────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────┐
│  2. PATTERN LIBRARY LAYER                           │
│     - Generic, reusable query patterns              │
│     - Parameter-based instantiation                 │
│     - Domain-agnostic templates                     │
└─────────────────────────────────────────────────────┘
                        ↓
┌─────────────────────────────────────────────────────┐
│  3. LLM INTERPRETATION LAYER                        │
│     - Combines semantic context + schema            │
│     - Generates appropriate Cypher                  │
│     - Handles edge cases intelligently              │
└─────────────────────────────────────────────────────┘
```

### Information Flow

```
User Question
    ↓
Detect Entities/Scopes
    ↓
Load Semantic Definitions
    ↓
Select Pattern Templates
    ↓
Enrich with Schema Context
    ↓
LLM Generates Cypher
    ↓
Execute Query
```

---

## Layer 1: Declarative Configuration Schema

### Configuration Structure

```php
'EntityName' => [
    'graph' => [...],  // Existing Neo4j config
    'vector' => [...], // Existing Qdrant config

    'metadata' => [
        'concept' => 'Natural language description of what this entity represents',

        'aliases' => ['synonym1', 'synonym2'],

        'scopes' => [
            'scope_name' => [
                // Scope definition
            ],
        ],

        'properties' => [
            'property_name' => [
                // Property semantic description
            ],
        ],

        'relationships' => [
            'RELATIONSHIP_TYPE' => [
                // Relationship semantic description
            ],
        ],
    ],
],
```

### Scope Definition Schema

Each scope uses one of three specification types:

#### Type 1: Property-Based Scope

For simple property filters:

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

#### Type 2: Relationship-Based Scope

For traversing graph relationships:

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
        'The volunteer role is stored in PersonTeam.role_type',
        'Multiple volunteer roles on different teams = still one volunteer',
    ],

    'examples' => [
        'Show me all volunteers',
        'How many volunteers do we have?',
        'List volunteers on teams',
    ],
],
```

#### Type 3: Pattern-Based Scope

For complex queries using pattern library:

```php
'high_value_customers' => [
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
        'A customer is high-value if sum of all order totals > $10,000',
        'All orders are included regardless of status',
    ],

    'examples' => [
        'Show high value customers',
        'List customers with over $10k in orders',
    ],
],
```

### Property Semantic Description

Describe properties semantically:

```php
'properties' => [
    'status' => [
        'concept' => 'Current state of the entity',
        'type' => 'categorical',
        'possible_values' => ['active', 'inactive', 'pending', 'suspended'],
        'default_value' => 'pending',
        'business_meaning' => 'Active means entity is currently operational and accessible',
    ],

    'role_type' => [
        'concept' => 'The type of role a person has on a team',
        'type' => 'categorical',
        'possible_values' => ['volunteer', 'leader', 'coordinator', 'member'],
        'business_meaning' => 'Determines permissions and responsibilities within the team',
    ],

    'total' => [
        'concept' => 'Total monetary value',
        'type' => 'numeric',
        'unit' => 'currency',
        'business_meaning' => 'Sum of all line items in the order',
    ],
],
```

### Relationship Semantic Description

Describe relationships semantically:

```php
'relationships' => [
    'HAS_ROLE' => [
        'concept' => 'Person has a role on a team',
        'target_entity' => 'PersonTeam',
        'direction' => 'outgoing',
        'cardinality' => 'one_to_many',
        'business_meaning' => 'Links a person to their team roles through junction entity',
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
        'business_meaning' => 'Links customer to their purchase orders',
        'common_use_cases' => [
            'Finding customers with orders: any PLACED relationship exists',
            'Calculating customer value: sum Order.total across PLACED relationships',
        ],
    ],
],
```

---

## Layer 2: Pattern Library

### Global Pattern Definitions

Define reusable patterns in `config/ai.php`:

```php
'query_patterns' => [

    /**
     * Pattern: Property Filter
     *
     * Find entities where a property matches a value
     */
    'property_filter' => [
        'description' => 'Filter entities by property value',
        'parameters' => [
            'entity' => 'Entity label (e.g., Person)',
            'property' => 'Property name (e.g., status)',
            'operator' => 'Comparison operator (equals, greater_than, less_than, contains)',
            'value' => 'Value to compare against',
        ],
        'semantic_template' => 'Find {entity} where {property} {operator} {value}',
    ],

    /**
     * Pattern: Relationship Traversal
     *
     * Find entities through relationship path
     */
    'relationship_traversal' => [
        'description' => 'Find entities connected through relationships',
        'parameters' => [
            'start_entity' => 'Starting entity label',
            'path' => 'Array of relationship steps',
            'filter_entity' => 'Entity to apply filter on',
            'filter_property' => 'Property to filter',
            'filter_value' => 'Filter value',
            'return_distinct' => 'Whether to return distinct results',
        ],
        'semantic_template' => 'Find {start_entity} connected through {path} where {filter_entity}.{filter_property} equals {filter_value}',
    ],

    /**
     * Pattern: Entity with Aggregated Relationship
     *
     * Find entities based on aggregated values from related entities
     */
    'entity_with_aggregated_relationship' => [
        'description' => 'Find entities where aggregation of related entities meets condition',
        'parameters' => [
            'base_entity' => 'Entity to return',
            'relationship' => 'Relationship to traverse',
            'related_entity' => 'Related entity',
            'aggregate_property' => 'Property to aggregate',
            'aggregate_function' => 'Aggregation function (sum, count, avg, max, min)',
            'condition_operator' => 'Comparison operator',
            'condition_value' => 'Threshold value',
        ],
        'semantic_template' => 'Find {base_entity} where {aggregate_function} of {related_entity}.{aggregate_property} {condition_operator} {condition_value}',
    ],

    /**
     * Pattern: Entity Exists Relationship
     *
     * Find entities that have at least one relationship
     */
    'entity_with_relationship' => [
        'description' => 'Find entities that have at least one relationship of a type',
        'parameters' => [
            'entity' => 'Entity label',
            'relationship' => 'Relationship type',
            'target_entity' => 'Target entity label',
        ],
        'semantic_template' => 'Find {entity} that have {relationship} relationship to {target_entity}',
    ],

    /**
     * Pattern: Entity Without Relationship
     *
     * Find entities that don't have a relationship
     */
    'entity_without_relationship' => [
        'description' => 'Find entities that lack a specific relationship',
        'parameters' => [
            'entity' => 'Entity label',
            'relationship' => 'Relationship type',
            'target_entity' => 'Target entity label',
        ],
        'semantic_template' => 'Find {entity} that do not have {relationship} relationship to {target_entity}',
    ],

    /**
     * Pattern: Multi-Hop Relationship
     *
     * Find entities through multiple relationship hops
     */
    'multi_hop_traversal' => [
        'description' => 'Find entities through multi-step relationship path',
        'parameters' => [
            'start_entity' => 'Starting entity',
            'hops' => 'Array of relationship steps',
            'end_entity' => 'Target entity',
            'filters' => 'Filters to apply at each step',
        ],
        'semantic_template' => 'Find {start_entity} connected to {end_entity} through {hops}',
    ],

    /**
     * Pattern: Property Range
     *
     * Find entities where numeric property is within range
     */
    'property_range' => [
        'description' => 'Filter entities by property value range',
        'parameters' => [
            'entity' => 'Entity label',
            'property' => 'Numeric property',
            'min_value' => 'Minimum value (inclusive)',
            'max_value' => 'Maximum value (inclusive)',
        ],
        'semantic_template' => 'Find {entity} where {property} is between {min_value} and {max_value}',
    ],

    /**
     * Pattern: Temporal Filter
     *
     * Find entities based on time-based conditions
     */
    'temporal_filter' => [
        'description' => 'Filter entities by date/time conditions',
        'parameters' => [
            'entity' => 'Entity label',
            'date_property' => 'Date/datetime property',
            'temporal_operator' => 'before, after, within_last, within_next',
            'temporal_value' => 'Date or duration',
        ],
        'semantic_template' => 'Find {entity} where {date_property} is {temporal_operator} {temporal_value}',
    ],

],
```

### Pattern Metadata

Each pattern includes:

1. **Description** - What the pattern does
2. **Parameters** - Required configuration values
3. **Semantic Template** - Human-readable pattern description
4. **Examples** - (Optional) Example instantiations

---

## Layer 3: LLM Interpretation Strategy

### Enhanced Context Format

When presenting scopes to LLM, use rich semantic context:

```
ENTITY: Person
CONCEPT: Individuals in the system

DETECTED SCOPE: volunteers
SPECIFICATION TYPE: relationship_traversal

BUSINESS CONCEPT:
People who volunteer on teams

RELATIONSHIP PATH:
Person → (HAS_ROLE) → PersonTeam → (ON_TEAM) → Team

QUALIFYING CONDITION:
PersonTeam.role_type equals "volunteer"

BUSINESS RULES:
- A person is a volunteer if they have at least one volunteer role on any team
- The volunteer role is stored in PersonTeam.role_type
- Multiple volunteer roles on different teams = still one volunteer (use DISTINCT)

SCHEMA CONTEXT:
- Person node has properties: id, first_name, last_name, email, status
- PersonTeam node has properties: id, person_id, team_id, role_type
- HAS_ROLE relationship connects Person → PersonTeam
- ON_TEAM relationship connects PersonTeam → Team

PATTERN HINT:
This matches the 'relationship_traversal' pattern

EXAMPLE QUESTIONS:
- Show me all volunteers
- How many volunteers do we have?
- List volunteers on teams

QUERY CONSTRUCTION GUIDANCE:
1. Start with Person nodes
2. Traverse HAS_ROLE relationship to PersonTeam
3. Traverse ON_TEAM relationship to Team
4. Filter where PersonTeam.role_type = 'volunteer'
5. Return DISTINCT Person nodes (to avoid duplicates from multiple teams)
```

### LLM Prompt Structure

```php
/**
 * Build enhanced prompt with semantic metadata
 */
private function buildSemanticPrompt(string $question, array $context): string
{
    $prompt = "You are a Neo4j Cypher query expert. Generate queries based on semantic business definitions.\n\n";

    // Add graph schema
    $prompt .= $this->formatGraphSchema($context['graph_schema']);

    // Add detected scopes with semantic context
    if (!empty($context['entity_metadata']['detected_scopes'])) {
        $prompt .= "\n=== DETECTED BUSINESS CONCEPTS ===\n\n";

        foreach ($context['entity_metadata']['detected_scopes'] as $scope) {
            $prompt .= $this->formatSemanticScope($scope);
        }
    }

    // Add pattern library hints
    $prompt .= "\n=== AVAILABLE QUERY PATTERNS ===\n\n";
    $prompt .= $this->formatPatternLibrary();

    // Add rules for query generation
    $prompt .= "\n=== QUERY GENERATION RULES ===\n\n";
    $prompt .= $this->formatQueryRules();

    // Add the question
    $prompt .= "\nUSER QUESTION: {$question}\n\n";

    // Request generation
    $prompt .= "Generate a Cypher query that:\n";
    $prompt .= "1. Respects the business rules defined in the detected concepts\n";
    $prompt .= "2. Uses the appropriate query pattern from the library\n";
    $prompt .= "3. Follows Neo4j best practices (DISTINCT for relationships, LIMIT for results)\n";
    $prompt .= "4. Returns only the Cypher query (no explanations)\n\n";
    $prompt .= "CYPHER QUERY:";

    return $prompt;
}

private function formatSemanticScope(array $scope): string
{
    $output = "SCOPE: {$scope['scope']}\n";
    $output .= "ENTITY: {$scope['entity']}\n";
    $output .= "TYPE: {$scope['specification_type']}\n\n";

    $output .= "CONCEPT:\n{$scope['concept']}\n\n";

    if (!empty($scope['relationship_spec'])) {
        $output .= "RELATIONSHIP PATH:\n";
        $output .= $this->formatRelationshipPath($scope['relationship_spec']);
        $output .= "\n";
    }

    if (!empty($scope['filter'])) {
        $output .= "FILTER:\n";
        $output .= "{$scope['filter']['entity']}.{$scope['filter']['property']} ";
        $output .= "{$scope['filter']['operator']} {$scope['filter']['value']}\n\n";
    }

    if (!empty($scope['business_rules'])) {
        $output .= "BUSINESS RULES:\n";
        foreach ($scope['business_rules'] as $rule) {
            $output .= "- {$rule}\n";
        }
        $output .= "\n";
    }

    if (!empty($scope['examples'])) {
        $output .= "EXAMPLE QUESTIONS:\n";
        foreach ($scope['examples'] as $example) {
            $output .= "- {$example}\n";
        }
        $output .= "\n";
    }

    $output .= "---\n\n";

    return $output;
}

private function formatRelationshipPath(array $spec): string
{
    $path = "{$spec['start_entity']}";

    foreach ($spec['path'] as $step) {
        $arrow = $step['direction'] === 'outgoing' ? '->' : '<-';
        $path .= " {$arrow}[:{$step['relationship']}]{$arrow} {$step['target_entity']}";
    }

    return $path;
}
```

---

## Complete Configuration Examples

### Example 1: Person Entity with Declarative Scopes

```php
'Person' => [
    'graph' => [
        'label' => 'Person',
        'properties' => ['id', 'first_name', 'last_name', 'email', 'status'],
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
        'concept' => 'Individuals in the system including volunteers, staff, and customers',

        'aliases' => ['person', 'people', 'user', 'users', 'individual', 'member'],

        // Property semantic descriptions
        'properties' => [
            'status' => [
                'concept' => 'Current state of the person',
                'type' => 'categorical',
                'possible_values' => ['active', 'inactive', 'pending'],
                'business_meaning' => 'Active means person can access the system',
            ],
            'role_type' => [
                'concept' => 'Type of role on a team',
                'type' => 'categorical',
                'location' => 'PersonTeam entity',
                'possible_values' => ['volunteer', 'leader', 'coordinator', 'member'],
            ],
        ],

        // Relationship semantic descriptions
        'relationships' => [
            'HAS_ROLE' => [
                'concept' => 'Person has a role on a team',
                'target_entity' => 'PersonTeam',
                'direction' => 'outgoing',
                'cardinality' => 'one_to_many',
                'business_meaning' => 'Links person to team roles',
            ],
        ],

        // Declarative scopes (NO CYPHER)
        'scopes' => [

            // Simple property filter
            'active' => [
                'specification_type' => 'property_filter',
                'concept' => 'People with active status',
                'filter' => [
                    'property' => 'status',
                    'operator' => 'equals',
                    'value' => 'active',
                ],
                'business_rules' => [
                    'Person is active if status property equals "active"',
                ],
                'examples' => [
                    'Show active people',
                    'List active members',
                ],
            ],

            // Relationship traversal
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
                    'Person is volunteer if they have at least one volunteer role',
                    'Multiple volunteer roles = still one volunteer',
                ],
                'examples' => [
                    'Show me all volunteers',
                    'How many volunteers do we have?',
                ],
            ],

            // Pattern-based
            'people_without_teams' => [
                'specification_type' => 'pattern',
                'concept' => 'People who are not on any team',
                'pattern' => 'entity_without_relationship',
                'pattern_params' => [
                    'entity' => 'Person',
                    'relationship' => 'MEMBER_OF',
                    'target_entity' => 'Team',
                ],
                'business_rules' => [
                    'Person without teams has no MEMBER_OF relationship to Team',
                ],
                'examples' => [
                    'Show people without teams',
                    'List unassigned people',
                ],
            ],

        ],
    ],
],
```

### Example 2: Customer Entity with Aggregation

```php
'Customer' => [
    'graph' => [
        'label' => 'Customer',
        'properties' => ['id', 'name', 'email', 'created_at'],
        'relationships' => [
            ['type' => 'PLACED', 'target_label' => 'Order'],
        ],
    ],

    'metadata' => [
        'concept' => 'Individuals or organizations who purchase products',

        'aliases' => ['customer', 'customers', 'client', 'clients', 'buyer'],

        'properties' => [
            'created_at' => [
                'concept' => 'When customer account was created',
                'type' => 'datetime',
                'business_meaning' => 'Customer registration date',
            ],
        ],

        'relationships' => [
            'PLACED' => [
                'concept' => 'Customer placed an order',
                'target_entity' => 'Order',
                'direction' => 'outgoing',
                'cardinality' => 'one_to_many',
            ],
        ],

        'scopes' => [

            // Existence check
            'with_orders' => [
                'specification_type' => 'pattern',
                'concept' => 'Customers who have placed at least one order',
                'pattern' => 'entity_with_relationship',
                'pattern_params' => [
                    'entity' => 'Customer',
                    'relationship' => 'PLACED',
                    'target_entity' => 'Order',
                ],
                'business_rules' => [
                    'Customer with orders has at least one PLACED relationship',
                ],
                'examples' => [
                    'Show customers with orders',
                    'List customers who have ordered',
                ],
            ],

            // Aggregation
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

            // Temporal filter
            'recent' => [
                'specification_type' => 'pattern',
                'concept' => 'Customers who joined recently',
                'pattern' => 'temporal_filter',
                'pattern_params' => [
                    'entity' => 'Customer',
                    'date_property' => 'created_at',
                    'temporal_operator' => 'within_last',
                    'temporal_value' => '30 days',
                ],
                'business_rules' => [
                    'Recent customer joined within last 30 days',
                ],
                'examples' => [
                    'Show recent customers',
                    'List new customers',
                ],
            ],

        ],
    ],
],
```

### Example 7: Handling All Required Cases

```php
// 1. Simple property filter
'active_people' => [
    'specification_type' => 'property_filter',
    'concept' => 'People who are active',
    'filter' => [
        'property' => 'status',
        'operator' => 'equals',
        'value' => 'active',
    ],
],

// 2. Relationship traversal
'volunteers' => [
    'specification_type' => 'relationship_traversal',
    // ... (see above)
],

// 3. Multi-hop relationship
'people_managing_marketing_teams' => [
    'specification_type' => 'relationship_traversal',
    'concept' => 'People who manage marketing teams',
    'relationship_spec' => [
        'start_entity' => 'Person',
        'path' => [
            [
                'relationship' => 'MANAGES',
                'target_entity' => 'Team',
                'direction' => 'outgoing',
            ],
        ],
        'filter' => [
            'entity' => 'Team',
            'property' => 'department',
            'operator' => 'equals',
            'value' => 'Marketing',
        ],
        'return_distinct' => true,
    ],
],

// 4. Relationship + property combination
'active_volunteers' => [
    'specification_type' => 'relationship_traversal',
    'concept' => 'Active people who volunteer',
    'relationship_spec' => [
        'start_entity' => 'Person',
        'path' => [
            [
                'relationship' => 'HAS_ROLE',
                'target_entity' => 'PersonTeam',
                'direction' => 'outgoing',
            ],
        ],
        'filters' => [
            [
                'entity' => 'PersonTeam',
                'property' => 'role_type',
                'operator' => 'equals',
                'value' => 'volunteer',
            ],
            [
                'entity' => 'Person',
                'property' => 'status',
                'operator' => 'equals',
                'value' => 'active',
            ],
        ],
        'return_distinct' => true,
    ],
],

// 5. Aggregation
'high_value_customers' => [
    'specification_type' => 'pattern',
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
],

// 6. Exists pattern
'people_with_orders' => [
    'specification_type' => 'pattern',
    'pattern' => 'entity_with_relationship',
    'pattern_params' => [
        'entity' => 'Person',
        'relationship' => 'PLACED',
        'target_entity' => 'Order',
    ],
],

// 7. Not exists pattern
'people_without_teams' => [
    'specification_type' => 'pattern',
    'pattern' => 'entity_without_relationship',
    'pattern_params' => [
        'entity' => 'Person',
        'relationship' => 'MEMBER_OF',
        'target_entity' => 'Team',
    ],
],
```

---

## Implementation Approach

### Phase 1: Pattern Library Foundation

**File**: `src/Services/PatternLibrary.php`

```php
<?php

namespace AiSystem\Services;

class PatternLibrary
{
    private array $patterns;

    public function __construct(array $patterns = null)
    {
        $this->patterns = $patterns ?? $this->loadPatterns();
    }

    /**
     * Get pattern definition by name
     */
    public function getPattern(string $name): ?array
    {
        return $this->patterns[$name] ?? null;
    }

    /**
     * Get all available patterns
     */
    public function getAllPatterns(): array
    {
        return $this->patterns;
    }

    /**
     * Instantiate pattern with parameters
     */
    public function instantiatePattern(string $name, array $params): array
    {
        $pattern = $this->getPattern($name);

        if (!$pattern) {
            throw new \InvalidArgumentException("Unknown pattern: {$name}");
        }

        // Validate parameters
        $this->validatePatternParams($pattern, $params);

        // Build semantic description
        $description = $this->buildSemanticDescription($pattern, $params);

        return [
            'pattern_name' => $name,
            'pattern_def' => $pattern,
            'parameters' => $params,
            'semantic_description' => $description,
        ];
    }

    /**
     * Load patterns from config
     */
    private function loadPatterns(): array
    {
        if (function_exists('config')) {
            return config('ai.query_patterns', []);
        }

        return [];
    }

    /**
     * Validate pattern parameters
     */
    private function validatePatternParams(array $pattern, array $params): void
    {
        $required = $pattern['parameters'] ?? [];

        foreach ($required as $param => $description) {
            if (!isset($params[$param])) {
                throw new \InvalidArgumentException(
                    "Missing required parameter '{$param}' for pattern"
                );
            }
        }
    }

    /**
     * Build semantic description from pattern template
     */
    private function buildSemanticDescription(array $pattern, array $params): string
    {
        $template = $pattern['semantic_template'] ?? '';

        foreach ($params as $key => $value) {
            $placeholder = '{' . $key . '}';
            $template = str_replace($placeholder, $value, $template);
        }

        return $template;
    }
}
```

### Phase 2: Enhanced ContextRetriever

Update `getEntityMetadata()` to handle semantic specifications:

```php
public function getEntityMetadata(string $question): array
{
    $questionLower = strtolower($question);
    $detectedEntities = [];
    $detectedScopes = [];
    $entityMetadata = [];

    foreach ($this->entityConfigs as $entityName => $config) {
        $metadata = $config['metadata'] ?? null;

        if (!$metadata) {
            continue;
        }

        $isDetected = false;

        // Check entity name
        if (stripos($question, $entityName) !== false) {
            $isDetected = true;
        }

        // Check aliases
        if (!$isDetected && !empty($metadata['aliases'])) {
            foreach ($metadata['aliases'] as $alias) {
                if (strpos($questionLower, strtolower($alias)) !== false) {
                    $isDetected = true;
                    break;
                }
            }
        }

        // Check for scope terms
        if (!empty($metadata['scopes'])) {
            foreach ($metadata['scopes'] as $scopeName => $scopeConfig) {
                if (strpos($questionLower, strtolower($scopeName)) !== false) {
                    $isDetected = true;

                    // Record detected scope with full semantic context
                    $detectedScopes[$scopeName] = [
                        'entity' => $entityName,
                        'scope' => $scopeName,
                        'specification_type' => $scopeConfig['specification_type'] ?? 'property_filter',
                        'concept' => $scopeConfig['concept'] ?? '',
                        'relationship_spec' => $scopeConfig['relationship_spec'] ?? null,
                        'filter' => $scopeConfig['filter'] ?? null,
                        'pattern' => $scopeConfig['pattern'] ?? null,
                        'pattern_params' => $scopeConfig['pattern_params'] ?? null,
                        'business_rules' => $scopeConfig['business_rules'] ?? [],
                        'examples' => $scopeConfig['examples'] ?? [],
                    ];
                }
            }
        }

        if ($isDetected) {
            $detectedEntities[] = $entityName;
            $entityMetadata[$entityName] = $metadata;
        }
    }

    return [
        'detected_entities' => $detectedEntities,
        'entity_metadata' => $entityMetadata,
        'detected_scopes' => $detectedScopes,
    ];
}
```

### Phase 3: Semantic Prompt Builder

New service to build LLM prompts from semantic metadata:

```php
<?php

namespace AiSystem\Services;

class SemanticPromptBuilder
{
    private PatternLibrary $patternLibrary;

    public function __construct(PatternLibrary $patternLibrary)
    {
        $this->patternLibrary = $patternLibrary;
    }

    /**
     * Build semantic prompt for LLM
     */
    public function buildPrompt(
        string $question,
        array $context,
        bool $allowWrite = false
    ): string {
        $prompt = "You are a Neo4j Cypher query expert.\n\n";

        // Add graph schema
        $prompt .= $this->formatGraphSchema($context['graph_schema'] ?? []);

        // Add detected scopes with semantic context
        if (!empty($context['entity_metadata']['detected_scopes'])) {
            $prompt .= "\n=== DETECTED BUSINESS CONCEPTS ===\n\n";

            foreach ($context['entity_metadata']['detected_scopes'] as $scope) {
                $prompt .= $this->formatSemanticScope($scope);
            }
        }

        // Add pattern library documentation
        $prompt .= "\n=== AVAILABLE QUERY PATTERNS ===\n\n";
        $prompt .= $this->formatPatternLibrary();

        // Add query generation rules
        $prompt .= "\n=== QUERY GENERATION RULES ===\n\n";
        $prompt .= $this->formatQueryRules($allowWrite);

        // Add question
        $prompt .= "\nUSER QUESTION: {$question}\n\n";

        // Request generation
        $prompt .= "Generate a Cypher query that:\n";
        $prompt .= "1. Respects all business rules from detected concepts\n";
        $prompt .= "2. Uses appropriate patterns from the library\n";
        $prompt .= "3. Follows Neo4j best practices\n";
        $prompt .= "4. Returns only the Cypher query\n\n";
        $prompt .= "CYPHER QUERY:";

        return $prompt;
    }

    private function formatSemanticScope(array $scope): string
    {
        $output = "SCOPE: {$scope['scope']}\n";
        $output .= "ENTITY: {$scope['entity']}\n";
        $output .= "TYPE: {$scope['specification_type']}\n\n";

        $output .= "CONCEPT:\n{$scope['concept']}\n\n";

        // Format based on specification type
        switch ($scope['specification_type']) {
            case 'relationship_traversal':
                $output .= $this->formatRelationshipSpec($scope['relationship_spec']);
                break;

            case 'property_filter':
                $output .= $this->formatPropertyFilter($scope['filter']);
                break;

            case 'pattern':
                $output .= $this->formatPatternSpec($scope['pattern'], $scope['pattern_params']);
                break;
        }

        // Add business rules
        if (!empty($scope['business_rules'])) {
            $output .= "\nBUSINESS RULES:\n";
            foreach ($scope['business_rules'] as $rule) {
                $output .= "- {$rule}\n";
            }
        }

        // Add examples
        if (!empty($scope['examples'])) {
            $output .= "\nEXAMPLE QUESTIONS:\n";
            foreach ($scope['examples'] as $example) {
                $output .= "- {$example}\n";
            }
        }

        $output .= "\n---\n\n";

        return $output;
    }

    private function formatRelationshipSpec(array $spec): string
    {
        $output = "RELATIONSHIP PATH:\n";

        $path = "{$spec['start_entity']}";
        foreach ($spec['path'] as $step) {
            $arrow = $step['direction'] === 'outgoing' ? '->' : '<-';
            $path .= " {$arrow}[:{$step['relationship']}]{$arrow} {$step['target_entity']}";
        }

        $output .= "{$path}\n\n";

        if (!empty($spec['filter'])) {
            $output .= "FILTER:\n";
            $output .= "{$spec['filter']['entity']}.{$spec['filter']['property']} ";
            $output .= "{$spec['filter']['operator']} '{$spec['filter']['value']}'\n";
        }

        if (!empty($spec['return_distinct'])) {
            $output .= "\nNOTE: Return DISTINCT results to avoid duplicates\n";
        }

        return $output;
    }

    private function formatPropertyFilter(array $filter): string
    {
        return "FILTER:\n{$filter['property']} {$filter['operator']} '{$filter['value']}'\n";
    }

    private function formatPatternSpec(string $patternName, array $params): string
    {
        $pattern = $this->patternLibrary->getPattern($patternName);

        if (!$pattern) {
            return "PATTERN: {$patternName}\nPARAMETERS: " . json_encode($params) . "\n";
        }

        $output = "PATTERN: {$patternName}\n";
        $output .= "DESCRIPTION: {$pattern['description']}\n\n";

        $output .= "PARAMETERS:\n";
        foreach ($params as $key => $value) {
            $output .= "- {$key}: {$value}\n";
        }

        // Build semantic description
        $template = $pattern['semantic_template'] ?? '';
        foreach ($params as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }

        $output .= "\nSEMANTIC MEANING:\n{$template}\n";

        return $output;
    }

    private function formatGraphSchema(array $schema): string
    {
        if (empty($schema)) {
            return '';
        }

        $output = "=== GRAPH SCHEMA ===\n\n";

        if (!empty($schema['labels'])) {
            $output .= "Node Labels: " . implode(', ', $schema['labels']) . "\n";
        }

        if (!empty($schema['relationships'])) {
            $output .= "Relationships: " . implode(', ', $schema['relationships']) . "\n";
        }

        return $output . "\n";
    }

    private function formatPatternLibrary(): string
    {
        $patterns = $this->patternLibrary->getAllPatterns();

        if (empty($patterns)) {
            return '';
        }

        $output = "";

        foreach ($patterns as $name => $pattern) {
            $output .= "PATTERN: {$name}\n";
            $output .= "DESCRIPTION: {$pattern['description']}\n";
            $output .= "TEMPLATE: {$pattern['semantic_template']}\n\n";
        }

        return $output;
    }

    private function formatQueryRules(bool $allowWrite): string
    {
        $rules = "1. Use only labels and relationships from the schema\n";
        $rules .= "2. Respect business rules from detected concepts\n";
        $rules .= "3. Use DISTINCT when traversing relationships to avoid duplicates\n";
        $rules .= "4. Always include LIMIT clause to prevent large result sets\n";
        $rules .= "5. Return only the Cypher query (no markdown, no explanations)\n";

        if (!$allowWrite) {
            $rules .= "6. NO write operations (DELETE, CREATE, MERGE, SET, etc.)\n";
        }

        return $rules;
    }
}
```

### Phase 4: Update QueryGenerator

Integrate semantic prompt builder:

```php
public function __construct(
    private readonly LlmProviderInterface $llm,
    private readonly GraphStoreInterface $graphStore,
    private readonly array $config = [],
    private readonly ?SemanticPromptBuilder $promptBuilder = null
) {
    $this->promptBuilder = $promptBuilder ?? new SemanticPromptBuilder(
        new PatternLibrary()
    );
}

private function buildPrompt(
    string $question,
    array $context,
    bool $allowWrite,
    ?string $previousError
): string {
    // Use semantic prompt builder if scopes detected
    if (!empty($context['entity_metadata']['detected_scopes'])) {
        $prompt = $this->promptBuilder->buildPrompt($question, $context, $allowWrite);

        // Add retry context if needed
        if ($previousError) {
            $prompt .= "\n\nPrevious attempt failed: {$previousError}\n";
            $prompt .= "Please fix the error and regenerate.\n\n";
            $prompt .= "CYPHER QUERY:";
        }

        return $prompt;
    }

    // Fallback to original prompt building
    return $this->buildOriginalPrompt($question, $context, $allowWrite, $previousError);
}
```

---

## Migration Guide

### Step 1: Add Pattern Library to Config

Add to `config/ai.php`:

```php
'query_patterns' => [
    'property_filter' => [...],
    'relationship_traversal' => [...],
    'entity_with_aggregated_relationship' => [...],
    // ... (see Layer 2 above)
],
```

### Step 2: Convert Existing Scopes

**Before (Concrete):**

```php
'volunteers' => [
    'description' => 'People who volunteer',
    'filter' => ['type' => 'volunteer'],
    'cypher_pattern' => "type = 'volunteer'",
],
```

**After (Semantic):**

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
        'Person is volunteer if they have at least one volunteer role',
    ],
    'examples' => [
        'Show me all volunteers',
    ],
],
```

### Step 3: Add Semantic Property Descriptions

```php
'properties' => [
    'status' => [
        'concept' => 'Current state',
        'type' => 'categorical',
        'possible_values' => ['active', 'inactive'],
    ],
    // ... other properties
],
```

### Step 4: Add Relationship Descriptions

```php
'relationships' => [
    'HAS_ROLE' => [
        'concept' => 'Person has role on team',
        'target_entity' => 'PersonTeam',
        'direction' => 'outgoing',
    ],
    // ... other relationships
],
```

### Step 5: Test Incrementally

1. Start with one entity
2. Convert one scope at a time
3. Test query generation
4. Verify business rules are respected
5. Expand to other entities

---

## Validation & Testing

### Unit Tests

```php
public function test_pattern_library_instantiation()
{
    $library = new PatternLibrary();

    $result = $library->instantiatePattern('property_filter', [
        'entity' => 'Person',
        'property' => 'status',
        'operator' => 'equals',
        'value' => 'active',
    ]);

    $this->assertEquals('property_filter', $result['pattern_name']);
    $this->assertStringContainsString('status equals active',
        $result['semantic_description']);
}

public function test_semantic_scope_detection()
{
    $retriever = new ContextRetriever(...);

    $metadata = $retriever->getEntityMetadata('Show me all volunteers');

    $this->assertArrayHasKey('volunteers', $metadata['detected_scopes']);
    $this->assertEquals('relationship_traversal',
        $metadata['detected_scopes']['volunteers']['specification_type']);
}

public function test_semantic_prompt_building()
{
    $builder = new SemanticPromptBuilder(new PatternLibrary());

    $context = [
        'entity_metadata' => [
            'detected_scopes' => [
                'volunteers' => [
                    'specification_type' => 'relationship_traversal',
                    'concept' => 'People who volunteer',
                    // ...
                ],
            ],
        ],
    ];

    $prompt = $builder->buildPrompt('Show volunteers', $context);

    $this->assertStringContainsString('BUSINESS CONCEPT', $prompt);
    $this->assertStringContainsString('RELATIONSHIP PATH', $prompt);
    $this->assertStringContainsString('BUSINESS RULES', $prompt);
}
```

### Integration Tests

```php
public function test_generates_query_from_semantic_scope()
{
    $question = 'How many volunteers do we have?';
    $context = $this->retriever->retrieveContext($question);

    $result = $this->generator->generate($question, $context);

    // Verify relationship traversal
    $this->assertStringContainsString('HAS_ROLE', $result['cypher']);
    $this->assertStringContainsString('PersonTeam', $result['cypher']);
    $this->assertStringContainsString('role_type', $result['cypher']);
    $this->assertStringContainsString('DISTINCT', $result['cypher']);
}

public function test_pattern_based_query_generation()
{
    $question = 'Show high value customers';
    $context = $this->retriever->retrieveContext($question);

    $result = $this->generator->generate($question, $context);

    // Verify aggregation pattern
    $this->assertStringContainsString('sum(', $result['cypher']);
    $this->assertStringContainsString('PLACED', $result['cypher']);
    $this->assertStringContainsString('10000', $result['cypher']);
}
```

---

## Success Metrics

### Configuration Quality

- ✅ Zero Cypher patterns in entity config
- ✅ All scopes have semantic descriptions
- ✅ Business rules documented in plain language
- ✅ Pattern library covers 90%+ of use cases

### System Performance

- ✅ LLM generates correct queries 95%+ of time
- ✅ Query generation time < 2 seconds
- ✅ No manual Cypher editing required
- ✅ Non-technical users can add scopes

### Maintainability

- ✅ Adding new scope = config change only
- ✅ Schema changes don't break scopes
- ✅ Pattern library is reusable across domains
- ✅ Self-documenting configuration

---

## Best Practices

### Writing Semantic Scopes

1. **Use Natural Language**
   - Describe business concepts clearly
   - Avoid technical jargon
   - Focus on WHAT, not HOW

2. **Provide Business Rules**
   - Explain the logic
   - Document edge cases
   - Clarify ambiguities

3. **Include Examples**
   - Multiple phrasings
   - Different question formats
   - Edge case queries

4. **Choose Right Specification Type**
   - Property filter: Simple attribute checks
   - Relationship traversal: Graph traversal
   - Pattern: Complex logic, aggregations

### Extending Pattern Library

1. **Identify Common Patterns**
   - Look for repeated query structures
   - Abstract to generic parameters
   - Document use cases

2. **Create Semantic Templates**
   - Human-readable descriptions
   - Parameter placeholders
   - Clear examples

3. **Validate Patterns**
   - Test with multiple entities
   - Verify parameter combinations
   - Document limitations

---

## Future Enhancements

### Phase 1: Pattern Learning

- Automatically suggest patterns from successful queries
- Learn common query structures
- Propose new scope definitions

### Phase 2: Pattern Composition

- Combine multiple patterns
- Build complex scopes from simple ones
- Reuse pattern fragments

### Phase 3: Visual Configuration

- UI for building semantic scopes
- Visual relationship path builder
- Pattern template selector

### Phase 4: Multi-Database Support

- Extend patterns to other graph databases
- Abstract database-specific syntax
- Unified semantic layer

---

## Conclusion

This redesign achieves the goal of **maximally configurable, minimally concrete** metadata:

1. **Configuration = Business Logic** - No technical syntax
2. **Pattern Library = Reusable Templates** - Generic, domain-agnostic
3. **LLM = Intelligence Layer** - Interprets semantics, generates queries
4. **Self-Documenting** - Configuration explains itself
5. **Extensible** - New use cases = config changes only

The system now separates concerns cleanly:

- **Config authors** define business concepts
- **Pattern library** provides reusable query structures
- **LLM** combines context to generate queries
- **Code** provides infrastructure only

This approach works for ANY domain without hardcoding business logic.

---

**Document Version**: 2.0
**Status**: Ready for Implementation
**Breaking Changes**: Yes (config format changes)
**Migration Required**: Yes (see Migration Guide)
**Backward Compatible**: Optional (can support both formats during transition)
