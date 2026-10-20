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
 * OPTIONAL MATCH predicate PLACEMENT (security review 2026-10-01, P1).
 *
 * Cypher attaches a WHERE to the clause directly before it. A WHERE on an
 * OPTIONAL MATCH only decides whether THAT optional pattern matches — it never
 * filters rows or variables bound by EARLIER clauses. The injector used to put
 * every optional binding's null-tolerant scope into the WHERE of the LAST
 * clause before the anchor:
 *
 *   MATCH (a:Team) WHERE a.id = 1 OPTIONAL MATCH (x:Invoice)
 *   OPTIONAL MATCH (y:Contact) WHERE (x IS NULL OR <scope x>) AND (y IS NULL OR …)
 *
 * so the x predicate only decided whether y matched: a cross-tenant x row
 * survived with y = null. Live proof (local Neo4j, $ai_team_ids = [3]): the
 * injected reviewer query returned all 6006 invoices, 3963 of them out of
 * tenant (team 3 owns 2043).
 *
 * INVARIANT pinned here: every OPTIONAL binding's scope sits in the WHERE of
 * the OPTIONAL MATCH clause that BINDS it (no match → null, row survives,
 * cross-tenant value never surfaces).
 */
class OptionalMatchClausePlacementTest extends TestCase
{
    private CypherScopeAnalyzer $analyzer;
    private SecurityFilterInjector $injector;

    public function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new CypherScopeAnalyzer();
        $this->injector = new SecurityFilterInjector();
    }

    private function direct(): callable
    {
        return static fn (NodeBinding $b): FilterClause => new FilterClause(
            'where',
            "WHERE {$b->alias}.team_id IN \$ai_team_ids",
            ['ai_team_ids' => [3]]
        );
    }

    /** The real CondoEdge rules for the roster labels (TeamPathResolver output). */
    private function condoedge(): callable
    {
        return static function (NodeBinding $b): FilterClause {
            $a = $b->alias;
            $f = match ($b->label) {
                'Team' => "WHERE {$a}.id IN \$ai_team_ids",
                'User' => "WHERE {$a}.current_team_id IN \$ai_team_ids",
                'ContactDirectory' => "MATCH ({$a})-[:CONTACT]->(c:Contact) WHERE c.team_id IN \$ai_team_ids",
                'Invoice' => "MATCH ({$a})-[:BELONGS_TO_UNION]->(u:Union) WHERE u.team_id IN \$ai_team_ids",
                default => "WHERE {$a}.team_id IN \$ai_team_ids",
            };
            $kind = preg_match('/\bMATCH\b/', $f) ? 'match' : 'where';
            return new FilterClause($kind, $f, ['ai_team_ids' => [3]]);
        };
    }

    private function inject(string $cypher, callable $filterFor): ?string
    {
        $plan = $this->analyzer->analyze($cypher);
        $this->assertTrue($plan->isScopeable, "not scopeable: {$plan->reason}");
        return $this->injector->inject($cypher, $plan, $filterFor);
    }

    /**
     * Structural check: for every `(<alias> IS NULL OR` scope predicate, the
     * WHERE carrying it must belong to the OPTIONAL MATCH clause that binds
     * <alias>, or be a row-level `WITH * WHERE`.
     */
    private function assertEachOptionalScopeInItsOwnClause(string $out, array $aliases): void
    {
        $tokens = (new CypherLexer())->tokenize($out)['tokens'];
        $n = count($tokens);
        foreach ($aliases as $alias) {
            $found = false;
            for ($i = 0; $i < $n - 3; $i++) {
                if (!($tokens[$i]->type === CypherLexer::T_WORD && $tokens[$i]->value === $alias
                    && $tokens[$i + 1]->isWord('IS') && $tokens[$i + 2]->isWord('NULL') && $tokens[$i + 3]->isWord('OR'))) {
                    continue;
                }
                $found = true;
                // Walk back to the WHERE at depth 0, then to the clause head it belongs to.
                $w = $i - 1;
                while ($w >= 0 && !($tokens[$w]->isWord('WHERE') && $tokens[$w]->isTopLevel())) {
                    $w--;
                }
                $this->assertGreaterThanOrEqual(0, $w, "no WHERE for {$alias}: {$out}");
                $h = $w - 1;
                while ($h >= 0 && !($tokens[$h]->isTopLevel() && $tokens[$h]->type === CypherLexer::T_WORD
                    && in_array(strtoupper($tokens[$h]->value), ['MATCH', 'WITH', 'UNWIND', 'RETURN'], true))) {
                    $h--;
                }
                $this->assertTrue($tokens[$h]->isWord('MATCH') && $tokens[$h - 1]->isWord('OPTIONAL'),
                    "scope for {$alias} is not in an OPTIONAL MATCH WHERE: {$out}");
                // That OPTIONAL MATCH must bind the alias: `( alias :` between MATCH and WHERE.
                $binds = false;
                for ($k = $h + 1; $k < $w - 1; $k++) {
                    if ($tokens[$k]->isPunct('(') && $tokens[$k + 1]->value === $alias && $tokens[$k + 2]->isPunct(':')) {
                        $binds = true;
                    }
                }
                $this->assertTrue($binds, "scope for {$alias} sits in a DIFFERENT OPTIONAL MATCH's WHERE (filters the wrong pattern): {$out}");
            }
            $this->assertTrue($found, "no null-tolerant scope for optional {$alias}: {$out}");
        }
    }

    private function assertValid(string $out): void
    {
        $this->assertTrue(
            CypherStructuralValidator::isValid($out),
            'Rewrite not structurally valid: ' . $out . ' | ' . (CypherStructuralValidator::diagnose($out) ?? '')
        );
    }

    /** @test */
    public function reviewer_query_each_optional_scope_lands_in_its_own_optional_match(): void
    {
        $q = 'MATCH (a:Team) WHERE a.id = 1 OPTIONAL MATCH (x:Invoice) OPTIONAL MATCH (y:Contact) RETURN a.name, x.amount, y.value LIMIT 100';
        $out = $this->inject($q, $this->direct());

        $this->assertSame(
            'MATCH (a:Team) WHERE a.id = 1 OPTIONAL MATCH (x:Invoice) WHERE (x IS NULL OR x.team_id IN $ai_team_ids) '
            . 'OPTIONAL MATCH (y:Contact) WHERE (y IS NULL OR y.team_id IN $ai_team_ids) '
            . 'WITH * WHERE a.team_id IN $ai_team_ids RETURN a.name, x.amount, y.value LIMIT 100',
            $out
        );
        $this->assertEachOptionalScopeInItsOwnClause($out, ['x', 'y']);
        $this->assertValid($out);
    }

    /** @test */
    public function reviewer_query_with_relationship_scope_uses_exists_in_own_clause(): void
    {
        $q = 'MATCH (a:Team) WHERE a.id = 3 OPTIONAL MATCH (x:Invoice) OPTIONAL MATCH (y:Contact) RETURN a.id, x.id, y.id';
        $out = $this->inject($q, $this->condoedge());

        $this->assertMatchesRegularExpression(
            '/OPTIONAL MATCH \(x:Invoice\) WHERE \(x IS NULL OR EXISTS \{ MATCH \(x\)-\[:BELONGS_TO_UNION\]->\((ai_s\d+):Union\) WHERE \1\.team_id IN \$ai_team_ids \}\) OPTIONAL MATCH \(y:Contact\)/',
            $out
        );
        $this->assertEachOptionalScopeInItsOwnClause($out, ['x', 'y']);
        $this->assertValid($out);
    }

    /** @test */
    public function live_roster_query_scopes_each_optional_hop_in_its_own_clause(): void
    {
        $q = "MATCH (t:Team) WHERE t.id IN \$ai_team_ids AND t.id = 3\n"
            . "OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t)\n"
            . "OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id\n"
            . "OPTIONAL MATCH (c:Contact)-[:USER]->(u)\n"
            . "OPTIONAL MATCH (cd:ContactDirectory)-[:CONTACT]->(c)\n"
            . 'RETURN u.name AS member_name, tr.role AS role, cd.value AS contact_info LIMIT 100';
        $out = $this->inject($q, $this->condoedge());

        $this->assertStringContainsString(
            'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t)' . "\n" . 'WHERE (tr IS NULL OR tr.team_id IN $ai_team_ids) OPTIONAL MATCH (u:User)',
            $out
        );
        // Existing WHERE on the optional clause: parenthesized and AND-merged.
        $this->assertStringContainsString(
            'OPTIONAL MATCH (u:User) WHERE (u.id = tr.user_id) AND (u IS NULL OR u.current_team_id IN $ai_team_ids) OPTIONAL MATCH (c:Contact)',
            $out
        );
        $this->assertStringContainsString('OPTIONAL MATCH (c:Contact)-[:USER]->(u)' . "\n" . 'WHERE (c IS NULL OR c.team_id IN $ai_team_ids) OPTIONAL MATCH (cd', $out);
        $this->assertMatchesRegularExpression('/OPTIONAL MATCH \(cd:ContactDirectory\)-\[:CONTACT\]->\(c\)\s*WHERE \(cd IS NULL OR EXISTS \{/', $out);
        // Mandatory t stays row-level.
        $this->assertStringContainsString('WITH * WHERE t.id IN $ai_team_ids RETURN u.name', $out);
        $this->assertEachOptionalScopeInItsOwnClause($out, ['tr', 'u', 'c', 'cd']);
        $this->assertValid($out);
    }

    /** @test */
    public function several_variables_bound_by_one_optional_match_share_its_where(): void
    {
        $q = 'MATCH (a:Team) OPTIONAL MATCH (x:Invoice)-[:R]->(y:Contact) OPTIONAL MATCH (z:Unit) RETURN a, x, y, z';
        $out = $this->inject($q, $this->direct());

        $this->assertStringContainsString(
            'OPTIONAL MATCH (x:Invoice)-[:R]->(y:Contact) WHERE (x IS NULL OR x.team_id IN $ai_team_ids) AND (y IS NULL OR y.team_id IN $ai_team_ids) OPTIONAL MATCH (z:Unit) WHERE (z IS NULL OR z.team_id IN $ai_team_ids)',
            $out
        );
        $this->assertEachOptionalScopeInItsOwnClause($out, ['x', 'y', 'z']);
        $this->assertValid($out);
    }

    /** @test */
    public function comma_separated_optional_patterns_share_the_clause_where(): void
    {
        $q = 'MATCH (a:Team) OPTIONAL MATCH (x:Invoice), (y:Contact) OPTIONAL MATCH (z:Unit) RETURN a, x, y, z';
        $out = $this->inject($q, $this->direct());

        $this->assertStringContainsString(
            'OPTIONAL MATCH (x:Invoice), (y:Contact) WHERE (x IS NULL OR x.team_id IN $ai_team_ids) AND (y IS NULL OR y.team_id IN $ai_team_ids) OPTIONAL MATCH (z:Unit)',
            $out
        );
        $this->assertEachOptionalScopeInItsOwnClause($out, ['x', 'y', 'z']);
        $this->assertValid($out);
    }

    /** @test */
    public function existing_top_level_or_and_xor_in_optional_where_are_parenthesized(): void
    {
        $q = 'MATCH (a:Team) OPTIONAL MATCH (x:Invoice) WHERE x.a = 1 OR x.b = 2 OPTIONAL MATCH (y:Contact) WHERE y.k = 1 XOR y.j = 2 RETURN a, x, y';
        $out = $this->inject($q, $this->direct());

        $this->assertStringContainsString('OPTIONAL MATCH (x:Invoice) WHERE (x.a = 1 OR x.b = 2) AND (x IS NULL OR x.team_id IN $ai_team_ids) OPTIONAL MATCH (y:Contact)', $out);
        $this->assertStringContainsString('WHERE (y.k = 1 XOR y.j = 2) AND (y IS NULL OR y.team_id IN $ai_team_ids)', $out);
        $this->assertEachOptionalScopeInItsOwnClause($out, ['x', 'y']);
        $this->assertValid($out);
    }

    /** @test */
    public function optional_where_containing_a_subquery_match_is_not_split(): void
    {
        $q = 'MATCH (a:Team) OPTIONAL MATCH (x:Invoice) WHERE EXISTS { MATCH (x)-[:R]->(a) } OPTIONAL MATCH (y:Contact) RETURN a, x, y';
        $out = $this->inject($q, $this->direct());

        $this->assertStringContainsString(
            'OPTIONAL MATCH (x:Invoice) WHERE (EXISTS { MATCH (x)-[:R]->(a) }) AND (x IS NULL OR x.team_id IN $ai_team_ids) OPTIONAL MATCH (y:Contact)',
            $out
        );
        $this->assertEachOptionalScopeInItsOwnClause($out, ['x', 'y']);
        $this->assertValid($out);
    }

    /** @test */
    public function with_chain_between_optional_matches_scopes_each_in_its_segment_clause(): void
    {
        $q = 'MATCH (a:Team) OPTIONAL MATCH (x:Invoice) OPTIONAL MATCH (w:Unit) WITH a, x, w '
            . 'OPTIONAL MATCH (y:Contact) OPTIONAL MATCH (z:Payment) RETURN a, x, w, y, z';
        $out = $this->inject($q, $this->direct());

        $this->assertStringContainsString('OPTIONAL MATCH (x:Invoice) WHERE (x IS NULL OR x.team_id IN $ai_team_ids) OPTIONAL MATCH (w:Unit) WHERE (w IS NULL OR w.team_id IN $ai_team_ids) WITH * WHERE a.team_id IN $ai_team_ids WITH a, x, w', $out);
        $this->assertStringContainsString('OPTIONAL MATCH (y:Contact) WHERE (y IS NULL OR y.team_id IN $ai_team_ids) OPTIONAL MATCH (z:Payment) WHERE (z IS NULL OR z.team_id IN $ai_team_ids) RETURN', $out);
        $this->assertEachOptionalScopeInItsOwnClause($out, ['x', 'w', 'y', 'z']);
        $this->assertValid($out);
    }

    /** @test */
    public function optional_followed_by_a_no_where_clause_keeps_its_own_where(): void
    {
        $q = 'MATCH (a:Team) OPTIONAL MATCH (x:Invoice) UNWIND [1, 2] AS k RETURN a, x, k';
        $out = $this->inject($q, $this->direct());

        $this->assertStringContainsString('OPTIONAL MATCH (x:Invoice) WHERE (x IS NULL OR x.team_id IN $ai_team_ids) UNWIND [1, 2] AS k WITH * WHERE a.team_id IN $ai_team_ids RETURN', $out);
        $this->assertEachOptionalScopeInItsOwnClause($out, ['x']);
        $this->assertValid($out);
    }

    /** @test */
    public function single_optional_last_clause_shape_is_unchanged(): void
    {
        // The pre-existing, already-correct placement (optional clause directly
        // before the anchor) keeps its exact shape.
        $out = $this->inject('MATCH (a:Account) OPTIONAL MATCH (i:Invoice) RETURN a, i', $this->direct());

        $this->assertSame(
            'MATCH (a:Account) OPTIONAL MATCH (i:Invoice) WHERE (i IS NULL OR i.team_id IN $ai_team_ids) WITH * WHERE a.team_id IN $ai_team_ids RETURN a, i',
            $out
        );
    }

    /** @test */
    public function mandatory_where_with_top_level_xor_is_parenthesized_before_and_merge(): void
    {
        // `a XOR b AND team` parses as `a XOR (b AND team)` — the scope would
        // only constrain the b side. XOR binds looser than AND, like OR.
        $out = $this->inject('MATCH (i:Invoice) WHERE i.a = 1 XOR i.b = 2 RETURN i', $this->direct());

        $this->assertStringContainsString('WHERE (i.a = 1 XOR i.b = 2) AND i.team_id IN $ai_team_ids', $out);
        $this->assertValid($out);
    }
}
