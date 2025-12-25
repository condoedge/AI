<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;

/**
 * TeamFilteredQuery
 *
 * Builds team-filtered queries for Neo4j and Qdrant.
 * Enables native database-level security filtering rather than post-processing.
 *
 * Usage:
 *   $filter = new TeamFilteredQuery([1, 2, 3]);
 *   $qdrantFilter = $filter->toQdrantFilter();
 *   $cypherClause = $filter->toCypherWhereClause('n');
 *
 * @package Condoedge\Ai\Services\Security
 */
class TeamFilteredQuery
{
    protected array $teamIds;
    protected ?int $ownerId;

    /**
     * @param array $teamIds Team IDs the user has access to
     * @param int|null $ownerId Optional owner ID for owner-bypass support
     */
    public function __construct(array $teamIds, ?int $ownerId = null)
    {
        $this->teamIds = array_map('intval', array_filter($teamIds));
        $this->ownerId = $ownerId;
    }

    /**
     * Build Qdrant filter for team-based access
     *
     * Returns a filter structure compatible with Qdrant's filter syntax.
     * Uses 'should' (OR) when both team and owner filters exist.
     *
     * @return array Qdrant filter structure
     */
    public function toQdrantFilter(): array
    {
        if (empty($this->teamIds) && !$this->ownerId) {
            return []; // No restrictions
        }

        $conditions = [];

        if (!empty($this->teamIds)) {
            $conditions[] = [
                'key' => '_team_ids',
                'match' => ['any' => $this->teamIds],
            ];
        }

        if ($this->ownerId) {
            $conditions[] = [
                'key' => '_owner_id',
                'match' => ['value' => $this->ownerId],
            ];
        }

        // If we have both, user can access either (OR)
        if (count($conditions) > 1) {
            return ['should' => $conditions];
        }

        return ['must' => $conditions];
    }

    /**
     * Build Cypher WHERE clause for team-based access
     *
     * Generates a Cypher clause that filters nodes by their BELONGS_TO_TEAM relationships.
     *
     * @param string $nodeAlias The alias of the node in the Cypher query (e.g., 'n')
     * @return string Cypher WHERE clause or empty string if no filter
     */
    public function toCypherWhereClause(string $nodeAlias): string
    {
        if (empty($this->teamIds) && !$this->ownerId) {
            return ''; // No restrictions
        }

        $clauses = [];

        if (!empty($this->teamIds)) {
            $clauses[] = "({$nodeAlias})-[:BELONGS_TO_TEAM]->(t:Team) WHERE t.id IN \$teamIds";
        }

        if ($this->ownerId) {
            $clauses[] = "{$nodeAlias}._owner_id = \$ownerId";
        }

        return implode(' OR ', $clauses);
    }

    /**
     * Build full Cypher MATCH clause with team filtering
     *
     * @param string $label Node label to match
     * @param string $nodeAlias Node alias (default 'n')
     * @return string Complete MATCH...WHERE clause
     */
    public function toCypherMatchClause(string $label, string $nodeAlias = 'n'): string
    {
        $match = "MATCH ({$nodeAlias}:{$label})";

        if (!empty($this->teamIds)) {
            $match .= "-[:BELONGS_TO_TEAM]->(t:Team)";
            $match .= " WHERE t.id IN \$teamIds";
        }

        return $match;
    }

    /**
     * Get count from Neo4j with team filter
     *
     * @param GraphStoreInterface $graph Graph store instance
     * @param string $label Node label to count
     * @param array $filters Additional property filters
     * @return int Count of matching nodes
     */
    public function countInNeo4j(GraphStoreInterface $graph, string $label, array $filters = []): int
    {
        $cypher = "MATCH (n:{$label})";

        if (!empty($this->teamIds)) {
            $cypher .= "-[:BELONGS_TO_TEAM]->(t:Team) WHERE t.id IN \$teamIds";
        }

        // Add additional filters
        if (!empty($filters)) {
            $filterClauses = [];
            foreach ($filters as $field => $value) {
                $filterClauses[] = "n.{$field} = \${$field}";
            }
            $cypher .= (empty($this->teamIds) ? ' WHERE ' : ' AND ') . implode(' AND ', $filterClauses);
        }

        $cypher .= " RETURN count(DISTINCT n) as count";

        $params = array_merge(
            ['teamIds' => $this->teamIds],
            $filters
        );

        $result = $graph->query($cypher, $params);

        return $result[0]['count'] ?? 0;
    }

    /**
     * Search Qdrant with team filter
     *
     * @param VectorStoreInterface $vectorStore Vector store instance
     * @param string $collection Collection name
     * @param array $vector Query vector
     * @param int $limit Maximum results to return
     * @return array Search results
     */
    public function searchQdrant(
        VectorStoreInterface $vectorStore,
        string $collection,
        array $vector,
        int $limit = 10
    ): array {
        return $vectorStore->search(
            $collection,
            $vector,
            $limit,
            $this->toQdrantFilter()
        );
    }

    /**
     * Get global count (no team filtering)
     *
     * @param GraphStoreInterface $graph Graph store instance
     * @param string $label Node label to count
     * @return int Total count
     */
    public static function globalCount(GraphStoreInterface $graph, string $label): int
    {
        $cypher = "MATCH (n:{$label}) RETURN count(n) as count";
        $result = $graph->query($cypher, []);
        return $result[0]['count'] ?? 0;
    }

    /**
     * Apply threshold protection to a count
     *
     * If the count is below the threshold, returns a protected message.
     *
     * @param int $count The actual count
     * @param int $threshold Minimum count to reveal exact number
     * @return string|int The count or a protected message
     */
    public static function applyThreshold(int $count, int $threshold): string|int
    {
        if ($count < $threshold && $count > 0) {
            return "fewer than {$threshold}";
        }

        return $count;
    }

    /**
     * Get team IDs
     *
     * @return array
     */
    public function getTeamIds(): array
    {
        return $this->teamIds;
    }

    /**
     * Get owner ID
     *
     * @return int|null
     */
    public function getOwnerId(): ?int
    {
        return $this->ownerId;
    }

    /**
     * Check if any filters are applied
     *
     * @return bool True if filtering is active
     */
    public function hasFilters(): bool
    {
        return !empty($this->teamIds) || $this->ownerId !== null;
    }
}
