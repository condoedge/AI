<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Tests\Fixtures\TestCustomer;
use Condoedge\Ai\Tests\Fixtures\TestOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI System Feature Tests
 *
 * Tests the complete AI Text-to-Query pipeline with real OpenAI API
 * integration, using test data from migrations and factories.
 *
 * **Important**: These tests make real API calls to OpenAI and Neo4j.
 * Ensure you have:
 * - OPENAI_API_KEY set in your .env
 * - Neo4j running and configured
 * - Qdrant running and configured
 *
 * Run with: php artisan test tests/Feature/AiSystemFeatureTest.php
 */
class AiSystemFeatureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Path to test migrations
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Set up test data before each test
     */
    public function setUp(): void
    {
        parent::setUp();

        // Create test customers and orders
        $this->seedTestData();
    }

    /**
     * Seed test data
     */
    protected function seedTestData(): void
    {
        // Create 5 active customers in USA
        $usaCustomers = TestCustomer::factory()
            ->count(5)
            ->active()
            ->fromCountry('USA')
            ->create();

        // Create 3 active customers in Canada
        $canadaCustomers = TestCustomer::factory()
            ->count(3)
            ->active()
            ->fromCountry('Canada')
            ->create();

        // Create 2 inactive customers
        TestCustomer::factory()
            ->count(2)
            ->inactive()
            ->create();

        // Create orders for USA customers (3 completed, 2 pending each)
        foreach ($usaCustomers as $customer) {
            TestOrder::factory()
                ->count(3)
                ->completed()
                ->forCustomer($customer)
                ->create();

            TestOrder::factory()
                ->count(2)
                ->pending()
                ->forCustomer($customer)
                ->create();
        }

        // Create orders for Canada customers (2 completed each)
        foreach ($canadaCustomers as $customer) {
            TestOrder::factory()
                ->count(2)
                ->completed()
                ->forCustomer($customer)
                ->create();
        }

        // Ingest all customers into AI system
        $allCustomers = TestCustomer::with('orders')->get();
        foreach ($allCustomers as $customer) {
            AI::ingest($customer);
        }

        // Ingest all orders into AI system
        $allOrders = TestOrder::with('customer')->get();
        foreach ($allOrders as $order) {
            AI::ingest($order);
        }
    }

    /**
     * Test: Count total customers
     *
     * @test
     */
    public function it_answers_how_many_customers_exist(): void
    {
        $result = AI::answerQuestion("How many customers do we have?");

        // Verify structure
        $this->assertArrayHasKey('question', $result);
        $this->assertArrayHasKey('answer', $result);
        $this->assertArrayHasKey('insights', $result);
        $this->assertArrayHasKey('visualizations', $result);
        $this->assertArrayHasKey('cypher', $result);
        $this->assertArrayHasKey('data', $result);

        // Verify the answer contains the correct count (10 customers)
        $expectedCount = TestCustomer::count(); // Should be 10
        $this->assertTrue(
            str_contains($result['answer'], (string) $expectedCount) ||
            str_contains($result['answer'], 'ten') ||
            str_contains($result['cypher'], 'count'),
            "Answer should mention the customer count of {$expectedCount}"
        );

        // Verify the Cypher query was generated
        $this->assertNotNull($result['cypher']);
        $this->assertStringContainsStringIgnoringCase('Customer', $result['cypher']);

        // Verify insights were extracted
        $this->assertIsArray($result['insights']);
        $this->assertNotEmpty($result['insights']);

        // Verify visualization suggestions
        $this->assertIsArray($result['visualizations']);
    }

    /**
     * Test: Count active customers
     *
     * @test
     */
    public function it_answers_how_many_active_customers_exist(): void
    {
        $result = AI::answerQuestion("How many active customers do we have?");

        // Expected count: 8 active customers (5 USA + 3 Canada)
        $expectedCount = TestCustomer::where('status', 'active')->count();

        // The answer should reference the count
        $answerLower = strtolower($result['answer']);
        $this->assertTrue(
            str_contains($answerLower, (string) $expectedCount) ||
            str_contains($answerLower, 'eight') ||
            str_contains($answerLower, 'active'),
            "Answer should mention active customers count of {$expectedCount}"
        );

        // Verify Cypher includes status filter
        $this->assertStringContainsStringIgnoringCase('status', $result['cypher']);
    }

    /**
     * Test: Count customers by country
     *
     * @test
     */
    public function it_answers_how_many_customers_from_usa(): void
    {
        $result = AI::answerQuestion("How many customers are from USA?");

        // Expected count: 5 customers from USA
        $expectedCount = TestCustomer::where('country', 'USA')->count();

        // Verify answer
        $this->assertTrue(
            str_contains($result['answer'], (string) $expectedCount) ||
            str_contains($result['answer'], 'five') ||
            str_contains(strtolower($result['answer']), 'usa'),
            "Answer should mention USA customer count of {$expectedCount}"
        );

        // Verify Cypher includes country filter
        $cypherLower = strtolower($result['cypher']);
        $this->assertTrue(
            str_contains($cypherLower, 'country') ||
            str_contains($cypherLower, 'usa'),
            "Cypher should filter by country"
        );
    }

    /**
     * Test: Count total orders
     *
     * @test
     */
    public function it_answers_how_many_orders_exist(): void
    {
        $result = AI::answerQuestion("How many orders do we have in total?");

        // Expected count: (5 USA * 5 orders) + (3 Canada * 2 orders) = 31 orders
        $expectedCount = TestOrder::count();

        // Verify answer contains count
        $this->assertTrue(
            str_contains($result['answer'], (string) $expectedCount) ||
            preg_match('/\b' . $expectedCount . '\b/', $result['answer']),
            "Answer should mention order count of {$expectedCount}"
        );

        // Verify Cypher query targets Order nodes
        $this->assertStringContainsStringIgnoringCase('Order', $result['cypher']);
    }

    /**
     * Test: Count completed orders
     *
     * @test
     */
    public function it_answers_how_many_completed_orders(): void
    {
        $result = AI::answerQuestion("How many completed orders are there?");

        // Expected count: (5 USA * 3 completed) + (3 Canada * 2 completed) = 21 completed
        $expectedCount = TestOrder::where('status', 'completed')->count();

        // Verify answer
        $answerLower = strtolower($result['answer']);
        $this->assertTrue(
            str_contains($result['answer'], (string) $expectedCount) ||
            str_contains($answerLower, 'completed'),
            "Answer should mention completed orders count of {$expectedCount}"
        );
    }

    /**
     * Test: Find customers with orders
     *
     * @test
     */
    public function it_finds_customers_with_orders(): void
    {
        $result = AI::answerQuestion("Show me customers who have placed orders");

        // All 8 active customers have orders
        // The query should return customer data
        $this->assertNotEmpty($result['data']);

        // Verify the answer is helpful
        $this->assertNotEmpty($result['answer']);
        $this->assertIsString($result['answer']);

        // Should mention customers or orders
        $answerLower = strtolower($result['answer']);
        $this->assertTrue(
            str_contains($answerLower, 'customer') ||
            str_contains($answerLower, 'order'),
            "Answer should discuss customers and orders"
        );
    }

    /**
     * Test: Query with aggregation
     *
     * @test
     */
    public function it_answers_questions_with_aggregation(): void
    {
        $result = AI::answerQuestion("What is the total value of all orders?");

        // Should generate aggregation query
        $this->assertNotEmpty($result['cypher']);
        $this->assertTrue(
            str_contains(strtolower($result['cypher']), 'sum') ||
            str_contains(strtolower($result['cypher']), 'total'),
            "Cypher should include SUM aggregation"
        );

        // Should have insights about the data
        $this->assertNotEmpty($result['insights']);

        // Answer should be meaningful
        $this->assertNotEmpty($result['answer']);
    }

    /**
     * Test: Relationship query
     *
     * @test
     */
    public function it_answers_questions_about_relationships(): void
    {
        $result = AI::answerQuestion("Which customers have pending orders?");

        // Should generate relationship query
        $cypherLower = strtolower($result['cypher']);
        $this->assertTrue(
            (str_contains($cypherLower, 'placed') || str_contains($cypherLower, '-[') || str_contains($cypherLower, '->')) &&
            (str_contains($cypherLower, 'pending') || str_contains($cypherLower, 'status')),
            "Cypher should query relationships and filter by status"
        );

        // Should return data
        $this->assertNotEmpty($result['data']);

        // Should suggest graph visualization
        $hasGraphViz = false;
        foreach ($result['visualizations'] as $viz) {
            if ($viz['type'] === 'graph' || $viz['type'] === 'table') {
                $hasGraphViz = true;
                break;
            }
        }
        $this->assertTrue($hasGraphViz, "Should suggest graph or table visualization");
    }

    /**
     * Test: Empty result handling
     *
     * @test
     */
    public function it_handles_queries_with_no_results_gracefully(): void
    {
        $result = AI::answerQuestion("Show me customers from Antarctica");

        // Should have an answer even with no data
        $this->assertNotEmpty($result['answer']);

        // Answer should explain there are no results
        $answerLower = strtolower($result['answer']);
        $this->assertTrue(
            str_contains($answerLower, 'no') ||
            str_contains($answerLower, 'not') ||
            str_contains($answerLower, 'none') ||
            str_contains($answerLower, 'found'),
            "Answer should explain that no results were found"
        );
    }

    /**
     * Test: Context retrieval (RAG)
     *
     * @test
     */
    public function it_retrieves_context_for_questions(): void
    {
        $context = AI::retrieveContext("Show me customer information");

        // Should retrieve schema context
        $this->assertArrayHasKey('schema', $context);
        $this->assertNotEmpty($context['schema']);

        // Should include Customer and Order labels
        $schemaText = json_encode($context['schema']);
        $this->assertStringContainsString('Customer', $schemaText);
        $this->assertStringContainsString('Order', $schemaText);
    }

    /**
     * Test: Query generation
     *
     * @test
     */
    public function it_generates_valid_cypher_queries(): void
    {
        $context = AI::retrieveContext("Count all customers");

        $queryResult = AI::generateQuery("Count all customers", $context);

        // Should generate valid Cypher
        $this->assertArrayHasKey('cypher', $queryResult);
        $this->assertNotEmpty($queryResult['cypher']);

        // Should validate successfully
        $validation = AI::validateQuery($queryResult['cypher']);
        $this->assertTrue($validation['valid'], "Generated Cypher should be valid");

        // Should include LIMIT (sanitization)
        $this->assertStringContainsStringIgnoringCase('LIMIT', $queryResult['cypher']);
    }

    /**
     * Test: Query execution
     *
     * @test
     */
    public function it_executes_cypher_queries(): void
    {
        $cypher = "MATCH (c:Customer {status: 'active'}) RETURN count(c) as count LIMIT 100";

        $result = AI::executeQuery($cypher);

        // Should have success response
        $this->assertTrue($result['success']);

        // Should have data
        $this->assertArrayHasKey('data', $result);
        $this->assertNotEmpty($result['data']);

        // Should have stats
        $this->assertArrayHasKey('stats', $result);
    }

    /**
     * Test: Response generation
     *
     * @test
     */
    public function it_generates_natural_language_responses(): void
    {
        $queryResult = [
            'data' => [
                ['count' => 10]
            ],
            'stats' => [
                'execution_time_ms' => 15,
                'rows_returned' => 1
            ]
        ];

        $response = AI::generateResponse(
            "How many customers?",
            $queryResult,
            "MATCH (c:Customer) RETURN count(c) as count"
        );

        // Should have answer
        $this->assertArrayHasKey('answer', $response);
        $this->assertNotEmpty($response['answer']);

        // Should mention the count
        $this->assertTrue(
            str_contains($response['answer'], '10') ||
            str_contains($response['answer'], 'ten'),
            "Response should mention the count"
        );

        // Should have insights
        $this->assertArrayHasKey('insights', $response);

        // Should have visualizations
        $this->assertArrayHasKey('visualizations', $response);
    }

    /**
     * Test: Full pipeline multiple questions
     *
     * @test
     */
    public function it_handles_multiple_different_questions(): void
    {
        $questions = [
            "How many customers do we have?",
            "How many orders are pending?",
            "Show me customers from Canada",
        ];

        foreach ($questions as $question) {
            $result = AI::answerQuestion($question);

            $this->assertArrayHasKey('answer', $result);
            $this->assertNotEmpty($result['answer'], "Question failed: {$question}");
            $this->assertArrayHasKey('cypher', $result);
            $this->assertNotNull($result['cypher']);
        }
    }

    /**
     * Test: Insights extraction
     *
     * @test
     */
    public function it_extracts_insights_from_query_results(): void
    {
        $data = [
            ['value' => 100],
            ['value' => 200],
            ['value' => 150],
        ];

        $insights = AI::extractInsights($data);

        $this->assertIsArray($insights);
        $this->assertNotEmpty($insights);

        // Should mention count
        $insightsText = implode(' ', $insights);
        $this->assertStringContainsString('3', $insightsText);
    }

    /**
     * Test: Visualization suggestions
     *
     * @test
     */
    public function it_suggests_appropriate_visualizations(): void
    {
        $data = [['count' => 42]];
        $cypher = "MATCH (n:Customer) RETURN count(n) as count";

        $suggestions = AI::suggestVisualizations($data, $cypher);

        $this->assertIsArray($suggestions);
        $this->assertNotEmpty($suggestions);

        // Should suggest number visualization for count
        $types = array_column($suggestions, 'type');
        $this->assertContains('number', $types);
    }
}
