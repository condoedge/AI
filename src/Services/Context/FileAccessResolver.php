<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;

/**
 * File Access Resolver
 *
 * Resolves file access permissions for the AI context system.
 * Supports both physical files (documentation) and database-backed files.
 *
 * Physical files are identified by the 'physical:' prefix and always bypass
 * security checks. Database files are subject to the configured security rules.
 *
 * Configuration options (in config/ai.php):
 * - ai.file_context.security_enabled: Enable/disable security enforcement
 * - ai.file_context.access_resolver: Closure that returns accessible file IDs
 * - ai.file_context.file_model: Eloquent model class for database files
 * - ai.file_context.access_scope: Scope method name to call on the model
 *
 * @package Condoedge\Ai\Services\Context
 */
class FileAccessResolver implements FileAccessResolverInterface
{
    /**
     * Prefix used to identify physical file IDs
     */
    public const PHYSICAL_PREFIX = 'physical:';

    /**
     * Check if security enforcement is enabled
     *
     * @return bool
     */
    public function shouldEnforceSecurity(): bool
    {
        return (bool) config('ai.file_context.security_enabled', true);
    }

    /**
     * Get all file IDs accessible by the given user
     *
     * Priority:
     * 1. Config closure resolver (ai.file_context.access_resolver)
     * 2. File model with scope (ai.file_context.file_model + access_scope)
     *
     * @param mixed $user
     * @return array<int|string>
     */
    public function getAccessibleFileIds(mixed $user): array
    {
        // No user means no database file access when security is enabled
        if ($user === null) {
            return [];
        }

        // Check for closure-based resolver first (takes precedence)
        $resolver = config('ai.file_context.access_resolver');
        if ($resolver instanceof \Closure) {
            return $resolver($user);
        }

        // Fall back to file model with scope
        $fileModel = config('ai.file_context.file_model');
        $accessScope = config('ai.file_context.access_scope', 'accessibleBy');

        if ($fileModel && class_exists($fileModel)) {
            try {
                return $fileModel::$accessScope($user)->pluck('id')->toArray();
            } catch (\Throwable) {
                // If scope fails, return empty array
                return [];
            }
        }

        return [];
    }

    /**
     * Filter a list of file IDs to only include accessible ones
     *
     * @param array<int|string> $fileIds
     * @param mixed $user
     * @return array<int|string>
     */
    public function filterAccessibleFileIds(array $fileIds, mixed $user): array
    {
        // If security is disabled, return all files
        if (!$this->shouldEnforceSecurity()) {
            return $fileIds;
        }

        // Separate physical and database files
        $physicalFiles = [];
        $databaseFiles = [];

        foreach ($fileIds as $fileId) {
            if ($this->isPhysicalFile($fileId)) {
                $physicalFiles[] = $fileId;
            } else {
                $databaseFiles[] = $fileId;
            }
        }

        // Get accessible database file IDs
        $accessibleDbIds = $this->getAccessibleFileIds($user);

        // Filter database files based on access
        $accessibleDatabaseFiles = array_values(
            array_filter($databaseFiles, function ($id) use ($accessibleDbIds) {
                return in_array($id, $accessibleDbIds, false);
            })
        );

        // Rebuild array maintaining relative order from original input
        $result = [];
        foreach ($fileIds as $fileId) {
            if ($this->isPhysicalFile($fileId)) {
                $result[] = $fileId;
            } elseif (in_array($fileId, $accessibleDatabaseFiles, false)) {
                $result[] = $fileId;
            }
        }

        return $result;
    }

    /**
     * Check if a specific file is accessible by the user
     *
     * @param int|string $fileId
     * @param mixed $user
     * @return bool
     */
    public function canAccessFile(int|string $fileId, mixed $user): bool
    {
        // Physical files are always accessible
        if ($this->isPhysicalFile($fileId)) {
            return true;
        }

        // If security is disabled, all files are accessible
        if (!$this->shouldEnforceSecurity()) {
            return true;
        }

        // No user means no database file access
        if ($user === null) {
            return false;
        }

        // Check if file is in accessible list
        $accessibleIds = $this->getAccessibleFileIds($user);

        return in_array($fileId, $accessibleIds, false);
    }

    /**
     * Check if a file ID represents a physical file
     *
     * @param int|string $fileId
     * @return bool
     */
    public function isPhysicalFile(int|string $fileId): bool
    {
        if (!is_string($fileId)) {
            return false;
        }

        return str_starts_with($fileId, self::PHYSICAL_PREFIX);
    }

    /**
     * Create a physical file ID from a path
     *
     * @param string $path
     * @return string
     */
    public function makePhysicalFileId(string $path): string
    {
        return self::PHYSICAL_PREFIX . $path;
    }

    /**
     * Extract the file path from a physical file ID
     *
     * @param int|string $fileId
     * @return string|null The path, or null if not a physical file ID
     */
    public function getPhysicalFilePath(int|string $fileId): ?string
    {
        if (!$this->isPhysicalFile($fileId)) {
            return null;
        }

        return substr((string) $fileId, strlen(self::PHYSICAL_PREFIX));
    }
}
