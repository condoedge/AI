<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

/**
 * The ONE authoritative table of Cypher clause heads shared by the scope
 * analyzer and the structural validator (Task 1.26 follow-up: three separate,
 * disagreeing keyword lists — the analyzer's anchor-clause resets, its WHERE
 * predicate terminators and the validator's no-WHERE clauses — let a
 * `yield` word inside an OPTIONAL MATCH's WHERE flip the anchor-clause kind
 * and bypass row-level scoping). Grammar tokens, not semantic vocabulary.
 *
 * Callers still decide POSITION (depth, Token::$keywordAsIdentifier, …); this
 * table only answers "is this word one of these clause heads".
 *
 * TODO: CypherScopeAnalyzer::isClauseKeyword() (node-pattern walk) and
 * looksLikeKeyword() keep their own narrower lists on purpose — widening the
 * walk's stop-set changes which label-free nodes count as MATCHed, a
 * security-relevant decision to revisit with generator-side scoping (Phase 11).
 */
final class CypherClauses
{
    /**
     * Clause heads that END a WHERE predicate / a WITH projection list: the
     * predicate may not extend past one.
     */
    public const CLAUSE_HEADS = [
        'MATCH', 'OPTIONAL', 'WITH', 'RETURN', 'ORDER', 'SKIP', 'LIMIT',
        'CALL', 'UNION', 'CREATE', 'MERGE', 'DELETE', 'SET', 'REMOVE',
        'FOREACH', 'UNWIND',
    ];

    /**
     * Clause heads a WHERE can NEVER directly follow (a bare WHERE there is a
     * SyntaxError; a row-level `WITH *` must be re-opened first). CALL is
     * re-opened for WHERE only by its own YIELD.
     */
    public const NO_WHERE_HEADS = [
        'UNWIND', 'SET', 'REMOVE', 'DELETE', 'CREATE', 'MERGE', 'FOREACH', 'CALL',
    ];

    /** @param list<string> $list */
    public static function in(string $word, array $list): bool
    {
        foreach ($list as $kw) {
            if (strcasecmp($word, $kw) === 0) {
                return true;
            }
        }
        return false;
    }
}
