# Simple Usage (AI Facade)

The `AI` Facade provides a simple, one-line interface to all AI system features.

---

## Overview

The AI Facade provides access to all AI system capabilities:

```php
use Condoedge\Ai\Facades\AI;

// RAG context retrieval (most common)
$context = AI::retrieveContext("Show all teams");

// Chat with LLM
$response = AI::chat("What is 2+2?");

// Manual ingestion (rarely needed - auto-sync handles this!)
AI::ingest($customer);
```

**The AI Facade automatically handles:**
- Dependency instantiation
- Configuration loading
- Service coordination
- Error handling

---

## Auto-Sync vs Manual Operations

**⚠️ Important:** Most ingestion methods are **rarely needed** because **auto-sync handles this automatically**.

When you use the `HasNodeableConfig` trait, entities automatically sync to Neo4j + Qdrant on model events:

```php
class Customer extends Model implements Nodeable
{
    use HasNodeableConfig;
}

// Auto-synced - no manual AI::ingest() needed!
$customer = Customer::create(['name' => 'Acme']);  // ✓ Auto-ingested
$customer->update(['name' => 'Acme Corp']);        // ✓ Auto-synced
$customer->delete();                                // ✓ Auto-removed
```

**Manual ingestion methods are only needed when:**
- Auto-sync is disabled globally or for specific models
- You need explicit control over ingestion timing
- Running bulk operations outside of model events

For most applications, you'll primarily use:
1. **`AI::retrieveContext()`** - RAG for query generation
2. **`AI::chat()` / `AI::chatJson()`** - LLM interactions
3. **`AI::embed()`** - Custom embedding operations

See: [Data Ingestion Guide](/docs/{{version}}/usage/data-ingestion) for auto-sync configuration.

---

## Data Ingestion Methods

**Note:** These methods are typically **not needed** due to auto-sync. See section above.

### ingest() - Ingest Single Entity

Ingest an entity into both graph and vector stores.

```php
AI::ingest(Nodeable $entity): array
```

**Example:**
```php
$customer = Customer::find(1);
$status = AI::ingest($customer);

// Returns:
[
    'graph_stored' => true,
    'vector_stored' => true,
    'relationships_created' => 2,
    'errors' => []
]
```

**Status Array:**
- `graph_stored` (bool) - True if stored in Neo4j
- `vector_stored` (bool) - True if stored in Qdrant
- `relationships_created` (int) - Number of relationships created
- `errors` (array) - Error messages if any failures occurred

---

### ingestBatch() - Batch Ingestion

Ingest multiple entities efficiently in a single operation.

```php
AI::ingestBatch(array $entities): array
```

**Example:**
```php
$customers = Customer::all();
$result = AI::ingestBatch($customers->toArray());

// Returns:
[
    'total' => 100,
    'succeeded' => 98,
    'partially_succeeded' => 1,
    'failed' => 1,
    'errors' => [
        45 => ['Vector: Connection timeout']
    ]
]
```

**Summary Array:**
- `total` (int) - Total entities processed
- `succeeded` (int) - Fully ingested (both stores)
- `partially_succeeded` (int) - Ingested in one store only
- `failed` (int) - Failed completely
- `errors` (array) - Errors indexed by entity ID

---

### sync() - Sync Entity

Update if exists, create if not.

```php
AI::sync(Nodeable $entity): array
```

**Example:**
```php
$customer->name = 'Updated Name';
$customer->save();

$status = AI::sync($customer);

// Returns:
[
    'action' => 'updated',  // or 'created'
    'graph_synced' => true,
    'vector_synced' => true,
    'errors' => []
]
```

---

### remove() - Remove Entity

Delete entity from both stores.

```php
AI::remove(Nodeable $entity): bool
```

**Example:**
```php
$customer = Customer::find(1);

if (AI::remove($customer)) {
    $customer->delete(); // Safe to delete from database
}
```

**Returns:** `true` if removed from at least one store.

---

## Context Retrieval (RAG) Methods

### retrieveContext() - Get Complete Context

Retrieve rich context for LLM query generation using RAG.

```php
AI::retrieveContext(string $question, array $options = []): array
```

**Parameters:**
- `question` (string) - Natural language question
- `options` (array) - Optional configuration

**Options:**
```php
[
    'collection' => 'questions',      // Vector collection
    'limit' => 5,                     // Max similar queries
    'includeSchema' => true,          // Include graph schema
    'includeExamples' => true,        // Include sample entities
    'examplesPerLabel' => 2,          // Examples per label
    'scoreThreshold' => 0.7           // Min similarity score
]
```

**Example:**
```php
$context = AI::retrieveContext("Show teams with most members", [
    'collection' => 'teams',
    'limit' => 10
]);

// Returns:
[
    'similar_queries' => [
        [
            'question' => 'List all teams',
            'query' => 'MATCH (t:Team) RETURN t',
            'score' => 0.89,
            'metadata' => [...]
        ]
    ],
    'graph_schema' => [
        'labels' => ['Team', 'Person'],
        'relationships' => ['MEMBER_OF'],
        'properties' => ['id', 'name', 'size']
    ],
    'relevant_entities' => [
        'Team' => [
            ['id' => 1, 'name' => 'Alpha Team', 'size' => 10]
        ]
    ],
    'errors' => []
]
```

---

### searchSimilar() - Vector Similarity Search

Find semantically similar items using vector search.

```php
AI::searchSimilar(string $question, array $options = []): array
```

**Example:**
```php
$similar = AI::searchSimilar("software development", [
    'collection' => 'teams',
    'limit' => 5,
    'scoreThreshold' => 0.7
]);

// Returns:
[
    [
        'question' => 'Engineering Team description',
        'query' => '',
        'score' => 0.89,
        'metadata' => ['id' => 1, 'name' => 'Engineering Team']
    ],
    [
        'question' => 'Development Squad description',
        'query' => '',
        'score' => 0.85,
        'metadata' => ['id' => 2, 'name' => 'Dev Squad']
    ]
]
```

---

### getSchema() - Get Graph Schema

Retrieve Neo4j database schema.

```php
AI::getSchema(): array
```

**Example:**
```php
$schema = AI::getSchema();

// Returns:
[
    'labels' => ['Team', 'Person', 'Project'],
    'relationships' => ['MEMBER_OF', 'WORKS_ON', 'MANAGES'],
    'properties' => ['id', 'name', 'email', 'created_at']
]
```

---

## Embedding Methods

### embed() - Generate Single Embedding

Convert text to vector embedding.

```php
AI::embed(string $text): array
```

**Example:**
```php
$vector = AI::embed("Artificial Intelligence and Machine Learning");

// Returns: Array of 1536 floats (OpenAI text-embedding-3-small)
// [0.023, -0.015, 0.042, -0.008, ...]

echo "Dimensions: " . count($vector); // 1536
```

---

### embedBatch() - Batch Generate Embeddings

Generate embeddings for multiple texts efficiently.

```php
AI::embedBatch(array $texts): array
```

**Example:**
```php
$texts = [
    "First document about AI",
    "Second document about ML",
    "Third document about data science"
];

$vectors = AI::embedBatch($texts);

// Returns: Array of vectors
// [
//     [0.023, -0.015, ...],  // Vector for first text
//     [0.031, -0.008, ...],  // Vector for second text
//     [0.019, -0.012, ...]   // Vector for third text
// ]

foreach ($vectors as $index => $vector) {
    echo "Text {$index}: " . count($vector) . " dimensions\n";
}
```

---

## LLM Methods

### chat() - Chat with LLM

Send messages to LLM and get text response.

```php
AI::chat(string|array $input, array $options = []): string
```

**Simple Usage:**
```php
$response = AI::chat("What is the capital of France?");
echo $response; // "The capital of France is Paris."
```

**Conversation History:**
```php
$conversation = [
    ['role' => 'system', 'content' => 'You are a helpful assistant'],
    ['role' => 'user', 'content' => 'Hello!'],
    ['role' => 'assistant', 'content' => 'Hi! How can I help you?'],
    ['role' => 'user', 'content' => 'What is 2+2?']
];

$response = AI::chat($conversation);
echo $response; // "2+2 equals 4."
```

**With Options:**
```php
$response = AI::chat("Write a haiku about programming", [
    'temperature' => 0.9,  // More creative
    'max_tokens' => 100
]);
```

---

### chatJson() - Get JSON Response

Chat with LLM and get structured JSON response.

```php
AI::chatJson(string|array $input, array $options = []): object|array
```

**Example:**
```php
$data = AI::chatJson("Generate a Cypher query for: Show all teams");

// Returns decoded JSON:
// {
//     "query": "MATCH (t:Team) RETURN t",
//     "explanation": "This query matches all Team nodes"
// }

echo $data->query;
echo $data->explanation;
```

**Structured Data Extraction:**
```php
$prompt = "Extract person info: John Doe, age 30, email john@example.com";
$data = AI::chatJson($prompt);

// Returns:
// {
//     "name": "John Doe",
//     "age": 30,
//     "email": "john@example.com"
// }
```

---

### complete() - Simple Completion

Simple prompt completion with optional system message.

```php
AI::complete(string $prompt, ?string $systemPrompt = null, array $options = []): string
```

**Example:**
```php
$response = AI::complete("Translate 'hello' to French");
echo $response; // "Bonjour"

// With system prompt
$response = AI::complete(
    "Translate 'hello' to French",
    "You are a professional translator"
);

// With options
$response = AI::complete(
    "Write a creative story",
    "You are a novelist",
    ['temperature' => 0.9, 'max_tokens' => 500]
);
```

---

## Testing the AI Facade

The AI Facade is fully testable using Laravel's facade mocking:

### Mocking in Tests

```php
use Condoedge\Ai\Facades\AI;
use Tests\TestCase;

class CustomerServiceTest extends TestCase
{
    public function test_customer_ingestion()
    {
        // Mock the AI facade
        AI::shouldReceive('ingest')
            ->once()
            ->with(Mockery::type(Customer::class))
            ->andReturn([
                'graph_stored' => true,
                'vector_stored' => true,
                'relationships_created' => 2,
                'errors' => []
            ]);

        // Your test code
        $customer = Customer::factory()->create();
        $service = new CustomerService();
        $result = $service->processCustomer($customer);

        $this->assertTrue($result);
    }

    public function test_context_retrieval()
    {
        AI::shouldReceive('retrieveContext')
            ->once()
            ->with('Show all teams')
            ->andReturn([
                'similar_queries' => [],
                'graph_schema' => ['labels' => ['Team']],
                'relevant_entities' => [],
                'errors' => []
            ]);

        $service = new QueryService();
        $context = $service->getContext('Show all teams');

        $this->assertIsArray($context);
    }
}
```

### Partial Mocking

```php
// Mock only specific methods
AI::shouldReceive('embed')
    ->andReturn([0.1, 0.2, 0.3]);

// Let other methods work normally
AI::makePartial();
```

See [Testing Documentation](/docs/{{version}}/usage/testing) for comprehensive testing strategies.

---

## Complete Usage Example

Here's a complete RAG-powered natural language query system:

```php
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\GraphStore\Neo4jStore;

// 1. Create customer (auto-synced to Neo4j + Qdrant!)
$customer = Customer::create([
    'name' => 'Acme Corporation',
    'email' => 'contact@acme.com',
    'description' => 'Leading software development company'
]);
// ✓ Automatically ingested via auto-sync - no manual AI::ingest() needed!

// 2. User asks a natural language question
$question = "Which customers are in the software industry?";

// 3. Retrieve context using RAG
$context = AI::retrieveContext($question, [
    'collection' => 'customers',
    'limit' => 5,
    'includeSchema' => true,
    'includeExamples' => true
]);

// 4. Build LLM prompt with retrieved context + project context
$projectContext = config('ai.project');

$systemPrompt = sprintf(
    "You are a Cypher query expert for a %s application.\n\nProject: %s\nDescription: %s\n\nGenerate valid Neo4j queries based on the provided schema, examples, and business rules.",
    $projectContext['domain'] ?? 'business',
    $projectContext['name'] ?? 'Application',
    $projectContext['description'] ?? ''
);

$businessRules = !empty($projectContext['business_rules'])
    ? "\n\nBusiness Rules:\n" . implode("\n", array_map(fn($rule) => "- $rule", $projectContext['business_rules']))
    : '';

$userPrompt = sprintf(
    "Question: %s\n\nGraph Schema:\n%s\n\nSimilar Previous Queries:\n%s\n\nExample Entities:\n%s%s\n\nGenerate a Cypher query (JSON format with 'query' and 'explanation' fields).",
    $question,
    json_encode($context['graph_schema'], JSON_PRETTY_PRINT),
    json_encode($context['similar_queries'], JSON_PRETTY_PRINT),
    json_encode($context['relevant_entities'], JSON_PRETTY_PRINT),
    $businessRules
);

// 5. Generate Cypher query using LLM
$result = AI::chatJson([
    ['role' => 'system', 'content' => $systemPrompt],
    ['role' => 'user', 'content' => $userPrompt]
], [
    'temperature' => 0.2  // Low temperature for consistency
]);

$cypherQuery = $result->query ?? '';
$explanation = $result->explanation ?? '';

// 6. Execute query against Neo4j
$neo4j = app(Neo4jStore::class);
$results = $neo4j->query($cypherQuery);

// 7. Generate human-readable response with full context
$responseSystemPrompt = sprintf(
    "You are a helpful assistant for a %s application (%s).\n\nProject Description: %s%s\n\nDomain Knowledge:\n%s\n\nGraph Schema:\n%s\n\nUse this context to provide accurate, domain-specific explanations to non-technical users.",
    $projectContext['domain'] ?? 'business',
    $projectContext['name'] ?? 'Application',
    $projectContext['description'] ?? '',
    $businessRules,
    json_encode([
        'entities' => array_map(function($entity) {
            return [
                'label' => $entity['graph']['label'] ?? '',
                'aliases' => $entity['metadata']['aliases'] ?? [],
                'description' => $entity['metadata']['description'] ?? ''
            ];
        }, config('entities', [])),
    ], JSON_PRETTY_PRINT),
    json_encode($context['graph_schema'], JSON_PRETTY_PRINT)
);

$responseUserPrompt = sprintf(
    "Original question: \"%s\"\n\nCypher query executed:\n%s\n\nQuery explanation: %s\n\nResults:\n%s\n\nExplain these results in plain English, using proper terminology from the domain.",
    $question,
    $cypherQuery,
    $explanation,
    json_encode($results, JSON_PRETTY_PRINT)
);

$response = AI::complete(
    $responseUserPrompt,
    $responseSystemPrompt
);

echo $response;
```

**What happens here:**

1. **Customer created** - Auto-synced to Neo4j (graph) + Qdrant (vectors)
2. **User question** - Natural language query received
3. **RAG retrieval** - Semantic search finds similar queries + graph schema
4. **LLM query generation** - Cypher query generated with full context
5. **Query execution** - Cypher executed against Neo4j
6. **Response generation** - Results explained with **same domain context** as query generation
7. **User receives** - Natural language answer using proper domain terminology

**Key Points:**
- No manual `AI::ingest()` call needed - auto-sync handles it!
- Both query and response generators receive **full project context**:
  - Project name, description, and domain type
  - Business rules specific to your application
  - Entity metadata (aliases, descriptions)
  - Graph schema (labels, relationships, properties)
- Ensures consistent terminology between query generation and response explanation
- LLM understands your business domain, not just generic database operations

---

## Error Handling

All methods handle errors gracefully:

```php
// Partial success - one store fails
$status = AI::ingest($customer);
if (!empty($status['errors'])) {
    Log::warning('Partial ingestion failure', $status['errors']);
    // Graph or vector might still be stored
}

// Batch ingestion errors
$result = AI::ingestBatch($customers);
if ($result['failed'] > 0) {
    foreach ($result['errors'] as $entityId => $errors) {
        Log::error("Entity {$entityId} failed: " . implode(', ', $errors));
    }
}

// RAG context with partial failures
$context = AI::retrieveContext($question);
if (!empty($context['errors'])) {
    // Some context sources failed, but others may have succeeded
    Log::warning('RAG partial failure', $context['errors']);
}
```

---

## Performance Tips

### Queue Auto-Sync Operations

For high-throughput applications, queue sync operations:

```env
AI_AUTO_SYNC_QUEUE=true
AI_AUTO_SYNC_QUEUE_CONNECTION=redis
```

Now all model events dispatch queued jobs instead of blocking.

### Use Batch Operations (When Auto-Sync Disabled)

```php
// If auto-sync is disabled and you must manually ingest:

// Slow - multiple API calls
foreach ($customers as $customer) {
    AI::ingest($customer);
}

// Fast - single batch operation
AI::ingestBatch($customers->toArray());
```

### Cache Embeddings for Static Content

```php
// Cache embeddings for content that doesn't change often
$embedding = Cache::remember("embed:faq_{$id}", 3600, function() use ($text) {
    return AI::embed($text);
});
```

### Batch Embed Multiple Texts

```php
// Slow - multiple API calls
$vectors = [];
foreach ($texts as $text) {
    $vectors[] = AI::embed($text);
}

// Fast - single API call
$vectors = AI::embedBatch($texts);
```

### Configure Timeouts

```env
# Set in .env for slow operations
QDRANT_TIMEOUT=60
AI_QUERY_TIMEOUT=60
NEO4J_TIMEOUT=60
```

### Disable Auto-Sync During Seeding

```php
// database/seeders/DatabaseSeeder.php
public function run()
{
    // Disable auto-sync for bulk seeding
    config(['ai.auto_sync.enabled' => false]);

    Customer::factory()->count(10000)->create();

    // Bulk ingest after seeding (one-time)
    Artisan::call('ai:ingest', ['--model' => 'App\\Models\\Customer']);
}
```

---

## Next Steps

- **[Advanced Usage](/docs/{{version}}/usage/advanced-usage)** - Direct service usage
- **[Data Ingestion API](/docs/{{version}}/usage/data-ingestion)** - Detailed ingestion guide
- **[Context Retrieval](/docs/{{version}}/usage/context-retrieval)** - RAG deep dive
- **[Configuration](/docs/{{version}}/foundations/configuration)** - All settings explained
