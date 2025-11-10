<?php

declare(strict_types=1);

namespace AiSystem\Services;

/**
 * Pattern Library
 *
 * Provides reusable, generic query patterns that can be instantiated
 * with specific parameters from entity configurations.
 *
 * Patterns are domain-agnostic templates that the LLM uses to generate
 * appropriate Cypher queries based on semantic business descriptions.
 */
class PatternLibrary
{
    private array $patterns;

    /**
     * Create a new pattern library instance
     *
     * @param array|null $patterns Optional pattern definitions (defaults to loading from config)
     */
    public function __construct(?array $patterns = null)
    {
        $this->patterns = $patterns ?? $this->loadPatterns();
    }

    /**
     * Get pattern definition by name
     *
     * @param string $name Pattern name
     * @return array|null Pattern definition or null if not found
     */
    public function getPattern(string $name): ?array
    {
        return $this->patterns[$name] ?? null;
    }

    /**
     * Get all available patterns
     *
     * @return array All pattern definitions
     */
    public function getAllPatterns(): array
    {
        return $this->patterns;
    }

    /**
     * Get all pattern names
     *
     * @return array List of available pattern names
     */
    public function getPatternNames(): array
    {
        return array_keys($this->patterns);
    }

    /**
     * Check if a pattern exists
     *
     * @param string $name Pattern name
     * @return bool True if pattern exists
     */
    public function hasPattern(string $name): bool
    {
        return isset($this->patterns[$name]);
    }

    /**
     * Instantiate pattern with parameters
     *
     * Validates parameters and builds semantic description
     *
     * @param string $name Pattern name
     * @param array $params Pattern parameters
     * @return array Instantiated pattern with semantic description
     * @throws \InvalidArgumentException If pattern not found or params invalid
     */
    public function instantiatePattern(string $name, array $params): array
    {
        $pattern = $this->getPattern($name);

        if (!$pattern) {
            throw new \InvalidArgumentException("Unknown pattern: {$name}");
        }

        // Validate parameters
        $this->validatePatternParams($pattern, $params);

        // Build semantic description
        $description = $this->buildSemanticDescription($pattern, $params);

        return [
            'pattern_name' => $name,
            'pattern_def' => $pattern,
            'parameters' => $params,
            'semantic_description' => $description,
        ];
    }

    /**
     * Load patterns from configuration
     *
     * Attempts to load from Laravel config first, then falls back to direct file require
     *
     * @return array Pattern definitions
     */
    private function loadPatterns(): array
    {
        // Try Laravel config first
        if (function_exists('config')) {
            $patterns = config('ai.query_patterns', []);
            if (!empty($patterns)) {
                return $patterns;
            }
        }

        // Fallback: Try loading from file directly
        $configPath = __DIR__ . '/../../config/ai-patterns.php';
        if (file_exists($configPath)) {
            return require $configPath;
        }

        // Return empty array if nothing found
        return [];
    }

    /**
     * Validate pattern parameters
     *
     * Ensures all required parameters are provided
     *
     * @param array $pattern Pattern definition
     * @param array $params Provided parameters
     * @throws \InvalidArgumentException If required parameters are missing
     */
    private function validatePatternParams(array $pattern, array $params): void
    {
        $required = $pattern['parameters'] ?? [];

        foreach ($required as $param => $description) {
            if (!isset($params[$param]) && !array_key_exists($param, $params)) {
                throw new \InvalidArgumentException(
                    "Missing required parameter '{$param}' for pattern. " .
                    "Expected: {$description}"
                );
            }
        }
    }

    /**
     * Build semantic description from pattern template
     *
     * Replaces placeholders in semantic template with actual parameter values
     *
     * @param array $pattern Pattern definition
     * @param array $params Pattern parameters
     * @return string Human-readable semantic description
     */
    private function buildSemanticDescription(array $pattern, array $params): string
    {
        $template = $pattern['semantic_template'] ?? '';

        if (empty($template)) {
            return '';
        }

        // Replace all parameter placeholders with values
        foreach ($params as $key => $value) {
            $placeholder = '{' . $key . '}';

            // Handle array values (like paths)
            if (is_array($value)) {
                $value = $this->formatArrayValue($key, $value);
            }

            $template = str_replace($placeholder, (string)$value, $template);
        }

        return $template;
    }

    /**
     * Format array values for semantic description
     *
     * @param string $key Parameter key
     * @param array $value Array value
     * @return string Formatted string representation
     */
    private function formatArrayValue(string $key, array $value): string
    {
        // Handle relationship paths specially
        if ($key === 'path' || $key === 'hops') {
            return $this->formatRelationshipPath($value);
        }

        // Handle filter arrays
        if ($key === 'filters') {
            return $this->formatFilters($value);
        }

        // Default: JSON representation
        return json_encode($value);
    }

    /**
     * Format relationship path for display
     *
     * @param array $path Relationship path steps
     * @return string Formatted path string
     */
    private function formatRelationshipPath(array $path): string
    {
        $formatted = [];

        foreach ($path as $step) {
            if (is_array($step)) {
                $rel = $step['relationship'] ?? '';
                $target = $step['target_entity'] ?? '';
                $formatted[] = "{$rel} → {$target}";
            }
        }

        return implode(' → ', $formatted);
    }

    /**
     * Format filters for display
     *
     * @param array $filters Filter conditions
     * @return string Formatted filters string
     */
    private function formatFilters(array $filters): string
    {
        $formatted = [];

        foreach ($filters as $filter) {
            if (is_array($filter)) {
                $entity = $filter['entity'] ?? '';
                $property = $filter['property'] ?? '';
                $operator = $filter['operator'] ?? '=';
                $value = $filter['value'] ?? '';

                $formatted[] = "{$entity}.{$property} {$operator} '{$value}'";
            }
        }

        return implode(', ', $formatted);
    }
}
