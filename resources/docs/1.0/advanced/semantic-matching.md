# Semantic Matching

Fuzzy text matching using vector embeddings for intelligent entity and scope detection.

---

## Overview

Semantic matching uses vector embeddings to understand the meaning of text rather than relying on exact string matches. This enables:

- **Entity detection**: "clients" matches `Customer` entity
- **Scope detection**: "active users" matches `active` scope
- **Label inference**: "show orders" infers `Order` label

---

## How It Works

1. **Indexing**: Entity names, aliases, and scopes are converted to vector embeddings
2. **Querying**: User's question is converted to an embedding
3. **Matching**: Similar vectors are found using cosine similarity
4. **Thresholding**: Matches above the threshold are returned

```
User: "Show me all clients from USA"
                ↓
          Embedding
                ↓
Vector similarity search
                ↓
Match: "clients" → Customer (0.92 similarity)
Match: "USA" → n.country = 'USA' (0.88 similarity)
```

---

## Configuration

### Enable Semantic Matching

```env
```

### Similarity Thresholds

Fine-tune thresholds for different matching types:

```env
# Entity detection (e.g., "clients" → Customer)

# Scope detection (e.g., "active" → active scope)

# Label inference from query
```

**Threshold guidelines:**
- **0.80+**: Very precise, may miss valid matches
- **0.70-0.79**: Balanced precision/recall (recommended)
- **0.60-0.69**: More permissive, may include false positives
- **<0.60**: Too permissive, not recommended

### Caching

Cache embeddings to reduce API calls:

```env
```

---

## Indexing

Build the semantic index for your entities:

```bash
# Index all entities
php artisan ai:index-semantic

# Rebuild from scratch
php artisan ai:index-semantic --rebuild

# Check index status
php artisan ai:index-semantic --check
```

The command always indexes every configured entity. The `--entities` option is kept for compatibility; entities are the only thing it indexes.

### What Gets Indexed

For each entity:
- Entity name (e.g., "Customer")
- All aliases (e.g., "client", "buyer", "account")
- The entity description

Scopes are not part of this index. Scope concepts and example phrasings are indexed into `scope_examples` by `php artisan ai:index-context` / `php artisan ai:index-scopes`.

### Index Storage

The index is stored in one Qdrant collection, `semantic_entities`. The name is fixed; there is no config key for it. See [Data ingestion](/docs/{{version}}/usage/data-ingestion) for everything Qdrant holds.

The `semantic_matching` section of `config/ai.php` holds the scope-matching settings:

```php
// config/ai.php
'semantic_matching' => [
    'scope_threshold' => 0.7,  // Minimum similarity for a scope match
    'max_scopes' => 5,         // Maximum scopes returned
],
```

---

## Entity Detection

Semantic matching identifies which entities are relevant to a query.

### Example

```php
// Query: "Show all clients from Europe"

// Without semantic matching (exact match):
// "clients" ≠ "Customer" → No match

// With semantic matching:
// "clients" ≈ "Customer" (0.92) → Match!
```

### How It Works

1. Extracts nouns from the query
2. Generates embeddings for each noun
3. Searches entity index for similar vectors
4. Returns matches above threshold

### Configuration

```php
// config/ai.php
'semantic_matching' => [
    'thresholds' => [
        'entity_detection' => 0.75,  // Minimum similarity
    ],
],
```

---

## Scope Detection

Automatically detect and apply scopes from natural language.

### Example

```php
// Entity: Customer with scope "active"
// Query: "Show me currently active clients"

// Semantic matching detects:
// "active" ≈ "active scope" (0.89) → Apply WHERE n.status = 'active'
```

### Supported Scope Types

1. **Simple scopes**: Direct property filters
   ```php
   'active' => "n.status = 'active'"
   ```

2. **Complex scopes**: Multiple conditions
   ```php
   'premium' => "n.tier IN ['gold', 'platinum']"
   ```

3. **Traversal scopes**: Cross-entity filters
   ```php
   'with_orders' => "EXISTS((n)-[:HAS_ORDER]->(:Order))"
   ```

### Indexing Scopes

Scopes are automatically indexed from:

1. **Laravel query scopes**:
   ```php
   public function scopeActive($query) {
       return $query->where('status', 'active');
   }
   ```

2. **Entity configuration**:
   ```php
   'scopes' => [
       'active' => [
           'cypher_pattern' => "n.status = 'active'",
           'description' => 'Active customers',
           'example_queries' => ['Show active customers'],
       ],
   ],
   ```

---

## Fallback Behavior

When semantic matching fails or is disabled:

```env
```

The system falls back to exact string matching:
1. Direct alias lookup
2. Case-insensitive comparison
3. Plural/singular variations

---

## Performance

### Embedding Cache

Enable in-memory caching to avoid redundant API calls:

```php
'semantic_matching' => [
    'cache_embeddings' => true,
],
```

### Batch Indexing

Index multiple entities efficiently:

```bash
# Index all at once (batched API calls)
php artisan ai:index-semantic
```

### Index Size

Typical index sizes:
- Small project (10 entities): ~1MB
- Medium project (50 entities): ~5MB
- Large project (200+ entities): ~20MB

---

## Debugging

### Check Matching Results

```php
use Condoedge\Ai\Services\ScopeSemanticMatcher;
use Condoedge\Ai\Services\SemanticContextSelector;

$entityConfigs = config('ai.entities');

// Check entity/relationship/scope selection
$selector = app(SemanticContextSelector::class);
$result = $selector->selectRelevantContext('Show all clients', $entityConfigs);
// $result is a SemanticSelectionResult:
//   $result->entities       — array<string, SelectedEntity> keyed by entity name,
//                             each with ->score and ->matchedText
//   $result->relationships  — array<string, SelectedRelationship>
//   $result->scopes         — array<string, SelectedScope>
//   $result->selectionMethod — 'semantic' or 'keyword' (keyword = index unavailable)

// Check scope detection on its own
$scopeMatcher = app(ScopeSemanticMatcher::class);
$scopes = $scopeMatcher->findMatchingScopes('active premium customers', $entityConfigs);
// Returns matched scopes keyed by scope, each with a 'match_score' (0-1),
// 'entity', 'scope' and 'match_type'.
```

### View Index Contents

```bash
# Check index status
php artisan ai:index-semantic --check
```

---

## Best Practices

### Aliases

Add comprehensive aliases for better matching:

```php
'metadata' => [
    'aliases' => [
        'customer',      // Exact name
        'client',        // Synonym
        'buyer',         // Business term
        'account',       // Alternative
        'purchaser',     // Formal term
    ],
],
```

### Scope Descriptions

Provide clear descriptions for scopes:

```php
'scopes' => [
    'active' => [
        'description' => 'Customers with active status who can place orders',
        'example_queries' => [
            'Show active customers',
            'List all active clients',
            'Currently active accounts',
        ],
    ],
],
```

### Threshold Tuning

Start with defaults and adjust based on:
- **False positives**: Increase threshold
- **Missed matches**: Decrease threshold or add aliases

---

## Related Documentation

- [Context Selection](/docs/{{version}}/advanced/context-selection) - Token optimization
- [Scopes & Business Logic](/docs/{{version}}/advanced/scopes) - Scope configuration
- [Entity Configuration](/docs/{{version}}/configuration/entities) - Entity setup
- [Auto-Discovery](/docs/{{version}}/advanced/auto-discovery) - Automatic detection
