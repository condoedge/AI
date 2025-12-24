# Query Patterns

Reusable query templates for common operations.

---

## Overview

Query patterns are predefined Cypher templates that the LLM uses to generate queries. They provide:

- **Consistency**: Same query structure for similar questions
- **Efficiency**: Optimized queries for common operations
- **Safety**: Validated patterns prevent dangerous queries

---

## Built-in Patterns

### List All

Returns all entities of a type.

```cypher
MATCH (n:{label})
RETURN n
LIMIT {limit}
```

**Triggers:** "Show all", "List", "Display", "Get all"

**Example:** "Show all customers" → Lists all Customer nodes

### Count All

Counts entities of a type.

```cypher
MATCH (n:{label})
RETURN count(n) as count
```

**Triggers:** "How many", "Count", "Total number"

**Example:** "How many orders?" → Returns count of Order nodes

### Find By Property

Finds entities by property value.

```cypher
MATCH (n:{label})
WHERE n.{property} = {value}
RETURN n
```

**Triggers:** "Find by", "Where", "With"

**Example:** "Find customer by email john@example.com"

### Top N

Returns top N entities by a metric.

```cypher
MATCH (n:{label})
RETURN n
ORDER BY n.{property} DESC
LIMIT {n}
```

**Triggers:** "Top", "Best", "Highest", "Most"

**Example:** "Top 10 customers by revenue"

### Recent

Returns recently created/updated entities.

```cypher
MATCH (n:{label})
RETURN n
ORDER BY n.created_at DESC
LIMIT {limit}
```

**Triggers:** "Recent", "Latest", "Newest", "Last"

**Example:** "Recent orders" → Last N orders

### With Relationship

Finds entities with specific relationships.

```cypher
MATCH (n:{label})-[:{relationship}]->(m:{target})
RETURN n, m
```

**Triggers:** "With", "Having", "That have"

**Example:** "Customers with orders"

### Aggregation

Performs aggregation operations.

```cypher
MATCH (n:{label})
RETURN {aggregation}(n.{property}) as result
```

**Triggers:** "Average", "Sum", "Min", "Max"

**Example:** "Average order value"

---

## Custom Patterns

Define custom patterns in `config/ai-patterns.php`:

```php
// config/ai-patterns.php
return [
    'monthly_revenue' => [
        'name' => 'monthly_revenue',
        'description' => 'Calculate revenue for a specific month',
        'pattern' => '
            MATCH (o:Order)
            WHERE o.created_at >= date({year: $year, month: $month, day: 1})
              AND o.created_at < date({year: $year, month: $month + 1, day: 1})
            RETURN sum(o.total) as revenue
        ',
        'parameters' => ['year', 'month'],
        'triggers' => [
            'revenue for',
            'monthly revenue',
            'sales in',
        ],
    ],

    'customer_lifetime_value' => [
        'name' => 'customer_lifetime_value',
        'description' => 'Calculate total spent by a customer',
        'pattern' => '
            MATCH (c:Customer)-[:HAS_ORDER]->(o:Order)
            WHERE c.id = $customer_id
            RETURN c.name, sum(o.total) as lifetime_value
        ',
        'parameters' => ['customer_id'],
        'triggers' => [
            'lifetime value',
            'total spent by',
            'customer value',
        ],
    ],
];
```

### Pattern Structure

```php
'pattern_name' => [
    'name' => 'pattern_name',           // Unique identifier
    'description' => 'What it does',     // For LLM context
    'pattern' => 'MATCH ... RETURN ...', // Cypher template
    'parameters' => ['param1', 'param2'], // Required parameters
    'triggers' => ['phrase1', 'phrase2'], // Activation phrases
    'priority' => 10,                     // Higher = preferred
    'entity_types' => ['Customer'],       // Applicable entities
],
```

---

## Pattern Matching

### How Patterns Are Selected

1. **Semantic matching**: Question compared to triggers
2. **Entity detection**: Required entities must match
3. **Priority ordering**: Higher priority wins
4. **Confidence threshold**: Must exceed threshold

```env
AI_ENABLE_TEMPLATES=true
AI_TEMPLATE_THRESHOLD=0.8
```

### Matching Examples

| Question | Pattern | Confidence |
|----------|---------|------------|
| "Show all customers" | `list_all` | 0.95 |
| "How many orders?" | `count_all` | 0.92 |
| "Top 5 products" | `top_n` | 0.88 |
| "Revenue for March" | `monthly_revenue` | 0.85 |

---

## Pattern Parameters

### Static Parameters

Hardcoded in the pattern:

```php
'pattern' => "MATCH (n:Customer) WHERE n.status = 'active' RETURN n",
```

### Dynamic Parameters

Extracted from the question:

```php
'pattern' => 'MATCH (n:{label}) RETURN n LIMIT {limit}',
'parameters' => ['label', 'limit'],
```

**Parameter extraction:**
- `{label}` - From entity detection
- `{limit}` - From "top N" or default
- `{property}` - From "by [property]"
- `{value}` - From quoted values or context

### Parameter Types

| Type | Description | Example |
|------|-------------|---------|
| `{label}` | Node label | Customer, Order |
| `{property}` | Property name | name, email |
| `{value}` | Property value | "active", 42 |
| `{relationship}` | Relationship type | HAS_ORDER |
| `{limit}` | Result limit | 10, 100 |
| `{n}` | Numeric value | 5, 10, 20 |

---

## Domain-Specific Patterns

### E-Commerce

```php
'order_status_count' => [
    'description' => 'Count orders by status',
    'pattern' => '
        MATCH (o:Order)
        RETURN o.status as status, count(o) as count
        ORDER BY count DESC
    ',
    'triggers' => ['orders by status', 'order breakdown'],
],

'products_low_stock' => [
    'description' => 'Find products with low inventory',
    'pattern' => '
        MATCH (p:Product)
        WHERE p.stock < 10
        RETURN p.name, p.stock
        ORDER BY p.stock ASC
    ',
    'triggers' => ['low stock', 'out of stock', 'inventory alert'],
],
```

### CRM

```php
'leads_by_stage' => [
    'description' => 'Count leads by pipeline stage',
    'pattern' => '
        MATCH (l:Lead)
        RETURN l.stage as stage, count(l) as count
    ',
    'triggers' => ['leads by stage', 'pipeline breakdown'],
],

'deals_closing_soon' => [
    'description' => 'Find deals closing within N days',
    'pattern' => '
        MATCH (d:Deal)
        WHERE d.close_date <= date() + duration({days: $days})
        RETURN d
        ORDER BY d.close_date
    ',
    'triggers' => ['closing soon', 'upcoming deals', 'deals this week'],
],
```

### Healthcare

```php
'appointments_today' => [
    'description' => 'List today appointments',
    'pattern' => '
        MATCH (a:Appointment)-[:WITH_PATIENT]->(p:Patient)
        WHERE date(a.datetime) = date()
        RETURN a, p.name as patient
        ORDER BY a.datetime
    ',
    'triggers' => ['today appointments', 'schedule today'],
],
```

---

## Pattern Optimization

### Use Indexes

Ensure Neo4j indexes exist for frequently queried properties:

```cypher
CREATE INDEX customer_email FOR (c:Customer) ON (c.email)
CREATE INDEX order_status FOR (o:Order) ON (o.status)
```

### Limit Results

Always include limits to prevent large result sets:

```php
'pattern' => 'MATCH (n:{label}) RETURN n LIMIT {limit}',
```

### Use Parameters

Use parameters instead of string concatenation:

```php
// Good
'pattern' => 'MATCH (n:Customer) WHERE n.id = $id RETURN n',

// Bad (vulnerable to injection)
'pattern' => 'MATCH (n:Customer) WHERE n.id = {id} RETURN n',
```

---

## Debugging Patterns

### Check Pattern Matching

```php
use Condoedge\Ai\Services\PatternMatcher;

$matcher = app(PatternMatcher::class);

// Check which pattern matches
$result = $matcher->match("Show me top 10 customers");
// Returns: ['pattern' => 'top_n', 'confidence' => 0.92, 'params' => [...]]
```

### View Available Patterns

```php
$patterns = config('ai.query_patterns');
```

### Test Pattern Execution

```bash
# Test in Neo4j browser
MATCH (n:Customer) RETURN n LIMIT 10
```

---

## Best Practices

### 1. Clear Triggers

Use diverse, natural language triggers:

```php
'triggers' => [
    'how many',
    'count of',
    'total number of',
    'number of',
],
```

### 2. Descriptive Names

Use clear pattern names:

```php
// Good
'customer_lifetime_value'
'orders_by_status'

// Bad
'pattern1'
'query_a'
```

### 3. Documentation

Include descriptions for LLM context:

```php
'description' => 'Calculate the total amount spent by a customer across all their orders',
```

### 4. Parameter Validation

Validate parameters in complex patterns:

```php
'pattern' => '
    MATCH (n:{label})
    WHERE n.{property} IS NOT NULL
    RETURN n
',
```

---

## Related Documentation

- [Scopes & Business Logic](/docs/{{version}}/advanced/scopes) - Query filters
- [Semantic Matching](/docs/{{version}}/advanced/semantic-matching) - Pattern detection
- [Context Retrieval](/docs/{{version}}/usage/context-retrieval) - RAG system
- [Direct Services](/docs/{{version}}/usage/advanced-usage) - Advanced usage
