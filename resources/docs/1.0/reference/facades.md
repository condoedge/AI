# Facades API

Reference for the AI facade and service methods.

---

## AI Facade

The primary interface for AI operations.

```php
use Condoedge\Ai\Facades\AI;
```

---

## Chat & Query Methods

### chat()

Generate a response to a natural language question.

```php
AI::chat(string $question, array $options = []): string
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$question` | string | Natural language question |
| `$options` | array | Optional settings |

**Options:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `style` | string | 'friendly' | Response style |
| `format` | string | 'text' | Output format |
| `max_length` | int | 100 | Max words |
| `include_query` | bool | false | Include Cypher query |

**Examples:**

```php
// Simple query
$response = AI::chat("How many active customers?");

// With options
$response = AI::chat("Show top 10 customers", [
    'style' => 'detailed',
    'format' => 'markdown',
]);

// Minimal style
$answer = AI::chat("What is John's email?", [
    'style' => 'minimal',
]);
// Returns: "john@example.com"
```

### query()

Execute a Cypher query directly.

```php
AI::query(string $cypher, array $params = []): array
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$cypher` | string | Cypher query |
| `$params` | array | Query parameters |

**Examples:**

```php
// Simple query
$results = AI::query("MATCH (n:Customer) RETURN n LIMIT 10");

// Parameterized query
$results = AI::query(
    "MATCH (n:Customer) WHERE n.status = \$status RETURN n",
    ['status' => 'active']
);
```

### generateQuery()

Generate Cypher from natural language without executing.

```php
AI::generateQuery(string $question, array $options = []): string
```

**Examples:**

```php
$cypher = AI::generateQuery("Show active customers");
// Returns: "MATCH (n:Customer) WHERE n.status = 'active' RETURN n"
```

---

## Ingestion Methods

### ingest()

Ingest a single entity.

```php
AI::ingest(Model $entity): bool
```

**Examples:**

```php
$customer = Customer::find(1);
AI::ingest($customer);
```

### bulkIngest()

Ingest multiple entities.

```php
AI::bulkIngest(Collection|array $entities, array $options = []): array
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `chunk_size` | int | 100 | Batch size |
| `with_relationships` | bool | true | Include relationships |

**Examples:**

```php
$customers = Customer::all();
$result = AI::bulkIngest($customers);

// With options
$result = AI::bulkIngest($customers, [
    'chunk_size' => 500,
    'with_relationships' => true,
]);

// Returns:
[
    'ingested' => 1000,
    'failed' => 0,
    'errors' => [],
]
```

### remove()

Remove an entity from the AI system.

```php
AI::remove(Model $entity): bool
```

**Examples:**

```php
$customer = Customer::find(1);
AI::remove($customer);
$customer->delete();
```

---

## Search Methods

### search()

Semantic search across entities.

```php
AI::search(string $query, array $options = []): array
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `collection` | string | null | Search specific collection |
| `limit` | int | 10 | Max results |
| `threshold` | float | 0.7 | Similarity threshold |
| `filters` | array | [] | Metadata filters |

**Examples:**

```php
// Basic search
$results = AI::search("premium software customers");

// With filters
$results = AI::search("active customers", [
    'collection' => 'customers',
    'limit' => 20,
    'filters' => ['status' => 'active'],
]);
```

### searchFiles()

Search file content.

```php
AI::searchFiles(string $query, array $options = []): array
```

**Examples:**

```php
$results = AI::searchFiles("quarterly revenue report");

// With filters
$results = AI::searchFiles("budget 2024", [
    'limit' => 5,
    'filters' => ['file_type' => 'pdf'],
]);
```

---

## Context Methods

### getContext()

Retrieve context for a question.

```php
AI::getContext(string $question, array $options = []): array
```

**Examples:**

```php
$context = AI::getContext("Show customer orders");

// Returns:
[
    'schema' => [...],
    'scopes' => [...],
    'examples' => [...],
    'similar_queries' => [...],
]
```

### getSchema()

Get the current graph schema.

```php
AI::getSchema(): array
```

**Examples:**

```php
$schema = AI::getSchema();

// Returns:
[
    'entities' => [
        'Customer' => ['id', 'name', 'email', 'status'],
        'Order' => ['id', 'total', 'status', 'created_at'],
    ],
    'relationships' => [
        ['from' => 'Customer', 'type' => 'HAS_ORDER', 'to' => 'Order'],
    ],
]
```

---

## Configuration Methods

### getConfig()

Get entity configuration.

```php
AI::getConfig(string $entityClass): array
```

**Examples:**

```php
$config = AI::getConfig(Customer::class);

// Returns:
[
    'graph' => [
        'label' => 'Customer',
        'properties' => [...],
    ],
    'vector' => [...],
    'metadata' => [...],
]
```

### isEnabled()

Check if AI features are enabled.

```php
AI::isEnabled(): bool
```

### status()

Get system status.

```php
AI::status(): array
```

**Examples:**

```php
$status = AI::status();

// Returns:
[
    'neo4j' => ['connected' => true, 'uri' => 'bolt://...'],
    'qdrant' => ['connected' => true, 'host' => '...'],
    'llm' => ['provider' => 'openai', 'available' => true],
]
```

---

## Embedding Methods

### embed()

Generate embeddings for text.

```php
AI::embed(string|array $text): array
```

**Examples:**

```php
// Single text
$embedding = AI::embed("Customer support inquiry");

// Batch
$embeddings = AI::embed([
    "First text",
    "Second text",
]);
```

---

## Low-Level Access

### neo4j()

Get Neo4j client instance.

```php
AI::neo4j(): \Laudis\Neo4j\Contracts\ClientInterface
```

### qdrant()

Get Qdrant client instance.

```php
AI::qdrant(): \Qdrant\Client
```

### llm()

Get LLM provider instance.

```php
AI::llm(): LlmProviderInterface
```

---

## Usage Examples

### Complete Query Flow

```php
use Condoedge\Ai\Facades\AI;

// Ask question and get response
$response = AI::chat("How many active customers placed orders this month?");

// Get detailed response with Cypher
$response = AI::chat("Top 10 customers by revenue", [
    'style' => 'detailed',
    'include_query' => true,
]);

// Get minimal answer for API
$count = AI::chat("Total order count", ['style' => 'minimal']);
```

### Manual Ingestion

```php
// Single entity
$customer = Customer::create(['name' => 'Acme Corp', 'status' => 'active']);
AI::ingest($customer);

// Bulk ingestion
$customers = Customer::where('created_at', '>', now()->subDay())->get();
AI::bulkIngest($customers);

// Remove entity
$customer = Customer::find(1);
AI::remove($customer);
$customer->delete();
```

### Semantic Search

```php
// Find similar customers
$results = AI::search("technology companies in California", [
    'collection' => 'customers',
    'limit' => 5,
]);

foreach ($results as $result) {
    echo "{$result['name']} - Score: {$result['score']}\n";
}
```

### Direct Query Execution

```php
// Execute Cypher directly
$results = AI::query("
    MATCH (c:Customer)-[:HAS_ORDER]->(o:Order)
    WHERE o.created_at > datetime() - duration('P30D')
    RETURN c.name, count(o) as order_count
    ORDER BY order_count DESC
    LIMIT 10
");

foreach ($results as $row) {
    echo "{$row['c.name']}: {$row['order_count']} orders\n";
}
```

---

## Error Handling

```php
use Condoedge\Ai\Exceptions\AiException;
use Condoedge\Ai\Exceptions\QueryGenerationException;
use Condoedge\Ai\Exceptions\ConnectionException;

try {
    $response = AI::chat("Complex query here");
} catch (QueryGenerationException $e) {
    // Failed to generate valid Cypher
    Log::warning("Query generation failed: " . $e->getMessage());
} catch (ConnectionException $e) {
    // Database connection issue
    Log::error("Connection failed: " . $e->getMessage());
} catch (AiException $e) {
    // General AI error
    Log::error("AI error: " . $e->getMessage());
}
```

---

## Related Documentation

- [Simple Usage](/docs/{{version}}/usage/simple-usage) - Basic usage guide
- [Advanced Usage](/docs/{{version}}/usage/advanced-usage) - Direct service usage
- [Response Styles](/docs/{{version}}/configuration/response-styles) - Style configuration
