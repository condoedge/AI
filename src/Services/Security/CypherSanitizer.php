<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

/**
 * CypherSanitizer
 *
 * Provides sanitization methods for Cypher query components to prevent injection attacks.
 * Used to safely handle user input that gets interpolated into Cypher queries.
 *
 * @package Condoedge\Ai\Services\Security
 */
class CypherSanitizer
{
    /**
     * Escape a label for safe use in Cypher queries.
     *
     * Labels can only contain alphanumeric characters and underscores.
     * This method removes any characters that could be used for injection
     * and ensures the label starts with a valid character.
     *
     * @param string $label The label to sanitize
     * @return string The sanitized label safe for use in Cypher queries
     */
    public static function escapeLabel(string $label): string
    {
        // Remove any characters that aren't alphanumeric or underscore
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $label);

        // Ensure it starts with a letter or underscore (Cypher label requirement)
        if (empty($sanitized) || !preg_match('/^[a-zA-Z_]/', $sanitized)) {
            $sanitized = 'Invalid_' . $sanitized;
        }

        return $sanitized;
    }

    /**
     * Escape a property name for safe use in Cypher queries.
     *
     * Property names follow the same rules as labels.
     *
     * @param string $property The property name to sanitize
     * @return string The sanitized property name
     */
    public static function escapeProperty(string $property): string
    {
        return self::escapeLabel($property);
    }

    /**
     * Escape a relationship type for safe use in Cypher queries.
     *
     * Relationship types follow similar rules but are typically uppercase.
     *
     * @param string $type The relationship type to sanitize
     * @return string The sanitized relationship type
     */
    public static function escapeRelationshipType(string $type): string
    {
        $sanitized = self::escapeLabel($type);

        return strtoupper($sanitized);
    }

    /**
     * Check if a string contains potentially dangerous Cypher patterns.
     *
     * @param string $input The input to check
     * @return bool True if the input contains dangerous patterns
     */
    public static function containsDangerousPatterns(string $input): bool
    {
        $dangerousPatterns = [
            '/\bDELETE\b/i',
            '/\bDETACH\b/i',
            '/\bDROP\b/i',
            '/\bCREATE\b/i',
            '/\bMERGE\b/i',
            '/\bSET\b/i',
            '/\bREMOVE\b/i',
            '/[{}()\[\];\-]/',  // Structural characters that could be used for injection
            '/\/\//',            // Comment injection
            '/\/\*/',            // Block comment injection
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }
}
