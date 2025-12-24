<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

/**
 * QueryRulesSection
 *
 * Provides rules for query generation including:
 * - Schema compliance
 * - Data type rules
 * - Relationship direction rules
 * - Business rules
 * - Query best practices
 * - Output format
 *
 * Priority: 75 (after pattern library, before question)
 */
class QueryRulesSection extends BasePromptSection
{
    protected string $name = 'query_rules';
    protected int $priority = 75;

    /**
     * Custom rules that can be added dynamically
     */
    private array $customRules = [];

    /**
     * Add a custom rule
     *
     * @param string $category Rule category (e.g., 'SCHEMA_COMPLIANCE', 'CUSTOM')
     * @param string $rule The rule text
     * @return self
     */
    public function addRule(string $category, string $rule): self
    {
        $this->customRules[$category][] = $rule;
        return $this;
    }

    /**
     * Set all custom rules at once
     *
     * @param array $rules Array of [category => [rules...]]
     * @return self
     */
    public function setRules(array $rules): self
    {
        $this->customRules = $rules;
        return $this;
    }

    public function format(string $question, array $context, array $options = []): string
    {
        $allowWrite = $options['allowWrite'] ?? false;

        $output = $this->header('QUERY GENERATION RULES');
        $output .= "When generating Cypher queries, you MUST follow these rules:\n\n";

        $output .= $this->formatSchemaCompliance();
        $output .= $this->formatDataTypeRules();
        $output .= $this->formatRelationshipRules();
        $output .= $this->formatBusinessRules();
        $output .= $this->formatBestPractices();
        $output .= $this->formatOutputFormat();

        if (!$allowWrite) {
            $output .= $this->formatReadOnlyConstraint();
        }

        // Add custom rules
        $output .= $this->formatCustomRules();

        return $output;
    }

    private function formatSchemaCompliance(): string
    {
        return "1. SCHEMA COMPLIANCE:\n" .
               "   - Use only labels and relationships from the schema above\n" .
               "   - Use only properties that exist in the schema\n" .
               "   - CRITICAL: Use the EXACT relationship directions shown (arrows matter!)\n" .
               "   - CRITICAL: Use the EXACT data formats from example entities (strings vs dates)\n\n";
    }

    private function formatDataTypeRules(): string
    {
        return "2. DATA TYPE RULES:\n" .
               "   - Look at example entities to determine if dates are stored as strings or Neo4j dates\n" .
               "   - If date looks like '2020-01-15' (quoted string), compare as string: property < '2020-01-01'\n" .
               "   - If date looks like date('2020-01-15'), use date() function: property < date('2020-01-01')\n" .
               "   - String comparisons work for ISO date format (YYYY-MM-DD)\n\n";
    }

    private function formatRelationshipRules(): string
    {
        return "3. RELATIONSHIP DIRECTION RULES:\n" .
               "   - ALWAYS check the relationship direction in the schema\n" .
               "   - (a)-[:REL]->(b) means relationship goes FROM a TO b\n" .
               "   - (a)<-[:REL]-(b) means relationship goes FROM b TO a\n" .
               "   - Getting the direction wrong will return zero results!\n\n";
    }

    private function formatBusinessRules(): string
    {
        return "4. BUSINESS RULES:\n" .
               "   - Respect all business rules from detected concepts\n" .
               "   - Apply filters exactly as specified in scope definitions\n" .
               "   - Use DISTINCT when specified to avoid duplicates\n\n";
    }

    private function formatBestPractices(): string
    {
        return "5. QUERY BEST PRACTICES:\n" .
               "   - Always include LIMIT clause (default LIMIT 100)\n" .
               "   - Use DISTINCT when traversing relationships\n" .
               "   - Use descriptive variable names (p for Person, o for Order, etc.)\n" .
               "   - Optimize for performance (use indexes, avoid cartesian products)\n\n";
    }

    private function formatOutputFormat(): string
    {
        return "6. OUTPUT FORMAT:\n" .
               "   - Return ONLY the Cypher query\n" .
               "   - NO markdown code blocks\n" .
               "   - NO explanations or comments\n" .
               "   - NO formatting or decorations\n" .
               "   - Just clean, executable Cypher\n\n";
    }

    private function formatReadOnlyConstraint(): string
    {
        return "7. READ-ONLY CONSTRAINT:\n" .
               "   - NO write operations (CREATE, MERGE, SET, DELETE, REMOVE, etc.)\n" .
               "   - Only generate read queries (MATCH, RETURN, WHERE, etc.)\n\n";
    }

    private function formatCustomRules(): string
    {
        if (empty($this->customRules)) {
            return '';
        }

        $output = '';
        $ruleNumber = 8; // Continue from last standard rule

        foreach ($this->customRules as $category => $rules) {
            $output .= "{$ruleNumber}. {$category}:\n";
            foreach ($rules as $rule) {
                $output .= "   - {$rule}\n";
            }
            $output .= "\n";
            $ruleNumber++;
        }

        return $output;
    }
}
