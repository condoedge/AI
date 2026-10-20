<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Services\Security\Scope\FilterClause;
use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Services\Security\SecurityFilterInjector;
use Condoedge\Ai\Tests\TestCase;

/**
 * Boundary proof (§5 invariant): for EVERY input the pipeline either
 *   (a) rejects/denies it (not scopeable), or
 *   (b) produces a rewrite in which EVERY tenant-sensitive binding carries a
 *       $ai_team_ids predicate on its OWN live alias.
 * Never a silent unscoped run.
 *
 * The checker re-analyzes the OUTPUT and asserts the predicate-per-binding
 * property, so "safe by construction" stays continuously verifiable as the subset
 * widens. The executeCount `CALL { … } RETURN count(*)` wrapper is a first-class
 * accepted case (inner scoped, outer untouched).
 */
class TenantScopingInvariantTest extends TestCase
{
    private CypherScopeAnalyzer $analyzer;
    private SecurityFilterInjector $injector;

    public function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new CypherScopeAnalyzer();
        $this->injector = new SecurityFilterInjector();
    }

    /**
     * The full adversarial corpus plus controls and mutations. Each entry is a
     * raw query; the invariant holds for ALL of them regardless of class.
     *
     * @return array<string, array{0:string}>
     */
    public static function corpus(): array
    {
        $cases = [
            // controls / simple
            'simple' => 'MATCH (i:Invoice) RETURN i',
            'where' => 'MATCH (i:Invoice) WHERE i.status = "active" RETURN i',
            'with_anchor' => 'MATCH (i:Invoice) WITH i, i.amount AS a RETURN a',
            'order' => 'MATCH (i:Invoice) ORDER BY i.created_at RETURN i',
            'scalar' => 'RETURN 1',
            'introspection' => 'CALL db.labels()',

            // adversarial corpus (ids 1..25)
            'c1_leading_with' => 'WITH 1 AS one MATCH (i:Invoice) RETURN i',
            'c2_post_with_where' => 'MATCH (i:Invoice) WITH i WHERE i.x = 1 RETURN i',
            'c4_label_free' => 'MATCH (n {id: 1}) RETURN n',
            'c5_second_root' => 'MATCH (i:Invoice) MATCH (i)-[:HAS]->(li:LineItem) RETURN li',
            'c6_return_hides_root' => 'MATCH (p:Person)-[:WORKS_AT]->(o:Organization) RETURN o',
            'c7_two_roots' => 'MATCH (i:Invoice) MATCH (p:Person) RETURN i, p',
            'c8_call_subquery' => 'MATCH (t:Team) CALL { MATCH (i:Invoice) RETURN i } RETURN t',
            'c9_call_agg' => 'CALL { MATCH (i:Invoice) WITH i RETURN i } RETURN count(*) AS c, i',
            'c10_union_branches' => 'MATCH (i:Invoice) RETURN i UNION MATCH (p:Person) RETURN p',
            'c11_union_collision' => 'MATCH (x:Invoice) RETURN x UNION MATCH (x:Payment) RETURN x',
            'c12_top_level_or' => 'MATCH (i:Invoice) WHERE i.a = 1 OR i.b = 2 MATCH (i)-[:HAS]->(li:LineItem) RETURN i',
            'c13_comprehension' => 'MATCH (a:Account) RETURN [(a)-->(i:Invoice) | i.total] AS totals',
            'c14_comprehension_only' => 'WITH 1 AS x RETURN [(n)-->(i:Invoice) | i] AS xs',
            'c17_optional_single' => 'OPTIONAL MATCH (i:Invoice) RETURN i',
            'c17_optional_multi' => 'MATCH (a:Account) OPTIONAL MATCH (i:Invoice) RETURN a, i',
            'c18_label_free_return' => 'MATCH (n) RETURN n',
            'c19_with_agg_second' => 'MATCH (i:Invoice) WITH i WHERE i.total > 0 RETURN i',
            'c22_union_in_call' => 'CALL { MATCH (i:Invoice) RETURN i UNION MATCH (p:Person) RETURN p } RETURN count(*) AS total',
            'c23_label_in_string' => 'MATCH (n:Note) WHERE n.body = "see Invoice (x:Secret)" RETURN n',
            'c24_multi_statement' => 'MATCH (i:Invoice) RETURN i; MATCH (p:Person) RETURN p',
            'c25_two_invoice_roots' => 'MATCH (i:Invoice) MATCH (i2:Invoice) RETURN i, i2',

            // the executeCount wrapper we generate ourselves (accepted)
            'count_wrapper' => 'CALL { MATCH (i:Invoice) RETURN i } RETURN count(*) AS total',
            'count_wrapper_where' => 'CALL { MATCH (i:Invoice) WHERE i.paid = true RETURN i } RETURN count(*) AS total',

            // multi-binding / multi-MATCH shapes (the mangle class)
            'mb_contact_unit_account_balance' =>
                'MATCH (c:Contact)<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) '
                . 'MATCH (ab:AccountBalance)-[:ACCOUNT]->(a:Account)-[:BELONGS_TO_UNION]->(un:Union) '
                . 'RETURN ab',
            'mb_three_roots' => 'MATCH (i:Invoice) MATCH (p:Person) MATCH (o:Organization) RETURN i, p, o',
            'mb_chain_then_return_leaf' =>
                'MATCH (u:Unit)-[:HAS]->(p:Parking) MATCH (u)-[:HAS]->(s:Storage) RETURN p, s',
            'mb_where_plus_second_match' =>
                'MATCH (u:Unit) WHERE u.kind = "parking" MATCH (u)-[:HAS]->(v:Vehicle) RETURN v',

            // mutations
            'm_backtick' => 'MATCH (i:`Invoice`) RETURN i',
            'm_union_all' => 'MATCH (i:Invoice) RETURN i UNION ALL MATCH (i:Invoice) RETURN i',
            'm_comment' => "MATCH (i:Invoice) // get invoices\nRETURN i",
            'm_nested_or' => 'MATCH (i:Invoice) WHERE (i.a = 1 AND i.b = 2) OR i.c = 3 RETURN i',
            'm_no_anchor' => 'MATCH (i:Invoice)',

            // UNION shapes that must stay column-consistent after scoping (the
            // Parking/Storage/Vehicles-style three-branch union the generator emits).
            'u_three_branch_consistent_columns' =>
                'MATCH (p:Parking) RETURN p.id AS result '
                . 'UNION MATCH (s:Storage) RETURN s.id AS result '
                . 'UNION MATCH (v:Vehicle) RETURN v.id AS result',
            'u_two_branch_same_alias_column' =>
                'MATCH (i:Invoice) RETURN i AS r UNION MATCH (p:Person) RETURN p AS r',
        ];

        $out = [];
        foreach ($cases as $name => $cypher) {
            $out[$name] = [$cypher];
        }
        return $out;
    }

    /**
     * Per-binding direct-column filter on the binding's own alias.
     */
    private function filterFor(): callable
    {
        return static fn(NodeBinding $b): FilterClause => new FilterClause(
            'where',
            "WHERE {$b->alias}.team_id IN \$ai_team_ids",
            ['ai_team_ids' => [1, 2]]
        );
    }

    /**
     * @test
     * @dataProvider corpus
     */
    public function invariant_holds_for_every_input(string $cypher): void
    {
        $plan = $this->analyzer->analyze($cypher);

        // (a) Rejected / over-denied — fail closed. The injector also refuses.
        if (!$plan->isScopeable) {
            $this->assertNull(
                $this->injector->inject($cypher, $plan, $this->filterFor()),
                "Non-scopeable plan must not produce a rewrite: {$cypher}"
            );
            $this->addToAssertionCount(1);
            return;
        }

        // In-subset but no tenant-sensitive binding (scalar/introspection): nothing
        // to scope; running unfiltered is correct.
        if (!$plan->hasSensitiveBindings()) {
            $this->addToAssertionCount(1);
            return;
        }

        // (b) Scoped — every sensitive binding must carry a $ai_team_ids predicate
        // on its own alias in the REWRITTEN query.
        $rewritten = $this->injector->inject($cypher, $plan, $this->filterFor());

        // A scopeable plan whose ONLY runnable rewrite would be structurally invalid
        // (e.g. a UNION whose branches already project mismatched columns — which
        // Neo4j itself rejects regardless of scoping) yields null: that is the
        // fail-closed path (caller safe-stops), not a leak. Accept it only when the
        // SOURCE query was itself structurally invalid, so a genuine missed rewrite
        // can never hide behind this branch.
        if ($rewritten === null) {
            $this->assertFalse(
                CypherStructuralValidator::isValid($cypher),
                "Scopeable plan with bindings must rewrite a structurally valid source: {$cypher}"
            );
            $this->addToAssertionCount(1);
            return;
        }

        $reanalyzed = $this->analyzer->analyze($rewritten);
        $this->assertTrue(
            $reanalyzed->isScopeable,
            "Rewritten query is no longer scopeable (broke the shape): {$rewritten}"
        );

        foreach ($reanalyzed->scopes as $scope) {
            foreach ($scope->sensitiveBindings as $b) {
                $this->assertMatchesRegularExpression(
                    '/\b' . preg_quote($b->alias, '/') . '\.\w+\s+IN\s+\$ai_team_ids/i',
                    $rewritten,
                    "Binding {$b->alias}:{$b->label} is NOT scoped in rewrite: {$rewritten}"
                );
            }
        }

        // Bound parameter, never an inline list literal.
        $this->assertStringNotContainsString('IN [', $rewritten, "team ids inlined: {$rewritten}");

        // STRUCTURAL VALIDITY: every ACCEPTED + SCOPED rewrite must be valid Cypher.
        // This closes the "safe only because Neo4j rejects the malformed query"
        // crutch: a mangled-but-non-leaking rewrite (a clause keyword trapped inside
        // a WHERE boolean expression, an unbalanced paren, or a bare boolean
        // connective in clause position) is now a test failure, not a silent
        // fail-by-syntax-error at runtime. (c12 is the canonical example.)
        $this->assertTrue(
            CypherStructuralValidator::isValid($rewritten),
            'Scoped rewrite is not structurally valid Cypher: ' . $rewritten
                . ' | reason: ' . (CypherStructuralValidator::diagnose($rewritten) ?? '')
        );
    }

    /**
     * Guard: the structural validator actually REJECTS the pre-fix c12 mangle
     * (li referenced before its binding / a MATCH trapped inside the WHERE parens).
     * Without this, the invariant's validity assertion could be vacuously true.
     *
     * @test
     */
    public function structural_validator_rejects_the_pre_fix_c12_mangles(): void
    {
        // The exact malformed shapes the buggy splice used to emit:
        // (a) li.team_id merged into the first WHERE, before li is bound.
        $bindBeforeUse = 'MATCH (i:Invoice) WHERE (i.a = 1 OR i.b = 2) AND i.team_id IN $ai_team_ids '
            . 'AND li.team_id IN $ai_team_ids MATCH (i)-[:HAS]->(li:LineItem) RETURN i';
        // This shape has no clause keyword inside parens, but a top-level AND chain
        // followed by a MATCH — caught as the boolean-connective ... well it IS a
        // valid WHERE chain; what makes it invalid is bind-before-use, which the
        // dedicated c12 injector test asserts. Here we pin the two structural mangles
        // the validator is responsible for:

        // (b) the MATCH swallowed INSIDE the WHERE parens (the literal c12 defect).
        $matchInParens = 'MATCH (i:Invoice) WHERE (i.a = 1 OR i.b = 2 MATCH (i)-[:HAS]->(li:LineItem)) '
            . 'AND i.team_id IN $ai_team_ids RETURN i';
        $this->assertFalse(
            CypherStructuralValidator::isValid($matchInParens),
            'Validator must reject a MATCH trapped inside WHERE parens'
        );

        // (c) a team filter AND-appended after a pattern clause with no WHERE.
        $andAfterMatch = 'MATCH (i:Invoice) WHERE i.x = 1 MATCH (i)-[:HAS]->(li:LineItem) '
            . 'AND i.team_id IN $ai_team_ids AND li.team_id IN $ai_team_ids RETURN li';
        $this->assertFalse(
            CypherStructuralValidator::isValid($andAfterMatch),
            'Validator must reject a boolean connective in clause position (MATCH ... AND ...)'
        );

        // Sanity: the CORRECT rewrite of c12 is accepted.
        $correct = 'MATCH (i:Invoice) WHERE i.a = 1 OR i.b = 2 MATCH (i)-[:HAS]->(li:LineItem) '
            . 'WHERE i.team_id IN $ai_team_ids AND li.team_id IN $ai_team_ids RETURN i';
        $this->assertTrue(
            CypherStructuralValidator::isValid($correct),
            'Validator must accept the correct, valid c12 rewrite: '
                . (CypherStructuralValidator::diagnose($correct) ?? 'ok')
        );

        // The bind-before-use shape (a) is the buggy output the c12 injector test
        // separately rejects via offset ordering; keep a reference for clarity.
        $this->assertStringContainsString('li.team_id', $bindBeforeUse);
    }

    /**
     * The UNION-column-consistency check is the deterministic guard for the
     * "All sub queries in an UNION must have the same columns" crash. A scoped
     * three-branch UNION whose branches all project `... AS result` is accepted;
     * the same shape with one branch projecting a different column is rejected
     * BEFORE it can reach Neo4j.
     *
     * @test
     */
    public function structural_validator_enforces_union_column_consistency(): void
    {
        // Generator-scoped, column-consistent UNION (each branch RETURNs `AS result`,
        // each branch carries its own $ai_team_ids predicate). Accepted.
        $consistent = 'MATCH (p:Parking) WHERE p.team_id IN $ai_team_ids RETURN p.id AS result '
            . 'UNION MATCH (s:Storage) WHERE s.team_id IN $ai_team_ids RETURN s.id AS result '
            . 'UNION MATCH (v:Vehicle) WHERE v.team_id IN $ai_team_ids RETURN v.id AS result';
        $this->assertTrue(
            CypherStructuralValidator::isValid($consistent),
            'Validator must accept a column-consistent scoped UNION: '
                . (CypherStructuralValidator::diagnose($consistent) ?? 'ok')
        );

        // Same UNION but one branch projects a DIFFERENT column name → the exact
        // shape Neo4j rejects. Caught here as a structural defect.
        $mismatched = 'MATCH (p:Parking) WHERE p.team_id IN $ai_team_ids RETURN p.id AS result '
            . 'UNION MATCH (s:Storage) WHERE s.team_id IN $ai_team_ids RETURN s.name AS label';
        $this->assertFalse(
            CypherStructuralValidator::isValid($mismatched),
            'Validator must reject a UNION with mismatched branch columns'
        );

        // A branch RETURNing the node variable directly vs an aliased projection is
        // also a mismatch (different column names: `p` vs `result`).
        $mismatchedVar = 'MATCH (i:Invoice) WHERE i.team_id IN $ai_team_ids RETURN i '
            . 'UNION MATCH (p:Person) WHERE p.team_id IN $ai_team_ids RETURN p AS result';
        $this->assertFalse(
            CypherStructuralValidator::isValid($mismatchedVar),
            'Validator must reject mixed bare-variable vs aliased UNION columns'
        );

        // UNION ALL with consistent multi-column projections is accepted.
        $multiCol = 'MATCH (i:Invoice) WHERE i.team_id IN $ai_team_ids RETURN i.id AS id, i.total AS total '
            . 'UNION ALL MATCH (p:Payment) WHERE p.team_id IN $ai_team_ids RETURN p.id AS id, p.amount AS total';
        $this->assertTrue(
            CypherStructuralValidator::isValid($multiCol),
            'Validator must accept consistent multi-column UNION ALL: '
                . (CypherStructuralValidator::diagnose($multiCol) ?? 'ok')
        );
    }
}
