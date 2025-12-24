# Scopes & Business Logic

Define reusable query filters that map natural language to Cypher patterns.

---

## Overview

Scopes are predefined filters that the LLM can use when generating queries. They translate business concepts into Cypher patterns:

| User Says | Scope | Cypher Generated |
|-----------|-------|------------------|
| "active customers" | `active` | `WHERE n.status = 'active'` |
| "premium clients" | `premium` | `WHERE n.tier IN ['gold', 'platinum']` |
| "customers with orders" | `with_orders` | `WHERE EXISTS((n)-[:HAS_ORDER]->())` |

---

## Scope Types

### 1. Simple Property Scopes

Filter by a single property value:

```php
'scopes' => [
    'active' => [
        'name' => 'active',
        'cypher_pattern' => "n.status = 'active'",
        'description' => 'Customers with active status',
        'example_queries' => [
            'Show active customers',
            'List all active clients',
        ],
    ],
],
```

### 2. Multi-Value Scopes

Filter by multiple allowed values:

```php
'premium' => [
    'name' => 'premium',
    'cypher_pattern' => "n.tier IN ['gold', 'platinum']",
    'description' => 'Gold and platinum tier customers',
    'example_queries' => [
        'Show premium customers',
        'List gold and platinum clients',
    ],
],
```

### 3. Comparison Scopes

Filter by numeric comparisons:

```php
'high_value' => [
    'name' => 'high_value',
    'cypher_pattern' => "n.total_spent > 10000",
    'description' => 'Customers who spent over $10,000',
    'example_queries' => [
        'High value customers',
        'Big spenders',
    ],
],
```

### 4. Date-Based Scopes

Filter by date ranges:

```php
'recent' => [
    'name' => 'recent',
    'cypher_pattern' => "n.created_at > datetime() - duration('P30D')",
    'description' => 'Customers created in the last 30 days',
    'example_queries' => [
        'Recent customers',
        'New customers this month',
    ],
],
```

### 5. Relationship Scopes

Filter by relationship existence:

```php
'with_orders' => [
    'name' => 'with_orders',
    'cypher_pattern' => "EXISTS((n)-[:HAS_ORDER]->(:Order))",
    'description' => 'Customers who have placed orders',
    'example_queries' => [
        'Customers with orders',
        'Clients who have purchased',
    ],
],
```

### 6. Traversal Scopes

Filter based on related entity properties:

```php
'volunteers' => [
    'name' => 'volunteers',
    'type' => 'traversal',
    'cypher_pattern' => "EXISTS((n)-[:MEMBER_OF]->(:Team {role_type: 3}))",
    'description' => 'People who are volunteers',
    'example_queries' => [
        'Show all volunteers',
        'List volunteer members',
    ],
],
```

---

## Defining Scopes

### In Entity Configuration

```php
// config/entities.php
return [
    'App\\Models\\Customer' => [
        'metadata' => [
            'scopes' => [
                'active' => [
                    'name' => 'active',
                    'cypher_pattern' => "n.status = 'active'",
                    'description' => 'Active customers',
                    'example_queries' => ['Show active customers'],
                ],
                'premium' => [
                    'name' => 'premium',
                    'cypher_pattern' => "n.tier IN ['gold', 'platinum']",
                    'description' => 'Premium tier customers',
                    'example_queries' => ['Premium clients'],
                ],
            ],
        ],
    ],
];
```

### Using NodeableConfig

```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::for(static::class)
        ->label('Customer')
        ->scope('active', [
            'cypher_pattern' => "n.status = 'active'",
            'description' => 'Active customers',
            'example_queries' => ['Show active customers'],
        ])
        ->scope('premium', [
            'cypher_pattern' => "n.tier IN ['gold', 'platinum']",
            'description' => 'Premium tier customers',
        ]);
}
```

---

## Auto-Discovery

Scopes can be automatically discovered from your Laravel models.

### From Laravel Query Scopes

```php
// In your Eloquent model
class Customer extends Model implements Nodeable
{
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePremium($query)
    {
        return $query->whereIn('tier', ['gold', 'platinum']);
    }
}
```

Run discovery:

```bash
php artisan ai:discover
```

**Generated scopes:**

```php
'scopes' => [
    'active' => [
        'name' => 'active',
        'cypher_pattern' => "n.status = 'active'",
        'description' => 'Filter active customers',
    ],
    'premium' => [
        'name' => 'premium',
        'cypher_pattern' => "n.tier IN ['gold', 'platinum']",
        'description' => 'Filter premium customers',
    ],
],
```

### From Relationship Discriminators

Traversal scopes can be auto-generated from relationship discriminator fields.

**Example scenario:**
- `PersonTeam` has `role_type` field (3=volunteer, 4=scout, 5=parent)
- You want a `volunteers` scope on `Person`

**Configure role mappings:**

```php
// config/ai.php
'auto_discovery' => [
    'role_mappings' => [
        'PersonTeam' => [
            'role_type' => [
                3 => 'volunteers',
                4 => 'scouts',
                5 => 'parents',
                6 => 'leaders',
            ],
        ],
    ],
],
```

**Generated traversal scopes:**

```php
// On Person entity
'scopes' => [
    'volunteers' => [
        'name' => 'volunteers',
        'type' => 'traversal',
        'cypher_pattern' => "EXISTS((n)-[:MEMBER_OF]->(:PersonTeam {role_type: 3}))",
        'description' => 'People who are volunteers',
    ],
    'scouts' => [
        'name' => 'scouts',
        'type' => 'traversal',
        'cypher_pattern' => "EXISTS((n)-[:MEMBER_OF]->(:PersonTeam {role_type: 4}))",
        'description' => 'People who are scouts',
    ],
],
```

---

## Scope Detection

The system detects scopes from natural language using semantic matching.

### How Detection Works

1. User asks: "Show me active premium customers"
2. System extracts terms: ["active", "premium", "customers"]
3. Semantic matching finds:
   - "active" → `active` scope (0.95)
   - "premium" → `premium` scope (0.92)
   - "customers" → Customer entity (0.98)
4. Query generated with both scopes

### Detection Threshold

```env
AI_SEMANTIC_THRESHOLD_SCOPE=0.70
```

### Example Queries and Matches

| Query | Detected Scopes |
|-------|-----------------|
| "active customers" | `active` |
| "premium clients" | `premium` |
| "active premium customers" | `active`, `premium` |
| "volunteers in our system" | `volunteers` |
| "high-spending clients" | `high_value` |

---

## Combining Scopes

Multiple scopes can be combined in queries.

### AND Combination (Default)

```
Query: "Show active premium customers"
Scopes: active AND premium
Cypher: WHERE n.status = 'active' AND n.tier IN ['gold', 'platinum']
```

### Complex Combinations

```php
'scopes' => [
    'engaged' => [
        'name' => 'engaged',
        'cypher_pattern' => "n.status = 'active' AND n.last_login > datetime() - duration('P7D')",
        'description' => 'Active customers who logged in recently',
    ],
],
```

---

## Best Practices

### 1. Clear Descriptions

Help the LLM understand when to use scopes:

```php
// Good
'description' => 'Customers with active subscription status who can make purchases'

// Poor
'description' => 'Active'
```

### 2. Example Queries

Provide diverse examples:

```php
'example_queries' => [
    'Show active customers',
    'List all active clients',
    'Active accounts only',
    'Currently active users',
],
```

### 3. Business Terminology

Use terms your users actually say:

```php
// Users might say "VIP" instead of "premium"
'premium' => [
    'description' => 'Premium tier (VIP/gold/platinum) customers',
    'example_queries' => [
        'VIP customers',
        'Premium clients',
        'Gold members',
    ],
],
```

### 4. Index Scopes for Semantic Matching

```bash
# After adding/changing scopes
php artisan ai:index-semantic --rebuild
```

### 5. Test Scope Detection

```php
use Condoedge\Ai\Services\SemanticMatcher;

$matcher = app(SemanticMatcher::class);
$scopes = $matcher->detectScopes("Show me VIP customers who are active");
// Returns detected scopes with confidence scores
```

---

## Troubleshooting

### Scope Not Detected

1. Check threshold: `AI_SEMANTIC_THRESHOLD_SCOPE`
2. Add more example queries
3. Add relevant aliases
4. Rebuild semantic index

### Wrong Scope Applied

1. Make descriptions more specific
2. Increase threshold
3. Add negative examples

### Scope Conflicts

When multiple scopes could match:

```php
// Be specific in descriptions
'active_subscription' => [
    'description' => 'Customers with active subscription (not account status)',
],
'active_account' => [
    'description' => 'Customers with active account status',
],
```

---

## Related Documentation

- [Semantic Matching](/docs/{{version}}/advanced/semantic-matching) - How matching works
- [Auto-Discovery](/docs/{{version}}/advanced/auto-discovery) - Automatic scope detection
- [Entity Configuration](/docs/{{version}}/configuration/entities) - Entity setup
- [Query Patterns](/docs/{{version}}/advanced/patterns) - Query templates
