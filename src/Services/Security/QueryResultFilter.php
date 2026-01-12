<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * QueryResultFilter
 *
 * Server-side filter for query results based on user access levels.
 * This provides defense-in-depth beyond LLM prompt-level access control.
 *
 * The key insight is that access restrictions in prompts can be bypassed
 * via prompt injection - this adds a second layer of protection at the data level.
 *
 * @package Condoedge\Ai\Services\Security
 */
class QueryResultFilter
{
    public function __construct(
        protected AccessLevelResolver $resolver
    ) {}

    /**
     * Filter query results based on user access level
     *
     * @param array $results Query results to filter
     * @param string $entityType Entity type for access lookup
     * @param Authenticatable|null $user User to check access for
     * @return array Filtered results
     */
    public function filterResults(array $results, string $entityType, ?Authenticatable $user): array
    {
        if (empty($results)) {
            return $results;
        }

        // Get sensitive columns for this entity
        $context = $this->resolver->buildContextForEntity($user, $entityType);
        $sensitiveColumns = $context['sensible_columns'];

        // Check if user has sensitive access
        $hasSensitiveAccess = $this->resolver->hasAccessLevel(
            $user,
            $entityType,
            'team_sensitive'
        );

        // If user has sensitive access, return unfiltered
        if ($hasSensitiveAccess) {
            return $results;
        }

        // Filter out sensitive columns from each result
        return array_map(function ($row) use ($sensitiveColumns) {
            return $this->filterRow($row, $sensitiveColumns);
        }, $results);
    }

    /**
     * Filter sensitive columns from a single row
     *
     * @param array $row Single result row
     * @param array $sensitiveColumns Columns to remove
     * @return array Filtered row
     */
    protected function filterRow(array $row, array $sensitiveColumns): array
    {
        foreach ($sensitiveColumns as $column) {
            unset($row[$column]);

            // Also check for nested versions (e.g., 'employee.salary')
            foreach ($row as $key => $value) {
                if (str_ends_with($key, ".{$column}")) {
                    unset($row[$key]);
                }
            }
        }

        return $row;
    }

    /**
     * Apply count threshold protection
     *
     * Threshold protects against identifying individuals through specific counts.
     * If a filtered count is below the threshold, we say "fewer than N" instead.
     *
     * @param int $count The actual count
     * @param string $entityType Entity type for threshold lookup
     * @return string|int Protected count string or actual count
     */
    public function applyCountThreshold(int $count, string $entityType): string|int
    {
        $threshold = $this->resolver->getThreshold($entityType);

        if ($count > 0 && $count < $threshold) {
            return "fewer than {$threshold}";
        }

        return $count;
    }
}
