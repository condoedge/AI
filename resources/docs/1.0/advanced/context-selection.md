# Context Selection (Token Optimization)

Intelligently select relevant context to reduce token consumption and improve response quality.

---

## Overview

The Semantic Context Selection system analyzes your question and retrieves only the relevant context (entities, relationships, scopes) instead of sending everything to the LLM. This provides:

- **Up to 80% token reduction** - Only relevant context is sent
- **Faster responses** - Less data for the LLM to process
- **Better accuracy** - Focused context improves query generation
- **Lower costs** - Fewer tokens = lower API costs

---

## How It Works

### Without Context Selection (Traditional)

```
Question: "How many active customers?"
     ↓
Send ALL context:
- 20 entity schemas
- 50 relationships
- 100 scopes
- 500 properties
     ↓
LLM processes ~8,000 tokens
     ↓
Response
```

### With Semantic Context Selection

```
Question: "How many active customers?"
     ↓
Semantic analysis identifies:
- Relevant: Customer entity
- Relevant: active scope
- Irrelevant: Order, Product, etc.
     ↓
Send ONLY relevant context:
- Customer schema
- active scope
- Customer relationships
     ↓
LLM processes ~800 tokens (90% reduction!)
     ↓
Response
```

---

## Configuration

### Enable Context Selection

```env
AI_SEMANTIC_CONTEXT_ENABLED=true
```

### Settings

```env
# Qdrant collection for context index
AI_SEMANTIC_CONTEXT_COLLECTION=context_index

# Minimum similarity threshold (0.0-1.0)
AI_SEMANTIC_CONTEXT_THRESHOLD=0.65

# Maximum context items to retrieve
AI_SEMANTIC_CONTEXT_TOP_K=10

# Vector dimensions (match embedding provider)
AI_SEMANTIC_CONTEXT_DIMENSION=1536
```

### Full Configuration

```php
// config/ai.php
'semantic_context' => [
    'enabled' => true,
    'collection' => 'context_index',
    'threshold' => 0.65,
    'top_k' => 10,
    'dimension' => 1536,
],
```

---

## Indexing Context

Build the context index for your entities:

```bash
# Index all context
php artisan ai:index-context

# Rebuild from scratch
php artisan ai:index-context --rebuild

# Preview without indexing
php artisan ai:index-context --dry-run
```

### What Gets Indexed

| Item | Description | Example |
|------|-------------|---------|
| Entities | Entity names and descriptions | "Customer - Business clients" |
| Properties | Property names and descriptions | "email - Customer email address" |
| Relationships | Relationship types and targets | "HAS_ORDER - Customer orders" |
| Scopes | Scope names and descriptions | "active - Active customers" |
| Aliases | Alternative entity names | "client, buyer, account" |

### Index Maintenance

Re-index after:
- Adding new entities
- Adding new scopes
- Changing entity configurations
- Modifying aliases

```bash
# Schedule regular re-indexing
php artisan ai:index-context --rebuild
```

---

## Entity Expansion Through Relationships

When an entity is selected by semantic search, the system automatically checks its configured relationships and includes connected entities that meet a relaxed threshold. This prevents missing context for cross-entity queries.

**How it works:**

1. A directly matched entity (e.g., `Customer` at score 0.92) is selected.
2. The system looks at `Customer`'s configured relationships (e.g., `PLACED_ORDER` &rarr; `Order`).
3. Connected entities are included with a reduced score (`parent_score * 0.6`).
4. The reduced score must still meet 80% of the threshold to be included.

**Example:** With a threshold of 0.65, a Customer matched at 0.92 would expand to include Order at score 0.55 (0.92 * 0.6 = 0.55, which is >= 0.65 * 0.8 = 0.52).

This expansion is logged in debug mode:

```
[AI] semantic_selector: entity_expanded {"from":"Customer","to":"Order","via":"PLACED_ORDER","score":0.552}
```

Entities already selected by direct match are not duplicated.

---

## Property Matching

When a property matches the question semantically (e.g., the question mentions "email" and `Customer.email` is indexed), the owning entity is automatically included in context with a slightly reduced score (property score * 0.9).

This means questions like "find customers by email" will correctly select the Customer entity even if the entity name itself does not appear in the question.

---

## Context Quality Indicator

Every context retrieval assesses completeness and returns a quality object in the result:

```php
$context = $retriever->getContext("How many active customers?");

// $context['quality'] contains:
[
    'level' => 'high',     // high, medium, or low
    'score' => '4/4',
    'details' => ['has_similar_queries', 'has_schema', 'has_detected_entities', 'no_errors'],
]
```

Quality criteria:
- **has_similar_queries** — vector search found relevant past queries
- **has_schema** — graph schema labels were retrieved
- **has_detected_entities** — at least one entity was detected for the question
- **no_errors** — no errors occurred during retrieval

Quality levels:
- **high** (4/4) — all context sources working
- **medium** (2-3/4) — some degradation, results may be less accurate
- **low** (0-1/4) — significant context missing, consider re-indexing

The quality level is always logged regardless of debug mode:

```
[AI] context_retriever: context_quality: high {"score":"4/4","details":[...]}
```

---

## Using Context Selection

### Automatic (Default)

When enabled, context selection happens automatically:

```php
use Condoedge\Ai\Facades\AI;

// Context is automatically selected based on question
$response = AI::chat("How many active customers?");
// Only Customer entity and active scope sent to LLM
```

### Manual Control

```php
use Condoedge\Ai\Services\ContextRetriever;

$retriever = app(ContextRetriever::class);

// Get minimal context
$context = $retriever->getMinimalContext("How many active customers?");

// Get context with statistics
$result = $retriever->getContextWithStats("Show customer orders");
// Returns: ['context' => [...], 'stats' => ['tokens_saved' => 7200, ...]]

// Get context with token budget
$context = $retriever->getContextWithBudget("Complex query", maxTokens: 2000);
```

---

## Context Retrieval Methods

### `getMinimalContext()`

Returns only the essential context for a question:

```php
$context = $retriever->getMinimalContext("Who placed the most orders?");

// Returns:
[
    'schema' => [
        'entities' => ['Customer', 'Order'],  // Only relevant entities
        'relationships' => ['HAS_ORDER'],
    ],
    'scopes' => [],
    'examples' => [...],
]
```

### `getContextWithStats()`

Returns context plus token statistics:

```php
$result = $retriever->getContextWithStats("Show active customers");

// Returns:
[
    'context' => [...],
    'stats' => [
        'total_tokens' => 850,
        'tokens_saved' => 7150,
        'reduction_percent' => 89.4,
        'entities_included' => 1,
        'entities_excluded' => 19,
    ],
]
```

### `getContextWithBudget()`

Fits context within a token budget:

```php
$context = $retriever->getContextWithBudget(
    "Complex multi-entity query",
    maxTokens: 2000
);

// Automatically prioritizes most relevant context
// Truncates or excludes less relevant items to fit budget
```

### `getContextConfidence()`

Returns context with confidence scores:

```php
$result = $retriever->getContextConfidence("Find premium customers");

// Returns:
[
    'context' => [...],
    'confidence' => [
        'Customer' => 0.95,
        'premium_scope' => 0.88,
        'Order' => 0.42,  // Below threshold, excluded
    ],
]
```

---

## Threshold Tuning

### Understanding Thresholds

| Threshold | Behavior | Use Case |
|-----------|----------|----------|
| 0.80+ | Very selective | Simple, focused queries |
| 0.65-0.79 | Balanced | General use (default) |
| 0.50-0.64 | Inclusive | Complex multi-entity queries |
| <0.50 | Very inclusive | When precision matters less |

### Adjusting Per Query

```php
// Lower threshold for complex queries
$context = $retriever->getMinimalContext(
    "Show customers with orders and their products",
    ['threshold' => 0.55]
);

// Higher threshold for simple queries
$context = $retriever->getMinimalContext(
    "Count all customers",
    ['threshold' => 0.80]
);
```

---

## Token Estimation

The system estimates tokens for context sizing:

```php
// Get token estimate for context
$stats = $retriever->getContextStats($context);

// Returns:
[
    'estimated_tokens' => 850,
    'entity_count' => 2,
    'relationship_count' => 3,
    'scope_count' => 1,
    'property_count' => 15,
]
```

### Token Budget Planning

```php
// Available tokens for context (considering prompt + response)
$maxContextTokens = 4000;  // e.g., 8K model, 4K for prompt, 4K for response

// Get context within budget
$context = $retriever->getContextWithBudget($question, $maxContextTokens);
```

---

## Fallback Behavior

When context selection fails, the system follows a logged fallback chain:

1. **Semantic search** (vector similarity) &mdash; primary method
2. **Keyword matching** &mdash; if the semantic index is empty or unavailable
3. **Full schema** &mdash; last resort, sends all context to the LLM

Every fallback decision is logged as a warning, so degradation is never silent:

```
[AI] semantic_selector: fallback: vector_search → keyword_matching {"reason":"semantic index not available"}
```

### Configure Fallback

```php
// config/ai.php
'semantic_context' => [
    'fallback_to_full' => true,  // Use all context if selection fails
    'min_context_items' => 1,     // Minimum items to return
],
```

Enable `AI_DEBUG=true` to see the full selection details when diagnosing fallback issues. See: [Debugging the AI Pipeline](/docs/{{version}}/advanced/debugging)

---

## Performance Impact

### Before Context Selection

| Metric | Value |
|--------|-------|
| Average tokens per query | ~8,000 |
| Response time | ~3.5s |
| Cost per 1000 queries | ~$4.00 |

### After Context Selection

| Metric | Value | Improvement |
|--------|-------|-------------|
| Average tokens per query | ~1,200 | 85% reduction |
| Response time | ~1.8s | 49% faster |
| Cost per 1000 queries | ~$0.60 | 85% savings |

---

## Debugging

Enable debug mode to see full selection details:

```env
AI_DEBUG=true
```

### View Selected Context

With debug mode on, every selection is logged:

```
[AI] semantic_selector: vector search completed {"result_count":12,"threshold":0.65}
[AI] semantic_selector: result below threshold, rejected {"type":"entity","entity":"Product","score":0.42,"threshold":0.65}
[AI] semantic_selector: entity_expanded {"from":"Customer","to":"Order","via":"PLACED_ORDER","score":0.552}
[AI] semantic_selector: final selection summary {"entities":{"Customer":0.92,"Order":0.552},"relationships":["Customer-PLACED_ORDER-Order"],"scopes":{"active":0.88}}
[AI] context_retriever: context_quality: high {"score":"4/4","details":["has_similar_queries","has_schema","has_detected_entities","no_errors"]}
```

### Check Index Status

```bash
# View index statistics
php artisan ai:index-context --stats

# Output:
# Context Index Statistics
# ========================
# Total indexed items: 156
# - Entities: 20
# - Properties: 95
# - Relationships: 25
# - Scopes: 16
# Collection size: 2.4 MB
```

For a full guide on tracing and diagnosing pipeline issues, see: [Debugging the AI Pipeline](/docs/{{version}}/advanced/debugging)

---

## Best Practices

### 1. Index After Changes

```bash
# Add to deployment pipeline
php artisan ai:index-context --rebuild
```

### 2. Monitor Token Usage

```php
// Track token savings in production
$result = $retriever->getContextWithStats($question);
Log::info('Context selection', [
    'tokens_used' => $result['stats']['total_tokens'],
    'tokens_saved' => $result['stats']['tokens_saved'],
]);
```

### 3. Tune Thresholds

Start with default (0.65) and adjust based on:
- **Missing context**: Lower threshold
- **Irrelevant context**: Raise threshold

### 4. Quality Descriptions

Better descriptions improve matching:

```php
// Good
'description' => 'Customer entity representing business clients with billing information and order history'

// Poor
'description' => 'Customer'
```

---

## Related Documentation

- [Debugging the AI Pipeline](/docs/{{version}}/advanced/debugging) - Tracing and diagnostics
- [Semantic Matching](/docs/{{version}}/advanced/semantic-matching) - Fuzzy text matching
- [Context Retrieval & RAG](/docs/{{version}}/usage/context-retrieval) - Full RAG system
- [Entity Configuration](/docs/{{version}}/configuration/entities) - Entity setup
- [Environment Variables](/docs/{{version}}/configuration/environment) - All settings
