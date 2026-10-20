<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\CypherLexer;
use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Services\Security\Scope\FilterClause;
use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Services\Security\SecurityFilterInjector;
use Condoedge\Ai\Tests\TestCase;

/**
 * Task 1.21 — the `WITH` of the string operators `STARTS WITH` / `ENDS WITH` is
 * NOT a clause boundary.
 *
 * Live regression (eval 2026-09-29, "So how much is my contribution this
 * year?"): the analyzer treated the `WITH` of `STARTS WITH` as a top-level WITH
 * clause, anchored the scope there and spliced the tenant predicates / MATCH
 * blocks between `STARTS` and `WITH`, producing
 *   `... toString(i.invoiced_at) STARTS) AND c.team_id IN $ai_team_ids MATCH ... WITH toString(date().year)`
 * → Neo.ClientError.Statement.SyntaxError "Invalid input ')': expected 'WITH'".
 *
 * Pinned here: the predicate stays intact, nothing is injected between STARTS
 * and WITH, and scoping is still COMPLETE (every sensitive binding scoped).
 * Also pinned (fail-closed direction): a genuine clause WITH that merely follows
 * a variable / alias / property NAMED `starts`/`ends` is still a clause.
 */
class StringOperatorWithScopingTest extends TestCase
{
    /** The EXACT generated cypher from the failing trace (base-0929-B.json, rows[2]). */
    private const CONTRIBUTION_QUERY = <<<'CYPHER'
MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit)
WHERE cu.sold_at IS NULL
MATCH (i:Invoice)
WHERE (i.customer_type = "unit" AND i.customer_id = u.id) OR (i.customer_type = "contact" AND i.customer_id = c.id)
AND i.budget_id IS NOT NULL
AND toString(i.invoiced_at) STARTS WITH toString(date().year)
RETURN sum(toFloat(i.calc_total_amount)) AS total_contribution, count(i) AS contribution_invoices
LIMIT 100
CYPHER;

    private const ORIGINAL_PREDICATE = 'toString(i.invoiced_at) STARTS WITH toString(date().year)';

    private CypherScopeAnalyzer $analyzer;
    private SecurityFilterInjector $injector;

    public function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new CypherScopeAnalyzer();
        $this->injector = new SecurityFilterInjector();
    }

    /** CondoEdge-shaped per-binding filters (mirrors the live rewrite in the trace). */
    private function condoedgeFilter(): callable
    {
        return static function (NodeBinding $b): ?FilterClause {
            return match ($b->label) {
                'ContactUnit' => new FilterClause(
                    'match',
                    "MATCH ({$b->alias})-[:UNIT]->(un1:Unit) MATCH (un1)-[:UNION]->(u1:Union) WHERE u1.team_id IN \$ai_team_ids",
                    ['ai_team_ids' => [14]]
                ),
                'Unit' => new FilterClause(
                    'match',
                    "MATCH ({$b->alias})-[:UNION]->(u1:Union) WHERE u1.team_id IN \$ai_team_ids",
                    ['ai_team_ids' => [14]]
                ),
                'Invoice' => new FilterClause(
                    'match',
                    "MATCH ({$b->alias})-[:BELONGS_TO_UNION]->(u1:Union) WHERE u1.team_id IN \$ai_team_ids",
                    ['ai_team_ids' => [14]]
                ),
                default => new FilterClause(
                    'where',
                    "WHERE {$b->alias}.team_id IN \$ai_team_ids",
                    ['ai_team_ids' => [14]]
                ),
            };
        };
    }

    /**
     * Scope + inject, then assert: scopeable, injectable, predicate verbatim,
     * every sensitive binding scoped, all scope text lands before $beforeMarker,
     * and the rewrite is structurally valid.
     *
     * @param list<string> $aliases Every sensitive alias the query binds.
     */
    private function assertScopedWithIntactPredicate(
        string $cypher,
        string $predicate,
        array $aliases,
        string $beforeMarker = 'RETURN'
    ): string {
        $plan = $this->analyzer->analyze($cypher);
        $this->assertTrue($plan->isScopeable, "must stay scopeable, rejected ({$plan->reason})");

        $bound = [];
        foreach ($plan->scopes as $scope) {
            foreach ($scope->sensitiveBindings as $b) {
                $bound[] = $b->alias;
            }
            // No scope may anchor on the operator's WITH (inside the predicate).
            $predStart = strpos($cypher, $predicate);
            $this->assertNotFalse($predStart);
            $this->assertFalse(
                $scope->anchorOffset >= $predStart && $scope->anchorOffset < $predStart + strlen($predicate),
                "a scope anchors INSIDE the string-operator predicate at offset {$scope->anchorOffset}"
            );
        }
        $this->assertEqualsCanonicalizing($aliases, array_values(array_unique($bound)), 'every sensitive binding enumerated');

        $out = $this->injector->inject($cypher, $plan, $this->condoedgeFilter());
        $this->assertNotNull($out, 'must be injectable (fail-closed here blocks a valid query)');

        // (a)+(b): nothing spliced between STARTS/ENDS and WITH — predicate verbatim.
        $this->assertStringContainsString($predicate, $out, "string-operator predicate mangled: {$out}");
        $this->assertDoesNotMatchRegularExpression(
            '/\b(STARTS|ENDS)\s*\)/i',
            $out,
            "scope text injected after STARTS/ENDS: {$out}"
        );

        // (c): every sensitive binding is scoped, before the marker clause.
        $markerPos = strrpos($out, $beforeMarker);
        $this->assertNotFalse($markerPos);
        foreach ($aliases as $alias) {
            $direct = strpos($out, "{$alias}.team_id IN \$ai_team_ids");
            $hop = strpos($out, "MATCH ({$alias})-[");
            $pos = $direct !== false ? $direct : $hop;
            $this->assertNotFalse($pos, "sensitive binding {$alias} left unscoped: {$out}");
        }

        $this->assertTrue(
            CypherStructuralValidator::isValid($out),
            'rewrite is not structurally valid: ' . $out . ' | ' . (CypherStructuralValidator::diagnose($out) ?? '')
        );

        return $out;
    }

    // =====================================================================
    // Live regression — exact trace query
    // =====================================================================

    /** @test */
    public function contribution_query_is_scoped_without_splitting_starts_with(): void
    {
        $out = $this->assertScopedWithIntactPredicate(
            self::CONTRIBUTION_QUERY,
            self::ORIGINAL_PREDICATE,
            ['c', 'cu', 'u', 'i']
        );

        // Single segment: every injected block lands before the RETURN.
        $returnPos = strpos($out, 'RETURN sum(');
        $this->assertNotFalse($returnPos);
        $this->assertLessThan($returnPos, strpos($out, 'c.team_id IN $ai_team_ids'));
        $this->assertLessThan($returnPos, strpos($out, 'MATCH (i)-[:BELONGS_TO_UNION]->'));
        // The predicate still ends the (OR-parenthesized) WHERE, intact.
        $this->assertStringContainsString(self::ORIGINAL_PREDICATE . ') AND', $out);
    }

    /** @test */
    public function contribution_query_anchors_at_the_return_not_the_operator_with(): void
    {
        $plan = $this->analyzer->analyze(self::CONTRIBUTION_QUERY);
        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");
        $this->assertCount(1, $plan->scopes, 'no WITH clause → exactly one segment');
        $this->assertSame(strpos(self::CONTRIBUTION_QUERY, 'RETURN'), $plan->scopes[0]->anchorOffset);
    }

    // =====================================================================
    // Variants
    // =====================================================================

    /** @test */
    public function ends_with_is_not_a_clause_boundary(): void
    {
        $pred = "i.reference ENDS WITH '-2026'";
        $this->assertScopedWithIntactPredicate(
            "MATCH (i:Invoice) WHERE i.budget_id IS NOT NULL AND {$pred} RETURN count(i) AS n",
            $pred,
            ['i']
        );
    }

    /** @test */
    public function lowercase_starts_with_is_not_a_clause_boundary(): void
    {
        $pred = 'toString(i.invoiced_at) starts with toString(date().year)';
        $this->assertScopedWithIntactPredicate(
            "MATCH (i:Invoice) WHERE i.budget_id IS NOT NULL AND {$pred} RETURN sum(toFloat(i.calc_total_amount)) AS t",
            $pred,
            ['i']
        );
    }

    /** @test */
    public function extra_whitespace_and_newline_between_starts_and_with(): void
    {
        $pred = "i.label STARTS   \n   WITH 'Q'";
        $this->assertScopedWithIntactPredicate(
            "MATCH (i:Invoice)\nWHERE {$pred}\nRETURN i.label AS label",
            $pred,
            ['i']
        );
    }

    /** @test */
    public function block_comment_between_starts_and_with(): void
    {
        $pred = "i.label STARTS /* x */ WITH 'Q'";
        $this->assertScopedWithIntactPredicate(
            "MATCH (i:Invoice) WHERE {$pred} RETURN i.label AS label",
            $pred,
            ['i']
        );
    }

    /** @test */
    public function starts_with_inside_a_second_with_segment(): void
    {
        $pred = 'toString(i.invoiced_at) STARTS WITH toString(date().year)';
        $cypher = "MATCH (u:Unit) WHERE u.name STARTS WITH '3' "
            . 'WITH u '
            . "MATCH (i:Invoice) WHERE i.customer_id = u.id AND {$pred} "
            . 'WITH u, sum(toFloat(i.calc_total_amount)) AS total '
            . 'RETURN u.name AS unit, total';

        $out = $this->assertScopedWithIntactPredicate($cypher, $pred, ['u', 'i']);
        $this->assertStringContainsString("u.name STARTS WITH '3'", $out);

        // Two segments: u anchors at the first CLAUSE WITH, i at the second.
        $plan = $this->analyzer->analyze($cypher);
        $anchors = [];
        foreach ($plan->scopes as $scope) {
            foreach ($scope->sensitiveBindings as $b) {
                $anchors[$b->alias] = $scope->anchorOffset;
            }
        }
        $this->assertSame(strpos($cypher, 'WITH u MATCH'), $anchors['u'] ?? null, 'u anchors at the first clause WITH');
        $this->assertSame(strpos($cypher, 'WITH u, sum('), $anchors['i'] ?? null, 'i anchors at the second clause WITH');

        // Scope blocks land in their own segments.
        $firstWith = strpos($out, 'WITH u MATCH');
        $secondWith = strpos($out, 'WITH u, sum(');
        $this->assertNotFalse($firstWith);
        $this->assertNotFalse($secondWith);
        $this->assertLessThan($firstWith, strpos($out, 'MATCH (u)-[:UNION]->'));
        $iScope = strpos($out, 'MATCH (i)-[:BELONGS_TO_UNION]->');
        $this->assertGreaterThan($firstWith, $iScope);
        $this->assertLessThan($secondWith, $iScope);
    }

    // =====================================================================
    // Fail-closed direction: a real clause WITH after a NAME `starts`/`ends`
    // =====================================================================

    /** @test */
    public function clause_with_after_an_alias_named_ends_is_still_a_clause(): void
    {
        // `AS ends WITH ends` — `ends` is an alias, the WITH is a clause. If it
        // were misread as the operator, i's segment boundary would vanish.
        $cypher = 'MATCH (i:Invoice) WITH collect(i.total) AS ends WITH ends MATCH (j:Invoice) RETURN ends, j';
        $plan = $this->analyzer->analyze($cypher);
        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");

        $anchors = [];
        foreach ($plan->scopes as $scope) {
            foreach ($scope->sensitiveBindings as $b) {
                $anchors[$b->alias] = $scope->anchorOffset;
            }
        }
        $this->assertSame(strpos($cypher, 'WITH collect'), $anchors['i'] ?? null);
        $this->assertSame(strpos($cypher, 'RETURN'), $anchors['j'] ?? null);
    }

    /** @test */
    public function clause_with_after_a_property_or_variable_named_starts_is_still_a_clause(): void
    {
        foreach ([
            'MATCH (i:Invoice) WHERE i.starts WITH i RETURN i',       // property key
            'MATCH (i:Invoice) WHERE i.a = starts WITH i RETURN i',   // variable after operator
            'MATCH (i:Invoice) WHERE starts WITH i RETURN i',         // boolean variable
            'MATCH (i:Invoice) WHERE i.a AND `starts` WITH i RETURN i',
        ] as $cypher) {
            $plan = $this->analyzer->analyze($cypher);
            $this->assertTrue($plan->isScopeable, "rejected ({$plan->reason}): {$cypher}");
            $this->assertSame(
                strpos($cypher, 'WITH i'),
                $plan->scopes[0]->anchorOffset,
                "the clause WITH must still be the anchor: {$cypher}"
            );
        }
    }

    /** @test */
    public function a_with_following_an_operator_with_is_a_clause(): void
    {
        // The second `starts` is an operand of the first operator (preceded by
        // the operator's WITH), so the WITH after it is the CLAUSE.
        $cypher = 'MATCH (i:Invoice) WHERE i.a STARTS WITH starts WITH i RETURN i';
        $plan = $this->analyzer->analyze($cypher);
        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");
        $this->assertSame(strrpos($cypher, 'WITH i'), $plan->scopes[0]->anchorOffset);

        $ops = array_values(array_filter(
            (new CypherLexer())->tokenize($cypher)['tokens'],
            static fn ($t) => $t->type === CypherLexer::T_OPERATOR
        ));
        $this->assertCount(2, $ops, 'only the first STARTS WITH is the operator');
    }

    /**
     * Variables NAMED after clause/modifier-head keywords (Neo4j accepts
     * `limit`, `skip`, `order`, `filter`, … as variable names). In operand
     * position they are variables, so `<kw> STARTS WITH` is the operator — the
     * fallback-to-clause re-created the splice bug (reviewer finding, 1.21).
     *
     * @test
     * @dataProvider keywordNamedVariableQueries
     */
    public function keyword_named_variable_before_starts_with_is_an_operand(string $cypher, string $pred, array $aliases): void
    {
        $this->assertScopedWithIntactPredicate($cypher, $pred, $aliases);
    }

    /** @return array<string, array{0:string,1:string,2:list<string>}> */
    public static function keywordNamedVariableQueries(): array
    {
        return [
            'limit alias (reviewer probe)' => [
                "MATCH (i:Invoice) WITH i, i.ref AS limit MATCH (j:Invoice) WHERE limit STARTS WITH 'x' RETURN j",
                "limit STARTS WITH 'x'",
                ['i', 'j'],
            ],
            'skip after AND' => [
                "MATCH (i:Invoice) WITH i, i.ref AS skip MATCH (j:Invoice) WHERE j.a = 1 AND skip ENDS WITH 'x' RETURN j",
                "skip ENDS WITH 'x'",
                ['i', 'j'],
            ],
            // `order` is ALSO an anchor / predicate-terminator / validator clause
            // keyword: a variable named `order` must not anchor the scope
            // mid-predicate, end the WHERE early, or trip the validator (ORDER is
            // a clause only as ORDER BY).
            'order in parens with OR' => [
                "MATCH (i:Invoice) WITH i, i.ref AS order MATCH (j:Invoice) WHERE (order STARTS WITH 'x' OR j.a = 1) RETURN j",
                "order STARTS WITH 'x'",
                ['i', 'j'],
            ],
            'order with a direct (where-kind) filter' => [
                "MATCH (c:Contact) WITH c, c.ref AS order MATCH (d:Contact) WHERE d.a = 1 AND order ENDS WITH 'x' RETURN d",
                "order ENDS WITH 'x'",
                ['c', 'd'],
            ],
            'order after comparison AND' => [
                "MATCH (i:Invoice) WITH i, i.ref AS order MATCH (j:Invoice) WHERE j.a = 1 AND order STARTS WITH 'x' RETURN j",
                "order STARTS WITH 'x'",
                ['i', 'j'],
            ],
            'filter after NOT' => [
                "MATCH (i:Invoice) WITH i, i.ref AS filter MATCH (j:Invoice) WHERE NOT filter STARTS WITH 'x' RETURN j",
                "filter STARTS WITH 'x'",
                ['i', 'j'],
            ],
        ];
    }

    /** @test */
    public function clause_head_keyword_before_a_variable_named_starts_keeps_the_clause(): void
    {
        // FILTER / SKIP in CLAUSE-head position (after a completed clause), then
        // a variable named `starts`/`ends`, then the WITH clause. Plus the
        // reviewer's must-stay-clause shapes. None may form the operator.
        foreach ([
            'UNWIND [true] AS starts MATCH (i:Invoice) FILTER starts WITH i RETURN i',
            'MATCH (i:Invoice) WITH i SKIP ends WITH i RETURN i',
            'MATCH (i:Invoice) WITH i AS ends WITH ends RETURN ends',
            'MATCH (i:Invoice) WHERE i:Starts WITH i RETURN i',
            'MATCH (i:Invoice) WHERE i IS Ends WITH i RETURN i',
            'MATCH (i:Invoice) SET i:Ends WITH i RETURN i',
            'MATCH (i:Invoice) CALL (i) { RETURN i AS ends } WITH ends RETURN ends',
            'MATCH (i:Invoice) WITH CASE WHEN i.x THEN i ELSE i END AS ends WITH ends RETURN ends',
            'MATCH (i:Invoice) WITH i AS /*c*/ ends /*c*/ WITH ends RETURN ends',
        ] as $cypher) {
            $ops = array_values(array_filter(
                (new CypherLexer())->tokenize($cypher)['tokens'],
                static fn ($t) => $t->type === CypherLexer::T_OPERATOR
            ));
            $this->assertCount(0, $ops, "a clause WITH was read as the string operator: {$cypher}");
        }
    }

    /** @test */
    public function backtick_quoted_starts_never_forms_the_operator(): void
    {
        $tokens = (new CypherLexer())->tokenize('MATCH (i:Invoice) WHERE i.x `starts` WITH i RETURN i')['tokens'];
        $withs = array_values(array_filter($tokens, static fn ($t) => $t->isWord('WITH')));
        $this->assertCount(1, $withs, 'a quoted identifier `starts` is not the STARTS keyword');
    }

    // =====================================================================
    // Structural validator: the operator WITH is not a clause keyword
    // =====================================================================

    /** @test */
    public function structural_validator_accepts_string_operators_in_where_and_parens(): void
    {
        foreach ([
            "MATCH (i:Invoice) WHERE i.ref STARTS WITH 'A' AND i.x = 1 RETURN i",
            "MATCH (i:Invoice) WHERE (i.ref ENDS WITH 'A') AND i.x = 1 RETURN i",
            "MATCH (i:Invoice) WHERE i.x = 1 OR (i.ref starts with 'B' AND i.y = 2) RETURN i",
            "MATCH (i:Invoice) RETURN i.ref STARTS WITH 'A' AS flag",
        ] as $cypher) {
            $this->assertNull(CypherStructuralValidator::diagnose($cypher), $cypher);
        }
    }

    /** @test */
    public function lexer_marks_the_string_operator_pair_as_one_operator(): void
    {
        $tokens = (new CypherLexer())->tokenize("MATCH (n) WHERE n.a STARTS WITH 'x' AND n.b ENDS /* c */ with 'y' WITH n RETURN n")['tokens'];

        $clauseWiths = array_values(array_filter($tokens, static fn ($t) => $t->isWord('WITH')));
        $this->assertCount(1, $clauseWiths, 'only the real clause WITH is a WITH word');

        $ops = array_values(array_filter($tokens, static fn ($t) => $t->type === CypherLexer::T_OPERATOR));
        $this->assertCount(4, $ops, 'STARTS, WITH, ENDS, with are operator tokens');
    }
}
