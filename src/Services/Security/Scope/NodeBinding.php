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
        public readonly string $label,
        /**
         * Whether the variable was introduced by OPTIONAL MATCH (it may be null
         * at the anchor). The injector must scope it NULL-TOLERANTLY — a bare
         * mandatory `MATCH (alias)…` or unguarded predicate on a null variable
         * eliminates every row, destroying OPTIONAL semantics. A non-null value
         * is STILL team-scoped (`alias IS NULL OR <scope>`), never skipped.
         *
         * Conservative direction: mis-marking a never-null variable as optional
         * only adds a dead `IS NULL` disjunct (scope still enforced); the flag
         * is therefore derived from the clause that introduces the binding.
         */
        public readonly bool $optional = false,
        /**
         * OPTIONAL bindings only — the binding's OWN OPTIONAL MATCH clause.
         * Cypher attaches a WHERE to the clause directly before it, and a WHERE
         * on an OPTIONAL MATCH only decides whether THAT pattern matches; it
         * never filters variables bound by EARLIER clauses. The null-tolerant
         * scope must therefore be spliced into the WHERE of the clause that
         * binds the variable (security review 2026-10-01, P1).
         *
         * Byte offset of the first clause-level token after the clause's
         * pattern (its own WHERE keyword, or the next clause head / anchor):
         * where a fresh WHERE is inserted. -1 = unknown (the injector then
         * falls back to a strictly-filtering row-level `WITH * WHERE`).
         */
        public readonly int $clauseInsertOffset = -1,
        /** Byte offset of the clause's own WHERE keyword (-1 if none). */
        public readonly int $clauseWhereOffset = -1,
        /** Byte offset just past the clause's own WHERE predicate (-1 if none). */
        public readonly int $clauseWhereEndOffset = -1
    ) {
    }
}
