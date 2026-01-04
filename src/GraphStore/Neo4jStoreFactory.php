<?php

declare(strict_types=1);

namespace Condoedge\Ai\GraphStore;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Illuminate\Support\Facades\Log;

/**
 * Factory for creating the appropriate Neo4j store based on URI scheme.
 *
 * Automatically selects between Bolt and HTTP implementations:
 * - Bolt (recommended): bolt://, neo4j://, neo4j+s://, neo4j+ssc://
 * - HTTP (legacy): http://, https://
 *
 * If Bolt URI is requested but laudis/neo4j-php-client is not installed,
 * falls back to HTTP with a warning.
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
     * If Bolt is requested but not available, falls back to HTTP.
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

        // Check if Bolt is requested
        if (in_array($scheme, self::BOLT_SCHEMES, true)) {
            // Check if Bolt driver is available
            if (self::isBoltDriverAvailable()) {
                return new BoltNeo4jStore($config);
            }

            // Fall back to HTTP with warning
            Log::warning('Neo4j Bolt driver not available (laudis/neo4j-php-client not installed). ' .
                'Falling back to HTTP API. Install the package for better performance: ' .
                'composer require laudis/neo4j-php-client:^3.0');

            // Convert bolt URI to http for fallback
            $config['uri'] = self::convertToHttpUri($uri);
            return new Neo4jStore($config);
        }

        if (in_array($scheme, self::HTTP_SCHEMES, true)) {
            return new Neo4jStore($config);
        }

        throw new \InvalidArgumentException(
            "Unsupported Neo4j URI scheme: '{$scheme}'. " .
            "Supported schemes: " . implode(', ', [...self::BOLT_SCHEMES, ...self::HTTP_SCHEMES])
        );
    }

    /**
     * Check if the Bolt driver (laudis/neo4j-php-client) is available.
     */
    public static function isBoltDriverAvailable(): bool
    {
        return class_exists(\Laudis\Neo4j\ClientBuilder::class);
    }

    /**
     * Convert a Bolt URI to HTTP URI for fallback.
     *
     * @param string $uri The Bolt URI
     * @return string The HTTP URI
     */
    private static function convertToHttpUri(string $uri): string
    {
        $parsed = parse_url($uri);
        $host = $parsed['host'] ?? 'localhost';
        // Bolt uses 7687, HTTP uses 7474
        $port = 7474;
        $scheme = 'http';

        // Use HTTPS for encrypted schemes
        if (in_array($parsed['scheme'] ?? '', ['neo4j+s', 'neo4j+ssc'], true)) {
            $scheme = 'https';
        }

        return "{$scheme}://{$host}:{$port}";
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
