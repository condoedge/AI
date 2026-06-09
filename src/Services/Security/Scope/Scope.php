<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security\Scope;

/**
 * One independently-scoped region of a query: a top-level part, a UNION branch,
 * or the inner query of the single accepted outer count wrapper.
 *
 * Every sensitive node binding that is live at this scope's anchor is enumerated
 * in {@see $sensitiveBindings}; the injector scopes each of them, so a scope with
 * N bindings yields N AND-combined predicates / MATCH extensions.
 */
final class Scope
{
    /**
     * @param NodeBinding[] $sensitiveBindings EVERY labelled node bound & live at the anchor.
     * @param int $anchorOffset Byte offset of this scope's primary-MATCH boundary
     *                          (first WITH|RETURN|ORDER at the scope's own depth):
     *                          a WHERE/MATCH filter is spliced immediately before it.
     * @param int $whereOffset Byte offset of this scope's own WHERE keyword (-1 if none).
     * @param int $whereEndOffset Byte offset just past the existing WHERE predicate
     *                            (i.e. the anchor offset / next clause start); used to
     *                            wrap a top-level OR predicate in parentheses (-1 if none).
     * @param bool $whereHasTopLevelOr Whether the existing WHERE predicate has a token-depth-0 OR.
     */
    public function __construct(
        public readonly array $sensitiveBindings,
        public readonly int $anchorOffset,
        public readonly int $whereOffset = -1,
        public readonly int $whereEndOffset = -1,
        public readonly bool $whereHasTopLevelOr = false
    ) {
    }
}
