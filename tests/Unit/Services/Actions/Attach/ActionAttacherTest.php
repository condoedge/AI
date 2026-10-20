<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Attach;

use Condoedge\Ai\DTOs\Digest\DigestSourceResult;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\Attach\ActionAttacher;
use Condoedge\Ai\Services\Actions\Attach\AttachedActions;
use Condoedge\Ai\Tests\TestCase;

/**
 * Actions plan B1–B4 — "code attaches links/actions; the AI only narrates".
 *
 * Every rule is pinned on structured signals only (rows, labels, ids, QU fields,
 * counts, money columns, permissions) — never on the question's words.
 */
class ActionAttacherTest extends TestCase
{
    use AttachFixtures;

    public function setUp(): void
    {
        parent::setUp();
        $this->configureCatalog();
    }

    private function attach($ctx, ?object $user = null): AttachedActions
    {
        return (new ActionAttacher(new AiActionRegistry()))->forTurn($ctx, ['user' => $user ?? $this->manager()]);
    }

    // ---------------------------------------------------------------- B1

    /** @test B1: one chip per record, labelled by name; ids only inside the invocation. */
    public function record_chips_come_from_result_rows_with_projected_ids(): void
    {
        $chips = $this->attach($this->turn($this->unitRoster(3), self::ROSTER_CYPHER, ['intent' => 'list']));

        $this->assertSame([
            'view|Unit|1001|Lot 1',
            'view|Unit|1002|Lot 2',
            'view|Unit|1003|Lot 3',
        ], $this->chipLines($chips->invocations()));
    }

    /** @test B1: three homonym units get the union as disambiguator; a bare number gets the entity name. */
    public function homonym_records_are_disambiguated_by_their_coarser_column(): void
    {
        $chips = $this->attach($this->turn($this->homonymRows(), self::HOMONYM_CYPHER, ['intent' => 'lookup']));

        $lines = $this->chipLines($chips->invocations());
        $this->assertContains('view|Unit|159|Unit 310 — Carré Soho Phase 3', $lines);
        $this->assertContains('view|Unit|412|Unit 310 — Les Terrasses', $lines);
        $this->assertContains('view|Unit|733|Unit 310 — Bruxelles', $lines);
    }

    /** @test B1: at most 5 record chips, then "+N more" to the entity's list page (true total counted). */
    public function record_chips_are_capped_with_a_more_chip_to_the_list_page(): void
    {
        $ctx = $this->turn($this->unitRoster(12), self::ROSTER_CYPHER, ['intent' => 'list'], ['true_total' => 40]);

        $invocations = $this->attach($ctx)->invocations();
        $records = array_values(array_filter($invocations, fn ($i) => $i['entity_type'] === 'Unit'));

        $this->assertCount(5, $records);
        $this->assertSame('units', $invocations[5]['key']);
        $this->assertSame('+35 more', $invocations[5]['text'], '7 more retrieved + 28 never retrieved');
    }

    /** @test B1: never for a count/aggregate answer with a single row. */
    public function no_record_chips_for_a_single_row_count_or_aggregate(): void
    {
        foreach (['count', 'aggregate'] as $intent) {
            $ctx = $this->turn([['unit_id' => 5, 'unit_name' => '310', 'total' => 3]], self::ROSTER_CYPHER, ['intent' => $intent]);
            $records = array_filter($this->attach($ctx)->invocations(), fn ($i) => $i['entity_type'] !== null);

            $this->assertSame([], array_values($records), $intent);
        }
    }

    /** @test B1: a row's `<label>_id` column attributes the record without any cypher. */
    public function label_id_columns_attribute_records_without_cypher(): void
    {
        $chips = $this->attach($this->turn([['meeting_id' => 140, 'name' => 'AGM 2023'], ['meeting_id' => 141, 'name' => 'Board']], null, ['intent' => 'list']));

        $this->assertSame(['view|Meeting|140|AGM 2023', 'view|Meeting|141|Board'], array_slice($this->chipLines($chips->invocations()), 0, 2));
    }

    /** @test B1: rows labelled by `_label` use the configured ai.entity_id_fields (custom id names). */
    public function labelled_rows_use_the_configured_id_fields(): void
    {
        config(['ai.entity_id_fields' => ['custom_id', 'reference_id']]);

        $chips = $this->attach($this->turn([
            ['_label' => 'Meeting', 'custom_id' => 'ABC123', 'name' => 'AGM'],
            ['_label' => 'Meeting', 'reference_id' => 'R-9', 'name' => 'Board'],
        ], null, ['intent' => 'list']));

        $this->assertSame(['view|Meeting|ABC123|AGM', 'view|Meeting|R-9|Board'], $this->chipLines($chips->invocations()));
    }

    /** @test B1: the configured `primary` action wins over config order. */
    public function a_configured_primary_action_is_the_record_chip(): void
    {
        $chips = $this->attach($this->turn([['invoice_id' => 9, 'invoice_number' => 'INV-9'], ['invoice_id' => 10, 'invoice_number' => 'INV-10']], null, ['intent' => 'list']));

        $this->assertSame('pdf|Invoice|9|INV-9', $this->chipLines($chips->invocations())[0]);
    }

    /** @test Authorization: a resident gets no chip for an action only managers pass. */
    public function chips_are_only_attached_for_actions_the_user_passes(): void
    {
        $rows = [['contact_id' => 7, 'name' => 'Alice'], ['contact_id' => 8, 'name' => 'Bob']];

        $manager = $this->attach($this->turn($rows, null, ['intent' => 'list']), $this->manager());
        $resident = $this->attach($this->turn($rows, null, ['intent' => 'list']), $this->resident());

        $this->assertSame(['view|Contact|7|Alice', 'view|Contact|8|Bob'], $this->chipLines($manager->invocations()));
        $this->assertSame([], $resident->invocations());
    }

    /** @test A mutation is never a record chip (it would render an approval card per row). */
    public function mutations_are_never_record_chips(): void
    {
        $chips = $this->attach($this->turn([['reservation_id' => 3, 'name' => 'Pool'], ['reservation_id' => 4, 'name' => 'Gym']], null, ['intent' => 'list']));

        $this->assertSame([], $chips->invocations());
    }

    /** Owner-arrears (eval p9 F7, live 2026-10-01): unit rows that also carry their union's id. */
    private const ARREARS_CYPHER = "MATCH (un:Union) WHERE un.name = 'SDC Carré Soho Phase 3' AND un.team_id IN \$ai_team_ids "
        . 'MATCH (u:Unit)-[:UNION]->(un) WHERE u.outstanding_balance >= 0.01 '
        . "OPTIONAL MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.status <> '1' AND i.due_amount >= 0.01 AND i.due_at < toString(date()) "
        . 'WITH u, un, count(i) AS overdue_invoices, min(i.due_at) AS oldest_due '
        . 'OPTIONAL MATCH (c:Contact)<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u) WHERE cu.sold_at IS NULL AND cu.deleted_at IS NULL AND cu.link_id IN [1, 2] '
        . "RETURN un.name AS union_name, u.name AS unit_name, round(u.outstanding_balance, 2) AS balance, overdue_invoices, oldest_due, "
        . "collect(DISTINCT trim(coalesce(c.first_name, '') + ' ' + coalesce(c.last_name, '')))[..3] AS owners, un.id AS union_id, u.id AS unit_id ORDER BY balance DESC";

    /** @return list<array<string, mixed>> */
    private function arrearsRows(): array
    {
        return [
            ['union_name' => 'SDC Carré Soho Phase 3', 'unit_name' => '305', 'balance' => 456.12, 'overdue_invoices' => 2, 'oldest_due' => '2021-12-01T15:00:00.000000Z', 'owners' => ['Sofiane Chaouche'], 'union_id' => 8, 'unit_id' => 154],
            ['union_name' => 'SDC Carré Soho Phase 3', 'unit_name' => '311', 'balance' => 397.81, 'overdue_invoices' => 1, 'oldest_due' => '2022-01-01T05:00:00.000000Z', 'owners' => ['Micheline Bailly', 'René Gobeil'], 'union_id' => 8, 'unit_id' => 160],
            ['union_name' => 'SDC Carré Soho Phase 3', 'unit_name' => '308', 'balance' => 307.55, 'overdue_invoices' => 1, 'oldest_due' => '2022-01-01T05:00:00.000000Z', 'owners' => ['Mohammed Amine Benhadji Serradj'], 'union_id' => 8, 'unit_id' => 157],
            ['union_name' => 'SDC Carré Soho Phase 3', 'unit_name' => '605', 'balance' => 50, 'overdue_invoices' => 1, 'oldest_due' => '2020-11-17T05:00:00.000000Z', 'owners' => ['Mikael Plasse'], 'union_id' => 8, 'unit_id' => 186],
        ];
    }

    private function openableUnions(): void
    {
        config(['ai.entity_actions.Union' => [
            'view' => ['action' => fn ($id, $text = 'Open union') => "union:{$id}", 'label' => 'Open union'],
        ]]);
    }

    /**
     * @test Eval p9 F7: rows at the UNIT grain that also carry their (repeated) union's id get
     * one chip per unit — never one Union chip for the parent every row shares, even when QU
     * named Union first — and no chip is labelled with a timestamp.
     */
    public function rows_carrying_a_repeated_parent_id_get_chips_at_the_row_grain(): void
    {
        $this->openableUnions();
        $ctx = $this->turn($this->arrearsRows(), self::ARREARS_CYPHER, [
            'intent' => 'list', 'entityTypes' => ['Union', 'Contact', 'Invoice', 'InvoiceDetail'],
        ]);

        $records = array_values(array_filter($this->attach($ctx)->invocations(), fn ($i) => $i['entity_type'] !== null));

        $this->assertSame([
            'view|Unit|154|Unit 305',
            'view|Unit|160|Unit 311',
            'view|Unit|157|Unit 308',
            'view|Unit|186|Unit 605',
        ], $this->chipLines($records));
    }

    /**
     * @test Eval p9 F7 (board), coordinator decision 2026-10-01: the chip opens what the user ASKED
     * about. QU = [BoardMember, Union]; BoardMember has no projected id, so the first QU-named
     * label whose id VARIES is Union → the DISTINCT unions (5 + "+3 more" to the unions list),
     * never five copies of the first union collapsed into one arbitrary chip — and never the
     * Contact ids just because there are more of them. Same for a resident.
     */
    public function a_multi_parent_table_gets_distinct_chips_not_one_arbitrary_parent(): void
    {
        $this->openableUnions();
        config(['ai.generic_actions.unions' => ['action' => fn ($text = 'Unions') => 'unions', 'label' => 'Unions', 'entity' => 'Union', 'kind' => 'list']]);
        $cypher = 'MATCH (b:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) WHERE un.team_id IN $ai_team_ids AND un.deleted_at IS NULL '
            . 'WITH un, max(b.year) AS latest_year MATCH (cur:BoardMember)-[:BELONGS_TO_UNION]->(un) WHERE cur.year = latest_year '
            . 'MATCH (cur)-[:BELONGS_TO_CONTACT]->(c:Contact) '
            . 'RETURN un.name AS union_name, c.first_name, c.last_name, cur.bm_role, cur.year, un.id AS union_id, c.id AS contact_id ORDER BY un.name';
        $rows = [];
        foreach ([19 => 6, 8 => 2, 21 => 2, 22 => 2, 23 => 2, 24 => 2, 25 => 2, 26 => 2] as $unionId => $members) {
            for ($n = 0; $n < $members; $n++) {
                $rows[] = ['union_name' => 'Union ' . $unionId, 'c.first_name' => 'First' . $n, 'c.last_name' => 'L' . $unionId, 'cur.bm_role' => null, 'cur.year' => 2022, 'union_id' => $unionId, 'contact_id' => $unionId * 100 + $n];
            }
        }
        $qu = ['intent' => 'list', 'entityTypes' => ['BoardMember', 'Union'], 'isPossessive' => true];

        foreach ([$this->manager(), $this->resident()] as $user) {
            $lines = $this->chipLines($this->attach($this->turn($rows, $cypher, $qu, ["true_total" => 40]), $user)->invocations());

            $this->assertSame([
                'view|Union|19|Union 19',
                'view|Union|8|Union 8',
                'view|Union|21|Union 21',
                'view|Union|22|Union 22',
                'view|Union|23|Union 23',
                'unions|||+3 more',
            ], array_slice($lines, 0, 6), $user->role . ': 8 unions over 20 rows (true total 40 ROWS) → 3 more unions, never the row delta');
        }
    }

    // ------------------------------------------------ eval p16-A board (F2a / F2b / F2c)

    /** Eval p16-A board-and-meetings t0, as generated: contact ids only, name parts unaliased. */
    private const BOARD_CYPHER = 'MATCH (b:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) WHERE un.team_id IN $ai_team_ids AND un.deleted_at IS NULL '
        . 'WITH un, max(b.year) AS latest_year MATCH (cur:BoardMember)-[:BELONGS_TO_UNION]->(un) WHERE cur.year = latest_year '
        . 'MATCH (cur)-[:BELONGS_TO_CONTACT]->(c:Contact) '
        . 'RETURN un.name AS union_name, c.first_name, c.last_name, cur.bm_role, cur.year, c.id AS contact_id ORDER BY un.name';

    /**
     * 37 board members (37 distinct contacts) over 8 unions, ordered by union — the first
     * five rows are all one union's, and two different people are called Julien.
     *
     * @return list<array<string, mixed>>
     */
    private function boardRows(): array
    {
        $first = ['Amélie', 'Mauricio', 'Denis', 'Cédric', 'Hélène', 'Julien', 'Olivier', 'Sylvie', 'Bertrand', 'Francine', 'Alain', 'Nicole', 'Chloé', 'Julien'];
        $rows = [];
        $n = 0;
        foreach (['825 de Bruxelles' => 5, "SDC 2205 Chemin d'oka" => 3, 'SDC Carré Soho Phase 2' => 3, 'SDC Carré Soho Phase 3' => 4, 'SDC Les Terrasses' => 3, 'SDC O Quai du nord' => 3, 'SDC du 1155' => 2, 'SDC du 1240' => 14] as $union => $members) {
            for ($m = 0; $m < $members; $m++, $n++) {
                $rows[] = [
                    'union_name' => $union, 'c.first_name' => $first[$n % count($first)], 'c.last_name' => 'Nom' . $n,
                    'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => 700 + $n,
                ];
            }
        }

        return $rows;
    }

    /** The host's Contact: split name (metadata.name_properties) and a contacts directory. */
    private function hostContacts(): void
    {
        config([
            'entities.App\\Models\\Contact' => ['graph' => ['label' => 'Contact'], 'metadata' => ['name_properties' => ['first_name', 'last_name']]],
            'ai.generic_actions.contacts' => ['action' => fn ($text = 'Contacts') => 'contacts', 'label' => 'Contacts', 'entity' => 'Contact', 'kind' => 'list'],
        ]);
    }

    /**
     * @test Eval p16-A board (F2a + F2b): chips were "Amélie | Mauricio | Denis | Cédric |
     * Hélène" — first names only, all five from the first union. A record is named by its
     * whole declared name, and when the records exceed the cap and belong to several groups
     * (the union each row names), the cap is spread over the groups and each chip says which
     * group it is from.
     */
    public function capped_record_chips_are_spread_over_their_groups_and_fully_named(): void
    {
        $this->hostContacts();
        $ctx = $this->turn($this->boardRows(), self::BOARD_CYPHER, ['intent' => 'list', 'entityTypes' => ['BoardMember'], 'isPossessive' => true]);

        $chips = $this->attach($ctx);
        $records = array_values(array_filter($chips->invocations(), fn ($i) => $i['entity_type'] !== null));

        $this->assertSame([
            'view|Contact|700|Amélie Nom0 — 825 de Bruxelles',
            "view|Contact|705|Julien Nom5 — SDC 2205 Chemin d'oka",
            'view|Contact|708|Bertrand Nom8 — SDC Carré Soho Phase 2',
            'view|Contact|711|Nicole Nom11 — SDC Carré Soho Phase 3',
            'view|Contact|715|Mauricio Nom15 — SDC Les Terrasses',
        ], $this->chipLines($records));

        // Inline references keep the plain name: the sentence around them carries the context.
        $this->assertSame('[Mauricio Nom1](entity://Contact/701/view)', $chips->recordLink(2));
    }

    /** @test F2b: two groups share the cap in turn (A, B, A, B, A); records at or under the cap are untouched. */
    public function the_cap_alternates_between_groups_only_when_records_exceed_it(): void
    {
        $rows = [];
        foreach (['Union A' => 9, 'Union B' => 3] as $union => $units) {
            for ($u = 1; $u <= $units; $u++) {
                $rows[] = ['unit_id' => count($rows) + 1, 'unit_name' => 'Lot ' . (count($rows) + 1), 'union_name' => $union];
            }
        }

        $lines = $this->chipLines($this->attach($this->turn($rows, self::ROSTER_CYPHER, ['intent' => 'list']))->invocations());
        $this->assertSame([
            'view|Unit|1|Lot 1 — Union A',
            'view|Unit|10|Lot 10 — Union B',
            'view|Unit|2|Lot 2 — Union A',
            'view|Unit|11|Lot 11 — Union B',
            'view|Unit|3|Lot 3 — Union A',
            'units|||+7 more',
        ], $lines);

        // A record with no name of its own is already placed by the row's other record
        // ("Invoice PDF — Unit 301"): spread over the units, but never "— Unit 301 — 301".
        $invoices = [];
        foreach (['301' => 4, '302' => 4] as $unit => $count) {
            for ($i = 0; $i < $count; $i++) {
                $invoices[] = ['invoice_id' => 700 + count($invoices), 'due' => 10.5, 'unit_name' => (string) $unit, 'unit_id' => (int) $unit];
            }
        }
        $cypher = 'MATCH (i:Invoice)-[:CUSTOMER]->(u:Unit) RETURN i.id AS invoice_id, i.due_amount AS due, u.name AS unit_name, u.id AS unit_id';
        $lines = $this->chipLines($this->attach($this->turn($invoices, $cypher, ['intent' => 'list', 'entityTypes' => ['Invoice']]))->invocations());
        $this->assertSame([
            'pdf|Invoice|700|Invoice PDF — Unit 301',
            'pdf|Invoice|704|Invoice PDF — Unit 302',
            'pdf|Invoice|701|Invoice PDF — Unit 301',
            'pdf|Invoice|705|Invoice PDF — Unit 302',
            'pdf|Invoice|702|Invoice PDF — Unit 301',
        ], array_slice($lines, 0, 5));

        $few = [$rows[0], $rows[1], $rows[9], $rows[10]];
        $lines = $this->chipLines($this->attach($this->turn($few, self::ROSTER_CYPHER, ['intent' => 'list']))->invocations());
        $this->assertSame(['view|Unit|1|Lot 1', 'view|Unit|2|Lot 2', 'view|Unit|10|Lot 10', 'view|Unit|11|Lot 11'], $lines, 'every record has its chip: row order, no group suffix');
    }

    /**
     * @test Eval p16-A board (F2c): "+32 more" opened the generic Contacts directory — the list
     * of every contact, not of the board members the answer is about. The list page of a
     * result is its SUBJECT's (QU entity types): BoardMember has none, so no "+N more" chip
     * and no list chip are attached (the table's "Showing 25 of 37" line and the Excel chip
     * cover the rest) — the contacts the rows merely link to never lend their directory.
     */
    public function the_more_chip_never_opens_the_list_of_an_entity_the_question_is_not_about(): void
    {
        $this->hostContacts();
        $ctx = $this->turn($this->boardRows(), self::BOARD_CYPHER, ['intent' => 'list', 'entityTypes' => ['BoardMember'], 'isPossessive' => true]);

        $invocations = $this->attach($ctx)->invocations();
        $generic = array_values(array_filter($invocations, fn ($i) => $i['entity_type'] === null));

        $this->assertSame(['export_excel|||Download Excel'], $this->chipLines($generic));
    }

    /**
     * @test F2c: when the records ARE what the question is about, their list page is the
     * "+N more" door — whatever position QU gave their type — and a result QU named no type
     * for keeps the records' own list page.
     */
    public function the_more_chip_opens_the_list_of_the_records_the_question_is_about(): void
    {
        $this->hostContacts();
        $lines = fn (array $types) => $this->chipLines($this->attach(
            $this->turn($this->boardRows(), self::BOARD_CYPHER, ['intent' => 'list', 'entityTypes' => $types])
        )->invocations());

        $this->assertContains('contacts|||+32 more', $lines(['Contact']));
        $this->assertContains('contacts|||+32 more', $lines(['Union', 'Contact']), 'Union has no list page; the contacts shown are asked about');
        $this->assertContains('contacts|||+32 more', $lines([]), 'QU named nothing: the records decide, as before');
        $this->assertContains('contacts|||+32 more', $lines(['Meeting']), 'QU named nothing this result holds: the records decide');
    }

    /**
     * @test F2c, QU variance: had QU named [BoardMember, Contact], the contacts would count as
     * asked about. The entity configs settle it structurally: a BoardMember BELONGS to its
     * Contact (it holds the foreign key) and QU named it first, so each row is one board
     * member and the contact is only the parent it is named by — the list page is
     * BoardMember's (none here), never the contacts directory. A BoardMember list page, when
     * the host has one, is the door.
     */
    public function rows_of_a_child_entity_never_open_the_list_of_the_parent_they_are_named_by(): void
    {
        $this->hostContacts();
        config(['entities.App\\Models\\BoardMember' => ['graph' => [
            'label' => 'BoardMember',
            'properties' => ['id', 'union_id', 'contact_id', 'year', 'bm_role'],
            'relationships' => [
                ['type' => 'BELONGS_TO_CONTACT', 'target_label' => 'Contact', 'foreign_key' => 'contact_id', 'direction' => 'outgoing'],
            ],
        ]]]);
        $generic = fn (array $types) => $this->chipLines(array_values(array_filter(
            $this->attach($this->turn($this->boardRows(), self::BOARD_CYPHER, ['intent' => 'list', 'entityTypes' => $types]))->invocations(),
            fn ($i) => $i['entity_type'] === null
        )));

        $this->assertSame(['export_excel|||Download Excel'], $generic(['BoardMember', 'Contact']));
        $this->assertSame(
            ['contacts|||+32 more', 'export_excel|||Download Excel'],
            $generic(['Contact', 'BoardMember']),
            'QU named the contacts FIRST: they are the primary subject, the records are what is asked about'
        );

        config(['ai.generic_actions.board' => ['action' => fn ($text = 'Board') => 'board', 'label' => 'Board', 'entity' => 'BoardMember', 'kind' => 'list']]);
        $this->assertSame(['board|||+32 more', 'export_excel|||Download Excel'], $generic(['BoardMember', 'Contact']));

        // The other way round — the PARENT named beside its children — changes nothing: a
        // relationship whose key is not the entity's own column (a hasMany / morphMany
        // declared with the other table's key), a pivot or an inverse makes no child of it.
        config(['entities.App\\Models\\Union' => ['graph' => [
            'label' => 'Union',
            'properties' => ['id', 'name'],
            'relationships' => [
                ['type' => 'HAS_UNITS', 'target_label' => 'Unit', 'foreign_key' => 'union_id', 'direction' => 'outgoing'],
                ['type' => 'UNITS_INVERSE', 'target_label' => 'Unit', 'foreign_key' => 'id', 'inverse' => true],
                ['type' => 'UNITS_PIVOT', 'target_label' => 'Unit', 'foreign_key' => 'id', 'pivot' => 'union_unit'],
                ['type' => 'UNITS_MORPH', 'target_label' => 'Unit', 'foreign_key' => 'id', 'morph' => 'owner'],
            ],
        ]]]);
        $units = $this->chipLines($this->attach(
            $this->turn($this->unitRoster(12), self::ROSTER_CYPHER, ['intent' => 'list', 'entityTypes' => ['Union', 'Unit']])
        )->invocations());
        $this->assertContains('units|||+7 more', $units);
    }

    /**
     * @test F2c, records the question is NOT about (and no subject is their child): "+N more"
     * would not open more of THESE records, so there is none — but the truncated list still
     * gets a door, under its own name: the list page of the first asked-about entity the
     * query matches. Units listed for a question about meetings open Meetings, never Units.
     */
    public function a_truncated_result_opens_the_list_of_its_subject_not_of_its_linked_records(): void
    {
        $cypher = 'MATCH (m:Meeting)-[:OF]->(un:Union)<-[:UNION]-(u:Unit) RETURN u.id AS unit_id, u.name AS unit_name, m.name AS meeting';
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['unit_id' => $i, 'unit_name' => 'Lot ' . $i, 'meeting' => 'AGM'];
        }

        $generic = $this->chipLines(array_values(array_filter(
            $this->attach($this->turn($rows, $cypher, ['intent' => 'list', 'entityTypes' => ['Union', 'Meeting']]))->invocations(),
            fn ($i) => $i['entity_type'] === null
        )));

        $this->assertSame(['meetings|||Meetings', 'export_excel|||Download Excel'], $generic);
    }

    // ------------------------------------------------ eval p16-A roster (F1)

    /** Eval p16-A team-roster t0: the roster starts at User; no id is projected. */
    private const USER_ROSTER_CYPHER = 'MATCH (u:User) WHERE u.current_team_id = 3 OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = 3 '
        . 'RETURN u.name AS name, u.email AS email, tr.role AS role LIMIT 100';

    /** @return list<array<string, mixed>> the executor-capped roster (100 of 523) */
    private function userRoster(): array
    {
        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = ['name' => 'Member ' . $i, 'email' => "m{$i}@example.com", 'role' => 'contact'];
        }

        return $rows;
    }

    /** The host places its team page as the list page of the team's member entities. */
    private function teamPageListsMembers(): void
    {
        config(['ai.generic_actions.team_settings' => config('ai.generic_actions.team_settings') + ['entity' => ['User', 'TeamRole'], 'kind' => 'list']]);
    }

    /**
     * @test Eval p16-A roster (F1): QU named [TeamRole, User] — not Team — so the identity team
     * chip did not attach and "25 of 523" had no door at all. The team page is the list page
     * of the member entities: the truncated roster carries it whichever entity QU named, and
     * a turn that also earns the identity team chip carries ONE chip.
     */
    public function a_truncated_roster_opens_the_team_page_whatever_entity_was_understood(): void
    {
        $this->teamPageListsMembers();
        $chips = fn (array $types) => $this->chipLines($this->attach(
            $this->turn($this->userRoster(), self::USER_ROSTER_CYPHER, ['intent' => 'list', 'entityTypes' => $types, 'isPossessive' => true], ['true_total' => 523])
        )->invocations());

        $this->assertSame(['team_settings|||Team settings'], $chips(['TeamRole', 'User']), 'eval p16-A');
        $this->assertSame(['team_settings|||Team settings'], $chips(['Team', 'TeamRole']), 'eval p14: identity chip + list chip = one chip');
        $this->assertSame(['team_settings|||Team settings'], $chips([]), 'QU named nothing: the query\'s own labels');

        // Permissions are unchanged: a resident denied the host team action gets no door.
        $resident = $this->attach(
            $this->turn($this->userRoster(), self::USER_ROSTER_CYPHER, ['intent' => 'list', 'entityTypes' => ['TeamRole', 'User']], ['true_total' => 523]),
            $this->resident()
        );
        $this->assertSame([], $resident->invocations());
    }

    /**
     * @test p11 G5 for next steps: a host page attached as a next step is labelled in the
     * QUESTION's language like the identity chip is — the French roster never gets the
     * English "Team settings" just because QU did not name Team.
     */
    public function next_step_chip_labels_are_translated_in_the_question_language(): void
    {
        $this->teamPageListsMembers();
        app('translator')->addLines(['*.Team settings' => 'Réglages de l\'équipe'], 'fr');
        app()->setLocale('en');
        $qu = ['intent' => 'list', 'entityTypes' => ['TeamRole', 'User'], 'isPossessive' => true];

        $fr = $this->attach($this->turn($this->userRoster(), self::USER_ROSTER_CYPHER, $qu + ['language' => 'fr'], ['true_total' => 523]));
        $this->assertSame(['team_settings|||Réglages de l\'équipe'], $this->chipLines($fr->invocations()));

        app()->setLocale('fr');
        $en = $this->attach($this->turn($this->userRoster(), self::USER_ROSTER_CYPHER, $qu + ['language' => 'en'], ['true_total' => 523]));
        $this->assertSame(['team_settings|||Team settings'], $this->chipLines($en->invocations()));
    }

    /** @test {{record:N}} still links every row after the chips were collapsed and capped. */
    public function record_refs_survive_dedupe_past_the_cap(): void
    {
        $chips = $this->attach($this->turn($this->unitRoster(8), self::ROSTER_CYPHER, ['intent' => 'list']));

        $this->assertSame('[Lot 8](entity://Unit/1008/view)', $chips->recordLink(8));
    }

    /**
     * @test I2: a chosen record without a name of its own borrows the other record on the row
     * as context — never N identical "Invoice PDF" chips.
     */
    public function a_nameless_record_borrows_the_other_records_name_as_context(): void
    {
        $cypher = 'MATCH (i:Invoice)-[:CUSTOMER]->(u:Unit) RETURN i.id AS invoice_id, i.due_amount AS due, u.name AS unit_name, u.id AS unit_id';
        $rows = [
            ['invoice_id' => 700, 'due' => 10.5, 'unit_name' => '301', 'unit_id' => 12],
            ['invoice_id' => 701, 'due' => 20.0, 'unit_name' => '302', 'unit_id' => 13],
        ];

        $lines = $this->chipLines($this->attach($this->turn($rows, $cypher, ['intent' => 'list', 'entityTypes' => ['Invoice']]))->invocations());

        $this->assertSame(['pdf|Invoice|700|Invoice PDF — Unit 301', 'pdf|Invoice|701|Invoice PDF — Unit 302'], array_slice($lines, 0, 2));
    }

    /**
     * @test I5: a digest source DECLARES its rows' entity — a Unit row that also carries an
     * invoice_id stays the unit (QU naming Invoice never re-attributes it), with its own name.
     */
    public function a_digest_rows_declared_entity_wins(): void
    {
        $ctx = $this->turn([], null, ['digestRequest' => true, 'entityTypes' => ['Invoice']]);
        $sources = [
            new DigestSourceResult(key: 'units', label: 'Units', rows: [['id' => 12, 'unit_name' => '301', 'invoice_id' => 700]], rowCount: 1, entity: 'Unit'),
        ];

        $chips = (new ActionAttacher(new AiActionRegistry()))->forDigest($ctx, $sources, ['user' => $this->manager()]);

        $this->assertSame(['view|Unit|12|Unit 301'], $this->chipLines($chips->invocations()));
    }

    /** @test A follow-up that ran no query attaches the previous turn's records (no inline refs). */
    public function a_follow_up_without_rows_uses_the_previous_result(): void
    {
        $ctx = $this->turn([], null, ['intent' => 'lookup', 'followUpReference' => true]);
        $ctx->conversationContext = [
            'last_result_sample' => [['unit_id' => 159, 'unit_name' => '310', 'union_name' => 'Carré Soho Phase 3']],
            'last_cypher_query' => self::HOMONYM_CYPHER,
        ];

        $chips = $this->attach($ctx);

        $this->assertSame(['view|Unit|159|Unit 310'], $this->chipLines($chips->invocations()));
        $this->assertFalse($chips->hasRecordRefs());
    }

    // ---------------------------------------------------------------- {{record:N}}

    /** @test {{record:N}} becomes the row's record link; an unknown N is stripped. */
    public function record_placeholders_are_substituted_or_stripped(): void
    {
        $chips = $this->attach($this->turn($this->homonymRows(), self::HOMONYM_CYPHER, ['intent' => 'lookup']));

        $answer = $chips->substitute('See {{record:1}} and {{ record : 3 }}, not {{record:9}}.');

        $this->assertSame(
            'See [Unit 310 — Carré Soho Phase 3](entity://Unit/159/view) and [Unit 310 — Bruxelles](entity://Unit/733/view), not.',
            $answer
        );
        $this->assertSame('Nothing here.', AttachedActions::strip('Nothing {{record:2}} here.'));
    }

    /** @test {{record:N}} for a record the user may not open is stripped (no link minted). */
    public function record_placeholders_of_unauthorized_records_are_stripped(): void
    {
        $rows = [['contact_id' => 7, 'name' => 'Alice'], ['contact_id' => 8, 'name' => 'Bob']];
        $chips = $this->attach($this->turn($rows, null, ['intent' => 'list']), $this->resident());

        $this->assertSame('Ask about.', $chips->substitute('Ask about {{record:1}}.'));
    }

    // ---------------------------------------------------------------- B2

    /** @test B2: a possessive question about the user's own (User) record attaches my_profile. */
    public function a_possessive_user_question_attaches_the_profile_link(): void
    {
        $chips = $this->attach($this->turn([], null, ['isPossessive' => true, 'entityTypes' => ['User']]), $this->resident());

        $this->assertSame(['my_profile||'], array_map(fn ($l) => substr($l, 0, strrpos($l, '|')), $this->chipLines($chips->invocations())));
    }

    /**
     * @test B2: a possessive TEAM question ("my team's members") attaches the host team action (no
     * my_team duplicate) — and NOT my_profile, even though the user's own row is in the roster
     * (eval p11/p12: graded as noise). The possessive is about the team (QU's first type).
     */
    public function a_possessive_team_roster_attaches_team_settings_but_not_my_profile(): void
    {
        $rows = [
            ['name' => 'Lea Lead', 'email' => 'lead@example.com', 'role' => 'lead'],
            ['name' => 'Sam Staff', 'email' => 'sam@example.com', 'role' => 'manager'],
        ];
        $chips = $this->attach($this->turn($rows, null, ['intent' => 'list', 'entityTypes' => ['Team', 'User'], 'isPossessive' => true]));

        $keys = array_column($chips->invocations(), 'key');
        $this->assertNotContains('my_profile', $keys);
        $this->assertContains('team_settings', $keys, 'the host alias is reused — no my_team duplicate');
        $this->assertNotContains('my_team', $keys);

        // Possessive with the user as the PRIMARY subject ("my details") still attaches it.
        $self = $this->attach($this->turn([['name' => 'Lea Lead', 'email' => 'lead@example.com']], null, ['intent' => 'lookup', 'entityTypes' => ['User', 'Team'], 'isPossessive' => true]));
        $this->assertContains('my_profile', array_column($self->invocations(), 'key'));
    }

    /**
     * @test Fix 7: a manager listing residents who happens to appear in the list is NOT asking
     * about themself — the own row attaches my_profile only when it is the ONLY row.
     */
    public function an_own_row_inside_a_plain_list_attaches_no_profile_link(): void
    {
        $list = [
            ['name' => 'Ann Owner', 'email' => 'ann@example.com'],
            ['name' => 'Lea Lead', 'email' => 'lead@example.com'],
        ];
        $this->assertNotContains('my_profile', array_column($this->attach($this->turn($list, null, ['intent' => 'list', 'entityTypes' => ['Contact']]))->invocations(), 'key'));

        $only = [['name' => 'Lea Lead', 'email' => 'lead@example.com']];
        $this->assertContains('my_profile', array_column($this->attach($this->turn($only, null, ['intent' => 'lookup', 'entityTypes' => ['Contact']]))->invocations(), 'key'));
    }

    /**
     * @test Eval p20-B digest t1: ONE meeting record whose detail field `president` (added by
     * the single-record enrichment) holds the user's name is a MEETING — not the user's own
     * record. The profile link is for a row whose own entity is the user, never for a record
     * that names the user in one of its fields.
     */
    public function a_record_naming_the_user_in_a_detail_field_attaches_no_profile_link(): void
    {
        $cypher = 'MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids RETURN m.name AS name, m.start_date AS start_date, m.id AS meeting_id ORDER BY m.start_date DESC LIMIT 1';
        $row = ['name' => 'Annual assembly', 'start_date' => '2023-10-24 16:00:00', 'meeting_id' => 140, 'place' => 'ZOOM', 'president' => 'Lea Lead', 'union_name' => 'Union A'];
        $enriched = ['record_enriched' => ['entity' => 'Meeting', 'columns' => ['union_name' => 'Union'], 'details' => ['place', 'president']]];

        $chips = $this->attach($this->turn([$row], $cypher, ['intent' => 'lookup', 'entityTypes' => ['Meeting']], $enriched));

        $this->assertSame(['view|Meeting|140|Annual assembly'], $this->chipLines($chips->invocations()));

        // The same field projected by the query itself: still the meeting's field.
        $projected = 'MATCH (m:Meeting) RETURN m.name AS name, m.president AS president, m.id AS meeting_id LIMIT 1';
        $chips = $this->attach($this->turn([['name' => 'Annual assembly', 'president' => 'Lea Lead', 'meeting_id' => 140]], $projected, ['intent' => 'lookup', 'entityTypes' => ['Meeting']]));
        $this->assertNotContains('my_profile', array_column($chips->invocations(), 'key'));

        // A follow-up about that record ("open it"): the recorded row carries the enriched
        // columns without their stamp — a column no node of the query owns decides nothing.
        $ctx = $this->turn([], null, ['intent' => 'lookup', 'followUpReference' => true]);
        $ctx->conversationContext = ['last_result_sample' => [$row], 'last_cypher_query' => $cypher];
        $this->assertNotContains('my_profile', array_column($this->attach($ctx)->invocations(), 'key'));
    }

    /**
     * @test Eval p20-A team-info: the team's own row carries the team's contact e-mail, which
     * is the user's — a field of the TEAM. A parent named beside a record is no different.
     */
    public function a_team_or_parent_column_holding_the_users_value_attaches_no_profile_link(): void
    {
        $team = 'MATCH (t:Team) WHERE t.id = 3 OPTIONAL MATCH (u:User) WHERE u.current_team_id = t.id RETURN t.name AS team_name, count(DISTINCT u) AS members, t.contact_email AS contact_email LIMIT 100';
        $chips = $this->attach($this->turn(
            [['team_name' => 'Novo', 'members' => 523, 'contact_email' => 'lead@example.com']],
            $team,
            ['intent' => 'lookup', 'entityTypes' => ['Team'], 'isPossessive' => true, 'navigationTarget' => 'own_team']
        ));
        $this->assertSame(['team_settings'], array_column($chips->invocations(), 'key'));

        $parent = 'MATCH (i:Invoice)-[:CUSTOMER]->(c:Customer) RETURN i.number AS number, c.name AS customer_name LIMIT 1';
        $chips = $this->attach($this->turn([['number' => 'F-12', 'customer_name' => 'Lea Lead']], $parent, ['intent' => 'lookup', 'entityTypes' => ['Invoice']]));
        $this->assertNotContains('my_profile', array_column($chips->invocations(), 'key'));
    }

    /**
     * @test Eval p17-p20 A team-info ("Can I get information about my team?", action 0.70):
     * the answer that IS the user's own team record carries the doors of that team — the
     * pages the host lists under `identity.team.related` — beside the team link. Only for
     * the team's own single record, bound to the user's current team; each door only for
     * who may open it; never more than the next-step cap.
     */
    public function the_users_own_team_record_carries_the_teams_related_doors(): void
    {
        config(['ai.attached_actions.identity.team.related' => ['units', 'meetings', 'report_receivables', 'bills_table', 'missing_action']]);
        $cypher = 'MATCH (t:Team) WHERE t.id = 3 OPTIONAL MATCH (u:User) WHERE u.current_team_id = t.id RETURN t.name AS team_name, count(DISTINCT u) AS members, t.contact_phone AS contact_phone LIMIT 100';
        $row = [['team_name' => 'Novo', 'members' => 523, 'contact_phone' => '514-000-0000']];
        $qu = ['intent' => 'lookup', 'entityTypes' => ['Team'], 'isPossessive' => true, 'navigationTarget' => 'own_team'];

        $chips = $this->attach($this->turn($row, $cypher, $qu));
        $this->assertSame(
            ['identity:team_settings', 'next_step:units', 'next_step:meetings', 'next_step:report_receivables'],
            array_map(fn ($c) => $c['role'] . ':' . $c['key'], $chips->applyTo([])['attached_actions']),
            'the team link, then at most max_next_steps doors in the declared order'
        );

        // A resident is denied the team page: no team link, and no door of the team either.
        $this->assertSame([], $this->attach($this->turn($row, $cypher, $qu), $this->resident())->invocations());

        // Zero config: nothing changes.
        config(['ai.attached_actions.identity.team.related' => null]);
        $this->assertSame(['team_settings'], array_column($this->attach($this->turn($row, $cypher, $qu))->invocations(), 'key'));
    }

    /**
     * @test The doors are for the team's OWN record only: not for a roster (many rows), not
     * for a count, not for another team's record, not for a row that holds no column of the
     * team, not for the recorded rows a follow-up refers to.
     */
    public function the_teams_related_doors_are_not_attached_to_anything_else(): void
    {
        config(['ai.attached_actions.identity.team.related' => ['units', 'meetings']]);
        $own = 'MATCH (t:Team) WHERE t.id = 3 RETURN t.name AS team_name, t.contact_phone AS contact_phone';
        $qu = ['intent' => 'lookup', 'entityTypes' => ['Team'], 'isPossessive' => true];
        $doors = fn ($ctx): array => array_values(array_intersect(array_column($this->attach($ctx)->invocations(), 'key'), ['units', 'meetings']));

        $this->assertSame(['units', 'meetings'], $doors($this->turn([['team_name' => 'Novo', 'contact_phone' => '1']], $own, $qu)), 'the control');

        $roster = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id RETURN t.name AS team_name, u.name AS name';
        $this->assertSame([], $doors($this->turn([['team_name' => 'Novo', 'name' => 'A'], ['team_name' => 'Novo', 'name' => 'B']], $roster, ['intent' => 'list'] + $qu)), 'a roster');
        $this->assertSame([], $doors($this->turn([['team_name' => 'Novo', 'members' => 523]], 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id RETURN t.name AS team_name, count(u) AS members', ['intent' => 'count'] + $qu)), 'a count');
        $this->assertSame([], $doors($this->turn([['team_name' => 'Other', 'contact_phone' => '1']], 'MATCH (t:Team) WHERE t.id = 9 RETURN t.name AS team_name, t.contact_phone AS contact_phone', $qu)), 'another team');
        $this->assertSame([], $doors($this->turn([['team_name' => 'Novo', 'contact_phone' => '1']], 'MATCH (t:Team) WHERE t.team_id IN $ai_team_ids RETURN t.name AS team_name, t.contact_phone AS contact_phone', $qu)), 'not bound to the current team');
        $this->assertSame([], $doors($this->turn([['members' => 523]], 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id RETURN count(u) AS members', $qu)), 'no column of the team');

        $followUp = $this->turn([], null, ['followUpReference' => true] + $qu);
        $followUp->conversationContext = ['last_result_sample' => [['team_name' => 'Novo', 'contact_phone' => '1']], 'last_cypher_query' => $own];
        $this->assertSame([], $doors($followUp), 'recorded rows of an earlier turn');
    }

    /**
     * @test The row that IS the user keeps the link: a row of the user label, or of a record
     * the host's ownership paths bind to the user (`ai.user_entity_paths`: the user's contact).
     */
    public function the_users_own_user_or_linked_identity_row_attaches_the_profile_link(): void
    {
        $user = 'MATCH (u:User) WHERE u.id = 13 RETURN u.name AS name, u.email AS email';
        $chips = $this->attach($this->turn([['name' => 'Lea Lead', 'email' => 'lead@example.com']], $user, ['intent' => 'lookup', 'entityTypes' => ['Team']]));
        $this->assertContains('my_profile', array_column($chips->invocations(), 'key'));

        $contact = 'MATCH (c:Contact {user_id: 13}) RETURN c.first_name AS first_name, c.email AS email';
        $row = [['first_name' => 'Lea', 'email' => 'lead@example.com']];
        $this->assertNotContains('my_profile', array_column($this->attach($this->turn($row, $contact, ['intent' => 'lookup', 'entityTypes' => ['Contact']]))->invocations(), 'key'), 'a contact the host does not link to the user');

        config(['ai.user_entity_paths' => ['Contact' => ['cypher' => 'MATCH (n:Contact {user_id: $userId})']]]);
        $this->assertContains('my_profile', array_column($this->attach($this->turn($row, $contact, ['intent' => 'lookup', 'entityTypes' => ['Contact']]))->invocations(), 'key'));
    }

    /**
     * @test B2 (owner's example): QU's navigation_target = own_profile attaches My profile even
     * when no entity is named; possessive + no entity alone ("my stuff") attaches nothing.
     */
    public function the_own_profile_navigation_target_attaches_the_profile_link(): void
    {
        $vague = $this->attach($this->turn([], null, ['isPossessive' => true, 'entityTypes' => [], 'intent' => 'lookup']), $this->resident());
        $this->assertSame([], $vague->invocations());

        $profile = $this->attach($this->turn([], null, ['intent' => 'lookup', 'navigationTarget' => 'own_profile']), $this->resident());
        $this->assertSame(['my_profile'], array_column($profile->invocations(), 'key'));
    }

    /** @test B2: own_team attaches the host's team action when allowed — never a fallback for a denied resident. */
    public function the_own_team_navigation_target_attaches_the_allowed_team_action_only(): void
    {
        $manager = $this->attach($this->turn([], null, ['navigationTarget' => 'own_team']), $this->manager());
        $this->assertSame(['team_settings'], array_column($manager->invocations(), 'key'));

        $resident = $this->attach($this->turn([], null, ['navigationTarget' => 'own_team']), $this->resident());
        $this->assertSame([], $resident->invocations());
    }

    /**
     * @test Fix 4: when the host names its own team action, a user DENIED that action gets no
     * team link at all — never the built-in my_team (teams.show) as a fallback.
     */
    public function a_denied_configured_team_action_never_falls_back_to_the_built_in(): void
    {
        \Illuminate\Support\Facades\Route::get('/teams/{team}', fn () => 'team')->name('teams.show');
        app('router')->getRoutes()->refreshNameLookups();

        $resident = $this->attach($this->turn([], null, ['entityTypes' => ['Team']]), $this->resident());
        $this->assertSame([], $resident->invocations());

        config(['ai.attached_actions.identity.team.action' => null]);
        $builtIn = $this->attach($this->turn([], null, ['entityTypes' => ['Team']]), $this->resident());
        $this->assertSame(['my_team'], array_column($builtIn->invocations(), 'key'), 'without a host action the built-in applies');
    }

    /** @test Fix 7: no team link when the user's current team is soft-deleted / gone. */
    public function no_team_link_when_the_current_team_is_gone(): void
    {
        $user = new class {
            public int $id = 13;
            public string $role = 'manager';
            public int $current_team_id = 99;
            public string $email = 'lead@example.com';
            public string $name = 'Lea Lead';

            public function currentTeam(): object
            {
                return new class { public function exists(): bool { return false; } };
            }
        };

        $chips = $this->attach($this->turn([], null, ['entityTypes' => ['Team']]), $user);

        $this->assertSame([], $chips->invocations());
    }

    /**
     * @test Fix 1: the per-label primary-action decision is per USER — the attacher lives in a
     * singleton (queue workers, Octane, the eval command), so a resident's denial must never
     * leak to a manager, nor a manager's grant to a resident.
     */
    public function the_primary_action_decision_is_never_reused_across_users(): void
    {
        $rows = [['contact_id' => 7, 'name' => 'Alice'], ['contact_id' => 8, 'name' => 'Bob']];
        $attacher = new ActionAttacher(new AiActionRegistry());
        $run = fn (object $user) => array_column($attacher->forTurn($this->turn($rows, null, ['intent' => 'list']), ['user' => $user])->invocations(), 'entity_id');

        $this->assertSame([], $run($this->resident()));
        $this->assertSame(['7', '8'], $run($this->manager()), 'resident first, then manager');
        $this->assertSame([], $run($this->resident()), 'manager first, then resident');
    }

    /** @test B2: the package's own chip labels follow the QUESTION language, not the app locale. */
    public function identity_chip_labels_follow_the_question_language(): void
    {
        app()->setLocale('fr');
        $en = $this->attach($this->turn([], null, ['isPossessive' => true, 'entityTypes' => ['User'], 'language' => 'en']));
        $this->assertSame('My profile', $en->invocations()[0]['text']);

        app()->setLocale('en');
        $fr = $this->attach($this->turn([], null, ['isPossessive' => true, 'entityTypes' => ['User'], 'language' => 'fr']));
        $this->assertSame('Mon profil', $fr->invocations()[0]['text']);
    }

    /**
     * @test p11 G5: a HOST-configured identity action's label is resolved through the
     * translator in the QUESTION's language (a literal JSON key like "Team settings" or a
     * group key) — a French question never gets the English "Team settings" chip when the
     * host ships the translation; without one, the literal label stands.
     */
    public function host_identity_chip_labels_are_translated_in_the_question_language(): void
    {
        app('translator')->addLines(['*.Team settings' => 'Réglages de l\'équipe'], 'fr');
        app()->setLocale('en');

        $fr = $this->attach($this->turn([], null, ['navigationTarget' => 'own_team', 'language' => 'fr']));
        $this->assertSame(['team_settings'], array_column($fr->invocations(), 'key'));
        $this->assertSame('Réglages de l\'équipe', $fr->invocations()[0]['text']);

        app()->setLocale('fr');
        $en = $this->attach($this->turn([], null, ['navigationTarget' => 'own_team', 'language' => 'en']));
        $this->assertSame('Team settings', $en->invocations()[0]['text'], 'English question → English label, whatever the app locale');

        // A group translation key as the host label resolves the same way.
        config(['ai.generic_actions.team_settings.label' => 'menus.team-settings']);
        app('translator')->addLines(['menus.team-settings' => 'Réglages de l\'équipe (menu)'], 'fr', '*');
        app('translator')->addLines(['menus.team-settings' => 'Team settings (menu)'], 'en', '*');
        $keyed = $this->attach($this->turn([], null, ['navigationTarget' => 'own_team', 'language' => 'fr']));
        $this->assertSame('Réglages de l\'équipe (menu)', $keyed->invocations()[0]['text']);

        // No translation in the question's language → the literal host label, never a raw key.
        config(['ai.generic_actions.team_settings.label' => 'Team settings']);
        $de = $this->attach($this->turn([], null, ['navigationTarget' => 'own_team', 'language' => 'de']));
        $this->assertSame('Team settings', $de->invocations()[0]['text']);
    }

    // ------------------------------------------------- B2 on a follow-up (eval p16-B E8)

    /** Eval p16-B long-conversation-resilience t0: "I wanna know the members of my team with their roles." */
    private const TEAM_ROSTER_CYPHER = 'MATCH (t:Team) WHERE t.id = 3 MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) '
        . 'MATCH (u:User) WHERE tr.user_id = u.id RETURN u.name AS member_name, tr.role AS role LIMIT 100';

    /** …and t1: "Ok, but could you add their last login to it?" — the recorded query, extended. */
    private const TEAM_ROSTER_LOGIN_CYPHER = 'MATCH (t:Team) WHERE t.id = 3 MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) '
        . 'MATCH (u:User) WHERE tr.user_id = u.id RETURN u.name AS member_name, tr.role AS role, u.last_logged_at AS last_login LIMIT 100';

    /** @return list<array<string, mixed>> the executor-capped roster (100 of 523), the asking lead inside it */
    private function teamRoster(bool $withLogin): array
    {
        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $row = ['member_name' => $i === 7 ? 'Lea Lead' : 'Member ' . $i, 'role' => $i === 7 ? 'lead' : 'contact'];
            $rows[] = $withLogin ? $row + ['last_login' => $i % 9 === 0 ? '2023-11-21 08:09:13' : null] : $row;
        }

        return $rows;
    }

    /**
     * What ConversationContextManager::buildPromptContext() hands the turn AFTER the roster
     * turn recorded its result (snapshot keys as written by updateContext()).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function rosterConversation(array $overrides = []): array
    {
        return $overrides + [
            'focused_entity' => 'Team',
            'focused_entity_filter' => 't.id = 3',
            'last_result_sample' => array_slice($this->teamRoster(false), 0, 10),
            'last_result_count' => 100,
            'last_cypher_query' => self::TEAM_ROSTER_CYPHER,
            'last_generated_cypher' => self::TEAM_ROSTER_CYPHER,
            'last_anchor_label' => 'User',
            'last_cypher_parameters' => [],
            'last_entity_types' => ['Team', 'TeamRole', 'User'],
            'last_subject' => 'Team Members and Roles',
            'last_query_type' => 'list',
            'last_relationships' => ['BELONGS_TO_TEAM'],
            'mentioned_entities' => ['Team', 'TeamRole', 'User'],
            'last_answer_summary' => 'Here are the members of your team, Novo Gestion, along with their roles:',
            'last_referenced_files' => [],
            'recent_exchanges' => [],
        ];
    }

    /** QU as traced on p16-B resilience t1: a follow-up that names NO entity types. */
    private const FOLLOW_UP_QU = ['intent' => 'other', 'entityTypes' => [], 'isPossessive' => true, 'followUpReference' => true];

    /**
     * @test Eval p16-B E8 (resilience t0 → t1): the roster turn carries the Team settings chip
     * (QU entity types [Team, TeamRole]); the follow-up "add their last login to it" names NO
     * entity types — its subject lives in the conversation — and lost the chip. The recorded
     * result's structure (the labels of the recorded query) stands in, so the follow-up
     * carries exactly the chips of the roster it extends — and never My profile: the
     * possessive is still about the team, whatever label the recorded rows are anchored on.
     */
    public function a_follow_up_naming_no_entity_keeps_the_recorded_results_team_link(): void
    {
        $meta = ['true_total' => 523];
        $t0 = $this->turn($this->teamRoster(false), self::TEAM_ROSTER_CYPHER, [
            'intent' => 'list', 'entityTypes' => ['Team', 'TeamRole'], 'isPossessive' => true,
        ], $meta);

        $t1 = $this->turn($this->teamRoster(true), self::TEAM_ROSTER_LOGIN_CYPHER, self::FOLLOW_UP_QU, $meta);
        $t1->conversationContext = $this->rosterConversation();

        $this->assertSame(['team_settings|||Team settings'], $this->chipLines($this->attach($t0)->invocations()));
        $this->assertSame(
            $this->chipLines($this->attach($t0)->invocations()),
            $this->chipLines($this->attach($t1)->invocations()),
            'the follow-up extends the roster: same chips as the turn it follows'
        );

        // Permissions are unchanged: a resident denied the host team action gets nothing.
        $this->assertSame([], $this->attach($t1, $this->resident())->invocations());
    }

    /**
     * @test E8 scope: the conversation stands in ONLY for a QU follow-up that names no entity
     * types. A fresh question with the same conversation behind it, and a follow-up whose
     * entity types QU did name, are decided by QU alone — exactly as before.
     */
    public function the_recorded_result_never_stands_in_for_a_fresh_question_or_named_types(): void
    {
        $rows = $this->unitRoster(3);

        $fresh = $this->turn($rows, self::ROSTER_CYPHER, ['intent' => 'list', 'entityTypes' => [], 'isPossessive' => true]);
        $fresh->conversationContext = $this->rosterConversation();
        $this->assertNotContains('team_settings', array_column($this->attach($fresh)->invocations(), 'key'), 'not a follow-up');

        $named = $this->turn($rows, self::ROSTER_CYPHER, ['entityTypes' => ['Unit']] + self::FOLLOW_UP_QU);
        $named->conversationContext = $this->rosterConversation();
        $this->assertNotContains('team_settings', array_column($this->attach($named)->invocations(), 'key'), 'QU named the subject');
    }

    /**
     * @test E8: only the result the conversation RECORDED and still focuses on stands in.
     *  - a recorded result that is not about the team attaches no team link;
     *  - no recorded query (a digest turn records rows without one; the entity types an OLDER
     *    turn left in the snapshot are stale) → nothing stands in;
     *  - a focus that moved off the recorded result (a documents turn about another entity in
     *    between) → nothing stands in: when the signals cannot decide, nothing is attached.
     */
    public function only_the_recorded_result_the_conversation_still_focuses_on_stands_in(): void
    {
        $keys = function (array $conversation): array {
            $ctx = $this->turn($this->teamRoster(true), self::TEAM_ROSTER_LOGIN_CYPHER, self::FOLLOW_UP_QU, ['true_total' => 523]);
            $ctx->conversationContext = $conversation;

            return array_column($this->attach($ctx)->invocations(), 'key');
        };

        $this->assertNotContains('team_settings', $keys($this->rosterConversation([
            'focused_entity' => 'Unit', 'last_cypher_query' => self::ROSTER_CYPHER, 'last_generated_cypher' => self::ROSTER_CYPHER,
            'last_anchor_label' => 'Unit', 'last_entity_types' => ['Unit', 'Union'],
        ])), 'the recorded result is a unit list');

        $this->assertNotContains('team_settings', $keys($this->rosterConversation([
            'last_cypher_query' => null, 'last_generated_cypher' => null, 'last_anchor_label' => null,
        ])), 'no recorded query: last_entity_types is an older turn\'s');

        $this->assertNotContains('team_settings', $keys($this->rosterConversation(['focused_entity' => 'Union'])), 'the focus moved on');

        $this->assertContains('team_settings', $keys($this->rosterConversation(['focused_entity' => null])), 'no focus recorded: the recorded result decides');
        $this->assertSame([], $keys([]), 'no conversation at all');
    }

    /**
     * @test E8 + eval p11/p12 noise rule: the stand-in decides what the turn is ABOUT, never
     * who its PRIMARY subject is — the order of a recorded query's labels is how the query was
     * written, not a judgment. A possessive follow-up on a roster whose query starts at User
     * (eval p16-A team-roster cypher) must not attach My profile.
     */
    public function a_possessive_follow_up_on_a_user_anchored_roster_attaches_no_profile_link(): void
    {
        $cypher = 'MATCH (u:User) WHERE u.current_team_id = 3 OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = 3 '
            . 'RETURN u.name AS name, u.email AS email, tr.role AS role LIMIT 100';
        $rows = [
            ['name' => 'Lea Lead', 'email' => 'lead@example.com', 'role' => 'lead', 'last_login' => '2026-10-01 09:00:00'],
            ['name' => 'Sam Staff', 'email' => 'sam@example.com', 'role' => 'manager', 'last_login' => null],
        ];
        $ctx = $this->turn($rows, $cypher, self::FOLLOW_UP_QU);
        $ctx->conversationContext = $this->rosterConversation([
            'focused_entity' => 'User', 'last_cypher_query' => $cypher, 'last_generated_cypher' => $cypher,
            'last_anchor_label' => 'User', 'last_entity_types' => ['User', 'TeamRole'],
        ]);

        $this->assertSame([], $this->attach($ctx)->invocations());
    }

    /** @test B2: no profile route → no profile chip (never a dead link). */
    public function no_profile_chip_without_the_route(): void
    {
        config(['ai.attached_actions.identity.profile.route' => 'missing.route']);

        $chips = $this->attach($this->turn([], null, ['isPossessive' => true, 'entityTypes' => ['User']]));

        $this->assertNotContains('my_profile', array_column($chips->invocations(), 'key'));
    }

    // ---------------------------------------------------------------- B3

    /** @test B3 empty: the entity's list page + its create form (create only for who may create). */
    public function an_empty_result_offers_the_list_and_create_pages(): void
    {
        $ctx = $this->turn([], 'MATCH (m:Meeting) RETURN m', ['entityTypes' => ['Meeting']]);

        $this->assertSame(['meetings', 'new_meeting'], array_column($this->attach($ctx)->invocations(), 'key'));
        $this->assertSame(['meetings'], array_column($this->attach($ctx, $this->resident())->invocations(), 'key'));
    }

    /**
     * @test Eval p9 F4: an empty result for a POSSESSIVE (own-data) question — "how much is my
     * contribution?" from a manager who owns no unit — never offers a create form: the user
     * asked about records linked to THEM, not to create one. The list page stays ("when is
     * our next meeting?" → Meetings, graded the right door live).
     */
    public function an_empty_possessive_result_offers_no_create_form(): void
    {
        config(['ai.generic_actions.new_invoice' => ['action' => fn ($text = 'New invoice') => 'new-invoice', 'label' => 'New invoice', 'entity' => 'Invoice', 'kind' => 'create']]);
        $contribution = 'MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) MATCH (i:Invoice)-[:CUSTOMER]->(u) '
            . 'RETURN sum(i.total_amount) AS contribution_amount';

        $own = $this->turn([], $contribution, ['intent' => 'lookup', 'entityTypes' => ['Invoice', 'Unit', 'Union'], 'isPossessive' => true]);
        $this->assertNotContains('new_invoice', array_column($this->attach($own)->invocations(), 'key'));

        $meeting = $this->turn([], 'MATCH (m:Meeting) RETURN m', ['entityTypes' => ['Meeting'], 'isPossessive' => true]);
        $this->assertSame(['meetings'], array_column($this->attach($meeting)->invocations(), 'key'));

        $notOwn = $this->turn([], 'MATCH (i:Invoice) RETURN i', ['entityTypes' => ['Invoice']]);
        $this->assertSame(['new_invoice'], array_column($this->attach($notOwn)->invocations(), 'key'), 'non-possessive empties keep the create form');
    }

    /**
     * @test Eval p16-B rights t2: the own-data empty above attached NOTHING (Invoice has no list
     * page, and its create form is suppressed) — the answer had no door. An own-data empty gets
     * ONE door: the subject's list page, else its report, when the user may open it.
     */
    public function an_empty_own_data_result_offers_the_subjects_report_when_it_has_no_list_page(): void
    {
        config(['ai.generic_actions.new_invoice' => ['action' => fn ($text = 'New invoice') => 'new-invoice', 'label' => 'New invoice', 'entity' => 'Invoice', 'kind' => 'create']]);
        $contribution = 'MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) MATCH (i:Invoice)-[:CUSTOMER]->(u) '
            . 'RETURN i.invoice_number AS invoice_number, i.due_amount AS contribution';
        $qu = ['intent' => 'lookup', 'entityTypes' => ['Invoice', 'Unit', 'Union'], 'isPossessive' => true];

        $own = $this->turn([], $contribution, $qu);
        $this->assertSame(['report_receivables'], array_column($this->attach($own)->invocations(), 'key'));
        $this->assertSame([], $this->attach($own, $this->resident())->invocations(), 'a user who may not open the report gets no door');

        // A list page wins over the report: one door, never both.
        config(['ai.generic_actions.invoices' => ['action' => fn ($text = 'Invoices') => 'invoices', 'label' => 'Invoices', 'entity' => 'Invoice', 'kind' => 'list']]);
        $this->assertSame(['invoices'], array_column($this->attach($this->turn([], $contribution, $qu))->invocations(), 'key'));
    }

    /**
     * @test The own-data scope is the EXECUTED query's (execution metadata empty_scope, stamped
     * by AiManager from the query's user anchor) — it holds even when QU's possessive flag is
     * off, and a team-wide empty that QU did not flag keeps the create form.
     */
    public function the_own_data_scope_of_an_empty_result_comes_from_the_executed_query(): void
    {
        config(['ai.generic_actions.new_invoice' => ['action' => fn ($text = 'New invoice') => 'new-invoice', 'label' => 'New invoice', 'entity' => 'Invoice', 'kind' => 'create']]);
        $contribution = 'MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) MATCH (i:Invoice)-[:CUSTOMER]->(u) '
            . 'RETURN i.invoice_number AS invoice_number';
        $qu = ['intent' => 'lookup', 'entityTypes' => ['Invoice'], 'isPossessive' => false];

        $anchored = $this->turn([], $contribution, $qu, ['empty_scope' => 'own']);
        $this->assertSame(['report_receivables'], array_column($this->attach($anchored)->invocations(), 'key'));

        $teamWide = $this->turn([], 'MATCH (i:Invoice) RETURN i.invoice_number', $qu, ['empty_scope' => 'team']);
        $this->assertSame(['new_invoice'], array_column($this->attach($teamWide)->invocations(), 'key'));
    }

    /**
     * @test A COLLECTIVE possessive ("our next meeting", "do we have…") speaks for the team: it
     * is a team question and keeps the team question's doors — only PERSONAL possession ("my…")
     * suppresses the create form.
     */
    public function a_collective_possessive_empty_result_keeps_the_team_doors(): void
    {
        $cypher = 'MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids RETURN m.name AS name';
        $base = ['entityTypes' => ['Meeting'], 'isPossessive' => true];

        $collective = $this->turn([], $cypher, $base + ['possessiveWords' => ['our'], 'possessiveScope' => 'collective'], ['empty_scope' => 'team']);
        $this->assertSame(['meetings', 'new_meeting'], array_column($this->attach($collective)->invocations(), 'key'));

        $personal = $this->turn([], $cypher, $base + ['possessiveWords' => ['my'], 'possessiveScope' => 'personal'], ['empty_scope' => 'team']);
        $this->assertSame(['meetings'], array_column($this->attach($personal)->invocations(), 'key'));
    }

    /**
     * @test Eval p16-A meetings: an empty "next meeting" result answered from the host's
     * fallback rows (execution metadata empty_fallback) keeps the door the empty answer had —
     * the source's own configured page, as the digest gives that section — beside the rows'
     * record chips. Only a page the user may open; an unknown key attaches nothing.
     */
    public function fallback_rows_carry_their_sources_configured_page(): void
    {
        $rows = [
            ['id' => 140, 'name' => 'AGA 2023', 'start_date' => '2023-10-24 16:00:00'],
            ['id' => 131, 'name' => 'CA septembre', 'start_date' => '2023-09-12 18:00:00'],
        ];
        $cypher = 'MATCH (m:Meeting) RETURN m.id AS id, m.name AS name, m.start_date AS start_date ORDER BY m.start_date DESC';
        $qu = ['intent' => 'lookup', 'entityTypes' => ['Meeting'], 'isPossessive' => true];
        $fallback = fn (?string $key) => ['empty_fallback' => [
            'source' => 'upcoming_meetings', 'label' => 'Upcoming meetings',
            'fallback_label' => 'Most recent meetings — none scheduled', 'entity' => 'Meeting', 'action_key' => $key,
        ]];

        $chips = $this->attach($this->turn($rows, $cypher, $qu, $fallback('meetings')));
        $this->assertSame([
            'view|Meeting|140|AGA 2023',
            'view|Meeting|131|CA septembre',
            'meetings|||Meetings',
        ], $this->chipLines($chips->invocations()));

        $plain = $this->attach($this->turn($rows, $cypher, $qu));
        $this->assertNotContains('meetings', array_column($plain->invocations(), 'key'), 'an ordinary complete list gets no list page');

        $denied = $this->attach($this->turn($rows, $cypher, $qu, $fallback('report_receivables')), $this->resident());
        $this->assertNotContains('report_receivables', array_column($denied->invocations(), 'key'));

        $unknown = $this->attach($this->turn($rows, $cypher, $qu, $fallback('no_such_action')));
        $this->assertSame(['view', 'view'], array_column($unknown->invocations(), 'key'));
    }

    /** @test B3 empty: a failed / safe-stopped data part is not "nothing matched" — no offers. */
    public function a_failed_data_part_gets_no_empty_offers(): void
    {
        $ctx = $this->turn([], 'MATCH (m:Meeting) RETURN m', ['entityTypes' => ['Meeting']], ['safe_stop' => true]);

        $this->assertSame([], $this->attach($ctx)->invocations());
    }

    /** @test B3 one record: its other read actions (statement), never a mutation. */
    public function a_single_record_offers_its_secondary_actions(): void
    {
        $ctx = $this->turn([['unit_id' => 159, 'unit_name' => '310', 'union_name' => 'Carré Soho Phase 3']], self::HOMONYM_CYPHER, ['intent' => 'lookup']);

        $this->assertSame([
            'view|Unit|159|Unit 310',
            'statement|Unit|159|Account statement',
            'add_access|Unit|159|Add access',
        ], $this->chipLines($this->attach($ctx)->invocations()));
        $this->assertSame([
            'view|Unit|159|Unit 310',
            'statement|Unit|159|Account statement',
        ], $this->chipLines($this->attach($ctx, $this->resident())->invocations()));
    }

    /** @test B3 truncated beyond the table: list page + Excel of every retrieved row. */
    public function a_list_longer_than_the_table_offers_list_and_excel(): void
    {
        $ctx = $this->turn($this->unitRoster(30), self::ROSTER_CYPHER, ['intent' => 'list', 'searchQuery' => 'units list']);

        $invocations = $this->attach($ctx)->invocations();
        $keys = array_column($invocations, 'key');

        $this->assertContains('units', $keys);
        $this->assertContains('export_excel', $keys);
        $export = $invocations[array_search('export_excel', $keys, true)];
        $this->assertSame(['title' => 'units list', 'mode' => 'table'], $export['params']);
        $this->assertSame(1, array_count_values($keys)['units'], '"+N more" and the truncation list chip are one door');
    }

    /**
     * @test Eval p12-B: the Excel chip's title is user-visible — QU's subject_label (the
     * question's language), never the English-normalized search_query when a label exists.
     */
    public function the_excel_chip_is_titled_by_the_localized_subject_label(): void
    {
        $ctx = $this->turn($this->unitRoster(30), self::ROSTER_CYPHER, [
            'intent' => 'list', 'searchQuery' => 'units list', 'subjectLabel' => 'Liste des unités', 'language' => 'fr',
        ]);

        $invocations = $this->attach($ctx)->invocations();
        $keys = array_column($invocations, 'key');
        $export = $invocations[array_search('export_excel', $keys, true)];

        $this->assertSame(['title' => 'Liste des unités', 'mode' => 'table'], $export['params']);
    }

    /**
     * @test B3 truncated retrieval (true total > rows) that a document cannot hold whole: list
     * page but NO partial Excel — neither of the stored rows nor of a re-run cut at the limit.
     */
    public function a_capped_retrieval_offers_no_partial_excel(): void
    {
        config(['ai.export.document_row_limit' => 250]);
        $ctx = $this->turn($this->unitRoster(3), self::ROSTER_CYPHER, ['intent' => 'list'], ['true_total' => 523]);

        $keys = array_column($this->attach($ctx)->invocations(), 'key');

        $this->assertContains('units', $keys);
        $this->assertNotContains('export_excel', $keys);
    }

    /** The capped roster of eval p18-A: 100 rows retrieved of 523, the generator's habitual LIMIT 100. */
    private function cappedRoster(?string $cypher, array $qu = [], array $metadata = ['true_total' => 523]): \Condoedge\Ai\DTOs\PipelineContext
    {
        config(['ai.export.document_row_limit' => 2000]);

        return $this->turn($this->unitRoster(100), $cypher, $qu + ['intent' => 'list', 'searchQuery' => 'units list'], $metadata);
    }

    /** @return array<string, mixed>|null the Excel chip's invocation */
    private function excelChip(\Condoedge\Ai\DTOs\PipelineContext $ctx, ?object $user = null): ?array
    {
        $invocations = $this->attach($ctx, $user)->invocations();
        $at = array_search('export_excel', array_column($invocations, 'key'), true);

        return $at === false ? null : $invocations[$at];
    }

    /**
     * @test Eval p18-A roster (25 of 523 shown, no export door): a capped list whose query is
     * recorded with the message gets the Excel chip in RE-RUN mode — the click re-executes the
     * recorded query as the clicking user and writes every row, exactly what the follow-up
     * "give me that as an Excel" does. Never mode=table: the stored rows are the first 100.
     */
    public function a_capped_list_with_a_recorded_query_offers_the_excel_that_reruns_it(): void
    {
        $chip = $this->excelChip($this->cappedRoster(self::ROSTER_CYPHER . ' LIMIT 100'));

        $this->assertNotNull($chip, 'the capped roster carries an export door');
        $this->assertSame(['title' => 'units list', 'mode' => 'rerun', 'language' => 'en'], $chip['params']);
        $this->assertSame('Download Excel', $chip['text']);
        $this->assertNull($chip['entity_type']);

        // No LIMIT in the query at all: the executor's own cap was the display limit.
        $this->assertSame('rerun', $this->excelChip($this->cappedRoster(self::ROSTER_CYPHER))['params']['mode']);
        // The chip carries the answer's language only when QU knows it.
        $this->assertSame(
            ['title' => 'units list', 'mode' => 'rerun'],
            $this->excelChip($this->cappedRoster(self::ROSTER_CYPHER, ['language' => '']))['params']
        );
    }

    /**
     * @test The built-in export chips are labelled in the QUESTION's language, like the
     * identity and next-step chips (eval p19-B french t0: "Download Excel" under a French
     * roster) — the package's own lang lines, the ones the export answer already uses.
     */
    public function the_export_chip_is_labelled_in_the_questions_language(): void
    {
        // The app locale stays English: the QUESTION's language decides.
        $this->assertSame('en', app()->getLocale());

        $french = $this->excelChip($this->cappedRoster(self::ROSTER_CYPHER . ' LIMIT 100', ['language' => 'fr']));
        $this->assertSame('Télécharger Excel', $french['text']);
        $this->assertSame('fr', $french['params']['language']);

        // The complete-list chip (mode=table) and the PDF of an export ask alike.
        $complete = $this->excelChip($this->turn($this->unitRoster(40), self::ROSTER_CYPHER, ['intent' => 'list', 'language' => 'fr']));
        $this->assertSame('table', $complete['params']['mode']);
        $this->assertSame('Télécharger Excel', $complete['text']);

        // A language the package has no line for: the app's fallback line, never the key.
        $other = $this->excelChip($this->cappedRoster(self::ROSTER_CYPHER . ' LIMIT 100', ['language' => 'de']));
        $this->assertSame('Download Excel', $other['text']);

        // QU does not know the language: the app locale.
        app()->setLocale('fr');
        $unknown = $this->excelChip($this->cappedRoster(self::ROSTER_CYPHER . ' LIMIT 100', ['language' => '']));
        $this->assertSame('Télécharger Excel', $unknown['text']);
        app()->setLocale('en');
    }

    /**
     * @test The re-run chip is offered only when a re-run would hold EVERY row — otherwise
     * today's behaviour stands (the list page, no Excel).
     *
     * @dataProvider cappedListsARerunCannotCover
     */
    public function a_capped_list_gets_no_excel_when_a_rerun_cannot_hold_every_row(?string $cypher, array $metadata, array $config = []): void
    {
        $ctx = $this->cappedRoster($cypher, [], $metadata);
        config($config);

        $this->assertNull($this->excelChip($ctx));
    }

    public static function cappedListsARerunCannotCover(): array
    {
        return [
            'no query recorded with the message' => [null, ['true_total' => 523]],
            'a blank query' => ['   ', ['true_total' => 523]],
            'the true total is unknown (cap hit, no companion count)' => [self::ROSTER_CYPHER . ' LIMIT 100', []],
            'more rows than a document may hold' => [self::ROSTER_CYPHER . ' LIMIT 100', ['true_total' => 523], ['ai.export.document_row_limit' => 500]],
            'a page of the list (SKIP)' => [self::ROSTER_CYPHER . ' SKIP 100 LIMIT 100', ['true_total' => 523]],
            'a document no larger than the display cap' => [self::ROSTER_CYPHER . ' LIMIT 100', ['true_total' => 523], ['ai.export.document_row_limit' => 100]],
        ];
    }

    /**
     * @test The chip re-runs the query RECORDED with the message, replaying only its `$pii_…`
     * bindings: a query bound to anything else could not be re-run later, so it gets no chip.
     */
    public function a_capped_list_whose_query_binds_what_a_rerun_cannot_replay_gets_no_excel(): void
    {
        $cypher = 'MATCH (u:Unit)-[:UNION]->(un:Union) WHERE un.name = $pii_0123456789ab RETURN u.id AS unit_id, u.name AS unit_name LIMIT 100';
        $withParameters = function (array $parameters) use ($cypher): \Condoedge\Ai\DTOs\PipelineContext {
            $ctx = $this->cappedRoster($cypher);
            $ctx->queryResult = new \Condoedge\Ai\DTOs\QueryGenerationResult(cypher: $cypher, parameters: $parameters);

            return $ctx;
        };

        $this->assertNotNull($this->excelChip($withParameters(['pii_0123456789ab' => 'Union A'])), 'a pii binding is recorded with the message and replayed');
        $this->assertNull($this->excelChip($withParameters(['pii_0123456789ab' => 'Union A', 'since' => '2026-01-01'])));
        $this->assertNull($this->excelChip($withParameters(['ids' => [1, 2, 3]])));
    }

    /**
     * @test Fallback rows of an empty result come from a host-configured source query, run
     * with the source's own bindings: not a query this chip can replay — today's behaviour.
     */
    public function fallback_rows_of_an_empty_result_get_no_rerun_excel(): void
    {
        $fallback = ['empty_fallback' => ['source' => 'recent_units', 'label' => 'Units', 'fallback_label' => 'Recent units', 'entity' => 'Unit', 'action_key' => 'units']];

        $this->assertNull($this->excelChip($this->cappedRoster(self::ROSTER_CYPHER . ' LIMIT 100', [], ['true_total' => 523] + $fallback)));
    }

    /** @test The re-run chip is the export action's: a user the action is not offered to gets none. */
    public function the_rerun_excel_is_offered_only_to_who_may_use_the_export_action(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new class extends \Condoedge\Ai\Services\Actions\BuiltIn\ExportExcelAction {
            public function authorize(mixed $user, array $input): bool
            {
                return is_object($user) && ($user->role ?? null) === 'manager';
            }

            public function mutates(): bool
            {
                // A gated action is advertised through its authorize() (ActionAdvertisement).
                return false;
            }
        });
        $ctx = $this->cappedRoster(self::ROSTER_CYPHER . ' LIMIT 100');
        $keysFor = fn (object $user): array => array_column(
            (new ActionAttacher($registry))->forTurn($ctx, ['user' => $user])->invocations(),
            'key'
        );

        $this->assertContains('export_excel', $keysFor($this->manager()));
        $this->assertNotContains('export_excel', $keysFor($this->resident()));
    }

    /** @test A list retrieved whole keeps the stored-row Excel: nothing to re-run for. */
    public function a_list_retrieved_whole_keeps_the_stored_row_excel(): void
    {
        config(['ai.export.document_row_limit' => 2000]);
        $ctx = $this->turn($this->unitRoster(30), self::ROSTER_CYPHER . ' LIMIT 100', ['intent' => 'list', 'searchQuery' => 'units list'], ['true_total' => 30]);

        $this->assertSame(['title' => 'units list', 'mode' => 'table'], $this->excelChip($ctx)['params']);
    }

    /**
     * @test Eval p9 F10: export_excel (mode=table) exports the message's STORED rows — it never
     * re-runs the query — so a retrieval that hit the executor cap (100 of 523, no true total)
     * offers the list page but no Excel that would silently hold only the first 100 rows.
     */
    public function a_retrieval_at_the_executor_cap_offers_no_partial_excel(): void
    {
        $ctx = $this->turn($this->unitRoster(100), self::ROSTER_CYPHER, ['intent' => 'list']);

        $keys = array_column($this->attach($ctx)->invocations(), 'key');

        $this->assertContains('units', $keys);
        $this->assertNotContains('export_excel', $keys);
    }

    /** @test B3 money: the report mapped to the entity, for who may open it; else its list page. */
    public function a_money_answer_offers_the_entitys_report(): void
    {
        $cypher = 'MATCH (u:Unit)<-[:FOR]-(i:Invoice) RETURN u.id AS unit_id, u.name AS unit_name, sum(i.due_amount) AS owed ORDER BY owed DESC';
        $rows = [['unit_id' => 1, 'unit_name' => 'A-1', 'owed' => 900.5], ['unit_id' => 2, 'unit_name' => 'A-2', 'owed' => 10.25]];

        $this->assertContains('report_receivables', array_column($this->attach($this->turn($rows, $cypher, ['intent' => 'list']))->invocations(), 'key'));
        $this->assertNotContains('report_receivables', array_column($this->attach($this->turn($rows, $cypher, ['intent' => 'list']), $this->resident())->invocations(), 'key'));

        $bills = 'MATCH (b:Bill)-[:SUPPLIER]->(s:Supplier) RETURN s.name AS supplier, sum(b.due_amount) AS due';
        $keys = array_column($this->attach($this->turn([['supplier' => 'Acme', 'due' => 50.5], ['supplier' => 'Bolt', 'due' => 3.5]], $bills, ['intent' => 'aggregate']))->invocations(), 'key');
        $this->assertSame(['bills_table'], $keys, 'no report for Bill → its list page (payables)');
    }

    /** @test B3: at most max_next_steps next-step chips. */
    public function next_steps_are_capped(): void
    {
        config(['ai.attached_actions.max_next_steps' => 1]);
        $ctx = $this->turn([['unit_id' => 159, 'unit_name' => '310']], self::HOMONYM_CYPHER, ['intent' => 'lookup']);

        $this->assertCount(2, $this->attach($ctx)->invocations(), 'record chip + ONE next step');
    }

    // ---------------------------------------------------------------- B4

    /** @test B4: each non-empty digest section's link, record chips per row, the export chip. */
    public function digest_sections_rows_and_export_are_attached_by_code(): void
    {
        $ctx = $this->turn([], null, ['digestRequest' => true, 'exportFormat' => 'excel', 'searchQuery' => 'attention']);
        $sources = [
            new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [['id' => 1, 'title' => 'Fix door']], rowCount: 1, actionKey: 'support', entity: 'Task'),
            new DigestSourceResult(key: 'meetings', label: 'Upcoming meetings', rows: [['id' => 140, 'name' => 'AGM']], rowCount: 1, usedFallback: true, fallbackLabel: 'Most recent meetings', actionKey: 'meetings', entity: 'Meeting'),
            new DigestSourceResult(key: 'bills', label: 'Bills', rows: [], rowCount: 0, actionKey: 'bills_table', entity: 'Bill'),
            new DigestSourceResult(key: 'broken', label: 'Broken', error: 'boom', actionKey: 'units', entity: 'Unit'),
        ];

        $chips = (new ActionAttacher(new AiActionRegistry()))->forDigest($ctx, $sources, ['user' => $this->manager()]);

        $this->assertSame([
            'support|||Open tasks',
            'meetings|||Most recent meetings',
            'view|Meeting|140|AGM',
            'export_excel|||Download Excel',
        ], $this->chipLines($chips->invocations()));
    }

    /**
     * @test B4: digest record chips are spread across sections (round-robin), not spent on the
     * first section's rows (live 2026-10-01: 4 bills + 1 invoice used the whole cap, the
     * meeting and unit rows got none).
     */
    public function digest_record_chips_are_spread_across_sections(): void
    {
        config(['ai.attached_actions.max_record_chips' => 3]);
        $ctx = $this->turn([], null, ['digestRequest' => true]);
        $sources = [
            new DigestSourceResult(key: 'meetings', label: 'Meetings', rows: [['id' => 1, 'name' => 'AGM'], ['id' => 2, 'name' => 'Board'], ['id' => 3, 'name' => 'Gala']], rowCount: 3, entity: 'Meeting'),
            new DigestSourceResult(key: 'units', label: 'Units', rows: [['id' => 10, 'unit_name' => 'Lot A'], ['id' => 11, 'unit_name' => 'Lot B']], rowCount: 2, entity: 'Unit'),
        ];

        $chips = (new ActionAttacher(new AiActionRegistry()))->forDigest($ctx, $sources, ['user' => $this->manager()]);

        $this->assertSame(['view|Meeting|1|AGM', 'view|Unit|10|Lot A', 'view|Meeting|2|Board'], $this->chipLines($chips->invocations()));
    }

    /** @test Disabled: nothing is attached. */
    public function nothing_is_attached_when_disabled(): void
    {
        config(['ai.attached_actions.enabled' => false]);

        $this->assertTrue($this->attach($this->turn($this->unitRoster(3), self::ROSTER_CYPHER))->isEmpty());
    }
}
