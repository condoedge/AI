# Facades API

Reference for the AI and FileSearch facades.

---

## AI Facade

The primary interface for AI operations.

```php
use Condoedge\Ai\Facades\AI;
```

---

## Data Ingestion Methods

### ingest()

Ingest a single entity into the AI system (Neo4j graph + Qdrant vectors).

```php
AI::ingest(\Condoedge\Ai\Domain\Contracts\Nodeable $entity): array
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$entity` | Nodeable | Entity implementing Nodeable contract |

**Returns:**

```php
[
    'graph_stored' => true,
    'vector_stored' => true,
    'relationships_created' => 2,
    'errors' => []
]
```

**Examples:**

```php
$customer = Customer::find(1);
$result = AI::ingest($customer);

if ($result['graph_stored'] && $result['vector_stored']) {
    echo "Entity ingested successfully!";
}
```

### ingestBatch()

Ingest multiple entities in a batch operation.

```php
AI::ingestBatch(array $entities): array
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$entities` | array | Array of Nodeable entities |

**Examples:**

```php
$customers = Customer::all()->toArray();
$result = AI::ingestBatch($customers);

// Returns:
[
    'ingested' => 100,
    'failed' => 0,
    'errors' => [],
]
```

### sync()

Sync an entity - updates existing or creates new entry.

```php
AI::sync(\Condoedge\Ai\Domain\Contracts\Nodeable $entity): array
```

**Examples:**

```php
$customer = Customer::find(1);
$customer->update(['status' => 'premium']);
$result = AI::sync($customer); // Updates graph and vector stores
```

### remove()

Remove an entity from the AI system.

```php
AI::remove(\Condoedge\Ai\Domain\Contracts\Nodeable $entity): bool
```

**Examples:**

```php
$customer = Customer::find(1);
AI::remove($customer);
$customer->delete();
```

---

## Context Retrieval Methods (RAG)

### retrieveContext()

Retrieve relevant context for a question (used for RAG).

```php
AI::retrieveContext(string $question, array $options = []): array
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$question` | string | Natural language question |
| `$options` | array | Optional settings |

**Examples:**

```php
$context = AI::retrieveContext("Show teams with most active members");

// Returns:
[
    'schema' => [...],
    'scopes' => [...],
    'examples' => [...],
    'similar_queries' => [...],
]
```

### searchSimilar()

Search for similar questions/queries in the vector store.

```php
AI::searchSimilar(string $question, array $options = []): array
```

**Examples:**

```php
$similar = AI::searchSimilar("Show all teams");

// Returns matching questions with similarity scores
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

### getExampleEntities()

Get example entities for specific labels (useful for few-shot prompting).

```php
AI::getExampleEntities(array $labels, int $limit = 3): array
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$labels` | array | Node labels to get examples for |
| `$limit` | int | Max examples per label (default: 3) |

**Examples:**

```php
$examples = AI::getExampleEntities(['Customer', 'Order'], 5);

// Returns example nodes for building context
```

### storeQuery()

Store a question-query pair for future retrieval.

```php
AI::storeQuery(string $question, string $cypherQuery, array $metadata = [], string $collection = 'questions'): array
```

**Examples:**

```php
AI::storeQuery(
    "Show all customers",
    "MATCH (c:Customer) RETURN c",
    ['confidence' => 0.9]
);
```

---

## Embedding Methods

### embed()

Generate embedding vector for text.

```php
AI::embed(string $text): array
```

**Examples:**

```php
$vector = AI::embed("Customer support inquiry");
// Returns: [0.123, -0.456, 0.789, ...]
```

### embedBatch()

Generate embeddings for multiple texts.

```php
AI::embedBatch(array $texts): array
```

**Examples:**

```php
$vectors = AI::embedBatch([
    "First text",
    "Second text",
]);

// Returns array of embedding vectors
```

### getEmbeddingDimensions()

Get the embedding vector dimensions for the current model.

```php
AI::getEmbeddingDimensions(): int
```

**Examples:**

```php
$dimensions = AI::getEmbeddingDimensions();
// Returns: 1536 (for OpenAI ada-002)
```

### getEmbeddingModel()

Get the current embedding model name.

```php
AI::getEmbeddingModel(): string
```

**Examples:**

```php
$model = AI::getEmbeddingModel();
// Returns: "text-embedding-ada-002"
```

---

## LLM Chat Methods

### chat()

Generate a response using the LLM.

```php
AI::chat(string|array $input, array $options = []): string
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$input` | string\|array | Message or messages array |
| `$options` | array | Optional settings |

**Examples:**

```php
// Simple chat
$response = AI::chat("What is 2+2?");

// With message array
$response = AI::chat([
    ['role' => 'system', 'content' => 'You are a helpful assistant.'],
    ['role' => 'user', 'content' => 'What is 2+2?'],
]);

// With options
$response = AI::chat("Explain Laravel", [
    'temperature' => 0.7,
    'max_tokens' => 500,
]);
```

### chatJson()

Generate a structured JSON response from the LLM.

```php
AI::chatJson(string|array $input, array $options = []): object|array
```

**Examples:**

```php
$data = AI::chatJson("Generate a user profile with name and age");

// Returns parsed JSON:
// ['name' => 'John Doe', 'age' => 30]
```

### complete()

Complete a prompt with an optional system message.

```php
AI::complete(string $prompt, string|null $systemPrompt = null, array $options = []): string
```

**Examples:**

```php
$translation = AI::complete(
    "Translate 'hello' to French",
    "You are a translator"
);
// Returns: "Bonjour"
```

### stream()

Stream responses from the LLM.

```php
AI::stream(array $messages, callable $callback, array $options = []): void
```

**Examples:**

```php
AI::stream(
    [['role' => 'user', 'content' => 'Write a story']],
    function ($chunk) {
        echo $chunk;
        ob_flush();
    }
);
```

### getLlmModel()

Get the current LLM model name.

```php
AI::getLlmModel(): string
```

**Examples:**

```php
$model = AI::getLlmModel();
// Returns: "gpt-4"
```

### getLlmProvider()

Get the current LLM provider name.

```php
AI::getLlmProvider(): string
```

**Examples:**

```php
$provider = AI::getLlmProvider();
// Returns: "openai"
```

### getLlmMaxTokens()

Get the maximum tokens for the current LLM.

```php
AI::getLlmMaxTokens(): int
```

**Examples:**

```php
$maxTokens = AI::getLlmMaxTokens();
// Returns: 8192
```

### countTokens()

Count tokens in a text string.

```php
AI::countTokens(string $text): int
```

**Examples:**

```php
$count = AI::countTokens("Hello, world!");
// Returns: 4
```

---

## Query Generation Methods

### generateQuery()

Generate Cypher query from natural language.

```php
AI::generateQuery(string $question, array $context = [], array $options = []): array
```

**Examples:**

```php
$result = AI::generateQuery("Show all customers with orders > 100");

// Returns:
[
    'query' => "MATCH (c:Customer)-[:HAS_ORDER]->(o:Order) WHERE o.total > 100 RETURN c",
    'confidence' => 0.85,
    'explanation' => "...",
]
```

### validateQuery()

Validate a Cypher query for syntax and safety.

```php
AI::validateQuery(string $cypherQuery, array $options = []): array
```

**Examples:**

```php
$validation = AI::validateQuery("MATCH (n:Customer) RETURN n LIMIT 10");

// Returns:
[
    'valid' => true,
    'safe' => true,
    'errors' => [],
    'warnings' => [],
]
```

### sanitizeQuery()

Sanitize a Cypher query by removing unsafe operations.

```php
AI::sanitizeQuery(string $cypherQuery): string
```

**Examples:**

```php
$safe = AI::sanitizeQuery("MATCH (n) DELETE n");
// Returns query with DELETE removed
```

### getQueryTemplates()

Get available query templates.

```php
AI::getQueryTemplates(): array
```

**Examples:**

```php
$templates = AI::getQueryTemplates();

// Returns array of template patterns
```

### detectQueryTemplate()

Detect if a question matches a known template.

```php
AI::detectQueryTemplate(string $question): string|null
```

**Examples:**

```php
$template = AI::detectQueryTemplate("How many customers?");
// Returns: "count_entities" or null if no match
```

### askQuestion()

Generate and validate a query for a question (without executing).

```php
AI::askQuestion(string $question, array $options = []): array
```

**Examples:**

```php
$result = AI::askQuestion("How many teams are active?");

// Returns validated query ready for execution
```

---

## Query Execution Methods

### executeQuery()

Execute a Cypher query directly.

```php
AI::executeQuery(string $cypherQuery, array $parameters = [], array $options = []): array
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$cypherQuery` | string | Cypher query |
| `$parameters` | array | Query parameters |
| `$options` | array | Execution options |

**Examples:**

```php
$results = AI::executeQuery("MATCH (n:Customer) RETURN n LIMIT 10");

// Parameterized query
$results = AI::executeQuery(
    "MATCH (n:Customer) WHERE n.status = \$status RETURN n",
    ['status' => 'active']
);
```

### executeCount()

Execute a query and return the count.

```php
AI::executeCount(string $cypherQuery, array $parameters = [], array $options = []): int
```

**Examples:**

```php
$count = AI::executeCount("MATCH (n:Customer) RETURN n");
// Returns: 150
```

### executePaginated()

Execute a query with pagination.

```php
AI::executePaginated(string $cypherQuery, int $page = 1, int $perPage = 20, array $parameters = [], array $options = []): array
```

**Examples:**

```php
$results = AI::executePaginated(
    "MATCH (n:Customer) RETURN n",
    page: 2,
    perPage: 25
);

// Returns:
[
    'data' => [...],
    'total' => 150,
    'page' => 2,
    'per_page' => 25,
    'total_pages' => 6,
]
```

### explainQuery()

Get execution plan for a query.

```php
AI::explainQuery(string $cypherQuery, array $parameters = []): array
```

**Examples:**

```php
$plan = AI::explainQuery("MATCH (n:Customer) RETURN n");

// Returns query execution plan
```

### testQuery()

Test if a query is valid and executable.

```php
AI::testQuery(string $cypherQuery): bool
```

**Examples:**

```php
$valid = AI::testQuery("MATCH (n:Customer) RETURN n");
// Returns: true
```

---

## Full Pipeline Methods

### ask()

Execute the full pipeline: Question -> Query -> Execute.

```php
AI::ask(string $question, array $options = []): array
```

**Examples:**

```php
$result = AI::ask("How many customers do we have?");

// Returns:
[
    'question' => "How many customers do we have?",
    'query' => "MATCH (c:Customer) RETURN count(c) as count",
    'result' => [['count' => 150]],
]
```

### answerQuestion()

Complete pipeline with insights and visualizations.

```php
AI::answerQuestion(string $question, array $options = []): array
```

**Examples:**

```php
$answer = AI::answerQuestion("Which customers have the most orders?");

// Returns:
[
    'question' => "Which customers have the most orders?",
    'answer' => "The top customers by order count are...",
    'insights' => [...],
    'visualizations' => [...],
    'cypher' => "MATCH (c:Customer)-[:HAS_ORDER]->(o) RETURN c, count(o) ORDER BY count(o) DESC",
    'data' => [...],
    'stats' => [...],
]
```

---

## Response Generation Methods

### generateResponse()

Generate a natural language response from query results.

```php
AI::generateResponse(string $originalQuestion, array $queryResult, string $cypherQuery, array $options = []): array
```

**Examples:**

```php
$response = AI::generateResponse(
    "How many teams?",
    [['count' => 25]],
    "MATCH (n:Team) RETURN count(n) as count"
);
```

### extractInsights()

Extract insights from query results.

```php
AI::extractInsights(array $queryResult): array
```

**Examples:**

```php
$insights = AI::extractInsights($queryResult);

// Returns:
[
    'summary' => "...",
    'key_findings' => [...],
    'trends' => [...],
]
```

### suggestVisualizations()

Suggest appropriate visualizations for query results.

```php
AI::suggestVisualizations(array $queryResult, string $cypherQuery): array
```

**Examples:**

```php
$charts = AI::suggestVisualizations($results, "MATCH (n) RETURN n");

// Returns:
[
    ['type' => 'bar', 'config' => [...]],
    ['type' => 'pie', 'config' => [...]],
]
```

---

## FileSearch Facade

Search files across Neo4j (metadata/relationships) and Qdrant (content).

```php
use Condoedge\Ai\Facades\FileSearch;
```

### searchByContent()

Search files by content using semantic search (Qdrant).

```php
FileSearch::searchByContent(string $query, array $options = []): array
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `limit` | int | 10 | Max results |
| `file_types` | array | [] | Filter by file types |
| `min_score` | float | 0.7 | Minimum similarity score |

**Examples:**

```php
$results = FileSearch::searchByContent("Laravel configuration", [
    'limit' => 5,
    'file_types' => ['pdf', 'md'],
    'min_score' => 0.7,
]);

// Returns:
[
    [
        'file_id' => 1,
        'score' => 0.85,
        'chunk_count' => 3,
        'best_chunk' => FileChunk,
        'chunks' => [FileChunk, FileChunk, FileChunk],
        'file' => File,
    ],
]
```

### searchByMetadata()

Search files by metadata using Neo4j.

```php
FileSearch::searchByMetadata(array $criteria, int $limit = 10): array
```

**Examples:**

```php
$files = FileSearch::searchByMetadata([
    'extension' => 'pdf',
    'user_id' => 123,
    'size_min' => 1000,
], limit: 10);

// Returns:
[
    [
        'file' => File,
        'relationships' => [
            ['type' => 'UPLOADED_BY', 'labels' => ['User'], 'properties' => [...]],
            ['type' => 'BELONGS_TO_TEAM', 'labels' => ['Team'], 'properties' => [...]],
        ],
    ],
]
```

### hybridSearch()

Combine content and metadata search.

```php
FileSearch::hybridSearch(string $contentQuery, array $metadataFilters = [], array $options = []): array
```

**Examples:**

```php
$results = FileSearch::hybridSearch(
    contentQuery: "Redis configuration",
    metadataFilters: ['extension' => 'md'],
    options: ['limit' => 10, 'include_relationships' => true]
);
```

### getRelatedFiles()

Get related files via graph traversal.

```php
FileSearch::getRelatedFiles(\Condoedge\Utils\Models\Files\File $file, ?string $relationshipType = null, int $limit = 10): array
```

**Examples:**

```php
$related = FileSearch::getRelatedFiles($file, 'BELONGS_TO', limit: 10);
```

### getFilesByUser()

Get files uploaded by a specific user.

```php
FileSearch::getFilesByUser(int $userId, int $limit = 10): array
```

**Examples:**

```php
$files = FileSearch::getFilesByUser(userId: 123, limit: 10);
```

### getFilesByTeam()

Get files belonging to a team.

```php
FileSearch::getFilesByTeam(int $teamId, int $limit = 10): array
```

**Examples:**

```php
$files = FileSearch::getFilesByTeam(teamId: 456, limit: 10);
```

---

## Usage Examples

### Complete Query Flow

```php
use Condoedge\Ai\Facades\AI;

// Full pipeline - question to answer
$answer = AI::answerQuestion("How many active customers placed orders this month?");

// Step by step
$query = AI::generateQuery("Show active customers");
$validated = AI::validateQuery($query['query']);
if ($validated['valid']) {
    $results = AI::executeQuery($query['query']);
}
```

### Data Ingestion Workflow

```php
// Single entity
$customer = Customer::create(['name' => 'Acme Corp', 'status' => 'active']);
AI::ingest($customer);

// Batch ingestion
$customers = Customer::where('created_at', '>', now()->subDay())->get();
AI::ingestBatch($customers->toArray());

// Sync after update
$customer->update(['status' => 'premium']);
AI::sync($customer);

// Remove before delete
AI::remove($customer);
$customer->delete();
```

### File Search with AI Context

```php
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Facades\FileSearch;

// Search for relevant files
$results = FileSearch::searchByContent("Redis configuration");

// Build context from file chunks
$context = collect($results)
    ->flatMap(fn($r) => $r['chunks'])
    ->pluck('content')
    ->implode("\n\n");

// Get AI-powered answer using file context
$answer = AI::answerQuestion("How do I configure Redis?", [
    'context' => $context,
]);
```

### Streaming Responses

```php
AI::stream(
    [
        ['role' => 'system', 'content' => 'You are a helpful assistant.'],
        ['role' => 'user', 'content' => 'Write a detailed explanation of Laravel facades.'],
    ],
    function ($chunk) {
        echo $chunk;
        ob_flush();
        flush();
    },
    ['temperature' => 0.7]
);
```

---

## Testing with Facades

```php
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Facades\FileSearch;

// Mock AI facade
AI::shouldReceive('ingest')
    ->once()
    ->with($customer)
    ->andReturn([
        'graph_stored' => true,
        'vector_stored' => true,
        'relationships_created' => 2,
        'errors' => []
    ]);

// Mock FileSearch facade
FileSearch::shouldReceive('searchByContent')
    ->once()
    ->with('test query', ['limit' => 10])
    ->andReturn([
        [
            'file_id' => 1,
            'score' => 0.85,
            'file' => $mockFile,
        ]
    ]);
```

---

## Error Handling

```php
use Condoedge\Ai\Exceptions\AiException;
use Condoedge\Ai\Exceptions\QueryGenerationException;
use Condoedge\Ai\Exceptions\ConnectionException;

try {
    $response = AI::answerQuestion("Complex query here");
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
