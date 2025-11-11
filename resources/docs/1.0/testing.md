# Testing Your Integration

Guide to testing AI system integrations with unit tests, integration tests, and mocking strategies.

---

## Overview

**Test Types:**
- **Unit Tests** - Fast, isolated, mock all dependencies
- **Integration Tests** - Slower, test with real Neo4j/Qdrant
- **Feature Tests** - End-to-end Laravel tests

**Testing Approaches:**
- **Facade Mocking** - Mock the AI Facade (simplest)
- **AiManager Injection** - Inject mock AiManager (recommended)
- **Service Mocking** - Mock individual services (maximum control)

---

## Testing with AI Facade

The AI Facade supports Laravel's facade mocking, making it easy to test.

### Basic Facade Mocking

```php
use Condoedge\Ai\Facades\AI;
use Tests\TestCase;

class CustomerServiceTest extends TestCase
{
    public function test_customer_ingestion()
    {
        // Mock the facade
        AI::shouldReceive('ingest')
            ->once()
            ->with(Mockery::type(Customer::class))
            ->andReturn([
                'graph_stored' => true,
                'vector_stored' => true,
                'relationships_created' => 2,
                'errors' => []
            ]);

        // Run code that uses AI::ingest()
        $service = new CustomerService();
        $customer = Customer::factory()->create();
        $result = $service->processCustomer($customer);

        $this->assertTrue($result);
    }

    public function test_context_retrieval()
    {
        AI::shouldReceive('retrieveContext')
            ->once()
            ->with('Show all teams', [])
            ->andReturn([
                'similar_queries' => [
                    ['question' => 'List teams', 'score' => 0.89]
                ],
                'graph_schema' => [
                    'labels' => ['Team', 'Person'],
                    'relationships' => ['MEMBER_OF']
                ],
                'relevant_entities' => [],
                'errors' => []
            ]);

        $service = new QueryService();
        $context = $service->getContext('Show all teams');

        $this->assertNotEmpty($context['similar_queries']);
    }
}
```

### Multiple Method Mocking

```php
public function test_full_workflow()
{
    // Mock multiple methods
    AI::shouldReceive('ingest')
        ->once()
        ->andReturn(['graph_stored' => true, 'vector_stored' => true]);

    AI::shouldReceive('retrieveContext')
        ->once()
        ->andReturn(['similar_queries' => []]);

    AI::shouldReceive('chat')
        ->once()
        ->andReturn('Generated query: MATCH (n) RETURN n');

    // Test code using all three methods
    $service = new WorkflowService();
    $result = $service->processWorkflow($customer);

    $this->assertNotEmpty($result);
}
```

### Partial Mocking

```php
public function test_with_partial_mock()
{
    // Mock only embed(), let others work normally
    AI::shouldReceive('embed')
        ->andReturn([0.1, 0.2, 0.3]);

    AI::makePartial(); // Other methods work normally

    $result = AI::embed('test text');
    $this->assertCount(3, $result);
}
```

---

## Testing with AiManager Dependency Injection

Inject a mock AiManager for better testability and control.

### Basic AiManager Mocking

```php
use Condoedge\Ai\Services\AiManager;
use Mockery;

class CustomerControllerTest extends TestCase
{
    public function test_store_customer()
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
                'relationships_created' => 1,
                'errors' => []
            ]);

        // Bind mock to container
        $this->app->instance(AiManager::class, $mockAi);

        // Make request
        $response = $this->postJson('/api/customers', [
            'name' => 'Test Customer',
            'email' => 'test@example.com'
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'ai_status' => [
                'graph_stored' => true,
                'vector_stored' => true
            ]
        ]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

### Service Class Testing with AiManager

```php
class QueryGenerationServiceTest extends TestCase
{
    public function test_generate_query()
    {
        // Create mock
        $mockAi = Mockery::mock(AiManager::class);

        // Mock retrieveContext
        $mockAi->shouldReceive('retrieveContext')
            ->once()
            ->with('Show all customers')
            ->andReturn([
                'similar_queries' => [
                    ['query' => 'MATCH (c:Customer) RETURN c', 'score' => 0.9]
                ],
                'graph_schema' => ['labels' => ['Customer']]
            ]);

        // Mock chatJson
        $mockAi->shouldReceive('chatJson')
            ->once()
            ->andReturn((object)[
                'query' => 'MATCH (c:Customer) RETURN c',
                'explanation' => 'Returns all customers'
            ]);

        // Inject mock into service
        $service = new QueryGenerationService($mockAi);

        // Test
        $query = $service->generateQuery('Show all customers');

        $this->assertEquals('MATCH (c:Customer) RETURN c', $query);
    }
}
```

### Constructor Injection in Tests

```php
class CustomerServiceTest extends TestCase
{
    private CustomerService $service;
    private AiManager $mockAi;

    public function setUp(): void
    {
        parent::setUp();

        // Create mock
        $this->mockAi = Mockery::mock(AiManager::class);

        // Inject into service
        $this->service = new CustomerService($this->mockAi);
    }

    public function test_process_customer()
    {
        $this->mockAi->shouldReceive('ingest')
            ->once()
            ->andReturn(['graph_stored' => true]);

        $customer = Customer::factory()->create();
        $result = $this->service->processCustomer($customer);

        $this->assertTrue($result['ingestion']['graph_stored']);
    }

    public function test_analyze_customer()
    {
        $this->mockAi->shouldReceive('chat')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn('Customer analysis complete');

        $customer = Customer::factory()->create();
        $analysis = $this->service->analyzeCustomer($customer);

        $this->assertStringContainsString('complete', $analysis);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

---

## Unit Testing with Mocks

### Testing Data Ingestion

```php
use Condoedge\Ai\Services\DataIngestionService;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Mockery;
use Tests\TestCase;

class DataIngestionServiceTest extends TestCase
{
    public function test_successful_ingestion()
    {
        // Create mocks
        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $graphStore = Mockery::mock(GraphStoreInterface::class);
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);

        // Set expectations
        $embedding->shouldReceive('embed')
            ->once()
            ->with('Customer Name Description')
            ->andReturn([0.1, 0.2, 0.3]);

        $vectorStore->shouldReceive('upsert')
            ->once()
            ->andReturn(true);

        $graphStore->shouldReceive('createNode')
            ->once()
            ->with('Customer', Mockery::any())
            ->andReturn('node-123');

        // Create service with mocks
        $service = new DataIngestionService($vectorStore, $graphStore, $embedding);

        // Create test entity
        $customer = new TestCustomer([
            'id' => 1,
            'name' => 'Customer Name',
            'description' => 'Description'
        ]);

        // Execute
        $status = $service->ingest($customer);

        // Assert
        $this->assertTrue($status['graph_stored']);
        $this->assertTrue($status['vector_stored']);
        $this->assertEmpty($status['errors']);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

---

### Testing Context Retrieval

```php
use Condoedge\Ai\Services\ContextRetriever;

class ContextRetrieverTest extends TestCase
{
    public function test_retrieve_context_success()
    {
        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $graphStore = Mockery::mock(GraphStoreInterface::class);
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);

        // Mock embedding generation
        $embedding->shouldReceive('embed')
            ->once()
            ->andReturn([0.1, 0.2]);

        // Mock vector search
        $vectorStore->shouldReceive('search')
            ->once()
            ->andReturn([
                [
                    'payload' => [
                        'question' => 'Similar question',
                        'cypher_query' => 'MATCH (n) RETURN n'
                    ],
                    'score' => 0.89
                ]
            ]);

        // Mock schema retrieval
        $graphStore->shouldReceive('getSchema')
            ->once()
            ->andReturn([
                'labels' => ['Team', 'Person'],
                'relationshipTypes' => ['MEMBER_OF']
            ]);

        $retriever = new ContextRetriever($vectorStore, $graphStore, $embedding);
        $context = $retriever->retrieveContext('Show all teams');

        $this->assertArrayHasKey('similar_queries', $context);
        $this->assertArrayHasKey('graph_schema', $context);
        $this->assertEmpty($context['errors']);
    }
}
```

---

## Integration Testing

### Testing with Real Neo4j

```php
use Condoedge\Ai\GraphStore\Neo4jStore;

class Neo4jIntegrationTest extends TestCase
{
    private Neo4jStore $neo4j;

    public function setUp(): void
    {
        parent::setUp();

        $this->neo4j = new Neo4jStore([
            'uri' => 'bolt://localhost:7687',
            'username' => 'neo4j',
            'password' => 'test-password',
            'database' => 'neo4j'
        ]);
    }

    public function test_create_and_retrieve_node()
    {
        // Create node
        $nodeId = $this->neo4j->createNode('TestNode', [
            'name' => 'Test Entity',
            'value' => 42
        ]);

        $this->assertIsString($nodeId);

        // Query to verify
        $results = $this->neo4j->query(
            'MATCH (n:TestNode {id: $id}) RETURN n',
            ['id' => $nodeId]
        );

        $this->assertCount(1, $results);
        $this->assertEquals('Test Entity', $results[0]['n']['name']);

        // Cleanup
        $this->neo4j->deleteNode('TestNode', $nodeId);
    }
}
```

---

### Testing with Real Qdrant

```php
use Condoedge\Ai\VectorStore\QdrantStore;

class QdrantIntegrationTest extends TestCase
{
    private QdrantStore $qdrant;
    private string $testCollection = 'test_collection';

    public function setUp(): void
    {
        parent::setUp();

        $this->qdrant = new QdrantStore([
            'host' => 'localhost',
            'port' => 6333,
            'api_key' => null
        ]);

        // Create test collection
        $this->qdrant->createCollection($this->testCollection, 3);
    }

    public function test_upsert_and_search()
    {
        // Upsert point
        $result = $this->qdrant->upsert($this->testCollection, [
            [
                'id' => 1,
                'vector' => [0.1, 0.2, 0.3],
                'payload' => ['name' => 'Test Item']
            ]
        ]);

        $this->assertTrue($result);

        // Search
        $results = $this->qdrant->search(
            $this->testCollection,
            [0.1, 0.2, 0.3],
            1
        );

        $this->assertCount(1, $results);
        $this->assertEquals('Test Item', $results[0]['payload']['name']);
    }

    public function tearDown(): void
    {
        // Cleanup
        $this->qdrant->deleteCollection($this->testCollection);
        parent::tearDown();
    }
}
```

---

## Feature Testing (Laravel)

### Testing API Endpoints

```php
class AiApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_endpoint()
    {
        $customer = Customer::factory()->create();

        $response = $this->postJson('/api/ai/ingest', [
            'entity_type' => 'Customer',
            'entity_id' => $customer->id
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'graph_stored' => true,
            'vector_stored' => true
        ]);
    }

    public function test_search_endpoint()
    {
        $response = $this->postJson('/api/ai/search', [
            'question' => 'Show all customers'
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            '*' => ['question', 'score', 'metadata']
        ]);
    }
}
```

---

## Testing Best Practices

### 1. Choose the Right Approach

```php
// Simple tests - Use Facade mocking
AI::shouldReceive('ingest')->andReturn(['graph_stored' => true]);

// Complex services - Use AiManager injection
$mockAi = Mockery::mock(AiManager::class);
$service = new ComplexService($mockAi);

// Testing core services - Mock individual dependencies
$mockVector = Mockery::mock(VectorStoreInterface::class);
$service = new DataIngestionService($mockVector, $mockGraph, $mockEmbed);
```

### 2. Always Clean Up Mocks

```php
public function tearDown(): void
{
    Mockery::close(); // Essential for Mockery
    parent::tearDown();
}
```

### 3. Use Type Hints in Expectations

```php
// Good - matches any Customer instance
AI::shouldReceive('ingest')
    ->with(Mockery::type(Customer::class))
    ->andReturn([...]);

// Bad - matches exact object instance only
AI::shouldReceive('ingest')
    ->with($customer) // Too specific
    ->andReturn([...]);
```

### 4. Test Both Success and Failure

```php
public function test_ingestion_failure()
{
    AI::shouldReceive('ingest')
        ->andReturn([
            'graph_stored' => false,
            'vector_stored' => true,
            'errors' => ['Graph: Connection timeout']
        ]);

    $service = new CustomerService();
    $result = $service->processCustomer($customer);

    // Assert error handling works
    $this->assertFalse($result['success']);
    $this->assertNotEmpty($result['errors']);
}
```

---

## Mocking External APIs

### Mocking OpenAI

```php
use Illuminate\Support\Facades\Http;

class OpenAiMockTest extends TestCase
{
    public function test_chat_with_mocked_openai()
    {
        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Mocked response'
                        ]
                    ]
                ]
            ], 200)
        ]);

        $response = AI::chat('Test question');

        $this->assertEquals('Mocked response', $response);
    }
}
```

---

## Test Database Setup

### Use Separate Test Database

```env
# .env.testing
NEO4J_URI=bolt://localhost:7687
NEO4J_DATABASE=neo4j_test

QDRANT_HOST=localhost
QDRANT_PORT=6333
```

---

## Best Practices

### 1. Use Database Transactions

```php
use Illuminate\Foundation\Testing\DatabaseTransactions;

class CustomerTest extends TestCase
{
    use DatabaseTransactions;

    // Tests automatically rolled back
}
```

### 2. Clean Up Resources

```php
public function tearDown(): void
{
    // Clean Neo4j
    $this->neo4j->query('MATCH (n:TestNode) DELETE n');

    // Clean Qdrant
    $this->qdrant->deleteCollection('test_collection');

    parent::tearDown();
}
```

### 3. Use Factories

```php
Customer::factory()->create([
    'name' => 'Test Customer',
    'email' => 'test@example.com'
]);
```

### 4. Test Error Cases

```php
public function test_ingest_with_invalid_entity()
{
    $this->expectException(\InvalidArgumentException::class);

    AI::ingest(new \stdClass());
}
```

---

## Running Tests

```bash
# All tests
composer test

# Unit tests only
composer test-unit

# Integration tests only
composer test-integration

# Specific test
vendor/bin/phpunit tests/Unit/DataIngestionServiceTest.php

# With coverage
composer test-coverage
```

---

## Continuous Integration

### GitHub Actions Example

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      neo4j:
        image: neo4j:latest
        ports:
          - 7687:7687
        env:
          NEO4J_AUTH: neo4j/test-password

      qdrant:
        image: qdrant/qdrant:latest
        ports:
          - 6333:6333

    steps:
      - uses: actions/checkout@v2
      - uses: php-actions/composer@v6
      - run: composer test
```

---

See also: [Advanced Usage](/docs/{{version}}/advanced-usage) | [Laravel Integration](/docs/{{version}}/laravel-integration)
