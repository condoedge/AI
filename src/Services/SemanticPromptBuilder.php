<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

/**
 * Semantic Prompt Builder
 *
 * Builds enhanced LLM prompts that include semantic metadata, pattern library,
 * and business rules to help the LLM generate accurate Cypher queries from
 * natural language questions.
 *
 * This service transforms declarative business concepts into rich context that
 * the LLM can understand and use for query generation.
 */
class SemanticPromptBuilder
{
    private PatternLibrary $patternLibrary;

    /**
     * Create a new semantic prompt builder
     *
     * @param PatternLibrary $patternLibrary Pattern library instance
     */
    public function __construct(PatternLibrary $patternLibrary)
    {
        $this->patternLibrary = $patternLibrary;
    }

    /**
     * Build semantic prompt for LLM query generation
     *
     * Creates a comprehensive prompt that includes:
     * - Graph schema
     * - Detected business concepts (scopes)
     * - Pattern library documentation
     * - Query generation rules
     * - The user's question
     *
     * @param string $question User's natural language question
     * @param array $context Context with schema, metadata, and detected scopes
     * @param bool $allowWrite Whether to allow write operations
     * @return string Complete LLM prompt
     */
    public function buildPrompt(
        string $question,
        array $context,
        bool $allowWrite = false
    ): string {
        $prompt = "You are a Neo4j Cypher query expert who generates queries based on semantic business definitions.\n\n";

        $prompt .= $this->getGenericContext();

        // Add graph schema
        $prompt .= $this->formatGraphSchema($context['graph_schema'] ?? []);

        // Add detected scopes with full semantic context
        if (!empty($context['entity_metadata']['detected_scopes'])) {
            $prompt .= "\n=== DETECTED BUSINESS CONCEPTS ===\n\n";
            $prompt .= "The user's question mentions these business concepts:\n\n";

            foreach ($context['entity_metadata']['detected_scopes'] as $scope) {
                $prompt .= $this->formatSemanticScope($scope);
            }
        }

        // Add pattern library documentation
        $prompt .= "\n=== AVAILABLE QUERY PATTERNS ===\n\n";
        $prompt .= "You can use these reusable patterns to construct queries:\n\n";
        $prompt .= $this->formatPatternLibrary();

        // Add query generation rules
        $prompt .= "\n=== QUERY GENERATION RULES ===\n\n";
        $prompt .= $this->formatQueryRules($allowWrite);

        // Add the user's question
        $prompt .= "\n=== USER QUESTION ===\n\n";
        $prompt .= "{$question}\n\n";

        // Request query generation
        $prompt .= "=== YOUR TASK ===\n\n";
        $prompt .= "Generate a Cypher query that:\n";
        $prompt .= "1. Accurately answers the user's question\n";
        $prompt .= "2. Respects all business rules from the detected concepts above\n";
        $prompt .= "3. Uses the appropriate query patterns from the library\n";
        $prompt .= "4. Follows all query generation rules\n";
        $prompt .= "5. Returns clean Cypher only (no markdown, no explanations, no formatting)\n\n";
        $prompt .= "CYPHER QUERY:";

        return $prompt;
    }

    private function getGenericContext()
    {
        $output = "=== CONTEXT INFORMATION ===\n\n";
        $output .= "Current date: " . date('Y-m-d H:i:s') . "\n\n";

        return $output;
    }

    /**
     * Format semantic scope for LLM context
     *
     * Presents a business concept with all its semantic information
     *
     * @param array $scope Detected scope with specification
     * @return string Formatted scope description
     */
    private function formatSemanticScope(array $scope): string
    {
        $output = "─────────────────────────────────────────────\n";
        $output .= "SCOPE: " . strtoupper($scope['scope'] ?? 'unknown') . "\n";
        $output .= "ENTITY: {$scope['entity']}\n";
        $output .= "TYPE: {$scope['specification_type']}\n";
        $output .= "─────────────────────────────────────────────\n\n";

        // Add concept description
        if (!empty($scope['concept'])) {
            $output .= "CONCEPT:\n{$scope['concept']}\n\n";
        }

        // Format based on specification type
        $specType = $scope['specification_type'] ?? 'property_filter';

        switch ($specType) {
            case 'relationship_traversal':
                $output .= $this->formatRelationshipSpec($scope['relationship_spec'] ?? []);
                break;

            case 'property_filter':
                $output .= $this->formatPropertyFilter($scope['filter'] ?? []);
                break;

            case 'pattern':
                $output .= $this->formatPatternSpec(
                    $scope['pattern'] ?? '',
                    $scope['pattern_params'] ?? []
                );
                break;
        }

        // Add business rules
        if (!empty($scope['business_rules'])) {
            $output .= "\nBUSINESS RULES:\n";
            foreach ($scope['business_rules'] as $i => $rule) {
                $output .= ($i + 1) . ". {$rule}\n";
            }
            $output .= "\n";
        }

        // Add example questions
        if (!empty($scope['examples'])) {
            $output .= "EXAMPLE QUESTIONS:\n";
            foreach ($scope['examples'] as $example) {
                $output .= "  • {$example}\n";
            }
            $output .= "\n";
        }

        $output .= "\n";

        return $output;
    }

    /**
     * Format relationship specification
     *
     * @param array $spec Relationship specification
     * @return string Formatted relationship description
     */
    private function formatRelationshipSpec(array $spec): string
    {
        if (empty($spec)) {
            return '';
        }

        $output = "RELATIONSHIP PATH:\n";

        // Build path visualization
        $path = $spec['start_entity'] ?? 'Entity';

        if (!empty($spec['path'])) {
            foreach ($spec['path'] as $step) {
                $relationship = $step['relationship'] ?? '';
                $targetEntity = $step['target_entity'] ?? '';
                $direction = $step['direction'] ?? 'outgoing';

                if ($direction === 'outgoing') {
                    $path .= " -[:{$relationship}]-> ({$targetEntity})";
                } else {
                    $path .= " <-[:{$relationship}]- ({$targetEntity})";
                }
            }
        }

        $output .= "  {$path}\n\n";

        // Add filter if present
        if (!empty($spec['filter'])) {
            $filter = $spec['filter'];
            $output .= "FILTER CONDITION:\n";
            $output .= "  {$filter['entity']}.{$filter['property']} ";
            $output .= "{$filter['operator']} '{$filter['value']}'\n\n";
        }

        // Add multiple filters if present
        if (!empty($spec['filters'])) {
            $output .= "FILTER CONDITIONS:\n";
            foreach ($spec['filters'] as $filter) {
                $output .= "  {$filter['entity']}.{$filter['property']} ";
                $output .= "{$filter['operator']} '{$filter['value']}'\n";
            }
            $output .= "\n";
        }

        // Add note about distinct if needed
        if (!empty($spec['return_distinct'])) {
            $output .= "NOTE: Return DISTINCT results to avoid duplicates from relationship traversal\n\n";
        }

        return $output;
    }

    /**
     * Format property filter
     *
     * @param array $filter Property filter specification
     * @return string Formatted filter description
     */
    private function formatPropertyFilter(array $filter): string
    {
        if (empty($filter)) {
            return '';
        }

        $output = "FILTER:\n";
        $output .= "  Property: {$filter['property']}\n";
        $output .= "  Operator: {$filter['operator']}\n";
        $output .= "  Value: '{$filter['value']}'\n\n";

        return $output;
    }

    /**
     * Format pattern specification
     *
     * @param string $patternName Pattern name
     * @param array $params Pattern parameters
     * @return string Formatted pattern description
     */
    private function formatPatternSpec(string $patternName, array $params): string
    {
        if (empty($patternName)) {
            return '';
        }

        $pattern = $this->patternLibrary->getPattern($patternName);

        $output = "PATTERN: {$patternName}\n";

        if ($pattern) {
            $output .= "DESCRIPTION: {$pattern['description']}\n\n";
        }

        if (!empty($params)) {
            $output .= "PARAMETERS:\n";
            foreach ($params as $key => $value) {
                $valueStr = is_array($value) ? json_encode($value) : $value;
                $output .= "  • {$key}: {$valueStr}\n";
            }
            $output .= "\n";
        }

        // Build semantic description from template
        if ($pattern && !empty($pattern['semantic_template'])) {
            $template = $pattern['semantic_template'];
            foreach ($params as $key => $value) {
                $placeholder = '{' . $key . '}';
                $valueStr = is_array($value) ? json_encode($value) : $value;
                $template = str_replace($placeholder, (string)$valueStr, $template);
            }

            $output .= "SEMANTIC MEANING:\n  {$template}\n\n";
        }

        return $output;
    }

    /**
     * Format graph schema for LLM context
     *
     * @param array $schema Graph schema information
     * @return string Formatted schema description
     */
    private function formatGraphSchema(array $schema): string
    {
        if (empty($schema)) {
            return "=== GRAPH SCHEMA ===\n\nNo schema information available.\n\n";
        }

        $output = "=== GRAPH SCHEMA ===\n\n";

        // Node labels
        if (!empty($schema['labels'])) {
            $output .= "Available Node Labels:\n";
            foreach ($schema['labels'] as $label) {
                $output .= "  • {$label}\n";
            }
            $output .= "\n";
        }

        // Relationship types
        if (!empty($schema['relationships'])) {
            $output .= "Available Relationship Types:\n";
            foreach ($schema['relationships'] as $relType) {
                $output .= "  • {$relType}\n";
            }
            $output .= "\n";
        }

        // Node properties (if available)
        if (!empty($schema['properties'])) {
            $output .= "Node Properties by Label:\n";
            foreach ($schema['properties'] as $label => $properties) {
                $output .= "  {$label}: " . implode(', ', $properties) . "\n";
            }
            $output .= "\n";
        }

        return $output;
    }

    /**
     * Format pattern library for LLM context
     *
     * @return string Formatted pattern library description
     */
    private function formatPatternLibrary(): string
    {
        $patterns = $this->patternLibrary->getAllPatterns();

        if (empty($patterns)) {
            return "No patterns available.\n";
        }

        $output = "";

        foreach ($patterns as $name => $pattern) {
            $output .= "PATTERN: {$name}\n";
            $output .= "  Purpose: {$pattern['description']}\n";
            $output .= "  Template: {$pattern['semantic_template']}\n";

            if (!empty($pattern['parameters'])) {
                $output .= "  Parameters: " . implode(', ', array_keys($pattern['parameters'])) . "\n";
            }

            $output .= "\n";
        }

        return $output;
    }

    /**
     * Format query generation rules
     *
     * @param bool $allowWrite Whether write operations are allowed
     * @return string Formatted rules
     */
    private function formatQueryRules(bool $allowWrite): string
    {
        $rules = "When generating Cypher queries, you MUST follow these rules:\n\n";

        $rules .= "1. SCHEMA COMPLIANCE:\n";
        $rules .= "   • Use only labels and relationships from the schema above\n";
        $rules .= "   • Use only properties that exist in the schema\n";
        $rules .= "   • Respect property data types\n\n";

        $rules .= "2. BUSINESS RULES:\n";
        $rules .= "   • Respect all business rules from detected concepts\n";
        $rules .= "   • Apply filters exactly as specified in scope definitions\n";
        $rules .= "   • Use DISTINCT when specified to avoid duplicates\n\n";

        $rules .= "3. QUERY BEST PRACTICES:\n";
        $rules .= "   • Always include LIMIT clause (default LIMIT 100)\n";
        $rules .= "   • Use DISTINCT when traversing relationships\n";
        $rules .= "   • Use descriptive variable names (p for Person, o for Order, etc.)\n";
        $rules .= "   • Optimize for performance (use indexes, avoid cartesian products)\n\n";

        $rules .= "4. OUTPUT FORMAT:\n";
        $rules .= "   • Return ONLY the Cypher query\n";
        $rules .= "   • NO markdown code blocks\n";
        $rules .= "   • NO explanations or comments\n";
        $rules .= "   • NO formatting or decorations\n";
        $rules .= "   • Just clean, executable Cypher\n\n";

        if (!$allowWrite) {
            $rules .= "5. READ-ONLY CONSTRAINT:\n";
            $rules .= "   • NO write operations (CREATE, MERGE, SET, DELETE, REMOVE, etc.)\n";
            $rules .= "   • Only generate read queries (MATCH, RETURN, WHERE, etc.)\n\n";
        }

        return $rules;
    }
}
