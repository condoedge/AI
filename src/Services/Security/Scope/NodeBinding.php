<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security\Scope;

/**
 * A single labelled node binding that is live at its scope's anchor.
 *
 * Produced by {@see \Condoedge\Ai\Services\Security\CypherScopeAnalyzer}; every
 * NodeBinding in a {@see Scope} is a tenant-sensitive node that MUST be team
 * scoped before the query is allowed to run.
 */
final class NodeBinding
{
    public function __construct(
        /** Variable bound to the node, guaranteed live at the scope anchor (e.g. 'i'). */
        public readonly string $alias,
        /** Node label as written in the pattern (e.g. 'Invoice'). */
        public readonly string $label
    ) {
    }
}
