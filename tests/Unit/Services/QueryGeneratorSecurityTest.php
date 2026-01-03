<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Mockery;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\GraphStore\CypherSanitizer;
use Condoedge\Ai\Exceptions\CypherInjectionException;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;

/**
 * Security tests for QueryGenerator
 *
 * Tests protection against Cypher injection via template pattern extraction.
 * Template-matched queries bypass LLM and RAG context, so user input
 * extracted into queries must be validated and sanitized.
 */
class QueryGeneratorSecurityTest extends TestCase
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
    // CypherSanitizer::escapeLabel Tests
    // =========================================================================

    /**
     * @test
     */
    public function it_sanitizes_labels_with_backtick_defense(): void
    {
        // Labels are validated and wrapped in backticks for defense-in-depth
        $this->assertEquals('`Customer`', CypherSanitizer::escapeLabel('Customer'));
        $this->assertEquals('`Customer123`', CypherSanitizer::escapeLabel('Customer123'));
        $this->assertEquals('`My_Label`', CypherSanitizer::escapeLabel('My_Label'));
    }

    /**
     * @test
     */
    public function it_rejects_injection_attempts_in_labels(): void
    {
        // Cypher injection attempts throw exceptions (strict validation, fail-fast)
        // Structural characters like })-[:]->(  are NOT allowed

        $this->expectException(CypherInjectionException::class);
        CypherSanitizer::escapeLabel('Customer})-[:HACKED]->(');
    }

    /**
     * @test
     */
    public function it_rejects_labels_with_special_characters(): void
    {
        $this->expectException(CypherInjectionException::class);
        CypherSanitizer::escapeLabel('Customer` DETACH DELETE n //');
    }

    /**
     * @test
     */
    public function it_rejects_labels_with_semicolons(): void
    {
        $this->expectException(CypherInjectionException::class);
        CypherSanitizer::escapeLabel('Node; DROP DATABASE');
    }

    /**
     * @test
     */
    public function it_rejects_labels_starting_with_numbers(): void
    {
        // Labels must start with a letter or underscore - strict validation throws
        $this->expectException(CypherInjectionException::class);
        CypherSanitizer::escapeLabel('123Customer');
    }

    /**
     * @test
     */
    public function it_rejects_empty_labels(): void
    {
        // Empty labels throw exception - strict validation, fail-fast
        $this->expectException(CypherInjectionException::class);
        CypherSanitizer::escapeLabel('');
    }

    /**
     * @test
     */
    public function it_rejects_labels_with_only_special_characters(): void
    {
        // Labels with only special characters throw exception - fail-fast validation
        $this->expectException(CypherInjectionException::class);
        CypherSanitizer::escapeLabel('{}[]()-;:');
    }

    // =========================================================================
    // Label Validation Tests
    // =========================================================================

    /**
     * @test
     */
    public function it_validates_labels_against_schema(): void
    {
        $this->assertTrue($this->generator->isValidLabel('Customer', ['Customer', 'Order', 'Product']));
        $this->assertTrue($this->generator->isValidLabel('Order', ['Customer', 'Order', 'Product']));
        $this->assertFalse($this->generator->isValidLabel('Hacked', ['Customer', 'Order', 'Product']));
        $this->assertFalse($this->generator->isValidLabel('customer', ['Customer', 'Order', 'Product'])); // Case sensitive
    }

    /**
     * @test
     */
    public function it_returns_false_for_empty_schema(): void
    {
        $this->assertFalse($this->generator->isValidLabel('Customer', []));
    }

    // =========================================================================
    // Template Query Security Tests
    // =========================================================================

    /**
     * @test
     */
    public function it_sanitizes_labels_extracted_from_user_input(): void
    {
        // Attempt injection via "show all X" pattern
        $question = "show all Customer})-[:HACKED]->(x";
        $context = [
            'graph_schema' => [
                'labels' => ['Customer', 'Order'],
                'relationships' => [],
            ],
        ];

        $result = $this->generator->generate($question, $context);

        // The query should be safe - no injection characters
        $this->assertStringNotContainsString('})', $result['cypher']);
        $this->assertStringNotContainsString('HACKED', $result['cypher']);
        $this->assertStringNotContainsString('->(', $result['cypher']);

        // Should still be a valid query structure
        $this->assertStringContainsString('MATCH', $result['cypher']);
        $this->assertStringContainsString('Customer', $result['cypher']);
    }

    /**
     * @test
     */
    public function it_rejects_unknown_labels_in_template_queries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown label');

        $question = "show all Hackers";
        $context = [
            'graph_schema' => [
                'labels' => ['Customer', 'Order'],
                'relationships' => [],
            ],
        ];

        $this->generator->generate($question, $context);
    }

    /**
     * @test
     */
    public function it_falls_through_to_llm_when_schema_is_empty(): void
    {
        $question = "show all customers";
        $context = [
            'graph_schema' => [
                'labels' => [], // Empty schema
                'relationships' => [],
            ],
        ];

        // Since schema is empty, should fall through to LLM
        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->andReturn('MATCH (n:Customer) RETURN n LIMIT 100');

        $result = $this->generator->generate($question, $context, ['explain' => false]);

        // Should use LLM, not template
        $this->assertNull($result['metadata']['template_used']);
    }

    /**
     * @test
     */
    public function it_falls_through_to_llm_when_schema_is_missing(): void
    {
        $question = "show all customers";
        $context = []; // No schema at all

        // Since schema is missing, should fall through to LLM
        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->andReturn('MATCH (n:Customer) RETURN n LIMIT 100');

        $result = $this->generator->generate($question, $context, ['explain' => false]);

        // Should use LLM, not template
        $this->assertNull($result['metadata']['template_used']);
    }

    /**
     * @test
     */
    public function it_prevents_cypher_injection_via_count_template(): void
    {
        $question = "how many Customer})-[r]->(n) DETACH DELETE n //";
        $context = [
            'graph_schema' => [
                'labels' => ['Customer', 'Order'],
                'relationships' => [],
            ],
        ];

        $result = $this->generator->generate($question, $context);

        // The query should be safe
        $this->assertStringNotContainsString('DETACH', $result['cypher']);
        $this->assertStringNotContainsString('DELETE', $result['cypher']);
        $this->assertStringNotContainsString('})', $result['cypher']);
    }

    /**
     * @test
     */
    public function it_handles_case_insensitive_label_matching(): void
    {
        // User types "customers" (lowercase, plural), schema has "Customer"
        $question = "show all customers";
        $context = [
            'graph_schema' => [
                'labels' => ['Customer', 'Order'],
                'relationships' => [],
            ],
        ];

        $result = $this->generator->generate($question, $context);

        // Should match Customer (singularized, capitalized)
        $this->assertStringContainsString('Customer', $result['cypher']);
        $this->assertEquals('list_all', $result['metadata']['template_used']);
    }

    /**
     * @test
     */
    public function it_applies_defense_in_depth_sanitization(): void
    {
        // Even if a label somehow passes validation, it should still be sanitized
        // This tests that escapeLabel is called even after schema validation
        $question = "show all Customer";
        $context = [
            'graph_schema' => [
                'labels' => ['Customer'],
                'relationships' => [],
            ],
        ];

        $result = $this->generator->generate($question, $context);

        // Query should be clean and valid - labels are wrapped in backticks for defense-in-depth
        $this->assertMatchesRegularExpression('/MATCH \(n:`?[A-Za-z_][A-Za-z0-9_]*`?\)/', $result['cypher']);
    }
}
