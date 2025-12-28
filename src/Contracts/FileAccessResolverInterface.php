<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * File Access Resolver Interface
 *
 * Defines contract for resolving file access permissions in the AI context system.
 * Supports both physical files (documentation) and database-backed files with
 * configurable security enforcement.
 *
 * The resolver distinguishes between:
 * - Physical files: Prefixed with 'physical:', always accessible (no security checks)
 * - Database files: Regular IDs, subject to security checks via config resolver/scope
 *
 * @package Condoedge\Ai\Contracts
 */
interface FileAccessResolverInterface
{
    /**
     * Check if security enforcement is enabled
     *
     * When disabled, all files are accessible without checking permissions.
     * When enabled, database files are filtered based on user access.
     *
     * @return bool True if security is enabled, false otherwise
     */
    public function shouldEnforceSecurity(): bool;

    /**
     * Get all file IDs accessible by the given user
     *
     * Uses the configured access resolver (closure) or file model scope
     * to determine which files the user can access.
     *
     * @param mixed $user The user to check access for (typically Authenticatable)
     * @return array<int|string> Array of accessible file IDs
     */
    public function getAccessibleFileIds(mixed $user): array;

    /**
     * Filter a list of file IDs to only include those accessible by the user
     *
     * Physical files (prefixed with 'physical:') always pass through.
     * Database files are checked against the user's permissions.
     *
     * @param array<int|string> $fileIds Array of file IDs to filter
     * @param mixed $user The user to check access for
     * @return array<int|string> Filtered array of accessible file IDs
     */
    public function filterAccessibleFileIds(array $fileIds, mixed $user): array;

    /**
     * Check if a specific file is accessible by the user
     *
     * Physical files are always accessible.
     * Database files require permission check when security is enabled.
     *
     * @param int|string $fileId The file ID to check
     * @param mixed $user The user to check access for
     * @return bool True if accessible, false otherwise
     */
    public function canAccessFile(int|string $fileId, mixed $user): bool;
}
