<?php

declare(strict_types=1);

namespace Condoedge\Ai\GraphStore;

use Condoedge\Ai\Contracts\GraphStoreInterface;

/**
 * Factory for creating the appropriate Neo4j store based on URI scheme.
 *
 * Automatically selects between Bolt and HTTP implementations:
 * - Bolt (recommended): bolt://, neo4j://, neo4j+s://, neo4j+ssc://
 * - HTTP (legacy): http://, https://
 *
 * Usage:
 * ```php
 * // Auto-detect from config
 * $store = Neo4jStoreFactory::create();
 *
 * // With custom config
 * $store = Neo4jStoreFactory::create([
 *     'uri' => 'bolt://localhost:7687',
 *     'username' => 'neo4j',
 *     'password' => 'secret',
 * ]);
 * ```
 *
 * @see BoltNeo4jStore For Bolt protocol implementation
 * @see Neo4jStore For HTTP API implementation
 */
class Neo4jStoreFactory
{
    /**
     * Bolt-compatible URI schemes (use BoltNeo4jStore)
     */
    private const BOLT_SCHEMES = ['bolt', 'neo4j', 'neo4j+s', 'neo4j+ssc'];

    /**
     * HTTP-compatible URI schemes (use Neo4jStore)
     */
    private const HTTP_SCHEMES = ['http', 'https'];

    /**
     * Create a Neo4j store instance based on the URI scheme.
     *
     * @param array|null $config Configuration array with 'uri', 'username', 'password', etc.
     *                          If null, reads from config('ai.graph.neo4j')
     * @return GraphStoreInterface The appropriate Neo4j store implementation
     * @throws \InvalidArgumentException If the URI scheme is not supported
     */
    public static function create(?array $config = null): GraphStoreInterface
    {
        $config = $config ?? config('ai.graph.neo4j');
        $uri = $config['uri'] ?? 'bolt://localhost:7687';
        $scheme = self::extractScheme($uri);

        return match (true) {
            in_array($scheme, self::BOLT_SCHEMES, true) => new BoltNeo4jStore($config),
            in_array($scheme, self::HTTP_SCHEMES, true) => new Neo4jStore($config),
            default => throw new \InvalidArgumentException(
                "Unsupported Neo4j URI scheme: '{$scheme}'. " .
                "Supported schemes: " . implode(', ', [...self::BOLT_SCHEMES, ...self::HTTP_SCHEMES])
            ),
        };
    }

    /**
     * Check if a URI would use the Bolt driver.
     *
     * @param string $uri The Neo4j connection URI
     * @return bool True if Bolt driver would be used
     */
    public static function isBoltUri(string $uri): bool
    {
        return in_array(self::extractScheme($uri), self::BOLT_SCHEMES, true);
    }

    /**
     * Check if a URI would use the HTTP driver.
     *
     * @param string $uri The Neo4j connection URI
     * @return bool True if HTTP driver would be used
     */
    public static function isHttpUri(string $uri): bool
    {
        return in_array(self::extractScheme($uri), self::HTTP_SCHEMES, true);
    }

    /**
     * Get the list of supported URI schemes.
     *
     * @return array List of all supported schemes
     */
    public static function getSupportedSchemes(): array
    {
        return [...self::BOLT_SCHEMES, ...self::HTTP_SCHEMES];
    }

    /**
     * Extract the scheme from a URI, handling edge cases.
     *
     * @param string $uri The URI to parse
     * @return string The scheme (lowercase), defaults to 'bolt' if not found
     */
    private static function extractScheme(string $uri): string
    {
        $scheme = parse_url($uri, PHP_URL_SCHEME);

        if ($scheme === null || $scheme === false) {
            return 'bolt';
        }

        return strtolower($scheme);
    }
}
