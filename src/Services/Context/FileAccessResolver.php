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

        // Invoke the configured access scope on the CONFIGURED File model.
        // Using config('ai.file_context.file_model') (e.g. App\Models\File) keeps the
        // authorization model and the indexed model the same class. The scope method
        // name is config-driven (default 'accessibleBy') so hosts can wire their own.
        $scope = config('ai.file_context.access_scope', 'accessibleBy');
        if (is_string($scope) && $scope !== '') {
            try {
                return $this->fileModelQuery()->{$scope}($user)->pluck('id')->toArray();
            } catch (\Throwable $e) {
                \Log::debug("FileAccessResolver: access scope '{$scope}' failed, using fallback", [
                    'error' => $e->getMessage(),
                    'user_id' => $user->id ?? null,
                ]);
            }
        }

        // Fallback: use user_id and optional team_id filtering
        return $this->getFallbackAccessibleFileIds($user);
    }

    /**
     * Enumerate the user's accessible files as lightweight descriptors.
     *
     * ACL-respecting by construction: the candidate id set is exactly what
     * getAccessibleFileIds() returns (closure resolver / configured scope /
     * fallback user+team filter), so a user can never enumerate a file they may
     * not access. The descriptors (id, name, type) are then loaded from the
     * configured File model for those ids only.
     *
     * Returns an empty list (never throws) when there is no user, no accessible
     * ids, or the File model cannot be queried — fail-closed.
     *
     * @param mixed $user
     * @param int|null $limit Optional cap on the number of descriptors returned
     * @return array<int,array{file_id:int|string,name:string,type:string}>
     */
    public function enumerateAccessibleFiles(mixed $user, ?int $limit = null): array
    {
        // With security enabled and no user there is no DB file access at all.
        if ($this->shouldEnforceSecurity() && $user === null) {
            return [];
        }

        $accessibleIds = $this->getAccessibleFileIds($user);
        if (empty($accessibleIds)) {
            return [];
        }

        try {
            $query = $this->fileModelQuery()->whereIn('id', $accessibleIds);

            if ($limit !== null && $limit > 0) {
                $query->limit($limit);
            }

            $files = $query->get();
        } catch (\Throwable $e) {
            \Log::debug('FileAccessResolver: enumerateAccessibleFiles query failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null,
            ]);

            return [];
        }

        $descriptors = [];
        foreach ($files as $file) {
            $descriptors[] = [
                'file_id' => $file->id,
                'name' => (string) ($file->name ?? ''),
                'type' => $this->resolveFileType($file),
            ];
        }

        return $descriptors;
    }

    /**
     * Derive a human-meaningful file type for enumeration output.
     *
     * Prefers an explicit extension/mime if the model exposes one, otherwise
     * falls back to the filename extension. Returns '' when nothing is available
     * rather than guessing.
     *
     * @param mixed $file An Eloquent File model instance
     */
    protected function resolveFileType(mixed $file): string
    {
        $extension = $file->extension ?? null;
        if (is_string($extension) && $extension !== '') {
            return strtolower(ltrim($extension, '.'));
        }

        $mime = $file->mime_type ?? null;
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }

        $name = $file->name ?? null;
        if (is_string($name) && $name !== '') {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            if (is_string($ext) && $ext !== '') {
                return strtolower($ext);
            }
        }

        return '';
    }

    /**
     * Fallback method when accessibleBy scope is not available
     *
     * @param mixed $user
     * @return array<int|string>
     */
    protected function getFallbackAccessibleFileIds(mixed $user): array
    {
        $query = $this->fileModelQuery();
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
     * Resolve a query builder for the configured File model.
     *
     * Prefers config('ai.file_context.file_model') (e.g. App\Models\File) so the
     * authorization scope (accessibleBy / scopeAccessibleBy) runs against the same
     * class that is indexed. Falls back to the condoedge/utils FileModel facade
     * for backwards compatibility when no model class is configured.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function fileModelQuery()
    {
        $modelClass = config('ai.file_context.file_model');

        if (is_string($modelClass) && $modelClass !== '' && class_exists($modelClass)) {
            return $modelClass::query();
        }

        return FileModel::query();
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
        $accessMethod = null;
        $granted = false;

        try {
            // Physical files are always accessible
            if ($this->isPhysicalFile($fileId)) {
                $granted = true;
                $accessMethod = 'physical';
                return true;
            }

            // If security is disabled, all files are accessible
            if (!$this->shouldEnforceSecurity()) {
                $granted = true;
                $accessMethod = 'security_disabled';
                return true;
            }

            // No user means no database file access
            if ($user === null) {
                $granted = false;
                $accessMethod = 'no_user';
                return false;
            }

            // Check if file is in accessible list
            $accessibleIds = $this->getAccessibleFileIds($user);
            $granted = in_array($fileId, $accessibleIds, false);
            $accessMethod = 'access_list';

            return $granted;
        } finally {
            // Log all access attempts (non-blocking)
            if (config('ai.file_context.log_access', true)) {
                try {
                    \Condoedge\Ai\Models\AiFileAccessLog::log(
                        $user,
                        $fileId,
                        $granted,
                        $accessMethod
                    );
                } catch (\Exception $e) {
                    \Log::debug('Failed to log file access', ['error' => $e->getMessage()]);
                }
            }
        }
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
