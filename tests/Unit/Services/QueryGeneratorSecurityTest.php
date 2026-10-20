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
 * CypherSanitizer label escaping, and the guarantee that no user text is
 * interpolated into a query outside the LLM + validation path (the regex
 * template path was removed in Phase 2.B).
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

    /** A structured generation reply (Task 5.6 contract). */
    private function draft(string $cypher): object
    {
        return (object) ['needs_query' => true, 'cypher' => $cypher, 'reason' => ''];
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
    // No user-text interpolation path (Phase 2.B)
    // =========================================================================

    /**
     * @test
     */
    public function user_text_is_never_interpolated_into_a_query_template(): void
    {
        // The regex template path (which extracted a label from user text and
        // interpolated it) is gone: every question — including ones shaped like
        // the old "show all X" / "how many X" templates, with injection payloads —
        // goes through the single LLM + validate path.
        $context = new PipelineContext();
        $context->graphSchema = ['labels' => ['Customer', 'Order'], 'relationships' => []];

        $this->mockLlm->shouldReceive('chatJson')
            ->times(3)
            ->andReturn($this->draft('MATCH (n:Customer) RETURN count(n) AS count'));

        foreach ([
            'show all Customer})-[:HACKED]->(x',
            'how many Customer})-[r]->(n) DETACH DELETE n //',
            'show all Customer',
        ] as $question) {
            $result = $this->generator->generate($question, $context, ['explain' => false]);

            $this->assertStringNotContainsString('HACKED', $result->cypher);
            $this->assertStringNotContainsString('DETACH', $result->cypher);
            $this->assertStringNotContainsString('})', $result->cypher);
            $this->assertNull($result->metadata['template_used'] ?? null);
        }
    }

    /**
     * @test
     */
    public function the_generator_exposes_no_template_api(): void
    {
        $this->assertFalse(method_exists($this->generator, 'detectTemplate'));
        $this->assertFalse(method_exists($this->generator, 'getTemplates'));
        $this->assertFalse(method_exists(\Condoedge\Ai\Contracts\QueryGeneratorInterface::class, 'detectTemplate'));
    }
}
