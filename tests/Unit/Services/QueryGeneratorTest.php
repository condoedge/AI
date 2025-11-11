<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Mockery;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Exceptions\QueryGenerationException;
use Condoedge\Ai\Exceptions\QueryValidationException;

class QueryGeneratorTest extends TestCase
{
    private QueryGenerator $generator;
    private LlmProviderInterface $mockLlm;
    private GraphStoreInterface $mockGraph;
    private array $config;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);
        $this->mockGraph = Mockery::mock(GraphStoreInterface::class);
        $this->config = [
            'temperature' => 0.1,
            'max_retries' => 3,
            'allow_write_operations' => false,
            'default_limit' => 100,
            'max_complexity' => 100,
            'enable_templates' => true,
            'template_confidence_threshold' => 0.8,
        ];

        $this->generator = new QueryGenerator(
            $this->mockLlm,
            $this->mockGraph,
            $this->config
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // Query Generation Tests
    // =========================================================================

    public function test_generate_simple_query(): void
    {
        $question = "What are the customers?";
        $context = [
            'graph_schema' => [
                'labels' => ['Customer', 'Order'],
                'relationships' => ['PURCHASED'],
            ],
            'similar_queries' => [],
            'relevant_entities' => [],
        ];

        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->with(Mockery::any(), null, Mockery::any())
            ->andReturn('MATCH (n:Customer) RETURN n LIMIT 100');

        // Disable explanation to avoid second complete() call
        $result = $this->generator->generate($question, $context, ['explain' => false]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('cypher', $result);
        $this->assertArrayHasKey('explanation', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertStringContainsString('MATCH', $result['cypher']);
    }

    public function test_generate_uses_template_for_list_all(): void
    {
        $question = "Show all customers";
        $context = [
            'graph_schema' => [
                'labels' => ['Customer'],
                'relationships' => [],
            ],
        ];

        $result = $this->generator->generate($question, $context);

        $this->assertStringContainsString('MATCH', $result['cypher']);
        $this->assertStringContainsString('Customer', $result['cypher']);
        $this->assertStringContainsString('LIMIT', $result['cypher']);
        $this->assertNotNull($result['metadata']['template_used']);
    }

    public function test_generate_uses_template_for_count(): void
    {
        $question = "How many orders";
        $context = [
            'graph_schema' => [
                'labels' => ['Order'],
                'relationships' => [],
            ],
        ];

        $result = $this->generator->generate($question, $context);

        $this->assertStringContainsString('count', strtolower($result['cypher']));
        $this->assertStringContainsString('Order', $result['cypher']);
        $this->assertNotNull($result['metadata']['template_used']);
    }

    public function test_generate_retries_on_validation_failure(): void
    {
        $question = "What are the customers?";
        $context = [
            'graph_schema' => ['labels' => ['Customer'], 'relationships' => []],
        ];

        // First attempt returns invalid query
        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->with(Mockery::any(), null, Mockery::any())
            ->andReturn('INVALID QUERY');

        // Second attempt returns valid query
        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->with(Mockery::any(), null, Mockery::any())
            ->andReturn('MATCH (n:Customer) RETURN n LIMIT 100');

        $result = $this->generator->generate($question, $context, ['explain' => false]);

        $this->assertGreaterThan(0, $result['metadata']['retry_count']);
        $this->assertStringContainsString('MATCH', $result['cypher']);
    }

    public function test_generate_throws_exception_after_max_retries(): void
    {
        $this->expectException(QueryGenerationException::class);

        $question = "What are the customers?";
        $context = [
            'graph_schema' => ['labels' => ['Customer'], 'relationships' => []],
        ];

        // All attempts return invalid queries
        $this->mockLlm->shouldReceive('complete')
            ->times(3)
            ->with(Mockery::any(), null, Mockery::any())
            ->andReturn('INVALID QUERY');

        $this->generator->generate($question, $context, ['explain' => false]);
    }

    public function test_generate_respects_temperature_option(): void
    {
        $question = "What are the customers?";
        $context = [
            'graph_schema' => ['labels' => ['Customer'], 'relationships' => []],
        ];

        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->with(Mockery::any(), null, Mockery::on(function ($options) {
                return isset($options['temperature']) && $options['temperature'] === 0.5;
            }))
            ->andReturn('MATCH (n:Customer) RETURN n LIMIT 100');

        $result = $this->generator->generate($question, $context, ['temperature' => 0.5, 'explain' => false]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('cypher', $result);
    }

    public function test_generate_includes_explanation_when_requested(): void
    {
        $question = "What are the customers?";
        $context = [
            'graph_schema' => ['labels' => ['Customer'], 'relationships' => []],
        ];

        // First call generates the query
        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->with(Mockery::any(), null, Mockery::on(function ($options) {
                return isset($options['max_tokens']) && $options['max_tokens'] === 500;
            }))
            ->andReturn('MATCH (n:Customer) RETURN n LIMIT 100');

        // Second call generates the explanation
        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->with(Mockery::any(), null, Mockery::on(function ($options) {
                return isset($options['max_tokens']) && $options['max_tokens'] === 150;
            }))
            ->andReturn('This query finds all customer nodes.');

        $result = $this->generator->generate($question, $context, ['explain' => true]);

        $this->assertNotEmpty($result['explanation']);
    }

    // =========================================================================
    // Validation Tests
    // =========================================================================

    public function test_validate_accepts_read_only_query(): void
    {
        $query = 'MATCH (n:Customer) RETURN n LIMIT 100';

        $result = $this->generator->validate($query);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertTrue($result['is_read_only']);
    }

    public function test_validate_rejects_delete_without_permission(): void
    {
        $query = 'MATCH (n:Customer) DELETE n';

        $result = $this->generator->validate($query, ['allow_write' => false]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('DELETE', $result['errors'][0]);
    }

    public function test_validate_allows_delete_with_permission(): void
    {
        $query = 'MATCH (n:Customer) DELETE n';

        $result = $this->generator->validate($query, ['allow_write' => true]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertFalse($result['is_read_only']);
    }

    public function test_validate_warns_about_missing_limit(): void
    {
        $query = 'MATCH (n:Customer) RETURN n';

        $result = $this->generator->validate($query);

        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('LIMIT', $result['warnings'][0]);
    }

    public function test_validate_calculates_complexity(): void
    {
        $query = 'MATCH (n:Customer) RETURN n LIMIT 100';

        $result = $this->generator->validate($query);

        $this->assertArrayHasKey('complexity', $result);
        $this->assertGreaterThan(0, $result['complexity']);
    }

    public function test_validate_throws_exception_for_empty_query(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator->validate('');
    }

    public function test_validate_rejects_query_without_match_or_return(): void
    {
        $query = 'SHOW DATABASES';

        $result = $this->generator->validate($query);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_validate_warns_about_high_complexity(): void
    {
        $query = 'MATCH (a)-[r1]->(b)-[r2]->(c)-[r3]->(d) WHERE a.prop = 1 AND b.prop = 2 RETURN a, b, c, d LIMIT 10';

        $result = $this->generator->validate($query, ['max_complexity' => 20]);

        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertGreaterThan(20, $result['complexity']);
    }

    // =========================================================================
    // Sanitization Tests
    // =========================================================================

    public function test_sanitize_removes_delete(): void
    {
        $query = 'MATCH (n:Customer) DELETE n';

        $sanitized = $this->generator->sanitize($query);

        $this->assertStringNotContainsString('DELETE', $sanitized);
    }

    public function test_sanitize_removes_drop(): void
    {
        $query = 'DROP INDEX customer_email';

        $sanitized = $this->generator->sanitize($query);

        $this->assertStringNotContainsString('DROP', $sanitized);
    }

    public function test_sanitize_adds_limit_if_missing(): void
    {
        $query = 'MATCH (n:Customer) RETURN n';

        $sanitized = $this->generator->sanitize($query);

        $this->assertStringContainsString('LIMIT', $sanitized);
        $this->assertStringContainsString('100', $sanitized);
    }

    public function test_sanitize_preserves_existing_limit(): void
    {
        $query = 'MATCH (n:Customer) RETURN n LIMIT 50';

        $sanitized = $this->generator->sanitize($query);

        $this->assertStringContainsString('LIMIT 50', $sanitized);
        $this->assertEquals(1, substr_count(strtoupper($sanitized), 'LIMIT'));
    }

    public function test_sanitize_removes_multiple_dangerous_operations(): void
    {
        $query = 'MATCH (n:Customer) DELETE n; DROP INDEX x; CREATE (m:New)';

        $sanitized = $this->generator->sanitize($query);

        $this->assertStringNotContainsString('DELETE', $sanitized);
        $this->assertStringNotContainsString('DROP', $sanitized);
        $this->assertStringNotContainsString('CREATE', $sanitized);
    }

    // =========================================================================
    // Template Tests
    // =========================================================================

    public function test_get_templates_returns_array(): void
    {
        $templates = $this->generator->getTemplates();

        $this->assertIsArray($templates);
        $this->assertNotEmpty($templates);
    }

    public function test_get_templates_includes_metadata(): void
    {
        $templates = $this->generator->getTemplates();

        foreach ($templates as $template) {
            $this->assertArrayHasKey('name', $template);
            $this->assertArrayHasKey('description', $template);
            $this->assertArrayHasKey('pattern', $template);
        }
    }

    public function test_detect_template_finds_list_all(): void
    {
        $question = "Show all customers";

        $template = $this->generator->detectTemplate($question);

        $this->assertNotNull($template);
        $this->assertEquals('list_all', $template);
    }

    public function test_detect_template_finds_count(): void
    {
        $question = "How many orders";

        $template = $this->generator->detectTemplate($question);

        $this->assertNotNull($template);
        $this->assertEquals('count', $template);
    }

    public function test_detect_template_returns_null_for_no_match(): void
    {
        $question = "This is a very complex question that doesn't match any template";

        $template = $this->generator->detectTemplate($question);

        $this->assertNull($template);
    }

    public function test_detect_template_is_case_insensitive(): void
    {
        $question = "SHOW ALL CUSTOMERS";

        $template = $this->generator->detectTemplate($question);

        $this->assertNotNull($template);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function test_generate_with_empty_context(): void
    {
        $question = "What are the customers?";
        $context = [];

        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->with(Mockery::any(), null, Mockery::any())
            ->andReturn('MATCH (n:Customer) RETURN n LIMIT 100');

        $result = $this->generator->generate($question, $context, ['explain' => false]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('cypher', $result);
    }

    public function test_generate_extracts_cypher_from_markdown(): void
    {
        $question = "What are the customers?";
        $context = [
            'graph_schema' => ['labels' => ['Customer'], 'relationships' => []],
        ];

        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->with(Mockery::any(), null, Mockery::any())
            ->andReturn("```cypher\nMATCH (n:Customer) RETURN n LIMIT 100\n```");

        $result = $this->generator->generate($question, $context, ['explain' => false]);

        $this->assertStringNotContainsString('```', $result['cypher']);
        $this->assertStringContainsString('MATCH', $result['cypher']);
    }

    public function test_validate_with_cypher_comments(): void
    {
        $query = '// Find customers
MATCH (n:Customer)
// Return all
RETURN n LIMIT 100';

        $result = $this->generator->validate($query);

        $this->assertTrue($result['valid']);
    }
}
