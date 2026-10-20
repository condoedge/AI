<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Attach;

use Condoedge\Ai\Services\Actions\Attach\RecordResolver;
use Condoedge\Ai\Tests\TestCase;

/**
 * RecordResolver (actions plan B1) — which record each row is, its name and its homonym
 * disambiguator. Structural signals only (cypher projections, id columns, value shapes).
 */
class RecordResolverTest extends TestCase
{
    private const UNIT_UNION = 'MATCH (u:Unit)-[:UNION]->(un:Union) RETURN u.name AS unit_name, un.name AS union_name, u.id AS unit_id, un.id AS union_id';

    /** @test The row's grain wins: the id that varies across rows, not the parent id every row repeats. */
    public function the_varying_id_is_the_record_not_the_repeated_parent(): void
    {
        $rows = [
            ['unit_name' => '305', 'union_name' => 'Carré Soho', 'unit_id' => 154, 'union_id' => 8],
            ['unit_name' => '311', 'union_name' => 'Carré Soho', 'unit_id' => 160, 'union_id' => 8],
        ];

        $records = (new RecordResolver(['Unit', 'Union'], self::UNIT_UNION, ['Union'], ['id']))->resolve($rows);

        $this->assertSame(['Unit', 'Unit'], array_column($records, 'label'));
        $this->assertSame(['154', '160'], array_column($records, 'id'));
        $this->assertSame(['305', '311'], array_column($records, 'name'));
    }

    /** @test A single row (no grain signal): QU's preferred label still decides. */
    public function a_single_row_keeps_the_qu_preference(): void
    {
        $rows = [['unit_name' => '305', 'union_name' => 'Carré Soho', 'unit_id' => 154, 'union_id' => 8]];

        $records = (new RecordResolver(['Unit', 'Union'], self::UNIT_UNION, ['Union'], ['id']))->resolve($rows);

        $this->assertSame('Union', $records[0]['label']);
        $this->assertSame('8', $records[0]['id']);
    }

    /**
     * @test Eval p9 F7: a date / datetime value is never a disambiguator ("SDC Carré Soho Phase 3 —
     * 2021-12-01T15:00:00.000000Z"), nor an aggregate's per-row value.
     */
    public function dates_and_aggregates_are_never_disambiguators(): void
    {
        $cypher = 'MATCH (un:Union)<-[:UNION]-(u:Unit)<-[:CUSTOMER]-(i:Invoice) WITH un, u, min(i.due_at) AS oldest_due, max(i.number) AS last_number '
            . 'RETURN un.name AS union_name, oldest_due, i.due_on AS due_on, last_number, un.id AS union_id';
        // Three DIFFERENT unions sharing a name (real homonyms): only dates and aggregates differ.
        $rows = [
            ['union_name' => 'Carré Soho', 'oldest_due' => '2021-12-01T15:00:00.000000Z', 'due_on' => '2021-12-01', 'last_number' => 'INV-1', 'union_id' => 8],
            ['union_name' => 'Carré Soho', 'oldest_due' => '2022-01-01T05:00:00.000000Z', 'due_on' => '2022-01-01', 'last_number' => 'INV-2', 'union_id' => 9],
            ['union_name' => 'Carré Soho', 'oldest_due' => '2020-11-17 05:00:00', 'due_on' => '2020-11-17', 'last_number' => 'INV-3', 'union_id' => 10],
        ];

        $records = (new RecordResolver(['Union'], $cypher, [], ['id']))->resolve($rows);

        $this->assertCount(3, $records);
        $this->assertSame([null, null, null], array_column($records, 'disambiguator'));
    }

    /**
     * @test One record repeated over several rows (the union beside each of its units, when
     * only the union is openable) is not a homonym: no "— 305" child value as disambiguator.
     */
    public function a_record_repeated_over_rows_gets_no_disambiguator(): void
    {
        $rows = [
            ['unit_name' => '305', 'union_name' => 'Carré Soho', 'unit_id' => 154, 'union_id' => 8],
            ['unit_name' => '311', 'union_name' => 'Carré Soho', 'unit_id' => 160, 'union_id' => 8],
        ];

        $records = (new RecordResolver(['Union'], self::UNIT_UNION, [], ['id']))->resolve($rows);

        $this->assertSame(['8', '8'], array_column($records, 'id'));
        $this->assertSame(['Carré Soho', 'Carré Soho'], array_column($records, 'name'));
        $this->assertSame([null, null], array_column($records, 'disambiguator'));
    }

    /** @test The first QU-named label whose id VARIES is the record — even with fewer ids than another label. */
    public function the_first_varying_qu_label_wins_over_the_label_with_most_ids(): void
    {
        $cypher = 'MATCH (c:Contact)-[:MEMBER]->(un:Union) RETURN un.name AS union_name, c.name AS member, un.id AS union_id, c.id AS contact_id';
        $rows = [
            ['union_name' => 'A', 'member' => 'Ann', 'union_id' => 1, 'contact_id' => 10],
            ['union_name' => 'A', 'member' => 'Bob', 'union_id' => 1, 'contact_id' => 11],
            ['union_name' => 'B', 'member' => 'Cid', 'union_id' => 2, 'contact_id' => 12],
        ];

        $records = (new RecordResolver(['Union', 'Contact'], $cypher, ['BoardMember', 'Union'], ['id']))->resolve($rows);

        $this->assertSame(['Union', 'Union', 'Union'], array_column($records, 'label'));
        $this->assertSame(['1', '1', '2'], array_column($records, 'id'));
    }

    /**
     * @test I1: with no QU-named label varying, the grain is counted per id COLUMN (node
     * variable), not per label: owner_id + tenant_id never add up to outnumber the units.
     */
    public function the_grain_fallback_counts_ids_per_column_not_per_label(): void
    {
        $cypher = 'MATCH (o:Contact)<-[:OWNER]-(u:Unit)-[:TENANT]->(t:Contact) RETURN u.name AS unit_name, u.id AS unit_id, o.id AS owner_id, t.id AS tenant_id';
        $rows = [
            ['unit_name' => '101', 'unit_id' => 1, 'owner_id' => 50, 'tenant_id' => 60],
            ['unit_name' => '102', 'unit_id' => 2, 'owner_id' => 51, 'tenant_id' => 61],
            ['unit_name' => '103', 'unit_id' => 3, 'owner_id' => 50, 'tenant_id' => 60],
        ];

        $records = (new RecordResolver(['Unit', 'Contact'], $cypher, [], ['id']))->resolve($rows);

        $this->assertSame(['Unit', 'Unit', 'Unit'], array_column($records, 'label'));
    }

    /** @test I3: a record NAMED by a date (a meeting held on a day) keeps its homonym disambiguation. */
    public function a_record_named_by_a_date_is_still_disambiguated(): void
    {
        $cypher = 'MATCH (m:Meeting)-[:OF]->(un:Union) RETURN m.id AS meeting_id, m.held_on AS held_on, un.name AS union_name';
        $rows = [
            ['meeting_id' => 1, 'held_on' => '2024-05-01', 'union_name' => 'Carré Soho'],
            ['meeting_id' => 2, 'held_on' => '2024-05-01', 'union_name' => 'Les Terrasses'],
            ['meeting_id' => 3, 'held_on' => '2024-06-01', 'union_name' => 'Carré Soho'],
        ];

        $records = (new RecordResolver(['Meeting'], $cypher, [], ['id']))->resolve($rows);

        $this->assertSame(['2024-05-01', '2024-05-01', '2024-06-01'], array_column($records, 'name'));
        $this->assertSame(['Carré Soho', 'Les Terrasses', null], array_column($records, 'disambiguator'));
    }

    /**
     * @test I4: the disambiguator comes from ANOTHER node (the parent union), never the
     * record's own attribute ("310 — occupied") even when that column is coarser.
     */
    public function the_disambiguator_prefers_another_nodes_column_over_the_records_own(): void
    {
        $cypher = 'MATCH (u:Unit)-[:UNION]->(un:Union) RETURN u.id AS unit_id, u.name AS unit_name, u.status AS status, un.name AS union_name';
        $rows = [
            ['unit_id' => 159, 'unit_name' => '310', 'status' => 'occupied', 'union_name' => 'Carré Soho Phase 3'],
            ['unit_id' => 412, 'unit_name' => '310', 'status' => 'vacant', 'union_name' => 'Les Terrasses'],
            ['unit_id' => 733, 'unit_name' => '310', 'status' => 'occupied', 'union_name' => 'Bruxelles'],
        ];

        $records = (new RecordResolver(['Unit'], $cypher, [], ['id']))->resolve($rows);

        $this->assertSame(['Carré Soho Phase 3', 'Les Terrasses', 'Bruxelles'], array_column($records, 'disambiguator'));
    }

    /** @test S2: homonym names compare case- and space-insensitively ("Lot A" / "lot a "). */
    public function homonyms_compare_case_insensitively(): void
    {
        $rows = [
            ['unit_id' => 1, 'unit_name' => 'Lot A', 'union_name' => 'Carré Soho'],
            ['unit_id' => 2, 'unit_name' => 'lot a ', 'union_name' => 'Les Terrasses'],
        ];

        $records = (new RecordResolver(['Unit'], self::HOMONYM_CYPHER, [], ['id']))->resolve($rows);

        $this->assertSame(['Carré Soho', 'Les Terrasses'], array_column($records, 'disambiguator'));
    }

    /** @test S3: homonyms past the 10th row are still disambiguated. */
    public function homonyms_beyond_ten_rows_are_disambiguated(): void
    {
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $rows[] = ['unit_id' => $i, 'unit_name' => 'Lot ' . $i, 'union_name' => 'Union A'];
        }
        $rows[] = ['unit_id' => 99, 'unit_name' => 'Lot 12', 'union_name' => 'Union B'];

        $records = (new RecordResolver(['Unit'], self::HOMONYM_CYPHER, [], ['id']))->resolve($rows);

        $this->assertSame('Union A', $records[11]['disambiguator']);
        $this->assertSame('Union B', $records[12]['disambiguator']);
    }

    private const HOMONYM_CYPHER = 'MATCH (u:Unit)-[:UNION]->(un:Union) RETURN u.id AS unit_id, u.name AS unit_name, un.name AS union_name';

    /**
     * @test I5: a row's declared entity (`_label`) wins over QU, and its name never comes from
     * another record's column on the row.
     */
    public function a_declared_label_wins_and_names_stay_with_their_record(): void
    {
        $records = (new RecordResolver(['Unit', 'Invoice'], null, ['Invoice'], ['id']))->resolve([
            ['_label' => 'Unit', 'id' => 12, 'unit_name' => '301', 'invoice_id' => 700],
        ]);
        $this->assertSame(['Unit', '12', '301'], [$records[0]['label'], $records[0]['id'], $records[0]['name']]);

        // Without a marker the invoice is the record — but "301" is the UNIT's name.
        $records = (new RecordResolver(['Unit', 'Invoice'], null, ['Invoice'], ['id']))->resolve([
            ['invoice_id' => 700, 'unit_name' => '301', 'unit_id' => 12],
        ]);
        $this->assertSame(['Invoice', '700', null], [$records[0]['label'], $records[0]['id'], $records[0]['name']]);
        $this->assertSame(['label' => 'Unit', 'name' => '301'], $records[0]['context']);
    }

    /**
     * @test Eval p10-A (data-answer HOMONYMS note): the repeated parent (union_id 8 on every
     * unit row) is ONE record — no homonym group, whatever its name repeats.
     */
    public function homonym_groups_never_count_a_repeated_parent(): void
    {
        $rows = [
            ['union_name' => 'Carré Soho', 'unit_name' => '305', 'oldest_due' => '2021-12-01T15:00:00.000000Z', 'union_id' => 8, 'unit_id' => 154],
            ['union_name' => 'Carré Soho', 'unit_name' => '311', 'oldest_due' => '2022-01-01T05:00:00.000000Z', 'union_id' => 8, 'unit_id' => 160],
            ['union_name' => 'Carré Soho', 'unit_name' => '308', 'oldest_due' => '2022-01-01T05:00:00.000000Z', 'union_id' => 8, 'unit_id' => 157],
        ];

        $this->assertSame([], (new RecordResolver(['Unit', 'Union'], self::UNIT_UNION, [], ['id']))->homonyms($rows));
    }

    /**
     * @test The mirror image: three DISTINCT units named "310" are homonyms by identity — even
     * when the union's id column comes first and nothing names Unit as preferred.
     */
    public function homonym_groups_are_distinct_records_sharing_a_name(): void
    {
        $cypher = 'MATCH (un:Union)<-[:UNION]-(u:Unit) RETURN u.name AS unit_name, un.name AS union_name, u.updated_at AS updated, un.id AS union_id, u.id AS unit_id';
        $rows = [
            ['unit_name' => '310', 'union_name' => 'Alpha', 'updated' => '2024-01-01', 'union_id' => 1, 'unit_id' => 159],
            ['unit_name' => '310', 'union_name' => 'Beta', 'updated' => '2024-02-01', 'union_id' => 2, 'unit_id' => 412],
            ['unit_name' => '310', 'union_name' => 'Gamma', 'updated' => '2024-03-01', 'union_id' => 3, 'unit_id' => 733],
        ];

        $groups = (new RecordResolver(['Unit', 'Union'], $cypher, [], ['id']))->homonyms($rows);

        $this->assertSame([[
            'column' => 'unit_name',
            'value' => '310',
            'count' => 3,
            'rows' => 3,
            'by' => 'union_name',
            'values' => ['Alpha', 'Beta', 'Gamma'],
        ]], $groups);
    }

    /** @test Rows the resolver cannot attribute to a record: identity unknown (null) — the caller falls back. */
    public function homonym_groups_are_unknown_without_record_identity(): void
    {
        $rows = [
            ['unit' => '310', 'union_name' => 'Alpha'],
            ['unit' => '310', 'union_name' => 'Beta'],
        ];

        $this->assertNull((new RecordResolver(['Unit', 'Union'], 'MATCH (u:Unit) RETURN u', [], ['id']))->homonyms($rows));
    }

    // ------------------------------------------------ split names (eval p16-A board, F2a)

    /** Eval p16-A board-and-meetings t0, as generated (unaliased name columns, no union id). */
    private const BOARD_CYPHER = 'MATCH (b:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) WHERE un.team_id IN $ai_team_ids AND un.deleted_at IS NULL '
        . 'WITH un, max(b.year) AS latest_year MATCH (cur:BoardMember)-[:BELONGS_TO_UNION]->(un) WHERE cur.year = latest_year '
        . 'MATCH (cur)-[:BELONGS_TO_CONTACT]->(c:Contact) '
        . 'RETURN un.name AS union_name, c.first_name, c.last_name, cur.bm_role, cur.year, c.id AS contact_id ORDER BY un.name';

    /** The host declares which properties make a contact's name (metadata.name_properties). */
    private function contactsHaveSplitNames(): void
    {
        config(['entities' => [
            'App\\Models\\Contact' => ['graph' => ['label' => 'Contact'], 'metadata' => ['name_properties' => ['first_name', 'last_name']]],
            'App\\Models\\Unit' => ['graph' => ['label' => 'Unit']],
        ]]);
    }

    /** @return array<string, mixed> */
    private function boardRow(string $union, string $first, ?string $last, int $contactId): array
    {
        return ['union_name' => $union, 'c.first_name' => $first, 'c.last_name' => $last, 'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => $contactId];
    }

    /**
     * @test Eval p16-A board (F2a): a record whose entity declares several name properties is
     * named by ALL of them, joined in the declared order — "Amélie Quirion", never the first
     * projected text column alone ("Amélie").
     */
    public function a_record_is_named_by_all_its_declared_name_properties(): void
    {
        $this->contactsHaveSplitNames();
        $rows = [
            $this->boardRow('825 de Bruxelles', 'Amélie', 'Quirion', 779),
            $this->boardRow('825 de Bruxelles', 'Mauricio', 'Ruiz', 1436),
        ];

        $records = (new RecordResolver(['Contact'], self::BOARD_CYPHER, ['BoardMember'], ['id']))->resolve($rows);

        $this->assertSame(['Amélie Quirion', 'Mauricio Ruiz'], array_column($records, 'name'));
        $this->assertSame(['c.first_name', 'c.first_name'], array_column($records, 'name_column'));
        $this->assertSame([['c.first_name', 'c.last_name'], ['c.first_name', 'c.last_name']], array_column($records, 'name_columns'));
    }

    /**
     * @test The declared ORDER names the record, whatever order (or alias) the query projects
     * the parts in; a part that is missing or empty is simply left out.
     */
    public function declared_name_parts_are_joined_in_the_declared_order(): void
    {
        $this->contactsHaveSplitNames();
        $cypher = 'MATCH (c:Contact) RETURN c.last_name AS nom, c.title AS title, c.first_name AS prenom, c.id AS contact_id';
        $rows = [
            ['nom' => 'Quirion', 'title' => 'Mrs.', 'prenom' => 'Amélie', 'contact_id' => 779],
            ['nom' => 'Ruiz', 'title' => 'Mr.', 'prenom' => '', 'contact_id' => 1436],
            ['nom' => null, 'title' => 'Dr.', 'prenom' => 'Denis', 'contact_id' => 876],
        ];

        $records = (new RecordResolver(['Contact'], $cypher, [], ['id']))->resolve($rows);

        $this->assertSame(['Amélie Quirion', 'Ruiz', 'Denis'], array_column($records, 'name'));

        // No cypher: the label-prefixed columns carry the same properties.
        $records = (new RecordResolver(['Contact'], null, [], ['id']))->resolve([
            ['contact_id' => 779, 'contact_last_name' => 'Quirion', 'contact_first_name' => 'Amélie'],
            ['contact_id' => 876, 'contactLastName' => 'Bertrand', 'contactFirstName' => 'Denis'],
        ]);
        $this->assertSame(['Amélie Quirion', 'Denis Bertrand'], array_column($records, 'name'));
    }

    /**
     * @test Declared name properties the query did not project fall back to the record's first
     * own text column (as for an entity that declares none); another node's column never
     * joins a name.
     */
    public function without_projected_name_properties_the_first_own_text_column_names_the_record(): void
    {
        $this->contactsHaveSplitNames();
        $cypher = 'MATCH (c:Contact)-[:OWNS]->(u:Unit) RETURN c.company AS company, c.title AS title, u.name AS unit_name, c.id AS contact_id';
        $rows = [
            ['company' => 'Gestion ABC', 'title' => 'Mrs.', 'unit_name' => '305', 'contact_id' => 1],
            ['company' => 'Immo XYZ', 'title' => 'Mr.', 'unit_name' => '311', 'contact_id' => 2],
        ];

        $records = (new RecordResolver(['Contact'], $cypher, [], ['id']))->resolve($rows);

        $this->assertSame(['Gestion ABC', 'Immo XYZ'], array_column($records, 'name'));

        // An entity declaring nothing: unchanged — the first own text column alone.
        $units = 'MATCH (u:Unit) RETURN u.name AS unit_name, u.status AS status, u.id AS unit_id';
        $records = (new RecordResolver(['Unit'], $units, [], ['id']))->resolve([
            ['unit_name' => '305', 'status' => 'occupied', 'unit_id' => 154],
            ['unit_name' => '311', 'status' => 'vacant', 'unit_id' => 160],
        ]);
        $this->assertSame(['305', '311'], array_column($records, 'name'));
    }

    /**
     * @test Eval p16-A board: two different people who share a FIRST name ("Julien Ménard",
     * "Julien Allard") are not homonyms — the data answer's HOMONYMS note and the chips'
     * disambiguator compare the whole name. Two people sharing the WHOLE name still are, and
     * are told apart by their union.
     */
    public function people_sharing_only_a_first_name_are_not_homonyms(): void
    {
        $this->contactsHaveSplitNames();
        $rows = [
            $this->boardRow("SDC 2205 Chemin d'oka", 'Julien', 'Ménard', 63),
            $this->boardRow('SDC O Quai du nord', 'Julien', 'Allard', 1201),
            $this->boardRow('SDC O Quai du nord', 'Karine', 'Ouellette', 1202),
        ];
        $resolver = new RecordResolver(['Contact'], self::BOARD_CYPHER, ['BoardMember'], ['id']);

        $this->assertSame([], $resolver->homonyms($rows));
        $this->assertSame([null, null, null], array_column($resolver->resolve($rows), 'disambiguator'));

        $rows[1] = $this->boardRow('SDC O Quai du nord', 'Julien', 'Ménard', 1201);
        $groups = $resolver->homonyms($rows);

        $this->assertCount(1, $groups);
        $this->assertSame(['c.first_name', 'Julien Ménard', 2, 'union_name'], [$groups[0]['column'], $groups[0]['value'], $groups[0]['count'], $groups[0]['by']]);
        $this->assertSame(["SDC 2205 Chemin d'oka", 'SDC O Quai du nord', null], array_column($resolver->resolve($rows), 'disambiguator'));
    }

    // ------------------------------------------------ record groups (eval p16-A board, F2b)

    /**
     * @test Eval p16-A board (F2b): the records' GROUP is the coarser column of another node —
     * each board member's union — so a capped chip list can be spread over the groups.
     */
    public function records_carry_the_coarser_column_of_another_node_as_their_group(): void
    {
        $this->contactsHaveSplitNames();
        $rows = [
            $this->boardRow('825 de Bruxelles', 'Amélie', 'Quirion', 779),
            $this->boardRow('825 de Bruxelles', 'Mauricio', 'Ruiz', 1436),
            $this->boardRow("SDC 2205 Chemin d'oka", 'Julien', 'Ménard', 63),
            // One person on a second board: still one record, the column still groups.
            $this->boardRow('SDC O Quai du nord', 'Julien', 'Ménard', 63),
            $this->boardRow('SDC O Quai du nord', 'Karine', 'Ouellette', 1202),
        ];

        $records = (new RecordResolver(['Contact'], self::BOARD_CYPHER, ['BoardMember'], ['id']))->resolve($rows);

        $this->assertSame(
            ['825 de Bruxelles', '825 de Bruxelles', "SDC 2205 Chemin d'oka", 'SDC O Quai du nord', 'SDC O Quai du nord'],
            array_column($records, 'group')
        );
    }

    /**
     * @test A group is a PARENT of the records, never something they contain or merely carry:
     *  - a child node's column (the contacts listed beside each union) varies inside one record;
     *  - a column with one value per record (as fine as the records) groups nothing;
     *  - a column every record shares (one union for all units) groups nothing;
     *  - the record's own attribute, a date and an aggregate's result are never groups.
     */
    public function only_a_coarser_parent_column_is_a_group(): void
    {
        $unions = 'MATCH (un:Union)<-[:BELONGS_TO_UNION]-(b:BoardMember)-[:BELONGS_TO_CONTACT]->(c:Contact) '
            . 'RETURN un.name AS union_name, c.first_name AS first_name, un.id AS union_id';
        $rows = [];
        foreach ([1, 2, 3] as $union) {
            foreach (['Ann', 'Bob'] as $first) {
                $rows[] = ['union_name' => 'Union ' . $union, 'first_name' => $first, 'union_id' => $union];
            }
        }
        $records = (new RecordResolver(['Union'], $unions, [], ['id']))->resolve($rows);
        $this->assertSame(array_fill(0, 6, null), array_column($records, 'group'), 'a child column (2 values < 3 unions) varies inside each union');

        $units = 'MATCH (u:Unit)-[:UNION]->(un:Union) OPTIONAL MATCH (i:Invoice)-[:CUSTOMER]->(u) '
            . 'RETURN u.id AS unit_id, u.name AS unit_name, u.status AS status, un.name AS union_name, un.code AS union_code, '
            . 'un.created_at AS union_created, max(i.number) AS last_invoice';
        $rows = [
            ['unit_id' => 1, 'unit_name' => '101', 'status' => 'occupied', 'union_name' => 'Union A', 'union_code' => 'A', 'union_created' => '2020-01-01', 'last_invoice' => 'INV-1'],
            ['unit_id' => 2, 'unit_name' => '102', 'status' => 'vacant', 'union_name' => 'Union A', 'union_code' => 'A', 'union_created' => '2020-01-01', 'last_invoice' => 'INV-1'],
            ['unit_id' => 3, 'unit_name' => '103', 'status' => 'occupied', 'union_name' => 'Union A', 'union_code' => 'A', 'union_created' => '2021-01-01', 'last_invoice' => 'INV-2'],
        ];
        $records = (new RecordResolver(['Unit'], $units, [], ['id']))->resolve($rows);
        $this->assertSame([null, null, null], array_column($records, 'group'), 'one union for every unit; status is the unit\'s own; dates and aggregates never group');

        $rows[2] = ['unit_id' => 3, 'unit_name' => '103', 'status' => 'occupied', 'union_name' => 'Union B', 'union_code' => 'B', 'union_created' => '2021-01-01', 'last_invoice' => 'INV-2'];
        $records = (new RecordResolver(['Unit'], $units, [], ['id']))->resolve($rows);
        $this->assertSame(['Union A', 'Union A', 'Union B'], array_column($records, 'group'), 'the first coarser parent column in column order');
    }

    /** @test Real homonyms keep their parent disambiguator (three units "310" in three unions). */
    public function homonyms_keep_their_parent_disambiguator(): void
    {
        $cypher = 'MATCH (u:Unit)-[:UNION]->(un:Union) RETURN u.id AS unit_id, u.name AS unit_name, un.name AS union_name, u.created_at AS created';
        $rows = [
            ['unit_id' => 159, 'unit_name' => '310', 'union_name' => 'Carré Soho Phase 3', 'created' => '2021-01-01T00:00:00Z'],
            ['unit_id' => 412, 'unit_name' => '310', 'union_name' => 'Les Terrasses', 'created' => '2021-02-01T00:00:00Z'],
            ['unit_id' => 733, 'unit_name' => '310', 'union_name' => 'Bruxelles', 'created' => '2021-03-01T00:00:00Z'],
        ];

        $records = (new RecordResolver(['Unit'], $cypher, [], ['id']))->resolve($rows);

        $this->assertSame(['Carré Soho Phase 3', 'Les Terrasses', 'Bruxelles'], array_column($records, 'disambiguator'));
    }
}
