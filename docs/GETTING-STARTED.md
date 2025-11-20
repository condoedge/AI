# Getting Started with AI Package

## What This Package Does

The AI package provides **intelligent text-to-Cypher query generation** for Laravel applications. Ask questions in natural language, and the system automatically generates Neo4j Cypher queries using RAG (Retrieval-Augmented Generation) with dual-storage in Neo4j (graph relationships) and Qdrant (vector embeddings). Zero configuration needed for most use cases.

## Prerequisites

- **Laravel**: 9.x or higher
- **PHP**: 8.1 or higher
- **Neo4j**: 4.4 or higher (for graph storage)
- **Qdrant**: 1.0 or higher (for vector search)
- **API Key**: OpenAI or Anthropic (for LLM and embeddings)

## Installation

### 1. Install Package

```bash
composer require condoedge/ai
```

### 2. Publish Config (Optional)

```bash
php artisan vendor:publish --tag=ai-config
```

This creates `config/ai.php` for custom configuration.

### 3. Configure Services

Add to your `.env` file:

```bash
# Neo4j Configuration
NEO4J_HOST=http://localhost:7474
NEO4J_USER=neo4j
NEO4J_PASSWORD=your-password

# Qdrant Configuration
QDRANT_HOST=http://localhost:6333

# OpenAI Configuration (Option 1)
OPENAI_API_KEY=sk-your-key-here
AI_LLM_PROVIDER=openai
AI_EMBEDDING_PROVIDER=openai

# Anthropic Configuration (Option 2)
ANTHROPIC_API_KEY=sk-ant-your-key-here
AI_LLM_PROVIDER=anthropic
AI_EMBEDDING_PROVIDER=anthropic
```

### 4. Set up AI Providers

Configure your preferred LLM and embedding providers in `config/ai.php`:

```php
'llm' => [
    'provider' => env('AI_LLM_PROVIDER', 'openai'),
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => 'gpt-4',
    ],
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => 'claude-3-5-sonnet-20241022',
    ],
],

'embedding' => [
    'provider' => env('AI_EMBEDDING_PROVIDER', 'openai'),
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => 'text-embedding-3-small',
        'dimensions' => 1536,
    ],
],
```

## Quick Start (Zero Config)

### Step 1: Make Models Nodeable

Add the `Nodeable` interface and `HasNodeableConfig` trait to your models:

```php
use Illuminate\Database\Eloquent\Model;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'status', 'country'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
```

**That's it!** Everything is auto-discovered:
- Label: "Customer"
- Properties: `id`, `name`, `email`, `status`, `country`, timestamps
- Collection: "customers"
- Embed fields: `name`, `email` (text-like fields)
- Aliases: "customer", "customers", "client", "clients"
- Scopes: "active"

### Step 2: Ingest Data

Data is automatically synced when you create, update, or delete models:

```php
// Create a customer - automatically synced to Neo4j + Qdrant
$customer = Customer::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'status' => 'active',
    'country' => 'USA',
]);

// Update - automatically synced
$customer->update(['status' => 'inactive']);

// Delete - automatically removed from both stores
$customer->delete();
```

**Manual ingestion** (if auto-sync disabled):
```php
use Condoedge\Ai\Facades\AI;

// Single entity
AI::ingest($customer);

// Batch (more efficient for multiple entities)
$customers = Customer::all();
AI::ingestBatch($customers->toArray());
```

### Step 3: Ask Questions

Use the AI facade to ask natural language questions:

```php
use Condoedge\Ai\Facades\AI;

// Simple question
$response = AI::chat("How many active customers do we have?");

// With context retrieval (RAG)
$context = AI::retrieveContext("Show me customers in USA");
$response = AI::chat([
    [
        'role' => 'system',
        'content' => 'You are a helpful assistant with access to customer data.',
    ],
    [
        'role' => 'user',
        'content' => "Question: Show customers in USA\n\nContext: " . json_encode($context),
    ],
]);
```

## Configuration (When Needed)

Most of the time, auto-discovery works perfectly. But when you need customization:

### Override Auto-Discovery

Use the `nodeableConfig()` method in your model:

```php
use Condoedge\Ai\Domain\ValueObjects\NodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'bio'];

    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::discover($this)
            ->embedFields(['name', 'bio'])        // Override embed fields
            ->addAlias('subscriber')              // Add custom alias
            ->addProperty('custom_field');        // Add property
    }
}
```

### Define Scopes

Eloquent scopes automatically convert to Cypher patterns:

```php
class Order extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['total', 'status', 'customer_id'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    // Simple scope - auto-converts to Cypher
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    // Complex scope - auto-converts with relationship
    public function scopeHighValue($query)
    {
        return $query->where('total', '>', 1000);
    }
}

// Auto-generated Cypher patterns:
// pending: WHERE n.status = 'pending'
// high_value: WHERE n.total > 1000
```

### Custom Patterns

Add custom query patterns to `config/ai-patterns.php`:

```php
return [
    'custom_pattern' => [
        'name' => 'custom_pattern',
        'description' => 'Find entities with specific criteria',
        'cypher_template' => 'MATCH (n:{label}) WHERE n.{property} {operator} $value RETURN n LIMIT 100',
        'parameters' => ['label', 'property', 'operator', 'value'],
        'examples' => [
            'Show me {label} where {property} is {value}',
            'Find {label} with {property} {operator} {value}',
        ],
    ],
];
```

## Common Use Cases

### Use Case 1: Basic Entity Search

**Question:** "How many customers do we have?"

**What happens:**
1. Question embedded to vector
2. RAG retrieves similar queries + schema
3. LLM generates Cypher: `MATCH (n:Customer) RETURN count(n) as count`
4. Query executed against Neo4j
5. Natural language response: "You have 1,250 customers in the system."

### Use Case 2: Relationship Queries

**Question:** "Show me active volunteers in this team"

**Model setup:**
```php
class Person extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'status'];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeVolunteers($query)
    {
        return $query->whereHas('personTeams', function($q) {
            $q->where('role_type', 'volunteer');
        });
    }
}
```

**Generated Cypher:**
```cypher
MATCH (t:Team {id: $teamId})<-[:MEMBER_OF]-(p:Person)-[:HAS_ROLE]->(pt:PersonTeam)
WHERE p.status = 'active'
  AND pt.role_type = 'volunteer'
RETURN p
LIMIT 100
```

### Use Case 3: Aggregations

**Question:** "What is the average order value?"

**Generated Cypher:**
```cypher
MATCH (o:Order)
RETURN avg(o.total) as average_value
```

**Response:** "The average order value is $342.50 across all orders."

### Use Case 4: Complex Analysis

**Question:** "Show me customers who placed orders in the last 30 days"

**Generated Cypher:**
```cypher
MATCH (c:Customer)<-[:PLACED_BY]-(o:Order)
WHERE o.created_at > datetime() - duration('P30D')
RETURN DISTINCT c, count(o) as recent_orders
ORDER BY recent_orders DESC
LIMIT 100
```

**Response with insights:**
"I found 342 customers who placed orders in the last 30 days. The top customer (Alice Smith) placed 12 orders, significantly above the average of 2.3 orders per customer. 67% of these customers are in the 'premium' tier, suggesting strong engagement from high-value segments."

## Configuration Reference

### Key Config Options in `config/ai.php`

#### Auto-Sync

```php
'auto_sync' => [
    'enabled' => true,              // Enable automatic syncing
    'queue' => false,               // Use queues for async processing
    'operations' => [
        'create' => true,           // Sync on create
        'update' => true,           // Sync on update
        'delete' => true,           // Sync on delete
    ],
    'fail_silently' => true,        // Don't throw exceptions
    'log_errors' => true,           // Log errors to Laravel log
],
```

#### Discovery

```php
'discovery' => [
    'enabled' => true,              // Enable auto-discovery
    'cache' => [
        'enabled' => true,          // Cache discovery results
        'ttl' => 3600,              // Cache for 1 hour
    ],
    'embed_fields' => [
        'patterns' => [             // Text field patterns
            'name', 'title', 'description', 'notes', 'content', 'bio',
        ],
    ],
],
```

#### Query Generation

```php
'query_generation' => [
    'default_limit' => 100,         // Default result limit
    'max_limit' => 1000,            // Maximum result limit
    'allow_write_operations' => false,  // Safety: read-only
    'max_retries' => 3,             // Retry on validation failure
    'temperature' => 0.1,           // LLM temperature (low = consistent)
],
```

#### Chat Orchestrator

```php
'chat_orchestrator' => [
    'enable_caching' => true,       // Cache query results
    'cache_ttl' => 3600,            // Cache for 1 hour
    'max_history_length' => 50,    // Conversation history limit
    'enable_suggested_questions' => true,  // Follow-up suggestions
],
```

## Troubleshooting

### Issue: Models not syncing to Neo4j/Qdrant

**Check:**
1. Auto-sync enabled: `config('ai.auto_sync.enabled')`
2. Trait present: `use HasNodeableConfig;`
3. Interface implemented: `implements Nodeable`
4. Check logs: `storage/logs/laravel.log`

**Test manually:**
```php
AI::ingest($customer);
```

### Issue: "No text fields found for embedding"

**Cause:** Entity has no text-like fields to embed.

**Solution:**
```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->embedFields(['name', 'description']);  // Explicitly set
}
```

Or disable vector storage:
```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->disableVectorStore();  // Graph-only
}
```

### Issue: Relationship not discovered

**Cause:** Only `belongsTo` relationships are auto-discovered.

**Solution:** Add manually:
```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->addRelationship(
            type: 'HAS_ORDERS',
            targetLabel: 'Order',
            foreignKey: 'customer_id'
        );
}
```

### Issue: Scope not converting to Cypher

**Cause:** Complex scopes with nested closures may not parse automatically.

**Solution:** Add manually:
```php
public function nodeableConfig(): NodeableConfig
{
    return NodeableConfig::discover($this)
        ->addScope('complex_scope', [
            'specification_type' => 'property_filter',
            'concept' => 'Description of scope',
            'cypher_pattern' => 'n.field > 100 AND n.other_field = "value"',
            'filter' => [],
            'examples' => ['Show complex scope items'],
        ]);
}
```

### Issue: Performance slow with auto-sync

**Solution:** Enable queueing:
```bash
# .env
AI_AUTO_SYNC_QUEUE=true
AI_AUTO_SYNC_QUEUE_CONNECTION=redis
```

Run queue worker:
```bash
php artisan queue:work redis --queue=ai-sync
```

### Issue: Query returns no results

**Check:**
1. Data ingested: Run `AI::ingest($model)` manually
2. Neo4j connection: Test with `php artisan tinker` and Neo4j query
3. Schema correct: Run `php artisan ai:discover ModelName` to preview config
4. Question phrasing: Try different variations

## CLI Commands

### Preview Auto-Discovered Config

```bash
php artisan ai:discover Customer
```

Shows the configuration that will be auto-discovered from your model.

### Compare with Config File

```bash
php artisan ai:discover:compare Customer
```

Compare auto-discovered config with `config/entities.php`.

### Cache Discovery Results

```bash
# Cache all entities
php artisan ai:discover:cache

# Cache specific entity
php artisan ai:discover:cache Customer
```

Warm the cache for faster performance. Run during deployment.

### Clear Discovery Cache

```bash
# Clear all
php artisan ai:discover:clear

# Clear specific entity
php artisan ai:discover:clear Customer
```

## Next Steps

### Customize Entity Configuration

Learn about advanced configuration options:
- Override auto-discovered settings
- Add custom relationships
- Define complex scopes
- Configure embed fields

### Add Custom Scopes

Define Eloquent scopes that automatically convert to Cypher:
```php
public function scopeActive($query)
{
    return $query->where('status', 'active');
}
```

### Implement Custom Patterns

Add domain-specific query patterns to `config/ai-patterns.php`.

### Integrate with Your UI

Use the AI facade in your controllers:
```php
use Condoedge\Ai\Facades\AI;

class ChatController extends Controller
{
    public function ask(Request $request)
    {
        $question = $request->input('question');
        $context = AI::retrieveContext($question);

        $response = AI::chat([
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => $question . "\n\nContext: " . json_encode($context)],
        ]);

        return response()->json([
            'answer' => $response,
            'context' => $context,
        ]);
    }
}
```

## Advanced Features

### Dependency Injection

For better testability, inject services directly:

```php
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\ContextRetrieverInterface;

class CustomerService
{
    public function __construct(
        private DataIngestionServiceInterface $ingestion,
        private ContextRetrieverInterface $context
    ) {}

    public function indexCustomer(Customer $customer)
    {
        return $this->ingestion->ingest($customer);
    }

    public function searchCustomers(string $query)
    {
        return $this->context->retrieveContext($query);
    }
}
```

### Batch Operations

For better performance when processing multiple entities:

```php
// Ingest batch
$customers = Customer::all();
$result = AI::ingestBatch($customers->toArray());

// Result
[
    'succeeded' => 1250,
    'failed' => 0,
    'errors' => [],
]
```

### Disable Auto-Sync for Bulk Operations

```php
// Temporarily disable
config(['ai.auto_sync.enabled' => false]);

foreach ($records as $record) {
    Customer::create($record);  // Fast, no sync
}

config(['ai.auto_sync.enabled' => true]);

// Manual batch sync
AI::ingestBatch(Customer::all()->toArray());
```

### Testing

Disable auto-sync in tests:

```php
// tests/TestCase.php
public function setUp(): void
{
    parent::setUp();
    config(['ai.auto_sync.enabled' => false]);
}
```

Mock the AI facade:

```php
use Condoedge\Ai\Facades\AI;

public function test_customer_search()
{
    AI::shouldReceive('retrieveContext')
        ->once()
        ->with('search query')
        ->andReturn(['similar_queries' => [...]]);

    // Test code
}
```

## Security Features

The package includes production-ready security features that work automatically with no configuration required.

### Automatic Injection Protection

**Cypher Injection Prevention:**
All user input is automatically validated before being used in Cypher queries:

```php
// These are automatically protected - no action needed
$customer = Customer::find($request->id);  // ✓ Safe
AI::query("Show customers named {$userInput}");  // ✓ Validated automatically
```

**What's Protected:**
- Node labels (e.g., `Customer`, `Order`)
- Relationship types (e.g., `BELONGS_TO`, `HAS_ORDER`)
- Property keys (e.g., `name`, `email`)
- Database identifiers in auto-discovery

### Data Consistency Guarantees

**Automatic Rollback on Failure:**
When syncing to both Neo4j and Qdrant, the package automatically handles failures:

```php
// If vector store fails, graph insert is automatically rolled back
$customer = Customer::create($data);
// Either both stores succeed, or both roll back - no orphaned data
```

**What Happens:**
1. Data saved to Neo4j first
2. If Qdrant fails, Neo4j insert is rolled back automatically
3. On deletion, if Qdrant fails, Neo4j node is restored
4. Critical errors logged for manual intervention if rollback fails

### Automatic Retry & Circuit Breaking

**Built-in Resilience:**
Network failures and transient errors are handled automatically:

```php
// Automatically retries with exponential backoff
AI::ingest($customer);  // Retries up to 5 times on transient failures

// Circuit breaker prevents cascading failures
// If Neo4j is down, requests fail fast instead of timing out
```

**How It Works:**
- **Retry Policy**: Exponential backoff with jitter (prevents thundering herd)
- **Circuit Breaker**: Opens after 5 failures, prevents cascade
- **Timeouts**: Connection (5s) and request (30s) timeouts prevent hangs

### Sensitive Data Protection

**API Keys Never Logged:**
All logs automatically redact sensitive information:

```php
// This log is safe - API keys are automatically redacted
Log::error('OpenAI failed', [
    'api_key' => config('ai.openai.api_key'),  // Logged as: ***REDACTED***
    'error' => $exception->getMessage(),
]);
```

**Protected Data:**
- API keys (OpenAI, Anthropic, AWS)
- Passwords and secrets
- Bearer tokens
- Database credentials
- Stack traces (paths sanitized)

### Recursion Protection

**Stack Overflow Prevention:**
Auto-discovery includes guards against infinite recursion:

```php
// Even with circular relationships, discovery won't crash
class User extends Model {
    public function team() { return $this->belongsTo(Team::class); }
}

class Team extends Model {
    public function users() { return $this->hasMany(User::class); }
}

// Auto-discovery handles this safely with max depth limits
```

**Protection Includes:**
- Maximum discovery depth (5 levels)
- Circular reference detection
- Configuration merge depth limits (10 levels)

### Security Best Practices

**No Configuration Needed:**
Security features are enabled by default and require no setup:

- ✓ All injection vectors protected automatically
- ✓ Data consistency guaranteed across stores
- ✓ Failures handled with retry and circuit breaking
- ✓ Sensitive data never exposed in logs
- ✓ Resource limits prevent DoS attacks

**For Advanced Users:**
Review `docs/ARCHITECTURE.md` Security Architecture section for details on:
- Custom sanitization rules
- Circuit breaker tuning
- Retry policy configuration
- Security testing approach

## Best Practices

1. **Keep `$fillable` updated** - Auto-discovery relies on it
2. **Use descriptive scope names** - They become semantic terms
3. **Leverage Eloquent scopes** - They auto-convert to Cypher
4. **Use `nodeableConfig()` sparingly** - Only override what's needed
5. **Preview before deploying** - Run `php artisan ai:discover Model`
6. **Warm cache on deployment** - Run `php artisan ai:discover:cache`
7. **Enable queueing in production** - Set `AI_AUTO_SYNC_QUEUE=true`
8. **Disable for bulk imports** - Temporarily disable auto-sync
9. **Trust the security defaults** - Injection protection is automatic
10. **Review logs safely** - Sensitive data is automatically redacted

## Resources

- **Documentation**: Full docs in `docs/` directory
- **Examples**: Check `examples/` for working demos
- **Tests**: Review tests for usage patterns
- **Issues**: GitHub Issues for support

Happy building with AI-powered queries!
