# Entity Metadata System - Quick Start Guide

## 5-Minute Quick Start

### What Is This?

The Entity Metadata System helps your AI understand business terminology:

**Before**: "Show me volunteers" → ❌ Fails or returns wrong data
**After**: "Show me volunteers" → ✅ `MATCH (p:Person {type: 'volunteer'}) RETURN p`

### How It Works

1. You define business terms in `config/entities.php`
2. System detects terms in user questions
3. LLM receives mapping: "volunteers" = Person WHERE type = 'volunteer'
4. Accurate queries generated automatically

---

## Add Metadata to Your Entity (3 Steps)

### Step 1: Open Config File
```bash
File: config/entities.php
```

### Step 2: Add Metadata Section
```php
'YourEntity' => [
    'graph' => [...],   // Existing config
    'vector' => [...],  // Existing config

    // NEW: Add this
    'metadata' => [
        'aliases' => ['singular', 'plural', 'synonym'],
        'description' => 'What this entity represents',

        'scopes' => [
            'business_term' => [
                'description' => 'What this term means',
                'filter' => ['property' => 'value'],
                'cypher_pattern' => "property = 'value'",
                'examples' => [
                    'Example question 1',
                    'Example question 2',
                ],
            ],
        ],

        'common_properties' => [
            'property_name' => 'Description',
        ],
    ],
],
```

### Step 3: Test It
```php
use AiSystem\Services\ContextRetriever;

$retriever = new ContextRetriever(...);
$metadata = $retriever->getEntityMetadata('Show me business_term');

// Should detect your scope!
print_r($metadata['detected_scopes']);
```

---

## Real-World Example

### Scenario: Your App Has "Volunteers"

**Problem**: Users say "volunteers" but your database has `Person` with `type = 'volunteer'`

**Solution**: Add metadata to Person entity

```php
'Person' => [
    'graph' => [
        'label' => 'Person',
        'properties' => ['id', 'name', 'type'],
    ],

    'metadata' => [
        // Alternative names users might say
        'aliases' => ['person', 'people', 'user', 'member'],

        // What this entity is
        'description' => 'Individuals in our system',

        // Business terms with filters
        'scopes' => [
            'volunteers' => [
                'description' => 'People who volunteer',
                'filter' => ['type' => 'volunteer'],
                'cypher_pattern' => "type = 'volunteer'",
                'examples' => [
                    'Show me all volunteers',
                    'How many volunteers?',
                ],
            ],
        ],

        // Property documentation
        'common_properties' => [
            'type' => 'Person type: volunteer, customer, staff',
        ],
    ],
],
```

**Result**:
- User: "How many volunteers?"
- System: `MATCH (p:Person {type: 'volunteer'}) RETURN count(p)`
- ✅ Works perfectly!

---

## Common Patterns

### Pattern 1: Status-Based Scopes

```php
'Order' => [
    'metadata' => [
        'aliases' => ['order', 'orders', 'purchase'],
        'description' => 'Customer orders',

        'scopes' => [
            'pending' => [
                'description' => 'Orders awaiting processing',
                'cypher_pattern' => "status = 'pending'",
                'examples' => ['Show pending orders'],
            ],
            'completed' => [
                'description' => 'Completed orders',
                'cypher_pattern' => "status = 'completed'",
                'examples' => ['Show completed orders'],
            ],
        ],
    ],
],
```

### Pattern 2: Role-Based Scopes

```php
'Person' => [
    'metadata' => [
        'scopes' => [
            'admins' => [
                'description' => 'Administrative users',
                'cypher_pattern' => "role = 'admin'",
                'examples' => ['List all admins'],
            ],
            'managers' => [
                'description' => 'Manager-level users',
                'cypher_pattern' => "role = 'manager'",
                'examples' => ['Show managers'],
            ],
        ],
    ],
],
```

### Pattern 3: Numeric Filters

```php
'Order' => [
    'metadata' => [
        'scopes' => [
            'high_value' => [
                'description' => 'Large orders',
                'filter' => [],
                'cypher_pattern' => 'total > 1000',
                'examples' => ['Show high value orders'],
            ],
        ],
    ],
],
```

### Pattern 4: Combined Filters

```php
'Person' => [
    'metadata' => [
        'combinations' => [
            'active_volunteers' => [
                'description' => 'Currently active volunteers',
                'filters' => ['type' => 'volunteer', 'status' => 'active'],
                'cypher_pattern' => "type = 'volunteer' AND status = 'active'",
                'examples' => ['Show active volunteers'],
            ],
        ],
    ],
],
```

---

## Quick Testing

### Test Entity Detection
```php
$metadata = $retriever->getEntityMetadata('Show me people');

// Check detected entities
var_dump($metadata['detected_entities']);
// Expected: ['Person']
```

### Test Scope Detection
```php
$metadata = $retriever->getEntityMetadata('How many volunteers?');

// Check detected scopes
var_dump($metadata['detected_scopes']);
// Expected: ['volunteers' => [...]]
```

### Test Full Context
```php
$context = $retriever->retrieveContext('Show volunteers');

// Metadata should be included
var_dump($context['entity_metadata']);
```

---

## Common Issues & Fixes

### Issue 1: Scope Not Detected

**Problem**: User says "volunteers" but scope not detected

**Check**:
1. ✅ Scope name matches exactly (case-insensitive)
2. ✅ Parent entity has `metadata` key
3. ✅ Config file syntax is valid PHP

**Fix**: Verify in config file
```php
'scopes' => [
    'volunteers' => [...],  // Exact name
],
```

### Issue 2: Wrong Entity Detected

**Problem**: System detects wrong entity

**Check**:
1. ✅ Aliases don't overlap between entities
2. ✅ Entity name is specific enough

**Fix**: Use more specific aliases
```php
// Before (ambiguous)
'aliases' => ['item'],

// After (specific)
'aliases' => ['product', 'merchandise', 'item_for_sale'],
```

### Issue 3: Query Still Wrong

**Problem**: Query generated but filter is incorrect

**Check**:
1. ✅ `cypher_pattern` syntax is correct
2. ✅ Property names match database

**Fix**: Verify Cypher pattern
```php
'cypher_pattern' => "type = 'volunteer'",  // ✅ Correct
'cypher_pattern' => "type == 'volunteer'", // ❌ Wrong (== not valid Cypher)
```

---

## Best Practices

### ✅ DO:
- Use business terms users actually say
- Include plural and singular aliases
- Provide clear descriptions
- Add multiple example questions
- Document property types and values

### ❌ DON'T:
- Use technical database terms as scope names
- Forget to test after adding metadata
- Leave out common variations in aliases
- Use ambiguous descriptions
- Skip property documentation

---

## Examples to Copy/Paste

### Minimal Example
```php
'Product' => [
    'graph' => ['label' => 'Product'],

    'metadata' => [
        'aliases' => ['product', 'products'],
        'description' => 'Products in catalog',

        'scopes' => [
            'in_stock' => [
                'description' => 'Products available',
                'cypher_pattern' => "stock > 0",
                'examples' => ['Show products in stock'],
            ],
        ],

        'common_properties' => [
            'stock' => 'Quantity available',
        ],
    ],
],
```

### Complete Example
```php
'Customer' => [
    'graph' => [
        'label' => 'Customer',
        'properties' => ['id', 'name', 'tier', 'status'],
    ],

    'metadata' => [
        'aliases' => ['customer', 'customers', 'client', 'clients'],
        'description' => 'Customers who purchase from us',

        'scopes' => [
            'vip' => [
                'description' => 'VIP tier customers',
                'filter' => ['tier' => 'vip'],
                'cypher_pattern' => "tier = 'vip'",
                'examples' => [
                    'Show VIP customers',
                    'List VIP clients',
                    'How many VIP customers?',
                ],
            ],
            'active' => [
                'description' => 'Currently active customers',
                'filter' => ['status' => 'active'],
                'cypher_pattern' => "status = 'active'",
                'examples' => ['Show active customers'],
            ],
        ],

        'common_properties' => [
            'tier' => 'Customer tier: standard, premium, vip',
            'status' => 'Account status: active, inactive, suspended',
        ],

        'combinations' => [
            'active_vip' => [
                'description' => 'Active VIP customers',
                'filters' => ['tier' => 'vip', 'status' => 'active'],
                'cypher_pattern' => "tier = 'vip' AND status = 'active'",
                'examples' => ['Show active VIP customers'],
            ],
        ],
    ],
],
```

---

## Next Steps

1. ✅ Review your entities in `config/entities.php`
2. ✅ Add `metadata` sections to entities users ask about most
3. ✅ Test with `EntityMetadataDemo.php`
4. ✅ Monitor query accuracy
5. ✅ Expand metadata based on user feedback

---

## Need More Help?

- **Full Documentation**: `docs/ENTITY_METADATA_GUIDE.md`
- **Implementation Details**: `docs/ENTITY_METADATA_IMPLEMENTATION.md`
- **Demo Script**: `examples/EntityMetadataDemo.php`
- **Test Examples**: `tests/Unit/Services/EntityMetadataTest.php`

---

**Quick Start Complete!**

You now know how to add semantic understanding to your AI system. Start with your most-used entities and expand from there.
