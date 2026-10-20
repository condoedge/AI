<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

/**
 * CypherSchemaValidator (Task 5.4 — the "G4" schema check).
 *
 * Pure / deterministic / no I/O. Given a generated Cypher draft and the LIVE graph
 * schema, reports what the draft uses that the graph provably does not have:
 *
 *   (a) an unknown node label                      `(c:Customer)`
 *   (b) an unknown relationship type, or a typed relationship pattern whose
 *       (from label, type, to label) triple does not exist in the written
 *       direction — naming the reverse when THAT exists  `(c:Contact)<-[:CUSTOMER]-(i:Invoice)`
 *   (c) `x.prop` where x is PROVABLY a node of label A and A has no such property
 *                                                  `a.balance` on :Account
 *
 * CONSERVATIVE BY CONSTRUCTION — a false violation on a valid query would make the
 * generator "repair" a correct draft, so every check fires only on proof:
 *   - it works on the {@see CypherLexer} token stream (string literals are opaque,
 *     comments are whitespace), and any input the lexer rejects yields no violation;
 *   - a variable's labels come only from node patterns; its binding is dropped at a
 *     WITH that does not project it and poisoned by anything that re-defines the name
 *     (`AS x`, `x IN …`, `p = (…)`, relationship / path variables, YIELD);
 *   - an endpoint whose labels are unknown matches any pattern, variable-length
 *     relationships skip the triple check, unparseable label / type expressions skip
 *     their checks, and a schema fact the caller does not have (null) disables the
 *     checks that need it;
 *   - a property is reported only when it is absent from every known property of the
 *     variable's labels (entity config ∪ sampled keys) AND either every label was
 *     sampled on the live graph or the key exists on no node at all (db.propertyKeys).
 *
 * The checks are Cypher GRAMMAR (tokens, nesting, arrows) + schema membership only —
 * no semantic word lists.
 */
final class CypherSchemaValidator
{
    /** Cap on the relationships listed in one "existing relationships" hint. */
    public const MAX_LISTED_RELATIONSHIPS = 15;

    /** Cap on the property names listed in one property hint. */
    public const MAX_LISTED_PROPERTIES = 30;

    /** Cypher keywords after which a `(` may open a pattern (grammar tokens). */
    private const PATTERN_PRECEDING_KEYWORDS = [
        'MATCH', 'MERGE', 'CREATE', 'WHERE', 'AND', 'OR', 'XOR', 'NOT',
        'WHEN', 'THEN', 'ELSE', 'RETURN', 'WITH', 'DISTINCT',
    ];

    /** Clause heads that end a WITH projection list (plus WHERE). */
    private const PROJECTION_TERMINATORS = [
        'WHERE', 'MATCH', 'OPTIONAL', 'WITH', 'RETURN', 'ORDER', 'SKIP', 'LIMIT',
        'CALL', 'UNION', 'CREATE', 'MERGE', 'DELETE', 'SET', 'REMOVE', 'FOREACH', 'UNWIND',
    ];

    private const AMBIGUOUS = '__ambiguous__';

    public function __construct(
        private readonly CypherLexer $lexer = new CypherLexer()
    ) {
    }

    // ------------------------------------------------------------------ schema

    /**
     * Normalize a PipelineContext::$graphSchema (the PROJECTED per-turn schema) into
     * validator facts. Only the FULL-graph keys (all_labels, all_relationship_types,
     * all_relationship_patterns, all_property_keys) are trusted for existence checks —
     * the projected `labels` / `relationships` are a per-question selection, and a
     * label outside it is not "unknown".
     *
     * @return array{labels: ?array, types: ?array, patterns: ?array, declared: array, sampled: array, keys: ?array}
     */
    public static function schemaFromContext(array $graphSchema): array
    {
        return [
            'labels' => self::stringList($graphSchema['all_labels'] ?? null),
            'types' => self::stringList($graphSchema['all_relationship_types'] ?? null),
            'patterns' => self::patternList($graphSchema['all_relationship_patterns'] ?? null),
            'declared' => self::propertyMap($graphSchema['properties'] ?? []),
            'sampled' => self::sampledMap($graphSchema['property_types'] ?? []),
            'keys' => self::stringList($graphSchema['all_property_keys'] ?? null),
        ];
    }

    /**
     * Normalize a FULL graph schema (ContextRetriever::getGraphSchema() shape:
     * labels, relationships, relationshipPatterns, properties, propertyKeys, plus an
     * optional property_types map).
     *
     * @return array{labels: ?array, types: ?array, patterns: ?array, declared: array, sampled: array, keys: ?array}
     */
    public static function schemaFromFullSchema(array $schema): array
    {
        return [
            'labels' => self::stringList($schema['labels'] ?? null),
            'types' => self::stringList($schema['relationships'] ?? $schema['relationshipTypes'] ?? null),
            'patterns' => self::patternList($schema['relationshipPatterns'] ?? $schema['relationship_patterns'] ?? null),
            'declared' => self::propertyMap($schema['properties'] ?? []),
            'sampled' => self::sampledMap($schema['property_types'] ?? []),
            'keys' => self::stringList($schema['propertyKeys'] ?? null),
        ];
    }

    // ---------------------------------------------------------------- validate

    /**
     * @param array $schema facts from {@see schemaFromContext()} / {@see schemaFromFullSchema()}
     * @return list<string> human-readable violations (empty = nothing provably wrong)
     */
    public function validate(string $cypher, array $schema): array
    {
        if (trim($cypher) === '') {
            return [];
        }

        $lexed = $this->lexer->tokenize($cypher);
        if ($lexed['error'] !== null) {
            return [];
        }

        /** @var list<Token> $tokens */
        $tokens = $lexed['tokens'];
        $facts = $this->prepareFacts($schema);

        $violations = [];
        foreach ($this->unionParts($tokens) as [$start, $end]) {
            foreach ($this->validatePart($tokens, $start, $end, $facts) as $violation) {
                $violations[$violation] = true;
            }
        }

        return array_keys($violations);
    }

    /**
     * @param list<Token> $tokens
     * @return list<string>
     */
    private function validatePart(array $tokens, int $start, int $end, array $facts): array
    {
        $violations = [];
        $poisoned = $this->poisonedNames($tokens, $start, $end);

        /** @var array<string, array<string, true>|string> $bindings var => label set | AMBIGUOUS */
        $bindings = [];
        $pendingRebuild = null; // [applyAtIndex, newBindings]
        /** @var list<array{close: int, snapshot: array}> $scopes nested subquery / comprehension scopes */
        $scopes = [];

        for ($i = $start; $i < $end; $i++) {
            if ($pendingRebuild !== null && $i >= $pendingRebuild[0]) {
                $bindings = $pendingRebuild[1];
                $pendingRebuild = null;
            }

            // Leaving a subquery / pattern comprehension: its variables were local.
            // Restore the enclosing bindings; names it projected (AS) stay unprovable.
            while ($scopes !== [] && $i >= $scopes[count($scopes) - 1]['close']) {
                $scope = array_pop($scopes);
                $restored = $scope['snapshot'];
                foreach ($bindings as $name => $value) {
                    if ($value === self::AMBIGUOUS && !array_key_exists($name, $restored)) {
                        $restored[$name] = self::AMBIGUOUS;
                    }
                }
                $bindings = $restored;
                if ($pendingRebuild !== null && $pendingRebuild[0] > $i) {
                    $pendingRebuild = null; // an inner WITH never outlives its scope
                }
            }

            $t = $tokens[$i];

            if ($this->opensLocalScope($tokens, $i)) {
                $close = $this->matching($tokens, $i, $end);
                if ($close !== null) {
                    $scopes[] = ['close' => $close, 'snapshot' => $bindings];
                }
            }

            // WITH clause: the projection decides which bindings survive (applied once
            // the projection list ends, so its own items still see the old bindings).
            if ($t->isWord('WITH') && !$t->keywordAsIdentifier) {
                $pendingRebuild = $this->projectWith($tokens, $i, $end, $bindings, $poisoned);
                continue;
            }

            // Any other `AS name` (UNWIND, RETURN, nested projections): name is computed.
            if ($t->isWord('AS') && isset($tokens[$i + 1]) && $tokens[$i + 1]->type === CypherLexer::T_WORD) {
                $bindings[$tokens[$i + 1]->value] = self::AMBIGUOUS;
                continue;
            }

            if ($t->isPunct('(') && $this->mayOpenPattern($tokens, $i, $start)) {
                $chain = $this->parseChain($tokens, $i, $end);
                if ($chain !== null) {
                    array_push($violations, ...$this->checkChain($chain, $bindings, $poisoned, $facts));
                }
                continue;
            }

            // Property access x.prop on a provably-labelled node variable.
            if ($t->type === CypherLexer::T_WORD && isset($tokens[$i + 2])
                && $tokens[$i + 1]->isPunct('.') && $tokens[$i + 2]->type === CypherLexer::T_WORD) {
                $violation = $this->checkPropertyAccess($tokens, $i, $bindings, $poisoned, $facts);
                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    // --------------------------------------------------------------- patterns

    /**
     * Whether the token at $i opens a scope whose NEW variables are local to it:
     * a `CALL {…}` / `EXISTS {…}` / `COUNT {…}` / `COLLECT {…}` subquery, or a
     * pattern comprehension `[(a)-->(b) | …]`.
     *
     * @param list<Token> $tokens
     */
    private function opensLocalScope(array $tokens, int $i): bool
    {
        $t = $tokens[$i];
        if ($t->isPunct('{')) {
            $prev = $tokens[$i - 1] ?? null;

            return $prev !== null && $prev->type === CypherLexer::T_WORD && !$prev->keywordAsIdentifier
                && CypherClauses::in($prev->value, ['CALL', 'EXISTS', 'COUNT', 'COLLECT']);
        }

        return $t->isPunct('[') && ($tokens[$i + 1] ?? null)?->isPunct('(') === true;
    }

    /**
     * Whether the `(` at $i can open a node pattern: not a function-call paren and
     * not the middle of an already-walked chain.
     *
     * @param list<Token> $tokens
     */
    private function mayOpenPattern(array $tokens, int $i, int $start): bool
    {
        if ($i <= $start) {
            return true;
        }
        $prev = $tokens[$i - 1];

        if ($prev->type === CypherLexer::T_PUNCT) {
            // Inside a chain (after `-` / `>`) the head walk already covered it.
            return !in_array($prev->value, ['-', '>'], true);
        }

        if ($prev->type === CypherLexer::T_WORD) {
            // `count(` / `toFloat(` … is a call; a grammar keyword may open a pattern.
            return !$prev->keywordAsIdentifier
                && CypherClauses::in($prev->value, self::PATTERN_PRECEDING_KEYWORDS);
        }

        return $prev->type === CypherLexer::T_OPERATOR;
    }

    /**
     * Walk a pattern chain `(n)-[r]->(m)<-[s]-(o)…` starting at the `(` at $open.
     *
     * @param list<Token> $tokens
     * @return array{nodes: list<array>, rels: list<array>}|null null when $open is not a node pattern
     */
    private function parseChain(array $tokens, int $open, int $end): ?array
    {
        $node = $this->parseNode($tokens, $open, $end);
        if ($node === null) {
            return null;
        }

        $nodes = [$node];
        $rels = [];
        $k = $node['close'] + 1;

        while ($k < $end) {
            $rel = $this->parseRelationship($tokens, $k, $end);
            if ($rel === null) {
                break;
            }
            $next = $this->parseNode($tokens, $rel['next'], $end);
            if ($next === null) {
                break;
            }
            $rels[] = $rel;
            $nodes[] = $next;
            $k = $next['close'] + 1;
        }

        return ['nodes' => $nodes, 'rels' => $rels];
    }

    /**
     * Parse `( [var] [:L1:L2 | :L1|L2 | :L1&L2] [{k: v, …}] [WHERE …] )`.
     *
     * @param list<Token> $tokens
     * @return array{var: ?string, labels: list<string>, labelsKnown: bool, mapKeys: list<string>, close: int}|null
     */
    private function parseNode(array $tokens, int $open, int $end): ?array
    {
        if (!isset($tokens[$open]) || !$tokens[$open]->isPunct('(')) {
            return null;
        }
        $close = $this->matching($tokens, $open, $end);
        if ($close === null) {
            return null;
        }

        $i = $open + 1;
        $var = null;

        if ($i < $close && $tokens[$i]->type === CypherLexer::T_WORD && !$tokens[$i]->isWord('WHERE')) {
            $next = $tokens[$i + 1] ?? null;
            $isNodeTail = $next !== null && ($next->isPunct(':') || $next->isPunct('{') || $i + 1 === $close || $next->isWord('WHERE'));
            if (!$isNodeTail || !$this->isVariableName($tokens[$i]->value)) {
                return null; // an expression in parentheses, not a node pattern
            }
            $var = $tokens[$i]->value;
            $i++;
        }

        $labels = [];
        $labelsKnown = true;
        if ($i < $close && $tokens[$i]->isPunct(':')) {
            while ($i < $close) {
                $sep = $tokens[$i];
                if (!($sep->isPunct(':') || $sep->isPunct('|') || $sep->isPunct('&'))) {
                    break;
                }
                $word = $tokens[$i + 1] ?? null;
                if ($word === null || $word->type !== CypherLexer::T_WORD || $i + 1 >= $close) {
                    $labelsKnown = false; // `:!X`, `:%`, `:(A|B)` … — not provable
                    break;
                }
                $labels[] = $word->value;
                $i += 2;
            }
        }

        $mapKeys = [];
        if ($i < $close && $tokens[$i]->isPunct('{')) {
            $mapClose = $this->matching($tokens, $i, $close);
            if ($mapClose === null) {
                return null;
            }
            $mapKeys = $this->mapKeys($tokens, $i, $mapClose);
            $i = $mapClose + 1;
        }

        if ($i < $close && $tokens[$i]->isWord('WHERE')) {
            $i = $close; // inline predicate: its property refs are scanned linearly
        }

        if ($i !== $close) {
            // Something else between the parts (label expression we cannot read):
            // still a node pattern only when labels were being read.
            if ($labels === [] && $var === null) {
                return null;
            }
            $labelsKnown = false;
        }

        return [
            'var' => $var,
            'labels' => $labelsKnown ? array_values(array_unique($labels)) : [],
            'declaredLabels' => array_values(array_unique($labels)),
            'labelsKnown' => $labelsKnown,
            'mapKeys' => $mapKeys,
            'close' => $close,
        ];
    }

    /**
     * Parse one relationship segment starting right after a node's `)`:
     * `-[…]->`, `<-[…]-`, `-[…]-`, `-->`, `<--`, `--`.
     *
     * @param list<Token> $tokens
     * @return array{direction: string, var: ?string, types: list<string>, typesKnown: bool, varLength: bool, next: int}|null
     */
    private function parseRelationship(array $tokens, int $k, int $end): ?array
    {
        $left = false;
        if (($tokens[$k] ?? null)?->isPunct('<') && ($tokens[$k + 1] ?? null)?->isPunct('-')) {
            $left = true;
            $k += 2;
        } elseif (($tokens[$k] ?? null)?->isPunct('-')) {
            $k += 1;
        } else {
            return null;
        }

        $var = null;
        $types = [];
        $typesKnown = true;
        $varLength = false;

        if (($tokens[$k] ?? null)?->isPunct('[')) {
            $close = $this->matching($tokens, $k, $end);
            if ($close === null) {
                return null;
            }
            $i = $k + 1;
            if ($i < $close && $tokens[$i]->type === CypherLexer::T_WORD && $this->isVariableName($tokens[$i]->value)) {
                $var = $tokens[$i]->value;
                $i++;
            }
            if ($i < $close && $tokens[$i]->isPunct(':')) {
                $i++;
                while ($i < $close) {
                    $word = $tokens[$i];
                    if ($word->type !== CypherLexer::T_WORD) {
                        $typesKnown = false;
                        break;
                    }
                    $types[] = $word->value;
                    $i++;
                    if ($i < $close && $tokens[$i]->isPunct('|')) {
                        $i++;
                        if ($i < $close && $tokens[$i]->isPunct(':')) {
                            $i++; // legacy `|:TYPE`
                        }
                        continue;
                    }
                    break;
                }
            }
            if ($i < $close && $tokens[$i]->isPunct('*')) {
                $varLength = true;
                while ($i < $close && !$tokens[$i]->isPunct('{') && !$tokens[$i]->isWord('WHERE')) {
                    $i++;
                }
            }
            if ($i < $close && $tokens[$i]->isPunct('{')) {
                $mapClose = $this->matching($tokens, $i, $close);
                $i = $mapClose === null ? $close : $mapClose + 1;
            }
            if ($i < $close && $tokens[$i]->isWord('WHERE')) {
                $i = $close;
            }
            if ($i !== $close) {
                $typesKnown = false;
            }
            $k = $close + 1;
            if (!($tokens[$k] ?? null)?->isPunct('-')) {
                return null;
            }
            $k++;
        } elseif (($tokens[$k] ?? null)?->isPunct('-')) {
            $k++; // bare `--`
        } else {
            return null;
        }

        $right = false;
        if (($tokens[$k] ?? null)?->isPunct('>')) {
            $right = true;
            $k++;
        }

        if (!($tokens[$k] ?? null)?->isPunct('(')) {
            return null;
        }

        return [
            'direction' => $left && !$right ? 'in' : ($right && !$left ? 'out' : 'both'),
            'var' => $var,
            'types' => array_values(array_unique($types)),
            'typesKnown' => $typesKnown,
            'varLength' => $varLength,
            'next' => $k,
        ];
    }

    /**
     * Update bindings from a chain's nodes and check its labels / relationships /
     * inline property keys.
     *
     * @param array<string, array<string, true>|string> $bindings
     * @param array<string, true> $poisoned
     * @return list<string>
     */
    private function checkChain(array $chain, array &$bindings, array $poisoned, array $facts): array
    {
        $violations = [];

        foreach ($chain['nodes'] as $node) {
            foreach ($node['declaredLabels'] as $label) {
                if ($facts['labels'] !== null && !isset($facts['labelSet'][$label])) {
                    $violations[] = $this->unknownLabelMessage($label, $facts);
                }
            }

            $var = $node['var'];
            if ($var === null || isset($poisoned[$var])) {
                continue;
            }
            if (!$node['labelsKnown']) {
                $bindings[$var] = self::AMBIGUOUS;
                continue;
            }
            if ($node['labels'] === [] || ($bindings[$var] ?? null) === self::AMBIGUOUS) {
                continue;
            }
            $current = is_array($bindings[$var] ?? null) ? $bindings[$var] : [];
            foreach ($node['labels'] as $label) {
                $current[$label] = true;
            }
            $bindings[$var] = $current;
        }

        foreach ($chain['nodes'] as $node) {
            $labels = $this->nodeLabels($node, $bindings, $poisoned, $facts);
            foreach ($node['mapKeys'] as $key) {
                $violation = $this->propertyViolation($node['var'] ?? '', $key, $labels, $facts);
                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }

        foreach ($chain['rels'] as $idx => $rel) {
            $violation = $this->checkRelationship(
                $rel,
                $this->nodeLabels($chain['nodes'][$idx], $bindings, $poisoned, $facts),
                $this->nodeLabels($chain['nodes'][$idx + 1], $bindings, $poisoned, $facts),
                $facts
            );
            array_push($violations, ...$violation);
        }

        return $violations;
    }

    /**
     * The labels a chain node provably carries (its own ∪ its variable's binding),
     * or null when unknown.
     *
     * @return list<string>|null
     */
    private function nodeLabels(array $node, array $bindings, array $poisoned, array $facts): ?array
    {
        if (!$node['labelsKnown']) {
            return null;
        }
        $labels = array_fill_keys($node['labels'], true);
        $var = $node['var'];
        if ($var !== null && !isset($poisoned[$var]) && is_array($bindings[$var] ?? null)) {
            $labels += $bindings[$var];
        }

        // A label the graph does not have is reported on its own; it tells us
        // nothing about which relationships / properties the node can have.
        if ($facts['labels'] !== null) {
            foreach (array_keys($labels) as $label) {
                if (!isset($facts['labelSet'][$label])) {
                    return null;
                }
            }
        }

        return $labels === [] ? null : array_keys($labels);
    }

    /**
     * @param list<string>|null $leftLabels
     * @param list<string>|null $rightLabels
     * @return list<string>
     */
    private function checkRelationship(array $rel, ?array $leftLabels, ?array $rightLabels, array $facts): array
    {
        if (!$rel['typesKnown'] || $rel['types'] === []) {
            return [];
        }

        $violations = [];
        $allTypesExist = true;
        foreach ($rel['types'] as $type) {
            if ($facts['types'] !== null && !isset($facts['typeSet'][$type])) {
                $allTypesExist = false;
                $violations[] = $this->unknownTypeMessage($type, $facts);
            }
        }

        if (!$allTypesExist || $rel['varLength'] || $facts['patterns'] === null
            || ($leftLabels === null && $rightLabels === null)) {
            return $violations;
        }

        [$from, $to] = $rel['direction'] === 'in' ? [$rightLabels, $leftLabels] : [$leftLabels, $rightLabels];

        $exists = $this->tripleExists($rel['types'], $from, $to, $facts)
            || ($rel['direction'] === 'both' && $this->tripleExists($rel['types'], $to, $from, $facts));
        if ($exists) {
            return $violations;
        }

        $violations[] = $this->missingTripleMessage($rel, $leftLabels, $rightLabels, $from, $to, $facts);

        return $violations;
    }

    /**
     * @param list<string> $types
     * @param list<string>|null $from
     * @param list<string>|null $to
     */
    private function tripleExists(array $types, ?array $from, ?array $to, array $facts): bool
    {
        foreach ($facts['patterns'] as $p) {
            if (!in_array($p['type'], $types, true)) {
                continue;
            }
            if (($from === null || in_array($p['from'], $from, true)) && ($to === null || in_array($p['to'], $to, true))) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------- properties

    /**
     * @param list<Token> $tokens
     * @param array<string, true> $poisoned
     */
    private function checkPropertyAccess(array $tokens, int $i, array $bindings, array $poisoned, array $facts): ?string
    {
        $var = $tokens[$i]->value;
        $prev = $tokens[$i - 1] ?? null;
        $after = $tokens[$i + 3] ?? null;

        if (!$this->isVariableName($var)
            || ($prev !== null && $prev->isPunct('.'))
            || ($after !== null && $after->isPunct('('))  // namespaced function: date.truncate(
            || isset($poisoned[$var])
            || !is_array($bindings[$var] ?? null)) {
            return null;
        }

        return $this->propertyViolation($var, $tokens[$i + 2]->value, array_keys($bindings[$var]), $facts);
    }

    /**
     * @param list<string>|null $labels
     */
    private function propertyViolation(string $var, string $property, ?array $labels, array $facts): ?string
    {
        if ($labels === null || $labels === []) {
            return null;
        }

        // PROOF ONLY: the key must exist on NO node of the graph (db.propertyKeys).
        // A label's declared (entity config) + sampled keys are NOT exhaustive — a
        // sparse property (e.g. deleted_at, null on every sampled node) is still
        // real, and flagging it would "repair" a valid query. They only feed the hint.
        if ($facts['keys'] === null || isset($facts['keySet'][$property])) {
            return null;
        }

        $known = [];
        foreach ($labels as $label) {
            if ($facts['labels'] !== null && !isset($facts['labelSet'][$label])) {
                return null; // unknown label already reported; nothing to compare against
            }
            foreach ($facts['declared'][$label] ?? [] as $p) {
                $known[$p] = true;
            }
            foreach ($facts['sampled'][$label] ?? [] as $p) {
                $known[$p] = true;
            }
        }

        if (isset($known[$property])) {
            return null; // configured but never written: reads null, not an invention
        }

        $labelText = implode(':', $labels);
        $message = "Property `{$property}` does not exist on :{$labelText}" . ($var !== '' ? " (used as {$var}.{$property})" : '') . '.';
        $names = array_keys($known);
        $suggestion = $this->closest($property, $names);
        if ($suggestion !== null) {
            $message .= " Did you mean `{$suggestion}`?";
        }
        if ($names !== []) {
            $message .= " Existing :{$labelText} properties: " . implode(', ', array_slice($names, 0, self::MAX_LISTED_PROPERTIES))
                . (count($names) > self::MAX_LISTED_PROPERTIES ? ', …' : '') . '.';
        }

        return $message;
    }

    // ---------------------------------------------------------------- scoping

    /**
     * Names that can never be proven to be a labelled node in this part:
     * comprehension / quantifier / FOREACH iteration variables (`x IN`), reduce
     * accumulators (`(acc =`), path variables (`p = (`), relationship variables and
     * YIELDed names.
     *
     * @param list<Token> $tokens
     * @return array<string, true>
     */
    private function poisonedNames(array $tokens, int $start, int $end): array
    {
        $names = [];

        for ($i = $start; $i < $end; $i++) {
            $t = $tokens[$i];
            if ($t->type !== CypherLexer::T_WORD) {
                continue;
            }
            $prev = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            $afterNext = $tokens[$i + 2] ?? null;

            if ($next !== null && $next->isWord('IN') && $prev !== null
                && ($prev->isPunct('(') || $prev->isPunct('[') || $prev->isPunct(','))) {
                $names[$t->value] = true;
            }
            if ($next !== null && $next->isPunct('=') && $afterNext !== null && $afterNext->isPunct('(')) {
                $names[$t->value] = true; // path variable
            }
            if ($next !== null && $next->isPunct('=') && $prev !== null && $prev->isPunct('(')) {
                $names[$t->value] = true; // reduce accumulator
            }
            if ($prev !== null && $prev->isPunct('[') && $this->isVariableName($t->value)
                && $next !== null && ($next->isPunct(':') || $next->isPunct(']') || $next->isPunct('*') || $next->isPunct('{'))) {
                $names[$t->value] = true; // relationship variable
            }
            if ($t->isWord('YIELD')) {
                for ($j = $i + 1; $j < $end; $j++) {
                    $y = $tokens[$j];
                    if ($y->type === CypherLexer::T_WORD && !$y->isWord('AS')
                        && !CypherClauses::in($y->value, self::PROJECTION_TERMINATORS)) {
                        $names[$y->value] = true;
                        continue;
                    }
                    if ($y->isPunct(',') || $y->isWord('AS')) {
                        continue;
                    }
                    break;
                }
            }
        }

        return $names;
    }

    /**
     * The bindings that survive the WITH at $withIdx, and the token index at which
     * they take effect (the end of its projection list).
     *
     * @param list<Token> $tokens
     * @param array<string, array<string, true>|string> $bindings
     * @param array<string, true> $poisoned
     * @return array{0: int, 1: array<string, array<string, true>|string>}
     */
    private function projectWith(array $tokens, int $withIdx, int $end, array $bindings, array $poisoned): array
    {
        $base = $tokens[$withIdx];
        $projEnd = $end;
        for ($i = $withIdx + 1; $i < $end; $i++) {
            $t = $tokens[$i];
            if ($this->sameDepth($t, $base) && $t->type === CypherLexer::T_WORD && !$t->keywordAsIdentifier
                && CypherClauses::in($t->value, self::PROJECTION_TERMINATORS)) {
                $projEnd = $i;
                break;
            }
            if ($t->braceDepth < $base->braceDepth || $t->parenDepth < $base->parenDepth || $t->bracketDepth < $base->bracketDepth) {
                $projEnd = $i; // the enclosing subquery closed
                break;
            }
        }

        $items = [];
        $itemStart = $withIdx + 1;
        for ($i = $withIdx + 1; $i <= $projEnd; $i++) {
            if ($i === $projEnd || ($tokens[$i]->isPunct(',') && $this->sameDepth($tokens[$i], $base))) {
                $items[] = [$itemStart, $i];
                $itemStart = $i + 1;
            }
        }

        $new = [];
        foreach ($items as [$s, $e]) {
            if ($s < $e && $tokens[$s]->isWord('DISTINCT')) {
                $s++;
            }
            $len = $e - $s;
            if ($len === 1 && $tokens[$s]->isPunct('*')) {
                $new = $bindings + $new;
                continue;
            }
            if ($len === 1 && $tokens[$s]->type === CypherLexer::T_WORD && array_key_exists($tokens[$s]->value, $bindings)) {
                $new[$tokens[$s]->value] = $bindings[$tokens[$s]->value];
                continue;
            }
            if ($len === 3 && $tokens[$s]->type === CypherLexer::T_WORD && $tokens[$s + 1]->isWord('AS')
                && $tokens[$s + 2]->type === CypherLexer::T_WORD) {
                $source = $tokens[$s]->value;
                $alias = $tokens[$s + 2]->value;
                $new[$alias] = !isset($poisoned[$source]) && array_key_exists($source, $bindings)
                    ? $bindings[$source]
                    : self::AMBIGUOUS;
                continue;
            }
            // `expr AS alias`: the alias is a computed value.
            for ($j = $e - 2; $j >= $s; $j--) {
                if ($tokens[$j]->isWord('AS') && $tokens[$j + 1]->type === CypherLexer::T_WORD) {
                    $new[$tokens[$j + 1]->value] = self::AMBIGUOUS;
                    break;
                }
            }
        }

        return [$projEnd, $new];
    }

    /**
     * Top-level UNION parts as [start, end) token ranges.
     *
     * @param list<Token> $tokens
     * @return list<array{0: int, 1: int}>
     */
    private function unionParts(array $tokens): array
    {
        $parts = [];
        $partStart = 0;
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t->isWord('UNION') && !$t->keywordAsIdentifier && $t->isTopLevel()) {
                $parts[] = [$partStart, $i];
                if (isset($tokens[$i + 1]) && $tokens[$i + 1]->isWord('ALL')) {
                    $i++;
                }
                $partStart = $i + 1;
            }
        }
        $parts[] = [$partStart, $n];

        return $parts;
    }

    // ---------------------------------------------------------------- messages

    private function unknownLabelMessage(string $label, array $facts): string
    {
        $message = "Label :{$label} does not exist in the graph.";
        $suggestion = $this->closest($label, $facts['labels']);

        return $suggestion !== null ? $message . " Did you mean :{$suggestion}?" : $message;
    }

    private function unknownTypeMessage(string $type, array $facts): string
    {
        $message = "Relationship type :{$type} does not exist in the graph.";
        $suggestion = $this->closest($type, $facts['types']);

        return $suggestion !== null ? $message . " Did you mean :{$suggestion}?" : $message;
    }

    /**
     * @param list<string>|null $leftLabels
     * @param list<string>|null $rightLabels
     * @param list<string>|null $from
     * @param list<string>|null $to
     */
    private function missingTripleMessage(array $rel, ?array $leftLabels, ?array $rightLabels, ?array $from, ?array $to, array $facts): string
    {
        $types = implode('|', $rel['types']);
        $left = $this->renderNode($leftLabels);
        $right = $this->renderNode($rightLabels);
        $written = match ($rel['direction']) {
            'in' => "{$left}<-[:{$types}]-{$right}",
            'out' => "{$left}-[:{$types}]->{$right}",
            default => "{$left}-[:{$types}]-{$right}",
        };

        $message = "Your query uses {$written}, which does not exist in the graph.";

        if ($rel['direction'] !== 'both') {
            $reverse = $this->matchingPatterns($rel['types'], $to, $from, $facts);
            if ($reverse !== []) {
                $message .= ' It exists only in the opposite direction — did you mean ' . implode(' or ', array_slice($reverse, 0, 3)) . '?';
            }
        }

        $ofType = $this->matchingPatterns($rel['types'], null, null, $facts);
        if ($ofType !== []) {
            $message .= " Existing :{$types} relationships: " . $this->renderList($ofType) . '.';
        }

        if ($from !== null) {
            $out = $this->patternsTouching($from, 'from', $facts);
            $message .= ' Existing relationships from ' . implode('/', $from) . ': ' . ($out === [] ? 'none' : $this->renderList($out)) . '.';
        }
        if ($to !== null) {
            $in = $this->patternsTouching($to, 'to', $facts);
            $message .= ' Existing relationships into ' . implode('/', $to) . ': ' . ($in === [] ? 'none' : $this->renderList($in)) . '.';
        }

        return $message;
    }

    /**
     * @param list<string> $types
     * @param list<string>|null $from
     * @param list<string>|null $to
     * @return list<string>
     */
    private function matchingPatterns(array $types, ?array $from, ?array $to, array $facts): array
    {
        $out = [];
        foreach ($facts['patterns'] as $p) {
            if (in_array($p['type'], $types, true)
                && ($from === null || in_array($p['from'], $from, true))
                && ($to === null || in_array($p['to'], $to, true))) {
                $out[] = "({$p['from']})-[:{$p['type']}]->({$p['to']})";
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $labels
     * @return list<string>
     */
    private function patternsTouching(array $labels, string $side, array $facts): array
    {
        $out = [];
        foreach ($facts['patterns'] as $p) {
            if (in_array($p[$side], $labels, true)) {
                $out[] = "({$p['from']})-[:{$p['type']}]->({$p['to']})";
            }
        }

        return array_values(array_unique($out));
    }

    /** @param list<string> $patterns */
    private function renderList(array $patterns): string
    {
        $shown = array_slice($patterns, 0, self::MAX_LISTED_RELATIONSHIPS);

        return implode(', ', $shown) . (count($patterns) > count($shown) ? ', …' : '');
    }

    /** @param list<string>|null $labels */
    private function renderNode(?array $labels): string
    {
        return $labels === null ? '()' : '(' . implode(':', $labels) . ')';
    }

    /**
     * The unique closest candidate within edit distance 2 (1 for short names), or null.
     *
     * @param list<string>|null $candidates
     */
    private function closest(string $name, ?array $candidates): ?string
    {
        if ($candidates === null || $candidates === []) {
            return null;
        }

        $lower = strtolower($name);
        $best = null;
        $bestDistance = PHP_INT_MAX;
        $tie = false;
        $limit = strlen($name) <= 4 ? 1 : 2;

        foreach ($candidates as $candidate) {
            $distance = strtolower($candidate) === $lower ? 0 : levenshtein($lower, strtolower($candidate));
            if ($distance > $limit) {
                continue;
            }
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
                $tie = false;
            } elseif ($distance === $bestDistance && $candidate !== $best) {
                $tie = true;
            }
        }

        return $tie ? null : $best;
    }

    // ----------------------------------------------------------------- helpers

    private function prepareFacts(array $schema): array
    {
        $labels = $schema['labels'] ?? null;
        $types = $schema['types'] ?? null;
        $keys = $schema['keys'] ?? null;

        return [
            'labels' => is_array($labels) ? array_values($labels) : null,
            'labelSet' => is_array($labels) ? array_fill_keys($labels, true) : [],
            'types' => is_array($types) ? array_values($types) : null,
            'typeSet' => is_array($types) ? array_fill_keys($types, true) : [],
            'patterns' => is_array($schema['patterns'] ?? null) ? $schema['patterns'] : null,
            'declared' => is_array($schema['declared'] ?? null) ? $schema['declared'] : [],
            'sampled' => is_array($schema['sampled'] ?? null) ? $schema['sampled'] : [],
            'keys' => is_array($keys) ? array_values($keys) : null,
            'keySet' => is_array($keys) ? array_fill_keys($keys, true) : [],
        ];
    }

    /**
     * Index of the delimiter closing the one at $open (same nesting depth), or null.
     *
     * @param list<Token> $tokens
     */
    private function matching(array $tokens, int $open, int $end): ?int
    {
        $o = $tokens[$open];
        $closer = match ($o->value) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            default => null,
        };
        if ($closer === null) {
            return null;
        }

        $limit = min($end, count($tokens));
        for ($i = $open + 1; $i < $limit; $i++) {
            $t = $tokens[$i];
            if ($t->isPunct($closer) && $this->sameDepth($t, $o)) {
                return $i;
            }
        }

        return null;
    }

    private function sameDepth(Token $a, Token $b): bool
    {
        return $a->parenDepth === $b->parenDepth
            && $a->bracketDepth === $b->bracketDepth
            && $a->braceDepth === $b->braceDepth;
    }

    /**
     * Top-level keys of the `{…}` map between $open and $close.
     *
     * @param list<Token> $tokens
     * @return list<string>
     */
    private function mapKeys(array $tokens, int $open, int $close): array
    {
        $keys = [];
        $depth = $tokens[$open]->braceDepth + 1;
        for ($i = $open + 1; $i < $close; $i++) {
            $t = $tokens[$i];
            if ($t->type === CypherLexer::T_WORD && $t->braceDepth === $depth
                && ($tokens[$i + 1] ?? null)?->isPunct(':')
                && ($tokens[$i - 1]->isPunct('{') || $tokens[$i - 1]->isPunct(','))) {
                $keys[] = $t->value;
            }
        }

        return $keys;
    }

    private function isVariableName(string $word): bool
    {
        $first = $word[0] ?? '';

        return $first !== '' && $first !== '$' && !ctype_digit($first);
    }

    /**
     * An EMPTY list is treated as unknown (null): an empty vocabulary means the
     * schema fetch produced nothing, and "nothing exists" would flag every query.
     *
     * @return list<string>|null
     */
    private static function stringList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $list = array_values(array_unique(array_filter($value, static fn ($v): bool => is_string($v) && $v !== '')));

        return $list === [] ? null : $list;
    }

    /** @return list<array{from: string, type: string, to: string}>|null null = unknown (absent or empty) */
    private static function patternList(mixed $raw): ?array
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $patterns = [];
        foreach ($raw as $p) {
            if (!is_array($p)) {
                continue;
            }
            $from = $p['from_label'] ?? $p['fromLabel'] ?? $p['from'] ?? null;
            $type = $p['type'] ?? $p['relationshipType'] ?? $p['relationship_type'] ?? null;
            $to = $p['to_label'] ?? $p['toLabel'] ?? $p['to'] ?? null;
            if (is_string($from) && is_string($type) && is_string($to)) {
                $patterns[$from . '|' . $type . '|' . $to] = ['from' => $from, 'type' => $type, 'to' => $to];
            }
        }

        return array_values($patterns);
    }

    /** @return array<string, list<string>> */
    private static function propertyMap(mixed $raw): array
    {
        $map = [];
        foreach (is_array($raw) ? $raw : [] as $label => $properties) {
            if (is_string($label) && is_array($properties)) {
                $map[$label] = array_values(array_filter($properties, 'is_string'));
            }
        }

        return $map;
    }

    /** @return array<string, list<string>> label => sampled property keys */
    private static function sampledMap(mixed $raw): array
    {
        $map = [];
        foreach (is_array($raw) ? $raw : [] as $label => $types) {
            if (is_string($label) && is_array($types) && $types !== []) {
                $map[$label] = array_values(array_filter(array_keys($types), 'is_string'));
            }
        }

        return $map;
    }
}
