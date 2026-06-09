<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Services\Security\Scope\FilterClause;
use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Services\Security\SecurityFilterInjector;
use Condoedge\Ai\Tests\TestCase;

/**
 * Injector-level tests: given a proven-scopeable plan, the rewritten query must
 * carry a $ai_team_ids predicate on EVERY sensitive binding's own live alias, on
 * valid Cypher, and the team IDs must stay bound parameters (never inline IN[...]).
 *
 * The invariant checker re-analyzes the OUTPUT and asserts every sensitive
 * binding now has a $ai_team_ids predicate on a live alias (or the query was
 * rejected/denied) — "safe by construction", continuously verifiable.
 */
class SecurityFilterInjectorTest extends TestCase
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
     * Default per-binding filter: a direct-column predicate on the binding's own
     * alias, referencing the bound $ai_team_ids parameter.
     */
    private function directFilter(): callable
    {
        return static fn(NodeBinding $b): FilterClause => new FilterClause(
            'where',
            "WHERE {$b->alias}.team_id IN \$ai_team_ids",
            ['ai_team_ids' => [1, 2]]
        );
    }

    private function inject(string $cypher, ?callable $filterFor = null): ?string
    {
        $plan = $this->analyzer->analyze($cypher);
        return $this->injector->inject($cypher, $plan, $filterFor ?? $this->directFilter());
    }

    /**
     * INVARIANT CHECK: re-analyze the rewritten query and assert every sensitive
     * binding carries a $ai_team_ids predicate referencing its own alias.
     */
    private function assertEverySensitiveBindingScoped(string $rewritten): void
    {
        $plan = $this->analyzer->analyze($rewritten);
        $this->assertTrue($plan->isScopeable, "Rewritten query is not even scopeable: {$rewritten}");

        foreach ($plan->scopes as $scope) {
            foreach ($scope->sensitiveBindings as $b) {
                $this->assertMatchesRegularExpression(
                    '/\b' . preg_quote($b->alias, '/') . '\.\w+\s+IN\s+\$ai_team_ids/i',
                    $rewritten,
                    "Binding {$b->alias}:{$b->label} is NOT scoped in: {$rewritten}"
                );
            }
        }

        $this->assertStringNotContainsString('IN [', $rewritten, 'team ids must stay bound, not inline');
    }

    /** @test */
    public function injects_where_when_no_where_exists(): void
    {
        $out = $this->inject('MATCH (i:Invoice) RETURN i');
        $this->assertStringContainsString('WHERE i.team_id IN $ai_team_ids', $out);
        $this->assertLessThan(strpos($out, 'RETURN'), strpos($out, 'WHERE i.team_id'));
        $this->assertEverySensitiveBindingScoped($out);
    }

    /** @test */
    public function merges_with_existing_where_via_and(): void
    {
        $out = $this->inject('MATCH (i:Invoice) WHERE i.status = "active" RETURN i');
        $this->assertStringContainsString('i.status = "active"', $out);
        $this->assertStringContainsString('AND i.team_id IN $ai_team_ids', $out);
        $this->assertEverySensitiveBindingScoped($out);
    }

    /** @test */
    public function parenthesizes_top_level_or_before_anding_team_filter(): void
    {
        // `a OR b AND team` would bind as `a OR (b AND team)` (leak). Must group.
        $out = $this->inject('MATCH (i:Invoice) WHERE i.status = "active" OR i.flagged = true RETURN i');
        $this->assertStringContainsString(
            '(i.status = "active" OR i.flagged = true) AND i.team_id IN $ai_team_ids',
            $out
        );
        $this->assertEverySensitiveBindingScoped($out);
    }

    /** @test */
    public function corpus_1_scopes_after_leading_with_const(): void
    {
        $out = $this->inject('WITH 1 AS one MATCH (i:Invoice) RETURN i');
        // Predicate must follow the MATCH (i) — not be spliced at offset 0.
        $matchPos = strpos($out, 'MATCH (i:Invoice)');
        $wherePos = strpos($out, 'WHERE i.team_id');
        $this->assertNotFalse($wherePos);
        $this->assertGreaterThan($matchPos, $wherePos, "Predicate spliced before its MATCH: {$out}");
        $this->assertEverySensitiveBindingScoped($out);
    }

    /** @test */
    public function corpus_5_scopes_both_invoice_root_and_returned_child(): void
    {
        $out = $this->inject('MATCH (i:Invoice) MATCH (i)-[:HAS]->(li:LineItem) RETURN li');
        $this->assertEverySensitiveBindingScoped($out);
        $this->assertStringContainsString('i.team_id IN $ai_team_ids', $out);
        $this->assertStringContainsString('li.team_id IN $ai_team_ids', $out);
    }

    /** @test */
    public function corpus_12_top_level_or_with_intervening_match_produces_valid_scoped_cypher(): void
    {
        // c12: a top-level OR WHERE followed by a 2nd MATCH (introducing li) before
        // the anchor. The first WHERE is already CLOSED by that intervening MATCH, so
        // the team filter must NOT be AND-ed into it (doing so would either swallow
        // the MATCH inside the OR-parens, or reference li before it is bound — both
        // invalid Cypher). Instead a fresh WHERE after the 2nd MATCH scopes BOTH
        // bindings on their own live aliases.
        $out = $this->inject(
            'MATCH (i:Invoice) WHERE i.a = 1 OR i.b = 2 MATCH (i)-[:HAS]->(li:LineItem) RETURN i'
        );

        // The original OR predicate is preserved verbatim (its WHERE is left alone —
        // nothing is AND-ed into it, so it needs no parenthesizing).
        $this->assertStringContainsString('WHERE i.a = 1 OR i.b = 2', $out);

        // The intervening MATCH is preserved verbatim and NOT swallowed into a WHERE
        // boolean expression / parens.
        $this->assertStringContainsString('MATCH (i)-[:HAS]->(li:LineItem)', $out);
        $this->assertStringNotContainsString(
            'MATCH (i)-[:HAS]->(li:LineItem))',
            $out,
            "Intervening MATCH was swallowed inside the WHERE parens: {$out}"
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\([^()]*\bMATCH\b/i',
            $out,
            "A MATCH clause keyword is trapped inside a WHERE boolean expression: {$out}"
        );

        // Both bindings are scoped on their own alias.
        $this->assertStringContainsString('i.team_id IN $ai_team_ids', $out);
        $this->assertStringContainsString('li.team_id IN $ai_team_ids', $out);
        $this->assertEverySensitiveBindingScoped($out);

        // VALIDITY: li is introduced by the SECOND MATCH; its team predicate MUST
        // appear AFTER that binding (a binding cannot be referenced before it is
        // bound). Before the fix, li.team_id was merged into the first WHERE — at an
        // offset BEFORE `MATCH (...li...)` — yielding Cypher Neo4j rejects.
        $this->assertGreaterThan(
            strpos($out, 'MATCH (i)-[:HAS]->(li:LineItem)'),
            strpos($out, 'li.team_id IN $ai_team_ids'),
            "li.team_id referenced before li is bound (invalid Cypher): {$out}"
        );

        // VALIDITY: the whole rewrite is structurally valid Cypher (balanced parens,
        // no clause keyword trapped inside a WHERE boolean expression, no bare
        // boolean connective in clause position).
        $this->assertTrue(
            CypherStructuralValidator::isValid($out),
            'c12 rewrite is not structurally valid Cypher: ' . $out
                . ' | reason: ' . (CypherStructuralValidator::diagnose($out) ?? '')
        );
    }

    /** @test */
    public function corpus_7_scopes_both_independent_roots(): void
    {
        $out = $this->inject('MATCH (i:Invoice) MATCH (p:Person) RETURN i, p');
        $this->assertEverySensitiveBindingScoped($out);
    }

    /** @test */
    public function corpus_10_scopes_each_union_branch_on_own_binding(): void
    {
        // Column-consistent UNION (both branches RETURN ... AS r): Neo4j requires
        // identical column names across branches, so the realistic generator shape
        // projects a shared alias. Each branch is still scoped on its own binding.
        $out = $this->inject('MATCH (i:Invoice) RETURN i AS r UNION MATCH (p:Person) RETURN p AS r');
        $this->assertStringContainsString('UNION', $out);
        $this->assertStringContainsString('i.team_id IN $ai_team_ids', $out);
        $this->assertStringContainsString('p.team_id IN $ai_team_ids', $out);
        $this->assertEverySensitiveBindingScoped($out);
    }

    /** @test */
    public function corpus_25_scopes_two_invoice_roots_distinctly(): void
    {
        $out = $this->inject('MATCH (i:Invoice) MATCH (i2:Invoice) RETURN i, i2');
        $this->assertStringContainsString('i.team_id IN $ai_team_ids', $out);
        $this->assertStringContainsString('i2.team_id IN $ai_team_ids', $out);
        $this->assertEverySensitiveBindingScoped($out);
    }

    /** @test */
    public function match_based_filter_is_spliced_before_anchor(): void
    {
        $matchFilter = static fn(NodeBinding $b): FilterClause => new FilterClause(
            'match',
            "MATCH ({$b->alias})-[:BELONGS_TO]->(t:Team) WHERE t.id IN \$ai_team_ids",
            ['ai_team_ids' => [1, 2]]
        );

        $out = $this->inject('MATCH (i:Invoice) WITH i RETURN i', $matchFilter);
        $this->assertNotNull($out);
        $this->assertLessThan(strpos($out, 'WITH i'), strpos($out, '[:BELONGS_TO]'));
    }

    // =====================================================================
    // Multi-binding rewrite corpus: each shape mixes a relationship/parent
    // 'match' filter (carries its OWN WHERE) with a direct 'where' filter in a
    // single scope. The pre-fix splicer emitted two adjacent WHEREs (`… WHERE …
    // WHERE …`) and re-used generated inner aliases — invalid Cypher Neo4j
    // rejected with a SyntaxError. Every case below must be VALID Cypher AND have
    // each sensitive binding scoped exactly once on its own / a fresh alias.
    // =====================================================================

    /**
     * Realistic per-binding resolver mirroring TeamPathResolver output: direct
     * column for some labels, single/multi-hop relationship MATCH for others.
     */
    private function mixedFilter(): callable
    {
        return static function (NodeBinding $b): ?FilterClause {
            return match ($b->label) {
                'Contact', 'Union', 'Person', 'Invoice' => new FilterClause(
                    'where',
                    "WHERE {$b->alias}.team_id IN \$ai_team_ids",
                    ['ai_team_ids' => [14]]
                ),
                'Unit' => new FilterClause(
                    'match',
                    "MATCH ({$b->alias})-[:UNION]->(u1:Union) WHERE u1.team_id IN \$ai_team_ids",
                    ['ai_team_ids' => [14]]
                ),
                'AccountBalance' => new FilterClause(
                    'match',
                    "MATCH ({$b->alias})-[:ACCOUNT]->(a1:Account) MATCH (a1)-[:BELONGS_TO_UNION]->(u1:Union) WHERE u1.team_id IN \$ai_team_ids",
                    ['ai_team_ids' => [14]]
                ),
                'Account', 'Vehicle' => new FilterClause(
                    'match',
                    "MATCH ({$b->alias})-[:BELONGS_TO_UNION]->(u1:Union) WHERE u1.team_id IN \$ai_team_ids",
                    ['ai_team_ids' => [14]]
                ),
                'ContactUnit' => null, // join node, no team_path → bypassed
                default => new FilterClause(
                    'where',
                    "WHERE {$b->alias}.team_id IN \$ai_team_ids",
                    ['ai_team_ids' => [14]]
                ),
            };
        };
    }

    /**
     * Assert the rewrite is structurally valid Cypher, has no two adjacent WHEREs,
     * and scopes every (non-bypassed) sensitive binding exactly once.
     *
     * @param array<string,string> $expectedAliasColumns alias => the predicate alias
     *        that must carry the $ai_team_ids filter (a fresh alias for match paths).
     */
    private function assertValidMultiBindingRewrite(string $out, array $mustContain): void
    {
        $this->assertNotNull($out);
        $this->assertTrue(
            CypherStructuralValidator::isValid($out),
            'Rewrite is not structurally valid Cypher: ' . $out
                . ' | reason: ' . (CypherStructuralValidator::diagnose($out) ?? '')
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\bWHERE\b[^()]*?\bWHERE\b(?![^()]*\))/i',
            $out,
            "Two adjacent WHERE clauses in rewrite: {$out}"
        );
        $this->assertStringNotContainsString('IN [', $out, 'team ids must stay bound');
        foreach ($mustContain as $needle) {
            $this->assertStringContainsString($needle, $out, "Missing `{$needle}` in: {$out}");
        }
    }

    /** @test */
    public function multi_binding_contact_unit_account_balance_is_valid_and_scoped(): void
    {
        // The exact live-failing shape: Contact (direct) + Union (direct) + Unit,
        // AccountBalance, Account (relationship/parent MATCH paths), all in ONE
        // scope anchored by a single RETURN.
        $cypher = 'MATCH (c:Contact {user_id: 1512})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) '
            . 'MATCH (ab:AccountBalance)-[:ACCOUNT]->(a:Account)-[:BELONGS_TO_UNION]->(un:Union) '
            . 'MATCH (cu)-[:UNIT]->(u) RETURN ab';

        $out = $this->inject($cypher, $this->mixedFilter());

        $this->assertValidMultiBindingRewrite($out, [
            'c.team_id IN $ai_team_ids',   // Contact direct
            'un.team_id IN $ai_team_ids',  // Union direct
            'MATCH (u)-[:UNION]->',        // Unit relationship path
            'MATCH (ab)-[:ACCOUNT]->',     // AccountBalance multi-hop path
            'MATCH (a)-[:BELONGS_TO_UNION]->', // Account parent path
        ]);

        // The two direct predicates share ONE leading WHERE before the MATCH blocks.
        $this->assertStringContainsString(
            'WHERE c.team_id IN $ai_team_ids AND un.team_id IN $ai_team_ids',
            $out
        );
        // Generated inner aliases are uniquified — no re-declared variable.
        $this->assertMatchesRegularExpression('/\bai_s\d+:Union\b/', $out);
        $this->assertSame(
            0,
            substr_count($out, '(u1:Union)'),
            "Generated alias u1 leaked / collided: {$out}"
        );
    }

    /** @test */
    public function multi_binding_union_query_keeps_branches_column_consistent(): void
    {
        // Each UNION branch is its own scope; the rewrite must keep both branches
        // returning the same single column NAME (Neo4j requirement) and be valid
        // Cypher. The realistic generator emits a shared `AS r` projection.
        $cypher = 'MATCH (i:Invoice) RETURN i AS r UNION MATCH (p:Person) RETURN p AS r';

        $out = $this->inject($cypher, $this->mixedFilter());

        $this->assertValidMultiBindingRewrite($out, [
            'i.team_id IN $ai_team_ids',
            'p.team_id IN $ai_team_ids',
            'UNION',
        ]);
        // Column consistency: still exactly `RETURN i AS r` and `RETURN p AS r`.
        $this->assertSame(1, substr_count($out, 'RETURN i AS r'));
        $this->assertSame(1, substr_count($out, 'RETURN p AS r'));
    }

    /** @test */
    public function multi_binding_two_independent_relationship_paths_uniquify_aliases(): void
    {
        // Two parent-path bindings in one scope both resolve to (…)->(u1:Union):
        // their generated aliases MUST be uniquified or Neo4j errors on a
        // re-declared variable.
        $cypher = 'MATCH (a:Account) MATCH (v:Vehicle) RETURN a, v';

        $out = $this->inject($cypher, $this->mixedFilter());

        $this->assertValidMultiBindingRewrite($out, [
            'MATCH (a)-[:BELONGS_TO_UNION]->',
            'MATCH (v)-[:BELONGS_TO_UNION]->',
        ]);
        // The two fresh Union aliases differ.
        preg_match_all('/\b(ai_s\d+):Union\b/', $out, $m);
        $this->assertCount(2, $m[1]);
        $this->assertNotSame($m[1][0], $m[1][1], "Two paths re-used the same alias: {$out}");
    }

    /** @test */
    public function multi_binding_match_path_plus_direct_in_same_scope_is_valid(): void
    {
        // A relationship MATCH path (Unit) alongside a direct binding (Invoice) in
        // ONE scope: the direct WHERE must lead, the MATCH block follow — never two
        // adjacent WHEREs.
        $cypher = 'MATCH (u:Unit) MATCH (i:Invoice) RETURN u, i';

        $out = $this->inject($cypher, $this->mixedFilter());

        $this->assertValidMultiBindingRewrite($out, [
            'WHERE i.team_id IN $ai_team_ids',
            'MATCH (u)-[:UNION]->',
        ]);
    }

    /** @test */
    public function multi_binding_existing_where_plus_relationship_path_is_valid(): void
    {
        // Existing user WHERE on the Unit, plus a relationship MATCH path scoping
        // it, plus a second relationship binding: clause order must stay valid.
        $cypher = 'MATCH (u:Unit) WHERE u.kind = "parking" MATCH (u)-[:HAS]->(v:Vehicle) RETURN v';

        $out = $this->inject($cypher, $this->mixedFilter());

        $this->assertValidMultiBindingRewrite($out, [
            'u.kind = "parking"',
            'MATCH (u)-[:UNION]->',          // Unit path
            'MATCH (v)-[:BELONGS_TO_UNION]->', // Vehicle path
        ]);
    }

    /** @test */
    public function count_wrapper_scopes_inner_leaves_outer_untouched(): void
    {
        $out = $this->inject('CALL { MATCH (i:Invoice) RETURN i } RETURN count(*) AS total');
        $this->assertNotNull($out);
        $this->assertStringContainsString('i.team_id IN $ai_team_ids', $out);
        // Outer aggregation untouched.
        $this->assertStringContainsString('RETURN count(*) AS total', $out);
        // The predicate must live INSIDE the braces.
        $open = strpos($out, '{');
        $close = strpos($out, '}');
        $predicate = strpos($out, 'i.team_id IN $ai_team_ids');
        $this->assertGreaterThan($open, $predicate);
        $this->assertLessThan($close, $predicate);
    }

    /** @test */
    public function order_anchor_is_respected(): void
    {
        $out = $this->inject('MATCH (i:Invoice) ORDER BY i.created_at RETURN i');
        $this->assertLessThan(strpos($out, 'ORDER BY'), strpos($out, 'WHERE i.team_id'));
        $this->assertEverySensitiveBindingScoped($out);
    }

    /** @test */
    public function label_in_string_literal_is_not_spliced(): void
    {
        $out = $this->inject('MATCH (n:Note) WHERE n.body = "see Invoice (x:Secret)" RETURN n');
        // Only one real binding scoped (n); the literal is untouched verbatim.
        $this->assertStringContainsString('"see Invoice (x:Secret)"', $out);
        $this->assertStringContainsString('n.team_id IN $ai_team_ids', $out);
        $this->assertEverySensitiveBindingScoped($out);
    }

    /** @test */
    public function non_scopeable_plan_returns_null(): void
    {
        // No anchor → not scopeable → injector refuses (caller fails closed).
        $plan = $this->analyzer->analyze('MATCH (i:Invoice)');
        $this->assertNull($this->injector->inject('MATCH (i:Invoice)', $plan, $this->directFilter()));
    }
}
