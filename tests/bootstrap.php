<?php

/**
 * PHPUnit Bootstrap File
 *
 * Loads Composer autoloader and sets up test environment
 */

$autoloader = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoloader)) {
    echo "Composer autoloader not found. Please run: composer install\n";
    exit(1);
}

require $autoloader;

// Load environment variables from phpunit.xml
// These are accessible via getenv() or $_ENV

/**
 * Helper function to load config for tests
 */
function config(string $key, $default = null)
{
    static $config = null;

    // Helper to get env var - prioritize $_ENV (set by PHPUnit)
    $getEnv = function($key, $default = null) {
        // Check $_ENV first (PHPUnit sets this)
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }
        // Then try getenv()
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        return $default;
    };

    if ($config === null) {
        $config = [
            'ai' => [
                'neo4j' => [
                    'uri' => $getEnv('NEO4J_URI', 'bolt://localhost:7687'),
                    'username' => $getEnv('NEO4J_USERNAME', 'neo4j'),
                    'password' => $getEnv('NEO4J_PASSWORD', 'password'),
                    'database' => $getEnv('NEO4J_DATABASE', 'neo4j'),
                    'enabled' => $getEnv('NEO4J_ENABLED') === 'true',
                ],
                'qdrant' => [
                    'host' => $getEnv('QDRANT_HOST', 'localhost'),
                    'port' => (int) $getEnv('QDRANT_PORT', 6333),
                    'api_key' => $getEnv('QDRANT_API_KEY'),
                    'timeout' => (int) $getEnv('QDRANT_TIMEOUT', 30),
                    'enabled' => $getEnv('QDRANT_ENABLED') === 'true',
                ],
            ],
        ];
    }

    $keys = explode('.', $key);
    $value = $config;

    foreach ($keys as $k) {
        if (!isset($value[$k])) {
            return $default;
        }
        $value = $value[$k];
    }

    return $value;
}

echo "Test environment initialized.\n";
