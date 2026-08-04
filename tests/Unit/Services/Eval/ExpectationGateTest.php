<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Eval;

use Condoedge\Ai\DTOs\Eval\EvalRow;
use Condoedge\Ai\Services\Eval\ExpectationGate;
use PHPUnit\Framework\TestCase;

/**
 * Fact-gates are DETERMINISTIC and STRUCTURAL: they check row counts, column keys,
 * export payload markers, context availability, citations and latency — never
 * meaning. Each gate gets an explicit pass + fail case so a regression in any
 * single gate is caught in isolation.
 */
class ExpectationGateTest extends TestCase
{
    private ExpectationGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new ExpectationGate();
    }

    private function firstResult(array $results, string $gateName): array
    {
        foreach ($results as $result) {
            if ($result['gate'] === $gateName) {
                return $result;
            }
        }
        $this->fail("Gate '{$gateName}' not present in results.");
    }

    // ---- min_rows -------------------------------------------------------

    /** @test */
    public function min_rows_passes_when_row_count_meets_the_minimum(): void
    {
        $row = new EvalRow(rowCount: 3);
        $result = $this->firstResult($this->gate->evaluate(['min_rows' => 2], $row), 'min_rows');
        $this->assertTrue($result['passed']);
    }

    /** @test */
    public function min_rows_fails_when_row_count_is_below_the_minimum(): void
    {
        $row = new EvalRow(rowCount: 1);
        $result = $this->firstResult($this->gate->evaluate(['min_rows' => 2], $row), 'min_rows');
        $this->assertFalse($result['passed']);
    }

    // ---- columns_include ------------------------------------------------

    /** @test */
    public function columns_include_passes_when_all_columns_are_present_case_insensitively(): void
    {
        $row = new EvalRow(dataSample: [['Name' => 'Alice', 'ROLE' => 'admin', 'email' => 'a@x.com']]);
        $result = $this->firstResult(
            $this->gate->evaluate(['columns_include' => ['name', 'role', 'Email']], $row),
            'columns_include'
        );
        $this->assertTrue($result['passed']);
    }

    /** @test */
    public function columns_include_fails_when_a_column_is_missing(): void
    {
        $row = new EvalRow(dataSample: [['name' => 'Alice']]);
        $result = $this->firstResult(
            $this->gate->evaluate(['columns_include' => ['name', 'email']], $row),
            'columns_include'
        );
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('email', $result['detail']);
        // The failure names WHICH source was inspected.
        $this->assertStringContainsString('result rows', $result['detail']);
    }

    /** @test */
    public function columns_include_matches_the_concept_as_a_substring_of_prefixed_keys(): void
    {
        // Suites name the CONCEPT ('name'); generators alias it ('user_name',
        // 'member_name', 'Member Name') — substring matching must accept all.
        $row = new EvalRow(dataSample: [['user_name' => 'Alice', 'Member Email' => 'a@x.com', 'phone-number' => '555']]);
        $result = $this->firstResult(
            $this->gate->evaluate(['columns_include' => ['name', 'email', 'phone_number']], $row),
            'columns_include'
        );
        $this->assertTrue($result['passed']);
    }

    /** @test */
    public function columns_include_inspects_the_export_payload_header_when_no_result_rows_exist(): void
    {
        // Export-from-previous turns are legitimately not backed by result rows:
        // the export payload's table header row is the inspectable contract.
        $row = new EvalRow(dataSample: []);
        $row->responseMetadata = [
            'export_intent' => true,
            'export_format' => 'excel',
            'export_document_markdown' => "# Team Roster\n\nAs of: 2026-07-23\n\n| User Name | User Email | Role |\n| --- | --- | --- |\n| Alice | a@x.com | Owner |",
        ];

        $result = $this->firstResult(
            $this->gate->evaluate(['columns_include' => ['name', 'email']], $row),
            'columns_include'
        );
        $this->assertTrue($result['passed']);
    }

    /** @test */
    public function columns_include_failure_from_the_export_payload_names_that_source_honestly(): void
    {
        $row = new EvalRow(dataSample: []);
        $row->responseMetadata = [
            'export_document_markdown' => "# X\n\n| Name |\n| --- |\n| Alice |",
        ];

        $result = $this->firstResult(
            $this->gate->evaluate(['columns_include' => ['email']], $row),
            'columns_include'
        );
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('export payload header row', $result['detail']);
    }

    /** @test */
    public function columns_include_with_no_rows_and_no_payload_says_so(): void
    {
        $row = new EvalRow(dataSample: []);
        $result = $this->firstResult(
            $this->gate->evaluate(['columns_include' => ['name']], $row),
            'columns_include'
        );
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('no result rows and no export payload', $result['detail']);
    }

    // ---- produces_file --------------------------------------------------

    /** @test */
    public function produces_file_passes_on_a_matching_export_payload(): void
    {
        $row = new EvalRow();
        $row->responseMetadata = [
            'export_intent' => true,
            'export_format' => 'excel',
            'export_document_markdown' => "# Team\n\n| Name |\n| --- |\n| Alice |",
        ];

        $result = $this->firstResult($this->gate->evaluate(['produces_file' => 'excel'], $row), 'produces_file');
        $this->assertTrue($result['passed']);
    }

    /** @test */
    public function produces_file_fails_when_the_response_is_not_an_export_of_that_format(): void
    {
        $row = new EvalRow();
        $row->responseMetadata = ['export_intent' => true, 'export_format' => 'pdf', 'export_document_markdown' => '# X'];

        $result = $this->firstResult($this->gate->evaluate(['produces_file' => 'excel'], $row), 'produces_file');
        $this->assertFalse($result['passed']);

        // A plain non-export answer fails too.
        $plain = new EvalRow();
        $result = $this->firstResult($this->gate->evaluate(['produces_file' => 'excel'], $plain), 'produces_file');
        $this->assertFalse($result['passed']);
    }

    // ---- previous_result_available -------------------------------------

    /** @test */
    public function previous_result_available_passes_only_when_the_turn_received_a_sample(): void
    {
        $row = new EvalRow();
        $row->conversationContextMeta = ['last_result_sample_count' => 4];
        $result = $this->firstResult(
            $this->gate->evaluate(['previous_result_available' => true], $row),
            'previous_result_available'
        );
        $this->assertTrue($result['passed']);
    }

    /** @test */
    public function previous_result_available_fails_when_no_sample_was_threaded_in(): void
    {
        $row = new EvalRow();
        $row->conversationContextMeta = ['last_result_sample_count' => 0];
        $result = $this->firstResult(
            $this->gate->evaluate(['previous_result_available' => true], $row),
            'previous_result_available'
        );
        $this->assertFalse($result['passed']);
    }

    // ---- cites_document -------------------------------------------------

    /** @test */
    public function cites_document_passes_when_the_response_carries_file_citations(): void
    {
        $row = new EvalRow();
        $row->referencedFiles = [['id' => 9, 'filename' => 'bylaws.pdf']];
        $result = $this->firstResult($this->gate->evaluate(['cites_document' => true], $row), 'cites_document');
        $this->assertTrue($result['passed']);
    }

    /** @test */
    public function cites_document_fails_when_no_files_are_cited(): void
    {
        $row = new EvalRow();
        $result = $this->firstResult($this->gate->evaluate(['cites_document' => true], $row), 'cites_document');
        $this->assertFalse($result['passed']);
    }

    // ---- max_latency_ms -------------------------------------------------

    /** @test */
    public function max_latency_ms_passes_under_the_budget_and_fails_over_it(): void
    {
        $row = new EvalRow();
        $row->totalDurationMs = 900;

        $pass = $this->firstResult($this->gate->evaluate(['max_latency_ms' => 1000], $row), 'max_latency_ms');
        $this->assertTrue($pass['passed']);

        $fail = $this->firstResult($this->gate->evaluate(['max_latency_ms' => 500], $row), 'max_latency_ms');
        $this->assertFalse($fail['passed']);
    }

    // ---- discloses_total ------------------------------------------------

    /** @test */
    public function discloses_total_passes_when_the_answer_states_the_total_and_the_shown_count(): void
    {
        $row = new EvalRow(rowCount: 100, answer: 'Your team has 100 members; here are the first 10.');
        $row->dataSample = array_fill(0, 10, ['n' => 1]);

        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $row), 'discloses_total');
        $this->assertTrue($result['passed']);
    }

    /** @test */
    public function discloses_total_passes_when_the_answer_states_the_total_and_the_remainder(): void
    {
        $row = new EvalRow(rowCount: 100, answer: 'Your team has 100 members — 90 more are not listed.');
        $row->dataSample = array_fill(0, 10, ['n' => 1]);

        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $row), 'discloses_total');
        $this->assertTrue($result['passed']);
    }

    /** @test */
    public function discloses_total_fails_on_a_bare_total_without_shown_or_remainder(): void
    {
        // Pinned from the 2026-07-23 iter-9 live failure: "more than 523 results
        // available" states the total (523) but neither the shown count (10) nor
        // the remainder (513) — the reader cannot tell how much was withheld and
        // "more than" reads as an approximation. TOTAL alone is no longer clean.
        $row = new EvalRow(rowCount: 523, answer: 'There are more than 523 results available.');
        $row->dataSample = array_fill(0, 10, ['n' => 1]);
        $row->responseMetadata = ['result_total' => 523, 'result_shown' => 10];

        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $row), 'discloses_total');
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('523', $result['detail']);
        $this->assertStringContainsString('513', $result['detail']);
    }

    /** @test */
    public function discloses_total_fails_on_a_bare_remainder_without_the_total(): void
    {
        $row = new EvalRow(rowCount: 100, answer: 'Ten members shown — 90 more are not listed.');
        $row->dataSample = array_fill(0, 10, ['n' => 1]);

        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $row), 'discloses_total');
        $this->assertFalse($result['passed']);
    }

    /** @test */
    public function discloses_total_fails_when_a_truncated_list_ships_no_numbers(): void
    {
        $row = new EvalRow(rowCount: 100, answer: 'Here are the members of your team.');
        $row->dataSample = array_fill(0, 10, ['n' => 1]);

        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $row), 'discloses_total');
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('100', $result['detail']);
    }

    /** @test */
    public function discloses_total_uses_the_companion_count_result_total_over_the_row_count(): void
    {
        // 100 retrieved rows are the cap; the companion count knows 250 exist.
        // Quoting "100" is NOT the true total nor the remainder (240) — fail.
        $row = new EvalRow(rowCount: 100, answer: 'Your team has 100 members.');
        $row->dataSample = array_fill(0, 10, ['n' => 1]);
        $row->responseMetadata = ['result_total' => 250];

        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $row), 'discloses_total');
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('250', $result['detail']);
    }

    /** @test */
    public function discloses_total_trivially_passes_when_nothing_was_withheld(): void
    {
        $row = new EvalRow(rowCount: 4, answer: 'All four members are listed above.');
        $row->dataSample = array_fill(0, 4, ['n' => 1]);

        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $row), 'discloses_total');
        $this->assertTrue($result['passed']);
    }

    /** @test */
    public function discloses_total_never_confuses_a_number_inside_a_longer_number(): void
    {
        // "1000" must not satisfy a required "100".
        $row = new EvalRow(rowCount: 100, answer: 'The building is from 1000 AD.');
        $row->dataSample = array_fill(0, 10, ['n' => 1]);

        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $row), 'discloses_total');
        $this->assertFalse($result['passed']);
    }

    /** @test */
    public function discloses_total_rejects_the_ambiguous_remainder_equal_to_the_rendered_count(): void
    {
        // 20 rows, 10 shown: "here are the 10..." is the rendered count. Without
        // the exact TOTAL (20) stated, the disclosure is missing — the tightened
        // rule always requires the total.
        $row = new EvalRow(rowCount: 20, answer: 'Here are the 10 members of your team.');
        $row->dataSample = array_fill(0, 10, ['n' => 1]);

        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $row), 'discloses_total');
        $this->assertFalse($result['passed']);
    }

    /** @test */
    public function discloses_total_ignores_ordered_list_markers_and_dates_as_coincidental_numbers(): void
    {
        // remainder 2 must not be satisfied by the "2." of a numbered list.
        $list = new EvalRow(rowCount: 12, answer: "1. Alice\n2. Bob\n3. Carol");
        $list->dataSample = array_fill(0, 10, ['n' => 1]);
        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $list), 'discloses_total');
        $this->assertFalse($result['passed']);

        // remainder 23 must not be satisfied by a date fragment ("2026-07-23").
        $date = new EvalRow(rowCount: 33, answer: 'As of 2026-07-23, here are your team members.');
        $date->dataSample = array_fill(0, 10, ['n' => 1]);
        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $date), 'discloses_total');
        $this->assertFalse($result['passed']);

        // A genuine standalone remainder statement (with the total) still passes.
        $genuine = new EvalRow(rowCount: 12, answer: 'Of your 12 members, ten are shown — 2 more exist.');
        $genuine->dataSample = array_fill(0, 10, ['n' => 1]);
        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $genuine), 'discloses_total');
        $this->assertTrue($result['passed']);
    }

    /** @test */
    public function discloses_total_accepts_locale_grouped_totals(): void
    {
        foreach (['1,500', '1 500', '1.500'] as $grouped) {
            $row = new EvalRow(rowCount: 1500, answer: "Showing the first 10 of {$grouped} members.");
            $row->dataSample = array_fill(0, 10, ['n' => 1]);
            $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $row), 'discloses_total');
            $this->assertTrue($result['passed'], "Grouped form {$grouped} not accepted");
        }
    }

    /** @test */
    public function discloses_total_accepts_a_row_range_spanning_exactly_the_shown_count(): void
    {
        // "rows 1-10 of 523" IS a full disclosure: the reader sees the total and
        // the shown window. The range token must not be destroyed as noise.
        $row = new EvalRow(rowCount: 523, answer: 'Showing rows 1-10 of 523 members.');
        $row->dataSample = array_fill(0, 10, ['n' => 1]);
        $row->responseMetadata = ['result_total' => 523, 'result_shown' => 10];
        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $row), 'discloses_total');
        $this->assertTrue($result['passed']);

        // An offset window spanning the shown count discloses equally.
        $offset = new EvalRow(rowCount: 523, answer: 'Showing rows 41-50 of 523 members.');
        $offset->dataSample = array_fill(0, 10, ['n' => 1]);
        $offset->responseMetadata = ['result_total' => 523, 'result_shown' => 10];
        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $offset), 'discloses_total');
        $this->assertTrue($result['passed']);

        // A range whose span is NOT the shown count discloses nothing extra.
        $wrong = new EvalRow(rowCount: 523, answer: 'Showing rows 1-9 of 523 members.');
        $wrong->dataSample = array_fill(0, 10, ['n' => 1]);
        $wrong->responseMetadata = ['result_total' => 523, 'result_shown' => 10];
        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $wrong), 'discloses_total');
        $this->assertFalse($result['passed']);

        // Date tokens are still never mistaken for ranges (span 17 vs shown 17).
        $date = new EvalRow(rowCount: 523, answer: 'As of 2026-07-23, here are 523 rows.');
        $date->dataSample = array_fill(0, 17, ['n' => 1]);
        $date->responseMetadata = ['result_total' => 523, 'result_shown' => 17];
        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $date), 'discloses_total');
        $this->assertFalse($result['passed']);
    }

    /** @test */
    public function discloses_total_accepts_the_shown_over_total_fraction_token(): void
    {
        // "10/523 shown" states both numbers in one token.
        $row = new EvalRow(rowCount: 523, answer: '10/523 shown.');
        $row->dataSample = array_fill(0, 10, ['n' => 1]);
        $row->responseMetadata = ['result_total' => 523, 'result_shown' => 10];
        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $row), 'discloses_total');
        $this->assertTrue($result['passed']);

        // A fraction with the WRONG shown numerator stays structural noise.
        $wrong = new EvalRow(rowCount: 523, answer: '9/523 shown.');
        $wrong->dataSample = array_fill(0, 10, ['n' => 1]);
        $wrong->responseMetadata = ['result_total' => 523, 'result_shown' => 10];
        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $wrong), 'discloses_total');
        $this->assertFalse($result['passed']);
    }

    /** @test */
    public function discloses_total_reads_the_rendered_row_count_from_response_metadata(): void
    {
        // A host with setMaxDataItems(20) renders all 20 rows — nothing withheld.
        // The gate must honor the generator's own result_shown evidence instead
        // of assuming the driver's 10-row sample slice.
        $row = new EvalRow(rowCount: 20, answer: 'Here are your members.');
        $row->dataSample = array_fill(0, 10, ['n' => 1]);
        $row->responseMetadata = ['result_shown' => 20];

        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $row), 'discloses_total');
        $this->assertTrue($result['passed']);
    }

    // ---- no_self_contact ------------------------------------------------

    /** @test */
    public function no_self_contact_passes_when_the_own_email_carries_a_thats_you_marker(): void
    {
        $row = new EvalRow(answer: "The contact on file is j.rousseau@novogestion.ca — that's you.");
        $row->pinnedUserEmail = 'j.rousseau@novogestion.ca';

        $result = $this->firstResult($this->gate->evaluate(['no_self_contact' => true], $row), 'no_self_contact');
        $this->assertTrue($result['passed']);
    }

    /** @test */
    public function no_self_contact_fails_when_the_own_email_ships_unmarked(): void
    {
        $row = new EvalRow(answer: 'Your team can be reached at j.rousseau@novogestion.ca.');
        $row->pinnedUserEmail = 'j.rousseau@novogestion.ca';

        $result = $this->firstResult($this->gate->evaluate(['no_self_contact' => true], $row), 'no_self_contact');
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString("that's-you", $result['detail']);
    }

    /** @test */
    public function no_self_contact_is_case_insensitive_on_the_email(): void
    {
        $row = new EvalRow(answer: 'Contact J.Rousseau@Novogestion.CA for anything.');
        $row->pinnedUserEmail = 'j.rousseau@novogestion.ca';

        $result = $this->firstResult($this->gate->evaluate(['no_self_contact' => true], $row), 'no_self_contact');
        $this->assertFalse($result['passed']);
    }

    /** @test */
    public function no_self_contact_accepts_the_typographic_apostrophe_marker(): void
    {
        // "that’s you" (U+2019) is the common LLM typography for the marker.
        $row = new EvalRow(answer: "The contact on file is j.rousseau@novogestion.ca — that\u{2019}s you.");
        $row->pinnedUserEmail = 'j.rousseau@novogestion.ca';

        $result = $this->firstResult($this->gate->evaluate(['no_self_contact' => true], $row), 'no_self_contact');
        $this->assertTrue($result['passed']);
    }

    /** @test */
    public function no_self_contact_trivially_passes_when_the_email_is_absent_or_no_identity_is_pinned(): void
    {
        // Email absent from the answer.
        $row = new EvalRow(answer: 'Your team has 100 members.');
        $row->pinnedUserEmail = 'j.rousseau@novogestion.ca';
        $result = $this->firstResult($this->gate->evaluate(['no_self_contact' => true], $row), 'no_self_contact');
        $this->assertTrue($result['passed']);

        // No pinned identity at all.
        $bare = new EvalRow(answer: 'Reach j.rousseau@novogestion.ca.');
        $result = $this->firstResult($this->gate->evaluate(['no_self_contact' => true], $bare), 'no_self_contact');
        $this->assertTrue($result['passed']);
    }

    // ---- promised_columns_nonempty --------------------------------------

    /** @test */
    public function promised_columns_nonempty_passes_when_every_column_has_a_value_somewhere(): void
    {
        $row = new EvalRow();
        $row->responseMetadata = [
            'export_document_markdown' => "# Roster\n\n| Name | Email |\n| --- | --- |\n| Alice | a@x.com |\n| Bob |  |",
        ];

        $result = $this->firstResult(
            $this->gate->evaluate(['promised_columns_nonempty' => true], $row),
            'promised_columns_nonempty'
        );
        $this->assertTrue($result['passed']);
    }

    /** @test */
    public function promised_columns_nonempty_fails_when_a_promised_column_is_empty_in_every_row(): void
    {
        // The cold-roster live failure: a "Contact Info" column silently empty
        // in all rows.
        $row = new EvalRow();
        $row->responseMetadata = [
            'export_document_markdown' => "# Roster\n\n| Name | Contact Info |\n| --- | --- |\n| Alice |  |\n| Bob |  |",
        ];

        $result = $this->firstResult(
            $this->gate->evaluate(['promised_columns_nonempty' => true], $row),
            'promised_columns_nonempty'
        );
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Contact Info', $result['detail']);
    }

    /** @test */
    public function promised_columns_nonempty_treats_bare_null_placeholders_as_empty(): void
    {
        $row = new EvalRow();
        $row->responseMetadata = [
            'export_document_markdown' => "# Roster\n\n| Name | Phone |\n| --- | --- |\n| Alice | None |\n| Bob | null |",
        ];

        $result = $this->firstResult(
            $this->gate->evaluate(['promised_columns_nonempty' => true], $row),
            'promised_columns_nonempty'
        );
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Phone', $result['detail']);
    }

    /** @test */
    public function promised_columns_nonempty_fails_honestly_without_a_payload_or_data_rows(): void
    {
        // No export payload at all.
        $none = new EvalRow();
        $result = $this->firstResult(
            $this->gate->evaluate(['promised_columns_nonempty' => true], $none),
            'promised_columns_nonempty'
        );
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('no export payload', $result['detail']);

        // A header-only table (zero data rows).
        $empty = new EvalRow();
        $empty->responseMetadata = ['export_document_markdown' => "# X\n\n| Name |\n| --- |"];
        $result = $this->firstResult(
            $this->gate->evaluate(['promised_columns_nonempty' => true], $empty),
            'promised_columns_nonempty'
        );
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('no data rows', $result['detail']);
    }

    /** @test */
    public function export_table_parsing_honors_the_composers_escaped_pipes(): void
    {
        // Cell value 'Doe | Smith' is written as 'Doe \| Smith' by the composer's
        // escapeMarkdownCell. Splitting on the escaped pipe would shift cells
        // right and read 'Smith' as the Phone value — masking a genuinely empty
        // Phone column as filled (false pass).
        $row = new EvalRow();
        $row->responseMetadata = [
            'export_document_markdown' => "# Roster\n\n| Name | Phone |\n| --- | --- |\n| Doe \\| Smith |  |",
        ];

        $result = $this->firstResult(
            $this->gate->evaluate(['promised_columns_nonempty' => true], $row),
            'promised_columns_nonempty'
        );
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Phone', $result['detail']);
    }

    /** @test */
    public function trivial_gate_passes_are_marked_vacuous(): void
    {
        // Nothing withheld: discloses_total passes without inspecting the answer.
        $total = new EvalRow(rowCount: 4, answer: 'All four members are listed.');
        $total->dataSample = array_fill(0, 4, ['n' => 1]);
        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $total), 'discloses_total');
        $this->assertTrue($result['passed']);
        $this->assertTrue($result['vacuous'] ?? false);

        // Email absent: no_self_contact passes without inspecting anything.
        $self = new EvalRow(answer: 'Your team has 100 members.');
        $self->pinnedUserEmail = 'j.rousseau@novogestion.ca';
        $result = $this->firstResult($this->gate->evaluate(['no_self_contact' => true], $self), 'no_self_contact');
        $this->assertTrue($result['passed']);
        $this->assertTrue($result['vacuous'] ?? false);

        // A TRIGGERED pass carries no vacuous flag.
        $real = new EvalRow(rowCount: 100, answer: 'Your team has 100 members; 10 are shown.');
        $real->dataSample = array_fill(0, 10, ['n' => 1]);
        $result = $this->firstResult($this->gate->evaluate(['discloses_total' => true], $real), 'discloses_total');
        $this->assertTrue($result['passed']);
        $this->assertArrayNotHasKey('vacuous', $result);
    }

    // ---- disambiguation_column_present ----------------------------------

    /** @test */
    public function disambiguation_passes_when_rows_carry_a_second_identifying_column_and_differ(): void
    {
        // The homonym-safe projection: three units named "310", each row made
        // distinguishable by its union's name.
        $row = new EvalRow(dataSample: [
            ['unit_name' => '310', 'union_name' => 'SDC Alpha', 'balance' => 0],
            ['unit_name' => '310', 'union_name' => 'SDC Beta', 'balance' => 0],
            ['unit_name' => '310', 'union_name' => 'SDC Gamma', 'balance' => 0],
        ]);

        $result = $this->firstResult(
            $this->gate->evaluate(['disambiguation_column_present' => true], $row),
            'disambiguation_column_present'
        );
        $this->assertTrue($result['passed']);
        $this->assertArrayNotHasKey('vacuous', $result);
    }

    /** @test */
    public function disambiguation_fails_when_rows_project_only_one_column(): void
    {
        $row = new EvalRow(dataSample: [
            ['unit_name' => '310'],
            ['unit_name' => 'A-201'],
        ]);

        $result = $this->firstResult(
            $this->gate->evaluate(['disambiguation_column_present' => true], $row),
            'disambiguation_column_present'
        );
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('unit_name', $result['detail']);
        $this->assertStringContainsString('1 column', $result['detail']);
    }

    /** @test */
    public function disambiguation_fails_when_two_rows_are_fully_identical_and_names_them(): void
    {
        // Pinned from the 2026-07-23 iter-9 live failure: three homonymous "310"
        // rows identical in every projected column — the union column that would
        // distinguish them was lost at projection time.
        $row = new EvalRow(dataSample: [
            ['unit_name' => '310', 'balance' => 0, 'open_invoices' => 0],
            ['unit_name' => '310', 'balance' => 0, 'open_invoices' => 0],
            ['unit_name' => '310', 'balance' => 0, 'open_invoices' => 0],
        ]);

        $result = $this->firstResult(
            $this->gate->evaluate(['disambiguation_column_present' => true], $row),
            'disambiguation_column_present'
        );
        $this->assertFalse($result['passed']);
        // The failure detail names WHICH rows collided.
        $this->assertStringContainsString('rows 1, 2, 3', $result['detail']);
    }

    /** @test */
    public function disambiguation_inspects_the_export_payload_rows_when_no_result_sample_exists(): void
    {
        // Export-from-previous-turn (my-balance t1): the turn itself has no
        // result rows; the export payload's table rows are the evidence.
        $pass = new EvalRow(dataSample: []);
        $pass->responseMetadata = [
            'export_document_markdown' => "# Balance\n\n| Unit | Union | Balance |\n| --- | --- | --- |\n| 310 | SDC Alpha | 0 |\n| 310 | SDC Beta | 0 |",
        ];
        $result = $this->firstResult(
            $this->gate->evaluate(['disambiguation_column_present' => true], $pass),
            'disambiguation_column_present'
        );
        $this->assertTrue($result['passed']);

        // Identical payload rows fail, naming the source honestly.
        $fail = new EvalRow(dataSample: []);
        $fail->responseMetadata = [
            'export_document_markdown' => "# Balance\n\n| Unit | Balance |\n| --- | --- |\n| 310 | 0 |\n| 310 | 0 |",
        ];
        $result = $this->firstResult(
            $this->gate->evaluate(['disambiguation_column_present' => true], $fail),
            'disambiguation_column_present'
        );
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('export payload rows', $result['detail']);
    }

    /** @test */
    public function disambiguation_passes_vacuously_with_nothing_to_inspect_and_when_declared_false(): void
    {
        // Nothing to inspect is NOT a structural homonym failure: the suite's
        // standards allow clarify / "none recorded" answer paths that execute no
        // query (rubric 6). Turns that REQUIRE rows enforce that via min_rows.
        // The vacuous flag keeps the green from being read as verified compliance.
        $none = new EvalRow(dataSample: []);
        $result = $this->firstResult(
            $this->gate->evaluate(['disambiguation_column_present' => true], $none),
            'disambiguation_column_present'
        );
        $this->assertTrue($result['passed']);
        $this->assertTrue($result['vacuous'] ?? false);
        $this->assertStringContainsString('nothing', strtolower($result['detail']));

        $off = new EvalRow(dataSample: []);
        $result = $this->firstResult(
            $this->gate->evaluate(['disambiguation_column_present' => false], $off),
            'disambiguation_column_present'
        );
        $this->assertTrue($result['passed']);
        $this->assertTrue($result['vacuous'] ?? false);
    }

    /** @test */
    public function disambiguation_keeps_distinct_rows_distinct_when_values_defeat_json_encoding(): void
    {
        // Latin-1 bytes from a legacy import make json_encode return false; the
        // fingerprint must stay faithful so two DIFFERENT rows never collide.
        $row = new EvalRow(dataSample: [
            ['name' => "Caf\xE9 A", 'union' => 'X'],
            ['name' => "Caf\xE9 B", 'union' => 'Y'],
        ]);

        $result = $this->firstResult(
            $this->gate->evaluate(['disambiguation_column_present' => true], $row),
            'disambiguation_column_present'
        );
        $this->assertTrue($result['passed'], $result['detail'] ?? '');

        // Genuinely identical malformed rows still collide, with a readable detail.
        $dup = new EvalRow(dataSample: [
            ['name' => "Caf\xE9 A", 'union' => 'X'],
            ['name' => "Caf\xE9 A", 'union' => 'X'],
        ]);

        $result = $this->firstResult(
            $this->gate->evaluate(['disambiguation_column_present' => true], $dup),
            'disambiguation_column_present'
        );
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('rows 1, 2', $result['detail']);
    }

    /** @test */
    public function disambiguation_aligns_sparse_result_rows_over_the_full_column_union(): void
    {
        // Cypher rows may be sparse: a missing key and an explicit null are the
        // same projected value — these two rows ARE identical.
        $row = new EvalRow(dataSample: [
            ['unit_name' => '310', 'union_name' => null],
            ['unit_name' => '310'],
        ]);

        $result = $this->firstResult(
            $this->gate->evaluate(['disambiguation_column_present' => true], $row),
            'disambiguation_column_present'
        );
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('rows 1, 2', $result['detail']);
    }

    // ---- value validation for the new keys ------------------------------

    /** @test */
    public function the_new_gates_validate_as_booleans_fail_fast(): void
    {
        foreach (['discloses_total', 'no_self_contact', 'promised_columns_nonempty', 'disambiguation_column_present'] as $key) {
            try {
                ExpectationGate::assertValidValues([$key => 'yes'], 'test');
                $this->fail("Non-boolean {$key} should have thrown.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    // ---- unknown keys ---------------------------------------------------

    /** @test */
    public function evaluating_an_unknown_expect_key_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->gate->evaluate(['row_minimum' => 1], new EvalRow());
    }
}
