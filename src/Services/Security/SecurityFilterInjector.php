<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Condoedge\Ai\Services\Security\Scope\FilterClause;
use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Services\Security\Scope\Scope;
use Condoedge\Ai\Services\Security\Scope\ScopePlan;

/**
 * Structural tenant-filter splicer.
 *
 * Given a {@see ScopePlan} (already proven scopeable by {@see CypherScopeAnalyzer})
 * and a per-binding filter resolver, it scopes EVERY sensitive binding in EVERY
 * scope using the byte offsets the analyzer recorded — no re-parsing, no regex
 * anchoring. Edits are applied right-to-left by descending offset so earlier
 * offsets stay valid.
 *
 * Each scope is rewritten so the clause order is always valid Cypher:
 *
 *     <existing patterns> [WHERE <direct preds>] <MATCH-extension blocks> <anchor>
 *
 * The combined direct-column predicate is emitted FIRST (merged into the scope's
 * own WHERE, or opened as a single fresh WHERE on the live patterns), and the
 * self-contained relationship MATCH … WHERE … blocks follow. That ordering is the
 * fix for the multi-binding mangle: a relationship block carries its OWN WHERE, so
 * appending a direct WHERE after it produced two adjacent WHEREs (invalid Cypher);
 * leading with the direct WHERE and trailing the MATCH blocks never does.
 *
 * Newly-introduced aliases inside each MATCH block are uniquified so two parent /
 * relationship paths (and the original query) can never collide on a generated
 * variable name.
 *
 * OPTIONAL MATCH (null-tolerant scoping, AiQueryLog 674):
 *   • A binding introduced by OPTIONAL MATCH may be null. A mandatory
 *     `MATCH (i)-[:REL]->(s:Union) WHERE s.team_id IN $ai_team_ids` on a null
 *     variable eliminates EVERY row, so its relationship scope is emitted as
 *     `(i IS NULL OR EXISTS { MATCH (i)-[:REL]->(ai_sN:Union) WHERE … })` and a
 *     direct scope as `(i IS NULL OR i.team_id IN $ai_team_ids)` — spliced into
 *     the WHERE of the OPTIONAL MATCH clause that BINDS i (AND-merged, existing
 *     predicate parenthesized, or a fresh WHERE right after its pattern). There
 *     it filters the optional match: a cross-tenant value becomes null, the row
 *     survives and the value never contributes. It is NEVER placed on another
 *     clause's WHERE: on a LATER OPTIONAL MATCH a WHERE only decides whether
 *     that later pattern matches, so `… OPTIONAL MATCH (x) OPTIONAL MATCH (y)
 *     WHERE <scope x> …` returned every cross-tenant x with y = null (security
 *     review 2026-10-01 P1; live: 3963 of 6006 invoices out of tenant). If the
 *     binding clause is unknown, the scope falls back to a row-level
 *     `WITH * WHERE` (drops a cross-tenant row — strictly filtering).
 *     SECURITY INVARIANT: OPTIONAL variables are scoped null-tolerantly, never
 *     left unscoped.
 *   • When the clause immediately before the anchor is an OPTIONAL MATCH, a
 *     WHERE spliced there belongs to that OPTIONAL MATCH and does NOT constrain
 *     the row — so a MANDATORY binding's direct predicate is instead emitted as
 *     a row-level `WITH * WHERE …` block just before the anchor. (Mandatory
 *     relationship MATCH blocks are row-level by construction and unaffected.)
 *
 * KNOWN REDUNDANCY (documented, intentional): injection is all-or-nothing per
 * scope — when the acceptance gate rejects the query (any ONE binding not
 * self-scoped), the injector re-scopes EVERY binding, including ones the
 * generator already scoped (e.g. a query carrying `(u)-[:UNION]->(u1:Union)
 * WHERE u1.team_id IN $ai_team_ids` still receives a fresh
 * `MATCH (u)-[:UNION]->(ai_sN:Union) …` block). Recognizing semantically
 * equivalent existing scoping would require predicate-level equivalence
 * analysis; the redundant AND-ed filter can never widen results (defence in
 * depth), though a duplicate relationship MATCH can multiply rows when a node
 * has several team edges (not the case for the 1:1 CondoEdge paths).
 *
 * Returns null on any residual ambiguity (a clause it cannot place, an empty
 * filter) OR when the resulting rewrite is not structurally valid, so the caller
 * fails closed rather than running an under-scoped or malformed query.
 */
final class SecurityFilterInjector
{
    private const WHERE_LEN = 5; // strlen('WHERE')

    public function __construct(
        private readonly CypherLexer $lexer = new CypherLexer(),
        private readonly CypherStructuralValidator $validator = new CypherStructuralValidator()
    ) {
    }

    /**
     * @param callable(NodeBinding): ?FilterClause $filterFor Resolves a per-binding
     *        filter (already referencing bound $ai_team_ids). May return null to
     *        signal "this binding needs no filter" (e.g. global-count bypass).
     * @return string|null Rewritten query, or null if any scope cannot be safely rewritten.
     */
    public function inject(string $cypher, ScopePlan $plan, callable $filterFor): ?string
    {
        if (!$plan->isScopeable) {
            return null;
        }

        // Aliases already declared in the source — generated MATCH-block variables
        // must avoid every one of them (and each other) to stay collision-free.
        $reserved = $this->declaredAliases($cypher);
        $aliasCounter = 0;

        /** @var list<array{0:int,1:int,2:string}> $edits */
        $edits = [];

        foreach ($plan->scopes as $scope) {
            if (empty($scope->sensitiveBindings)) {
                continue; // nothing tenant-sensitive in this scope
            }

            $scopeEdits = $this->editsForScope($cypher, $scope, $filterFor, $reserved, $aliasCounter);
            if ($scopeEdits === null) {
                return null; // a binding could not be scoped → fail closed
            }

            foreach ($scopeEdits as $edit) {
                $edits[] = $edit;
            }
        }

        if (empty($edits)) {
            // Plan had sensitive bindings but nothing was injected — guard against
            // silently returning the raw query. The caller treats this like a no-op
            // only when there genuinely were no sensitive bindings (handled above),
            // so reaching here with bindings means every filterFor returned null.
            return $cypher;
        }

        // Edits must be pairwise disjoint (and no two pure inserts may share an
        // offset — their relative order would be undefined). Anything else means
        // two scoping decisions target the same bytes → fail closed.
        if (!$this->editsAreDisjoint($edits)) {
            return null;
        }

        $rewritten = $this->applyEdits($cypher, $edits);

        // Runtime defence-in-depth: a structurally invalid rewrite must NEVER reach
        // Neo4j. Discard it (caller fails closed gracefully) instead of crashing on
        // a raw SyntaxError. The invariant is unaffected: a discarded rewrite simply
        // means no query runs, which cannot leak.
        if (!$this->validator->isValid($rewritten)) {
            return null;
        }

        return $rewritten;
    }

    /**
     * Build the byte edits that scope a single Scope.
     *
     * @param callable(NodeBinding): ?FilterClause $filterFor
     * @param array<string, true> $reserved Aliases already used in the query / earlier inserts.
     * @return list<array{0:int,1:int,2:string}>|null
     */
    private function editsForScope(
        string $cypher,
        Scope $scope,
        callable $filterFor,
        array &$reserved,
        int &$aliasCounter
    ): ?array {
        $whereParts = [];    // MANDATORY predicates AND-combined into the anchor-adjacent WHERE
        $rowWhereParts = []; // predicates that need ROW-level `WITH * WHERE …`
        $matchInserts = [];  // mandatory relationship MATCH ... WHERE blocks before anchor
        /** @var array<int, array{where:int, whereEnd:int, parts:list<string>}> $clauseParts */
        $clauseParts = [];   // OPTIONAL predicates, keyed by their OWN clause's insert offset

        // An OPTIONAL binding's null-tolerant scope goes into the WHERE of the
        // OPTIONAL MATCH that BINDS it (no match → null, row survives). A WHERE
        // attached to any other clause is wrong: on a later OPTIONAL MATCH it
        // only decides whether THAT pattern matches, so a cross-tenant value
        // bound earlier flows through untouched (P1, live: 3963 cross-tenant
        // invoices). Unknown clause → strictly-filtering row-level fallback.
        $addOptional = static function (NodeBinding $binding, string $predicate) use (&$clauseParts, &$rowWhereParts): void {
            if ($binding->clauseInsertOffset < 0) {
                $rowWhereParts[] = $predicate;
                return;
            }
            $key = $binding->clauseInsertOffset;
            $clauseParts[$key] ??= [
                'where' => $binding->clauseWhereOffset,
                'whereEnd' => $binding->clauseWhereEndOffset,
                'parts' => [],
            ];
            $clauseParts[$key]['parts'][] = $predicate;
        };

        foreach ($scope->sensitiveBindings as $binding) {
            $clause = $filterFor($binding);

            if ($clause === null) {
                // No filter required for this binding (e.g. global-count). Skip it,
                // but every OTHER sensitive binding still gets scoped.
                continue;
            }

            $text = trim($clause->clause);
            if ($text === '') {
                return null; // empty filter for a sensitive binding → cannot prove scoping
            }

            if ($clause->kind === 'match') {
                // Uniquify the variables this block introduces so two paths (or the
                // source query) never re-declare the same generated alias.
                $uniquified = $this->uniquifyMatchAliases($text, $binding->alias, $reserved, $aliasCounter);
                if ($uniquified === null) {
                    return null;
                }

                if ($binding->optional) {
                    // OPTIONAL-bound variable (may be null): a MANDATORY MATCH on a
                    // null variable eliminates every row — OPTIONAL semantics
                    // destroyed (AiQueryLog 674). Express the SAME scope as a
                    // null-tolerant existential predicate instead: a non-null value
                    // must still reach a permitted team ($ai_team_ids enforced),
                    // a null (no optional match) keeps the row. NEVER simply skip
                    // scoping for OPTIONAL variables.
                    $addOptional($binding, '(' . $binding->alias . ' IS NULL OR EXISTS { ' . $uniquified . ' })');
                } else {
                    $matchInserts[] = $uniquified;
                }
                continue;
            }

            // 'where': strip a leading WHERE if present, keep only the predicate.
            $predicate = preg_replace('/^\s*WHERE\s+/i', '', $text);
            $predicate = trim((string) $predicate);
            if ($predicate === '') {
                return null;
            }

            if ($binding->optional) {
                // Null-tolerant direct predicate, attached to the binding's OWN
                // OPTIONAL MATCH's WHERE: it filters that optional match
                // (cross-tenant value → null, row survives). In the row-level
                // fallback the IS-NULL disjunct keeps unmatched rows while a
                // cross-tenant non-null value drops the row.
                $addOptional($binding, '(' . $binding->alias . ' IS NULL OR ' . $predicate . ')');
            } elseif ($scope->anchorClauseIsOptionalMatch) {
                // The WHERE we would merge into / open belongs to an OPTIONAL
                // MATCH: a mandatory binding's predicate there would only filter
                // the optional match — a cross-tenant mandatory row would still
                // flow through with the optional variable null (leak). Enforce it
                // at ROW level via `WITH * WHERE …` instead.
                $rowWhereParts[] = $predicate;
            } else {
                $whereParts[] = $predicate;
            }
        }

        $edits = [];
        $anchor = $scope->anchorOffset;

        // The insertion spliced immediately before the anchor. ORDER MATTERS:
        // the combined direct WHERE comes FIRST, then the self-contained MATCH
        // extension blocks. A MATCH block ends in its own WHERE, so trailing the
        // direct WHERE after it would emit two adjacent WHEREs (invalid Cypher);
        // leading with it and following with MATCH blocks never does.
        $insertBeforeAnchor = '';

        if (!empty($whereParts)) {
            $combined = implode(' AND ', $whereParts);

            // The existing WHERE is "mergeable" only when its predicate runs right up
            // to the anchor (no intervening clause). If a clause — typically a 2nd
            // MATCH that introduces further bindings — sits between the WHERE and the
            // anchor (whereEndOffset < anchorOffset), that WHERE is already CLOSED:
            //   • AND-appending here would land after a pattern clause (`MATCH … AND …`)
            //     or BEFORE a later binding is in scope (`WHERE … li … MATCH (…li…)`),
            //     both invalid Cypher; and
            //   • the combined filter scopes bindings (e.g. li) that only become live
            //     past that clause.
            // So treat it like "no existing WHERE": open a fresh WHERE before the
            // anchor, where every sensitive binding is guaranteed live. The closed
            // OR-WHERE is left untouched and needs no parenthesizing (nothing is
            // AND-ed into it).
            $whereAdjacentToAnchor = $scope->whereEndOffset < 0
                || $scope->whereEndOffset >= $anchor;

            if ($scope->whereOffset >= 0 && $whereAdjacentToAnchor) {
                // Scope already has a WHERE that directly precedes the anchor.
                // AND-merge into it.
                if ($scope->whereHasTopLevelOr) {
                    // Wrap the existing predicate in parens so AND binds correctly:
                    // `a OR b` + ` AND team` must become `(a OR b) AND team`.
                    $predStart = $scope->whereOffset + self::WHERE_LEN;
                    $predEnd = $scope->whereEndOffset >= 0 ? $scope->whereEndOffset : $anchor;
                    $existing = trim(substr($cypher, $predStart, $predEnd - $predStart));
                    if ($existing === '') {
                        return null;
                    }
                    // Replace the predicate range with the parenthesized + ANDed form.
                    $edits[] = [$predStart, $predEnd, ' (' . $existing . ') AND ' . $combined . ' '];
                } else {
                    // No top-level OR: append ` AND <combined>` just before the anchor.
                    $insertBeforeAnchor .= 'AND ' . $combined . ' ';
                }
            } else {
                // Either no existing WHERE in this scope, or an existing WHERE that
                // is already closed by an intervening clause: open a fresh WHERE
                // before the anchor (every sensitive binding is live there).
                $insertBeforeAnchor .= 'WHERE ' . $combined . ' ';
            }
        }

        foreach ($matchInserts as $m) {
            $insertBeforeAnchor .= $m . ' ';
        }

        if (!empty($rowWhereParts)) {
            // Row-level enforcement for mandatory bindings when the anchor-adjacent
            // clause is an OPTIONAL MATCH: `WITH *` re-anchors the WHERE at row
            // level (all variables stay live), so cross-tenant mandatory rows are
            // eliminated even though the preceding clause is OPTIONAL. Emitted
            // LAST, after the (self-contained) MATCH blocks, immediately before
            // the anchor.
            $insertBeforeAnchor .= 'WITH * WHERE ' . implode(' AND ', $rowWhereParts) . ' ';
        }

        if ($insertBeforeAnchor !== '' && $scope->anchorClauseRejectsWhere) {
            // The clause before the anchor cannot carry a WHERE (UNWIND … AS x,
            // SET …, CALL (…) {…}, …): a bare WHERE there is a SyntaxError, and a
            // MATCH directly after an update clause needs a WITH first. `WITH *`
            // keeps every variable live and re-opens a row-level clause, so the
            // direct WHERE (row-level, strictly filtering) and MATCH-hop blocks
            // attach validly. Any existing WHERE is necessarily closed by that
            // clause, so the fresh-WHERE path above was taken; if not, the shape is
            // not understood → fail closed.
            if (str_starts_with($insertBeforeAnchor, 'AND ')) {
                return null;
            }
            $insertBeforeAnchor = 'WITH * ' . $insertBeforeAnchor;
        }

        // OPTIONAL bindings: one WHERE edit per binding clause (several variables
        // bound by the same OPTIONAL MATCH share it).
        $clauseAtAnchor = '';
        foreach ($clauseParts as $insertOffset => $clause) {
            $combined = implode(' AND ', $clause['parts']);

            if ($clause['where'] >= 0) {
                // The clause has its own WHERE: ALWAYS parenthesize the existing
                // predicate before AND-ing (OR / XOR bind looser than AND; and a
                // mis-bounded predicate then yields unbalanced parens the
                // structural validator rejects, instead of a valid-but-wrong
                // conjunction).
                $predStart = $clause['where'] + self::WHERE_LEN;
                $predEnd = $clause['whereEnd'];
                if ($predEnd <= $predStart || $predEnd > $anchor) {
                    return null;
                }
                $existing = trim(substr($cypher, $predStart, $predEnd - $predStart));
                if ($existing === '') {
                    return null;
                }
                $edits[] = [$predStart, $predEnd, ' (' . $existing . ') AND ' . $combined . ' '];
                continue;
            }

            if ($insertOffset > $anchor) {
                return null; // the clause must close before this scope's anchor
            }

            if ($insertOffset === $anchor) {
                // The binding clause is the last one before the anchor: its WHERE
                // must come FIRST in the anchor insertion (before any row-level
                // `WITH * …` / MATCH block, which would otherwise end the clause).
                $clauseAtAnchor .= 'WHERE ' . $combined . ' ';
                continue;
            }

            $edits[] = [$insertOffset, $insertOffset, $this->leftPad($cypher, $insertOffset) . 'WHERE ' . $combined . ' '];
        }

        if ($clauseAtAnchor !== '') {
            if (str_starts_with($insertBeforeAnchor, 'AND ')) {
                // A mandatory AND-merge into an anchor-adjacent WHERE cannot coexist
                // with a WHERE-less OPTIONAL clause there — shape not understood.
                return null;
            }
            $insertBeforeAnchor = $clauseAtAnchor . $insertBeforeAnchor;
        }

        if ($insertBeforeAnchor !== '') {
            // Insert before the anchor. Ensure a separating space precedes it.
            $edits[] = [$anchor, $anchor, $this->leftPad($cypher, $anchor) . $insertBeforeAnchor];
        }

        return $edits;
    }

    /**
     * Rename the variables a relationship/parent MATCH block INTRODUCES so they
     * cannot collide with the source query or with another injected block. The
     * block's first node alias is the existing binding (left untouched); every
     * variable declared by a `(<var>:Label)` node pattern in the block is renamed
     * to a fresh `ai_sN` and its references rewritten throughout the block.
     *
     * Returns null if the block is not lexable (it never reaches Neo4j then).
     *
     * @param array<string, true> $reserved
     */
    private function uniquifyMatchAliases(
        string $block,
        string $bindingAlias,
        array &$reserved,
        int &$aliasCounter
    ): ?string {
        $lexed = $this->lexer->tokenize($block);
        if ($lexed['error'] !== null) {
            return null;
        }

        /** @var list<Token> $tokens */
        $tokens = $lexed['tokens'];
        $n = count($tokens);

        // Collect variables declared inside `(<var> : Label …)` node patterns. The
        // binding alias itself is a back-reference, never renamed.
        $introduced = [];
        for ($i = 0; $i < $n - 1; $i++) {
            if (!$tokens[$i]->isPunct('(')) {
                continue;
            }
            $var = $tokens[$i + 1];
            $colon = $tokens[$i + 2] ?? null;
            if ($var->type === CypherLexer::T_WORD
                && $colon !== null && $colon->isPunct(':')
                && $var->value !== $bindingAlias) {
                $introduced[$var->value] = true;
            }
        }

        if (empty($introduced)) {
            return $block;
        }

        // Assign a fresh, collision-free name to each introduced variable.
        $rename = [];
        foreach (array_keys($introduced) as $var) {
            do {
                $fresh = 'ai_s' . $aliasCounter++;
            } while (isset($reserved[$fresh]));
            $reserved[$fresh] = true;
            $rename[$var] = $fresh;
        }

        // Rewrite every whole-word occurrence of an introduced variable. Word
        // boundaries (\b) are safe here: the block is generated text with simple
        // identifiers, no string literals to protect.
        $patterns = [];
        $replacements = [];
        foreach ($rename as $old => $new) {
            $patterns[] = '/\b' . preg_quote($old, '/') . '\b/';
            $replacements[] = $new;
        }

        return (string) preg_replace($patterns, $replacements, $block);
    }

    /**
     * Every variable declared by a `(<var> …)` node pattern at top level in the
     * source query, so generated aliases avoid them.
     *
     * @return array<string, true>
     */
    private function declaredAliases(string $cypher): array
    {
        $lexed = $this->lexer->tokenize($cypher);
        if ($lexed['error'] !== null) {
            return [];
        }

        /** @var list<Token> $tokens */
        $tokens = $lexed['tokens'];
        $n = count($tokens);

        $aliases = [];
        for ($i = 0; $i < $n - 1; $i++) {
            if (!$tokens[$i]->isPunct('(')) {
                continue;
            }
            $var = $tokens[$i + 1];
            $next = $tokens[$i + 2] ?? null;
            if ($var->type === CypherLexer::T_WORD
                && $next !== null
                && ($next->isPunct(':') || $next->isPunct(')') || $next->isPunct('{'))) {
                $aliases[$var->value] = true;
            }
        }

        return $aliases;
    }

    /**
     * Ensure exactly one space separates the spliced text from the preceding
     * token (the head already ends in a space when the source had one).
     */
    private function leftPad(string $cypher, int $offset): string
    {
        if ($offset === 0) {
            return '';
        }
        $prev = $cypher[$offset - 1];
        return ($prev === ' ' || $prev === "\t" || $prev === "\n" || $prev === "\r") ? '' : ' ';
    }

    /**
     * Whether no two edits overlap. A replacement [s,e) and an insert at e are
     * disjoint; two inserts at the same offset are not (ambiguous order).
     *
     * @param list<array{0:int,1:int,2:string}> $edits
     */
    private function editsAreDisjoint(array $edits): bool
    {
        usort($edits, static fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        for ($i = 1, $n = count($edits); $i < $n; $i++) {
            [$prevStart, $prevEnd] = $edits[$i - 1];
            [$start] = $edits[$i];
            if ($start < $prevEnd || $start === $prevStart) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply non-overlapping edits to the string, right-to-left by start offset.
     *
     * @param list<array{0:int,1:int,2:string}> $edits
     */
    private function applyEdits(string $cypher, array $edits): string
    {
        // Sort descending by start so earlier offsets remain valid as we splice.
        usort($edits, static fn($a, $b) => $b[0] <=> $a[0]);

        foreach ($edits as [$start, $end, $text]) {
            $cypher = substr($cypher, 0, $start) . $text . substr($cypher, $end);
        }

        return $cypher;
    }
}
