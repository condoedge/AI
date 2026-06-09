<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\GraphStore\CypherSanitizer;

/**
 * Template Matcher
 *
 * Owns the library of regex-based query templates and the deterministic,
 * LLM-free generation path for common questions ("list all X", "how many X", ...).
 *
 * Template queries bypass the LLM, so any label extracted from user input is
 * validated against the schema and backtick-escaped before interpolation.
 *
 * @package Condoedge\Ai\Services
 */
class TemplateMatcher
{
    /**
     * Query templates for common patterns
     */
    private array $templates = [
        'list_all' => [
            'pattern' => '/^(show|list|get|display|find)\s+all\s+(\w+)\s*[?.!]?$/i',
            'cypher' => 'MATCH (n:{label}) RETURN n LIMIT 100',
            'description' => 'List all entities of a type',
            'example_question' => 'Show all customers',
            'example_cypher' => 'MATCH (n:Customer) RETURN n LIMIT 100'
        ],
        'count' => [
            'pattern' => '/^(how many|count|number of)\s+(\w+)\s*(are there|do we have|exist|total)?\s*[?.!]?$/i',
            'cypher' => 'MATCH (n:{label}) RETURN count(n) as count',
            'description' => 'Count entities of a type',
            'example_question' => 'How many orders?',
            'example_cypher' => 'MATCH (n:Order) RETURN count(n) as count'
        ],
        'find_by_property' => [
            'pattern' => '/^find\s+(\w+)\s+(with|where|having)\s+(\w+)\s*(=|is|equals)\s*(.+)/i',
            'cypher' => 'MATCH (n:{label} {{{property}: $value}}) RETURN n LIMIT 100',
            'description' => 'Find entities by property value',
            'example_question' => 'Find customers with email john@example.com',
            'example_cypher' => 'MATCH (n:Customer {{email: $value}}) RETURN n LIMIT 100'
        ],
        'relationship_query' => [
            'pattern' => '/^(show|find|get)\s+(\w+)\s+(connected to|related to|linked to)\s+(\w+)/i',
            'cypher' => 'MATCH (a:{label1})-[r]-(b:{label2}) RETURN a, r, b LIMIT 100',
            'description' => 'Find related entities',
            'example_question' => 'Show customers connected to orders',
            'example_cypher' => 'MATCH (a:Customer)-[r]-(b:Order) RETURN a, r, b LIMIT 100'
        ],
        'aggregation' => [
            'pattern' => '/^(sum|total|average|avg|max|min)\s+(.+)/i',
            'cypher' => 'MATCH (n:{label}) RETURN {aggregation}(n.{property}) as result',
            'description' => 'Aggregate property values',
            'example_question' => 'What is the average order total?',
            'example_cypher' => 'MATCH (n:Order) RETURN avg(n.total) as result'
        ],
        'filtering' => [
            'pattern' => '/^(\w+)\s+where\s+(.+)/i',
            'cypher' => 'MATCH (n:{label}) WHERE {condition} RETURN n LIMIT 100',
            'description' => 'Filter entities by condition',
            'example_question' => 'Customers where age > 30',
            'example_cypher' => 'MATCH (n:Customer) WHERE n.age > 30 RETURN n LIMIT 100'
        ],
    ];

    /**
     * Get available query templates (metadata only).
     *
     * @return array Array of template metadata
     */
    public function getTemplates(): array
    {
        return array_map(function ($name, $template) {
            return [
                'name' => $name,
                'description' => $template['description'],
                'pattern' => $template['pattern'],
                'example_question' => $template['example_question'] ?? '',
                'example_cypher' => $template['example_cypher'] ?? '',
            ];
        }, array_keys($this->templates), $this->templates);
    }

    /**
     * Detect which template (if any) matches the question.
     *
     * @param string $question Natural language question
     * @return string|null Template name or null if no match
     */
    public function detectTemplate(string $question): ?string
    {
        foreach ($this->templates as $name => $template) {
            if (preg_match($template['pattern'], $question)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Check if a label exists in the graph schema.
     *
     * Used to validate labels extracted from user input before
     * using them in template-generated queries.
     *
     * @param string $label The label to validate
     * @param array $validLabels Array of valid labels from schema
     * @return bool True if the label is valid
     */
    public function isValidLabel(string $label, array $validLabels): bool
    {
        if (empty($validLabels)) {
            return false;
        }

        return in_array($label, $validLabels, true);
    }

    /**
     * Generate a query from a matched template.
     *
     * Security: Template queries bypass the LLM, so labels extracted from user
     * input must be validated against schema and sanitized before interpolation.
     *
     * @param string $question Original question
     * @param string $templateName Template name
     * @param PipelineContext $context Pipeline context
     * @return array|null Generation result, or null to fall through to LLM
     * @throws \InvalidArgumentException If label is not in schema
     */
    public function generateFromTemplate(string $question, string $templateName, PipelineContext $context): ?array
    {
        $template = $this->templates[$templateName];

        // Extract parameters from question
        preg_match($template['pattern'], $question, $matches);

        // Get valid labels from both Neo4j schema and entity config
        $schema = $context->graphSchema;
        $validLabels = $schema['labels'] ?? [];

        // Also include labels from entity config properties (covers entities not yet ingested)
        $configLabels = array_keys($schema['properties'] ?? []);
        $validLabels = array_unique(array_merge($validLabels, $configLabels));

        // Also include labels from entity configs if available
        $entityConfigs = config('entities', []);
        foreach ($entityConfigs as $config) {
            $label = $config['graph']['label'] ?? null;
            if ($label && !in_array($label, $validLabels)) {
                $validLabels[] = $label;
            }
        }

        // Security: If no valid labels at all, fall through to LLM generation
        if (empty($validLabels)) {
            return null;
        }

        // Infer the label from user input
        $label = $this->inferLabel($matches, $schema);

        // Security: Validate the label exists in schema
        // This prevents injection of arbitrary labels from user input
        if (!$this->isValidLabel($label, $validLabels)) {
            throw new \InvalidArgumentException("Unknown label: {$label}");
        }

        // Security: Defense in depth - sanitize even after validation
        // This ensures no special characters can slip through
        $sanitizedLabel = CypherSanitizer::escapeLabel($label);

        // Generate query from template with sanitized label
        $cypher = str_replace('{label}', $sanitizedLabel, $template['cypher']);

        return [
            'cypher' => $cypher,
            'explanation' => "This query uses the '{$templateName}' template to {$template['description']}.",
            'confidence' => 0.9,
            'warnings' => [],
            'metadata' => [
                'template_used' => $templateName,
                'retry_count' => 0,
                'complexity' => 10,
            ],
        ];
    }

    /**
     * Infer a label from regex matches and schema.
     *
     * @param array $matches Regex matches
     * @param array $schema Graph schema
     * @return string Label name
     */
    private function inferLabel(array $matches, array $schema): string
    {
        // Get potential label from regex match
        $potentialLabel = $matches[2] ?? $matches[1] ?? 'Node';

        // Capitalize and singularize
        $label = ucfirst(rtrim(strtolower($potentialLabel), 's'));

        // Check if label exists in schema
        if (!empty($schema['labels']) && in_array($label, $schema['labels'])) {
            return $label;
        }

        // Return as-is if not in schema
        return $label;
    }
}
