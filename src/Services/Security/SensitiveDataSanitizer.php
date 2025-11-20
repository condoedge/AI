<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

/**
 * SensitiveDataSanitizer
 *
 * Removes or masks sensitive information from logs, error messages, and exceptions
 * to prevent accidental exposure of API keys, passwords, and other credentials.
 */
class SensitiveDataSanitizer
{
    /**
     * Patterns to match sensitive data
     */
    private const PATTERNS = [
        // API Keys (various formats)
        '/(["\']?api[_-]?key["\']?\s*[:=]\s*["\']?)([a-zA-Z0-9_\-]{20,})/i' => '$1***REDACTED***',
        '/Bearer\s+([a-zA-Z0-9_\-\.]{20,})/' => 'Bearer ***REDACTED***',

        // OpenAI API keys (sk-... format)
        '/sk-[a-zA-Z0-9]{48}/' => 'sk-***REDACTED***',

        // Anthropic API keys
        '/sk-ant-[a-zA-Z0-9\-_]{95,}/' => 'sk-ant-***REDACTED***',

        // Generic tokens
        '/(["\']?token["\']?\s*[:=]\s*["\']?)([a-zA-Z0-9_\-]{20,})/i' => '$1***REDACTED***',

        // Passwords
        '/(["\']?password["\']?\s*[:=]\s*["\']?)([^"\'\s]{3,})/i' => '$1***REDACTED***',
        '/(["\']?passwd["\']?\s*[:=]\s*["\']?)([^"\'\s]{3,})/i' => '$1***REDACTED***',
        '/(["\']?pwd["\']?\s*[:=]\s*["\']?)([^"\'\s]{3,})/i' => '$1***REDACTED***',

        // Database credentials
        '/(["\']?db[_-]?password["\']?\s*[:=]\s*["\']?)([^"\'\s]{3,})/i' => '$1***REDACTED***',

        // AWS credentials
        '/AKIA[0-9A-Z]{16}/' => 'AKIA***REDACTED***',
        '/([A-Za-z0-9+\/]{40})/' => '***REDACTED_AWS_SECRET***',

        // Basic Auth
        '/Authorization:\s*Basic\s+([A-Za-z0-9+\/=]+)/' => 'Authorization: Basic ***REDACTED***',

        // URLs with credentials
        '/\/\/([^:]+):([^@]+)@/' => '//***REDACTED***:***REDACTED***@',
    ];

    /**
     * Keys in arrays/objects that should be redacted
     */
    private const SENSITIVE_KEYS = [
        'api_key',
        'apiKey',
        'apikey',
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'access_token',
        'accessToken',
        'refresh_token',
        'refreshToken',
        'private_key',
        'privateKey',
        'client_secret',
        'clientSecret',
        'db_password',
        'dbPassword',
        'redis_password',
        'redisPassword',
    ];

    /**
     * Sanitize a string by removing sensitive data
     *
     * @param string $input Input string that may contain sensitive data
     * @return string Sanitized string with sensitive data redacted
     */
    public static function sanitizeString(string $input): string
    {
        $output = $input;

        foreach (self::PATTERNS as $pattern => $replacement) {
            $output = preg_replace($pattern, $replacement, $output);
        }

        return $output;
    }

    /**
     * Sanitize an array by redacting sensitive keys
     *
     * @param array $data Array that may contain sensitive data
     * @param int $maxDepth Maximum recursion depth to prevent infinite loops
     * @param int $currentDepth Current recursion depth
     * @return array Sanitized array with sensitive values redacted
     */
    public static function sanitizeArray(array $data, int $maxDepth = 5, int $currentDepth = 0): array
    {
        if ($currentDepth >= $maxDepth) {
            return ['***MAX_DEPTH_REACHED***'];
        }

        $sanitized = [];

        foreach ($data as $key => $value) {
            // Check if key is sensitive
            if (self::isSensitiveKey($key)) {
                $sanitized[$key] = '***REDACTED***';
                continue;
            }

            // Recursively sanitize nested arrays
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArray($value, $maxDepth, $currentDepth + 1);
            }
            // Recursively sanitize objects
            elseif (is_object($value)) {
                $sanitized[$key] = self::sanitizeObject($value, $maxDepth, $currentDepth + 1);
            }
            // Sanitize string values
            elseif (is_string($value)) {
                $sanitized[$key] = self::sanitizeString($value);
            }
            // Keep other values as-is
            else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize an object by converting to array and redacting sensitive data
     *
     * @param object $obj Object that may contain sensitive data
     * @param int $maxDepth Maximum recursion depth
     * @param int $currentDepth Current recursion depth
     * @return array Sanitized object as array
     */
    public static function sanitizeObject(object $obj, int $maxDepth = 5, int $currentDepth = 0): array
    {
        // Convert object to array
        $array = (array) $obj;

        return self::sanitizeArray($array, $maxDepth, $currentDepth);
    }

    /**
     * Sanitize an exception for safe logging
     *
     * Redacts sensitive data from message, file paths, and trace
     *
     * @param \Throwable $exception Exception to sanitize
     * @return array Safe exception data for logging
     */
    public static function sanitizeException(\Throwable $exception): array
    {
        return [
            'class' => get_class($exception),
            'message' => self::sanitizeString($exception->getMessage()),
            'code' => $exception->getCode(),
            'file' => self::sanitizePath($exception->getFile()),
            'line' => $exception->getLine(),
            'trace' => self::sanitizeTrace($exception->getTrace()),
        ];
    }

    /**
     * Sanitize a stack trace
     *
     * @param array $trace Stack trace array
     * @return array Sanitized trace
     */
    private static function sanitizeTrace(array $trace): array
    {
        $sanitized = [];

        foreach (array_slice($trace, 0, 10) as $frame) { // Limit to 10 frames
            $sanitized[] = [
                'file' => isset($frame['file']) ? self::sanitizePath($frame['file']) : 'unknown',
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? 'unknown',
                'class' => $frame['class'] ?? null,
                // DO NOT include args - they may contain sensitive data
            ];
        }

        return $sanitized;
    }

    /**
     * Sanitize file paths by removing absolute paths
     *
     * @param string $path File path
     * @return string Relative path
     */
    private static function sanitizePath(string $path): string
    {
        // Remove absolute path prefixes to avoid leaking directory structure
        $basePath = base_path();
        if (str_starts_with($path, $basePath)) {
            return '...' . substr($path, strlen($basePath));
        }

        return basename($path);
    }

    /**
     * Check if a key name indicates sensitive data
     *
     * @param string|int $key Key name
     * @return bool True if key is sensitive
     */
    private static function isSensitiveKey(string|int $key): bool
    {
        if (!is_string($key)) {
            return false;
        }

        $lowerKey = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (str_contains($lowerKey, strtolower($sensitiveKey))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a safe log context from any data
     *
     * This is the main method to use when logging data that might contain sensitive information
     *
     * @param mixed $data Data to sanitize for logging
     * @return mixed Sanitized data safe for logging
     */
    public static function forLogging(mixed $data): mixed
    {
        if (is_string($data)) {
            return self::sanitizeString($data);
        }

        if (is_array($data)) {
            return self::sanitizeArray($data);
        }

        if (is_object($data)) {
            if ($data instanceof \Throwable) {
                return self::sanitizeException($data);
            }
            return self::sanitizeObject($data);
        }

        // Primitives are safe to log
        return $data;
    }
}
