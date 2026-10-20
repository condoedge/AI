<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Services\Security\CypherCanonicalizer;
use Condoedge\Ai\Services\Security\CypherScopeAnalyzer;
use Condoedge\Ai\Services\Security\ScopeAcceptanceGate;
use Condoedge\Ai\Services\Security\Scope\NodeBinding;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Task 1.26b — three PRE-EXISTING (HEAD) cross-tenant bypasses proven by the
 * reviewer's live-execution oracle (each returned 6006 Invoice rows with
 * `ai_team_ids = []`), plus two more found while fixing them.
 *
 *  1. `\r` desync: Neo4j ends a `//` comment at `\r` OR `\n`; the lexer only at
 *     `\n`, so text after `\r` (a whole UNION branch, an `OR true`) was invisible
 *     to the analyzer yet executed by Neo4j.
 *  2. Whitespace Neo4j accepts but the lexer emitted as T_PUNCT (FF, VT, NBSP,
 *     EM SPACE, IDEOGRAPHIC SPACE, FS, U+2028 …) broke token adjacency:
 *     `MATCH (i<FF>:Invoice)` hid the binding; `OPTIONAL<FF>MATCH` lost its
 *     OPTIONAL-ness. Also: a Unicode identifier `(é:Invoice)` and a doubled-
 *     backtick identifier `` (`x``y`:Invoice) `` hid the binding the same way.
 *  3. ScopeAcceptanceGate accepted `x.team_id IN $ai_team_ids` ANYWHERE in the
 *     scope — under OR / NOT, `IN $ai_team_ids + [5]`, or in an OPTIONAL MATCH's
 *     WHERE — as self-scoped, and the executor ran the query AS-IS. An LLM writing
 *     `WHERE x.team_id IN $ai_team_ids OR x.status = 'open'` leaks by precedence.
 *
 * CLASS-CLOSING DESIGN pinned here: ONE canonicalization step before any analysis
 * rejects non-canonical whitespace/control/format characters outside literals and
 * strips every comment; the executor analyzes, gates, injects, validates and
 * EXECUTES only that canonical text. The gate accepts a direct predicate only as
 * a TOP-LEVEL AND-conjunct of a mandatory MATCH's WHERE.
 */
class CanonicalizationBypassTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =====================================================================
    // Canonicalizer (unit)
    // =====================================================================

    /**
     * @test
     * @dataProvider commentShapes
     */
    public function comments_are_stripped_to_a_single_space(string $raw, string $canonical): void
    {
        $result = (new CypherCanonicalizer())->canonicalize($raw);
        $this->assertNull($result['reason'], "unexpectedly rejected: {$result['reason']}");
        $this->assertSame($canonical, $result['query']);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function commentShapes(): array
    {
        return [
            'block' => ['MATCH (i:Invoice) /* c */ RETURN i', 'MATCH (i:Invoice)   RETURN i'],
            'block glued' => ['OPTIONAL/**/MATCH', 'OPTIONAL MATCH'],
            'line to \n' => ["MATCH (i) // c\nRETURN i", "MATCH (i)  \nRETURN i"],
            'line to \r (Neo4j ends // at CR)' => ["RETURN 1 AS id // \r UNION ALL RETURN 2 AS id", "RETURN 1 AS id  \r UNION ALL RETURN 2 AS id"],
            'line to end' => ['RETURN 1 // tail', 'RETURN 1  '],
            'not nested' => ['RETURN 1 /* /* */ */', 'RETURN 1   */'],
            'markers inside strings kept' => ["WHERE i.n = '/*' OR i.m = \"//\" RETURN '*/'", "WHERE i.n = '/*' OR i.m = \"//\" RETURN '*/'"],
            'escaped quote inside string' => ["WHERE i.n = 'a\\'/*' RETURN '*/'", "WHERE i.n = 'a\\'/*' RETURN '*/'"],
            'markers inside backticks kept' => ['MATCH (`x/*`:Invoice) RETURN `x/*`.id', 'MATCH (`x/*`:Invoice) RETURN `x/*`.id'],
            'doubled backtick kept' => ['MATCH (`x``/*`:Invoice) RETURN 1', 'MATCH (`x``/*`:Invoice) RETURN 1'],
            'unicode inside string kept' => ["WHERE i.n = 'a\u{00A0}b\u{2028}' RETURN i", "WHERE i.n = 'a\u{00A0}b\u{2028}' RETURN i"],
            'tab CR LF allowed' => ["MATCH (i)\t\r\nRETURN i", "MATCH (i)\t\r\nRETURN i"],
        ];
    }

    /**
     * @test
     * @dataProvider nonCanonicalWhitespace
     */
    public function non_canonical_whitespace_outside_literals_is_rejected(string $char): void
    {
        $result = (new CypherCanonicalizer())->canonicalize("MATCH (i:Invoice) OPTIONAL{$char}MATCH (i)-->(b) RETURN i");
        $this->assertNull($result['query']);
        $this->assertSame('non_canonical_whitespace', $result['reason']);
    }

    /** @return array<string, array{0:string}> */
    public static function nonCanonicalWhitespace(): array
    {
        return [
            'FF' => ["\x0c"], 'VT' => ["\x0b"], 'FS 0x1C' => ["\x1c"], 'NUL' => ["\x00"], 'DEL' => ["\x7f"],
            'NBSP' => ["\u{00A0}"], 'EM SPACE' => ["\u{2003}"], 'IDEOGRAPHIC SPACE' => ["\u{3000}"],
            'LINE SEPARATOR' => ["\u{2028}"], 'PARAGRAPH SEPARATOR' => ["\u{2029}"], 'NEL' => ["\u{0085}"],
            'ZWSP' => ["\u{200B}"], 'BOM' => ["\u{FEFF}"],
        ];
    }

    /** @test */
    public function unterminated_block_comment_and_invalid_utf8_are_rejected(): void
    {
        $this->assertNotNull((new CypherCanonicalizer())->canonicalize('MATCH (i) /* x RETURN i')['reason']);
        $this->assertNotNull((new CypherCanonicalizer())->canonicalize("MATCH (i\xC3:Invoice) RETURN i")['reason']);
    }

    // =====================================================================
    // Lexer defense in depth (analyzer never desyncs even without canonicalizer)
    // =====================================================================

    /**
     * @test
     * @dataProvider hiddenBindingShapes
     */
    public function analyzer_sees_every_binding(string $cypher, array $aliases, ?string $failClosedReason = null): void
    {
        $plan = (new CypherScopeAnalyzer())->analyze($cypher);
        if ($failClosedReason !== null) {
            // Non-plain alias: interpolating it into the team filter is unsafe →
            // the analyzer must REJECT (never "scopeable with the binding hidden").
            $this->assertFalse($plan->isScopeable, 'must fail closed: ' . $cypher);
            $this->assertSame($failClosedReason, $plan->reason);
            return;
        }
        $bound = [];
        foreach ($plan->scopes as $scope) {
            foreach ($scope->sensitiveBindings as $b) {
                $bound[] = $b->alias;
            }
        }
        foreach ($aliases as $alias) {
            $this->assertContains($alias, $bound, "binding {$alias} hidden from the analyzer: " . addcslashes($cypher, "\r\n"));
        }
    }

    /** @return array<string, array{0:string,1:list<string>,2?:string}> */
    public static function hiddenBindingShapes(): array
    {
        return [
            '// ended by \r hides UNION branch' => ["MATCH (x:Invoice) RETURN x.id AS id // \r UNION ALL MATCH (i:Invoice) RETURN i.id AS id", ['x', 'i']],
            '// ended by \r hides second pattern' => ["MATCH (x:Invoice) //\r, (i:Invoice)\nRETURN x.id AS id, i.id AS iid", ['x', 'i']],
            'plain backtick alias still scoped' => ['MATCH (`i`:Invoice) RETURN `i`.id AS id', ['i']],
            'unicode identifier' => ['MATCH (é:Invoice) RETURN é.id AS id', [], 'non_identifier_alias'],
            'doubled backtick identifier' => ['MATCH (`x``y`:Invoice) RETURN `x``y`.id AS id', [], 'non_identifier_alias'],
            // Filter-text injection through a backtick alias (4th HEAD bypass):
            // `WHERE true OR x.team_id IN $ai_team_ids` — a tautology.
            'backtick alias carrying Cypher' => ['WITH {team_id: 0} AS x MATCH (`true OR x`:Invoice) RETURN `true OR x`.id AS id', [], 'non_identifier_alias'],
        ];
    }

    // =====================================================================
    // (B) Acceptance gate — only a top-level AND-conjunct of a mandatory WHERE
    // =====================================================================

    /**
     * @test
     * @dataProvider notSelfScoped
     */
    public function gate_rejects_a_predicate_that_does_not_constrain_every_row(string $cypher): void
    {
        $plan = (new CypherScopeAnalyzer())->analyze($cypher);
        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");
        $this->assertFalse(
            (new ScopeAcceptanceGate())->isFullySelfScoped($cypher, $plan, $this->directRule()),
            "gate accepted a non-constraining predicate as self-scoped: {$cypher}"
        );
    }

    /** @return array<string, array{0:string}> */
    public static function notSelfScoped(): array
    {
        return [
            'OR true' => ['MATCH (x:Invoice) WHERE x.team_id IN $ai_team_ids OR true RETURN x.id'],
            'OR status (LLM precedence)' => ["MATCH (x:Invoice) WHERE x.team_id IN \$ai_team_ids OR x.status = 'open' RETURN x.id"],
            'OR before' => ['MATCH (x:Invoice) WHERE true OR x.team_id IN $ai_team_ids RETURN x.id'],
            'XOR' => ['MATCH (x:Invoice) WHERE x.team_id IN $ai_team_ids XOR true RETURN x.id'],
            'NOT' => ['MATCH (x:Invoice) WHERE NOT x.team_id IN $ai_team_ids RETURN x.id'],
            'AND NOT elsewhere' => ['MATCH (x:Invoice) WHERE x.team_id IN $ai_team_ids AND NOT x.a = 1 OR true RETURN x.id'],
            'IN ids + [5]' => ['MATCH (x:Invoice) WHERE x.team_id IN $ai_team_ids + [5] RETURN x.id'],
            '= false' => ['MATCH (x:Invoice) WHERE x.team_id IN $ai_team_ids = false RETURN x.id'],
            'IS NULL' => ['MATCH (x:Invoice) WHERE x.team_id IN $ai_team_ids IS NULL RETURN x.id'],
            'parenthesized with OR' => ['MATCH (x:Invoice) WHERE (x.team_id IN $ai_team_ids OR true) RETURN x.id'],
            'in a comprehension' => ['MATCH (x:Invoice) WITH x, [y IN [1] WHERE x.team_id IN $ai_team_ids] AS l RETURN x.id'],
            'in the OPTIONAL MATCH WHERE' => ['MATCH (x:Invoice) OPTIONAL MATCH (x)-->(b:Invoice) WHERE x.team_id IN $ai_team_ids AND b.team_id IN $ai_team_ids RETURN x.id'],
            'in a CASE' => ['MATCH (x:Invoice) WHERE CASE WHEN x.team_id IN $ai_team_ids THEN true ELSE true END RETURN x.id'],
        ];
    }

    /**
     * @test
     * @dataProvider selfScoped
     */
    public function gate_still_accepts_top_level_conjuncts(string $cypher): void
    {
        $plan = (new CypherScopeAnalyzer())->analyze($cypher);
        $this->assertTrue($plan->isScopeable, "rejected: {$plan->reason}");
        $this->assertTrue((new ScopeAcceptanceGate())->isFullySelfScoped($cypher, $plan, $this->directRule()), $cypher);
    }

    /** @return array<string, array{0:string}> */
    public static function selfScoped(): array
    {
        return [
            'sole predicate' => ['MATCH (x:Invoice) WHERE x.team_id IN $ai_team_ids RETURN x.id'],
            'first conjunct' => ['MATCH (x:Invoice) WHERE x.team_id IN $ai_team_ids AND x.a = 1 RETURN x.id'],
            'last conjunct' => ["MATCH (x:Invoice) WHERE x.a = 1 AND (x.b = 2 OR x.c = 3) AND x.team_id IN \$ai_team_ids RETURN x.id"],
            'later mandatory MATCH WHERE' => ['MATCH (x:Invoice) MATCH (y:Invoice) WHERE y.team_id IN $ai_team_ids AND x.team_id IN $ai_team_ids RETURN x.id, y.id'],
        ];
    }

    private function directRule(): callable
    {
        return static fn (NodeBinding $b): array => [
            'type' => 'direct',
            'kind' => 'where',
            'alias' => $b->alias,
            'column' => 'team_id',
            'filter' => "WHERE {$b->alias}.team_id IN \$ai_team_ids",
        ];
    }

    // =====================================================================
    // End to end through QueryExecutor (the oracle's shapes)
    // =====================================================================

    /** @return array{0: \Condoedge\Ai\DTOs\QueryExecutionResult, 1: list<string>} */
    private function runExecutor(string $cypher): array
    {
        config()->set('ai.security.enabled', true);
        config()->set('ai.security.on_permission_unexistent', 'deny');
        config()->set('entities.Invoice.security.team_path', 'team_id');

        $security = Mockery::mock(AiSecurityService::class);
        $security->shouldReceive('getTeamFilterWithParameters')
            ->andReturnUsing(fn ($user, string $entity, string $alias) => [
                'filter' => "WHERE {$alias}.team_id IN \$ai_team_ids",
                'parameters' => ['ai_team_ids' => [3]],
            ]);
        $security->shouldReceive('shouldSkipTeamFilterForCount')->andReturn(false);
        $this->app->instance(AiSecurityService::class, $security);

        $executed = [];
        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('query')->andReturnUsing(function (string $q) use (&$executed) {
            $executed[] = $q;
            return [];
        });

        $result = (new QueryExecutor($graph, ['log_slow_queries' => false]))
            ->execute($cypher, [], ['user' => (object) ['id' => 13]]);

        return [$result, $executed];
    }

    /**
     * Every Invoice alias must be scoped in the EXECUTED text (or nothing runs),
     * and the executed text never contains a comment or the raw query.
     *
     * @test
     * @dataProvider oracleLeakShapes
     */
    public function executor_never_runs_an_oracle_leak_shape_unscoped(string $cypher, array $aliases): void
    {
        [$result, $executed] = $this->runExecutor($cypher);

        if (($result->metadata['safe_stop'] ?? false) === true) {
            $this->assertSame([], $executed, 'safe-stopped but still executed');
            return;
        }

        $this->assertCount(1, $executed);
        $q = $executed[0];
        $this->assertStringNotContainsString('//', $q, 'executed text still carries a comment');
        $this->assertStringNotContainsString('/*', $q, 'executed text still carries a comment');
        foreach ($aliases as $alias) {
            $this->assertMatchesRegularExpression(
                '/(?<![\w.`])' . preg_quote($alias, '/') . '\.team_id IN \$ai_team_ids(?!\s*(?:OR\b|XOR\b|\+|=|IS\b))/',
                $q,
                "alias {$alias} not constrained in the EXECUTED text: " . addcslashes($q, "\r\n")
            );
        }
        // OR-precedence: the generator's own predicate must never be the only one.
        if (preg_match('/\bOR\b/i', $cypher)) {
            $this->assertNotSame($cypher, $q, 'OR-precedence shape executed as-is');
        }
    }

    /** @return array<string, array{0:string,1:list<string>}> */
    public static function oracleLeakShapes(): array
    {
        return [
            '\r UNION branch' => ["MATCH (x:Invoice) RETURN x.id AS id // \r UNION ALL MATCH (i:Invoice) RETURN i.id AS id", ['x', 'i']],
            '\r OR true' => ["MATCH (x:Invoice) WHERE x.id > 0 //\r OR true\nRETURN x.id AS id", ['x']],
            '\r OR true after self-scope (gate)' => ["MATCH (x:Invoice) WHERE x.team_id IN \$ai_team_ids //\r OR true\nRETURN x.id AS id", ['x']],
            '\r second pattern' => ["MATCH (x:Invoice) //\r, (i:Invoice)\nRETURN x.id AS id, i.id AS iid", ['x', 'i']],
            '\r rebinding' => ["WITH 1 AS z //\r MATCH (i:Invoice) WITH i.id AS z\nRETURN z", ['i']],
            'OPTIONAL<FF>MATCH' => ["MATCH (i:Invoice) OPTIONAL\x0cMATCH (i)-[:R]->(b:Invoice) WHERE b.x = 1 RETURN i.id, b.id", ['i', 'b']],
            'OPTIONAL<NBSP>MATCH' => ["MATCH (i:Invoice) OPTIONAL\u{00A0}MATCH (i)-[:R]->(b:Invoice) WHERE b.x = 1 RETURN i.id, b.id", ['i', 'b']],
            'OPTIONAL<LS>MATCH' => ["MATCH (i:Invoice) OPTIONAL\u{2028}MATCH (i)-[:R]->(b:Invoice) WHERE b.x = 1 RETURN i.id, b.id", ['i', 'b']],
            'MATCH<FF>(' => ["MATCH\x0c(i:Invoice) RETURN i.id", ['i']],
            '(i<FF>:Invoice)' => ["MATCH (i\x0c:Invoice) RETURN i.id", ['i']],
            '<VT>WHERE' => ["MATCH (i:Invoice)\x0bWHERE true OR i.x = 1 RETURN i.id", ['i']],
            'WITH u<FF>MATCH' => ["MATCH (u:Invoice) WITH u\x0cMATCH (i:Invoice) RETURN i.id", ['u', 'i']],
            'gate: OR true' => ['MATCH (x:Invoice) WHERE x.team_id IN $ai_team_ids OR true RETURN x.id', ['x']],
            'gate: OR status' => ["MATCH (x:Invoice) WHERE x.team_id IN \$ai_team_ids OR x.status = 'open' RETURN x.id", ['x']],
            'gate: NOT' => ['MATCH (x:Invoice) WHERE NOT x.team_id IN $ai_team_ids RETURN x.id', ['x']],
            'gate: + [5]' => ['MATCH (x:Invoice) WHERE x.team_id IN $ai_team_ids + [5] RETURN x.id', ['x']],
            'unicode identifier' => ['MATCH (é:Invoice) RETURN é.id AS id', ['é']],
            'doubled backtick' => ['MATCH (`x``y`:Invoice) RETURN `x``y`.id AS id', ['`x``y`']],
            'block comment in pattern' => ['MATCH (a)-[r/*x*/:R]->(i/*y*/:/*z*/Invoice/*w*/) RETURN i.id', ['i']],
            'backtick alias filter injection' => ['WITH {team_id: 0} AS x MATCH (`true OR x`:Invoice) RETURN `true OR x`.id AS id', ['`true OR x`']],
        ];
    }

    /** @test */
    public function executor_executes_only_the_canonical_text(): void
    {
        [, $executed] = $this->runExecutor("MATCH (i:Invoice) /* note */ WHERE i.team_id IN \$ai_team_ids // tail\nRETURN i.id AS id");

        $this->assertCount(1, $executed);
        $this->assertStringNotContainsString('note', $executed[0]);
        $this->assertStringNotContainsString('tail', $executed[0]);
        $this->assertStringContainsString('i.team_id IN $ai_team_ids', $executed[0]);
    }

    /** @test */
    public function executor_safe_stops_non_canonical_whitespace(): void
    {
        [$result, $executed] = $this->runExecutor("MATCH (i:Invoice) OPTIONAL\x0cMATCH (i)-->(b:Invoice) RETURN i.id");

        $this->assertTrue($result->metadata['safe_stop'] ?? false);
        $this->assertSame([], $executed);
    }
}
