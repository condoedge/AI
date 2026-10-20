<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security\Scope;

/**
 * A ready-to-splice tenant filter for ONE node binding.
 *
 * The clause already references the bound parameter `$ai_team_ids` (never an
 * inline `IN [...]` literal); `parameters` carries the validated integer IDs the
 * executor merges into the query parameters.
 */
final class FilterClause
{
    /**
     * @param string $kind 'where' (direct column predicate) or 'match'
     *                      (relationship / parent traversal that prepends a MATCH).
     * @param string $clause e.g. 'i.team_id IN $ai_team_ids' (kind=where, WHERE-stripped)
     *                       or 'MATCH (i)-[:BELONGS_TO]->(t:Team) WHERE t.id IN $ai_team_ids'
     *                       (kind=match, spliced verbatim before the anchor).
     * @param array<string, mixed> $parameters e.g. ['ai_team_ids' => [1, 2]].
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $clause,
        public readonly array $parameters
    ) {
    }
}
