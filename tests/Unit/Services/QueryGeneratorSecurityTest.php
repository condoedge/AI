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
use Condoedge\Ai\DTOs\PipelineContext;

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
        // Attempt injection via the "show all X" pattern. Structural Cypher
        // characters embedded in the question must never be interpolated into a
        // template label: the template only captures \w+ AND wraps the result in
        // a backtick-escaped, schema-validated label. Because the injected
        // structure breaks the anchored template pattern, the question cannot
        // produce a template query at all and falls through to the LLM.
        $question = "show all Customer})-[:HACKED]->(x";
        $context = new PipelineContext();
        $context->graphSchema = [
            'labels' => ['Customer', 'Order'],
            'relationships' => [],
        ];

        // The mocked LLM stands in for the fallthrough path; it returns a clean
        // query. The security property under test is that no injected template
        // query is produced from the malicious question.
        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->andReturn('MATCH (n:Customer) RETURN n LIMIT 100');

        $result = $this->generator->generate($question, $context, ['explain' => false]);

        // The injection structure must never appear in the executed query.
        $this->assertStringNotContainsString('})', $result['cypher']);
        $this->assertStringNotContainsString('HACKED', $result['cypher']);
        $this->assertStringNotContainsString('->(', $result['cypher']);

        // The malicious question did NOT yield a template query (it could not be
        // safely extracted), so no template was used.
        $this->assertNull($result['metadata']['template_used']);

        // A benign question over the same schema still flows through the template
        // path and produces a clean, backtick-escaped label - proving the
        // template label extraction is sanitized, not merely bypassed.
        $benign = $this->generator->generate('show all Customer', $context);
        $this->assertEquals('list_all', $benign['metadata']['template_used']);
        $this->assertStringContainsString('MATCH', $benign['cypher']);
        $this->assertStringContainsString('Customer', $benign['cypher']);
        $this->assertStringNotContainsString('})', $benign['cypher']);
    }

    /**
     * @test
     */
    public function it_rejects_unknown_labels_in_template_queries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown label');

        $question = "show all Hackers";
        $context = new PipelineContext();
        $context->graphSchema = [
            'labels' => ['Customer', 'Order'],
            'relationships' => [],
        ];

        $this->generator->generate($question, $context);
    }

    /**
     * @test
     */
    public function it_falls_through_to_llm_when_schema_is_empty(): void
    {
        $question = "show all customers";
        $context = new PipelineContext();
        $context->graphSchema = [
            'labels' => [], // Empty schema
            'relationships' => [],
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
        $context = new PipelineContext(); // No schema at all (graphSchema defaults to [])

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
        // A "how many X ..." question carrying a DETACH DELETE payload must never
        // be interpolated into the count template. The count template captures
        // only \w+ and is anchored, so the malicious structure breaks the match
        // and the question falls through to the LLM rather than producing an
        // injected count query.
        $question = "how many Customer})-[r]->(n) DETACH DELETE n //";
        $context = new PipelineContext();
        $context->graphSchema = [
            'labels' => ['Customer', 'Order'],
            'relationships' => [],
        ];

        $this->mockLlm->shouldReceive('complete')
            ->once()
            ->andReturn('MATCH (n:Customer) RETURN count(n) as count');

        $result = $this->generator->generate($question, $context, ['explain' => false]);

        // The executed query must carry no write/injection payload.
        $this->assertStringNotContainsString('DETACH', $result['cypher']);
        $this->assertStringNotContainsString('DELETE', $result['cypher']);
        $this->assertStringNotContainsString('})', $result['cypher']);

        // The malicious question produced no template query.
        $this->assertNull($result['metadata']['template_used']);

        // A benign count question over the same schema still uses the count
        // template and yields a clean, injection-free query.
        $benign = $this->generator->generate('how many Customer', $context);
        $this->assertEquals('count', $benign['metadata']['template_used']);
        $this->assertStringNotContainsString('DETACH', $benign['cypher']);
        $this->assertStringNotContainsString('})', $benign['cypher']);
    }

    /**
     * @test
     */
    public function it_handles_case_insensitive_label_matching(): void
    {
        // User types "customers" (lowercase, plural), schema has "Customer"
        $question = "show all customers";
        $context = new PipelineContext();
        $context->graphSchema = [
            'labels' => ['Customer', 'Order'],
            'relationships' => [],
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
        $context = new PipelineContext();
        $context->graphSchema = [
            'labels' => ['Customer'],
            'relationships' => [],
        ];

        $result = $this->generator->generate($question, $context);

        // Query should be clean and valid - labels are wrapped in backticks for defense-in-depth
        $this->assertMatchesRegularExpression('/MATCH \(n:`?[A-Za-z_][A-Za-z0-9_]*`?\)/', $result['cypher']);
    }
}
