<?php

namespace AiSystem\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base Test Case
 *
 * Provides common functionality for all tests
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Assert array has keys
     */
    protected function assertArrayHasKeys(array $keys, array $array, string $message = ''): void
    {
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $array, $message ?: "Array missing key: {$key}");
        }
    }

    /**
     * Create a temporary test collection/database name
     */
    protected function getTestCollectionName(string $prefix = 'test'): string
    {
        return $prefix . '_' . uniqid() . '_' . time();
    }
}
