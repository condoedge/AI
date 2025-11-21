# Advanced Usage (Dependency Injection & Direct Services)

For maximum control, testability, and flexibility, you can use dependency injection or access services directly instead of using the AI Facade.

---

## Three Usage Approaches

The AI system provides three ways to access functionality:

### 1. Facade (Simplest)
```php
use Condoedge\Ai\Facades\AI;

AI::ingest($customer);
```
**Best for:** Quick integration, clean code, standard apps

### 2. AiManager Dependency Injection (Recommended for Testing)
```php
use Condoedge\Ai\Services\AiManager;

class CustomerController extends Controller
{
    public function __construct(private AiManager $ai) {}

    public function store(Request $request)
    {
        $this->ai->ingest($customer);
    }
}
```
**Best for:** Testable code, SOLID principles, constructor injection

### 3. Direct Service Injection (Maximum Control)
```php
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;

class CustomerService
{
    public function __construct(
        private DataIngestionServiceInterface $ingestion
    ) {}

    public function process(Customer $customer)
    {
        $this->ingestion->ingest($customer);
    }
}
```
**Best for:** Custom implementations, fine-grained control, libraries

---

## Using AiManager with Dependency Injection

The `AiManager` service provides the same convenient API as the Facade, but with proper dependency injection.

### Basic Injection

```php
use Condoedge\Ai\Services\AiManager;

class CustomerService
{
    public function __construct(private AiManager $ai) {}

    public function processCustomer(Customer $customer): array
    {
        // Ingest customer data
        $status = $this->ai->ingest($customer);

        // Retrieve context
        $context = $this->ai->retrieveContext("related customers");

        // Generate insights
        $response = $this->ai->chat("Analyze this customer data");

        return [
            'ingestion' => $status,
            'context' => $context,
            'insights' => $response
        ];
    }
}
```

### Controller Example

```php
use Condoedge\Ai\Services\AiManager;
use Illuminate\Http\JsonResponse;

class CustomerController extends Controller
{
    public function __construct(private AiManager $ai) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email',
            'description' => 'nullable|string'
        ]);

        $customer = Customer::create($validated);

        // Ingest into AI system
        $status = $this->ai->ingest($customer);

        return response()->json([
            'customer' => $customer,
            'ai_status' => $status
        ], 201);
    }

    public function search(Request $request): JsonResponse
    {
        $question = $request->input('question');

        $context = $this->ai->retrieveContext($question, [
            'collection' => 'customers',
            'limit' => 10
        ]);

        return response()->json($context);
    }
}
```

### Service Class Example

```php
use Condoedge\Ai\Services\AiManager;

class QueryGenerationService
{
    public function __construct(private AiManager $ai) {}

    public function generateQuery(string $naturalLanguage): string
    {
        // Get context from RAG
        $context = $this->ai->retrieveContext($naturalLanguage);

        // Build prompt
        $prompt = $this->buildPrompt($naturalLanguage, $context);

        // Generate query
        $result = $this->ai->chatJson($prompt);

        return $result->query ?? '';
    }

    private function buildPrompt(string $question, array $context): string
    {
        return sprintf(
            "Question: %s\n\nSchema: %s\n\nSimilar Queries: %s\n\nGenerate Cypher query (JSON).",
            $question,
            json_encode($context['graph_schema']),
            json_encode($context['similar_queries'])
        );
    }
}
```

### Testing with AiManager

Easily mock AiManager in tests:

```php
use Condoedge\Ai\Services\AiManager;
use Mockery;

class CustomerServiceTest extends TestCase
{
    public function test_process_customer()
    {
        // Create mock AiManager
        $mockAi = Mockery::mock(AiManager::class);

        // Set expectations
        $mockAi->shouldReceive('ingest')
            ->once()
            ->with(Mockery::type(Customer::class))
            ->andReturn([
                'graph_stored' => true,
                'vector_stored' => true,
                'relationships_created' => 2,
                'errors' => []
            ]);

        $mockAi->shouldReceive('retrieveContext')
            ->once()
            ->andReturn(['similar_queries' => []]);

        // Inject mock into service
        $service = new CustomerService($mockAi);

        // Test
        $customer = Customer::factory()->create();
        $result = $service->processCustomer($customer);

        $this->assertTrue($result['ingestion']['graph_stored']);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

---

## Manual Service Instantiation

### Data Ingestion Service

```php
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\VectorStore\QdrantStore;
use Condoedge\Ai\GraphStore\Neo4jStore;
use Condoedge\Ai\EmbeddingProviders\OpenAiEmbeddingProvider;

$vectorStore = new QdrantStore([
    'host' => 'localhost',
    'port' => 6333,
    'api_key' => null
]);

$graphStore = new Neo4jStore([
    'uri' => 'bolt://localhost:7687',
    'username' => 'neo4j',
    'password' => 'password'
]);

$embeddingProvider = new OpenAiEmbeddingProvider([
    'api_key' => env('OPENAI_API_KEY'),
    'model' => 'text-embedding-3-small',
    'dimensions' => 1536
]);

$ingestion = new DataIngestionService(
    vectorStore: $vectorStore,
    graphStore: $graphStore,
    embeddingProvider: $embeddingProvider
);

$customer = Customer::find(1);
$status = $ingestion->ingest($customer);
```

---

## Using Different LLM Providers

### Anthropic LLM

```php
use Condoedge\Ai\LlmProviders\AnthropicLlmProvider;

$claude = new AnthropicLlmProvider([
    'api_key' => env('ANTHROPIC_API_KEY'),
    'model' => 'claude-3-5-sonnet-20241022',
    'temperature' => 0.3,
    'max_tokens' => 4000
]);

$response = $claude->chat([
    ['role' => 'user', 'content' => 'Explain quantum computing']
]);
```

---

## Direct Store Access

### Neo4j Operations

```php
use Condoedge\Ai\GraphStore\Neo4jStore;

$neo4j = new Neo4jStore(config('ai.graph.neo4j'));

// Create node
$neo4j->createNode('Person', ['name' => 'John', 'age' => 30]);

// Query
$results = $neo4j->query(
    'MATCH (p:Person) WHERE p.age > $age RETURN p',
    ['age' => 25]
);

// Get schema
$schema = $neo4j->getSchema();
```

---

## Custom Provider Implementation

```php
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;

class CustomEmbeddingProvider implements EmbeddingProviderInterface
{
    public function embed(string $text): array
    {
        // Your implementation
        return $this->callCustomApi($text);
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn($t) => $this->embed($t), $texts);
    }
}
```

---

## Injecting Individual Services

For maximum control, inject only the services you need:

### Example: Injecting DataIngestionService

```php
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;

class CustomerController extends Controller
{
    public function __construct(
        private DataIngestionServiceInterface $ingestion
    ) {}

    public function store(Request $request)
    {
        $customer = Customer::create($request->validated());
        $status = $this->ingestion->ingest($customer);

        return response()->json([
            'customer' => $customer,
            'ai_status' => $status
        ]);
    }
}
```

### Example: Multiple Service Injection

```php
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;

class QueryService
{
    public function __construct(
        private DataIngestionServiceInterface $ingestion,
        private ContextRetrieverInterface $context,
        private LlmProviderInterface $llm
    ) {}

    public function processQuery(string $question): string
    {
        // Retrieve context
        $context = $this->context->retrieveContext($question);

        // Generate query with LLM
        $prompt = $this->buildPrompt($question, $context);
        $response = $this->llm->chat($prompt);

        return $response;
    }
}
```

---

## Testing with Direct Service Mocks

```php
use Mockery;
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;

class DataIngestionTest extends TestCase
{
    public function test_ingest_with_mocked_dependencies()
    {
        // Create mocks for each dependency
        $vectorMock = Mockery::mock(VectorStoreInterface::class);
        $graphMock = Mockery::mock(GraphStoreInterface::class);
        $embedMock = Mockery::mock(EmbeddingProviderInterface::class);

        // Set expectations
        $embedMock->shouldReceive('embed')
            ->once()
            ->with('test content')
            ->andReturn([0.1, 0.2, 0.3]);

        $vectorMock->shouldReceive('upsert')
            ->once()
            ->andReturn(true);

        $graphMock->shouldReceive('createNode')
            ->once()
            ->with('Customer', Mockery::any())
            ->andReturn('node-id-123');

        // Inject mocks into service
        $service = new DataIngestionService($vectorMock, $graphMock, $embedMock);

        // Test
        $entity = new TestEntity(['content' => 'test content']);
        $status = $service->ingest($entity);

        // Assert
        $this->assertTrue($status['graph_stored']);
        $this->assertTrue($status['vector_stored']);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

---

## Comparison of Approaches

| Feature | Facade | AiManager DI | Direct Services |
|---------|--------|--------------|-----------------|
| **Ease of Use** | Easiest | Easy | More Complex |
| **Testability** | Good (facade mocking) | Excellent | Excellent |
| **Flexibility** | Limited | Good | Maximum |
| **Dependencies** | Hidden | Explicit (single) | Explicit (multiple) |
| **Best For** | Quick integration | Testable apps | Libraries, packages |

### When to Use Each:

**Use Facade when:**
- You want simple, clean code
- Testing with facade mocks is sufficient
- You're building a standard Laravel app

**Use AiManager DI when:**
- You want explicit dependencies in constructor
- You need easy testing with dependency injection
- You want all AI features in one service

**Use Direct Services when:**
- You only need specific functionality
- You're building a library or reusable package
- You want maximum control over dependencies
- You need custom service implementations

---

See also: [Simple Usage](/docs/{{version}}/usage/simple-usage) | [Testing](/docs/{{version}}/usage/testing) | [Laravel Integration](/docs/{{version}}/usage/laravel-integration)
