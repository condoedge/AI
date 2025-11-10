# Relationship-Based Scopes Enhancement - Design Document

## Executive Summary

This document specifies the enhancement of the Entity Metadata System to support **relationship-based scopes** in graph databases. The current system only supports simple property filters (e.g., `type = 'volunteer'`), but real-world domain models often require traversing relationships to define business concepts.

**Example Problem:**
- Business Term: "volunteers"
- Current System: `MATCH (p:Person) WHERE p.type = 'volunteer'`
- Reality: `MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team) WHERE pt.role_type = 'volunteer'`

This enhancement enables the system to handle complex graph patterns while maintaining backward compatibility with simple property filters.

## Architecture Overview

### Design Principles

1. **Backward Compatibility**: Existing simple property filters continue to work without changes
2. **Progressive Enhancement**: Add relationship support without breaking existing functionality
3. **Explicit Over Implicit**: Clear pattern types prevent ambiguity
4. **LLM-Friendly**: Provide patterns in formats LLMs can easily understand and apply
5. **Maintainable**: Structured configuration over opaque Cypher strings

### Three Pattern Types

We introduce a pattern type system to distinguish complexity levels:

```php
'pattern_type' => 'simple'        // Property filters only (existing behavior)
'pattern_type' => 'relationship'  // Traverse relationships (NEW)
'pattern_type' => 'complex'       // Custom Cypher templates (NEW - use sparingly)
```

## Enhanced Metadata Schema

### 1. Simple Pattern (Existing - Backward Compatible)

For straightforward property filters:

```php
'active' => [
    'pattern_type' => 'simple',  // Optional - default if not specified
    'description' => 'People with active status',
    'filter' => ['status' => 'active'],
    'cypher_pattern' => "p.status = 'active'",
    'examples' => [
        'Show active people',
        'List active members',
    ],
],
```

**Usage**: Direct property comparisons on the entity node.

**When LLM sees this**: Apply as WHERE clause on the entity node.

### 2. Relationship Pattern (NEW - Primary Enhancement)

For scopes requiring relationship traversal:

```php
'volunteers' => [
    'pattern_type' => 'relationship',
    'description' => 'People with volunteer role on any team',

    // Structured approach (recommended)
    'relationship' => [
        'pattern' => '(p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)',
        'where' => "pt.role_type = 'volunteer'",
        'return_distinct' => true,
    ],

    // Alternative: Raw Cypher pattern (for complex cases)
    'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
CYPHER,

    'examples' => [
        'Show me all volunteers',
        'How many volunteers do we have?',
        'List volunteers on teams',
    ],
],
```

**Key Fields:**
- `pattern`: The MATCH clause pattern (node in parentheses will be replaced with entity variable)
- `where`: Additional WHERE conditions on relationship/intermediate nodes
- `return_distinct`: Whether to use DISTINCT (important for multi-relationship traversals)

**When LLM sees this**: Use the complete pattern as-is, then add any additional filters.

### 3. Complex Pattern (NEW - Use Sparingly)

For advanced scenarios with aggregations, subqueries, or conditional logic:

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
        'Show me high value customers',
        'List customers with over $10k in orders',
    ],

    // Guidance for LLM on how to modify this pattern
    'modification_guidance' => 'To add filters, modify the final WHERE clause or add conditions after WITH',
],
```

**When to use**: Aggregations, calculations, or patterns that can't be expressed structurally.

**When LLM sees this**: Use template as base, minimal modifications allowed.

## Complete Configuration Examples

### Example 1: Person Entity with Relationship-Based Volunteers

```php
'Person' => [
    'graph' => [
        'label' => 'Person',
        'properties' => ['id', 'first_name', 'last_name', 'email', 'status'],
        'relationships' => [
            [
                'type' => 'HAS_ROLE',
                'target_label' => 'PersonTeam',
                'description' => 'Person has a role on a team',
            ],
            [
                'type' => 'MEMBER_OF',
                'target_label' => 'Team',
                'description' => 'Direct team membership',
            ],
        ],
    ],

    'vector' => [
        'collection' => 'people',
        'embed_fields' => ['first_name', 'last_name', 'bio'],
        'metadata' => ['id', 'email', 'status'],
    ],

    'metadata' => [
        'aliases' => ['person', 'people', 'user', 'users', 'individual', 'member'],
        'description' => 'Individuals in the system including volunteers, customers, and staff',

        'scopes' => [
            // Simple property filter (existing pattern)
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

            // Relationship-based scope (NEW)
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
                    'Who are the volunteers?',
                ],
            ],

            // Another relationship scope
            'team_leaders' => [
                'pattern_type' => 'relationship',
                'description' => 'People who lead teams',
                'relationship' => [
                    'pattern' => '(p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)',
                    'where' => "pt.role_type = 'leader'",
                    'return_distinct' => true,
                ],
                'cypher_pattern' => <<<CYPHER
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'leader'
RETURN DISTINCT p
CYPHER,
                'examples' => [
                    'Show me team leaders',
                    'List people who lead teams',
                    'Who are the team leaders?',
                ],
            ],

            // Combined: relationship + property filter
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
                'examples' => [
                    'Show active volunteers',
                    'List volunteers who are active',
                ],
            ],
        ],

        'common_properties' => [
            'id' => 'Unique identifier',
            'first_name' => 'Person\'s first name',
            'last_name' => 'Person\'s last name',
            'email' => 'Email address',
            'status' => 'Current status: active, inactive, suspended',
        ],

        // Relationship documentation (NEW)
        'relationships' => [
            'HAS_ROLE' => [
                'description' => 'Person has a role on a team through PersonTeam',
                'target' => 'PersonTeam',
                'common_patterns' => [
                    'Finding volunteers: (p)-[:HAS_ROLE]->(pt:PersonTeam) WHERE pt.role_type = "volunteer"',
                    'Finding leaders: (p)-[:HAS_ROLE]->(pt:PersonTeam) WHERE pt.role_type = "leader"',
                ],
            ],
            'MEMBER_OF' => [
                'description' => 'Direct team membership',
                'target' => 'Team',
                'common_patterns' => [
                    'Team members: (p)-[:MEMBER_OF]->(t:Team)',
                ],
            ],
        ],
    ],
],
```

### Example 2: Customer Entity with Order Relationships

```php
'Customer' => [
    'graph' => [
        'label' => 'Customer',
        'properties' => ['id', 'name', 'email', 'created_at'],
        'relationships' => [
            [
                'type' => 'PLACED',
                'target_label' => 'Order',
            ],
        ],
    ],

    'metadata' => [
        'aliases' => ['customer', 'customers', 'client', 'clients'],
        'description' => 'Customers who have placed orders',

        'scopes' => [
            // Relationship scope: customers with orders
            'with_orders' => [
                'pattern_type' => 'relationship',
                'description' => 'Customers who have placed at least one order',
                'relationship' => [
                    'pattern' => '(c:Customer)-[:PLACED]->(o:Order)',
                    'return_distinct' => true,
                ],
                'cypher_pattern' => <<<CYPHER
MATCH (c:Customer)-[:PLACED]->(o:Order)
RETURN DISTINCT c
CYPHER,
                'examples' => [
                    'Show customers with orders',
                    'List customers who have ordered',
                ],
            ],

            // Complex scope: high-value customers
            'high_value' => [
                'pattern_type' => 'complex',
                'description' => 'Customers with total order value exceeding $10,000',
                'cypher_template' => <<<CYPHER
MATCH (c:Customer)-[:PLACED]->(o:Order)
WITH c, sum(o.total) as total_value
WHERE total_value > 10000
RETURN c
CYPHER,
                'examples' => [
                    'Show high value customers',
                    'List customers with over $10k in orders',
                ],
                'modification_guidance' => 'To change threshold, modify the WHERE total_value comparison',
            ],

            // Relationship scope with intermediate node filter
            'recent_customers' => [
                'pattern_type' => 'relationship',
                'description' => 'Customers with orders in the last 30 days',
                'relationship' => [
                    'pattern' => '(c:Customer)-[:PLACED]->(o:Order)',
                    'where' => 'o.created_at > datetime() - duration({days: 30})',
                    'return_distinct' => true,
                ],
                'cypher_pattern' => <<<CYPHER
MATCH (c:Customer)-[:PLACED]->(o:Order)
WHERE o.created_at > datetime() - duration({days: 30})
RETURN DISTINCT c
CYPHER,
                'examples' => [
                    'Show recent customers',
                    'List customers who ordered recently',
                ],
            ],
        ],

        'common_properties' => [
            'id' => 'Unique customer identifier',
            'name' => 'Customer name',
            'email' => 'Customer email address',
            'created_at' => 'When customer account was created',
        ],

        'relationships' => [
            'PLACED' => [
                'description' => 'Customer placed an order',
                'target' => 'Order',
                'common_patterns' => [
                    'Customers with orders: (c)-[:PLACED]->(o:Order)',
                    'Recent orders: (c)-[:PLACED]->(o:Order) WHERE o.created_at > ...',
                ],
            ],
        ],
    ],
],
```

### Example 3: Order Entity with Multiple Relationship Patterns

```php
'Order' => [
    'graph' => [
        'label' => 'Order',
        'properties' => ['id', 'total', 'status', 'created_at'],
        'relationships' => [
            [
                'type' => 'PLACED_BY',
                'target_label' => 'Customer',
            ],
            [
                'type' => 'CONTAINS',
                'target_label' => 'Product',
            ],
        ],
    ],

    'metadata' => [
        'aliases' => ['order', 'orders', 'purchase', 'purchases'],
        'description' => 'Customer orders',

        'scopes' => [
            // Simple property filter
            'pending' => [
                'pattern_type' => 'simple',
                'description' => 'Orders awaiting processing',
                'filter' => ['status' => 'pending'],
                'cypher_pattern' => "o.status = 'pending'",
                'examples' => ['Show pending orders'],
            ],

            // Relationship scope: orders with specific product
            'with_product' => [
                'pattern_type' => 'relationship',
                'description' => 'Orders containing specific products',
                'relationship' => [
                    'pattern' => '(o:Order)-[:CONTAINS]->(p:Product)',
                    'return_distinct' => true,
                ],
                'cypher_pattern' => <<<CYPHER
MATCH (o:Order)-[:CONTAINS]->(p:Product)
RETURN DISTINCT o
CYPHER,
                'examples' => [
                    'Show orders with products',
                    'List orders containing items',
                ],
            ],

            // Complex: large orders (multi-product aggregation)
            'large_orders' => [
                'pattern_type' => 'complex',
                'description' => 'Orders with more than 5 products',
                'cypher_template' => <<<CYPHER
MATCH (o:Order)-[:CONTAINS]->(p:Product)
WITH o, count(p) as product_count
WHERE product_count > 5
RETURN o
CYPHER,
                'examples' => [
                    'Show large orders',
                    'List orders with many products',
                ],
            ],
        ],

        'common_properties' => [
            'id' => 'Order identifier',
            'total' => 'Total order amount',
            'status' => 'Order status: pending, completed, cancelled',
            'created_at' => 'When order was placed',
        ],

        'relationships' => [
            'PLACED_BY' => [
                'description' => 'Customer who placed the order',
                'target' => 'Customer',
            ],
            'CONTAINS' => [
                'description' => 'Products in the order',
                'target' => 'Product',
            ],
        ],
    ],
],
```

## Enhanced Context Format for LLM

### Current Format (Simple Patterns)

```
Detected Business Terms (Scopes):
- 'volunteers' means People who volunteer → Use filter: type = 'volunteer'
- 'active' means People with active status → Use filter: status = 'active'
```

### Enhanced Format (With Relationship Patterns)

```
Detected Business Terms (Scopes):

SIMPLE PROPERTY FILTERS (use in WHERE clause):
- 'active' means People with active status
  → WHERE p.status = 'active'

RELATIONSHIP PATTERNS (use complete MATCH pattern):
- 'volunteers' means People with volunteer role on teams
  → This requires traversing relationships:

  MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
  WHERE pt.role_type = 'volunteer'
  RETURN DISTINCT p

  IMPORTANT: This CANNOT be expressed as a simple property filter on Person.
  You MUST use this exact relationship pattern.

  To add additional filters, extend the WHERE clause:
  - For person properties: AND p.property = value
  - For team filters: AND t.property = value

  To modify the return:
  - Count: RETURN count(DISTINCT p)
  - With team: RETURN DISTINCT p, t
  - Specific fields: RETURN DISTINCT p.id, p.name

- 'team_leaders' means People who lead teams
  → Relationship pattern:

  MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
  WHERE pt.role_type = 'leader'
  RETURN DISTINCT p

COMPLEX PATTERNS (use as template, minimal modifications):
- 'high_value_customers' means Customers with orders over $10,000
  → Template (use as-is):

  MATCH (c:Customer)-[:PLACED]->(o:Order)
  WITH c, sum(o.total) as total_value
  WHERE total_value > 10000
  RETURN c

  To modify: Adjust the WHERE total_value comparison or add conditions after WITH.
```

## Enhanced ContextRetriever Implementation

### Method: formatEntityMetadataForLLM()

Add a new method to format metadata specifically for LLM consumption:

```php
/**
 * Format entity metadata for LLM prompt
 *
 * Converts detected scopes into LLM-friendly format with clear guidance
 * on how to use simple vs. relationship vs. complex patterns.
 *
 * @param array $detectedScopes Detected scopes from getEntityMetadata()
 * @return string Formatted text for LLM prompt
 */
private function formatEntityMetadataForLLM(array $detectedScopes): string
{
    if (empty($detectedScopes)) {
        return '';
    }

    $output = "Detected Business Terms (Scopes):\n\n";

    // Group by pattern type
    $simpleScopes = [];
    $relationshipScopes = [];
    $complexScopes = [];

    foreach ($detectedScopes as $scopeName => $scopeInfo) {
        $patternType = $scopeInfo['pattern_type'] ?? 'simple';

        switch ($patternType) {
            case 'relationship':
                $relationshipScopes[$scopeName] = $scopeInfo;
                break;
            case 'complex':
                $complexScopes[$scopeName] = $scopeInfo;
                break;
            default:
                $simpleScopes[$scopeName] = $scopeInfo;
        }
    }

    // Format simple scopes
    if (!empty($simpleScopes)) {
        $output .= "SIMPLE PROPERTY FILTERS (use in WHERE clause):\n";
        foreach ($simpleScopes as $scopeName => $info) {
            $entity = $info['entity'];
            $variable = strtolower(substr($entity, 0, 1));
            $output .= "- '{$scopeName}' means {$info['description']}\n";
            $output .= "  → WHERE {$info['cypher_pattern']}\n\n";
        }
    }

    // Format relationship scopes
    if (!empty($relationshipScopes)) {
        $output .= "RELATIONSHIP PATTERNS (use complete MATCH pattern):\n";
        foreach ($relationshipScopes as $scopeName => $info) {
            $output .= "- '{$scopeName}' means {$info['description']}\n";
            $output .= "  → This requires traversing relationships:\n\n";
            $output .= "  {$info['cypher_pattern']}\n\n";
            $output .= "  IMPORTANT: This CANNOT be expressed as a simple property filter.\n";
            $output .= "  You MUST use this exact relationship pattern.\n\n";
            $output .= "  To add additional filters, extend the WHERE clause.\n";
            $output .= "  To modify the return, change the RETURN clause (e.g., count, fields).\n\n";
        }
    }

    // Format complex scopes
    if (!empty($complexScopes)) {
        $output .= "COMPLEX PATTERNS (use as template, minimal modifications):\n";
        foreach ($complexScopes as $scopeName => $info) {
            $output .= "- '{$scopeName}' means {$info['description']}\n";
            $output .= "  → Template (use as-is):\n\n";
            $output .= "  {$info['cypher_template']}\n\n";
            if (!empty($info['modification_guidance'])) {
                $output .= "  Modification guidance: {$info['modification_guidance']}\n\n";
            }
        }
    }

    return $output;
}
```

### Updated getEntityMetadata() to Include Pattern Type

```php
// In getEntityMetadata() method, update scope detection to include pattern_type
if (!empty($metadata['scopes'])) {
    foreach ($metadata['scopes'] as $scopeName => $scopeConfig) {
        if (strpos($questionLower, strtolower($scopeName)) !== false) {
            $isDetected = true;

            // Record the detected scope with pattern type
            $detectedScopes[$scopeName] = [
                'entity' => $entityName,
                'scope' => $scopeName,
                'description' => $scopeConfig['description'] ?? '',
                'pattern_type' => $scopeConfig['pattern_type'] ?? 'simple',
                'cypher_pattern' => $scopeConfig['cypher_pattern'] ?? '',
                'cypher_template' => $scopeConfig['cypher_template'] ?? '',
                'filter' => $scopeConfig['filter'] ?? [],
                'relationship' => $scopeConfig['relationship'] ?? null,
                'modification_guidance' => $scopeConfig['modification_guidance'] ?? '',
            ];
        }
    }
}
```

## Enhanced QueryGenerator Prompt

### Updated buildPrompt() Method

```php
private function buildPrompt(string $question, array $context, bool $allowWrite, ?string $previousError): string
{
    $prompt = "You are a Neo4j Cypher query expert. Generate a valid, safe Cypher query.\n\n";

    // Add graph schema
    if (!empty($context['graph_schema'])) {
        $prompt .= "Graph Schema:\n";
        $prompt .= "Labels: " . implode(', ', $context['graph_schema']['labels'] ?? []) . "\n";
        $prompt .= "Relationships: " . implode(', ', $context['graph_schema']['relationships'] ?? []) . "\n\n";
    }

    // Add entity metadata with enhanced relationship pattern guidance
    if (!empty($context['entity_metadata']['detected_scopes'])) {
        $prompt .= $this->formatScopesForPrompt($context['entity_metadata']['detected_scopes']);
    }

    // Add relationship documentation if available
    if (!empty($context['entity_metadata']['entity_metadata'])) {
        $prompt .= "Common Relationship Patterns:\n";
        foreach ($context['entity_metadata']['entity_metadata'] as $entityName => $entityMeta) {
            if (!empty($entityMeta['relationships'])) {
                foreach ($entityMeta['relationships'] as $relType => $relInfo) {
                    $prompt .= "- {$relType}: {$relInfo['description']}\n";
                    if (!empty($relInfo['common_patterns'])) {
                        foreach ($relInfo['common_patterns'] as $pattern) {
                            $prompt .= "  Example: {$pattern}\n";
                        }
                    }
                }
            }
        }
        $prompt .= "\n";
    }

    // Add similar queries
    if (!empty($context['similar_queries'])) {
        $prompt .= "Similar Past Queries:\n";
        foreach (array_slice($context['similar_queries'], 0, 3) as $similar) {
            $prompt .= "- " . ($similar['question'] ?? '') . " → " . ($similar['query'] ?? '') . "\n";
        }
        $prompt .= "\n";
    }

    // Add rules with relationship pattern guidance
    $prompt .= "Rules:\n";
    $prompt .= "1. Use only labels/relationships from the schema\n";
    $prompt .= "2. When using RELATIONSHIP PATTERNS from detected scopes:\n";
    $prompt .= "   - Use the EXACT MATCH pattern provided\n";
    $prompt .= "   - These patterns define business concepts through graph traversal\n";
    $prompt .= "   - You can extend the WHERE clause but NOT change the MATCH pattern\n";
    $prompt .= "   - Always use DISTINCT when returning nodes from relationship traversals\n";
    $prompt .= "3. When using SIMPLE FILTERS from detected scopes:\n";
    $prompt .= "   - Apply as WHERE conditions on the entity node\n";
    $prompt .= "   - Can be combined with other filters using AND/OR\n";
    $prompt .= "4. Return ONLY the Cypher query (no explanations)\n";
    $prompt .= "5. Always include LIMIT to prevent large result sets\n";

    if (!$allowWrite) {
        $prompt .= "6. NO DELETE, DROP, CREATE, MERGE, SET, or other write operations\n";
    }

    // Add retry context
    if ($previousError) {
        $prompt .= "\nPrevious attempt failed with error: {$previousError}\n";
        $prompt .= "Please fix the error and try again.\n";
    }

    $prompt .= "\nQuestion: {$question}\n\n";
    $prompt .= "Generate Cypher query:";

    return $prompt;
}

/**
 * Format detected scopes for LLM prompt
 */
private function formatScopesForPrompt(array $detectedScopes): string
{
    if (empty($detectedScopes)) {
        return '';
    }

    $output = "Detected Business Terms (Scopes):\n\n";

    // Group by pattern type
    $grouped = [
        'simple' => [],
        'relationship' => [],
        'complex' => [],
    ];

    foreach ($detectedScopes as $scopeName => $scopeInfo) {
        $patternType = $scopeInfo['pattern_type'] ?? 'simple';
        $grouped[$patternType][$scopeName] = $scopeInfo;
    }

    // Format simple scopes
    if (!empty($grouped['simple'])) {
        $output .= "SIMPLE PROPERTY FILTERS (use in WHERE clause):\n";
        foreach ($grouped['simple'] as $scopeName => $info) {
            $output .= "- '{$scopeName}' means {$info['description']}\n";
            $output .= "  → WHERE {$info['cypher_pattern']}\n\n";
        }
    }

    // Format relationship scopes
    if (!empty($grouped['relationship'])) {
        $output .= "RELATIONSHIP PATTERNS (MUST use complete MATCH pattern):\n";
        foreach ($grouped['relationship'] as $scopeName => $info) {
            $output .= "- '{$scopeName}' means {$info['description']}\n";
            $output .= "  → Use this EXACT pattern:\n\n";

            // Clean and indent the cypher pattern
            $cypherLines = explode("\n", trim($info['cypher_pattern']));
            foreach ($cypherLines as $line) {
                $output .= "  " . trim($line) . "\n";
            }

            $output .= "\n  CRITICAL: This requires relationship traversal.\n";
            $output .= "  You MUST use this complete MATCH pattern, not a simple property filter.\n\n";
        }
    }

    // Format complex scopes
    if (!empty($grouped['complex'])) {
        $output .= "COMPLEX PATTERNS (use template as-is):\n";
        foreach ($grouped['complex'] as $scopeName => $info) {
            $output .= "- '{$scopeName}' means {$info['description']}\n";
            $output .= "  → Template:\n\n";

            $templateLines = explode("\n", trim($info['cypher_template']));
            foreach ($templateLines as $line) {
                $output .= "  " . trim($line) . "\n";
            }
            $output .= "\n";

            if (!empty($info['modification_guidance'])) {
                $output .= "  Note: {$info['modification_guidance']}\n\n";
            }
        }
    }

    return $output;
}
```

## Usage Scenarios

### Scenario 1: Simple Question with Relationship Scope

**User Question**: "How many volunteers do we have?"

**Detection**:
- Entity: Person
- Scope: volunteers (pattern_type: relationship)

**Context Provided to LLM**:
```
RELATIONSHIP PATTERNS:
- 'volunteers' means People with volunteer role on teams
  → Use this EXACT pattern:

  MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
  WHERE pt.role_type = 'volunteer'
  RETURN DISTINCT p

  CRITICAL: This requires relationship traversal.
  You MUST use this complete MATCH pattern.
```

**Generated Query**:
```cypher
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN count(DISTINCT p) as volunteer_count
```

### Scenario 2: Relationship Scope + Additional Filter

**User Question**: "Show me active volunteers"

**Detection**:
- Entity: Person
- Scopes: volunteers (relationship), active (simple)

**Context Provided to LLM**:
```
SIMPLE PROPERTY FILTERS:
- 'active' means People with active status
  → WHERE p.status = 'active'

RELATIONSHIP PATTERNS:
- 'volunteers' means People with volunteer role on teams
  → Use this EXACT pattern:

  MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
  WHERE pt.role_type = 'volunteer'
  RETURN DISTINCT p
```

**Generated Query**:
```cypher
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer' AND p.status = 'active'
RETURN DISTINCT p
LIMIT 100
```

### Scenario 3: Relationship Scope + Entity Filter

**User Question**: "List volunteers on the Marketing team"

**Generated Query**:
```cypher
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer' AND t.name = 'Marketing'
RETURN DISTINCT p.first_name, p.last_name, t.name
LIMIT 100
```

### Scenario 4: Complex Pattern

**User Question**: "Show me high value customers"

**Generated Query**:
```cypher
MATCH (c:Customer)-[:PLACED]->(o:Order)
WITH c, sum(o.total) as total_value
WHERE total_value > 10000
RETURN c
LIMIT 100
```

## Testing Strategy

### Unit Tests for Pattern Detection

```php
public function test_detects_relationship_pattern_type()
{
    $metadata = $this->retriever->getEntityMetadata('Show me all volunteers');

    $this->assertEquals('relationship',
        $metadata['detected_scopes']['volunteers']['pattern_type']);
}

public function test_detects_simple_pattern_type()
{
    $metadata = $this->retriever->getEntityMetadata('Show me active people');

    $this->assertEquals('simple',
        $metadata['detected_scopes']['active']['pattern_type']);
}

public function test_detects_complex_pattern_type()
{
    $metadata = $this->retriever->getEntityMetadata('Show high value customers');

    $this->assertEquals('complex',
        $metadata['detected_scopes']['high_value']['pattern_type']);
}
```

### Integration Tests for Query Generation

```php
public function test_generates_relationship_pattern_query()
{
    $question = 'How many volunteers do we have?';
    $context = $this->retriever->retrieveContext($question);
    $result = $this->queryGenerator->generate($question, $context);

    // Must include relationship traversal
    $this->assertStringContainsString('[:HAS_ROLE]->', $result['cypher']);
    $this->assertStringContainsString('PersonTeam', $result['cypher']);
    $this->assertStringContainsString("role_type = 'volunteer'", $result['cypher']);
    $this->assertStringContainsString('count(', $result['cypher']);
}

public function test_combines_relationship_and_simple_filters()
{
    $question = 'Show me active volunteers';
    $context = $this->retriever->retrieveContext($question);
    $result = $this->queryGenerator->generate($question, $context);

    // Must include relationship pattern
    $this->assertStringContainsString('[:HAS_ROLE]->', $result['cypher']);
    $this->assertStringContainsString("role_type = 'volunteer'", $result['cypher']);

    // Must include property filter
    $this->assertStringContainsString("status = 'active'", $result['cypher']);
}

public function test_adds_filters_to_relationship_pattern()
{
    $question = 'List volunteers on the Marketing team';
    $context = $this->retriever->retrieveContext($question);
    $result = $this->queryGenerator->generate($question, $context);

    // Must include relationship pattern
    $this->assertStringContainsString('[:HAS_ROLE]->', $result['cypher']);

    // Must include team filter
    $this->assertStringContainsString('Marketing', $result['cypher']);
}
```

### Performance Tests

```php
public function test_relationship_pattern_includes_distinct()
{
    $question = 'Show me all volunteers';
    $context = $this->retriever->retrieveContext($question);
    $result = $this->queryGenerator->generate($question, $context);

    // Must use DISTINCT to avoid duplicates in multi-relationship scenarios
    $this->assertStringContainsString('DISTINCT', $result['cypher']);
}

public function test_relationship_pattern_includes_limit()
{
    $question = 'Show me all volunteers';
    $context = $this->retriever->retrieveContext($question);
    $result = $this->queryGenerator->generate($question, $context);

    // Must include LIMIT for performance
    $this->assertStringContainsString('LIMIT', $result['cypher']);
}
```

## Migration Guide

### Step 1: Identify Relationship-Based Business Terms

Review your domain model and identify terms that require relationship traversal:

```
Simple Filter (no migration needed):
- "active people" → p.status = 'active'

Relationship Pattern (needs migration):
- "volunteers" → NOT p.type = 'volunteer'
                  BUT p-[:HAS_ROLE]->pt WHERE pt.role_type = 'volunteer'
```

### Step 2: Update Entity Configuration

For each relationship-based term:

```php
// Before (incorrect - simple filter for relationship concept)
'volunteers' => [
    'description' => 'People who volunteer',
    'filter' => ['type' => 'volunteer'],
    'cypher_pattern' => "type = 'volunteer'",
],

// After (correct - relationship pattern)
'volunteers' => [
    'pattern_type' => 'relationship',
    'description' => 'People with volunteer role on any team',
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
    ],
],
```

### Step 3: Add Relationship Documentation

Document relationships for LLM understanding:

```php
'metadata' => [
    // ... existing metadata ...

    'relationships' => [
        'HAS_ROLE' => [
            'description' => 'Person has a role on a team',
            'target' => 'PersonTeam',
            'common_patterns' => [
                'Volunteers: (p)-[:HAS_ROLE]->(pt:PersonTeam) WHERE pt.role_type = "volunteer"',
                'Leaders: (p)-[:HAS_ROLE]->(pt:PersonTeam) WHERE pt.role_type = "leader"',
            ],
        ],
    ],
],
```

### Step 4: Test Thoroughly

```php
// Test detection
$metadata = $retriever->getEntityMetadata('Show me volunteers');
assert($metadata['detected_scopes']['volunteers']['pattern_type'] === 'relationship');

// Test query generation
$result = $queryGenerator->generate('How many volunteers?', $context);
assert(strpos($result['cypher'], '[:HAS_ROLE]->') !== false);
```

### Step 5: Monitor and Adjust

- Review generated queries in logs
- Adjust patterns based on LLM behavior
- Add more examples if detection is inconsistent

## Performance Considerations

### Query Complexity

Relationship patterns are inherently more expensive than simple property filters:

```cypher
// Simple (fast)
MATCH (p:Person) WHERE p.type = 'volunteer' RETURN p

// Relationship (slower, but accurate)
MATCH (p:Person)-[:HAS_ROLE]->(pt:PersonTeam)-[:ON_TEAM]->(t:Team)
WHERE pt.role_type = 'volunteer'
RETURN DISTINCT p
```

**Mitigation**:
1. Always use `LIMIT` (enforced by validation)
2. Use `DISTINCT` to avoid duplicate processing
3. Index relationship properties (e.g., `pt.role_type`)
4. Consider adding property denormalization for frequently accessed patterns

### Indexing Strategy

```cypher
// Index intermediate node properties
CREATE INDEX FOR (pt:PersonTeam) ON (pt.role_type);

// Index relationship properties if database supports it
CREATE INDEX FOR ()-[r:HAS_ROLE]-() ON (r.role_type);

// Composite indexes for common patterns
CREATE INDEX FOR (pt:PersonTeam) ON (pt.role_type, pt.status);
```

### Caching Considerations

For frequently executed relationship patterns, consider:

1. **Materialized Views**: Pre-compute volunteer lists
2. **Property Denormalization**: Add `is_volunteer` flag to Person
3. **Query Result Caching**: Cache results for common patterns

### Pattern Complexity Metrics

Track pattern complexity in metadata:

```php
'volunteers' => [
    'pattern_type' => 'relationship',
    'complexity' => [
        'relationships_traversed' => 2,
        'intermediate_nodes' => 1,
        'estimated_cost' => 'medium',
    ],
    // ... rest of config
],
```

## Error Handling

### Invalid Pattern Configuration

```php
// In ContextRetriever::getEntityMetadata()
try {
    $this->validateScopeConfiguration($scopeConfig, $scopeName, $entityName);
} catch (InvalidScopeConfigurationException $e) {
    // Log error but don't break detection
    error_log("Invalid scope configuration for {$entityName}.{$scopeName}: " . $e->getMessage());
    continue;
}
```

### LLM Misinterpretation

If LLM generates incorrect query despite clear pattern:

1. **Add More Examples**: Include variations in `examples` array
2. **Strengthen Prompt**: Use stronger language ("MUST", "CRITICAL")
3. **Add Negative Examples**: Show what NOT to do
4. **Increase Temperature**: May need less deterministic output

### Pattern Validation

```php
/**
 * Validate relationship pattern configuration
 */
private function validateScopeConfiguration(array $config, string $scopeName, string $entityName): void
{
    $patternType = $config['pattern_type'] ?? 'simple';

    switch ($patternType) {
        case 'simple':
            if (empty($config['cypher_pattern'])) {
                throw new InvalidScopeConfigurationException(
                    "{$entityName}.{$scopeName}: Simple pattern requires cypher_pattern"
                );
            }
            break;

        case 'relationship':
            if (empty($config['cypher_pattern']) && empty($config['relationship'])) {
                throw new InvalidScopeConfigurationException(
                    "{$entityName}.{$scopeName}: Relationship pattern requires cypher_pattern or relationship config"
                );
            }
            break;

        case 'complex':
            if (empty($config['cypher_template'])) {
                throw new InvalidScopeConfigurationException(
                    "{$entityName}.{$scopeName}: Complex pattern requires cypher_template"
                );
            }
            break;
    }
}
```

## Future Enhancements

### 1. Pattern Composition

Allow combining multiple patterns:

```php
'active_volunteers_on_marketing_team' => [
    'pattern_type' => 'composed',
    'base_patterns' => ['volunteers', 'active'],
    'additional_filters' => ['team.name' => 'Marketing'],
],
```

### 2. Dynamic Pattern Generation

Generate patterns from relationship schema:

```php
'scopes' => [
    'auto_generate_role_scopes' => true,
    // Would generate: volunteers, leaders, members, etc. from role_type values
],
```

### 3. Pattern Optimization

Automatically optimize patterns based on database statistics:

```php
'volunteers' => [
    'pattern_type' => 'relationship',
    'pattern_variants' => [
        'default' => '(p)-[:HAS_ROLE]->(pt)-[:ON_TEAM]->(t)',
        'optimized_for_large_teams' => '(t)-[:ON_TEAM]<-(pt)<-[:HAS_ROLE]-(p)',
    ],
    'variant_selector' => 'auto',  // Use database stats to choose
],
```

### 4. Pattern Learning

Learn patterns from successful queries:

```php
// After successful query execution
$patternLearner->recordSuccessfulPattern($question, $generatedQuery, $patternUsed);

// Suggest new scopes based on patterns
$suggestions = $patternLearner->suggestScopes();
```

## Appendix A: Complete Person Entity Example

See file: `config/entities-with-relationship-patterns.example.php`

## Appendix B: Pattern Type Decision Tree

```
Is this business concept...

├─ A simple property filter?
│  └─ Use pattern_type: 'simple'
│     Example: "active people" → p.status = 'active'
│
├─ Defined by traversing relationships?
│  └─ Use pattern_type: 'relationship'
│     Example: "volunteers" → p-[:HAS_ROLE]->pt WHERE pt.role_type = 'volunteer'
│
└─ Requires aggregation, calculation, or complex logic?
   └─ Use pattern_type: 'complex'
      Example: "high value customers" → SUM(orders) > threshold
```

## Appendix C: Testing Checklist

- [ ] Simple pattern detection still works (backward compatibility)
- [ ] Relationship pattern detection identifies pattern_type correctly
- [ ] Complex pattern detection identifies pattern_type correctly
- [ ] LLM receives correctly formatted patterns in prompt
- [ ] Generated queries use complete MATCH patterns for relationship scopes
- [ ] Generated queries properly extend WHERE clauses
- [ ] Generated queries include DISTINCT for relationship traversals
- [ ] Generated queries include LIMIT clauses
- [ ] Multiple scopes can be detected simultaneously
- [ ] Mixed pattern types (simple + relationship) work together
- [ ] Error handling gracefully handles invalid configurations
- [ ] Performance is acceptable for typical use cases
- [ ] Documentation is clear and comprehensive

---

**Document Version**: 1.0
**Last Updated**: November 2024
**Status**: Design Complete - Ready for Implementation
