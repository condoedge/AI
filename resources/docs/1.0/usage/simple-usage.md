# Simple Usage (AI Facade)

The `AI` Facade provides a simple, one-line interface to all AI system features. This is the **recommended approach** for most use cases.

---

## Overview

The AI Facade automatically handles:
- Dependency instantiation
- Configuration loading
- Service coordination
- Error handling

> **Architecture Note**: The AI system was recently refactored to follow Laravel best practices. The facade now properly leverages Laravel's service container and dependency injection, making it fully testable. See [Migration Guide](https://github.com/your-repo/MIGRATION-GUIDE.md) for details.

```php
use Condoedge\Ai\Facades\AI;

// Facade usage (recommended)
AI::ingest($customer);
$context = AI::retrieveContext("Show all teams");
$response = AI::chat("What is 2+2?");
```

> **Note**: The old `Condoedge\Ai\Wrappers\AI` class is **deprecated** and will be removed in v2.0. Please update your imports to use `Condoedge\Ai\Facades\AI`.

---

## Data Ingestion Methods

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

Here's a complete example combining multiple features:

```php
use Condoedge\Ai\Facades\AI;

// 1. Ingest customer data
$customer = Customer::create([
    'name' => 'Acme Corporation',
    'email' => 'contact@acme.com',
    'description' => 'Leading software development company'
]);

$status = AI::ingest($customer);
if (!empty($status['errors'])) {
    Log::warning('Ingestion errors', $status['errors']);
}

// 2. User asks a question
$question = "Which customers are in the software industry?";

// 3. Retrieve context using RAG
$context = AI::retrieveContext($question, [
    'collection' => 'customers',
    'limit' => 5,
    'includeSchema' => true
]);

// 4. Build LLM prompt with context
$systemPrompt = "You are a Cypher query expert. Generate valid Neo4j queries.";
$userPrompt = sprintf(
    "Question: %s\n\nSchema: %s\n\nSimilar Queries: %s\n\nGenerate Cypher query (JSON format).",
    $question,
    json_encode($context['graph_schema']),
    json_encode($context['similar_queries'])
);

// 5. Generate query using LLM
$result = AI::chatJson($userPrompt, ['temperature' => 0.2]);
$cypherQuery = $result->query ?? '';

// 6. Execute query (not part of AI wrapper - use Neo4jStore directly)
// $results = Neo4jStore::query($cypherQuery);

// 7. Generate human-readable response
$response = AI::complete(
    "Explain these results to the user: " . json_encode($results ?? []),
    "You are a helpful assistant explaining database query results"
);

echo $response;
```

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

### Use Batch Operations
```php
// Slow - multiple API calls
foreach ($customers as $customer) {
    AI::ingest($customer);
}

// Fast - single batch operation
AI::ingestBatch($customers->toArray());
```

### Cache Embeddings
```php
// Cache frequently used embeddings
$embedding = Cache::remember("embed:{$text}", 3600, function() use ($text) {
    return AI::embed($text);
});
```

### Configure Timeouts
```php
// Set in .env for slow operations
QDRANT_TIMEOUT=60
AI_QUERY_TIMEOUT=60
```

---

## Next Steps

- **[Advanced Usage](/docs/{{version}}/usage/advanced-usage)** - Direct service usage
- **[Data Ingestion API](/docs/{{version}}/usage/data-ingestion)** - Detailed ingestion guide
- **[Context Retrieval](/docs/{{version}}/usage/context-retrieval)** - RAG deep dive
- **[Configuration](/docs/{{version}}/foundations/configuration)** - All settings explained
