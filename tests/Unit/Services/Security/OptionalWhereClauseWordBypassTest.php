<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Services\Security\Scope\FilterClause;
use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Services\Security\SecurityFilterInjector;
use Condoedge\Ai\Tests\TestCase;

/**
 * Task 1.26 follow-up — CROSS-TENANT BYPASS regression (security review).
 *
 * `anchorClauseKind()` reset the anchor-clause kind from OPTIONAL MATCH to
 * "WHERE is fine" on any base-depth `yield` word — including a VARIABLE named
 * `yield` or a LABEL `Yield` (reached via `!`, `&`, `|` or a comment) INSIDE the
 * OPTIONAL MATCH's still-open WHERE. The injector then AND-merged the MANDATORY
 * binding's direct predicate (`i.team_id IN $ai_team_ids`) into that OPTIONAL
 * WHERE, where it only filters the optional match: cross-tenant Invoice rows
 * survived with b = null.
 *
 * INVARIANT pinned: when the last pattern clause before the anchor is an
 * OPTIONAL MATCH, a mandatory binding's direct predicate is enforced at ROW
 * level (`WITH * WHERE …`) — never inside the OPTIONAL MATCH's WHERE — no
 * matter which clause-keyword-looking words appear inside that predicate.
 * (A safe-stop is also acceptable for the class-coverage shapes; running the
 * query with the predicate in the optional WHERE never is.)
 */
class OptionalWhereClauseWordBypassTest extends TestCase
{
    private CypherScopeAnalyzer $analyzer;
    private SecurityFilterInjector $injector;

    public function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new CypherScopeAnalyzer();
        $this->injector = new SecurityFilterInjector();
    }

    /** WHERE-kind (direct team_id) filter — the only filter kind that could leak. */
    private function directFilter(): callable
    {
        return static fn (NodeBinding $b): FilterClause => new FilterClause(
            'where',
            "WHERE {$b->alias}.team_id IN \$ai_team_ids",
            ['ai_team_ids' => [14]]
        );
    }

    private function inject(string $cypher): ?string
    {
        $plan = $this->analyzer->analyze($cypher);
        return $plan->isScopeable ? $this->injector->inject($cypher, $plan, $this->directFilter()) : null;
    }

    /**
     * The mandatory i-predicate must sit in a row-level `WITH * WHERE` AFTER the
     * OPTIONAL MATCH, not in the optional WHERE.
     */
    private function assertRowLevel(string $cypher, ?string $out): void
    {
        $this->assertNotNull($out, "expected a scoped rewrite: {$cypher}");

        $optional = strpos($out, 'OPTIONAL MATCH');
        $rowWhere = strpos($out, 'WITH * WHERE', (int) $optional);
        $pred = strpos($out, 'i.team_id IN $ai_team_ids', (int) $optional);

        $this->assertNotFalse($pred, "mandatory Invoice binding i left unscoped: {$out}");
        $this->assertNotFalse($rowWhere, "no row-level WITH * WHERE after the OPTIONAL MATCH (bypass): {$out}");
        $this->assertGreaterThan(
            $rowWhere,
            $pred,
            "mandatory predicate merged into the OPTIONAL MATCH's WHERE (cross-tenant bypass): {$out}"
        );
        // The optional binding is still scoped, null-tolerantly.
        $this->assertStringContainsString('(b IS NULL OR b.team_id IN $ai_team_ids)', $out);
        $this->assertTrue(
            CypherStructuralValidator::isValid($out),
            'rewrite is not structurally valid: ' . $out . ' | ' . (CypherStructuralValidator::diagnose($out) ?? '')
        );
    }

    /**
     * The four exact leaking queries from the review.
     *
     * @test
     * @dataProvider reviewedLeakingQueries
     */
    public function reviewed_leaking_query_is_scoped_at_row_level(string $cypher): void
    {
        $this->assertRowLevel($cypher, $this->inject($cypher));
    }

    /** @return array<string, array{0:string}> */
    public static function reviewedLeakingQueries(): array
    {
        return [
            '1 variable yield' => ['WITH 1 AS yield MATCH (i:Invoice) OPTIONAL MATCH (i)-->(b:Account) WHERE b.x = yield RETURN i, b'],
            '2 label !Yield' => ['MATCH (i:Invoice) OPTIONAL MATCH (i)-->(b:Account) WHERE b:!Yield RETURN i, b'],
            '3 label &Yield' => ['MATCH (i:Invoice) OPTIONAL MATCH (i)-->(b:Account) WHERE b:Account&Yield RETURN i, b'],
            '4 label via comment' => ['MATCH (i:Invoice) OPTIONAL MATCH (i)-->(b:Account) WHERE b:/*c*/Yield RETURN i, b'],
        ];
    }

    /**
     * Class coverage: any clause-keyword-looking word (variable / label) inside
     * the open OPTIONAL WHERE. Either scoped at row level, or safe-stopped —
     * NEVER merged into the optional WHERE.
     *
     * @test
     * @dataProvider clauseWordShapes
     */
    public function clause_word_inside_optional_where_never_bypasses(string $cypher): void
    {
        $out = $this->inject($cypher);
        if ($out === null) {
            $this->addToAssertionCount(1); // fail-closed is acceptable
            return;
        }
        $this->assertRowLevel($cypher, $out);
    }

    /** @return array<string, array{0:string}> */
    public static function clauseWordShapes(): array
    {
        $shapes = [];
        foreach (['yield', 'unwind', 'merge', 'create', 'with', 'match', 'call', 'set'] as $w) {
            $shapes["variable {$w}"] = ["WITH 1 AS {$w} MATCH (i:Invoice) OPTIONAL MATCH (i)-->(b:Account) WHERE b.x = {$w} RETURN i, b"];
            $shapes["variable {$w} under OR"] = ["WITH 1 AS {$w} MATCH (i:Invoice) OPTIONAL MATCH (i)-->(b:Account) WHERE b.x = 1 OR b.y = {$w} RETURN i, b"];
        }
        foreach (['Yield', 'Unwind', 'Merge', 'Create', 'With', 'Match', 'Call', 'Set'] as $l) {
            $shapes["label !{$l}"] = ["MATCH (i:Invoice) OPTIONAL MATCH (i)-->(b:Account) WHERE b:!{$l} RETURN i, b"];
            $shapes["label &{$l}"] = ["MATCH (i:Invoice) OPTIONAL MATCH (i)-->(b:Account) WHERE b:Account&{$l} RETURN i, b"];
            $shapes["label |{$l}"] = ["MATCH (i:Invoice) OPTIONAL MATCH (i)-->(b:Account) WHERE b:Account|{$l} RETURN i, b"];
            $shapes["label /*c*/{$l}"] = ["MATCH (i:Invoice) OPTIONAL MATCH (i)-->(b:Account) WHERE b:/*c*/{$l} RETURN i, b"];
            $shapes["label :{$l} under OR"] = ["MATCH (i:Invoice) OPTIONAL MATCH (i)-->(b:Account) WHERE b.x = 1 OR b:{$l} RETURN i, b"];
        }
        $shapes['wildcard label %'] = ['MATCH (i:Invoice) OPTIONAL MATCH (i)-->(b:Account) WHERE b:% RETURN i, b'];

        return $shapes;
    }

    /**
     * Comments are whitespace in Cypher (Neo4j accepts `MATCH (/*c*\/i:Invoice)`),
     * but a comment token between `(` and the alias hid the labelled binding from
     * BOTH the analyzer and the coverage gate → the query ran UNSCOPED (found
     * while fixing the bypass above). A comment between OPTIONAL and MATCH hid
     * the OPTIONAL-ness → mandatory predicate merged into the optional WHERE.
     *
     * @test
     * @dataProvider commentPlacements
     */
    public function comments_never_hide_a_binding_or_an_optional_match(string $cypher, string $alias, bool $optionalAnchor): void
    {
        $plan = $this->analyzer->analyze($cypher);
        $this->assertTrue($plan->isScopeable, "rejected ({$plan->reason}): {$cypher}");

        $aliases = [];
        foreach ($plan->scopes as $scope) {
            foreach ($scope->sensitiveBindings as $b) {
                $aliases[] = $b->alias;
            }
        }
        $this->assertContains($alias, $aliases, "labelled binding hidden by a comment: {$cypher}");
        $this->assertSame($optionalAnchor, $plan->scopes[0]->anchorClauseIsOptionalMatch, $cypher);

        $out = $this->injector->inject($cypher, $plan, $this->directFilter());
        $this->assertNotNull($out);
        $this->assertStringContainsString("{$alias}.team_id IN \$ai_team_ids", $out);
        if ($optionalAnchor) {
            $this->assertRowLevel($cypher, $out);
        }
    }

    /** @return array<string, array{0:string,1:string,2:bool}> */
    public static function commentPlacements(): array
    {
        return [
            'comment after (' => ['MATCH (/*c*/i:Invoice) RETURN i', 'i', false],
            'comment before :' => ['MATCH (i /*c*/ :Invoice) RETURN i', 'i', false],
            'comment after :' => ['MATCH (i:/*c*/Invoice) RETURN i', 'i', false],
            'line comment inside pattern' => ["MATCH ( // c\n i:Invoice) RETURN i", 'i', false],
            'comment between OPTIONAL and MATCH' => [
                'MATCH (i:Invoice) OPTIONAL /*c*/ MATCH (i)-->(b:Account) WHERE b.x = 1 RETURN i, b',
                'i',
                true,
            ],
        ];
    }

    /** @test */
    public function executor_never_runs_a_comment_hidden_binding_unscoped(): void
    {
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');
        config()->set('entities.Invoice.security.team_path', 'team_id');

        $security = \Mockery::mock(\Condoedge\Ai\Services\Security\AiSecurityService::class);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->andReturnUsing(fn ($user, string $entity, string $alias) => [
                'filter' => "WHERE {$alias}.team_id IN \$ai_team_ids",
                'parameters' => ['ai_team_ids' => [3]],
            ]);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $this->app->instance(\Condoedge\Ai\Services\Security\AiSecurityService::class, $security);

        $executed = [];
        $graph = \Mockery::mock(\Condoedge\Ai\Contracts\GraphStoreInterface::class);
        $graph->shouldReceive('query')->andReturnUsing(function (string $cypher) use (&$executed) {
            $executed[] = $cypher;
            return [];
        });

        $executor = new \Condoedge\Ai\Services\QueryExecutor($graph, ['log_slow_queries' => false]);
        $executor->execute('MATCH (/*c*/i:Invoice) RETURN i.total AS total', [], ['user' => (object) ['id' => 13]]);

        foreach ($executed as $cypher) {
            $this->assertStringContainsString('$ai_team_ids', $cypher, "ran UNSCOPED: {$cypher}");
        }
        \Mockery::close();
    }

    /** @test */
    public function control_plain_optional_where_is_row_level(): void
    {
        $cypher = 'MATCH (i:Invoice) OPTIONAL MATCH (i)-->(b:Account) WHERE b.x = 1 RETURN i, b';
        $this->assertRowLevel($cypher, $this->inject($cypher));
    }

    /** @test */
    public function a_real_match_after_the_optional_match_still_takes_a_plain_where(): void
    {
        // Control for (b): the kind still resets on a REAL later MATCH clause.
        $plan = $this->analyzer->analyze(
            'MATCH (i:Invoice) OPTIONAL MATCH (i)-->(b:Account) WHERE b.x = 1 MATCH (i)-->(c:Account) RETURN i, b, c'
        );
        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");
        $this->assertFalse($plan->scopes[0]->anchorClauseIsOptionalMatch);
    }
}
