<?php

declare(strict_types=1);

namespace Condoedge\Ai\GraphStore;

use Condoedge\Ai\Exceptions\CypherInjectionException;

/**
 * CypherSanitizer
 *
 * Provides methods to sanitize and validate Cypher query components
 * to prevent injection attacks.
 *
 * Neo4j doesn't support parameterized labels/types/property keys,
 * so we must validate them against strict patterns before interpolation.
 */
class CypherSanitizer
{
    /**
     * Valid identifier pattern:
     * - Must start with letter or underscore
     * - Can contain letters, digits, underscores
     * - No spaces, special characters, or Unicode exploits
     */
    private const IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * Maximum identifier length to prevent DoS
     */
    private const MAX_IDENTIFIER_LENGTH = 255;

    /**
     * Reserved Cypher keywords that cannot be used as identifiers
     */
    private const RESERVED_KEYWORDS = [
        'ALL', 'AND', 'AS', 'ASC', 'ASCENDING', 'BY', 'CALL', 'CASE', 'CONTAINS',
        'CREATE', 'DELETE', 'DESC', 'DESCENDING', 'DETACH', 'DISTINCT', 'ELSE',
        'END', 'ENDS', 'EXISTS', 'FALSE', 'FIELDTERMINATOR', 'IN', 'IS', 'LIMIT',
        'MATCH', 'MERGE', 'NOT', 'NULL', 'ON', 'OPTIONAL', 'OR', 'ORDER',
        'REMOVE', 'RETURN', 'SET', 'SKIP', 'STARTS', 'THEN', 'TRUE', 'UNION',
        'UNIQUE', 'UNWIND', 'WHEN', 'WHERE', 'WITH', 'XOR', 'YIELD'
    ];

    /**
     * Validate and sanitize a label name
     *
     * @param string $label The label to validate
     * @return string The validated label
     * @throws CypherInjectionException If label is invalid
     */
    public static function validateLabel(string $label): string
    {
        return self::validateIdentifier($label, 'label');
    }

    /**
     * Validate and sanitize a relationship type
     *
     * @param string $type The relationship type to validate
     * @return string The validated type
     * @throws CypherInjectionException If type is invalid
     */
    public static function validateRelationshipType(string $type): string
    {
        return self::validateIdentifier($type, 'relationship type');
    }

    /**
     * Validate and sanitize a property key
     *
     * @param string $key The property key to validate
     * @return string The validated key
     * @throws CypherInjectionException If key is invalid
     */
    public static function validatePropertyKey(string $key): string
    {
        return self::validateIdentifier($key, 'property key');
    }

    /**
     * Validate identifier (label, type, or property key)
     *
     * @param string $identifier The identifier to validate
     * @param string $type The type of identifier (for error messages)
     * @return string The validated identifier
     * @throws CypherInjectionException If identifier is invalid
     */
    private static function validateIdentifier(string $identifier, string $type): string
    {
        // Check for empty identifier
        if (empty($identifier)) {
            throw new CypherInjectionException(
                "Invalid {$type}: cannot be empty"
            );
        }

        // Check length
        if (strlen($identifier) > self::MAX_IDENTIFIER_LENGTH) {
            throw new CypherInjectionException(
                "Invalid {$type}: exceeds maximum length of " . self::MAX_IDENTIFIER_LENGTH
            );
        }

        // Check pattern
        if (!preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
            throw new CypherInjectionException(
                "Invalid {$type} '{$identifier}': must contain only alphanumeric characters and underscores, " .
                "and must start with a letter or underscore"
            );
        }

        // Check reserved keywords
        if (in_array(strtoupper($identifier), self::RESERVED_KEYWORDS, true)) {
            throw new CypherInjectionException(
                "Invalid {$type} '{$identifier}': cannot use reserved Cypher keyword"
            );
        }

        return $identifier;
    }

    /**
     * Validate multiple identifiers (useful for batch operations)
     *
     * @param array $identifiers Array of identifiers
     * @param string $type The type of identifier
     * @return array The validated identifiers
     * @throws CypherInjectionException If any identifier is invalid
     */
    public static function validateIdentifiers(array $identifiers, string $type): array
    {
        $validated = [];
        foreach ($identifiers as $identifier) {
            $validated[] = self::validateIdentifier($identifier, $type);
        }
        return $validated;
    }

    /**
     * Escape a label for safe interpolation with backtick quoting
     *
     * Even after validation, use backtick quoting as defense-in-depth
     *
     * @param string $label The validated label
     * @return string The escaped label
     */
    public static function escapeLabel(string $label): string
    {
        // Validate first
        $label = self::validateLabel($label);

        // Escape backticks in label (very rare but possible)
        $escaped = str_replace('`', '``', $label);

        // Return with backtick quoting
        return "`{$escaped}`";
    }

    /**
     * Escape a relationship type for safe interpolation
     *
     * @param string $type The validated type
     * @return string The escaped type
     */
    public static function escapeRelationshipType(string $type): string
    {
        // Validate first
        $type = self::validateRelationshipType($type);

        // Escape backticks
        $escaped = str_replace('`', '``', $type);

        // Return with backtick quoting
        return "`{$escaped}`";
    }
}
