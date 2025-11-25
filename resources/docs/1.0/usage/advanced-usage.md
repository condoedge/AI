# Advanced Usage

This guide covers advanced topics: NodeableConfig API, configuration priority, custom configurations, and direct service usage.

---

## NodeableConfig Builder API

The `NodeableConfig` builder provides a fluent, type-safe way to define entity configurations directly in your model.

### Why Use NodeableConfig?

**Benefits:**
- ✅ Configuration lives with the model (single source of truth)
- ✅ IDE autocomplete and type safety
- ✅ Can use logic, conditionals, and dynamic values
- ✅ Highest priority (overrides `config/entities.php`)
- ✅ Perfect for complex or conditional configurations

**When to use:**
- Models with complex relationship configurations
- Dynamic configuration based on environment
- When you want type safety and autocomplete
- Large projects with many models (distributed config)

### Basic Example

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Condoedge\Ai\Domain\Contracts\Nodeable;
use Condoedge\Ai\Domain\Traits\HasNodeableConfig;
use Condoedge\Ai\Domain\ValueObjects\NodeableConfig;

class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;

    protected $fillable = ['name', 'email', 'company', 'status', 'country'];

    /**
     * Define AI configuration using builder pattern
     */
    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::for(static::class)
            // Neo4j graph configuration
            ->label('Customer')
            ->properties('id', 'name', 'email', 'company', 'status', 'country')
            ->relationship('orders', 'Order', 'PLACED_ORDER', 'customer_id')

            // Qdrant vector configuration
            ->collection('customers')
            ->embedFields('name', 'company')
            ->metadata(['id', 'name', 'company', 'status', 'country'])

            // Metadata for RAG
            ->aliases('customer', 'client', 'account', 'buyer')
            ->description('Customer entity with orders and billing information');
    }

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

---

## Complete NodeableConfig API

### Graph Configuration Methods

#### `label(string $label): self`

Set the Neo4j node label:

```php
->label('Customer')  // Creates nodes with label :Customer
```

#### `properties(string ...$properties): self`

Define properties to store in Neo4j:

```php
->properties('id', 'name', 'email', 'status')

// Or as array
->properties(['id', 'name', 'email', 'status'])
```

**Note:** Only these properties will be stored in Neo4j. Exclude sensitive data.

#### `relationship(string $name, string $targetLabel, string $type, ?string $foreignKey = null): self`

Define a relationship to another entity:

```php
// BelongsTo relationship
->relationship('customer', 'Customer', 'BELONGS_TO', 'customer_id')

// HasMany relationship (inverse)
->relationship('orders', 'Order', 'PLACED_ORDER')

// Custom relationship
->relationship('manager', 'User', 'MANAGED_BY', 'manager_id')
```

**Parameters:**
- `$name` - Method name on the model
- `$targetLabel` - Neo4j label of target entity
- `$type` - Relationship type in UPPER_SNAKE_CASE
- `$foreignKey` - Foreign key column (optional for HasMany)

**Multiple relationships:**

```php
->relationship('customer', 'Customer', 'BELONGS_TO', 'customer_id')
->relationship('products', 'Product', 'CONTAINS', 'order_id')
->relationship('warehouse', 'Warehouse', 'SHIPPED_FROM', 'warehouse_id')
```

### Vector Configuration Methods

#### `collection(string $collection): self`

Set Qdrant collection name:

```php
->collection('customers')  // Stores vectors in 'customers' collection
```

#### `embedFields(string ...$fields): self`

Fields to concatenate and embed:

```php
->embedFields('name', 'email', 'company')

// These fields will be concatenated: "John Doe john@example.com Acme Corp"
// Then embedded into a vector
```

**Best practices:**
- Include text fields with semantic meaning
- Avoid IDs, dates, or numeric codes
- Include 2-4 fields typically

#### `metadata(array $metadata): self`

Metadata fields to store in Qdrant (for filtering):

```php
->metadata(['id', 'name', 'company', 'status', 'country'])

// These fields can be used for filtering vector searches
// Example: Find similar customers WHERE country = 'USA'
```

### Metadata Configuration Methods

#### `aliases(string ...$aliases): self`

Alternative names for natural language queries:

```php
->aliases('customer', 'client', 'account', 'buyer', 'purchaser')

// LLM can now understand:
// "Show me all clients" → MATCH (n:Customer)
// "List buyers" → MATCH (n:Customer)
```

#### `description(string $description): self`

Human-readable description for LLM context:

```php
->description('Customer entity representing buyers who place orders and have billing information')
```

### Advanced Configuration Methods

#### `commonProperties(array $properties): self`

Define common properties with descriptions:

```php
->commonProperties([
    'status' => 'Customer status: active, inactive, suspended',
    'tier' => 'Membership tier: bronze, silver, gold, platinum',
    'country' => 'ISO country code (e.g., USA, CAN, GBR)'
])
```

Helps LLM understand property values and constraints.

#### `addScope(string $name, array $config): self`

Manually add Eloquent scope metadata:

```php
->addScope('premium', [
    'cypher_pattern' => "n.tier IN ['gold', 'platinum']",
    'description' => 'Premium tier customers',
    'example_queries' => ['Show premium customers', 'List gold tier clients']
])
```

**Note:** Scopes are auto-discovered from your model, but you can override or add custom ones.

#### `autoSync(bool|array $config): self`

Configure auto-sync behavior for this entity:

```php
// Enable auto-sync
->autoSync(true)

// Disable auto-sync
->autoSync(false)

// Custom sync configuration
->autoSync([
    'enabled' => true,
    'queue' => true,           // Queue sync operations
    'queue_connection' => 'redis',
    'on_create' => true,       // Sync on create
    'on_update' => true,       // Sync on update
    'on_delete' => true,       // Sync on delete
])
```

---

## Dynamic Configuration

Use logic and conditionals in `nodeableConfig()`:

### Environment-Based Configuration

```php
public function nodeableConfig(): NodeableConfig
{
    $config = NodeableConfig::for(static::class)
        ->label('Customer')
        ->properties('id', 'name', 'email');

    // Add extra fields in production
    if (app()->environment('production')) {
        $config->properties('id', 'name', 'email', 'company', 'revenue', 'tier');
        $config->embedFields('name', 'company', 'description');
    } else {
        $config->embedFields('name');
    }

    return $config;
}
```

### Feature Flag-Based Configuration

```php
public function nodeableConfig(): NodeableConfig
{
    $config = NodeableConfig::for(static::class)
        ->label('Order')
        ->properties('id', 'total', 'status');

    // Conditionally add relationships
    if (config('features.track_shipments')) {
        $config->relationship('shipment', 'Shipment', 'SHIPPED_VIA', 'shipment_id');
    }

    if (config('features.reviews_enabled')) {
        $config->relationship('reviews', 'Review', 'HAS_REVIEW');
    }

    return $config;
}
```

### User Role-Based Configuration

```php
public function nodeableConfig(): NodeableConfig
{
    $user = auth()->user();

    $config = NodeableConfig::for(static::class)
        ->label('Document')
        ->properties('id', 'title', 'content');

    // Include sensitive fields only for admins
    if ($user && $user->hasRole('admin')) {
        $config->properties('id', 'title', 'content', 'author_id', 'internal_notes');
        $config->embedFields('title', 'content', 'internal_notes');
    } else {
        $config->embedFields('title', 'content');
    }

    return $config;
}
```

---

## Configuration Priority

The system resolves entity configuration in this order (highest to lowest):

### 1. `nodeableConfig()` Method (Highest Priority)

```php
class Customer extends Model implements Nodeable
{
    public function nodeableConfig(): NodeableConfig
    {
        return NodeableConfig::for(static::class)
            ->label('Customer')
            ->properties('id', 'name', 'email');
    }
}
```

**Priority:** ⭐⭐⭐ HIGHEST
**Use when:** You want configuration in the model, type safety, or dynamic config

### 2. `config/entities.php` (Middle Priority)

```php
// config/entities.php
return [
    'App\Models\Customer' => [
        'graph' => ['label' => 'Customer', 'properties' => ['id', 'name']],
        'vector' => ['collection' => 'customers'],
    ]
];
```

**Priority:** ⭐⭐ MIDDLE
**Use when:** You want centralized config, generated by `php artisan ai:discover`

### 3. Runtime Auto-Discovery (Lowest Priority)

**Priority:** ⭐ LOWEST (Disabled by default)
**Use when:** Development/testing only

To enable runtime discovery:

```bash
# .env
AI_AUTO_DISCOVERY_RUNTIME=true
```

**⚠️ Warning:** Runtime discovery is SLOW. Only enable for dev/testing.

### Mixing Approaches

You can mix configuration approaches:

```php
// config/entities.php - Default for most models
return [
    'App\Models\Order' => [...],
    'App\Models\Product' => [...],
];

// Customer.php - Override specific model
class Customer extends Model implements Nodeable
{
    public function nodeableConfig(): NodeableConfig
    {
        // This overrides config/entities.php for Customer only
        return NodeableConfig::for(static::class)->label('VIPCustomer');
    }
}
```

---

## Three Usage Approaches

The AI system provides three ways to access functionality:

### 1. Facade (Simplest)

```php
use Condoedge\Ai\Facades\AI;

// Note: Auto-sync handles most cases automatically
// Manual operations only needed when auto-sync disabled
```

**Best for:** Quick integration, clean code, standard apps

### 2. AiManager Dependency Injection (Recommended)

```php
use Condoedge\Ai\Services\AiManager;

class CustomerController extends Controller
{
    public function __construct(private AiManager $ai) {}

    public function analyze(Request $request)
    {
        $question = $request->input('question');
        $response = $this->ai->chat($question);
        return response()->json(['answer' => $response]);
    }
}
```

**Best for:** Testable code, SOLID principles, constructor injection

### 3. Direct Service Injection (Maximum Control)

```php
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\ContextRetrieverInterface;

class QueryService
{
    public function __construct(
        private DataIngestionServiceInterface $ingestion,
        private ContextRetrieverInterface $context
    ) {}

    public function process(string $question): string
    {
        $context = $this->context->retrieveContext($question);
        // ... process
    }
}
```

**Best for:** Custom implementations, fine-grained control, libraries

---

## Testing with Mocks

### Mocking AiManager

```php
use Condoedge\Ai\Services\AiManager;
use Mockery;

class CustomerServiceTest extends TestCase
{
    public function test_customer_analysis()
    {
        $mockAi = Mockery::mock(AiManager::class);

        $mockAi->shouldReceive('chat')
            ->once()
            ->with('Analyze customer data')
            ->andReturn('Analysis result...');

        $service = new CustomerService($mockAi);
        $result = $service->analyze('Analyze customer data');

        $this->assertStringContainsString('Analysis', $result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

### Mocking Individual Services

```php
use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Mockery;

$contextMock = Mockery::mock(ContextRetrieverInterface::class);

$contextMock->shouldReceive('retrieveContext')
    ->once()
    ->andReturn(['similar_queries' => [], 'graph_schema' => []]);

$service = new MyService($contextMock);
```

---

## Direct Service Usage

### Manual Service Instantiation

```php
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\VectorStore\QdrantStore;
use Condoedge\Ai\GraphStore\Neo4jStore;
use Condoedge\Ai\EmbeddingProviders\OpenAiEmbeddingProvider;

// Instantiate dependencies
$vectorStore = new QdrantStore(['host' => 'localhost', 'port' => 6333]);
$graphStore = new Neo4jStore(['uri' => 'bolt://localhost:7687']);
$embedProvider = new OpenAiEmbeddingProvider(['api_key' => env('OPENAI_API_KEY')]);

// Create service
$ingestion = new DataIngestionService($vectorStore, $graphStore, $embedProvider);

// Use service (only if auto-sync disabled)
$status = $ingestion->ingest($customer);
```

### Direct Store Access

```php
use Condoedge\Ai\GraphStore\Neo4jStore;

$neo4j = app(Neo4jStore::class);

// Create node
$neo4j->createNode('Person', ['name' => 'John', 'age' => 30]);

// Query
$results = $neo4j->query('MATCH (p:Person) RETURN p LIMIT 10');

// Get schema
$schema = $neo4j->getSchema();
```

---

## Comparison of Approaches

| Feature | Facade | AiManager DI | Direct Services |
|---------|--------|--------------|-----------------|
| **Ease of Use** | Easiest | Easy | More Complex |
| **Testability** | Good | Excellent | Excellent |
| **Flexibility** | Limited | Good | Maximum |
| **Dependencies** | Hidden | Explicit (single) | Explicit (multiple) |
| **Best For** | Quick integration | Testable apps | Libraries, packages |

---

## Next Steps

- **[Simple Usage (AI Facade)](/docs/{{version}}/usage/simple-usage)** - All AI wrapper methods
- **[Data Ingestion API](/docs/{{version}}/usage/data-ingestion)** - Manual ingestion and sync operations
- **[Configuration Reference](/docs/{{version}}/foundations/configuration)** - All config options
- **[Testing Guide](/docs/{{version}}/usage/testing)** - Testing strategies
