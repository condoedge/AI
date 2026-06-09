<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * ResponseGenerator Service Unit Tests
 *
 * Tests the Response Generator service that transforms query results
 * into natural language explanations.
 */
class ResponseGeneratorTest extends TestCase
{
    private ResponseGenerator $generator;
    private LlmProviderInterface $llmMock;
    private array $config;

    public function setUp(): void
    {
        parent::setUp();

        $this->llmMock = Mockery::mock(LlmProviderInterface::class);

        $this->config = [
            'default_format' => 'text',
            'default_style' => 'detailed',
            'default_max_length' => 200,
            'temperature' => 0.3,
            'include_insights' => true,
            'include_visualizations' => true,
            'summarize_threshold' => 10,
        ];

        $this->generator = new ResponseGenerator($this->llmMock, $this->config);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Build the single-path PipelineContext the generator now consumes.
     *
     * The legacy ($question, $queryResult, $cypher) arguments are mapped onto
     * the DTO: question -> ->question, the result rows -> executionResult->data,
     * and the cypher string -> queryResult->cypher.
     */
    private function makeContext(string $question, array $data, ?string $cypher): PipelineContext
    {
        $context = new PipelineContext();
        $context->question = $question;
        $context->queryResult = new QueryGenerationResult(cypher: $cypher);
        $context->executionResult = new QueryExecutionResult(success: true, data: $data);

        return $context;
    }

    /** @test */
    public function empty_response_carries_the_identity_system_prompt_not_null(): void
    {
        // Regression guard: the abstention/empty path must NOT call the LLM with a
        // null system prompt — that bypasses IdentitySection/non-disclosure and lets
        // a "which model are you?" question self-identify the provider.
        $capturedSystem = 'NEVER_CALLED';
        $capturedUser = '';
        $this->llmMock->shouldReceive('complete')->once()
            ->andReturnUsing(function ($user, $system, $opts) use (&$capturedSystem, &$capturedUser) {
                $capturedSystem = $system;
                $capturedUser = $user;
                return 'I could not find anything matching that.';
            });

        $this->generator->generateEmptyResponse('Which AI model are you?', 'MATCH (n:Invoice) RETURN n', []);

        $this->assertNotNull($capturedSystem, 'empty path sent a null system prompt — identity/non-disclosure bypassed');
        $this->assertNotSame('', $capturedSystem);
        $this->assertStringContainsStringIgnoringCase('never reveal', $capturedSystem);
        // The executed query/schema must not be leaked into the prompt on the empty path.
        $this->assertStringNotContainsString('MATCH (n:Invoice)', $capturedUser);
    }

    /** @test */
    public function it_generates_response_from_query_results(): void
    {
        $question = "How many customers do we have?";
        $data = [
            ['count' => 42]
        ];
        $cypher = "MATCH (c:Customer) RETURN count(c) as count";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("You have 42 customers in total.");

        $result = $this->generator->generate($this->makeContext($question, $data, $cypher));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('answer', $result);
        $this->assertArrayHasKey('insights', $result);
        $this->assertArrayHasKey('visualizations', $result);
        $this->assertArrayHasKey('format', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertEquals("You have 42 customers in total.", $result['answer']);
    }

    /** @test */
    public function it_generates_response_in_markdown_format(): void
    {
        $question = "Show top customers";
        $data = [
            ['name' => 'Alice', 'orders' => 10],
            ['name' => 'Bob', 'orders' => 8]
        ];
        $cypher = "MATCH (c:Customer) RETURN c.name, c.orders ORDER BY c.orders DESC LIMIT 2";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("## Top Customers\n\n- Alice: 10 orders\n- Bob: 8 orders");

        $result = $this->generator->generate($this->makeContext($question, $data, $cypher), [
            'format' => 'markdown'
        ]);

        $this->assertEquals('markdown', $result['format']);
        $this->assertStringContainsString('##', $result['answer']);
    }

    /** @test */
    public function it_generates_response_in_concise_style(): void
    {
        $question = "How many orders?";
        $data = [['count' => 100]];
        $cypher = "MATCH (o:Order) RETURN count(o)";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("100 orders.");

        $result = $this->generator->generate($this->makeContext($question, $data, $cypher), [
            'style' => 'concise'
        ]);

        $this->assertEquals('concise', $result['metadata']['style']);
        $this->assertLessThan(50, strlen($result['answer']));
    }

    /** @test */
    public function it_summarizes_large_result_sets(): void
    {
        $question = "List all customers";

        // Create 15 rows of data
        $data = [];
        for ($i = 1; $i <= 15; $i++) {
            $data[] = ['id' => $i, 'name' => "Customer $i"];
        }

        $cypher = "MATCH (c:Customer) RETURN c.id, c.name";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("Here are the first 10 customers out of 15 total...");

        $result = $this->generator->generate($this->makeContext($question, $data, $cypher));

        $this->assertTrue($result['metadata']['summarized']);
        $this->assertEquals(15, $result['metadata']['result_count']);
    }

    /** @test */
    public function it_generates_empty_response_when_no_results(): void
    {
        // No-results handling now lives in the dedicated generateEmptyResponse()
        // path (the orchestrator routes empty executions there), so the
        // empty_result contract is asserted through that method.
        $question = "Find customers named Zzzz";
        $cypher = "MATCH (c:Customer {name: 'Zzzz'}) RETURN c";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("No customers found with that name. Try a different search term.");

        $result = $this->generator->generateEmptyResponse($question, $cypher);

        $this->assertTrue($result['metadata']['empty_result']);
        $this->assertEquals(0, $result['metadata']['result_count']);
        $this->assertStringContainsString('No', $result['answer']);
    }

    /** @test */
    public function it_generates_empty_response_directly(): void
    {
        $question = "Find non-existent data";
        $cypher = "MATCH (n:NonExistent) RETURN n";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("No results were found. The data you're looking for might not exist.");

        $result = $this->generator->generateEmptyResponse($question, $cypher);

        $this->assertTrue($result['metadata']['empty_result']);
        $this->assertEquals(0, $result['metadata']['result_count']);
        $this->assertEquals('text', $result['format']);
    }

    /** @test */
    public function it_handles_llm_failure_in_empty_response(): void
    {
        $question = "Find something";
        $cypher = "MATCH (n) RETURN n";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andThrow(new \RuntimeException("LLM API failed"));

        $result = $this->generator->generateEmptyResponse($question, $cypher);

        $this->assertTrue($result['metadata']['empty_result']);
        $this->assertTrue($result['metadata']['fallback']);
        $this->assertStringContainsString('No results', $result['answer']);
    }

    /** @test */
    public function it_generates_error_response(): void
    {
        $question = "Complex query";
        $error = new \RuntimeException("Query timeout exceeded");

        $result = $this->generator->generateErrorResponse($question, $error);

        $this->assertTrue($result['metadata']['error']);
        $this->assertEquals('RuntimeException', $result['metadata']['error_type']);
        $this->assertStringContainsString('issue', $result['answer']);
        // The implementation checks for 'timeout' in error message and adds 'too long' guidance
        $this->assertStringContainsString('too long', $result['answer']);
    }

    /** @test */
    public function it_generates_error_response_with_syntax_guidance(): void
    {
        $question = "Bad query";
        $error = new \RuntimeException("syntax error in query");

        $result = $this->generator->generateErrorResponse($question, $error);

        // The implementation checks for 'syntax' (lowercase) in error message
        $this->assertStringContainsString('issue with the generated query', $result['answer']);
        $this->assertStringContainsString('rephrasing', $result['answer']);
    }

    /** @test */
    public function it_includes_error_details_when_requested(): void
    {
        $question = "Test query";
        $error = new \RuntimeException("Detailed error message");

        $result = $this->generator->generateErrorResponse($question, $error, [
            'include_details' => true
        ]);

        $this->assertArrayHasKey('error_message', $result['metadata']);
        $this->assertEquals("Detailed error message", $result['metadata']['error_message']);
    }

    /** @test */
    public function it_extracts_basic_insights(): void
    {
        $data = [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
            ['name' => 'Charlie']
        ];

        $insights = $this->generator->extractInsights($data);

        $this->assertIsArray($insights);
        $this->assertNotEmpty($insights);
        $this->assertStringContainsString('3', $insights[0]);
        $this->assertStringContainsString('result', $insights[0]);
    }

    /** @test */
    public function it_extracts_numeric_insights(): void
    {
        $data = [
            ['value' => 10],
            ['value' => 20],
            ['value' => 30],
            ['value' => 40],
            ['value' => 100] // Outlier
        ];

        $insights = $this->generator->extractInsights($data);

        $this->assertIsArray($insights);
        $this->assertGreaterThan(1, count($insights));

        // Should mention count
        $this->assertStringContainsString('5', $insights[0]);

        // Should mention average
        $foundAverage = false;
        foreach ($insights as $insight) {
            if (stripos($insight, 'average') !== false) {
                $foundAverage = true;
                break;
            }
        }
        $this->assertTrue($foundAverage);
    }

    /** @test */
    public function it_detects_high_value_outliers(): void
    {
        $data = [
            ['amount' => 100],
            ['amount' => 150],
            ['amount' => 120],
            ['amount' => 1000] // High outlier (> 2x average)
        ];

        $insights = $this->generator->extractInsights($data);

        $foundHighValues = false;
        foreach ($insights as $insight) {
            if (stripos($insight, 'high') !== false) {
                $foundHighValues = true;
                break;
            }
        }
        $this->assertTrue($foundHighValues);
    }

    /** @test */
    public function it_does_not_leak_raw_column_names_into_insights(): void
    {
        // L9-3: insights are user-facing prose and must not expose the underlying
        // schema. Raw DB column/property names (id, customer_email, ...) must never
        // appear in the insights payload.
        $data = [
            ['id' => 1, 'customer_email' => 'alice@example.com', 'internal_flag' => 30]
        ];

        $insights = $this->generator->extractInsights($data);

        $joined = implode(' | ', $insights);
        $this->assertStringNotContainsString('customer_email', $joined);
        $this->assertStringNotContainsString('internal_flag', $joined);
    }

    /** @test */
    public function it_suggests_number_visualization_for_count_queries(): void
    {
        $data = [['count' => 42]];
        $cypher = "MATCH (n:Customer) RETURN count(n) as count";

        $suggestions = $this->generator->suggestVisualizations($data, $cypher);

        $this->assertNotEmpty($suggestions);
        $this->assertEquals('number', $suggestions[0]['type']);
        $this->assertArrayHasKey('rationale', $suggestions[0]);
    }

    /** @test */
    public function it_suggests_graph_visualization_for_relationship_queries(): void
    {
        $data = [
            ['customer' => 'Alice', 'order' => 'Order1'],
            ['customer' => 'Bob', 'order' => 'Order2']
        ];
        $cypher = "MATCH (c:Customer)-[:PLACED]->(o:Order) RETURN c.name, o.id";

        $suggestions = $this->generator->suggestVisualizations($data, $cypher);

        $foundGraph = false;
        foreach ($suggestions as $suggestion) {
            if ($suggestion['type'] === 'graph') {
                $foundGraph = true;
                break;
            }
        }
        $this->assertTrue($foundGraph);
    }

    /** @test */
    public function it_suggests_table_visualization_for_multi_column_data(): void
    {
        $data = [];
        for ($i = 1; $i <= 5; $i++) {
            $data[] = [
                'id' => $i,
                'name' => "Customer $i",
                'email' => "customer$i@example.com",
                'orders' => rand(1, 20)
            ];
        }
        $cypher = "MATCH (c:Customer) RETURN c.id, c.name, c.email, c.orders";

        $suggestions = $this->generator->suggestVisualizations($data, $cypher);

        $foundTable = false;
        foreach ($suggestions as $suggestion) {
            if ($suggestion['type'] === 'table') {
                $foundTable = true;
                break;
            }
        }
        $this->assertTrue($foundTable);
    }

    /** @test */
    public function it_suggests_bar_chart_for_aggregation_queries(): void
    {
        $data = [
            ['category' => 'Electronics', 'count' => 42],
            ['category' => 'Books', 'count' => 38],
            ['category' => 'Clothing', 'count' => 25]
        ];
        $cypher = "MATCH (p:Product) RETURN p.category, count(p) as count";

        $suggestions = $this->generator->suggestVisualizations($data, $cypher);

        $foundBarChart = false;
        foreach ($suggestions as $suggestion) {
            if ($suggestion['type'] === 'bar-chart') {
                $foundBarChart = true;
                break;
            }
        }
        $this->assertTrue($foundBarChart);
    }

    /** @test */
    public function it_suggests_line_chart_for_time_series_data(): void
    {
        $data = [
            ['date' => '2024-01-01', 'count' => 10],
            ['date' => '2024-01-02', 'count' => 15],
            ['date' => '2024-01-03', 'count' => 12]
        ];
        $cypher = "MATCH (o:Order) RETURN o.date, count(o) ORDER BY o.date";

        $suggestions = $this->generator->suggestVisualizations($data, $cypher);

        $foundLineChart = false;
        foreach ($suggestions as $suggestion) {
            if ($suggestion['type'] === 'line-chart') {
                $foundLineChart = true;
                break;
            }
        }
        $this->assertTrue($foundLineChart);
    }

    /** @test */
    public function it_detects_time_component_in_various_formats(): void
    {
        $timeFields = [
            ['created_at' => '2024-01-01'],
            ['updated_at' => '2024-01-01'],
            ['timestamp' => 1234567890],
            ['datetime' => '2024-01-01 12:00:00'],
            ['time' => '12:00:00']
        ];

        foreach ($timeFields as $data) {
            $suggestions = $this->generator->suggestVisualizations([$data], "MATCH (n) RETURN n");

            $foundLineChart = false;
            foreach ($suggestions as $suggestion) {
                if ($suggestion['type'] === 'line-chart') {
                    $foundLineChart = true;
                    break;
                }
            }
            $this->assertTrue($foundLineChart, "Failed for: " . json_encode($data));
        }
    }

    /** @test */
    public function it_provides_default_table_suggestion_when_uncertain(): void
    {
        $data = [['unknown_field' => 'value']];
        $cypher = "MATCH (n) RETURN n.unknown_field";

        $suggestions = $this->generator->suggestVisualizations($data, $cypher);

        $this->assertNotEmpty($suggestions);
        $foundTable = false;
        foreach ($suggestions as $suggestion) {
            if ($suggestion['type'] === 'table') {
                $foundTable = true;
                break;
            }
        }
        $this->assertTrue($foundTable);
    }

    /** @test */
    public function it_returns_empty_suggestions_for_empty_data(): void
    {
        $suggestions = $this->generator->suggestVisualizations([], "MATCH (n) RETURN n");

        $this->assertEmpty($suggestions);
    }

    /** @test */
    public function it_throws_exception_when_response_generation_fails(): void
    {
        $question = "Test question";
        $data = [['id' => 1]];
        $cypher = "MATCH (n) RETURN n";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andThrow(new \RuntimeException("API Error"));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Response generation failed");

        $this->generator->generate($this->makeContext($question, $data, $cypher));
    }

    /** @test */
    public function it_disables_insights_when_requested(): void
    {
        $question = "Test";
        $data = [['count' => 5]];
        $cypher = "MATCH (n) RETURN count(n)";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("5 results found.");

        $result = $this->generator->generate($this->makeContext($question, $data, $cypher), [
            'include_insights' => false
        ]);

        $this->assertEmpty($result['insights']);
    }

    /** @test */
    public function it_disables_visualizations_when_requested(): void
    {
        $question = "Test";
        $data = [['count' => 5]];
        $cypher = "MATCH (n) RETURN count(n) as count";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("5 results found.");

        $result = $this->generator->generate($this->makeContext($question, $data, $cypher), [
            'include_visualization' => false
        ]);

        $this->assertEmpty($result['visualizations']);
    }

    /** @test */
    public function it_respects_custom_temperature(): void
    {
        $question = "Test";
        $data = [['id' => 1]];
        $cypher = "MATCH (n) RETURN n";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->with(
                Mockery::type('string'),
                // Identity/security ride in the real system role, so the second
                // argument is now the system prompt string rather than null.
                Mockery::type('string'),
                Mockery::on(function ($options) {
                    return isset($options['temperature']) && $options['temperature'] === 0.7;
                })
            )
            ->andReturn("Creative response.");

        $result = $this->generator->generate($this->makeContext($question, $data, $cypher), [
            'temperature' => 0.7
        ]);

        $this->assertNotEmpty($result['answer']);
    }

    /** @test */
    public function it_respects_custom_max_length(): void
    {
        $question = "Test";
        $data = [['id' => 1]];
        $cypher = "MATCH (n) RETURN n";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->with(
                Mockery::type('string'),
                // System prompt string is supplied in the real system role.
                Mockery::type('string'),
                Mockery::on(function ($options) {
                    // max_length 100 words ≈ 133 tokens (100 / 0.75)
                    return isset($options['max_tokens']) && $options['max_tokens'] >= 130;
                })
            )
            ->andReturn("Short response.");

        $result = $this->generator->generate($this->makeContext($question, $data, $cypher), [
            'max_length' => 100
        ]);

        $this->assertNotEmpty($result['answer']);
    }

    /** @test */
    public function a_prompt_section_that_fails_to_load_is_skipped_not_fatal(): void
    {
        // A host config may still reference a section class that was removed/renamed
        // (its file deleted from the package). Loading it must skip-and-log, never
        // crash the whole response pipeline. Reproduces the live CondoEdge failure
        // where a stale config referenced the removed QueryInfoSection.
        config()->set('ai.response_generator_sections', [
            ThrowingPromptSection::class,
            \Condoedge\Ai\Services\ResponseSections\GuidelinesSection::class,
        ]);

        $generator = new ResponseGenerator($this->llmMock, $this->config);

        // Construction did not crash despite the unloadable section.
        $this->assertInstanceOf(ResponseGenerator::class, $generator);
    }
}

/**
 * Stands in for a host config that references a section whose class file was
 * deleted (the deleted-file autoload also throws); construction fails hard.
 */
class ThrowingPromptSection
{
    public function __construct()
    {
        throw new \RuntimeException('section unavailable');
    }
}
