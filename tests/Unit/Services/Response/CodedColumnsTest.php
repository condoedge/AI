<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\CodedColumns;
use Condoedge\Ai\Tests\TestCase;

/**
 * p12 H3: a digest table never shows a raw code ("Urgent 1", "Status 1/3", "Flag Color
 * 2/3/4"). STRUCTURAL rule over the source cypher's projections:
 *  - a column projecting `v.prop` whose entity config declares an enum legend for prop
 *    ("1=High, -1=Regular") shows the legend's LABEL;
 *  - a column projecting a bare small-integer property WITHOUT a legend is a code nobody
 *    can read — it is omitted;
 *  - anything else (strings, numeric-looking names, decimals, aggregates) is untouched.
 */
class CodedColumnsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config(['entities' => [
            'App\\Models\\Task' => ['graph' => ['label' => 'Task'], 'metadata' => ['common_properties' => [
                'urgent' => 'Priority flag (-2=Low, -1=Regular, 1=High)',
                'status' => 'Task status (1=Open, 2=Pending, 3=Processing, 4=Closed)',
                'level' => 'Escalation level',
            ]]],
            'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['common_properties' => ['name' => 'Unit name']]],
            'App\\Models\\TeamRole' => ['graph' => ['label' => 'TeamRole'], 'metadata' => ['common_properties' => [
                'role' => 'Role key of the member in the team (lead=Lead union manager, manager=Union manager, contact=Unit Owner or Renter)',
            ]]],
        ]]);
    }

    /** Eval p16-B long-conversation-resilience t0 (the roster query, as generated). */
    private const ROSTER_CYPHER = 'MATCH (t:Team) WHERE t.id = 3 MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) '
        . 'MATCH (u:User) WHERE tr.user_id = u.id RETURN u.name AS member_name, tr.role AS role LIMIT 100';

    /**
     * @test Eval p16-B E7: the roster printed TeamRole.role raw ("contact", "manager"). A
     * STRING-coded column with a declared legend shows the label, like an integer-coded one;
     * a value the legend does not name keeps its value.
     */
    public function string_coded_columns_show_their_legend_labels(): void
    {
        $rows = [
            ['member_name' => 'Antoine Paquin', 'role' => 'contact'],
            ['member_name' => 'Alex Mc Nicoll', 'role' => 'manager'],
            ['member_name' => 'Julie Rousseau', 'role' => 'lead'],
            ['member_name' => 'New Hire', 'role' => 'intern'],
            ['member_name' => 'No Role', 'role' => null],
        ];
        $expected = [
            ['member_name' => 'Antoine Paquin', 'role' => 'Unit Owner or Renter'],
            ['member_name' => 'Alex Mc Nicoll', 'role' => 'Union manager'],
            ['member_name' => 'Julie Rousseau', 'role' => 'Lead union manager'],
            ['member_name' => 'New Hire', 'role' => 'intern'],
            ['member_name' => 'No Role', 'role' => null],
        ];

        $this->assertSame($expected, CodedColumns::labelled($rows, self::ROSTER_CYPHER));
        $this->assertSame($expected, CodedColumns::apply($rows, self::ROSTER_CYPHER));
        $this->assertSame(
            ['role' => ['lead' => 'Lead union manager', 'manager' => 'Union manager', 'contact' => 'Unit Owner or Renter']],
            CodedColumns::legends(self::ROSTER_CYPHER)
        );
    }

    /** @test Eval p16-B french-flow t0: the generator aliased the column `rôle` — a non-ASCII alias is a column like any other. */
    public function a_non_ascii_alias_is_resolved(): void
    {
        $cypher = 'MATCH (t:Team) WHERE t.id = 3 MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) MATCH (u:User) WHERE tr.user_id = u.id '
            . 'RETURN u.name AS membre, tr.role AS rôle LIMIT 100';

        $this->assertSame(
            [['membre' => 'Alex Mc Nicoll', 'rôle' => 'Union manager']],
            CodedColumns::labelled([['membre' => 'Alex Mc Nicoll', 'rôle' => 'manager']], $cypher)
        );
    }

    /**
     * @test labelled() is the DATA-ANSWER mapping: codes → labels and nothing else — a
     * legend-less small-integer column the user asked for is never dropped (only the digest's
     * apply() omits bare codes).
     */
    public function labelled_never_drops_a_column(): void
    {
        $cypher = 'MATCH (t:Task) RETURN t.title AS title, t.urgent AS urgent, t.level AS level';
        $rows = [['title' => 'Leak', 'urgent' => 1, 'level' => 2], ['title' => 'Door', 'urgent' => '-1', 'level' => 1]];

        $this->assertSame(
            [['title' => 'Leak', 'urgent' => 'High', 'level' => 2], ['title' => 'Door', 'urgent' => 'Regular', 'level' => 1]],
            CodedColumns::labelled($rows, $cypher)
        );
        $this->assertSame($rows, CodedColumns::labelled($rows, null));
        $this->assertSame([['title' => 'Leak', 'level' => 2]], CodedColumns::labelled([['title' => 'Leak', 'level' => 2]], $cypher));
    }

    /**
     * @test A legend label is HOST text (as a host action label, p11 G5): given the answer's
     * language it is resolved through the translator — a French roster shows the host's French
     * label when the host ships one, the declared label otherwise; without a locale nothing
     * is translated.
     */
    public function legend_labels_are_translated_into_the_given_locale_when_the_host_ships_a_translation(): void
    {
        app('translator')->addLines(['*.Union manager' => 'Gestionnaire'], 'fr');
        app()->setLocale('en');
        $rows = [['member_name' => 'Alex Mc Nicoll', 'role' => 'manager'], ['member_name' => 'Julie Rousseau', 'role' => 'lead']];

        $this->assertSame(
            [['member_name' => 'Alex Mc Nicoll', 'role' => 'Gestionnaire'], ['member_name' => 'Julie Rousseau', 'role' => 'Lead union manager']],
            CodedColumns::labelled($rows, self::ROSTER_CYPHER, 'fr')
        );
        $this->assertSame('Union manager', CodedColumns::labelled($rows, self::ROSTER_CYPHER, 'en')[0]['role']);

        app()->setLocale('fr');
        $this->assertSame('Union manager', CodedColumns::labelled($rows, self::ROSTER_CYPHER)[0]['role'], 'no locale given → the declared label');
    }

    /** @test */
    public function legend_columns_show_labels_and_unlegended_small_codes_are_dropped(): void
    {
        $cypher = 'MATCH (t:Task) RETURN t.id AS id, t.title AS title, t.urgent AS urgent, t.status AS status, t.level AS level';
        $rows = [
            ['id' => 1, 'title' => 'Leak', 'urgent' => 1, 'status' => 1, 'level' => 2],
            ['id' => 2, 'title' => 'Door', 'urgent' => -1, 'status' => '3', 'level' => 1],
        ];

        $this->assertSame([
            ['id' => 1, 'title' => 'Leak', 'urgent' => 'High', 'status' => 'Open'],
            ['id' => 2, 'title' => 'Door', 'urgent' => 'Regular', 'status' => 'Processing'],
        ], CodedColumns::apply($rows, $cypher));
    }

    /** @test */
    public function a_code_outside_the_legend_keeps_its_value_and_strings_numeric_names_and_aggregates_are_untouched(): void
    {
        $cypher = 'MATCH (t:Task)-[:UNIT]->(u:Unit) RETURN u.name AS unit_name, t.urgent AS urgent, count(t) AS n';
        $rows = [
            ['unit_name' => '203', 'urgent' => 7, 'n' => 2],
            ['unit_name' => '312', 'urgent' => 1, 'n' => 3],
        ];

        $this->assertSame([
            ['unit_name' => '203', 'urgent' => 7, 'n' => 2],
            ['unit_name' => '312', 'urgent' => 'High', 'n' => 3],
        ], CodedColumns::apply($rows, $cypher));
    }

    /** @test */
    public function without_a_cypher_or_a_resolvable_label_rows_are_returned_unchanged(): void
    {
        $rows = [['urgent' => 1, 'status' => 2]];

        $this->assertSame($rows, CodedColumns::apply($rows, null));
        $this->assertSame($rows, CodedColumns::apply($rows, 'MATCH (x:Unknown) RETURN x.urgent AS urgent, x.status AS status'));
        $this->assertSame(['urgent' => ['-2' => 'Low', '-1' => 'Regular', '1' => 'High']], CodedColumns::legends('MATCH (t:Task) RETURN t.urgent AS urgent'));
    }

    /** @test ids and declared foreign keys are identifiers, never bare codes. */
    public function ids_and_declared_foreign_keys_are_never_dropped(): void
    {
        config(['entities.App\\Models\\Task.graph.relationships' => [['type' => 'UNION', 'target_label' => 'Union', 'foreign_key' => 'union_id']]]);
        $rows = [['id' => 1, 'union_id' => 3, 'level' => 2], ['id' => 2, 'union_id' => 4, 'level' => 1]];

        $this->assertSame(
            [['id' => 1, 'union_id' => 3], ['id' => 2, 'union_id' => 4]],
            CodedColumns::apply($rows, 'MATCH (t:Task) RETURN t.id AS id, t.union_id AS union_id, t.level AS level')
        );
    }

    /** @test */
    public function an_unaliased_projection_resolves_through_its_expression_key(): void
    {
        $rows = [['t.urgent' => 1]];

        $this->assertSame([['t.urgent' => 'High']], CodedColumns::apply($rows, 'MATCH (t:Task) RETURN t.urgent'));
    }

    // =========================================================================
    // p17 (A roster-excel, B resilience t3 / french t1): SEVERAL CODES IN ONE CELL
    // =========================================================================

    /** The roster with the OPTIONAL role join the p17 generator wrote (a member may hold several roles). */
    private const FAN_OUT_ROSTER_CYPHER = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id '
        . 'RETURN u.name AS name, u.email AS email, tr.role AS role LIMIT 100';

    /**
     * @test The fan-out collapse records the roster as one row per member with the roles
     * COLLECTED (`WITH u, collect(DISTINCT tr.role)[0..11] AS role … RETURN …, role`): the cell
     * is a list at execution and an "a; b" string once joined (FanOutCollapse::joinLists). The
     * column still holds the coded property — each element reads as its label, a value the
     * legend does not name keeps its value, the truncation marker stays.
     */
    public function a_collected_coded_property_is_labelled_element_by_element(): void
    {
        $collapsed = \Condoedge\Ai\Services\Query\FanOutCollapse::perAnchorQuery(self::FAN_OUT_ROSTER_CYPHER, 'u', 10);
        $this->assertNotNull($collapsed);
        $this->assertStringContainsString('collect(DISTINCT tr.role)', (string) $collapsed);

        $rows = [
            ['name' => 'Julie Rousseau', 'email' => 'j@novo.test', 'role' => ['lead', 'contact']],
            ['name' => 'Alex Mc Nicoll', 'email' => 'a@novo.test', 'role' => 'manager; contact'],
            ['name' => 'Many Roles', 'email' => 'm@novo.test', 'role' => 'manager; contact; …'],
            ['name' => 'New Hire', 'email' => 'n@novo.test', 'role' => ['intern', 'manager']],
            ['name' => 'One Role', 'email' => 'o@novo.test', 'role' => 'contact'],
            ['name' => 'No Role', 'email' => 'x@novo.test', 'role' => null],
        ];

        $this->assertSame([
            ['name' => 'Julie Rousseau', 'email' => 'j@novo.test', 'role' => ['Lead union manager', 'Unit Owner or Renter']],
            ['name' => 'Alex Mc Nicoll', 'email' => 'a@novo.test', 'role' => 'Union manager; Unit Owner or Renter'],
            ['name' => 'Many Roles', 'email' => 'm@novo.test', 'role' => 'Union manager; Unit Owner or Renter; …'],
            ['name' => 'New Hire', 'email' => 'n@novo.test', 'role' => ['intern', 'Union manager']],
            ['name' => 'One Role', 'email' => 'o@novo.test', 'role' => 'Unit Owner or Renter'],
            ['name' => 'No Role', 'email' => 'x@novo.test', 'role' => null],
        ], CodedColumns::labelled($rows, $collapsed));
        $this->assertArrayHasKey('role', CodedColumns::legends($collapsed));
    }

    /** @test The same when the generator itself collects the property in the RETURN, or the collapse used an internal alias. */
    public function a_collect_written_in_the_return_or_under_an_internal_alias_is_resolved(): void
    {
        $direct = 'MATCH (u:User) OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) RETURN u.name AS name, collect(DISTINCT tr.role) AS role LIMIT 100';
        $internal = 'MATCH (u:User) OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) '
            . 'WITH u, collect(DISTINCT tr.role)[0..11] AS __fo1 WHERE u IS NOT NULL RETURN u.name AS name, __fo1 AS `rôle` LIMIT 100';

        $this->assertSame(
            [['name' => 'Alex', 'role' => ['Union manager', 'Unit Owner or Renter']]],
            CodedColumns::labelled([['name' => 'Alex', 'role' => ['manager', 'contact']]], $direct)
        );
        $this->assertSame(
            [['name' => 'Alex', 'rôle' => 'Union manager; Unit Owner or Renter']],
            CodedColumns::labelled([['name' => 'Alex', 'rôle' => 'manager; contact']], $internal)
        );
    }

    /**
     * @test An export that collapses a fresh fan-out to the previous grain joins a PLAIN
     * projection's values "a; b" (FanOutCollapse::collapseByColumns): a cell that is not itself
     * a code but whose every joined part is one reads as the labels. A free value that merely
     * contains the separator is never taken apart.
     */
    public function a_joined_cell_of_a_plain_coded_projection_is_labelled_when_every_part_is_a_code(): void
    {
        $rows = [
            ['name' => 'Alex', 'email' => 'a@novo.test', 'role' => 'manager; contact'],
            ['name' => 'Odd', 'email' => 'o@novo.test', 'role' => 'manager; see notes'],
        ];

        $this->assertSame([
            ['name' => 'Alex', 'email' => 'a@novo.test', 'role' => 'Union manager; Unit Owner or Renter'],
            ['name' => 'Odd', 'email' => 'o@novo.test', 'role' => 'manager; see notes'],
        ], CodedColumns::labelled($rows, self::FAN_OUT_ROSTER_CYPHER));
    }

    /** @test A collected expression, a collected uncoded property and a count are no coded column. */
    public function other_aggregates_are_untouched(): void
    {
        $cypher = 'MATCH (u:User) OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) '
            . 'RETURN u.name AS name, count(tr.role) AS role, collect(DISTINCT toUpper(tr.role)) AS shout, collect(DISTINCT u.email) AS emails';
        $rows = [['name' => 'Alex', 'role' => 2, 'shout' => ['MANAGER'], 'emails' => ['manager']]];

        $this->assertSame($rows, CodedColumns::labelled($rows, $cypher));
    }
}
