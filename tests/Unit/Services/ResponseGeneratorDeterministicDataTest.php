<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Deterministic data answers (Phase 8 batch A).
 *
 * Measured live failures this pins: gpt-4o-mini mis-added rows ($10,493.17 vs the
 * real $11,493.17), said "10 shown" while listing 5, merged three different units
 * named "310", and saw only a 10-row pretty-JSON sample. Now:
 *  - the LLM gets a stats line, a COMPACT masked pipe-table summary, the facts /
 *    system-computed totals as literal numbers, and writes ONLY the narrative
 *    with a {{table}} placeholder;
 *  - code renders the table from ALL rows (up to the display cap) plus an honest
 *    "Showing X of Y" line;
 *  - identifying fields are masked in everything the LLM sees and re-inserted
 *    deterministically in what the user sees.
 */
class ResponseGeneratorDeterministicDataTest extends TestCase
{
    private LlmProviderInterface $llm;
    private ResponseGenerator $generator;
    /** @var array<int, string> every user prompt the LLM received */
    private array $prompts = [];
    /** @var array<int, string|null> every system prompt the LLM received */
    private array $systems = [];

    public function setUp(): void
    {
        parent::setUp();

        config([
            'ai.response_generation.deterministic_tables' => true,
            'ai.response_generation.table_max_rows' => 25,
            'ai.access_control.mask_identifying_fields' => true,
            'ai.access_control.identifying_fields' => ['*' => ['email', 'phone']],
        ]);

        $this->llm = Mockery::mock(LlmProviderInterface::class);
        $this->generator = new ResponseGenerator($this->llm, [
            'default_format' => 'text',
            'default_style' => 'friendly',
            'default_max_length' => 200,
        ]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function llmReturns(string ...$answers): void
    {
        $queue = $answers;
        $this->llm->shouldReceive('complete')->andReturnUsing(function ($user, $system = null) use (&$queue) {
            $this->prompts[] = (string) $user;
            $this->systems[] = $system;

            return count($queue) > 1 ? array_shift($queue) : $queue[0];
        });
    }

    private function context(array $rows, array $meta = [], string $cypher = 'MATCH (b:Bill) RETURN b'): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = 'What do we owe?';
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'en');
        $ctx->queryResult = new QueryGenerationResult(cypher: $cypher);
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $rows, stats: $meta, metadata: $meta);

        return $ctx;
    }

    private function bills(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['bill_id' => $i, 'supplier' => "Supplier {$i}", 'due_amount' => 100.0 + $i / 100];
        }

        return $rows;
    }

    private const BILLS_CYPHER = 'MATCH (b:Bill)-[:SUPPLIER]->(s:Supplier) RETURN b.id AS bill_id, s.name AS supplier, b.due_amount AS due_amount';

    private function declareBillMoney(): void
    {
        config(['entities' => [
            'App\\Models\\Bill' => ['graph' => ['label' => 'Bill'], 'metadata' => ['money_properties' => ['due_amount']]],
        ]]);
    }

    /** @test P-3: an undeclared decimal (a rate) is never summed — only the row count is stated. */
    public function a_decimal_column_not_declared_money_gets_no_system_total(): void
    {
        $this->declareBillMoney();
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ['supplier' => "S{$i}", 'tax_rate' => 0.15, 'unit_price' => 10.5 * $i];
        }
        $this->llmReturns("Here they are.\n\n{{table}}");

        $this->generator->generate($this->context($rows, [], 'MATCH (b:Bill) RETURN b.supplier_name AS supplier, b.tax_rate AS tax_rate, b.unit_price AS unit_price'));

        $this->assertStringNotContainsString('TOTAL of', $this->prompts[0]);
        $this->assertStringNotContainsString('QUERY TOTAL:', $this->prompts[0], 'a constant rate is not a total');
        $this->assertStringContainsString('5 rows in total', $this->prompts[0]);
    }

    /** @test */
    public function the_prompt_carries_a_compact_pipe_table_and_system_computed_totals_not_pretty_json(): void
    {
        $this->declareBillMoney();
        $this->llmReturns("You owe 75 bills in total.\n\n{{table}}\n\nWant the oldest ones first?");

        $this->generator->generate($this->context($this->bills(75), [], self::BILLS_CYPHER));

        $prompt = $this->prompts[0];
        $this->assertStringContainsString('| Supplier | Due Amount |', $prompt);
        $this->assertStringNotContainsString('"due_amount":', $prompt, 'no pretty JSON sample any more');
        // compact: the LLM summary holds at most 10 rows
        $this->assertStringContainsString('| Supplier 10 |', $prompt);
        $this->assertStringNotContainsString('| Supplier 11 |', $prompt);
        // the sum over ALL 75 rows is computed in code and stated as a fact
        $expected = number_format(array_sum(array_column($this->bills(75), 'due_amount')), 2, '.', ',');
        $this->assertStringContainsString("TOTAL of Due Amount over all 75 rows = {$expected}", $prompt);
        $this->assertStringContainsString('{{table}}', $prompt, 'the placeholder instruction is given');
    }

    /** @test */
    public function the_table_placeholder_is_replaced_with_all_rows_up_to_the_cap_and_an_honest_count(): void
    {
        $this->llmReturns("You owe 75 bills in total.\n\n{{table}}\n\nWant the oldest ones first?");

        $result = $this->generator->generate($this->context($this->bills(75)));

        $answer = $result['answer'];
        $this->assertStringNotContainsString('{{table}}', $answer);
        $this->assertStringContainsString('| Supplier 25 |', $answer);
        $this->assertStringNotContainsString('| Supplier 26 |', $answer);
        $this->assertStringContainsString('Showing 25 of 75', $answer);
        $this->assertStringNotContainsString('Bill Id', $answer, 'internal id columns are redacted');
        $this->assertStringStartsWith('You owe 75 bills in total.', $answer);
        // D5: code owns everything from the table on — the model's closer is gone and the
        // answer ends at the code-rendered count line.
        $this->assertStringNotContainsString('Want the oldest ones first?', $answer);
        $this->assertStringEndsWith('Showing 25 of 75 rows — ask for the full list or an export.', $answer);
        $this->assertTrue($result['metadata']['narrative_tail_dropped'] ?? false);
        $this->assertSame(25, $result['metadata']['result_shown']);
    }

    /**
     * @test D5 (eval p16-B resilience t0 / french t0): the data block asked for "at most one
     * short closing next step" while the guidelines of the SAME prompt forbid ending with an
     * offer — the model resolved it with a generic closer on every run. One instruction now.
     */
    public function the_prompt_no_longer_asks_for_a_closing_step_the_guidelines_forbid(): void
    {
        $this->llmReturns("Here they are.\n\n{{table}}");

        $result = $this->generator->generate($this->context($this->bills(75)));

        $prompt = $this->prompts[0];
        $this->assertStringNotContainsString('closing next step', $prompt);
        $this->assertStringContainsString('as the LAST line of your answer', $prompt);
        $this->assertStringContainsString('Do NOT end with an offer or a question', $prompt);
        $this->assertArrayNotHasKey('narrative_tail_dropped', $result['metadata'], 'nothing to drop when the model stops at the table');
    }

    /**
     * @test eval p17 (A roster, B resilience t0 / french t0, A unit-310 t0): the padding the
     * tail-drop removed moved in FRONT of the placeholder — a bullet list repeating the table's
     * rows, against "Do NOT list the rows". Through the whole generator: the list is dropped
     * before the code-rendered table (the comparison runs on the UNMASKED narrative against the
     * real rows), the trace says so, and the prompt no longer invites "anything else".
     */
    public function a_list_echoing_the_table_rows_is_dropped_from_the_lead_and_recorded(): void
    {
        $this->llmReturns(
            "Here are your open bills:\n\n- Supplier 1: 100.01\n- Supplier 2: 100.02\n- Supplier 3: 100.03\n\nThree are open.\n\n{{table}}"
        );

        $result = $this->generator->generate($this->context($this->bills(3), [], self::BILLS_CYPHER));

        $answer = $result['answer'];
        $this->assertStringStartsWith("Here are your open bills:\n\nThree are open.\n\n| Supplier | Due Amount |", $answer);
        $this->assertStringNotContainsString('- Supplier 1', $answer);
        $this->assertStringEndsWith('| Supplier 3 | 100.03 |', $answer);
        $this->assertSame(1, $result['metadata']['row_echo_dropped'] ?? null);

        $this->assertStringNotContainsString('anything else you have to say', $this->prompts[0]);
        $this->assertStringContainsString('Write ONLY the lead — one or two sentences that answer the question', $this->prompts[0]);
    }

    /** @test A lead without an echo carries no such evidence. */
    public function a_lead_without_an_echo_records_nothing(): void
    {
        $this->llmReturns("Three bills are open.\n\n{{table}}");

        $result = $this->generator->generate($this->context($this->bills(3), [], self::BILLS_CYPHER));

        $this->assertArrayNotHasKey('row_echo_dropped', $result['metadata']);
        $this->assertArrayNotHasKey('label_echo_dropped', $result['metadata']);
    }

    /** The buttons of a turn: a report page and the export file. */
    private function buttons(): \Condoedge\Ai\Services\Actions\Attach\AttachedActions
    {
        $chip = static fn (string $key, string $text): array => [
            'role' => \Condoedge\Ai\Services\Actions\Attach\AttachedActions::ROLE_NEXT,
            'invocation' => new \Condoedge\Ai\Services\Actions\ActionInvocation($key, null, null, [], $text),
        ];

        return new \Condoedge\Ai\Services\Actions\Attach\AttachedActions([$chip('payables_report', 'Payables report'), $chip('export_excel', 'Download Excel')]);
    }

    /**
     * @test eval p21-B rights t2, on a DATA lead: the prompt's own label copied in front of the
     * table ("Attached below this answer: …") is dropped by code — the buttons are right below —
     * and the trace records it. A sentence that points at a button in the model's words stays.
     */
    public function a_copied_prompt_label_is_dropped_from_the_data_lead_and_recorded(): void
    {
        $this->llmReturns(
            "Three bills are open.\n\nAttached below this answer: Payables report; Download Excel.\n\n{{table}}",
            "Three bills are open — the Payables report has the detail.\n\n{{table}}"
        );
        $ctx = $this->context($this->bills(3), [], self::BILLS_CYPHER);
        $ctx->attachedActions = $this->buttons();

        $result = $this->generator->generate($ctx);

        $this->assertStringStartsWith('Three bills are open.', $result['answer']);
        $this->assertStringNotContainsString('Attached below this answer', $result['answer']);
        $this->assertStringEndsWith('| Supplier 3 | 100.03 |', $result['answer']);
        $this->assertSame(['Attached below this answer: Payables report; Download Excel.'], $result['metadata']['label_echo_dropped'] ?? null);
        // The instruction gives the labels inside a sentence, the prohibition last.
        $this->assertStringContainsString('The buttons attached below this answer are "Payables report"; "Download Excel" — the user already sees them: never list or repeat them.', $this->prompts[0]);

        $ctx = $this->context($this->bills(3), [], self::BILLS_CYPHER);
        $ctx->attachedActions = $this->buttons();

        $kept = $this->generator->generate($ctx);

        $this->assertStringStartsWith('Three bills are open — the Payables report has the detail.', $kept['answer']);
        $this->assertArrayNotHasKey('label_echo_dropped', $kept['metadata']);
    }

    /** @test …and on an answer without rows (a document answer): the same sentence goes, the answer stays. */
    public function a_copied_prompt_label_is_dropped_from_an_answer_without_rows(): void
    {
        $this->llmReturns("Bills are paid from the operating account.\n\nAttached below this answer: Payables report.");
        $ctx = $this->context([]);
        $ctx->attachedActions = $this->buttons();

        $result = $this->generator->generate($ctx);

        $this->assertSame('Bills are paid from the operating account.', $result['answer']);
        $this->assertSame(['Attached below this answer: Payables report.'], $result['metadata']['label_echo_dropped'] ?? null);
    }

    /** @test D5: a missing placeholder never puts model text under the table — the table closes the answer. */
    public function a_missing_placeholder_puts_the_table_at_the_end(): void
    {
        $this->llmReturns("Here are your bills.\n\nThree are open.");

        $answer = $this->generator->generate($this->context($this->bills(3)))['answer'];

        $this->assertStringStartsWith("Here are your bills.\n\nThree are open.", $answer);
        $this->assertStringEndsWith('| Supplier 3 | 100.03 |', $answer);
        $this->assertStringNotContainsString('Showing', $answer, 'a complete table needs no count line');
    }

    /**
     * @test D5: text after the table that CITES a passage is document-grounded content of a
     * data + document turn, never a closer — it is kept, in front of the table.
     */
    public function cited_text_after_the_placeholder_is_kept_in_front_of_the_table(): void
    {
        $ctx = $this->context($this->bills(3));
        $ctx->fileContext = ['relevant_files' => [
            ['file_id' => 1, 'filename' => 'policy.md', 'snippet' => 'Bills are paid within thirty days of approval.', 'relevance' => 0.8],
        ]];
        $this->llmReturns("Here are your bills.\n\n{{table}}\n\nBills are paid within thirty days of approval [1].");

        $answer = $this->generator->generate($ctx)['answer'];

        $this->assertStringContainsString('Bills are paid within thirty days of approval [1].', $answer);
        $this->assertLessThan(strpos($answer, '| Supplier |'), strpos($answer, 'thirty days'));
        $this->assertStringEndsWith('| Supplier 3 | 100.03 |', $answer);
    }

    /**
     * @test D2 (eval p16-B digest t1 "When was our last meeting then?", replayed): one row with
     * name, date and union. FACTS listed the union ("copy them verbatim") and the model dropped
     * it in several runs. The record's fields are now rendered by code — the model's actual
     * p16 answer (no union, a closer) ships with the union and without the closer.
     */
    public function a_single_record_ships_every_field_whatever_the_model_wrote(): void
    {
        $rows = [[
            'meeting_name' => 'Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN',
            'date' => '2023-10-24 16:00:00',
            'union_name' => 'SDC du 1240 St-Sébastien',
            'meeting_id' => 140,
        ]];
        $cypher = 'MATCH (m:Meeting)-[:BELONGS_TO_UNION]->(u:Union) WHERE u.team_id IN $ai_team_ids AND m.start_date < toString(date()) '
            . 'RETURN m.name AS meeting_name, m.start_date AS date, u.name AS union_name, m.id AS meeting_id ORDER BY m.start_date DESC LIMIT 1';
        $this->llmReturns(
            "The last meeting was the \"Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN,\" which took place on October 24, 2023, at 16:00.\n\n"
            . "{{table}}\n\nIf you need more details about this meeting, you can find them below."
        );

        $result = $this->generator->generate($this->context($rows, [], $cypher));

        $prompt = $this->prompts[0];
        $this->assertStringContainsString('ANSWER FORMAT — THE SYSTEM LISTS THE RECORD:', $prompt);
        $this->assertStringContainsString('(Meeting Name, Date, Union Name)', $prompt);
        $this->assertStringNotContainsString('No table', $prompt);
        $this->assertStringContainsString('(Rows returned: 1)', $prompt);
        $this->assertStringNotContainsString('DATA STATS', $prompt);

        $answer = $result['answer'];
        $this->assertStringStartsWith('The last meeting was the "Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN,"', $answer);
        $this->assertStringContainsString("- **Date:** 2023-10-24 16:00\n- **Union Name:** SDC du 1240 St-Sébastien", $answer);
        $this->assertStringEndsWith('- **Union Name:** SDC du 1240 St-Sébastien', $answer);
        $this->assertStringNotContainsString('If you need more details', $answer);
        $this->assertTrue($result['metadata']['record_rendered'] ?? false);
        $this->assertTrue($result['metadata']['table_rendered'] ?? false);
        $this->assertCount(1, $this->prompts, 'deterministic — no retry');
    }

    /** @test */
    public function a_query_computed_total_column_suppresses_system_sums_and_is_stated_as_the_total(): void
    {
        $rows = [
            ['supplier' => 'BFL', 'total_payable' => 7022.87, 'total_all_suppliers' => 11493.17],
            ['supplier' => 'Roli-Vert', 'total_payable' => 3075.6, 'total_all_suppliers' => 11493.17],
            ['supplier' => 'Hydro', 'total_payable' => 819.82, 'total_all_suppliers' => 11493.17],
            ['supplier' => 'Arken', 'total_payable' => 574.88, 'total_all_suppliers' => 11493.17],
        ];
        $this->llmReturns('You owe 11,493.17. {{table}}');

        $this->generator->generate($this->context($rows));

        $this->assertStringContainsString('Total All Suppliers', $this->prompts[0]);
        $this->assertStringContainsString('11,493.17', $this->prompts[0]);
        $this->assertStringNotContainsString('TOTAL of Total Payable', $this->prompts[0]);
    }

    /** @test */
    public function a_single_aggregate_row_with_a_nested_breakdown_states_facts_and_tables_the_breakdown(): void
    {
        // The live payables shape: RETURN total_payable_all_suppliers, open_bills_all_suppliers, per_supplier
        $rows = [[
            'total_payable_all_suppliers' => 11493.17,
            'open_bills_all_suppliers' => 4,
            'per_supplier' => [
                ['supplier' => 'BFL Canada', 'open_bills' => 1, 'total_payable' => 7022.87],
                ['supplier' => 'Roli-Vert', 'open_bills' => 1, 'total_payable' => 3075.6],
                ['supplier' => 'Hydro-Québec', 'open_bills' => 1, 'total_payable' => 819.82],
                ['supplier' => 'Groupe Arken', 'open_bills' => 1, 'total_payable' => 574.88],
            ],
        ]];
        $this->llmReturns("You currently owe 11,493.17 across 4 open bills.\n\n{{table}}");

        $result = $this->generator->generate($this->context($rows));

        $prompt = $this->prompts[0];
        $this->assertStringContainsString('Total Payable All Suppliers: 11,493.17', $prompt);
        $this->assertStringContainsString('Open Bills All Suppliers: 4', $prompt);
        $this->assertStringNotContainsString('TOTAL of', $prompt, 'the query already returned the totals');

        $answer = $result['answer'];
        $this->assertStringContainsString('| Supplier | Open Bills | Total Payable |', $answer);
        $this->assertStringContainsString('| Hydro-Québec | 1 | 819.82 |', $answer);
        $this->assertStringContainsString('| BFL Canada | 1 | 7,022.87 |', $answer);
    }

    /** @test */
    public function a_single_value_result_gets_no_table(): void
    {
        $this->llmReturns('Unit 310 owes 1,250.50. {{table}}');

        $result = $this->generator->generate($this->context([['unit' => '310', 'balance' => 1250.5]]));

        $this->assertSame('Unit 310 owes 1,250.50.', $result['answer']);
        $this->assertStringContainsString('Balance: 1,250.50', $this->prompts[0]);
        $this->assertStringNotContainsString('{{table}}', $this->prompts[0], 'no placeholder instruction without a table');
    }

    /** @test */
    public function homonyms_are_flagged_in_the_prompt_so_they_are_reported_separately(): void
    {
        $rows = [
            ['unit' => '310', 'union_name' => 'Syndicat Alpha', 'balance' => 10.5],
            ['unit' => '310', 'union_name' => 'Syndicat Beta', 'balance' => 20.25],
            ['unit' => '310', 'union_name' => 'Syndicat Gamma', 'balance' => 0.0],
        ];
        $this->llmReturns("Three different units are named 310.\n\n{{table}}");

        $result = $this->generator->generate($this->context($rows));

        $prompt = $this->prompts[0];
        $this->assertStringContainsString("3 DIFFERENT records share the Unit '310'", $prompt);
        $this->assertStringContainsString('Union Name', $prompt);
        // p17-A 310 t0: beside the code-rendered table the note no longer asks for a per-record
        // report (it produced a numbered list of the rows) — the table is that report.
        $this->assertStringContainsString('never merge them into one sentence, one record or one figure', $prompt);
        $this->assertStringNotContainsString('report each one separately', $prompt);
        $this->assertStringContainsString('Your FIRST sentence must say that there are 3 different Unit', $prompt);
        // every row, with its disambiguating union, reaches the user
        foreach (['Syndicat Alpha', 'Syndicat Beta', 'Syndicat Gamma'] as $union) {
            $this->assertStringContainsString("| 310 | {$union} |", $result['answer']);
        }
    }

    /**
     * @test G2 (eval p16-A "How much does unit 310 owe right now?", replayed): three units named
     * 310, one per union, each owing $0.00. The Results block demanded per-record figures in the
     * first sentence, a first sentence of merged totals AND no row list — the model answered
     * with merged totals on one run and a numbered list on the next. One lead instruction now;
     * the code-rendered table carries each record's own figure, minus the all-empty column (G3).
     */
    public function a_homonym_result_gets_one_lead_instruction_and_no_merged_total(): void
    {
        config([
            'ai.response_generation.currency_symbol' => '$',
            'entities' => [
                'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['money_properties' => ['outstanding_balance']]],
                'App\\Models\\Invoice' => ['graph' => ['label' => 'Invoice'], 'metadata' => ['money_properties' => ['due_amount']]],
            ],
        ]);
        $cypher = "MATCH (u:Unit) WHERE u.name = '310' MATCH (u)-[:UNION]->(un:Union) WHERE un.team_id IN \$ai_team_ids "
            . "OPTIONAL MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.status <> '1' AND i.due_amount >= 0.01 "
            . 'RETURN un.name AS union_name, u.name AS unit_name, round(u.outstanding_balance, 2) AS balance, count(i) AS open_invoices, '
            . 'round(sum(i.due_amount), 2) AS invoices_due, min(i.due_at) AS oldest_due, u.id AS unit_id';
        $rows = [
            ['union_name' => 'SDC Carré Soho Phase 3', 'unit_name' => '310', 'balance' => 0.0, 'open_invoices' => 0, 'invoices_due' => 0.0, 'oldest_due' => null, 'unit_id' => 159],
            ['union_name' => 'SDC Les Terrasses 7250 St-Hubert', 'unit_name' => '310', 'balance' => 0.0, 'open_invoices' => 0, 'invoices_due' => 0.0, 'oldest_due' => null, 'unit_id' => 301],
            ['union_name' => '825 de Bruxelles', 'unit_name' => '310', 'balance' => 0.0, 'open_invoices' => 0, 'invoices_due' => 0.0, 'oldest_due' => null, 'unit_id' => 472],
        ];
        $this->llmReturns("There are 3 different units named 310, one per union, and none of them owes anything.\n\n{{table}}");

        $result = $this->generator->generate($this->context($rows, [], $cypher));

        $prompt = $this->prompts[0];
        $this->assertStringContainsString("Your FIRST sentence must say that there are 3 different Unit Name '310' records (one per Union Name)", $prompt);
        $this->assertSame(1, substr_count($prompt, 'FIRST sentence'), 'one instruction owns the lead');
        $this->assertStringNotContainsString("give each one's figures", $prompt);
        $this->assertStringNotContainsString('TOTAL of', $prompt);
        $this->assertStringNotContainsString('NOT RECORDED', $prompt, 'an aggregate with nothing to compute is not a missing field');

        $answer = $result['answer'];
        $this->assertStringContainsString("| Union Name | Unit Name | Balance | Open Invoices | Invoices Due |\n", $answer);
        $this->assertStringContainsString('| 825 de Bruxelles | 310 | $0.00 | 0 | $0.00 |', $answer);
        $this->assertStringNotContainsString('Oldest Due', $answer);
        $this->assertSame(['Oldest Due'], $result['metadata']['empty_columns_hidden'] ?? null);
        $this->assertCount(1, $this->prompts, 'the lead the prompt asks for passes validation — no retry');
    }

    /**
     * @test G1 + G3 (eval p16-A board, replayed shape): member rows over several unions with a
     * role column empty on every row. The prompt states how the rows group; the shipped table
     * has no blank "Bm Role" column, and a table without hidden columns reports none.
     */
    public function the_board_prompt_states_its_groups_and_the_table_drops_the_empty_role(): void
    {
        $cypher = 'MATCH (cur:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) MATCH (cur)-[:BELONGS_TO_CONTACT]->(c:Contact) '
            . 'RETURN un.name AS union_name, c.first_name, c.last_name, cur.bm_role, cur.year, c.id AS contact_id ORDER BY un.name';
        $rows = [];
        foreach (['825 de Bruxelles' => 3, "SDC 2205 Chemin d'oka" => 2] as $union => $seats) {
            for ($i = 1; $i <= $seats; $i++) {
                $n = count($rows) + 1;
                $rows[] = ['union_name' => $union, 'c.first_name' => "First{$n}", 'c.last_name' => "Last{$n}", 'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => 700 + $n];
            }
        }
        $this->llmReturns("Here are the board members of your 2 unions; who presides is not recorded.\n\n{{table}}");

        $result = $this->generator->generate($this->context($rows, [], $cypher));

        $this->assertStringContainsString("GROUPS: The 5 rows span 2 different Union Name: 825 de Bruxelles (3); SDC 2205 Chemin d'oka (2).", $this->prompts[0]);
        $this->assertStringContainsString('NOT RECORDED: Bm Role is empty on all 5 rows', $this->prompts[0]);
        $this->assertStringContainsString("| Union Name | First Name | Last Name | Year |\n", $result['answer']);
        $this->assertStringNotContainsString('| Bm Role', $result['answer']);
        $this->assertSame(['Bm Role'], $result['metadata']['empty_columns_hidden'] ?? null);
        // p18: the field the table leaves out is named by CODE on the line above it (no host
        // noun is needed for that) — the count and the groups stay the model's without one.
        $this->assertStringContainsString("is not recorded.\n\nNot recorded: Bm Role.\n\n| Union Name |", $result['answer']);
        $this->assertSame(['not_recorded'], $result['metadata']['facts_line'] ?? null);
    }

    /**
     * @test Eval p18-A board, end to end: with the host's nouns declared, the count, the groups
     * and the unrecorded field are printed by code between the model's one sentence and the
     * table; the prompt asks the model for none of them; the trace names the parts printed.
     */
    public function the_facts_line_is_printed_between_the_lead_and_the_table(): void
    {
        config(['entities' => [
            'App\\Models\\Union' => ['graph' => ['label' => 'Union'], 'metadata' => ['display_name' => 'union', 'display_name_plural' => 'unions']],
            'App\\Models\\Contact' => ['graph' => ['label' => 'Contact'], 'metadata' => ['display_name' => 'contact', 'display_name_plural' => 'contacts']],
            'App\\Models\\BoardMember' => ['graph' => ['label' => 'BoardMember'], 'metadata' => []],
        ]]);
        $cypher = 'MATCH (cur:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) MATCH (cur)-[:BELONGS_TO_CONTACT]->(c:Contact) '
            . 'RETURN un.name AS union_name, c.first_name, c.last_name, cur.bm_role, cur.year, c.id AS contact_id ORDER BY un.name';
        $rows = [];
        foreach (['825 de Bruxelles' => 3, "SDC 2205 Chemin d'oka" => 2] as $union => $seats) {
            for ($i = 1; $i <= $seats; $i++) {
                $n = count($rows) + 1;
                $rows[] = ['union_name' => $union, 'c.first_name' => "First{$n}", 'c.last_name' => "Last{$n}", 'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => 700 + $n];
            }
        }
        $this->llmReturns("Here are the current board members of the unions you manage.\n\n{{table}}");

        $result = $this->generator->generate($this->context($rows, [], $cypher));

        // The model's lead names nothing of the rows: beside "Not recorded: …" it is dropped
        // (iteration 5) — the line and the table are the answer.
        $this->assertStringStartsWith("5 contacts across 2 unions. Not recorded: Bm Role.\n\n| Union Name |", $result['answer']);
        $this->assertSame(['not_recorded'], array_column($result['metadata']['lead_trimmed'] ?? [], 'rule'));
        $this->assertSame(['count', 'groups', 'not_recorded'], $result['metadata']['facts_line'] ?? null);
        $this->assertStringNotContainsString('FIRST sentence', $this->prompts[0]);
        $this->assertStringContainsString('The system itself prints, on a line right above the table', $this->prompts[0]);
        $this->assertCount(1, $this->prompts, 'a lead that restates nothing passes validation — no retry');
    }

    /**
     * @test Eval p19-A board, end to end: the model's two-sentence lead is cut to one, then dropped beside "Not recorded",
     * the trace records what was cut (`lead_trimmed`), and the declared split name is ONE column.
     * A model that writes the placeholder alone ships the line and the table.
     */
    public function p19_the_lead_is_bounded_and_the_name_is_one_column_end_to_end(): void
    {
        config(['entities' => [
            'App\\Models\\Union' => ['graph' => ['label' => 'Union'], 'metadata' => ['display_name' => 'union', 'display_name_plural' => 'unions']],
            'App\\Models\\Contact' => ['graph' => ['label' => 'Contact'], 'metadata' => [
                'display_name' => 'contact', 'display_name_plural' => 'contacts', 'name_properties' => ['first_name', 'last_name'],
            ]],
            'App\\Models\\BoardMember' => ['graph' => ['label' => 'BoardMember'], 'metadata' => []],
        ]]);
        $cypher = 'MATCH (cur:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) MATCH (cur)-[:BELONGS_TO_CONTACT]->(c:Contact) '
            . 'RETURN un.name AS union_name, c.first_name, c.last_name, cur.bm_role, cur.year, c.id AS contact_id ORDER BY un.name';
        $rows = [];
        foreach (['825 de Bruxelles' => 3, "SDC 2205 Chemin d'oka" => 2] as $union => $seats) {
            for ($i = 1; $i <= $seats; $i++) {
                $n = count($rows) + 1;
                $rows[] = ['union_name' => $union, 'c.first_name' => "First{$n}", 'c.last_name' => "Last{$n}", 'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => 700 + $n];
            }
        }
        $this->llmReturns(
            "The information about the board of directors and the president is available in the records. You can find the details in the table below.\n\n{{table}}",
            '{{table}}'
        );

        $result = $this->generator->generate($this->context($rows, [], $cypher));

        $this->assertStringStartsWith(
            "5 contacts across 2 unions. Not recorded: Bm Role.\n\n| Union Name | Name | Year |\n| --- | --- | --- |\n| 825 de Bruxelles | First1 Last1 | 2022 |",
            $result['answer']
        );
        // The second sentence is cut; the first names nothing of the rows beside "Not recorded".
        $this->assertSame(['one_sentence', 'not_recorded'], array_column($result['metadata']['lead_trimmed'] ?? [], 'rule'));
        $this->assertSame('You can find the details in the table below.', $result['metadata']['lead_trimmed'][0]['text'] ?? null);
        $this->assertStringContainsString('The system prints: "5 contacts across 2 unions. Not recorded: Bm Role."', $this->prompts[0]);
        $this->assertStringContainsString('the lead may be EMPTY', $this->prompts[0]);
        $this->assertStringContainsString('| Union Name | Name | Year |', $this->prompts[0]);

        $empty = $this->generator->generate($this->context($rows, [], $cypher));

        $this->assertStringStartsWith("5 contacts across 2 unions. Not recorded: Bm Role.\n\n| Union Name | Name | Year |", $empty['answer']);
        $this->assertArrayNotHasKey('lead_trimmed', $empty['metadata']);
        $this->assertSame([], $empty['metadata']['validation']['violations_final'], 'a placeholder-only draft is a valid answer');
    }

    /**
     * @test Eval p20-A roster, end to end: the model's "Here are the members of your team, Novo
     * Gestion, along with their roles:" names the user's own team — an identity value the prompt
     * itself gave it (CURRENT USER CONTEXT). It is known, so the lead is the restatement it reads
     * as: dropped, recorded, and the line and the table are the answer.
     */
    public function p20_an_intro_naming_the_users_own_team_is_dropped_end_to_end(): void
    {
        config(['entities' => [
            'App\\Models\\User' => ['graph' => ['label' => 'User'], 'metadata' => ['display_name' => 'member', 'display_name_plural' => 'members']],
            'App\\Models\\Team' => ['graph' => ['label' => 'Team'], 'metadata' => []],
        ]]);
        $user = new \Illuminate\Foundation\Auth\User();
        $user->id = 13;
        $user->name = 'Julie Rousseau';
        $user->email = 'j.rousseau@novogestion.ca';
        $user->currentTeam = (object) ['id' => 3, 'name' => 'Novo Gestion'];
        \Illuminate\Support\Facades\Auth::shouldReceive('check')->andReturn(true);
        \Illuminate\Support\Facades\Auth::shouldReceive('user')->andReturn($user);
        \Illuminate\Support\Facades\Auth::shouldReceive('id')->andReturn(13);

        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['name' => "Member {$i}", 'role' => 'contact', 'user_id' => 100 + $i];
        }
        $ctx = $this->context($rows, [], 'MATCH (t:Team) MATCH (u:User) WHERE u.current_team_id = t.id RETURN u.name AS name, u.role AS role, u.id AS user_id');
        $ctx->question = 'Who are the members of my team and what are their roles?';
        $lead = 'Here are the members of your team, Novo Gestion, along with their roles:';
        $this->llmReturns("{$lead}\n\n{{table}}");

        $result = $this->generator->generate($ctx);

        $this->assertStringContainsString('- Current team name: Novo Gestion', $this->prompts[0], 'the prompt gave the model the team name');
        $this->assertStringStartsWith("30 members.\n\n| Name | Role |", $result['answer']);
        $this->assertSame([['rule' => 'restatement', 'text' => $lead]], $result['metadata']['lead_trimmed'] ?? null);
    }

    /** @test G3: a table with a value in every column hides nothing and says so (no trace key). */
    public function a_table_without_empty_columns_reports_none_hidden(): void
    {
        $this->llmReturns("Here they are.\n\n{{table}}");

        $result = $this->generator->generate($this->context($this->bills(3)));

        $this->assertArrayNotHasKey('empty_columns_hidden', $result['metadata']);
    }

    /** @test */
    public function incidental_homonyms_in_a_long_list_never_demand_figures_for_unseen_rows(): void
    {
        // Live 2026-09-30 ("Which units owe the most?", 27 rows): demanding per-record
        // figures for homonyms outside the 10-row summary made the model invent them.
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = ['unit' => (string) (100 + $i), 'union_name' => $i % 2 ? 'Alpha' : 'Beta', 'balance' => 1000.5 - $i];
        }
        $rows[] = ['unit' => '101', 'union_name' => 'Beta', 'balance' => 3.5];
        $this->llmReturns("Here are the units.\n\n{{table}}");

        $this->generator->generate($this->context($rows));

        $prompt = $this->prompts[0];
        $this->assertStringContainsString("2 DIFFERENT records share the Unit '101'", $prompt);
        $this->assertStringContainsString('say which Union Name you mean', $prompt);
        $this->assertStringNotContainsString('Your FIRST sentence must', $prompt);
        $this->assertStringContainsString('never state a figure for a row that is not in the DATA SUMMARY', $prompt);
    }

    /** @test */
    public function identifying_fields_never_reach_the_llm_but_the_user_sees_the_real_values(): void
    {
        $rows = [
            ['member' => 'Karen Roy', 'email' => 'karen@novo.test', 'role' => 'Admin', 'phone' => '514-555-0101'],
            ['member' => 'Luc Tremblay', 'email' => 'luc@novo.test', 'role' => 'Member', 'phone' => '514-555-0102'],
        ];
        $observer = new RecordingPipelineObserver();
        $ctx = $this->context($rows, [], 'MATCH (u:User)-[:MEMBER]->(t:Team) RETURN u');
        $ctx->observer = $observer;
        $this->llmReturns("Your team has 2 members; Karen can be reached at [email#1].\n\n{{table}}");

        $result = $this->generator->generate($ctx);

        // LEAK TEST: nothing the LLM received (user + system prompt, as observed) carries a real value.
        $composed = json_encode($observer->prompts('composition'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . implode("\n", $this->prompts) . implode("\n", array_filter($this->systems));
        foreach (['karen@novo.test', 'luc@novo.test', '514-555-0101', '514-555-0102'] as $secret) {
            $this->assertStringNotContainsString($secret, $composed, "{$secret} leaked into the prompt");
        }
        $this->assertStringContainsString('[email#1]', $this->prompts[0]);
        // Live 2026-09-30 ("only the ones with a gmail address"): masked domains made the
        // model claim no row matched — it is told masked values cannot be judged.
        $this->assertStringContainsString('never say a row or value does not match because it is masked', $this->prompts[0]);
        // ...and the non-identifying DOMAIN stays visible beside the masked address, so a
        // domain question ("only the gmail ones") can be answered from the summary.
        $this->assertStringContainsString('| Karen Roy | [email#1] | novo.test |', $this->prompts[0]);
        $this->assertStringContainsString('Email Domain', $this->prompts[0]);

        // The user sees the real values: in the rendered table and substituted into the narrative.
        $answer = $result['answer'];
        $this->assertStringContainsString('Karen can be reached at karen@novo.test.', $answer);
        $this->assertStringContainsString('| Karen Roy | karen@novo.test | Admin | 514-555-0101 |', $answer);
        $this->assertStringNotContainsString('[email#', $answer);
        $this->assertStringNotContainsString('[phone#', $answer);
    }

    /** @test Live 2026-09-30: a host marking `domain` sensitive (Team) must not mask the derived email domain. */
    public function the_derived_email_domain_is_never_masked_by_a_sensitive_domain_field(): void
    {
        config(['entities' => [
            'App\\Models\\Team' => ['graph' => ['label' => 'Team'], 'security' => ['sensible_columns' => ['domain']]],
        ]]);
        $rows = [
            ['member' => 'Karen', 'email' => 'karen@gmail.com', 'role' => 'contact'],
            ['member' => 'Luc', 'email' => 'luc@gmail.com', 'role' => 'contact'],
        ];
        $this->llmReturns("Two members use Gmail.\n\n{{table}}");

        $this->generator->generate($this->context($rows, [], 'MATCH (t:Team)<-[:MEMBER]-(u:User) RETURN u.name AS member, u.email AS email'));

        $this->assertStringContainsString('| Karen | [email#1] | gmail.com | contact |', $this->prompts[0]);
        $this->assertStringNotContainsString('[domain#', $this->prompts[0]);
        $this->assertStringNotContainsString('karen@gmail.com', $this->prompts[0]);
    }

    /** @test */
    public function the_self_reference_guard_still_works_on_masked_values(): void
    {
        $rows = [
            ['member' => 'Julie Rousseau', 'email' => 'julie@novo.test', 'role' => 'Admin'],
            ['member' => 'Luc Tremblay', 'email' => 'luc@novo.test', 'role' => 'Member'],
        ];
        $user = new \Illuminate\Foundation\Auth\User();
        $user->forceFill(['id' => 13, 'name' => 'Julie Rousseau', 'email' => 'julie@novo.test']);
        $this->be($user);

        // Draft presents the user's own (masked) email as a contact route without a marker,
        // then the retry still fails — the deterministic patch must land next to it.
        $this->llmReturns("Reach your admin at [email#1].\n\n{{table}}");

        $result = $this->generator->generate($this->context($rows, [], 'MATCH (u:User) RETURN u'), ['user' => $user]);

        foreach ($this->prompts as $prompt) {
            $this->assertStringNotContainsString('julie@novo.test', $prompt, 'the notice and the retry quote the token, not the email');
        }
        $this->assertStringContainsString("The email '[email#1]' in the data IS the current user's own email", $this->prompts[0]);
        $this->assertSame('patched', $result['metadata']['self_reference_guard'] ?? null);
        $this->assertMatchesRegularExpression('/Reach your admin at julie@novo\.test \([^)]+\)/', $result['answer']);
    }

    /**
     * @test F1 (eval 2026-10-01 p9 team-info, gate no_self_contact): the narrative never
     * mentions the email (the model is told not to list rows), so the masked-narrative
     * validation had nothing to act on — and the code-rendered table then shipped the
     * user's REAL email as an unmarked contact. The shipped answer must mark it.
     */
    public function the_users_own_email_in_the_code_rendered_table_ships_with_the_marker(): void
    {
        $rows = [
            ['name' => 'Novo Gestion', 'contact_email' => 'j.rousseau@novogestion.ca', 'city' => 'Montréal'],
            ['name' => 'Novo Gestion Sud', 'contact_email' => 'luc@novo.test', 'city' => 'Laval'],
        ];
        $user = (object) ['id' => 13, 'name' => 'Julie Rousseau', 'email' => 'j.rousseau@novogestion.ca'];
        $this->llmReturns("Your team is called Novo Gestion. Here are the details:\n\n{{table}}\n\nNeed anything else?");

        $result = $this->generator->generate($this->context($rows, [], 'MATCH (t:Team) RETURN t'), ['user' => $user]);

        $answer = $result['answer'];
        $emailLine = array_values(array_filter(explode("\n", $answer), static fn ($l) => str_contains($l, 'j.rousseau@novogestion.ca')));
        $this->assertCount(1, $emailLine);
        $this->assertStringContainsString("that's you", $emailLine[0]);
        $this->assertSame(1, substr_count($answer, "that's you"), 'never double-marked');
        $this->assertSame('table', $result['metadata']['self_reference_guard'] ?? null);
        $this->assertCount(1, $this->prompts, 'deterministic — no retry');
    }

    /**
     * @test F1: the guard evaluates the FINAL shipped answer — a self value the table
     * renders without a cell marker (inside a list cell) gets the deterministic patch.
     */
    public function the_self_reference_guard_patches_what_the_table_inserted_after_validation(): void
    {
        $rows = [
            ['name' => 'Novo Gestion', 'contacts' => ['j.rousseau@novogestion.ca', 'luc@novo.test'], 'city' => 'Montréal'],
            ['name' => 'Novo Gestion Sud', 'contacts' => ['karen@novo.test'], 'city' => 'Laval'],
        ];
        $user = (object) ['id' => 13, 'name' => 'Julie Rousseau', 'email' => 'j.rousseau@novogestion.ca'];
        $this->llmReturns("Here are your teams.\n\n{{table}}");

        $result = $this->generator->generate($this->context($rows, [], 'MATCH (t:Team) RETURN t'), ['user' => $user]);

        $this->assertMatchesRegularExpression('/j\.rousseau@novogestion\.ca \(that\'s you[^)]*\)/', $result['answer']);
        $this->assertSame('patched', $result['metadata']['self_reference_guard'] ?? null);
    }

    /** @test F1: another user's email in the table is never marked and raises no guard flag. */
    public function another_users_email_in_the_table_is_untouched(): void
    {
        $rows = [
            ['name' => 'Novo Gestion', 'contact_email' => 'karen@novo.test'],
            ['name' => 'Novo Gestion Sud', 'contact_email' => 'luc@novo.test'],
        ];
        $user = (object) ['id' => 13, 'name' => 'Julie Rousseau', 'email' => 'j.rousseau@novogestion.ca'];
        $this->llmReturns("Here are your teams.\n\n{{table}}");

        $result = $this->generator->generate($this->context($rows, [], 'MATCH (t:Team) RETURN t'), ['user' => $user]);

        $this->assertStringNotContainsString("that's you", $result['answer']);
        $this->assertArrayNotHasKey('self_reference_guard', $result['metadata']);
    }

    /** @test F1: the kill switch also disables the table marker. */
    public function the_self_reference_kill_switch_leaves_the_table_unmarked(): void
    {
        config(['ai.response_generation.self_reference_guard' => false]);
        $rows = [
            ['name' => 'Novo Gestion', 'contact_email' => 'j.rousseau@novogestion.ca'],
            ['name' => 'Novo Gestion Sud', 'contact_email' => 'luc@novo.test'],
        ];
        $user = (object) ['id' => 13, 'name' => 'Julie Rousseau', 'email' => 'j.rousseau@novogestion.ca'];
        $this->llmReturns("Here are your teams.\n\n{{table}}");

        $result = $this->generator->generate($this->context($rows, [], 'MATCH (t:Team) RETURN t'), ['user' => $user]);

        $this->assertStringNotContainsString("that's you", $result['answer']);
        $this->assertArrayNotHasKey('self_reference_guard', $result['metadata']);
    }

    /** @test */
    public function the_kill_switch_restores_the_narrative_only_path(): void
    {
        config(['ai.response_generation.deterministic_tables' => false]);
        $this->llmReturns('Here are the bills.');

        $result = $this->generator->generate($this->context($this->bills(3)));

        $this->assertSame('Here are the bills.', $result['answer']);
        $this->assertStringNotContainsString('{{table}}', $this->prompts[0]);
    }
}
