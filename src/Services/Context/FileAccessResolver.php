<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Utils\Facades\FileModel;

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
 * - ai.file_context.fallback_filters: user_id/team_id filter config for fallback
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
     * 2. File model with accessibleBy scope
     * 3. Fallback: user_id + optional team_id filtering
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

        // Try accessibleBy macro (registered via FileAccessScopePlugin)
        try {
            return FileModel::query()->accessibleBy($user)->pluck('id')->toArray();
        } catch (\Throwable $e) {
            \Log::debug("FileAccessResolver: accessibleBy macro failed, using fallback", [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null,
            ]);
        }

        // Fallback: use user_id and optional team_id filtering
        return $this->getFallbackAccessibleFileIds($user);
    }

    /**
     * Fallback method when accessibleBy scope is not available
     *
     * @param mixed $user
     * @return array<int|string>
     */
    protected function getFallbackAccessibleFileIds(mixed $user): array
    {
        $query = FileModel::query();
        $userId = $user->id ?? $user->getKey();
        $useUserFilter = config('ai.file_context.fallback_filters.use_user_filter', true);
        $useTeamFilter = config('ai.file_context.fallback_filters.use_team_filter', true);
        $teamId = $useTeamFilter ? safeCurrentTeamId() : null;

        // If no filters enabled, return empty for security
        if (!$useUserFilter && !$useTeamFilter) {
            \Log::warning('FileAccessResolver: No fallback filters enabled, returning empty');
            return [];
        }

        // Use OR logic: user_id matches OR team_id matches
        $query->where(function ($q) use ($userId, $teamId, $useUserFilter, $useTeamFilter) {
            if ($useUserFilter) {
                $q->where('user_id', $userId);
            }

            if ($useTeamFilter && $teamId) {
                $q->orWhere('team_id', $teamId);
            }
        });

        return $query->pluck('id')->toArray();
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
