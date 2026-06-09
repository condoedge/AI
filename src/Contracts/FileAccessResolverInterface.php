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

    /**
     * Enumerate the user's accessible files as lightweight descriptors.
     *
     * Goes through the SAME access resolution as getAccessibleFileIds (closure
     * resolver / scope / fallback team+user filter), then loads id/name/type for
     * each accessible file. This is the ACL-respecting source for answering
     * "which files do I have access to?" — it never returns a file the user may
     * not access. Physical/documentation files are not enumerated here (they are
     * surfaced via semantic retrieval, not the per-user file table).
     *
     * @param mixed $user The user to enumerate accessible files for
     * @param int|null $limit Optional cap on the number of descriptors returned
     * @return array<int,array{file_id:int|string,name:string,type:string}>
     */
    public function enumerateAccessibleFiles(mixed $user, ?int $limit = null): array;
}
