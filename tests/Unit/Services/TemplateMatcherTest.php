<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\TemplateMatcher;
use Condoedge\Ai\Tests\TestCase;

/**
 * Focused tests for the extracted TemplateMatcher.
 *
 * TemplateMatcher owns the regex template library and the deterministic,
 * LLM-free generation path (with schema-validated, backtick-escaped labels).
 * Extends the package TestCase because generateFromTemplate() reads
 * config('entities') and uses CypherSanitizer (needs the app container).
 */
class TemplateMatcherTest extends TestCase
{
    private TemplateMatcher $matcher;

    public function setUp(): void
    {
        parent::setUp();
        $this->matcher = new TemplateMatcher();
    }

    private function schemaContext(array $labels): PipelineContext
    {
        $context = new PipelineContext();
        $context->graphSchema = ['labels' => $labels, 'relationships' => []];

        return $context;
    }

    public function test_get_templates_returns_metadata(): void
    {
        $templates = $this->matcher->getTemplates();

        $this->assertNotEmpty($templates);
        foreach ($templates as $template) {
            $this->assertArrayHasKey('name', $template);
            $this->assertArrayHasKey('description', $template);
            $this->assertArrayHasKey('pattern', $template);
        }
    }

    public function test_detect_template_matches_known_patterns(): void
    {
        $this->assertEquals('list_all', $this->matcher->detectTemplate('Show all customers'));
        $this->assertEquals('count', $this->matcher->detectTemplate('How many orders'));
        $this->assertEquals('list_all', $this->matcher->detectTemplate('SHOW ALL CUSTOMERS'));
    }

    public function test_detect_template_returns_null_for_no_match(): void
    {
        $this->assertNull(
            $this->matcher->detectTemplate("This is a complex question matching no template")
        );
    }

    public function test_is_valid_label(): void
    {
        $this->assertTrue($this->matcher->isValidLabel('Customer', ['Customer', 'Order']));
        $this->assertFalse($this->matcher->isValidLabel('Hacked', ['Customer', 'Order']));
        $this->assertFalse($this->matcher->isValidLabel('customer', ['Customer', 'Order'])); // case sensitive
        $this->assertFalse($this->matcher->isValidLabel('Customer', []));
    }

    public function test_generate_from_template_produces_escaped_label(): void
    {
        $context = $this->schemaContext(['Customer', 'Order']);

        $result = $this->matcher->generateFromTemplate('Show all customers', 'list_all', $context);

        $this->assertNotNull($result);
        $this->assertStringContainsString('MATCH', $result['cypher']);
        $this->assertStringContainsString('Customer', $result['cypher']);
        $this->assertStringContainsString('LIMIT', $result['cypher']);
        $this->assertEquals('list_all', $result['metadata']['template_used']);
        // Label is backtick-escaped for defense in depth.
        $this->assertMatchesRegularExpression('/MATCH \(n:`?Customer`?\)/', $result['cypher']);
    }

    public function test_generate_from_template_singularizes_and_capitalizes_label(): void
    {
        // User types lowercase plural; schema has the canonical singular form.
        $context = $this->schemaContext(['Customer', 'Order']);

        $result = $this->matcher->generateFromTemplate('how many customers', 'count', $context);

        $this->assertNotNull($result);
        $this->assertStringContainsString('Customer', $result['cypher']);
        $this->assertStringContainsString('count', strtolower($result['cypher']));
    }

    public function test_generate_from_template_rejects_unknown_label(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown label');

        $context = $this->schemaContext(['Customer', 'Order']);
        $this->matcher->generateFromTemplate('show all hackers', 'list_all', $context);
    }

    public function test_generate_from_template_returns_null_when_no_valid_labels(): void
    {
        // Empty schema and no entity configs => fall through to LLM (null).
        $context = $this->schemaContext([]);

        $result = $this->matcher->generateFromTemplate('show all customers', 'list_all', $context);

        $this->assertNull($result);
    }
}
