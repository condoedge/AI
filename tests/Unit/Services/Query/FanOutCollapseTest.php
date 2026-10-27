<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Query;

use Condoedge\Ai\Services\Query\FanOutCollapse;
use PHPUnit\Framework\TestCase;

/**
 * FAN-OUT COLLAPSE (eval p11 D1/D2): structural, vocabulary-free helpers that turn
 * a one-row-per-joined-record result back into one row per anchor record.
 */
class FanOutCollapseTest extends TestCase
{
    private const ROSTER_CONTACTS = "MATCH (t:Team) WHERE t.id IN \$ai_team_ids AND t.id = 3\n"
        . "OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t)\n"
        . "OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id\n"
        . "OPTIONAL MATCH (c:Contact)-[:USER]->(u)\n"
        . "OPTIONAL MATCH (cd:ContactDirectory)-[:CONTACT]->(c)\n"
        . "RETURN u.name AS member_name, tr.role AS role, cd.value AS contact_info LIMIT 100";

    /** @test */
    public function the_live_roster_contacts_query_is_rewritten_to_one_row_per_anchor(): void
    {
        $rewritten = FanOutCollapse::perAnchorQuery(self::ROSTER_CONTACTS, 'u');

        $this->assertNotNull($rewritten);
        // MATCH / WHERE (tenant scoping) are kept verbatim.
        $this->assertStringStartsWith("MATCH (t:Team) WHERE t.id IN \$ai_team_ids AND t.id = 3\n", $rewritten);
        $this->assertStringContainsString('OPTIONAL MATCH (cd:ContactDirectory)-[:CONTACT]->(c)', $rewritten);
        $this->assertMatchesRegularExpression(
            '/WITH u, collect\(DISTINCT tr\.role\) AS role, collect\(DISTINCT cd\.value\) AS contact_info WHERE u IS NOT NULL'
            . ' RETURN u\.name AS member_name, role, contact_info LIMIT 100$/',
            $rewritten
        );
    }

    /**
     * @test Eval p13-B french t0: the generator aliased the role column `rôle` (a valid unquoted
     * Cypher identifier); the ASCII-only alias parse refused the rewrite, so the 2 TeamRoles
     * without a user stayed in the result ("525 membres" instead of 523).
     */
    public function a_unicode_alias_is_a_plain_identifier(): void
    {
        $french = 'MATCH (t:Team) WHERE t.id IN $ai_team_ids '
            . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) '
            . 'OPTIONAL MATCH (tr)-[:BELONGS_TO_USER]->(u:User) '
            . 'RETURN u.name AS membre, tr.role AS rôle LIMIT 100';

        $rewritten = FanOutCollapse::perAnchorQuery($french, 'u');

        $this->assertNotNull($rewritten);
        $this->assertStringContainsString('collect(DISTINCT tr.role)', $rewritten);
        $this->assertStringContainsString(' AS rôle', $rewritten);
        $this->assertStringContainsString('WHERE u IS NOT NULL', $rewritten);
        $this->assertMatchesRegularExpression('/RETURN u\.name AS membre, rôle LIMIT 100$/u', $rewritten);
    }

    /** @test */
    public function a_return_of_anchor_columns_only_dedupes_the_anchor(): void
    {
        $rewritten = FanOutCollapse::perAnchorQuery(
            'MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS name, u.email AS email ORDER BY name LIMIT 50',
            'u'
        );

        $this->assertSame(
            'MATCH (u:User)-[:HAS]->(c:Contact) WITH DISTINCT u WHERE u IS NOT NULL RETURN u.name AS name, u.email AS email ORDER BY name LIMIT 50',
            $rewritten
        );
    }

    /** @test */
    public function a_bare_joined_column_keeps_its_original_column_name(): void
    {
        $rewritten = FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name, c.email', 'u');

        $this->assertStringEndsWith('WITH u, collect(DISTINCT c.email) AS __fo1 WHERE u IS NOT NULL RETURN u.name, __fo1 AS `c.email`', (string) $rewritten);
    }

    /** @test */
    public function an_alias_that_names_a_pattern_variable_gets_an_internal_grouping_alias(): void
    {
        $rewritten = FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS name, c.email AS c', 'u');

        $this->assertStringEndsWith('WITH u, collect(DISTINCT c.email) AS __fo1 WHERE u IS NOT NULL RETURN u.name AS name, __fo1 AS c', (string) $rewritten);
    }

    /** @test */
    public function a_joined_record_id_column_is_dropped_but_the_anchor_id_is_kept(): void
    {
        $rewritten = FanOutCollapse::perAnchorQuery(
            'MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS name, c.email AS email, u.id AS user_id, c.id AS contact_id',
            'u'
        );

        $this->assertStringEndsWith(
            'WITH u, collect(DISTINCT c.email) AS email WHERE u IS NOT NULL RETURN u.name AS name, email, u.id AS user_id',
            (string) $rewritten
        );
    }

    /** @test */
    public function commas_inside_function_calls_and_strings_do_not_split_items(): void
    {
        $rewritten = FanOutCollapse::perAnchorQuery(
            "MATCH (u:User)-[:HAS]->(c:Contact) RETURN coalesce(u.name, 'a, b') AS name, coalesce(c.email, c.phone) AS reach",
            'u'
        );

        $this->assertStringEndsWith(
            "WITH u, collect(DISTINCT coalesce(c.email, c.phone)) AS reach WHERE u IS NOT NULL RETURN coalesce(u.name, 'a, b') AS name, reach",
            (string) $rewritten
        );
    }

    /** @test */
    public function shapes_that_cannot_be_rewritten_safely_return_null(): void
    {
        // ORDER BY on a joined variable (out of scope after the grouping WITH).
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS n, c.email ORDER BY c.email', 'u'));
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS n, c.email AS e ORDER BY c.email', 'u'));
        // SKIP pagination.
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS n, c.email AS e SKIP 10 LIMIT 10', 'u'));
        // DISTINCT / aggregating projection.
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN DISTINCT u.name AS n, c.email AS e', 'u'));
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS n, count(c) AS e', 'u'));
        // UNION.
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User) RETURN u.name AS n UNION MATCH (u:User) RETURN u.name AS n', 'u'));
        // A projected value that is not a pattern variable (a WITH-computed alias).
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) WITH u, c, size(c.tags) AS n RETURN u.name AS name, n AS tags', 'u'));
        // The anchor is not a pattern variable.
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS n, c.email AS e', 'x'));
        // Final RETURN inside a subquery.
        $this->assertNull(FanOutCollapse::perAnchorQuery('CALL { MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS n, c.email AS e }', 'u'));
    }

    /** @test */
    public function a_union_label_or_alias_is_not_a_union_clause(): void
    {
        // CondoEdge's `:Union` label (a condo union) made every union-scoped list
        // look like a UNION query, so its anchor count was never computed.
        $this->assertFalse(FanOutCollapse::hasUnionClause('MATCH (u:Unit)-[:UNION]->(un:Union) RETURN un.name AS union_name, u.name AS unit'));
        $this->assertTrue(FanOutCollapse::hasUnionClause('MATCH (u:User) RETURN u.name AS n UNION ALL MATCH (c:Contact) RETURN c.name AS n'));
        $this->assertTrue(FanOutCollapse::hasUnionClause("MATCH (u:User) RETURN u.name AS n\nUNION\nMATCH (c:Contact) RETURN c.name AS n"));
        $this->assertFalse(FanOutCollapse::hasUnionClause("MATCH (u:User) WHERE u.note = ' UNION MATCH ' RETURN u.name"));

        $this->assertNotNull(FanOutCollapse::perAnchorQuery('MATCH (un:Union)<-[:UNION]-(u:Unit) RETURN un.name AS union_name, u.name AS unit', 'un'));
    }

    /** @test */
    public function a_relationship_variable_counts_as_a_joined_variable(): void
    {
        $rewritten = FanOutCollapse::perAnchorQuery('MATCH (u:User)-[r:HAS]->(c:Contact) RETURN u.name AS n, r.since AS since', 'u');

        $this->assertStringEndsWith('WITH u, collect(DISTINCT r.since) AS since WHERE u IS NOT NULL RETURN u.name AS n, since', (string) $rewritten);
    }

    /** @test */
    public function an_anchor_pinned_to_a_literal_or_parameter_is_detected(): void
    {
        $this->assertTrue(FanOutCollapse::isPinned("MATCH (u:Unit)-[:UNION]->(un:Union) WHERE u.name = '310' RETURN u.name, un.name", 'u'));
        $this->assertTrue(FanOutCollapse::isPinned('MATCH (u:User) WHERE $me = u.id RETURN u.name', 'u'));
        $this->assertTrue(FanOutCollapse::isPinned("MATCH (u:User {email: 'a@x.test'})-[:HAS]->(c:Contact) RETURN u.name", 'u'));
        $this->assertTrue(FanOutCollapse::isPinned('MATCH (u:User) WHERE id(u) = 4 RETURN u.name', 'u'));
        // A join condition against another variable, or a set membership, is not a pin.
        $this->assertFalse(FanOutCollapse::isPinned(self::ROSTER_CONTACTS, 'u'));
        $this->assertFalse(FanOutCollapse::isPinned('MATCH (u:User) WHERE u.team_id IN $ai_team_ids RETURN u.name', 'u'));
        $this->assertFalse(FanOutCollapse::isPinned('MATCH (u:Unit) WHERE u.union_id IN $ai_row_scope_ids RETURN u.name', 'u'));
        // Another variable pinned is not the anchor pinned.
        $this->assertFalse(FanOutCollapse::isPinned(self::ROSTER_CONTACTS, 'tr'));
        $this->assertTrue(FanOutCollapse::isPinned(self::ROSTER_CONTACTS, 't'));
    }

    /** @test */
    public function list_values_are_joined_with_distinct_non_empty_members(): void
    {
        $rows = FanOutCollapse::joinLists([
            ['member_name' => 'Nada', 'role' => ['owner'], 'contact_info' => ['n@x.test', '+1 514', 'n@x.test', null, '']],
            ['member_name' => 'Filip', 'role' => [], 'contact_info' => [7, 'f@x.test']],
            ['member_name' => 'Map', 'node' => ['id' => 1, 'name' => 'kept']],
        ]);

        $this->assertSame('owner', $rows[0]['role']);
        $this->assertSame('n@x.test; +1 514', $rows[0]['contact_info']);
        $this->assertNull($rows[1]['role']);
        $this->assertSame('7; f@x.test', $rows[1]['contact_info']);
        $this->assertSame(['id' => 1, 'name' => 'kept'], $rows[2]['node'], 'a map value is not a list');
    }

    /** @test */
    public function rows_are_collapsed_by_the_key_columns_joining_the_other_columns_values(): void
    {
        $rows = FanOutCollapse::collapseByColumns([
            ['member_name' => 'Antoine', 'role' => 'owner', 'contact_info' => 'a@x.test'],
            ['member_name' => 'Antoine', 'role' => 'owner', 'contact_info' => '+1 514'],
            ['member_name' => 'Nada', 'role' => 'admin', 'contact_info' => 'n@x.test'],
            ['Member_Name' => ' antoine', 'role' => 'OWNER', 'contact_info' => 'a@x.test'],
            ['member_name' => 'Nada', 'role' => 'admin', 'contact_info' => null],
            ['member_name' => 'Solo', 'role' => 'board', 'contact_info' => null],
        ], ['member_name', 'role']);

        $this->assertSame([
            ['member_name' => 'Antoine', 'role' => 'owner', 'contact_info' => 'a@x.test; +1 514'],
            ['member_name' => 'Nada', 'role' => 'admin', 'contact_info' => 'n@x.test'],
            ['member_name' => 'Solo', 'role' => 'board', 'contact_info' => null],
        ], $rows);
    }

    // ---------------------------------------------------------------------
    // Review findings (2026-10-01)
    // ---------------------------------------------------------------------

    /** @test */
    public function s1_a_comment_never_hides_the_final_return_from_the_rewrite(): void
    {
        // The tail used to land inside the comment: the run "succeeded" on the
        // still-fanned rows. The rewrite works on the comment-stripped text.
        $rewritten = FanOutCollapse::perAnchorQuery(
            'MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name, c.value // RETURN u.name LIMIT 100',
            'u'
        );
        $this->assertNotNull($rewritten);
        $this->assertStringNotContainsString('//', $rewritten);
        $this->assertStringEndsWith('WITH u, collect(DISTINCT c.value) AS __fo1 WHERE u IS NOT NULL RETURN u.name, __fo1 AS `c.value`', $rewritten);

        $block = FanOutCollapse::perAnchorQuery(
            "MATCH (u:User)-[:HAS]->(c:Contact) /* RETURN u.name LIMIT 5 */ RETURN u.name AS n, c.value AS v LIMIT 100",
            'u'
        );
        $this->assertStringEndsWith('WITH u, collect(DISTINCT c.value) AS v WHERE u IS NOT NULL RETURN u.name AS n, v LIMIT 100', (string) $block);
        $this->assertStringNotContainsString('/*', (string) $block);

        $this->assertFalse(FanOutCollapse::hasUnionClause("MATCH (u:User) RETURN u.name // UNION MATCH (c:Contact) RETURN c.name"));
    }

    /** @test */
    public function s1_a_backticked_joined_variable_is_not_mistaken_for_an_anchor_item(): void
    {
        // `c` IS the joined variable c: collected, never kept verbatim as an
        // anchor item (out of scope after the grouping WITH).
        $this->assertStringEndsWith(
            'WITH u, collect(DISTINCT `c`.email) AS e WHERE u IS NOT NULL RETURN u.name AS n, e',
            (string) FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS n, `c`.email AS e', 'u')
        );
        // A backticked name bound nowhere in a pattern is an unknown reference.
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS n, `x`.email AS e', 'u'));
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN *', 'u'));
    }

    /** @test */
    public function i2_several_columns_of_one_joined_record_are_never_collected_apart(): void
    {
        // unit + p.amount + p.date: per-column DISTINCT lists would de-pair the
        // amounts from their dates (and drop equal amounts).
        $this->assertNull(FanOutCollapse::perAnchorQuery(
            'MATCH (u:Unit)<-[:FOR]-(p:Payment) RETURN u.number AS unit, p.amount AS amount, p.date AS date LIMIT 100',
            'u'
        ));
        // One column per joined variable stays collapsible (the roster).
        $this->assertNotNull(FanOutCollapse::perAnchorQuery(self::ROSTER_CONTACTS, 'u'));
        // A joined record's id projection is dropped, so it does not count.
        $this->assertNotNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS n, c.email AS e, c.id AS contact_id', 'u'));
    }

    /** @test */
    public function i2_each_collected_list_is_capped_one_past_the_cell_limit(): void
    {
        $plan = FanOutCollapse::plan(self::ROSTER_CONTACTS, 'u', 10);

        $this->assertNotNull($plan);
        $this->assertStringContainsString(
            'WITH u, collect(DISTINCT tr.role)[0..11] AS role, collect(DISTINCT cd.value)[0..11] AS contact_info WHERE u IS NOT NULL RETURN u.name AS member_name, role, contact_info LIMIT 100',
            $plan['query']
        );
        $this->assertSame(['member_name'], $plan['anchor_columns']);
        $this->assertSame(['role', 'contact_info'], $plan['collected_columns']);
        $this->assertSame(10, FanOutCollapse::cellLimitOf($plan['query']));
        $this->assertNull(FanOutCollapse::cellLimitOf(self::ROSTER_CONTACTS));
    }

    /** @test */
    public function i2_a_cell_over_the_limit_is_truncated_and_marked(): void
    {
        $truncated = 0;
        $rows = FanOutCollapse::joinLists([
            ['n' => 'A', 'v' => array_map(static fn (int $i): string => "v{$i}", range(1, 11))],
            ['n' => 'B', 'v' => ['x', 'y']],
        ], 10, $truncated);

        $this->assertSame(implode('; ', array_map(static fn (int $i): string => "v{$i}", range(1, 10))) . '; …', $rows[0]['v']);
        $this->assertSame('x; y', $rows[1]['v']);
        $this->assertSame(1, $truncated);
    }

    /** @test */
    public function i3_a_joined_node_or_map_projection_is_not_collapsible(): void
    {
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS n, c', 'u'));
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS n, c {.value} AS contact', 'u'));
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS n, {v: c.value} AS contact', 'u'));
    }

    /** @test */
    public function s6_order_by_an_unaliased_joined_column_is_refused(): void
    {
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name, c.email ORDER BY c.email', 'u'));
        // An alias that shadows a pattern variable is no handle on the variable.
        $this->assertNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name AS n, c.email AS c ORDER BY c.email', 'u'));
        // Ordering by the anchor or by a plain alias stays in scope.
        $this->assertNotNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name, c.email AS email ORDER BY email', 'u'));
        $this->assertNotNull(FanOutCollapse::perAnchorQuery('MATCH (u:User)-[:HAS]->(c:Contact) RETURN u.name, c.email AS email ORDER BY u.name DESC', 'u'));
    }

    /** @test */
    public function s2_any_anchor_predicate_against_a_value_counts_as_pinned(): void
    {
        $pinned = [
            "MATCH (u:Unit)-[:R]->(p:Payment) WHERE toLower(u.number) = '310' RETURN u.number, p.amount",
            "MATCH (u:Unit)-[:R]->(p:Payment) WHERE u.number IN ['310'] RETURN u.number, p.amount",
            'MATCH (u:Unit)-[:R]->(p:Payment) WHERE u.id = toInteger($x) RETURN u.number, p.amount',
            "MATCH (u:Unit)-[:R]->(p:Payment) WHERE p.amount > 0 AND (trim(u.number) STARTS WITH '31') RETURN u.number, p.amount",
            'MATCH (u:Unit)-[:R]->(p:Payment) WHERE NOT u.balance <= 100 RETURN u.number, p.amount',
            "MATCH (u:Unit)-[:R]->(p:Payment) WITH u, p WHERE u.number CONTAINS '1' RETURN u.number, p.amount",
        ];
        foreach ($pinned as $cypher) {
            $this->assertTrue(FanOutCollapse::isPinned($cypher, 'u'), $cypher);
        }

        $notPinned = [
            // joins, the tenant scoping parameter, boolean/null tests, pattern predicates
            'MATCH (u:User) OPTIONAL MATCH (tr:TeamRole) WHERE u.id = tr.user_id RETURN u.name, tr.role',
            'MATCH (u:User) WHERE u.team_id IN $ai_team_ids RETURN u.name',
            'MATCH (u:User)-[:HAS]->(c:Contact) WHERE u.active = true AND u.deleted_at IS NULL RETURN u.name, c.value',
            'MATCH (u:User)-[:HAS]->(c:Contact) WHERE (u)-->() AND (u)-[:IN]->(:Team) RETURN u.name, c.value',
            // a comment or a string is not a predicate
            "MATCH (u:User)-[:HAS]->(c:Contact) // WHERE u.name = '310'\nRETURN u.name, c.value",
            "MATCH (u:User)-[:HAS]->(c:Contact) WHERE c.note = 'u.name = 1' RETURN u.name, c.value",
        ];
        foreach ($notPinned as $cypher) {
            $this->assertFalse(FanOutCollapse::isPinned($cypher, 'u'), $cypher);
        }
    }

    /** @test */
    public function s1_a_collapsed_result_is_verified_before_it_is_trusted(): void
    {
        $plan = FanOutCollapse::plan(self::ROSTER_CONTACTS, 'u', 10);

        $this->assertTrue(FanOutCollapse::isCollapsed([
            ['member_name' => 'A', 'role' => ['owner'], 'contact_info' => ['a@x.test']],
            ['member_name' => 'B', 'role' => [], 'contact_info' => null],
        ], $plan));
        // Still one row per joined record: scalar cells, repeated anchors.
        $this->assertFalse(FanOutCollapse::isCollapsed([
            ['member_name' => 'A', 'role' => 'owner', 'contact_info' => 'a@x.test'],
            ['member_name' => 'A', 'role' => 'owner', 'contact_info' => '+1 514'],
        ], $plan));
        $this->assertFalse(FanOutCollapse::isCollapsed([
            ['member_name' => 'A', 'role' => ['owner'], 'contact_info' => ['a@x.test']],
            ['member_name' => 'A', 'role' => ['owner'], 'contact_info' => ['+1 514']],
        ], $plan));
        // A collected node / map member would be silently dropped from the cell.
        $this->assertFalse(FanOutCollapse::isCollapsed([
            ['member_name' => 'A', 'role' => ['owner'], 'contact_info' => [['value' => 'a@x.test']]],
        ], $plan));
        $this->assertFalse(FanOutCollapse::isCollapsed([], $plan));
    }

    /** @test */
    public function i1_s3_the_uncapped_export_query_always_carries_its_own_limit(): void
    {
        // The executor appends its LIMIT only when no `LIMIT` word appears
        // anywhere — a subquery LIMIT, a 'limit' string or c.limit made the
        // stripped query run unbounded.
        $this->assertSame(
            'MATCH (u:User) CALL { WITH u MATCH (u)-[:HAS]->(c:Contact) RETURN c LIMIT 1 } RETURN u.name, c.value LIMIT 250',
            FanOutCollapse::uncappedQuery('MATCH (u:User) CALL { WITH u MATCH (u)-[:HAS]->(c:Contact) RETURN c LIMIT 1 } RETURN u.name, c.value LIMIT 100', 100, 250)
        );
        $this->assertSame(
            "MATCH (u:User) WHERE u.note <> 'limit' RETURN u.name, u.limit LIMIT 250",
            FanOutCollapse::uncappedQuery("MATCH (u:User) WHERE u.note <> 'limit' RETURN u.name, u.limit", 100, 250)
        );
        $this->assertSame(
            'MATCH (u:User) RETURN u.name LIMIT 250',
            FanOutCollapse::uncappedQuery('MATCH (u:User) RETURN u.name LIMIT 100; ', 100, 250)
        );
        $this->assertSame(
            'MATCH (u:User) RETURN u.name LIMIT 250',
            FanOutCollapse::uncappedQuery('MATCH (u:User) RETURN u.name LIMIT 100 // display cap', 100, 250)
        );
        // A LIMIT below the display cap is the user's own ask.
        $this->assertNull(FanOutCollapse::uncappedQuery('MATCH (u:User) RETURN u.name LIMIT 5', 100, 250));
        // S3: a LIMIT at the display cap after ORDER BY is still the DISPLAY limit
        // (the generator's habitual `ORDER BY u.name LIMIT 100`): widened, the
        // ordering kept. Below the cap it is the user's top-N; SKIP = pagination.
        $this->assertSame(
            'MATCH (u:User) RETURN u.name ORDER BY u.name LIMIT 250',
            FanOutCollapse::uncappedQuery('MATCH (u:User) RETURN u.name ORDER BY u.name LIMIT 100', 100, 250)
        );
        $this->assertNull(FanOutCollapse::uncappedQuery('MATCH (u:User) RETURN u.name ORDER BY u.balance DESC LIMIT 5', 100, 250));
        $this->assertNull(FanOutCollapse::uncappedQuery('MATCH (u:User) RETURN u.name SKIP 100 LIMIT 100', 100, 250));
        $this->assertNull(FanOutCollapse::uncappedQuery('MATCH (u:User) RETURN u.name ORDER BY u.name SKIP 100', 100, 250));
        // A LIMIT that is not a plain number cannot be safely replaced.
        $this->assertNull(FanOutCollapse::uncappedQuery('MATCH (u:User) RETURN u.name LIMIT $n', 100, 250));
    }

    /** @test */
    public function s3_the_live_p11_export_shapes_are_widened_to_the_document_limit(): void
    {
        // Exact cyphers from CondoEdge storage/eval/p11-1001-A.json.
        $roster = 'MATCH (t:Team) WHERE t.id IN $ai_team_ids AND t.id = 3 OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) '
            . 'OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id RETURN u.name AS member_name, tr.role AS role';
        $this->assertSame($roster . ' LIMIT 250', FanOutCollapse::uncappedQuery($roster . ' LIMIT 100', 100, 250));

        // The excel turn, as collapsed per member at execution (recorded query).
        $collapsed = (string) FanOutCollapse::perAnchorQuery(self::ROSTER_CONTACTS, 'u', 10);
        $this->assertSame(
            substr($collapsed, 0, -strlen(' LIMIT 100')) . ' LIMIT 250',
            FanOutCollapse::uncappedQuery($collapsed, 100, 250)
        );

        // Ranked list at the display cap: ordering kept, cap widened.
        $units = "MATCH (un:Union) WHERE un.name = 'SDC Carré Soho Phase 3' AND un.team_id IN \$ai_team_ids "
            . 'MATCH (u:Unit)-[:UNION]->(un) WHERE u.outstanding_balance >= 0.01 '
            . 'RETURN un.name AS union_name, u.name AS unit_name, round(u.outstanding_balance, 2) AS balance, un.id AS union_id, u.id AS unit_id ORDER BY balance DESC';
        $this->assertSame($units . ' LIMIT 250', FanOutCollapse::uncappedQuery($units . ' LIMIT 100', 100, 250));

        // A LIMIT inside a WITH (not the final projection) is untouched.
        $meeting = 'MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids AND m.start_date > toString(date()) WITH m ORDER BY m.start_date LIMIT 1 '
            . 'MATCH (s:Schedule)-[:BELONGS_TO_MEETING]->(m) RETURN m.name AS meeting_name, s.title AS agenda_item, m.id AS meeting_id';
        $this->assertSame($meeting . ' LIMIT 250', FanOutCollapse::uncappedQuery($meeting, 100, 250));
    }

    /** @test */
    public function i4_an_identity_column_keeps_distinct_records_with_equal_values_apart(): void
    {
        $rows = FanOutCollapse::collapseByColumns([
            ['member_name' => 'John Smith', 'role' => 'owner', 'contact_info' => 'john1@x.test', 'user_id' => 1],
            ['member_name' => 'John Smith', 'role' => 'owner', 'contact_info' => 'john2@x.test', 'user_id' => 2],
            ['member_name' => 'John Smith', 'role' => 'owner', 'contact_info' => '+1 514', 'user_id' => 1],
            ['member_name' => null, 'role' => 'owner', 'contact_info' => null, 'user_id' => null],
            ['member_name' => null, 'role' => 'owner', 'contact_info' => null, 'user_id' => null],
        ], ['member_name', 'role'], 'user_id');

        $this->assertSame([
            ['member_name' => 'John Smith', 'role' => 'owner', 'contact_info' => 'john1@x.test; +1 514', 'user_id' => 1],
            ['member_name' => 'John Smith', 'role' => 'owner', 'contact_info' => 'john2@x.test', 'user_id' => 2],
            ['member_name' => null, 'role' => 'owner', 'contact_info' => null, 'user_id' => null],
            ['member_name' => null, 'role' => 'owner', 'contact_info' => null, 'user_id' => null],
        ], $rows);
        $this->assertSame('user_id', FanOutCollapse::idColumnFor('User'));
        $this->assertSame('team_role_id', FanOutCollapse::idColumnFor('TeamRole'));
    }
    /** @test */
    public function p12_an_extension_of_the_generated_roster_collapses_again_but_one_of_the_rewrite_cannot(): void
    {
        // Eval p12-1001-A: the follow-up EXTENDS the generator's own (pre-collapse)
        // query; its fan-out is collapsed again at execution, the added column kept.
        $generatedExtension = "MATCH (t:Team) WHERE t.id = 3 AND t.id IN \$ai_team_ids "
            . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) '
            . 'OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id '
            . 'RETURN u.name AS member_name, tr.role AS role, u.email AS email LIMIT 100';

        $plan = FanOutCollapse::plan($generatedExtension, 'u', 10);

        $this->assertNotNull($plan);
        $this->assertStringEndsWith(
            'WITH u, collect(DISTINCT tr.role)[0..11] AS role WHERE u IS NOT NULL RETURN u.name AS member_name, role, u.email AS email LIMIT 100',
            $plan['query']
        );
        $this->assertSame(['member_name', 'email'], $plan['anchor_columns']);
        $this->assertSame(['role'], $plan['collected_columns']);

        // Extending the REWRITE (what p12 recorded as the Previous Query) gives a
        // query no collapse can take: its RETURN reads WITH aliases, not patterns.
        $rewriteExtension = "MATCH (t:Team) WHERE t.id = 3 AND t.id IN \$ai_team_ids "
            . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) '
            . 'OPTIONAL MATCH (u:User) WHERE u.id = tr.user_id '
            . 'WITH u, collect(DISTINCT tr.role)[0..11] AS role WHERE u IS NOT NULL '
            . 'RETURN u.name AS member_name, role, u.email AS email LIMIT 100';
        $this->assertNull(FanOutCollapse::plan($rewriteExtension, 'u', 10));
    }
    /** @test */
    public function return_columns_are_the_final_returns_aliases_or_item_texts(): void
    {
        $this->assertSame(
            ['member_name', 'role', 'email', 'phone'],
            FanOutCollapse::returnColumns('MATCH (u:User) WITH u, collect(tr.role) AS role RETURN DISTINCT u.name AS member_name, role, u.email AS `email`, t.contact_phone AS phone ORDER BY member_name LIMIT 100')
        );
        // The final RETURN only (a subquery RETURN is not it); unaliased items keep their text.
        $this->assertSame(['u.name', 'c'], FanOutCollapse::returnColumns('MATCH (u:User) CALL { WITH u MATCH (u)--(c) RETURN c LIMIT 1 } RETURN u.name, c'));
        $this->assertSame([], FanOutCollapse::returnColumns('MATCH (u:User) RETURN *'));
        $this->assertSame([], FanOutCollapse::returnColumns('MATCH (u:User) SET u.x = 1'));
    }
}
