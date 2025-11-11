<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use InvalidArgumentException;

/**
 * CypherPatternGenerator
 *
 * Converts recorded query builder spy calls into Cypher patterns.
 * Translates Eloquent query builder operations to Neo4j Cypher syntax.
 */
class CypherPatternGenerator
{
    /**
     * Operator conversion map from SQL/Eloquent to Cypher
     *
     * @var array
     */
    private const OPERATOR_MAP = [
        '=' => '=',
        '>' => '>',
        '<' => '<',
        '>=' => '>=',
        '<=' => '<=',
        '!=' => '<>',
        '<>' => '<>',
        'like' => 'CONTAINS',
        'LIKE' => 'CONTAINS',
    ];

    /**
     * Generate Cypher pattern from recorded calls
     *
     * @param array $calls Recorded query builder calls
     * @param string $nodeVar Node variable name (default: 'n')
     * @return string Generated Cypher pattern
     */
    public function generate(array $calls, string $nodeVar = 'n'): string
    {
        if (empty($calls)) {
            return '';
        }

        $conditions = [];

        foreach ($calls as $call) {
            $condition = $this->generateCondition($call, $nodeVar);
            if ($condition !== '') {
                $conditions[] = $condition;
            }
        }

        return $this->combineConditions($conditions);
    }

    /**
     * Generate a single condition from a call
     *
     * @param array $call Single recorded call
     * @param string $nodeVar Node variable name
     * @return string Generated condition
     */
    private function generateCondition(array $call, string $nodeVar): string
    {
        $method = $call['method'] ?? '';

        return match ($method) {
            'where' => $this->generateWhere($call, $nodeVar),
            'whereIn' => $this->generateWhereIn($call, $nodeVar),
            'whereNull' => $this->generateWhereNull($call, $nodeVar),
            'whereHas' => $this->generateWhereHas($call, $nodeVar),
            'whereDate' => $this->generateWhereDate($call, $nodeVar),
            'whereTime' => $this->generateWhereTime($call, $nodeVar),
            'whereBetween' => $this->generateWhereBetween($call, $nodeVar),
            'whereColumn' => $this->generateWhereColumn($call, $nodeVar),
            default => '',
        };
    }

    /**
     * Generate WHERE condition
     *
     * @param array $call Call data
     * @param string $nodeVar Node variable
     * @return string Cypher condition
     */
    private function generateWhere(array $call, string $nodeVar): string
    {
        $type = $call['type'] ?? 'basic';

        if ($type === 'nested') {
            // Handle nested where clauses
            $nestedCalls = $call['nested_calls'] ?? [];
            if (empty($nestedCalls)) {
                return '';
            }

            $nested = $this->generate($nestedCalls, $nodeVar);
            return $nested !== '' ? "({$nested})" : '';
        }

        // Basic where clause
        $column = $call['column'] ?? '';
        $operator = $call['operator'] ?? '=';
        $value = $call['value'] ?? null;
        $boolean = $call['boolean'] ?? 'and';

        if ($column === '') {
            return '';
        }

        $cypherOperator = $this->convertOperator($operator);
        $formattedValue = $this->formatValue($value, $operator);

        $condition = "{$nodeVar}.{$column} {$cypherOperator} {$formattedValue}";

        return $this->applyBoolean($condition, $boolean);
    }

    /**
     * Generate WHERE IN condition
     *
     * @param array $call Call data
     * @param string $nodeVar Node variable
     * @return string Cypher condition
     */
    private function generateWhereIn(array $call, string $nodeVar): string
    {
        $column = $call['column'] ?? '';
        $values = $call['values'] ?? [];
        $not = $call['not'] ?? false;
        $boolean = $call['boolean'] ?? 'and';

        if ($column === '' || empty($values)) {
            return '';
        }

        $formattedValues = array_map(fn($v) => $this->formatValue($v), $values);
        $valueList = '[' . implode(', ', $formattedValues) . ']';

        $operator = $not ? 'NOT IN' : 'IN';
        $condition = "{$nodeVar}.{$column} {$operator} {$valueList}";

        return $this->applyBoolean($condition, $boolean);
    }

    /**
     * Generate WHERE NULL condition
     *
     * @param array $call Call data
     * @param string $nodeVar Node variable
     * @return string Cypher condition
     */
    private function generateWhereNull(array $call, string $nodeVar): string
    {
        $column = $call['column'] ?? '';
        $not = $call['not'] ?? false;
        $boolean = $call['boolean'] ?? 'and';

        if ($column === '') {
            return '';
        }

        $operator = $not ? 'IS NOT NULL' : 'IS NULL';
        $condition = "{$nodeVar}.{$column} {$operator}";

        return $this->applyBoolean($condition, $boolean);
    }

    /**
     * Generate WHERE HAS (relationship) condition
     *
     * @param array $call Call data
     * @param string $nodeVar Node variable
     * @return string Cypher MATCH pattern
     */
    private function generateWhereHas(array $call, string $nodeVar): string
    {
        $relation = $call['relation'] ?? '';
        $nestedCalls = $call['nested_calls'] ?? [];
        $operator = $call['operator'] ?? '>=';
        $count = $call['count'] ?? 1;

        if ($relation === '') {
            return '';
        }

        // Convert relation name to relationship type (snake_case to SCREAMING_SNAKE_CASE)
        $relType = $this->relationshipNameToType($relation);

        // Generate relationship variable name
        $relVar = strtolower(substr($relation, 0, 1));

        // Build MATCH pattern
        $matchPattern = "MATCH ({$nodeVar})-[:{$relType}]->({$relVar})";

        // Add nested conditions if present
        if (!empty($nestedCalls)) {
            $nestedConditions = $this->generate($nestedCalls, $relVar);
            if ($nestedConditions !== '') {
                $matchPattern .= " WHERE {$nestedConditions}";
            }
        }

        // Handle COUNT operators
        if ($operator === '<' && $count === 1) {
            // whereDoesntHave - use NOT EXISTS
            return "NOT EXISTS {({$matchPattern})}";
        }

        // For standard whereHas, return the pattern (will be used in query context)
        return $matchPattern;
    }

    /**
     * Generate WHERE DATE condition
     *
     * @param array $call Call data
     * @param string $nodeVar Node variable
     * @return string Cypher condition
     */
    private function generateWhereDate(array $call, string $nodeVar): string
    {
        $column = $call['column'] ?? '';
        $operator = $call['operator'] ?? '=';
        $value = $call['value'] ?? null;
        $boolean = $call['boolean'] ?? 'and';

        if ($column === '' || $value === null) {
            return '';
        }

        $cypherOperator = $this->convertOperator($operator);

        // In Cypher, use date() function to extract date from datetime
        $condition = "date({$nodeVar}.{$column}) {$cypherOperator} date('{$value}')";

        return $this->applyBoolean($condition, $boolean);
    }

    /**
     * Generate WHERE TIME condition
     *
     * @param array $call Call data
     * @param string $nodeVar Node variable
     * @return string Cypher condition
     */
    private function generateWhereTime(array $call, string $nodeVar): string
    {
        $column = $call['column'] ?? '';
        $operator = $call['operator'] ?? '=';
        $value = $call['value'] ?? null;
        $boolean = $call['boolean'] ?? 'and';

        if ($column === '' || $value === null) {
            return '';
        }

        $cypherOperator = $this->convertOperator($operator);

        // In Cypher, use time() function to extract time from datetime
        $condition = "time({$nodeVar}.{$column}) {$cypherOperator} time('{$value}')";

        return $this->applyBoolean($condition, $boolean);
    }

    /**
     * Generate WHERE BETWEEN condition
     *
     * @param array $call Call data
     * @param string $nodeVar Node variable
     * @return string Cypher condition
     */
    private function generateWhereBetween(array $call, string $nodeVar): string
    {
        $column = $call['column'] ?? '';
        $values = $call['values'] ?? [];
        $not = $call['not'] ?? false;
        $boolean = $call['boolean'] ?? 'and';

        if ($column === '' || count($values) !== 2) {
            return '';
        }

        [$min, $max] = $values;
        $formattedMin = $this->formatValue($min);
        $formattedMax = $this->formatValue($max);

        if ($not) {
            $condition = "NOT ({$nodeVar}.{$column} >= {$formattedMin} AND {$nodeVar}.{$column} <= {$formattedMax})";
        } else {
            $condition = "{$nodeVar}.{$column} >= {$formattedMin} AND {$nodeVar}.{$column} <= {$formattedMax}";
        }

        return $this->applyBoolean($condition, $boolean);
    }

    /**
     * Generate WHERE COLUMN condition (comparing two columns)
     *
     * @param array $call Call data
     * @param string $nodeVar Node variable
     * @return string Cypher condition
     */
    private function generateWhereColumn(array $call, string $nodeVar): string
    {
        $first = $call['first'] ?? '';
        $operator = $call['operator'] ?? '=';
        $second = $call['second'] ?? '';
        $boolean = $call['boolean'] ?? 'and';

        if ($first === '' || $second === '') {
            return '';
        }

        $cypherOperator = $this->convertOperator($operator);
        $condition = "{$nodeVar}.{$first} {$cypherOperator} {$nodeVar}.{$second}";

        return $this->applyBoolean($condition, $boolean);
    }

    /**
     * Convert Eloquent operator to Cypher operator
     *
     * @param string $operator Eloquent operator
     * @return string Cypher operator
     */
    private function convertOperator(string $operator): string
    {
        $operator = trim($operator);
        $lowerOperator = strtolower($operator);

        return self::OPERATOR_MAP[$lowerOperator] ?? self::OPERATOR_MAP[$operator] ?? $operator;
    }

    /**
     * Format value for Cypher query
     *
     * @param mixed $value Value to format
     * @param string $operator Optional operator context
     * @return string Formatted value
     */
    private function formatValue(mixed $value, string $operator = '='): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        // Handle LIKE operator - convert to CONTAINS with pattern extraction
        if (strtolower($operator) === 'like') {
            $value = $this->convertLikePattern($value);
        }

        // String value - escape and quote
        return "'" . $this->escapeString((string) $value) . "'";
    }

    /**
     * Convert LIKE pattern to CONTAINS pattern
     *
     * @param string $pattern LIKE pattern
     * @return string CONTAINS pattern
     */
    private function convertLikePattern(string $pattern): string
    {
        // Remove % wildcards for CONTAINS
        return trim($pattern, '%');
    }

    /**
     * Escape string for Cypher
     *
     * @param string $value Value to escape
     * @return string Escaped value
     */
    private function escapeString(string $value): string
    {
        // Escape single quotes by doubling them
        return str_replace("'", "''", $value);
    }

    /**
     * Convert relationship name to Cypher relationship type
     *
     * @param string $relation Eloquent relation name (e.g., 'userRoles', 'orders')
     * @return string Cypher relationship type (e.g., 'HAS_ROLE', 'HAS_ORDER')
     */
    private function relationshipNameToType(string $relation): string
    {
        // Convert camelCase to snake_case
        $snakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $relation));

        // Convert to SCREAMING_SNAKE_CASE and prefix with HAS_
        return 'HAS_' . strtoupper($snakeCase);
    }

    /**
     * Apply boolean logic to condition
     *
     * @param string $condition Condition string
     * @param string $boolean Boolean operator (and/or)
     * @return string Condition with boolean prefix
     */
    private function applyBoolean(string $condition, string $boolean): string
    {
        if (strtolower($boolean) === 'or') {
            return "OR {$condition}";
        }

        return $condition;
    }

    /**
     * Combine multiple conditions with proper boolean logic
     *
     * @param array $conditions Array of condition strings
     * @return string Combined conditions
     */
    private function combineConditions(array $conditions): string
    {
        if (empty($conditions)) {
            return '';
        }

        $result = [];
        $pendingOr = false;

        foreach ($conditions as $condition) {
            if (str_starts_with($condition, 'OR ')) {
                // Remove OR prefix and mark as pending
                $condition = substr($condition, 3);
                if (!empty($result)) {
                    $result[] = 'OR';
                }
                $result[] = $condition;
                $pendingOr = false;
            } else {
                if (!empty($result)) {
                    $result[] = 'AND';
                }
                $result[] = $condition;
            }
        }

        return implode(' ', $result);
    }

    /**
     * Generate full Cypher query from parsed structure
     *
     * Used for relationship traversal patterns
     *
     * @param array $structure Parsed relationship structure
     * @return string Complete Cypher query
     */
    public function generateFullQuery(array $structure): string
    {
        $entity = $structure['entity'] ?? '';
        $relationships = $structure['relationships'] ?? [];
        $conditions = $structure['conditions'] ?? [];

        if (empty($entity)) {
            throw new InvalidArgumentException('Entity must be specified in structure');
        }

        $query = "MATCH (n:{$entity})";

        // Add relationship paths
        foreach ($relationships as $rel) {
            $type = $rel['type'] ?? '';
            $target = $rel['target'] ?? '';
            $direction = $rel['direction'] ?? 'outgoing';

            if ($type && $target) {
                $targetVar = strtolower(substr($target, 0, 1));
                $arrow = $direction === 'incoming' ? '<-' : '-';
                $query .= "{$arrow}[:{$type}]->({$targetVar}:{$target})";
            }
        }

        // Add WHERE conditions
        if (!empty($conditions)) {
            $whereClauses = [];
            foreach ($conditions as $condition) {
                $entityVar = $condition['entity'] ?? 'n';
                $field = $condition['field'] ?? '';
                $op = $this->convertOperator($condition['op'] ?? '=');
                $value = $this->formatValue($condition['value'] ?? '');

                if ($field) {
                    $whereClauses[] = "{$entityVar}.{$field} {$op} {$value}";
                }
            }

            if (!empty($whereClauses)) {
                $query .= ' WHERE ' . implode(' AND ', $whereClauses);
            }
        }

        $query .= ' RETURN DISTINCT n';

        return $query;
    }
}
