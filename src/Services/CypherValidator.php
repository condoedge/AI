<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

/**
 * Cypher Validator
 *
 * Owns the canonical write/dangerous keyword list and all syntax/safety/complexity
 * checks for read-oriented Cypher queries. This is the single source of truth for
 * "is this query safe and well-formed?" used by QueryGenerator's validate/sanitize
 * paths, so the dangerous-keyword set never diverges across call sites.
 *
 * @package Condoedge\Ai\Services
 */
class CypherValidator
{
    /**
     * Canonical list of keywords that modify or delete data.
     *
     * This is the single source of truth for write/dangerous keyword detection
     * within query generation. Validation, read-only classification, and
     * sanitization all derive from this one list.
     */
    public const DANGEROUS_KEYWORDS = [
        'DELETE', 'REMOVE', 'DROP', 'CREATE', 'MERGE', 'SET', 'DETACH',
    ];

    /**
     * Default maximum allowed complexity before a warning is raised.
     */
    private const DEFAULT_MAX_COMPLEXITY = 100;

    /**
     * Validate a Cypher query for syntax and safety.
     *
     * @param string|null $cypherQuery Query to validate
     * @param array $options Validation options (allow_write, max_complexity)
     * @return array Validation result with valid/errors/warnings/complexity/is_read_only
     * @throws \InvalidArgumentException If query is empty
     */
    public function validate(?string $cypherQuery, array $options = []): array
    {
        if (empty(trim((string) $cypherQuery))) {
            throw new \InvalidArgumentException('Query cannot be empty');
        }

        $allowWrite = $options['allow_write'] ?? false;
        $maxComplexity = $options['max_complexity'] ?? self::DEFAULT_MAX_COMPLEXITY;

        $errors = [];
        $warnings = [];

        // Check for dangerous operations (word boundary match to avoid false positives like "deleted_at")
        foreach (self::DANGEROUS_KEYWORDS as $keyword) {
            if ($this->containsKeyword($cypherQuery, $keyword) && !$allowWrite) {
                $errors[] = "Query contains forbidden keyword: {$keyword}";
            }
        }

        // Check for LIMIT clause
        if (!preg_match('/\bLIMIT\b/i', $cypherQuery)) {
            $warnings[] = "Query missing LIMIT clause - may return large result set";
        }

        // Basic syntax validation
        if (!preg_match('/\bMATCH\b/i', $cypherQuery) && !preg_match('/\bRETURN\b/i', $cypherQuery)) {
            $errors[] = "Query must contain MATCH or RETURN clause";
        }

        // Check complexity
        $complexity = $this->complexity($cypherQuery);
        if ($complexity > $maxComplexity) {
            $warnings[] = "Query complexity ({$complexity}) exceeds threshold ({$maxComplexity})";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'complexity' => $complexity,
            'is_read_only' => $this->isReadOnly($cypherQuery),
        ];
    }

    /**
     * Determine whether a query is read-only (contains no dangerous keywords).
     *
     * @param string $cypherQuery Query to inspect
     * @return bool True if no write/dangerous keyword is present
     */
    public function isReadOnly(string $cypherQuery): bool
    {
        foreach (self::DANGEROUS_KEYWORDS as $keyword) {
            if ($this->containsKeyword($cypherQuery, $keyword)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove dangerous operations from a query and ensure a LIMIT clause exists.
     *
     * @param string|null $cypherQuery Query to sanitize
     * @param int $defaultLimit Limit appended when none is present
     * @return string Sanitized query
     */
    public function sanitize(?string $cypherQuery, int $defaultLimit = 100): string
    {
        $cypherQuery = (string) $cypherQuery;

        // Remove dangerous keywords (and everything up to the next statement boundary)
        foreach (self::DANGEROUS_KEYWORDS as $keyword) {
            $cypherQuery = preg_replace('/\b' . $keyword . '\b[^;]*(;|$)/i', '', $cypherQuery);
        }

        // Add LIMIT if missing
        if (!preg_match('/\bLIMIT\b/i', $cypherQuery)) {
            if (preg_match('/\bRETURN\b/i', $cypherQuery)) {
                $cypherQuery = preg_replace('/(\bRETURN\b.*)$/i', "$1 LIMIT {$defaultLimit}", $cypherQuery);
            }
        }

        return trim($cypherQuery);
    }

    /**
     * Calculate a heuristic complexity score for a query.
     *
     * @param string $cypher Query to analyze
     * @return int Complexity score
     */
    public function complexity(string $cypher): int
    {
        $complexity = 0;

        // Count MATCH clauses
        $complexity += substr_count(strtoupper($cypher), 'MATCH') * 10;

        // Count WHERE clauses
        $complexity += substr_count(strtoupper($cypher), 'WHERE') * 5;

        // Count joins
        $complexity += substr_count($cypher, ']-') * 8;

        // Count aggregations
        $complexity += preg_match_all('/\b(count|sum|avg|max|min)\b/i', $cypher) * 3;

        return $complexity;
    }

    /**
     * Word-boundary, case-insensitive keyword presence check.
     */
    private function containsKeyword(string $cypherQuery, string $keyword): bool
    {
        return (bool) preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $cypherQuery);
    }
}
