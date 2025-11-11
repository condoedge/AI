# AI System Usage Examples

This document shows two ways to use the AI system:
1. **Simple Approach** - Using the `AI` wrapper (recommended for most cases)
2. **Advanced Approach** - Using services directly (for custom configurations)

## Table of Contents
- [Installation](#installation)
- [Simple Usage (Recommended)](#simple-usage-recommended)
- [Advanced Usage](#advanced-usage)
- [Comparison](#comparison)
- [Laravel Integration](#laravel-integration)

---

## Installation

### 1. Register the Service Provider (Laravel)

Add to `config/app.php`:

```php
'providers' => [
    // ...
    Condoedge\Ai\AiServiceProvider::class,
]
```

### 2. Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag=ai-config
php artisan vendor:publish --tag=ai-entities
```

### 3. Configure Environment Variables

Add to `.env`:

```env
# Neo4j Configuration
NEO4J_HOST=http://localhost:7474
NEO4J_USER=neo4j
NEO4J_PASSWORD=your-password

# Qdrant Configuration
QDRANT_HOST=http://localhost:6333

# OpenAI Configuration (Optional)
OPENAI_API_KEY=sk-your-key-here

# Anthropic Configuration (Optional)
ANTHROPIC_API_KEY=sk-ant-your-key-here
```

---

## Simple Usage (Recommended)

### Data Ingestion

```php
use Condoedge\Ai\Wrappers\AI;

// Ingest a single entity
$customer = Customer::find(1);
AI::ingest($customer);

// Ingest multiple entities (batch - more efficient)
$customers = Customer::all();
AI::ingestBatch($customers->toArray());

// Sync an entity (update if exists, create if not)
AI::sync($customer);

// Remove an entity
AI::remove($customer);
```

### Context Retrieval (RAG)

```php
use Condoedge\Ai\Wrappers\AI;

// Get complete context for a question
$context = AI::retrieveContext("Show teams with most active members");

// Returns:
// [
//     'similar_queries' => [...],    // Similar past questions
//     'graph_schema' => [...],       // Neo4j schema info
//     'relevant_entities' => [...],  // Example entities
//     'errors' => []
// ]

// Search for similar questions only
$similar = AI::searchSimilar("Show all teams", [
    'limit' => 5,
    'scoreThreshold' => 0.7
]);

// Get graph schema
$schema = AI::getSchema();
```

### Embeddings

```php
use Condoedge\Ai\Wrappers\AI;

// Generate embedding for a single text
$vector = AI::embed("Some text to embed");

// Generate embeddings in batch (more efficient)
$vectors = AI::embedBatch([
    "First text",
    "Second text",
    "Third text"
]);
```

### LLM Chat

```php
use Condoedge\Ai\Wrappers\AI;

// Simple chat
$response = AI::chat("What is the capital of France?");

// Chat with conversation history
$response = AI::chat([
    ['role' => 'system', 'content' => 'You are a helpful assistant'],
    ['role' => 'user', 'content' => 'Hello!'],
    ['role' => 'assistant', 'content' => 'Hi! How can I help you?'],
    ['role' => 'user', 'content' => 'What is 2+2?']
]);

// Get JSON response
$data = AI::chatJson("Generate a JSON with name and age fields");

// Simple completion
$response = AI::complete(
    "Translate 'hello' to French",
    "You are a professional translator"
);
```

### Complete Example: Question Answering

```php
use Condoedge\Ai\Wrappers\AI;

// User asks a question
$question = "Show me teams with more than 5 active members";

// Step 1: Get context using RAG
$context = AI::retrieveContext($question, [
    'collection' => 'customer_questions',
    'limit' => 5,
    'includeSchema' => true,
    'includeExamples' => true
]);

// Step 2: Build prompt for LLM
$prompt = [
    [
        'role' => 'system',
        'content' => "You are a Cypher query expert. Generate valid Neo4j Cypher queries."
    ],
    [
        'role' => 'user',
        'content' => "Question: {$question}\n\n" .
                     "Schema: " . json_encode($context['graph_schema']) . "\n\n" .
                     "Similar queries: " . json_encode($context['similar_queries']) . "\n\n" .
                     "Generate a Cypher query to answer this question."
    ]
];

// Step 3: Generate query using LLM
$cypherQuery = AI::chat($prompt);

echo "Generated Query: {$cypherQuery}";
```

### Instance Usage (Alternative to Static)

```php
use Condoedge\Ai\Wrappers\AI;

// Create an instance with custom configuration
$ai = new AI([
    'embedding_provider' => 'anthropic',
    'llm_provider' => 'openai'
]);

// Use instance methods
$ai->ingestEntity($customer);
$context = $ai->getContext("Show all teams");
$response = $ai->chatWithLlm("Hello!");
```

---

## Advanced Usage

When you need full control over dependencies and configuration:

### Data Ingestion (Advanced)

```php
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\VectorStore\QdrantStore;
use Condoedge\Ai\GraphStore\Neo4jStore;
use Condoedge\Ai\EmbeddingProviders\OpenAiEmbeddingProvider;

// Manually create dependencies
$vectorStore = new QdrantStore([
    'host' => 'http://localhost:6333',
    'api_key' => null
]);

$graphStore = new Neo4jStore([
    'host' => 'http://localhost:7474',
    'user' => 'neo4j',
    'password' => 'password'
]);

$embeddingProvider = new OpenAiEmbeddingProvider([
    'api_key' => 'sk-your-key',
    'model' => 'text-embedding-3-small',
    'dimensions' => 1536
]);

// Create service
$ingestionService = new DataIngestionService(
    vectorStore: $vectorStore,
    graphStore: $graphStore,
    embeddingProvider: $embeddingProvider
);

// Use service
$customer = Customer::find(1);
$status = $ingestionService->ingest($customer);
```

### Context Retrieval (Advanced)

```php
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\VectorStore\QdrantStore;
use Condoedge\Ai\GraphStore\Neo4jStore;
use Condoedge\Ai\EmbeddingProviders\OpenAiEmbeddingProvider;

// Manually create dependencies
$vectorStore = new QdrantStore(config('ai.vector.qdrant'));
$graphStore = new Neo4jStore(config('ai.graph.neo4j'));
$embeddingProvider = new OpenAiEmbeddingProvider(config('ai.embedding.openai'));

// Create service
$contextRetriever = new ContextRetriever(
    vectorStore: $vectorStore,
    graphStore: $graphStore,
    embeddingProvider: $embeddingProvider
);

// Use service
$context = $contextRetriever->retrieveContext(
    "Show teams with most active members",
    [
        'collection' => 'questions',
        'limit' => 10,
        'includeSchema' => true,
        'includeExamples' => true,
        'examplesPerLabel' => 3,
        'scoreThreshold' => 0.7
    ]
);
```

### Using Different Providers

```php
use Condoedge\Ai\EmbeddingProviders\AnthropicEmbeddingProvider;
use Condoedge\Ai\LlmProviders\AnthropicLlmProvider;

// Use Anthropic for embeddings
$embeddingProvider = new AnthropicEmbeddingProvider([
    'api_key' => 'sk-ant-your-key',
    'model' => 'claude-3-sonnet',
    'dimensions' => 1024
]);

// Use Anthropic for LLM
$llmProvider = new AnthropicLlmProvider([
    'api_key' => 'sk-ant-your-key',
    'model' => 'claude-3-5-sonnet-20241022',
    'temperature' => 0.3,
    'max_tokens' => 4000
]);

$response = $llmProvider->chat([
    ['role' => 'user', 'content' => 'Hello!']
]);
```

---

## Comparison

### Ingesting an Entity

**Simple Approach:**
```php
AI::ingest($customer);
```

**Advanced Approach:**
```php
$vectorStore = new QdrantStore(config('ai.vector.qdrant'));
$graphStore = new Neo4jStore(config('ai.graph.neo4j'));
$embeddingProvider = new OpenAiEmbeddingProvider(config('ai.embedding.openai'));

$service = new DataIngestionService($vectorStore, $graphStore, $embeddingProvider);
$service->ingest($customer);
```

### Getting Context

**Simple Approach:**
```php
$context = AI::retrieveContext("Show all teams");
```

**Advanced Approach:**
```php
$vectorStore = new QdrantStore(config('ai.vector.qdrant'));
$graphStore = new Neo4jStore(config('ai.graph.neo4j'));
$embeddingProvider = new OpenAiEmbeddingProvider(config('ai.embedding.openai'));

$retriever = new ContextRetriever($vectorStore, $graphStore, $embeddingProvider);
$context = $retriever->retrieveContext("Show all teams");
```

### Chatting with LLM

**Simple Approach:**
```php
$response = AI::chat("What is 2+2?");
```

**Advanced Approach:**
```php
$llmProvider = new OpenAiLlmProvider(config('ai.llm.openai'));
$response = $llmProvider->chat([
    ['role' => 'user', 'content' => 'What is 2+2?']
]);
```

---

## Laravel Integration

### Dependency Injection in Controllers

```php
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\ContextRetrieverInterface;

class CustomerController extends Controller
{
    public function __construct(
        private DataIngestionServiceInterface $ingestion,
        private ContextRetrieverInterface $context
    ) {}

    public function store(Request $request)
    {
        // Create customer
        $customer = Customer::create($request->validated());

        // Ingest into AI system
        $status = $this->ingestion->ingest($customer);

        return response()->json([
            'customer' => $customer,
            'ai_status' => $status
        ]);
    }

    public function search(Request $request)
    {
        $question = $request->input('question');

        // Get context using RAG
        $context = $this->context->retrieveContext($question);

        return response()->json($context);
    }
}
```

### Using AI Wrapper in Controllers

```php
use Condoedge\Ai\Wrappers\AI;

class ChatController extends Controller
{
    public function ask(Request $request)
    {
        $question = $request->input('question');

        // Get context
        $context = AI::retrieveContext($question);

        // Generate response
        $response = AI::chat([
            [
                'role' => 'system',
                'content' => 'You are a helpful assistant with access to a graph database.'
            ],
            [
                'role' => 'user',
                'content' => "Question: {$question}\n\nContext: " . json_encode($context)
            ]
        ]);

        return response()->json([
            'question' => $question,
            'answer' => $response,
            'context' => $context
        ]);
    }
}
```

### Model Observers

```php
use Condoedge\Ai\Wrappers\AI;

class CustomerObserver
{
    public function created(Customer $customer)
    {
        // Automatically ingest new customers
        AI::ingest($customer);
    }

    public function updated(Customer $customer)
    {
        // Sync changes
        AI::sync($customer);
    }

    public function deleted(Customer $customer)
    {
        // Remove from AI system
        AI::remove($customer);
    }
}
```

### Artisan Commands

```php
use Condoedge\Ai\Wrappers\AI;
use Illuminate\Console\Command;

class IngestCustomersCommand extends Command
{
    protected $signature = 'ai:ingest-customers';
    protected $description = 'Ingest all customers into AI system';

    public function handle()
    {
        $customers = Customer::all();

        $this->info("Ingesting {$customers->count()} customers...");

        $result = AI::ingestBatch($customers->toArray());

        $this->info("Succeeded: {$result['succeeded']}");
        $this->info("Failed: {$result['failed']}");

        return 0;
    }
}
```

---

## When to Use Each Approach

### Use Simple Approach (AI Wrapper) When:
- ✅ You want quick integration with minimal setup
- ✅ Default configurations work for your use case
- ✅ You prefer clean, readable code
- ✅ You're building a standard application
- ✅ You want to reduce boilerplate code

### Use Advanced Approach (Direct Services) When:
- ✅ You need custom provider implementations
- ✅ You want full control over configuration
- ✅ You're building a library or package
- ✅ You need to swap providers at runtime
- ✅ You require advanced testing scenarios
- ✅ You want to use multiple configurations simultaneously

---

## Best Practices

1. **Choose One Approach**: Don't mix simple and advanced approaches in the same codebase
2. **Use Dependency Injection**: Leverage Laravel's container for automatic wiring
3. **Configure Once**: Set up `.env` variables properly from the start
4. **Batch Operations**: Use batch methods when processing multiple entities
5. **Error Handling**: Always check status arrays for errors
6. **Testing**: Use the AI wrapper's `reset()` method in tests to clear singleton

```php
// In tests
public function setUp(): void
{
    parent::setUp();
    AI::reset(); // Clear singleton between tests
}
```

---

## Summary

The AI system provides **flexibility** while maintaining **simplicity**:

- **Simple Wrapper (`AI`)**: One-line usage, automatic configuration, perfect for 90% of use cases
- **Advanced Services**: Full control, custom configurations, perfect for complex scenarios
- **Both approaches** use the same underlying services - no functionality is lost!

Choose the approach that best fits your needs, and enjoy building AI-powered features! 🚀
