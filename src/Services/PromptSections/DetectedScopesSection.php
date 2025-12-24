<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

/**
 * DetectedScopesSection
 *
 * Shows business concepts (scopes) detected in the question
 * with their full semantic specifications.
 * Priority: 65
 */
class DetectedScopesSection extends BasePromptSection
{
    protected string $name = 'detected_scopes';
    protected int $priority = 65;

    public function format(string $question, array $context, array $options = []): string
    {
        $entityMetadata = $context['entity_metadata'] ?? [];
        $scopes = $entityMetadata['detected_scopes'] ?? [];

        if (empty($scopes)) {
            return '';
        }

        $output = $this->header('DETECTED BUSINESS CONCEPTS');
        $output .= "The user's question mentions these business concepts:\n\n";

        foreach ($scopes as $scope) {
            $output .= $this->formatScope($scope);
        }

        return $output;
    }

    private function formatScope(array $scope): string
    {
        $output = $this->divider();
        $output .= "SCOPE: " . strtoupper($scope['scope'] ?? 'unknown') . "\n";
        $output .= "ENTITY: {$scope['entity']}\n";

        if (!empty($scope['specification_type'])) {
            $output .= "TYPE: {$scope['specification_type']}\n";
        }

        $output .= $this->divider() . "\n";

        // Concept description
        if (!empty($scope['concept'])) {
            $output .= "CONCEPT:\n{$scope['concept']}\n\n";
        }

        // Format based on specification type
        $specType = $scope['specification_type'] ?? 'property_filter';

        switch ($specType) {
            case 'relationship_traversal':
                // Use parsed_structure if available, otherwise relationship_spec
                $relSpec = $scope['parsed_structure'] ?? $scope['relationship_spec'] ?? [];
                $output .= $this->formatRelationshipSpec($relSpec, $scope);
                break;

            case 'property_filter':
                $output .= $this->formatPropertyFilter($scope['filter'] ?? [], $scope);
                break;

            case 'pattern':
                $output .= $this->formatPatternSpec($scope);
                break;

            default:
                // For generic or unknown types, show cypher pattern if available
                if (!empty($scope['cypher_pattern'])) {
                    $output .= "CYPHER PATTERN:\n  {$scope['cypher_pattern']}\n\n";
                }
                break;
        }

        // Business rules
        if (!empty($scope['business_rules'])) {
            $output .= "BUSINESS RULES:\n";
            foreach ($scope['business_rules'] as $i => $rule) {
                $output .= "  " . ($i + 1) . ". {$rule}\n";
            }
            $output .= "\n";
        }

        // Example questions
        if (!empty($scope['examples'])) {
            $output .= "EXAMPLE QUESTIONS:\n";
            foreach ($scope['examples'] as $example) {
                $output .= "  - {$example}\n";
            }
            $output .= "\n";
        }

        return $output . "\n";
    }

    private function formatRelationshipSpec(array $spec, array $scope = []): string
    {
        $output = '';

        // If we have a cypher_pattern directly, show it
        if (!empty($scope['cypher_pattern'])) {
            $output .= "CYPHER PATTERN:\n  {$scope['cypher_pattern']}\n\n";
        }

        // If spec is empty, return what we have
        if (empty($spec)) {
            return $output;
        }

        // Build relationship path from parsed_structure format
        // Format: { entity: 'Person', relationships: [...], conditions: [...] }
        if (!empty($spec['entity']) && !empty($spec['relationships'])) {
            $output .= "RELATIONSHIP PATH:\n";
            $path = "({$spec['entity']})";

            foreach ($spec['relationships'] as $rel) {
                $type = $rel['type'] ?? '';
                $target = $rel['target'] ?? '';
                $direction = $rel['direction'] ?? 'outgoing';

                if ($direction === 'incoming') {
                    $path .= " <-[:{$type}]- ({$target})";
                } else {
                    $path .= " -[:{$type}]-> ({$target})";
                }
            }

            $output .= "  {$path}\n\n";

            // Conditions from parsed_structure
            if (!empty($spec['conditions'])) {
                $output .= "CONDITIONS:\n";
                foreach ($spec['conditions'] as $condition) {
                    $entityVar = $condition['entity'] ?? 'n';
                    $field = $condition['field'] ?? '';
                    $op = $condition['op'] ?? '=';
                    $value = $condition['value'] ?? '';

                    if ($field) {
                        $output .= "  {$entityVar}.{$field} {$op} '{$value}'\n";
                    }
                }
                $output .= "\n";
            }

            return $output;
        }

        // Legacy format: { start_entity, path: [...], filter: {...} }
        if (!empty($spec['start_entity']) || !empty($spec['path'])) {
            $output .= "RELATIONSHIP PATH:\n";
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

            // Filter conditions (legacy format)
            if (!empty($spec['filter']) && is_array($spec['filter'])) {
                $filter = $spec['filter'];
                if (isset($filter['property'])) {
                    $output .= "FILTER: {$filter['entity']}.{$filter['property']} ";
                    $output .= "{$filter['operator']} '{$filter['value']}'\n\n";
                }
            }

            if (!empty($spec['filters'])) {
                $output .= "FILTERS:\n";
                foreach ($spec['filters'] as $filter) {
                    if (isset($filter['property'])) {
                        $output .= "  {$filter['entity']}.{$filter['property']} ";
                        $output .= "{$filter['operator']} '{$filter['value']}'\n";
                    }
                }
                $output .= "\n";
            }

            if (!empty($spec['return_distinct'])) {
                $output .= "NOTE: Return DISTINCT to avoid duplicates\n\n";
            }
        }

        // Show role_value if available (from scope discovery)
        if (!empty($scope['role_value'])) {
            $output .= "ROLE VALUE: {$scope['role_value']}\n\n";
        }

        return $output;
    }

    private function formatPropertyFilter(array $filter, array $scope = []): string
    {
        $output = '';

        // Show cypher_pattern if available
        if (!empty($scope['cypher_pattern'])) {
            $output .= "CYPHER PATTERN:\n  {$scope['cypher_pattern']}\n\n";
        }

        if (empty($filter)) {
            return $output;
        }

        // New format from CypherScopeAdapter: { column => value }
        // e.g., ['status' => 'active']
        if (!isset($filter['property'])) {
            $output .= "FILTER:\n";
            foreach ($filter as $property => $value) {
                $output .= "  {$property} = '{$value}'\n";
            }
            $output .= "\n";
            return $output;
        }

        // Legacy format: { property, operator, value }
        return $output . "FILTER:\n" .
               "  Property: {$filter['property']}\n" .
               "  Operator: {$filter['operator']}\n" .
               "  Value: '{$filter['value']}'\n\n";
    }

    private function formatPatternSpec(array $scope): string
    {
        $patternName = $scope['pattern'] ?? '';
        $params = $scope['pattern_params'] ?? [];

        if (empty($patternName)) {
            return '';
        }

        $output = "PATTERN: {$patternName}\n";

        if (!empty($params)) {
            $output .= "PARAMETERS:\n";
            foreach ($params as $key => $value) {
                $valueStr = is_array($value) ? json_encode($value) : $value;
                $output .= "  - {$key}: {$valueStr}\n";
            }
            $output .= "\n";
        }

        return $output;
    }

    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        $entityMetadata = $context['entity_metadata'] ?? [];
        return !empty($entityMetadata['detected_scopes']);
    }
}
