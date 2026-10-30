<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\PiiMasker;
use Condoedge\Ai\Services\Response\TableRenderer;
use Condoedge\Ai\Tests\TestCase;

/**
 * THE table renderer (Phase 8 batch A): one place turns result rows into the
 * user-facing markdown table / export matrix — with the exporter's redaction
 * (internal id columns, UI decorations), humanized labels, a display cap and an
 * honest shown/total count. Also hosts the structural analyses the data prompt
 * needs: summable columns, query-computed total columns, homonym groups.
 */
class TableRendererTest extends TestCase
{
    private TableRenderer $renderer;

    public function setUp(): void
    {
        parent::setUp();
        $this->renderer = new TableRenderer();
    }

    private function units(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['unit_id' => 1000 + $i, 'unit' => (string) (100 + $i), 'balance' => $i + 0.5];
        }

        return $rows;
    }

    /** @test */
    public function it_renders_a_markdown_table_with_humanized_labels_and_redaction(): void
    {
        $out = $this->renderer->render([
            ['neo4j_id' => 9, 'supplier_name' => 'Hydro | Québec', 'due_amount' => 819.82, 'action_links' => ['[x](entity://A/1/v)'], '_label' => 'Bill'],
            ['neo4j_id' => 8, 'supplier_name' => 'Arken', 'due_amount' => 574.88],
        ]);

        $this->assertSame(['supplier_name', 'due_amount'], $out['columns']);
        $this->assertSame(['Supplier Name', 'Due Amount'], $out['headers']);
        $this->assertStringContainsString('| Supplier Name | Due Amount |', $out['markdown']);
        $this->assertStringContainsString('| Hydro \| Québec | 819.82 |', $out['markdown']);
        $this->assertStringNotContainsString('neo4j', $out['markdown']);
        $this->assertStringNotContainsString('entity://', $out['markdown']);
        $this->assertSame(2, $out['shown']);
        $this->assertSame(2, $out['total']);
        $this->assertFalse($out['truncated']);
    }

    /** @test */
    public function the_display_cap_defaults_to_config_and_reports_shown_of_total(): void
    {
        config(['ai.response_generation.table_max_rows' => 25]);

        $out = $this->renderer->render($this->units(30), ['total' => 75]);

        $this->assertSame(25, $out['shown']);
        $this->assertSame(75, $out['total']);
        $this->assertTrue($out['truncated']);
        $this->assertSame(27, substr_count($out['markdown'], "\n") + 1, 'header + separator + 25 rows');
    }

    /** @test */
    public function display_formatting_groups_floats_but_never_integers_or_numeric_strings(): void
    {
        $out = $this->renderer->render([
            ['unit' => '310', 'year' => 2024, 'balance' => 11493.17, 'due_at' => '2022-06-29T00:00:00.000000Z', 'paid' => true],
        ], ['humanize_values' => true]);

        $this->assertStringContainsString('| 310 | 2024 | 11,493.17 | 2022-06-29 | Yes |', $out['markdown']);
    }

    /** @test */
    public function matrix_returns_labels_and_raw_string_cells(): void
    {
        [$headers, $matrix] = $this->renderer->matrix([
            ['id' => 1, 'name' => 'Alice', 'amount' => 100],
            ['id' => 2, 'name' => 'Bob', 'amount' => 250.5],
        ]);

        $this->assertSame(['Name', 'Amount'], $headers);
        $this->assertSame([['Alice', '100'], ['Bob', '250.5']], $matrix);
    }

    /** @test */
    public function masked_rendering_shows_tokens_for_pii(): void
    {
        config(['ai.access_control.identifying_fields' => ['*' => ['email']]]);
        $masker = PiiMasker::fromConfig();

        $out = $this->renderer->render([['name' => 'Karen', 'email' => 'karen@x.test']], ['masker' => $masker]);

        $this->assertStringContainsString('| Karen | [email#1] |', $out['markdown']);
        $this->assertStringNotContainsString('karen@x.test', $out['markdown']);
    }

    /** @test */
    public function summable_columns_are_detected_structurally_from_value_types(): void
    {
        $rows = [
            ['unit' => '310', 'fiscal_year' => 2024, 'open_bills' => 3, 'balance' => 100.25],
            ['unit' => '311', 'fiscal_year' => 2024, 'open_bills' => 1, 'balance' => 50.0],
        ];

        // P-3: only DECLARED MONEY decimal columns are summable — integers (years,
        // counts, unit numbers) never, and an undeclared decimal (a rate) never.
        $this->assertSame(['balance'], $this->renderer->summableColumns($rows, ['balance', 'fiscal_year']));
        $this->assertSame([], $this->renderer->summableColumns($rows, []), 'unknown money => nothing summed');
        $this->assertEqualsWithDelta(150.25, $this->renderer->sum($rows, 'balance'), 0.0001);
    }

    /** @test P-3 */
    public function a_rate_or_unit_price_is_never_summed_without_a_money_declaration(): void
    {
        $rows = [
            ['item' => 'Fee', 'unit_price' => 12.5, 'tax_rate' => 0.15, 'amount' => 100.25],
            ['item' => 'Fine', 'unit_price' => 3.75, 'tax_rate' => 0.15, 'amount' => 20.0],
        ];

        $this->assertSame(['amount'], $this->renderer->summableColumns($rows, ['amount']));
    }

    /** @test */
    public function a_constant_column_equal_to_the_sum_of_a_varying_column_is_the_query_total(): void
    {
        $rows = [
            ['supplier' => 'A', 'total_payable' => 7022.87, 'all_suppliers_total' => 10098.47],
            ['supplier' => 'B', 'total_payable' => 3075.6, 'all_suppliers_total' => 10098.47],
        ];

        $this->assertSame(['all_suppliers_total'], $this->renderer->constantTotalColumns($rows, true));
        $this->assertSame([], $this->renderer->constantTotalColumns([$rows[0]], true), 'one row proves nothing');
    }

    /** @test P-4: a constant fee or rate is not a total. */
    public function a_constant_fee_or_tax_rate_is_never_taken_for_the_query_total(): void
    {
        $rows = [
            ['unit' => '101', 'condo_fee' => 350.0, 'balance' => 120.5, 'tax_rate' => 0.15],
            ['unit' => '102', 'condo_fee' => 350.0, 'balance' => 80.25, 'tax_rate' => 0.15],
            ['unit' => '103', 'condo_fee' => 350.0, 'balance' => 10.0, 'tax_rate' => 0.15],
        ];

        $this->assertSame([], $this->renderer->constantTotalColumns($rows, true, ['balance', 'condo_fee']));
    }

    /** @test P-4: on a truncated result a constant ≥ the visible money sum may be the grand total. */
    public function on_a_truncated_result_a_constant_total_needs_a_money_column_it_covers(): void
    {
        $rows = [
            ['unit' => '101', 'balance' => 120.5, 'grand_total' => 5000.0],
            ['unit' => '102', 'balance' => 80.25, 'grand_total' => 5000.0],
        ];

        $this->assertSame(['grand_total'], $this->renderer->constantTotalColumns($rows, false, ['balance']));
        $this->assertSame([], $this->renderer->constantTotalColumns($rows, false, []), 'the covered column must be money');
        $this->assertSame([], $this->renderer->constantTotalColumns($rows, true, ['balance']), 'complete: must EQUAL the sum');
    }

    /** @test P-5: tenant-entered markdown/citation syntax renders as plain text. */
    public function cell_values_cannot_inject_links_citations_or_emphasis(): void
    {
        $out = $this->renderer->render([
            ['note' => '[Verify](https://phish.example) see [3] *now* _x_ `code`'],
        ]);

        $this->assertStringContainsString(
            '| \[Verify\]\(https://phish.example\) see \[3\] \*now\* \_x\_ \`code\` |',
            $out['markdown']
        );
        $this->assertStringNotContainsString('[Verify](', $out['markdown']);
    }

    /** @test */
    public function homonyms_are_rows_sharing_a_name_but_differing_in_a_parent_column(): void
    {
        $rows = [
            ['unit' => '310', 'union_name' => 'Syndicat Alpha', 'balance' => 10.0],
            ['unit' => '310', 'union_name' => 'Syndicat Beta', 'balance' => 20.0],
            ['unit' => '310', 'union_name' => 'Syndicat Gamma', 'balance' => 30.0],
        ];

        $groups = $this->renderer->homonyms($rows);

        $this->assertCount(1, $groups);
        $this->assertSame('unit', $groups[0]['column']);
        $this->assertSame('310', $groups[0]['value']);
        $this->assertSame(3, $groups[0]['count']);
        $this->assertSame('union_name', $groups[0]['by']);
        $this->assertSame(['Syndicat Alpha', 'Syndicat Beta', 'Syndicat Gamma'], $groups[0]['values']);
    }

    /** @test */
    public function a_repeating_parent_first_column_is_not_reported_as_a_homonym(): void
    {
        // [union, unit]: the union repeats because it has many units — the unit
        // column is finer-grained than the name column, so no homonym note.
        $rows = [
            ['union_name' => 'Alpha', 'unit' => '101'],
            ['union_name' => 'Alpha', 'unit' => '102'],
            ['union_name' => 'Alpha', 'unit' => '310'],
            ['union_name' => 'Beta', 'unit' => '310'],
            ['union_name' => 'Beta', 'unit' => '201'],
        ];

        $groups = $this->renderer->homonyms($rows);

        $this->assertSame([], array_values(array_filter($groups, fn ($g) => $g['column'] === 'union_name')));
        // ...but the two unit 310s ARE reported (they differ in the coarser union column).
        $this->assertSame('310', $groups[0]['value'] ?? null);
        $this->assertSame('union_name', $groups[0]['by'] ?? null);
    }

    /** @test Live 2026-09-30: "100 DIFFERENT records share the Role 'contact'". */
    public function a_large_whole_result_group_is_not_a_homonym(): void
    {
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $rows[] = ['user_name' => "Member {$i}", 'role' => 'contact'];
        }

        $this->assertSame([], $this->renderer->homonyms($rows));
    }

    /**
     * @test Eval p10-A: a date is a per-row fact, never what tells records apart — a value
     * repeated on every row whose only differing text column is a date is no homonym group.
     */
    public function a_whole_result_group_differing_only_by_dates_is_not_a_homonym(): void
    {
        $rows = [
            ['union_name' => 'SDC Carré Soho Phase 3', 'oldest_due' => '2021-12-01T15:00:00.000000Z', 'due_on' => '2021-12-01', 'balance' => 456.12],
            ['union_name' => 'SDC Carré Soho Phase 3', 'oldest_due' => '2022-01-01T05:00:00.000000Z', 'due_on' => '2022-01-01', 'balance' => 397.81],
            ['union_name' => 'SDC Carré Soho Phase 3', 'oldest_due' => '2020-11-17 05:00:00', 'due_on' => '2020-11-17', 'balance' => 50.0],
        ];

        $this->assertSame([], $this->renderer->homonyms($rows));
    }

    /** @test A temporal column is never `by`, even when it is the coarsest differing column. */
    public function a_date_column_is_never_the_homonym_by_column(): void
    {
        $rows = [
            ['unit' => '310', 'union_name' => 'Syndicat Alpha', 'created' => '2021-01-01T00:00:00Z'],
            ['unit' => '310', 'union_name' => 'Syndicat Beta', 'created' => '2021-01-01T00:00:00Z'],
            ['unit' => '310', 'union_name' => 'Syndicat Gamma', 'created' => '2021-02-01T00:00:00Z'],
        ];

        $groups = $this->renderer->homonyms($rows);

        $this->assertCount(1, $groups);
        $this->assertSame('union_name', $groups[0]['by']);
    }

    /** @test An aggregate alias (from the cypher) is never `by`; a group left with only such columns is skipped. */
    public function an_aggregate_alias_is_never_the_homonym_by_column(): void
    {
        $rows = [
            ['union_name' => 'Carré Soho', 'last_number' => 'INV-1'],
            ['union_name' => 'Carré Soho', 'last_number' => 'INV-2'],
        ];

        $this->assertSame('last_number', $this->renderer->homonyms($rows)[0]['by'] ?? null);
        $this->assertSame([], $this->renderer->homonyms($rows, 5, ['last_number']));
    }

    /** @test */
    public function distinct_names_have_no_homonyms(): void
    {
        $this->assertSame([], $this->renderer->homonyms($this->units(5)));
    }

    /** @test F8 (eval A board): an unaliased RETURN c.first_name keys the column `c.first_name` — the header is the property. */
    public function an_unaliased_variable_property_key_is_labelled_by_its_property(): void
    {
        $out = $this->renderer->render([
            ['c.first_name' => 'Julie', 'cur.bm_role' => 'president', 'cur.since' => '2024-01-01'],
        ]);

        $this->assertSame(['First Name', 'Bm Role', 'Since'], $out['headers']);
        $this->assertSame(['c.first_name', 'cur.bm_role', 'cur.since'], $out['columns'], 'column keys are untouched');
        $this->assertSame('First Name', $this->renderer->label('c.first_name'));
        $this->assertSame('Bm Role', $this->renderer->label('cur.bmRole'));
    }

    /** @test F8: two columns that collide after the prefix is stripped stay distinguishable (node label from the cypher). */
    public function colliding_property_headers_are_prefixed_with_their_node_label(): void
    {
        $out = $this->renderer->render(
            [['c.name' => 'Julie', 'u.name' => '310', 'amount' => 5]],
            ['variable_labels' => ['c' => 'Contact', 'u' => 'Unit']]
        );

        $this->assertSame(['Contact Name', 'Unit Name', 'Amount'], $out['headers']);
    }

    /** @test F8: without known node labels the variable itself disambiguates; an aliased column keeps its plain label. */
    public function colliding_property_headers_fall_back_to_the_variable(): void
    {
        $out = $this->renderer->render([['name' => 'Novo', 'c.name' => 'Julie', 'u.name' => '310']]);

        $this->assertSame(['Name', 'C Name', 'U Name'], $out['headers']);
    }

    /** @test F8: the same node label on two variables still yields distinct headers. */
    public function colliding_headers_with_the_same_node_label_use_the_variable(): void
    {
        $out = $this->renderer->render(
            [['a.name' => 'Julie', 'b.name' => 'Luc']],
            ['variable_labels' => ['a' => 'Contact', 'b' => 'Contact']]
        );

        $this->assertSame(['A Name', 'B Name'], $out['headers']);
    }

    /** @test */
    public function variable_labels_are_read_from_the_cypher_node_patterns(): void
    {
        $this->assertSame(
            ['c' => 'Contact', 'cur' => 'BoardMember', 'u' => 'Union'],
            TableRenderer::variableLabels("MATCH (c:Contact)-[:IS]->(cur:`BoardMember`) MATCH (u:Union {name: 'x'}) RETURN c.first_name")
        );
        $this->assertSame([], TableRenderer::variableLabels(null));
    }

    /** @test F1: a cell whose value equals a marked value (case-insensitive, trimmed) carries the marker. */
    public function marked_values_get_their_marker_appended_and_are_counted(): void
    {
        $out = $this->renderer->render([
            ['name' => 'Novo Gestion', 'contact_email' => 'J.Rousseau@novogestion.ca '],
            ['name' => 'Other', 'contact_email' => 'luc@novo.test'],
        ], ['marked_values' => ['j.rousseau@novogestion.ca' => "that's you"]]);

        $this->assertStringContainsString("J.Rousseau@novogestion.ca \\(that's you\\)", $out['markdown']);
        $this->assertStringContainsString('| Other | luc@novo.test |', $out['markdown']);
        $this->assertSame(1, $out['marked']);
    }

    /** @test Measured: `b.due_amount AS frais` told the model the fees were 0,00 $. */
    public function in_another_language_an_amounts_alias_yields_to_the_hosts_word_for_its_property(): void
    {
        config(['entities' => ['Bill' => ['graph' => ['label' => 'Bill'], 'metadata' => ['money_properties' => ['due_amount', 'total_amount']]]]]);
        app('translator')->addLines(['*.Due Amount' => 'Montant dû'], 'fr', '*');
        $cypher = 'MATCH (b:Bill) RETURN b.number AS facture, b.due_amount AS frais, b.total_amount AS coût';

        $projections = TableRenderer::headerProjections($cypher, 'fr');

        $this->assertSame(['frais'], array_keys($projections), 'Not the text column; not the amount the host has no word for.');
        $this->assertSame('Due Amount', (new TableRenderer())->label('frais', [], $projections));
    }

    /** @test */
    public function without_marked_values_nothing_is_marked(): void
    {
        $out = $this->renderer->render([['contact_email' => 'j.rousseau@novogestion.ca']]);

        $this->assertStringNotContainsString('(', $out['markdown']);
        $this->assertSame(0, $out['marked']);
    }

    // =========================================================================
    // E2 (eval p11-A): money carries the configured currency; dates read in the app timezone
    // =========================================================================

    /** @test Declared money columns carry the configured symbol (locale position); other decimals do not. */
    public function declared_money_columns_carry_the_configured_currency_symbol(): void
    {
        config(['ai.response_generation.currency_symbol' => '$']);
        $rows = [
            ['unit' => '305', 'balance' => 456.12, 'rate' => 0.15],
            ['unit' => '605', 'balance' => 50, 'rate' => 0.05],
        ];

        $en = $this->renderer->render($rows, ['humanize_values' => true, 'locale' => 'en', 'money_columns' => ['balance']]);
        $fr = $this->renderer->render($rows, ['humanize_values' => true, 'locale' => 'fr', 'money_columns' => ['balance']]);

        $this->assertStringContainsString('| 305 | $456.12 | 0.15 |', $en['markdown']);
        $this->assertStringContainsString('| 605 | $50.00 | 0.05 |', $en['markdown'], 'an integer money value is still money');
        $this->assertMatchesRegularExpression('/\| 305 \| 456,12 \$ \|/u', $fr['markdown'], 'French puts the symbol after the number');
        $this->assertSame('-$5.25', $this->renderer->formatMoney(-5.25, 'en'));
    }

    /** @test Without a configured currency (the package default) money renders as before — no symbol. */
    public function without_a_configured_currency_money_has_no_symbol(): void
    {
        config(['ai.response_generation.currency_symbol' => null]);

        $out = $this->renderer->render([['unit' => '305', 'balance' => 456.12]], [
            'humanize_values' => true, 'locale' => 'en', 'money_columns' => ['balance'],
        ]);

        $this->assertStringContainsString('| 305 | 456.12 |', $out['markdown']);
        $this->assertSame('1,211.48', $this->renderer->formatMoney(1211.48, 'en'));
    }

    /** @test Money formatting is display-only: raw (non-humanized) cells stay raw. */
    public function money_symbol_only_applies_to_humanized_cells(): void
    {
        config(['ai.response_generation.currency_symbol' => '$']);

        [, $matrix] = $this->renderer->matrix([['unit' => '305', 'balance' => 456.12]], ['money_columns' => ['balance']]);

        $this->assertSame([['305', '456.12']], $matrix);
    }

    /** @test A whole number stored as a float (a percentage of 116.0) reads as the whole number; a fraction keeps 2 digits. */
    public function a_whole_float_that_is_not_money_reads_as_a_whole_number(): void
    {
        config(['ai.response_generation.currency_symbol' => '$']);
        $this->assertSame('116', $this->renderer->cell(116.0, true, 'fr'));
        $this->assertSame('116,5', str_replace("\u{202F}", ' ', rtrim($this->renderer->cell(116.5, true, 'fr'), '0')));
        $this->assertSame('8 000,00 $', str_replace(["\u{202F}", "\u{00A0}"], ' ', $this->renderer->formatMoney(8000.0, 'fr')), 'money keeps its cents');
    }

    /**
     * @test A local-midnight date serialized in UTC (05:00Z / 04:00Z in America/New_York) reads as
     * the date; a real timestamp reads in the app timezone with its time; UTC midnight stays a date.
     */
    public function utc_serialized_dates_read_in_the_app_timezone(): void
    {
        config(['app.timezone' => 'America/New_York']);

        $this->assertSame('2022-01-01', $this->renderer->cell('2022-01-01T05:00:00.000000Z', true, 'en'), 'EST midnight');
        $this->assertSame('2021-07-15', $this->renderer->cell('2021-07-15T04:00:00.000000Z', true, 'en'), 'EDT midnight');
        $this->assertSame('2022-06-29 13:51', $this->renderer->cell('2022-06-29T17:51:14.000000Z', true, 'en'), 'a real time, local');
        $this->assertSame('2022-06-29', $this->renderer->cell('2022-06-29T00:00:00.000000Z', true, 'en'), 'UTC midnight is a date shape');
        $this->assertSame('2022-06-29 17:51', $this->renderer->cell('2022-06-29 17:51:14', true, 'en'), 'no offset: no conversion');
        $this->assertSame('2021-07-15', TableRenderer::localDay('2021-07-15T04:00:00.000000Z'));
        $this->assertSame('2021-07-14', TableRenderer::localDay('2021-07-15T02:00:00Z'), 'the local day, not the UTC one');
    }

    /**
     * @test Owner-arrears oldest_due: three of four values are local midnights — a DATE column; the
     * stray 15:00Z (10:00 local) is shown as its date too. A column of real times keeps its times.
     */
    public function a_column_of_mostly_dates_shows_dates_only_while_real_times_keep_their_time(): void
    {
        config(['app.timezone' => 'America/New_York']);

        $dates = $this->renderer->render([
            ['unit' => '305', 'oldest_due' => '2021-12-01T15:00:00.000000Z'],
            ['unit' => '311', 'oldest_due' => '2022-01-01T05:00:00.000000Z'],
            ['unit' => '308', 'oldest_due' => '2022-01-01T05:00:00.000000Z'],
            ['unit' => '605', 'oldest_due' => '2020-11-17T05:00:00.000000Z'],
        ], ['humanize_values' => true, 'locale' => 'en']);
        $times = $this->renderer->render([
            ['supplier' => 'BFL', 'oldest_due' => '2022-06-29T17:51:14.000000Z'],
            ['supplier' => 'Roli-Vert', 'oldest_due' => '2022-06-29T17:52:02.000000Z'],
            ['supplier' => 'Hydro', 'oldest_due' => '2022-06-30T19:45:23.000000Z'],
            ['supplier' => 'Arken', 'oldest_due' => '2021-11-14T05:00:00.000000Z'],
        ], ['humanize_values' => true, 'locale' => 'en']);

        $this->assertStringContainsString('| 305 | 2021-12-01 |', $dates['markdown']);
        $this->assertStringContainsString('| 605 | 2020-11-17 |', $dates['markdown']);
        $this->assertStringNotContainsString(':00', $dates['markdown']);
        $this->assertStringContainsString('| BFL | 2022-06-29 13:51 |', $times['markdown']);
        $this->assertStringContainsString('| Arken | 2021-11-14 |', $times['markdown']);
    }

    /**
     * @test D2 (eval p16-B digest t1): ONE record reads as a compact "Field: value" block — the
     * same headers and cells as the table (humanized values, money, markers, redaction), one
     * field per line, never a one-row table.
     */
    public function the_record_layout_lists_one_field_per_line(): void
    {
        config(['ai.response_generation.currency_symbol' => '$']);

        $out = $this->renderer->render([[
            'meeting_id' => 140,
            'meeting_name' => 'Assemblée générale annuelle',
            'date' => '2023-10-24 16:00:00',
            'union_name' => 'SDC du 1240 St-Sébastien',
            'budget' => 1250.5,
            'place' => null,
        ]], ['layout' => 'record', 'humanize_values' => true, 'locale' => 'en', 'money_columns' => ['budget']]);

        $this->assertSame(
            "- **Meeting Name:** Assemblée générale annuelle\n"
            . "- **Date:** 2023-10-24 16:00\n"
            . "- **Union Name:** SDC du 1240 St-Sébastien\n"
            . "- **Budget:** \$1,250.50\n"
            . '- **Place:** —',
            $out['markdown']
        );
        $this->assertSame(['Meeting Name', 'Date', 'Union Name', 'Budget', 'Place'], $out['headers']);
        $this->assertSame(1, $out['shown']);
    }

    /** @test P-5 holds in the record layout: tenant-entered markdown stays plain text; the marker is appended. */
    public function the_record_layout_escapes_markdown_and_marks_values(): void
    {
        $out = $this->renderer->render(
            [['name' => '[Verify](https://phish.example) *now*', 'contact_email' => 'julie@novo.test']],
            ['layout' => 'record', 'marked_values' => ['julie@novo.test' => "that's you"]]
        );

        $this->assertStringContainsString('- **Name:** \[Verify\]\(https://phish.example\) \*now\*', $out['markdown']);
        $this->assertStringContainsString("- **Contact Email:** julie@novo.test \(that's you\)", $out['markdown']);
        $this->assertSame(1, $out['marked']);
    }

    /** @test The LLM-facing (masked) record render keeps tokens readable, like the table. */
    public function the_record_layout_keeps_tokens_readable_when_unescaped(): void
    {
        $out = $this->renderer->render(
            [['name' => 'Novo', 'contact_email' => '[email#1]']],
            ['layout' => 'record', 'redact' => false, 'escape_markdown' => false]
        );

        $this->assertStringContainsString('- **Contact Email:** [email#1]', $out['markdown']);
    }

    // =========================================================================
    // G3 (eval p16-A team-info / 310 / board): COLUMNS EMPTY ON EVERY ROW
    // =========================================================================

    /**
     * @test The export's emptiness semantics (ExportResponseComposer::withoutEmptyColumns):
     * null / a blank string / an empty list are empty; 0, false and "0" are VALUES; a row
     * missing the key counts as empty for it.
     */
    public function empty_columns_are_those_without_a_value_on_any_row(): void
    {
        $rows = [
            ['name' => 'A', 'role' => null, 'notes' => '', 'tags' => [], 'count' => 0, 'active' => false, 'code' => '0', 'late' => null],
            ['name' => 'B', 'role' => '  ', 'notes' => null, 'tags' => [], 'count' => 0, 'active' => false, 'code' => '0'],
            ['name' => 'C', 'role' => null, 'notes' => '', 'tags' => [], 'count' => 0, 'active' => false, 'code' => '0', 'late' => 'x'],
        ];

        $this->assertSame(['role', 'notes', 'tags'], $this->renderer->emptyColumns($rows));
        $this->assertSame([], $this->renderer->emptyColumns([]));
    }

    /** @test omit_columns leaves the named columns out of headers, cells and the column list. */
    public function omitted_columns_are_left_out_of_the_table(): void
    {
        $out = $this->renderer->render([
            ['union_name' => 'Alpha', 'c.first_name' => 'Amélie', 'cur.bm_role' => null, 'cur.year' => 2022],
            ['union_name' => 'Beta', 'c.first_name' => 'Denis', 'cur.bm_role' => null, 'cur.year' => 2021],
        ], ['omit_columns' => ['cur.bm_role']]);

        $this->assertSame(['union_name', 'c.first_name', 'cur.year'], $out['columns']);
        $this->assertSame(['Union Name', 'First Name', 'Year'], $out['headers']);
        $this->assertSame("| Union Name | First Name | Year |\n| --- | --- | --- |\n| Alpha | Amélie | 2022 |\n| Beta | Denis | 2021 |", $out['markdown']);
    }

    /** @test A header told apart by its node label keeps that name when its twin is omitted. */
    public function an_omitted_column_does_not_rename_the_remaining_headers(): void
    {
        $out = $this->renderer->render(
            [['c.name' => 'Julie', 'u.name' => null], ['c.name' => 'Luc', 'u.name' => null]],
            ['omit_columns' => ['u.name'], 'variable_labels' => ['c' => 'Contact', 'u' => 'Union']]
        );

        $this->assertSame(['Contact Name'], $out['headers']);
    }

    /** @test Omitting every column would leave nothing: the table is rendered whole instead. */
    public function omitting_every_column_is_ignored(): void
    {
        $out = $this->renderer->render(
            [['a' => null, 'b' => null], ['a' => null, 'b' => null]],
            ['omit_columns' => ['a', 'b']]
        );

        $this->assertSame(['A', 'B'], $out['headers']);
    }

    /** @test The record layout drops an omitted field instead of printing it as "—". */
    public function the_record_layout_leaves_omitted_fields_out(): void
    {
        $out = $this->renderer->render(
            [['team_name' => 'Novo Gestion', 'team_address' => null, 'contact_phone' => '514-400-7414']],
            ['layout' => 'record', 'omit_columns' => ['team_address']]
        );

        $this->assertSame("- **Team Name:** Novo Gestion\n- **Contact Phone:** 514-400-7414", $out['markdown']);
    }

    /**
     * @test G1: which node variable a result column is projected from (`v.prop AS alias`, a
     * bare `v.prop`, aliases minted in a WITH) — the projection parse RecordResolver uses to
     * tell a record's own columns from another node's. An expression has no single owner.
     */
    public function column_variables_come_from_the_cyphers_projections(): void
    {
        $cypher = "MATCH (cur:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) WHERE un.name <> 'x.y AS z' "
            . 'MATCH (cur)-[:BELONGS_TO_CONTACT]->(c:Contact) WITH un.city AS city, c, cur, un '
            . "RETURN un.name AS union_name, c.first_name, cur.bm_role, city, trim(c.first_name + ' ' + c.last_name) AS full_name, "
            . 'count(cur) AS seats, c.id AS contact_id';

        $variables = TableRenderer::columnVariables($cypher);

        $this->assertSame('un', $variables['union_name']);
        $this->assertSame('c', $variables['c.first_name']);
        $this->assertSame('cur', $variables['cur.bm_role']);
        $this->assertSame('un', $variables['city']);
        $this->assertSame('c', $variables['contact_id']);
        $this->assertArrayNotHasKey('full_name', $variables);
        $this->assertArrayNotHasKey('seats', $variables);
        $this->assertArrayNotHasKey('z', $variables);
        $this->assertSame([], TableRenderer::columnVariables(null));
    }

    // =========================================================================
    // Property headers (eval p17-B cold-roster-excel: `u.email AS contact_info` headed
    // the emails "Contact Info"; p13 `u.email AS contact`; p14 `n.due_amount AS contribution`)
    // =========================================================================

    /**
     * @test Result column => [variable, property] for the items the FINAL RETURN projects as
     * exactly `var.prop [AS alias]`. A function, an expression, an aggregate, or an alias
     * carried from a WITH is not a plain property projection.
     */
    public function column_projections_are_the_plain_property_items_of_the_final_return(): void
    {
        $cypher = "MATCH (u:User)-[:IN]->(un:Union) WHERE un.name <> 'x.y AS z' "
            . 'MATCH (c:Contact)-[:OF]->(u) WITH un.city AS city, u, c, un '
            . "RETURN u.name AS name, u.email AS contact_info, c.first_name, city, round(u.balance, 2) AS balance, "
            . "u.first_name + ' ' + u.last_name AS member, count(c) AS contacts, tr.role AS `rôle`, u.id AS user_id "
            . 'ORDER BY u.name LIMIT 100';

        // [variable, property, the variable's node label (null: `tr` is bound to no node pattern)]
        $this->assertSame([
            'name' => ['u', 'name', 'User'],
            'contact_info' => ['u', 'email', 'User'],
            'c.first_name' => ['c', 'first_name', 'Contact'],
            'rôle' => ['tr', 'role', null],
            'user_id' => ['u', 'id', 'User'],
        ], TableRenderer::columnProjections($cypher));
        $this->assertSame([], TableRenderer::columnProjections(null));
        $this->assertSame([], TableRenderer::columnProjections('   '));
    }

    /** @test Two UNION branches may fill one column from different properties: no projection is claimed. */
    public function a_union_query_has_no_column_projections(): void
    {
        $this->assertSame([], TableRenderer::columnProjections(
            'MATCH (u:User) RETURN u.email AS contact UNION MATCH (c:Contact) RETURN c.phone AS contact'
        ));
    }

    /** @test THE defect: an alias that shares no word with the property it holds is headed by the PROPERTY. */
    public function an_alias_sharing_no_token_with_its_property_is_headed_by_the_property(): void
    {
        $projections = TableRenderer::columnProjections(
            'MATCH (u:User) WHERE u.current_team_id = 3 RETURN u.name AS name, u.email AS contact_info, u.id AS user_id LIMIT 100'
        );

        $out = $this->renderer->render(
            [['name' => 'Julie Rousseau', 'contact_info' => 'j@x.test', 'user_id' => 13]],
            ['projections' => $projections]
        );

        $this->assertSame(['Name', 'Email'], $out['headers']);
        $this->assertSame(['name', 'contact_info'], $out['columns'], 'row keys are never rewritten');
        $this->assertStringContainsString('| Name | Email |', $out['markdown']);

        $this->assertSame('Email', $this->renderer->label('contact_info', [], $projections));
        $this->assertSame('Contact Info', $this->renderer->label('contact_info'), 'without the projection map the alias stands');
        $this->assertSame(
            'Due Amount',
            $this->renderer->label('contribution', [], TableRenderer::columnProjections('MATCH (n:Invoice) RETURN n.due_amount AS contribution'))
        );
        $this->assertSame(
            'Email',
            $this->renderer->label('contact', [], TableRenderer::columnProjections('MATCH (u:User) RETURN u.email AS contact'))
        );
    }

    /**
     * @test An alias that shares a word with its property is the generator's disambiguated name
     * and stays: words are split at `_`, camelCase and digit boundaries and compared after
     * AliasNormalizer folding (case, accents, plural mark).
     *
     * @dataProvider aliasesKeptProvider
     */
    public function an_alias_sharing_a_token_with_its_property_is_kept(string $projection, string $column, string $header): void
    {
        $projections = TableRenderer::columnProjections("MATCH (n:Node) RETURN {$projection}");

        $this->assertArrayHasKey($column, $projections);
        $this->assertSame($header, $this->renderer->label($column, [], $projections));
    }

    public static function aliasesKeptProvider(): array
    {
        return [
            'member_name ↔ name' => ['n.name AS member_name', 'member_name', 'Member Name'],
            'union_name ↔ name' => ['n.name AS union_name', 'union_name', 'Union Name'],
            'unit_number ↔ number' => ['n.number AS unit_number', 'unit_number', 'Unit Number'],
            'last_login ↔ last_logged_at' => ['n.last_logged_at AS last_login', 'last_login', 'Last Login'],
            'same word' => ['n.email AS email', 'email', 'Email'],
            'plural' => ['n.email AS emails', 'emails', 'Emails'],
            'accent' => ['n.role AS rôle', 'rôle', 'Rôle'],
            'camelCase alias' => ['n.name AS memberName', 'memberName', 'Member Name'],
            'camelCase property' => ['n.dueAmount AS amount_due', 'amount_due', 'Amount Due'],
            'digit boundary' => ['n.address1 AS street_address', 'street_address', 'Street Address'],
            'case' => ['n.Email AS EMAIL', 'EMAIL', 'EMAIL'],
        ];
    }

    /** @test A shared DIGIT is not a shared word: `line2` says nothing about `address2`. */
    public function a_shared_digit_alone_does_not_keep_the_alias(): void
    {
        $projections = TableRenderer::columnProjections('MATCH (n:Node) RETURN n.address2 AS line2');

        $this->assertSame('Address2', $this->renderer->label('line2', [], $projections));
    }

    /** @test An aggregate or any other expression has no single property: its alias is the header. */
    public function an_aggregate_or_expression_alias_is_kept(): void
    {
        $cypher = 'MATCH (u:User)-[:HAS]->(i:Invoice) '
            . "RETURN u.first_name + ' ' + u.last_name AS member, count(i) AS open_invoices, "
            . 'round(sum(i.due_amount), 2) AS contribution, min(i.due_at) AS oldest, toUpper(u.email) AS contact';

        $out = $this->renderer->render(
            [['member' => 'Julie R', 'open_invoices' => 2, 'contribution' => 10.5, 'oldest' => '2026-01-01', 'contact' => 'J@X.TEST']],
            ['projections' => TableRenderer::columnProjections($cypher)]
        );

        $this->assertSame(['Member', 'Open Invoices', 'Contribution', 'Oldest', 'Contact'], $out['headers']);
    }

    /** @test F8 stays: an unaliased `v.prop` column is headed by its property, with or without the projection map. */
    public function unaliased_property_columns_are_unchanged_by_the_projection_map(): void
    {
        $cypher = 'MATCH (cur:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) MATCH (cur)-[:BELONGS_TO_CONTACT]->(c:Contact) '
            . 'RETURN un.name AS union_name, c.first_name, c.last_name, cur.bm_role, cur.year, c.id AS contact_id ORDER BY un.name';
        $rows = [['union_name' => 'Alpha', 'c.first_name' => 'Julie', 'c.last_name' => 'R', 'cur.bm_role' => 'president', 'cur.year' => 2025]];

        $with = $this->renderer->render($rows, [
            'variable_labels' => TableRenderer::variableLabels($cypher),
            'projections' => TableRenderer::columnProjections($cypher),
        ]);

        $this->assertSame(['Union Name', 'First Name', 'Last Name', 'Bm Role', 'Year'], $with['headers']);
        $this->assertSame($this->renderer->render($rows)['headers'], $with['headers']);
    }

    /** @test Two columns that end up with the same property header are told apart by node label, as F8 does. */
    public function colliding_property_derived_headers_are_prefixed_with_their_node_label(): void
    {
        $cypher = 'MATCH (u:User)-[:IS]->(c:Contact) RETURN u.name AS name, u.email AS contact_info, c.email AS reach';
        $rows = [['name' => 'Julie', 'contact_info' => 'j@x.test', 'reach' => 'julie@home.test']];

        $out = $this->renderer->render($rows, [
            'variable_labels' => TableRenderer::variableLabels($cypher),
            'projections' => TableRenderer::columnProjections($cypher),
        ]);
        $this->assertSame(['Name', 'User Email', 'Contact Email'], $out['headers']);

        // The projection map carries each variable's node label itself.
        $out = $this->renderer->render($rows, ['projections' => TableRenderer::columnProjections($cypher)]);
        $this->assertSame(['Name', 'User Email', 'Contact Email'], $out['headers']);

        // Without any node label the variable disambiguates.
        $out = $this->renderer->render($rows, ['projections' => ['contact_info' => ['u', 'email'], 'reach' => ['c', 'email']]]);
        $this->assertSame(['Name', 'U Email', 'C Email'], $out['headers']);
    }

    /**
     * @test An alias that names the RECORD the value belongs to — it shares a word with the
     * node label of its variable — is the generator's name for that record's column and stays:
     * `s.name AS supplier` reads "Supplier" (never a bare "Name"), the live `cd.value AS
     * contact_info` on a ContactDirectory reads "Contact Info" (never "Value"). Only an alias
     * tied to NEITHER the property NOR its record is replaced by the property.
     *
     * @dataProvider aliasesNamingTheRecordProvider
     */
    public function an_alias_naming_the_record_of_its_variable_is_kept(string $cypher, string $column, string $header): void
    {
        $this->assertSame($header, $this->renderer->label($column, [], TableRenderer::columnProjections($cypher)));
    }

    public static function aliasesNamingTheRecordProvider(): array
    {
        return [
            'supplier ↔ Supplier' => ['MATCH (b:Bill)-[:SUPPLIER]->(s:Supplier) RETURN s.name AS supplier, b.due_amount AS due_amount', 'supplier', 'Supplier'],
            'unit ↔ Unit' => ['MATCH (u:Unit) RETURN u.name AS unit', 'unit', 'Unit'],
            'contact_info ↔ ContactDirectory' => ['MATCH (cd:ContactDirectory) RETURN cd.value AS contact_info', 'contact_info', 'Contact Info'],
            'board_seat ↔ BoardMember' => ['MATCH (cur:BoardMember) RETURN cur.bm_role AS board_seat', 'board_seat', 'Board Seat'],
            'contact_info on a User' => ['MATCH (u:User) RETURN u.email AS contact_info', 'contact_info', 'Email'],
            'member on a User' => ['MATCH (u:User) RETURN u.name AS member', 'member', 'Name'],
            'no node pattern' => ['UNWIND $rows AS r RETURN r.email AS contact', 'contact', 'Email'],
        ];
    }

    /**
     * @test A property-derived header colliding with a column that keeps its own name is the one
     * told apart; the kept alias is never rewritten (existing F8 behaviour for aliased columns).
     */
    public function a_property_derived_header_colliding_with_a_kept_alias_is_the_one_told_apart(): void
    {
        $cypher = 'MATCH (u:User)-[:IS]->(c:Contact) RETURN c.email AS email, u.email AS contact_info';

        $out = $this->renderer->render(
            [['email' => 'c@x.test', 'contact_info' => 'u@x.test']],
            ['variable_labels' => TableRenderer::variableLabels($cypher), 'projections' => TableRenderer::columnProjections($cypher)]
        );

        $this->assertSame(['Email', 'User Email'], $out['headers']);
    }

    /** @test The same property of the same variable twice cannot be told apart by owner: the aliases head the columns. */
    public function property_derived_headers_that_cannot_be_told_apart_fall_back_to_their_aliases(): void
    {
        $cypher = 'MATCH (u:User) RETURN u.email AS contact_info, u.email AS reach';

        $out = $this->renderer->render(
            [['contact_info' => 'u@x.test', 'reach' => 'u@x.test']],
            ['variable_labels' => TableRenderer::variableLabels($cypher), 'projections' => TableRenderer::columnProjections($cypher)]
        );

        $this->assertSame(['Contact Info', 'Reach'], $out['headers']);
    }

    /** @test An explicit label override always wins over the property header. */
    public function explicit_labels_override_the_property_header(): void
    {
        $projections = TableRenderer::columnProjections('MATCH (u:User) RETURN u.email AS contact_info');

        $out = $this->renderer->render(
            [['contact_info' => 'u@x.test']],
            ['projections' => $projections, 'labels' => ['contact_info' => 'Courriel']]
        );

        $this->assertSame(['Courriel'], $out['headers']);
    }

    /** @test The record block and the export matrix read the same headers as the table. */
    public function the_record_layout_and_the_matrix_use_the_property_header(): void
    {
        $projections = TableRenderer::columnProjections('MATCH (u:User) RETURN u.name AS name, u.email AS contact_info');
        $rows = [['name' => 'Julie', 'contact_info' => 'j@x.test']];

        $record = $this->renderer->render($rows, ['layout' => 'record', 'projections' => $projections]);
        $this->assertSame("- **Name:** Julie\n- **Email:** j@x.test", $record['markdown']);

        [$headers] = $this->renderer->matrix($rows, ['redact' => false, 'projections' => $projections]);
        $this->assertSame(['Name', 'Email'], $headers);
    }

    /**
     * @test A property name is a word of the schema's language
     * (ai.response_generation.property_header_language, default en). In an answer in another
     * language the generator's localized alias is the only header in that language
     * (eval p16/p17 french-flow: `u.name AS membre` → "Membre", never "Name" in a French table).
     */
    public function property_headers_apply_only_to_answers_in_the_schemas_language(): void
    {
        $cypher = 'MATCH (u:User) RETURN u.name AS membre, u.email AS courriel';

        $projections = ['membre' => ['u', 'name', 'User'], 'courriel' => ['u', 'email', 'User']];
        $this->assertSame($projections, TableRenderer::headerProjections($cypher, 'en'));
        $this->assertSame($projections, TableRenderer::headerProjections($cypher, 'EN-ca'));
        $this->assertSame([], TableRenderer::headerProjections($cypher, 'fr'));

        // No question language: the app locale decides (the package's language policy).
        $this->assertNotSame([], TableRenderer::headerProjections($cypher, null));
        app()->setLocale('fr');
        try {
            $this->assertSame([], TableRenderer::headerProjections($cypher, null));
            $this->assertSame([], TableRenderer::headerProjections($cypher, ''));
        } finally {
            app()->setLocale('en');
        }

        config(['ai.response_generation.property_header_language' => 'fr']);
        $this->assertSame([], TableRenderer::headerProjections($cypher, 'en'));
        $this->assertNotSame([], TableRenderer::headerProjections($cypher, 'fr'));

        config(['ai.response_generation.property_header_language' => null]);
        $this->assertSame([], TableRenderer::headerProjections($cypher, 'en'), 'null turns property headers off');
    }

    // =========================================================================
    // HEADERS IN THE ANSWER'S LANGUAGE (eval p18-B french t0 / t1)
    // =========================================================================

    private function frenchHeaderLines(): void
    {
        // (Load the package's French lines first: addLines() on a locale not loaded yet
        // would mark it loaded and hide them.)
        app('translator')->get('ai.response.table_showing_of', [], 'fr');
        app('translator')->addLines(['*.Name' => 'Nom', '*.Email' => 'Courriel', '*.Role' => 'Rôle'], 'fr');
    }

    /**
     * @test eval p18-B french t0: `RETURN u.name AS name, u.email AS email, tr.role AS role` headed
     * a French roster "Name / Email / Role". A humanized header is HOST text: with a header
     * locale it is said through the host's translator (the header itself is the key, as for a
     * legend label) — and kept as it is when the host ships no line for it.
     */
    public function headers_are_translated_through_the_host_for_the_header_locale(): void
    {
        $this->frenchHeaderLines();
        $rows = [['name' => 'Julie', 'email' => 'j@x.test', 'role' => 'Lead', 'last_login' => '2023-11-24 22:21:37']];

        $table = $this->renderer->render($rows, ['header_locale' => 'fr']);
        $this->assertSame(['Nom', 'Courriel', 'Rôle', 'Last Login'], $table['headers']);
        $this->assertStringStartsWith('| Nom | Courriel | Rôle | Last Login |', $table['markdown']);
        $this->assertSame(['name', 'email', 'role', 'last_login'], $table['columns'], 'the row keys never change');

        $record = $this->renderer->render($rows, ['header_locale' => 'fr', 'layout' => 'record']);
        $this->assertStringStartsWith("- **Nom:** Julie\n- **Courriel:** j@x.test", $record['markdown']);

        [$headers] = $this->renderer->matrix($rows, ['redact' => false, 'header_locale' => 'fr']);
        $this->assertSame(['Nom', 'Courriel', 'Rôle', 'Last Login'], $headers);

        $this->assertSame(['Nom', 'Courriel'], $this->renderer->headers(['name', 'email'], [], [], [], 'fr'));
    }

    /** @test Without a header locale nothing is translated (every existing caller). */
    public function without_a_header_locale_headers_are_not_translated(): void
    {
        $this->frenchHeaderLines();
        app()->setLocale('fr');
        try {
            $rows = [['name' => 'Julie', 'email' => 'j@x.test']];
            $this->assertSame(['Name', 'Email'], $this->renderer->render($rows)['headers']);
            $this->assertSame(['Name', 'Email'], $this->renderer->headers(['name', 'email']));
        } finally {
            app()->setLocale('en');
        }
    }

    /** @test An explicit label is the caller's own header: never rewritten. */
    public function an_explicit_label_is_never_translated(): void
    {
        $this->frenchHeaderLines();

        $table = $this->renderer->render([['name' => 'Julie', 'email' => 'j@x.test']], ['header_locale' => 'fr', 'labels' => ['name' => 'Name']]);

        $this->assertSame(['Name', 'Courriel'], $table['headers']);
    }

    /**
     * @test Headers are told apart BEFORE they are translated (the property / node-label
     * rules read schema words), and a translation that would make two headers read the same
     * is not applied to them.
     */
    public function a_translation_never_makes_two_headers_collide(): void
    {
        $this->frenchHeaderLines();
        app('translator')->addLines(['*.Full Name' => 'Nom'], 'fr');

        $this->assertSame(
            ['Name', 'Full Name', 'Courriel'],
            $this->renderer->headers(['name', 'full_name', 'email'], [], [], [], 'fr')
        );

        // Colliding unaliased projections are told apart by their node label first.
        $this->assertSame(
            ['Contact Name', 'Union Name'],
            $this->renderer->headers(['c.name', 'un.name'], [], ['c' => 'Contact', 'un' => 'Union'], [], 'fr')
        );
    }

    /** @test The header of ONE column, as a table in that language heads it. */
    public function a_single_header_can_be_translated(): void
    {
        $this->frenchHeaderLines();

        $this->assertSame('Courriel', $this->renderer->translateHeader('Email', 'fr'));
        $this->assertSame('Email', $this->renderer->translateHeader('Email', null));
        $this->assertSame('Last Login', $this->renderer->translateHeader('Last Login', 'fr'));
    }

    /** @test Tenant-entered text put in a code-written sentence is escaped like a table cell (P-5). */
    public function inline_text_is_markdown_escaped(): void
    {
        $this->assertSame('\[Verify\]\(https://phish.test\)', $this->renderer->escapeInline('[Verify](https://phish.test)'));
        $this->assertSame('310 | A', $this->renderer->escapeInline(" 310 |\n A "));
    }

    // =========================================================================
    // A NAME SPLIT OVER SEVERAL PROPERTIES IS ONE COLUMN (eval p19-A board)
    // =========================================================================

    /** The p19-A board query: `c.first_name, c.last_name` beside the union and the seat's year. */
    private const BOARD_CYPHER = 'MATCH (cur:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) MATCH (cur)-[:BELONGS_TO_CONTACT]->(c:Contact) '
        . 'RETURN un.name AS union_name, c.first_name, c.last_name, cur.bm_role, cur.year, c.id AS contact_id ORDER BY un.name';

    /** The host's Contact: a name split over two properties (metadata.name_properties). */
    private function declareContactName(array $properties = ['first_name', 'last_name']): void
    {
        config(['entities' => [
            'App\\Models\\Contact' => ['graph' => ['label' => 'Contact'], 'metadata' => ['name_properties' => $properties]],
            'App\\Models\\Union' => ['graph' => ['label' => 'Union'], 'metadata' => []],
            'App\\Models\\User' => ['graph' => ['label' => 'User'], 'metadata' => []],
        ]]);
    }

    /** @return array<int, array<string, mixed>> the first rows of the p19-A board trace */
    private function boardRows(): array
    {
        return [
            ['union_name' => '825 de Bruxelles', 'c.first_name' => 'Amélie', 'c.last_name' => 'Quirion', 'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => 779],
            ['union_name' => '825 de Bruxelles', 'c.first_name' => 'Mauricio', 'c.last_name' => 'Ruiz', 'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => 1436],
        ];
    }

    /**
     * @test eval p19-A board, replayed: "First Name | Last Name" for one person. The entity declares
     * the properties of its name; the query projects both from the same variable — one column.
     */
    public function p19_a_board_the_declared_name_parts_of_one_variable_merge_into_one_column(): void
    {
        $this->declareContactName();

        $merges = TableRenderer::nameMerges(self::BOARD_CYPHER, $this->boardRows());

        $this->assertSame([[
            'key' => 'c.name',
            'columns' => ['c.first_name', 'c.last_name'],
            'variable' => 'c',
            'label' => 'Contact',
            'properties' => ['first_name', 'last_name'],
        ]], $merges);

        $rows = $this->renderer->mergeColumns($this->boardRows(), $merges);
        $this->assertSame(['union_name', 'c.name', 'cur.bm_role', 'cur.year', 'contact_id'], array_keys($rows[0]));
        $this->assertSame('Amélie Quirion', $rows[0]['c.name']);
        $this->assertSame('Mauricio Ruiz', $rows[1]['c.name']);

        $rendered = $this->renderer->render($rows, ['variable_labels' => TableRenderer::variableLabels(self::BOARD_CYPHER), 'omit_columns' => ['cur.bm_role']]);
        $this->assertStringStartsWith("| Union Name | Name | Year |\n| --- | --- | --- |\n| 825 de Bruxelles | Amélie Quirion | 2022 |", $rendered['markdown']);
    }

    /** @test The parts are joined in the DECLARED order, at the place of the first one projected; an empty part is left out. */
    public function the_name_is_joined_in_the_declared_order_whatever_the_query_projected(): void
    {
        $this->declareContactName();
        $cypher = 'MATCH (c:Contact) RETURN c.last_name AS last_name, c.email AS email, c.first_name AS first_name';
        $rows = [
            ['last_name' => 'Quirion', 'email' => 'a@x.test', 'first_name' => 'Amélie'],
            ['last_name' => 'Ruiz', 'email' => 'm@x.test', 'first_name' => null],
            ['last_name' => '  ', 'email' => 'n@x.test', 'first_name' => null],
        ];

        $merged = $this->renderer->mergeColumns($rows, TableRenderer::nameMerges($cypher, $rows));

        $this->assertSame(['c.name' => 'Amélie Quirion', 'email' => 'a@x.test'], $merged[0]);
        $this->assertSame(['c.name' => 'Ruiz', 'email' => 'm@x.test'], $merged[1]);
        $this->assertSame(['c.name' => null, 'email' => 'n@x.test'], $merged[2], 'no part at all: no name, so an all-empty column is still seen as empty');
    }

    /**
     * @test
     * @dataProvider notMerged
     */
    public function the_parts_stay_separate_when_the_name_is_not_whole(string $cypher, array $rows, array $properties = ['first_name', 'last_name']): void
    {
        $this->declareContactName($properties);

        $this->assertSame([], TableRenderer::nameMerges($cypher, $rows));
        $this->assertSame($rows, $this->renderer->mergeColumns($rows, []));
    }

    /** @return array<string, array{0: string, 1: array<int, array<string, mixed>>, 2?: list<string>}> */
    public static function notMerged(): array
    {
        return [
            'only one part is projected' => [
                'MATCH (c:Contact) RETURN c.first_name AS first_name, c.email AS email',
                [['first_name' => 'Amélie', 'email' => 'a@x.test']],
            ],
            'the parts come from two variables (two different people)' => [
                'MATCH (a:Contact)-[:SPOUSE]->(b:Contact) RETURN a.first_name AS first_name, b.last_name AS last_name',
                [['first_name' => 'Amélie', 'last_name' => 'Ruiz']],
            ],
            'a part is an expression, not a plain property' => [
                "MATCH (c:Contact) RETURN toUpper(c.first_name) AS first_name, c.last_name AS last_name",
                [['first_name' => 'AMÉLIE', 'last_name' => 'Quirion']],
            ],
            'the entity declares no split name' => [
                'MATCH (u:User) RETURN u.first_name AS first_name, u.last_name AS last_name',
                [['first_name' => 'Julie', 'last_name' => 'Rousseau']],
            ],
            'a one-property name needs no merge' => [
                'MATCH (c:Contact) RETURN c.first_name AS first_name, c.last_name AS last_name',
                [['first_name' => 'Amélie', 'last_name' => 'Quirion']],
                ['first_name'],
            ],
            'a part was left out of the rows (redacted)' => [
                'MATCH (c:Contact) RETURN c.first_name AS first_name, c.last_name AS last_name',
                [['first_name' => 'Amélie']],
            ],
            'a part holds a collected list' => [
                'MATCH (c:Contact) RETURN c.first_name AS first_name, c.last_name AS last_name',
                [['first_name' => ['Amélie', 'Am'], 'last_name' => 'Quirion']],
            ],
            'the merged key is already a column' => [
                'MATCH (c:Contact) RETURN c.name, c.first_name, c.last_name',
                [['c.name' => 'A. Quirion', 'c.first_name' => 'Amélie', 'c.last_name' => 'Quirion']],
            ],
            'a UNION has no column projections' => [
                'MATCH (c:Contact) RETURN c.first_name AS first_name, c.last_name AS last_name UNION MATCH (c:Contact) RETURN c.first_name AS first_name, c.last_name AS last_name',
                [['first_name' => 'Amélie', 'last_name' => 'Quirion']],
            ],
        ];
    }

    /**
     * @test The merged column is keyed by what it holds: the qualifier the aliases share in front of
     * their property names it ("Owner Name" / "Tenant Name" — two contacts of one row stay two
     * names); aliases that say nothing more leave `<variable>.name`.
     */
    public function the_merged_key_keeps_the_qualifier_the_aliases_share(): void
    {
        $this->declareContactName();

        $two = 'MATCH (o:Contact)-[:RENTS_TO]->(t:Contact) RETURN o.first_name AS owner_first_name, o.last_name AS owner_last_name, '
            . 't.first_name AS tenantFirstName, t.last_name AS tenantLastName';
        $rows = [['owner_first_name' => 'Amélie', 'owner_last_name' => 'Quirion', 'tenantFirstName' => 'Mauricio', 'tenantLastName' => 'Ruiz']];
        $merged = $this->renderer->mergeColumns($rows, TableRenderer::nameMerges($two, $rows));
        $this->assertSame([['owner_name' => 'Amélie Quirion', 'tenant_name' => 'Mauricio Ruiz']], $merged);
        $this->assertSame(['Owner Name', 'Tenant Name'], $this->renderer->headers(array_keys($merged[0])));

        foreach ([
            'MATCH (c:Contact) RETURN c.first_name AS first_name, c.last_name AS last_name' => ['first_name' => 'A', 'last_name' => 'Q'],
            'MATCH (c:Contact) RETURN c.first_name AS prenom, c.last_name AS nom_de_famille' => ['prenom' => 'A', 'nom_de_famille' => 'Q'],
            'MATCH (c:Contact) RETURN c.first_name AS owner_first_name, c.last_name AS member_last_name' => ['owner_first_name' => 'A', 'member_last_name' => 'Q'],
        ] as $cypher => $row) {
            $this->assertSame('c.name', TableRenderer::nameMerges($cypher, [$row])[0]['key'], $cypher);
        }
    }

    /** @test A merged name is headed like any property column: "Name", told apart by its record when another column is one too. */
    public function a_merged_name_is_headed_name_and_told_apart_by_its_record(): void
    {
        $labels = ['c' => 'Contact', 'u' => 'User', 'un' => 'Union'];

        $this->assertSame(['Union Name', 'Name', 'Year'], $this->renderer->headers(['union_name', 'c.name', 'cur.year'], [], $labels));
        $this->assertSame(['Name', 'Contact Name'], $this->renderer->headers(['name', 'c.name'], [], $labels));
        $this->assertSame(['User Name', 'Contact Name'], $this->renderer->headers(['u.name', 'c.name'], [], $labels));

        $this->frenchHeaderLines();
        $this->assertSame(['Nom'], $this->renderer->headers(['c.name'], [], $labels, [], 'fr'));
    }
}
