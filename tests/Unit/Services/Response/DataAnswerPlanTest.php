<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Response\DataAnswerPlan;
use Condoedge\Ai\Tests\TestCase;

/**
 * DataAnswerPlan — the code-rendered user table.
 *
 * F1 (eval 2026-10-01 p9, team-info gate no_self_contact): the table is rendered
 * with real values AFTER every self-reference check ran on the masked narrative,
 * so the user's own email shipped as an unmarked "Contact Email". The table now
 * marks the user's own identity values (SelfReferenceNoticeSection::selfValuesIn —
 * email / full name) with the localized ai.response.self_reference_marker.
 *
 * F8 (eval A board): unaliased `RETURN c.first_name` keyed the column
 * `c.first_name` and the header read "C.first Name".
 */
class DataAnswerPlanTest extends TestCase
{
    private const EMAIL = 'j.rousseau@novogestion.ca';

    public function setUp(): void
    {
        parent::setUp();

        config([
            'ai.response_generation.deterministic_tables' => true,
            'ai.response_generation.table_max_rows' => 25,
            'ai.access_control.mask_identifying_fields' => true,
            'ai.access_control.identifying_fields' => ['*' => ['email', 'phone']],
        ]);
    }

    private function plan(array $rows, string $cypher = 'MATCH (t:Team) RETURN t'): DataAnswerPlan
    {
        $ctx = new PipelineContext();
        $ctx->question = 'Info about my team';
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'en');
        $ctx->queryResult = new QueryGenerationResult(cypher: $cypher);
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $rows);

        return DataAnswerPlan::for($ctx);
    }

    private function teamRows(): array
    {
        return [
            ['name' => 'Novo Gestion', 'contact_email' => self::EMAIL, 'phone' => '514-555-0101', 'city' => 'Montréal'],
            ['name' => 'Novo Gestion Sud', 'contact_email' => 'luc@novo.test', 'phone' => '514-555-0102', 'city' => 'Laval'],
        ];
    }

    private function user(string $email = self::EMAIL, string $name = 'Julie Rousseau'): object
    {
        return (object) ['id' => 13, 'name' => $name, 'email' => $email];
    }

    /** @test */
    public function the_users_own_email_in_the_table_gets_the_thats_you_marker(): void
    {
        $plan = $this->plan($this->teamRows());

        $answer = $plan->finalize("Your team is called Novo Gestion.\n\n{{table}}", true, 'en', $this->user());

        $line = $this->lineWith($answer, self::EMAIL);
        $this->assertStringContainsString("that's you", $line, 'the marker sits on the same table row as the email');
        $this->assertSame(1, substr_count($answer, "that's you"), 'marked exactly once');
        $this->assertStringNotContainsString("that's you", $this->lineWith($answer, 'luc@novo.test'), "another member's email is untouched");
        $this->assertTrue($plan->selfMarked());
    }

    /** @test */
    public function the_marker_follows_the_answer_language(): void
    {
        $plan = $this->plan($this->teamRows());

        $answer = $plan->finalize("Votre équipe s'appelle Novo Gestion.\n\n{{table}}", true, 'fr', $this->user());

        $this->assertStringContainsString("c'est vous", $this->lineWith($answer, self::EMAIL));
    }

    /** @test */
    public function the_users_own_name_is_marked_too(): void
    {
        $plan = $this->plan([
            ['member' => 'Julie Rousseau', 'role' => 'Admin'],
            ['member' => 'Luc Tremblay', 'role' => 'Member'],
        ]);

        $answer = $plan->finalize('{{table}}', true, 'en', $this->user());

        $this->assertStringContainsString("that's you", $this->lineWith($answer, 'Julie Rousseau'));
        $this->assertStringNotContainsString("that's you", $this->lineWith($answer, 'Luc Tremblay'));
    }

    /** @test */
    public function a_table_without_the_users_values_is_untouched(): void
    {
        $plan = $this->plan($this->teamRows());
        $other = $this->user('someone@else.test', 'Someone Else');

        $marked = $plan->finalize('{{table}}', true, 'en', $other);
        $plain = $plan->finalize('{{table}}', true, 'en');

        $this->assertSame($plain, $marked);
        $this->assertStringNotContainsString("that's you", $marked);
        $this->assertFalse($plan->selfMarked());
    }

    /** @test */
    public function the_llm_facing_summary_is_never_marked_or_unmasked(): void
    {
        $plan = $this->plan($this->teamRows());
        $plan->finalize('{{table}}', true, 'en', $this->user());

        $prompt = $plan->promptBlock(true);

        $this->assertStringNotContainsString(self::EMAIL, $prompt);
        $this->assertStringNotContainsString("that's you", $prompt);
    }

    /** @test F8 */
    public function unaliased_projection_headers_read_as_the_property(): void
    {
        $plan = $this->plan([
            ['c.first_name' => 'Julie', 'c.last_name' => 'Rousseau', 'cur.bm_role' => 'president'],
            ['c.first_name' => 'Luc', 'c.last_name' => 'Tremblay', 'cur.bm_role' => 'treasurer'],
        ], 'MATCH (c:Contact)-[:SITS_ON]->(cur:BoardMember) RETURN c.first_name, c.last_name, cur.bm_role');

        $answer = $plan->finalize('{{table}}', true, 'en');

        $this->assertStringContainsString('| First Name | Last Name | Bm Role |', $answer);
        $this->assertStringNotContainsString('C.first', $answer);
        $this->assertStringContainsString('| First Name | Last Name | Bm Role |', $plan->promptBlock(true));
    }

    /** @test F8 */
    public function colliding_unaliased_headers_carry_the_node_label_from_the_cypher(): void
    {
        $plan = $this->plan([
            ['c.name' => 'Julie', 'u.name' => 'Syndicat Alpha'],
            ['c.name' => 'Luc', 'u.name' => 'Syndicat Beta'],
        ], 'MATCH (c:Contact)-[:BOARD_OF]->(u:Union) RETURN c.name, u.name');

        $answer = $plan->finalize('{{table}}', true, 'en');

        $this->assertStringContainsString('| Contact Name | Union Name |', $answer);
    }

    /** Owner-arrears (eval p10-A, live 2026-10-01): unit rows that also carry their union's (constant) id. */
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

    /**
     * @test Eval p10-A: four units of ONE union (union_id 8 on every row) are not four
     * "different records sharing the Union Name" — the repeated parent is one record, and
     * the oldest due date is a per-row fact, never what tells records apart. The HOMONYMS
     * directive hijacked the first sentence and the computed total was never stated.
     */
    public function a_repeated_parent_is_one_record_not_homonyms(): void
    {
        config(['entities' => [
            'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['money_properties' => ['outstanding_balance']]],
        ]]);

        $prompt = $this->plan($this->arrearsRows(), self::ARREARS_CYPHER)->promptBlock(true);

        $this->assertStringNotContainsString('HOMONYMS', $prompt);
        // (The HOMONYMS lead directive is gone; E1's count + total lead is pinned below.)
        $this->assertStringNotContainsString('FIRST sentence must say that there are', $prompt);
        $this->assertStringContainsString('TOTAL of Balance over all 4 rows = 1,211.48', $prompt);
    }

    private function arrearsPlan(?string $searchQuery = null): DataAnswerPlan
    {
        config(['entities' => [
            'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['money_properties' => ['outstanding_balance']]],
        ]]);
        $ctx = new PipelineContext();
        $ctx->question = 'Which owners in SDC Carré Soho Phase 3 are behind on their fees?';
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'en', searchQuery: (string) $searchQuery);
        $ctx->queryResult = new QueryGenerationResult(cypher: self::ARREARS_CYPHER);
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $this->arrearsRows());

        return DataAnswerPlan::for($ctx);
    }

    /**
     * @test E1 (eval p11-A owner-arrears): the computed total $1,211.48 was in the prompt but the
     * answer stated neither it nor the count — nothing asked for them, and "do NOT state how many
     * rows are shown" discouraged the count on a COMPLETE result. A complete multi-row result with
     * grand totals now leads with the record count and the totals, verbatim.
     */
    public function a_complete_result_with_grand_totals_leads_with_the_count_and_the_total(): void
    {
        $prompt = $this->arrearsPlan()->promptBlock(true);

        $this->assertStringContainsString('Your FIRST sentence states the number of records (4) and the total', $prompt);
        $this->assertStringContainsString('Balance = 1,211.48', $this->lineWith($prompt, 'FIRST sentence states'));
        $this->assertStringNotContainsString('do NOT state how many rows are shown', $prompt);
    }

    /**
     * @test E1: a truncated table still forbids the shown count — the full total is what to state.
     * p17 P3: and it MUST be stated (the roster answer gave no count while only the system line
     * said "523 rows") — the known total of a truncated list leads, with or without grand totals.
     */
    public function a_truncated_table_still_forbids_stating_the_shown_count(): void
    {
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['unit' => (string) (100 + $i), 'balance' => $i + 0.5];
        }

        $prompt = $this->plan($rows)->promptBlock(true);

        $this->assertStringContainsString('do NOT state how many rows are shown', $prompt);
        $this->assertStringContainsString('the count you state is the full count, 30', $prompt);
        $this->assertStringContainsString('Your FIRST sentence states the number of records (30 in all), then answers the question.', $prompt);
    }

    /**
     * @test E2: with a configured currency symbol the declared money columns carry it — in the
     * user table, the LLM summary and the TOTAL line the model copies ("$1,211.48", not "1,211.48").
     */
    public function money_carries_the_configured_currency_in_totals_summary_and_table(): void
    {
        config(['ai.response_generation.currency_symbol' => '$']);
        $plan = $this->arrearsPlan();

        $prompt = $plan->promptBlock(true);
        $table = $plan->tableMarkdown('en');

        $this->assertStringContainsString('TOTAL of Balance over all 4 rows = $1,211.48', $prompt);
        $this->assertStringContainsString('| $456.12 |', $prompt);
        $this->assertStringContainsString('| $456.12 |', $table);
        $this->assertStringContainsString('| $50.00 |', $table);
        $this->assertStringContainsString('| 2 |', $table, 'a count column is never money');
    }

    /**
     * @test eval p12-A P0: the table was spliced in with preg_replace, so "$456.12" in the
     * replacement read as back-reference $45 → "6.12", "$0.00" → ".00" (and $0 re-inserted the
     * placeholder). The shipped answer must carry the table byte-for-byte.
     */
    public function the_finalized_answer_carries_currency_amounts_verbatim(): void
    {
        config(['ai.response_generation.currency_symbol' => '$']);
        $plan = $this->arrearsPlan();
        $table = $plan->tableMarkdown('en');

        $answer = $plan->finalize("There are 4 units behind.\n\n{{table}}\n\nThat is all.", true, 'en');

        $this->assertStringContainsString($table, $answer);
        $this->assertStringContainsString('| $456.12 |', $answer);
        $this->assertStringNotContainsString('{{table}}', $answer);
    }

    /** @test E2: a money FACT (the supplier grand total of a collected breakdown) carries the symbol too. */
    public function a_money_fact_carries_the_currency(): void
    {
        config([
            'ai.response_generation.currency_symbol' => '$',
            'entities' => ['App\\Models\\Bill' => ['graph' => ['label' => 'Bill'], 'metadata' => ['money_properties' => ['due_amount']]]],
        ]);
        $cypher = 'MATCH (b:Bill)-[:SUPPLIER]->(s:Supplier) WITH s.name AS supplier, round(sum(b.due_amount), 2) AS total_payable '
            . 'WITH round(sum(total_payable), 2) AS total_all, collect({supplier: supplier, total_payable: total_payable}) AS per_supplier '
            . 'RETURN total_all, per_supplier';

        $prompt = $this->plan([[
            'total_all' => 11493.17,
            'per_supplier' => [
                ['supplier' => 'BFL', 'total_payable' => 7022.87],
                ['supplier' => 'Arken', 'total_payable' => 4470.30],
            ],
        ]], $cypher)->promptBlock(true);

        $this->assertStringContainsString('- Total All: $11,493.17', $prompt);
        $this->assertStringContainsString('| BFL | $7,022.87 |', $prompt);
    }

    /**
     * @test E3 (eval p11-A board): the model said it "couldn't find" board info right above the
     * board table — the summary never said what the rows ARE. The QU subject now heads the block.
     */
    public function the_summary_names_what_the_rows_answer(): void
    {
        $prompt = $this->arrearsPlan('owners behind on fees SDC Carré Soho Phase 3')->promptBlock(true);

        $this->assertStringContainsString('These rows answer: "owners behind on fees SDC Carré Soho Phase 3"', $prompt);
        $this->assertLessThan(strpos($prompt, 'DATA SUMMARY'), strpos($prompt, 'These rows answer'));
    }

    /** @test E3: without a QU subject the user's question is the subject (never an empty quote). */
    public function the_subject_falls_back_to_the_question(): void
    {
        $prompt = $this->arrearsPlan()->promptBlock(true);

        $this->assertStringContainsString('These rows answer: "Which owners in SDC Carré Soho Phase 3 are behind on their fees?"', $prompt);
    }

    /**
     * @test Eval p16-A meetings: "When is our next meeting?" matched nothing and the rows are the
     * host-declared FALLBACK (the most recent past meetings). They must never be introduced as
     * the answer to "next meeting": the block says nothing matched, names the fallback label,
     * and reuses the digest's framing (recent history, NOT the asked-for items).
     */
    public function fallback_rows_are_framed_as_fallback_never_as_the_answer(): void
    {
        $ctx = new PipelineContext();
        $ctx->question = 'When is our next meeting?';
        $ctx->qu = new QueryUnderstandingResult(searchQuery: 'next meeting', questionType: 'data_query', language: 'en');
        $ctx->queryResult = new QueryGenerationResult(
            cypher: 'MATCH (m:Meeting) OPTIONAL MATCH (m)-[:BELONGS_TO_UNION]->(un:Union) '
                . 'RETURN m.id AS id, m.name AS name, un.name AS union_name, m.start_date AS start_date ORDER BY m.start_date DESC'
        );
        $ctx->executionResult = new QueryExecutionResult(
            success: true,
            data: [
                ['id' => 140, 'name' => 'AGA 2023', 'union_name' => 'SDC du 1240 St-Sébastien', 'start_date' => '2023-10-24 16:00:00'],
                ['id' => 131, 'name' => 'CA septembre', 'union_name' => '825 de Bruxelles', 'start_date' => '2023-09-12 18:00:00'],
            ],
            metadata: ['empty_fallback' => [
                'source' => 'upcoming_meetings',
                'label' => 'Upcoming meetings',
                'fallback_label' => 'Most recent meetings — none scheduled',
                'entity' => 'Meeting',
            ]],
        );

        $prompt = DataAnswerPlan::for($ctx)->promptBlock(true);

        $this->assertStringNotContainsString('These rows answer', $prompt, 'fallback rows are not "the data found for" the question');
        $this->assertStringContainsString('FALLBACK ROWS', $prompt);
        $this->assertStringContainsString('"next meeting" matched NOTHING', $prompt);
        $this->assertStringContainsString('"Upcoming meetings": none', $prompt);
        $this->assertStringContainsString('"Most recent meetings — none scheduled"', $prompt);
        $this->assertStringContainsString('recent history, NOT the asked-for items', $prompt);
        $this->assertStringContainsString('Your FIRST sentence', $prompt);
        $this->assertLessThan(strpos($prompt, 'DATA SUMMARY'), strpos($prompt, 'FALLBACK ROWS'));
        $this->assertStringContainsString('AGA 2023', $prompt, 'the rows themselves are still summarized');
    }

    /**
     * @test E3: a column empty on every row (the board's role — bm_role null on all 37 rows) is
     * named: the information is not recorded, which the answer says plainly instead of disowning
     * the rows.
     */
    public function a_column_empty_on_every_row_is_named_as_not_recorded(): void
    {
        $plan = $this->plan([
            ['union_name' => 'Alpha', 'c.first_name' => 'Amélie', 'cur.bm_role' => null, 'cur.year' => 2022],
            ['union_name' => 'Alpha', 'c.first_name' => 'Denis', 'cur.bm_role' => null, 'cur.year' => 2022],
            ['union_name' => 'Beta', 'c.first_name' => 'Julien', 'cur.bm_role' => '', 'cur.year' => 2021],
        ], 'MATCH (cur:BoardMember)-[:BELONGS_TO_CONTACT]->(c:Contact) RETURN c.first_name, cur.bm_role, cur.year');

        $line = $this->lineWith($plan->promptBlock(true), 'NOT RECORDED');

        $this->assertStringContainsString('Bm Role', $line);
        $this->assertStringContainsString('all 3 rows', $line);
        $this->assertStringNotContainsString('First Name', $line);
        $this->assertStringNotContainsString('Year', $line);
    }

    /**
     * @test The mirror image ("unit 310" matched in three unions) IS homonyms — told apart by
     * record identity (three distinct unit ids), whatever order the id columns come in and
     * without any QU entity preference; the union disambiguates, never the date.
     */
    public function three_distinct_records_sharing_a_name_keep_the_directive(): void
    {
        $cypher = 'MATCH (un:Union)<-[:UNION]-(u:Unit) WHERE u.name = $name '
            . 'RETURN u.name AS unit_name, un.name AS union_name, u.updated_at AS updated, u.balance AS balance, un.id AS union_id, u.id AS unit_id';
        $rows = [
            ['unit_name' => '310', 'union_name' => 'Syndicat Alpha', 'updated' => '2024-01-01T00:00:00Z', 'balance' => 10.5, 'union_id' => 1, 'unit_id' => 159],
            ['unit_name' => '310', 'union_name' => 'Syndicat Beta', 'updated' => '2024-02-01T00:00:00Z', 'balance' => 20.25, 'union_id' => 2, 'unit_id' => 412],
            ['unit_name' => '310', 'union_name' => 'Syndicat Gamma', 'updated' => '2024-03-01T00:00:00Z', 'balance' => 0.0, 'union_id' => 3, 'unit_id' => 733],
        ];

        $prompt = $this->plan($rows, $cypher)->promptBlock(true);

        $this->assertStringContainsString("HOMONYMS: 3 DIFFERENT records share the Unit Name '310' — they differ by Union Name (Syndicat Alpha; Syndicat Beta; Syndicat Gamma)", $prompt);
        $this->assertStringContainsString('Your FIRST sentence must say that there are 3 different Unit Name', $prompt);
        $this->assertStringNotContainsString('differ by Updated', $prompt);
    }

    /** @test Real homonyms that differ ONLY by dates / aggregates get no "differ by <date>" directive. */
    public function a_date_or_aggregate_column_is_never_the_homonym_disambiguator(): void
    {
        $cypher = 'MATCH (un:Union)<-[:UNION]-(u:Unit)<-[:CUSTOMER]-(i:Invoice) WITH un, min(i.due_at) AS oldest_due, max(i.number) AS last_number '
            . 'RETURN un.name AS union_name, oldest_due, last_number, un.id AS union_id';
        $rows = [
            ['union_name' => 'Carré Soho', 'oldest_due' => '2021-12-01T15:00:00.000000Z', 'last_number' => 'INV-1', 'union_id' => 8],
            ['union_name' => 'Carré Soho', 'oldest_due' => '2022-01-01T05:00:00.000000Z', 'last_number' => 'INV-2', 'union_id' => 9],
            ['union_name' => 'Carré Soho', 'oldest_due' => '2020-11-17 05:00:00', 'last_number' => 'INV-3', 'union_id' => 10],
        ];

        $prompt = $this->plan($rows, $cypher)->promptBlock(true);

        $this->assertStringNotContainsString('differ by Oldest Due', $prompt);
        $this->assertStringNotContainsString('differ by Last Number', $prompt);
    }

    /**
     * @test Fallback without record ids: a temporal column is never `by`, and a whole-result
     * group whose only differing columns are dates is no homonym group.
     */
    public function without_ids_a_date_never_makes_a_repeated_value_a_homonym(): void
    {
        $rows = array_map(static function (array $row): array {
            unset($row['union_id'], $row['unit_id'], $row['unit_name']);

            return $row;
        }, $this->arrearsRows());

        $prompt = $this->plan($rows, 'MATCH (u:Unit) RETURN u')->promptBlock(true);

        $this->assertStringNotContainsString('HOMONYMS', $prompt);
    }

    // =========================================================================
    // D5 (eval p16-B resilience t0 / french t0): CODE OWNS EVERYTHING AFTER THE TABLE
    // =========================================================================

    /** @return list<array<string, mixed>> the p16-B roster rows (523 members, 100 retrieved) */
    private function rosterRows(int $count = 100): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['member_name' => "Member {$i}", 'role' => $i === 6 ? 'manager' : 'contact'];
        }

        return $rows;
    }

    private function rosterPlan(): DataAnswerPlan
    {
        $ctx = new PipelineContext();
        $ctx->question = 'I wanna know the members of my team with their roles.';
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'en', searchQuery: 'team members roles');
        $ctx->queryResult = new QueryGenerationResult(
            cypher: 'MATCH (t:Team) WHERE t.id = 3 MATCH (tr:TeamRole)-[:BELONGS_TO_TEAM]->(t) MATCH (u:User) WHERE tr.user_id = u.id '
                . 'RETURN u.name AS member_name, tr.role AS role LIMIT 100'
        );
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $this->rosterRows(), metadata: ['true_total' => 523]);

        return DataAnswerPlan::for($ctx);
    }

    /**
     * @test The prompt asked for "at most one short closing next step" after {{table}} while
     * GuidelinesSection forbids ending with an offer — the model resolved the contradiction with
     * a generic closer on every run. The model's job now ENDS at the placeholder.
     */
    public function the_table_format_never_asks_for_a_closing_step(): void
    {
        $prompt = $this->rosterPlan()->promptBlock(true);

        $this->assertStringNotContainsString('closing next step', $prompt);
        $this->assertStringNotContainsString('closing', $this->lineWith($prompt, 'Write ONLY the lead'));
        $this->assertStringContainsString('as the LAST line of your answer', $this->lineWith($prompt, 'Write ONLY the lead'));
    }

    /** @test The p16-B roster answer, replayed: the shipped answer ends at the code-rendered count line. */
    public function narrative_after_the_table_placeholder_is_dropped(): void
    {
        $plan = $this->rosterPlan();

        $answer = $plan->finalize(
            "Here are the members of your team, Novo Gestion, along with their roles:\n\n{{table}}\n\n"
                . 'If you need further details or actions regarding your team, just let me know!',
            true,
            'en'
        );

        $this->assertStringStartsWith('Here are the members of your team, Novo Gestion, along with their roles:', $answer);
        $this->assertStringContainsString('| Member 25 | contact |', $answer);
        $this->assertStringEndsWith('Showing 25 of 523 rows — ask for the full list or an export.', $answer);
        $this->assertStringNotContainsString('let me know', $answer);
    }

    /** @test The same in French (p16-B french t0) — no phrase is detected, the position decides. */
    public function the_trailing_narrative_is_dropped_whatever_its_language(): void
    {
        $answer = $this->rosterPlan()->finalize(
            "Voici les membres de votre équipe :\n\n{{table}}\n\nSi vous avez besoin d'autres informations, n'hésitez pas à demander.",
            true,
            'fr'
        );

        $this->assertStringEndsWith('Affichage de 25 lignes sur 523 — demandez la liste complète ou un export.', $answer);
        $this->assertStringNotContainsString('hésitez', $answer);
    }

    /** @test A complete table has no count line: the answer ends at the table's last row. */
    public function a_complete_table_ends_the_answer_at_its_last_row(): void
    {
        $answer = $this->plan($this->teamRows())->finalize("Two teams.\n\n{{table}}\n\nAnything else?", true, 'en');

        $this->assertStringEndsWith('| Novo Gestion Sud | luc@novo.test | 514-555-0102 | Laval |', $answer);
    }

    /**
     * @test The model inverted the format (table first, the whole answer after): nothing would
     * be left of its answer, so the text moves in FRONT of the table — the table still ends it.
     */
    public function an_answer_written_entirely_after_the_placeholder_is_kept_before_the_table(): void
    {
        $answer = $this->plan($this->teamRows())->finalize("{{table}}\n\nYou belong to two teams.", true, 'en');

        $this->assertStringStartsWith('You belong to two teams.', $answer);
        $this->assertStringEndsWith('| Novo Gestion Sud | luc@novo.test | 514-555-0102 | Laval |', $answer);
    }

    /** @test No placeholder at all: the whole narrative is the lead, the table closes the answer. */
    public function a_missing_placeholder_puts_the_table_at_the_end(): void
    {
        $answer = $this->plan($this->teamRows())->finalize("You belong to two teams.\n\nBoth are active.", true, 'en');

        $this->assertStringStartsWith("You belong to two teams.\n\nBoth are active.", $answer);
        $this->assertStringEndsWith('| Novo Gestion Sud | luc@novo.test | 514-555-0102 | Laval |', $answer);
    }

    // =========================================================================
    // D2 (eval p16-B digest t1): A SINGLE RECORD IS CODE-RENDERED, LIKE A TABLE
    // =========================================================================

    private const LAST_MEETING_CYPHER = 'MATCH (m:Meeting)-[:BELONGS_TO_UNION]->(u:Union) WHERE u.team_id IN $ai_team_ids '
        . 'AND m.start_date < toString(date()) RETURN m.name AS meeting_name, m.start_date AS date, u.name AS union_name, '
        . 'm.id AS meeting_id ORDER BY m.start_date DESC LIMIT 1';

    /** @return list<array<string, mixed>> the p16-B digest t1 row */
    private function lastMeetingRows(): array
    {
        return [[
            'meeting_name' => 'Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN',
            'date' => '2023-10-24 16:00:00',
            'union_name' => 'SDC du 1240 St-Sébastien',
            'meeting_id' => 140,
        ]];
    }

    /**
     * @test "When was our last meeting?" — one row, three fields; the FACTS block listed the
     * union and the model dropped it (same loss in several runs). Such a record is now rendered
     * by code at {{table}}, exactly like a multi-row table: nothing can be dropped.
     */
    public function a_single_record_is_rendered_by_code_as_a_field_block(): void
    {
        $plan = $this->plan($this->lastMeetingRows(), self::LAST_MEETING_CYPHER);

        $this->assertTrue($plan->hasTable(), 'code owns the facts of a single record');
        $this->assertTrue($plan->isRecord());

        $prompt = $plan->promptBlock(true);
        $this->assertStringContainsString('{{table}}', $prompt);
        $this->assertStringNotContainsString('No table', $prompt);
        $this->assertStringContainsString('Meeting Name, Date, Union Name', $prompt, 'the model is told which fields the system lists');

        $answer = $plan->finalize(
            "The last meeting was the \"Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN,\" on October 24, 2023, at 16:00.\n\n{{table}}",
            true,
            'en'
        );

        $this->assertStringContainsString('- **Meeting Name:** Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN', $answer);
        $this->assertStringContainsString('- **Date:** 2023-10-24 16:00', $answer);
        $this->assertStringContainsString('- **Union Name:** SDC du 1240 St-Sébastien', $answer);
        $this->assertStringNotContainsString('| --- |', $answer, 'a record is a field block, never a one-row table');
        $this->assertStringNotContainsString('Meeting Id', $answer, 'the record id stays internal');
        $this->assertStringNotContainsString('Showing', $answer);
    }

    /**
     * @test The p16-B answer replayed verbatim: the model stated name + date, dropped the union
     * and wrote no placeholder. The shipped answer carries the union anyway.
     */
    public function a_fact_the_model_dropped_still_ships(): void
    {
        $shipped = "The last meeting was the \"Assemblée générale annuelle SDC 1240 SAINT-SÉBASTIEN,\" which took place on October 24, 2023, at 16:00.\n\n"
            . 'If you need more details about this meeting, you can find them below.';

        $answer = $this->plan($this->lastMeetingRows(), self::LAST_MEETING_CYPHER)->finalize($shipped, true, 'en');

        $this->assertStringContainsString('- **Union Name:** SDC du 1240 St-Sébastien', $answer);
        $this->assertStringEndsWith('- **Union Name:** SDC du 1240 St-Sébastien', $answer);
    }

    /** @test "You owe $11,493.17" never grows a table: a row of measures only stays prose. */
    public function a_row_of_aggregates_stays_prose(): void
    {
        $plan = $this->plan(
            [['total_payable' => 11493.17, 'open_bills' => 4, 'suppliers' => 4]],
            'MATCH (b:Bill)-[:SUPPLIER]->(s:Supplier) RETURN round(sum(b.due_amount), 2) AS total_payable, count(b) AS open_bills, count(DISTINCT s) AS suppliers'
        );

        $this->assertSame(DataAnswerPlan::MODE_SINGLE, $plan->mode());
        $this->assertFalse($plan->hasTable());
        $this->assertStringContainsString('No table', $plan->promptBlock(true));
        $this->assertSame('You owe 11,493.17 across 4 open bills.', $plan->finalize('You owe 11,493.17 across 4 open bills. {{table}}', true, 'en'));
    }

    /**
     * @test The live "nothing owed" payables shape (eval history, 15 runs): totals of 0 and an EMPTY
     * breakdown list. An empty list is no value at all — the row is still measures only, so the
     * answer stays one sentence ("You owe nothing") instead of a block of zeros.
     */
    public function an_empty_breakdown_list_does_not_turn_an_aggregate_row_into_a_record(): void
    {
        $plan = $this->plan(
            [['total_payable' => 0, 'open_bills' => 0, 'suppliers' => []]],
            'MATCH (b:Bill)-[:SUPPLIER]->(s:Supplier) WITH s.name AS supplier, sum(b.due_amount) AS payable '
                . 'RETURN sum(payable) AS total_payable, count(supplier) AS open_bills, collect({supplier: supplier, payable: payable}) AS suppliers'
        );

        $this->assertSame(DataAnswerPlan::MODE_SINGLE, $plan->mode());
        $this->assertFalse($plan->hasTable());
        $this->assertStringContainsString('- Total Payable: 0', $plan->promptBlock(true));
    }

    /** @test An aggregate that yields TEXT (the oldest due date) is still a measure, by the cypher's grammar. */
    public function an_aggregate_alias_is_a_measure_whatever_its_value(): void
    {
        $plan = $this->plan(
            [['oldest_due' => '2021-11-14T05:00:00.000000Z', 'total_payable' => 11493.17, 'open_bills' => 4]],
            'MATCH (b:Bill) RETURN min(b.due_at) AS oldest_due, round(sum(b.due_amount), 2) AS total_payable, count(b) AS open_bills'
        );

        $this->assertSame(DataAnswerPlan::MODE_SINGLE, $plan->mode());
    }

    /** @test One or two fields fit the lead sentence (no loss observed): prose, as before. */
    public function a_record_with_two_fields_stays_prose(): void
    {
        $plan = $this->plan(
            [['date' => '2023-10-24 16:00:00', 'meeting_name' => 'Assemblée générale annuelle', 'meeting_id' => 140]],
            'MATCH (m:Meeting) RETURN m.start_date AS date, m.name AS meeting_name, m.id AS meeting_id ORDER BY m.start_date DESC LIMIT 1'
        );

        $this->assertSame(DataAnswerPlan::MODE_SINGLE, $plan->mode());
        $this->assertStringContainsString('- Meeting Name: Assemblée générale annuelle', $plan->promptBlock(true));
    }

    /**
     * @test The record block follows every rule of the table path: identifying values are masked
     * in the prompt and real in the shipped block, the user's own value is marked, declared money
     * carries the currency, a date-time reads as one, and an empty field is shown as empty.
     */
    public function the_record_block_masks_formats_and_marks_like_the_table(): void
    {
        config([
            'ai.response_generation.currency_symbol' => '$',
            'entities' => ['App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['money_properties' => ['outstanding_balance']]]],
        ]);
        $plan = $this->plan(
            [[
                'unit_name' => '310',
                'contact_email' => self::EMAIL,
                'balance' => 1250.5,
                'updated' => '2024-03-01T15:30:00.000000Z',
                'parking' => null,
                'unit_id' => 159,
            ]],
            'MATCH (u:Unit) RETURN u.name AS unit_name, u.contact_email AS contact_email, u.outstanding_balance AS balance, '
                . 'u.updated_at AS updated, u.parking AS parking, u.id AS unit_id'
        );

        $prompt = $plan->promptBlock(true);
        $this->assertStringNotContainsString(self::EMAIL, $prompt);
        $this->assertStringContainsString('[email#1]', $prompt);

        $answer = $plan->finalize("Unit 310 owes \$1,250.50.\n\n{{table}}", true, 'en', $this->user());

        $this->assertStringContainsString('- **Unit Name:** 310', $answer);
        $this->assertStringContainsString('- **Balance:** $1,250.50', $answer);
        $this->assertStringContainsString("that's you", $this->lineWith($answer, self::EMAIL));
        $this->assertStringStartsWith('- **Contact Email:** ' . self::EMAIL, $this->lineWith($answer, self::EMAIL));
        $this->assertMatchesRegularExpression('/^- \*\*Updated:\*\* 2024-03-01 \d{2}:30$/m', $answer);
        // G3 (eval p16-A team-info "Team Address"): a field the record does not hold is left
        // out of the block — the prompt's NOT RECORDED line is where the narrative learns it,
        // and (p18) code says so itself on the line above the record.
        $this->assertStringNotContainsString('**Parking', $answer);
        $this->assertStringContainsString("\n\nNot recorded: Parking.\n\n- **Unit Name:** 310", $answer);
        $this->assertStringContainsString('Parking', $this->lineWith($prompt, 'NOT RECORDED'));
        $this->assertStringNotContainsString('Parking', $this->lineWith($prompt, 'every field of this record'));
        $this->assertTrue($plan->selfMarked());
    }

    // =========================================================================
    // G3 (eval p16-A team-info / 310 / board): ALL-EMPTY COLUMNS LEAVE THE TABLE
    // =========================================================================

    private const BOARD_CYPHER = 'MATCH (b:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) WHERE un.team_id IN $ai_team_ids AND un.deleted_at IS NULL '
        . 'WITH un, max(b.year) AS latest_year MATCH (cur:BoardMember)-[:BELONGS_TO_UNION]->(un) WHERE cur.year = latest_year '
        . 'MATCH (cur)-[:BELONGS_TO_CONTACT]->(c:Contact) '
        . 'RETURN un.name AS union_name, c.first_name, c.last_name, cur.bm_role, cur.year, c.id AS contact_id ORDER BY un.name';

    /**
     * The p16-A board shape: one row per board member, the union beside each (no union id).
     *
     * @param array<string, int> $seats union name => members
     * @return list<array<string, mixed>>
     */
    private function boardRows(array $seats = ['825 de Bruxelles' => 5, "SDC 2205 Chemin d'oka" => 3, 'SDC Carré Soho Phase 2' => 4], ?string $role = null): array
    {
        $rows = [];
        $id = 700;
        foreach ($seats as $union => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $id++;
                $rows[] = [
                    'union_name' => $union,
                    'c.first_name' => "First{$id}",
                    'c.last_name' => "Last{$id}",
                    'cur.bm_role' => $role,
                    'cur.year' => 2022,
                    'contact_id' => $id,
                ];
            }
        }

        return $rows;
    }

    /**
     * @test eval p16-A board (0.50): "Bm Role" headed a column empty on all 37 rows while the
     * prompt said NOT RECORDED. The export already drops such columns; the on-screen table now
     * does too — in the user table AND the summary the model sees — and the NOT RECORDED line
     * (which tells the model the table leaves it out) is the only place the column is named.
     */
    public function a_column_empty_on_every_row_is_left_out_of_the_table_and_the_summary(): void
    {
        $plan = $this->plan($this->boardRows(), self::BOARD_CYPHER);

        $table = $plan->tableMarkdown('en');
        $prompt = $plan->promptBlock(true);

        $this->assertStringStartsWith("| Union Name | First Name | Last Name | Year |\n| --- | --- | --- | --- |\n", $table);
        $this->assertStringContainsString('| 825 de Bruxelles | First701 | Last701 | 2022 |', $table);
        $this->assertStringNotContainsString('Bm Role', $table);
        $this->assertStringContainsString('| Union Name | First Name | Last Name | Year |', $prompt);
        $this->assertSame(1, substr_count($prompt, 'Bm Role is empty'), 'named once: in the NOT RECORDED line');
        $this->assertStringContainsString('the table leaves it out', $this->lineWith($prompt, 'NOT RECORDED'));
        $this->assertSame(['Bm Role'], $plan->hiddenColumns());
    }

    /** @test G3: 0, false and "0" are values — such a column is never dropped (a $0.00 balance is the answer). */
    public function zero_false_and_zero_string_columns_stay_in_the_table(): void
    {
        $plan = $this->plan([
            ['unit' => '310', 'balance' => 0, 'occupied' => false, 'floor' => '0', 'note' => ''],
            ['unit' => '311', 'balance' => 0, 'occupied' => false, 'floor' => '0', 'note' => null],
        ]);

        $table = $plan->tableMarkdown('en');

        $this->assertStringStartsWith('| Unit | Balance | Occupied | Floor |', $table);
        $this->assertStringContainsString('| 310 | 0 | No | 0 |', $table);
        $this->assertSame(['Note'], $plan->hiddenColumns());
    }

    /** @test G3: a column with a value on ONE row (even outside the visible rows) stays. */
    public function a_column_with_a_value_beyond_the_display_cap_stays(): void
    {
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['unit' => (string) (100 + $i), 'note' => $i === 30 ? 'late' : null];
        }

        $plan = $this->plan($rows);

        $this->assertStringStartsWith('| Unit | Note |', $plan->tableMarkdown('en'));
        $this->assertSame([], $plan->hiddenColumns());
    }

    /** @test G3: when EVERY column is empty nothing is dropped — a table never renders as nothing. */
    public function a_result_whose_every_column_is_empty_keeps_its_columns(): void
    {
        $plan = $this->plan([
            ['note' => null, 'place' => '', 'extra' => null],
            ['note' => null, 'place' => null, 'extra' => ''],
        ]);

        $this->assertStringStartsWith('| Note | Place | Extra |', $plan->tableMarkdown('en'));
        $this->assertSame([], $plan->hiddenColumns());
        $this->assertStringContainsString('NOT RECORDED: Note, Place, Extra are empty', $plan->promptBlock(true));
    }

    /**
     * @test G3 + eval p16-A 310 (replayed): "Oldest Due" = min(i.due_at) is empty because nothing is
     * due — an AGGREGATE with nothing to compute from, never a field "the data does not record".
     * It leaves the table like any all-empty column, under its own wording (the cypher's grammar).
     */
    public function an_all_empty_aggregate_is_dropped_but_never_called_not_recorded(): void
    {
        $prompt = $this->unit310Plan()->promptBlock(true);

        $this->assertStringNotContainsString('NOT RECORDED', $prompt);
        $line = $this->lineWith($prompt, 'NO VALUE');
        $this->assertStringContainsString('Oldest Due', $line);
        $this->assertStringContainsString('the table leaves it out', $line);
        $this->assertStringNotContainsString('Oldest Due', $this->unit310Plan()->tableMarkdown('en'));
    }

    // =========================================================================
    // G2 (eval p16-A 310 t0 / ambiguous t1): ONE INSTRUCTION FOR A HOMONYM RESULT
    // =========================================================================

    private const UNIT_310_CYPHER = "MATCH (u:Unit) WHERE u.name = '310' MATCH (u)-[:UNION]->(un:Union) WHERE un.team_id IN \$ai_team_ids "
        . "OPTIONAL MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.status <> '1' AND i.due_amount >= 0.01 "
        . 'RETURN un.name AS union_name, u.name AS unit_name, round(u.outstanding_balance, 2) AS balance, count(i) AS open_invoices, '
        . 'round(sum(i.due_amount), 2) AS invoices_due, min(i.due_at) AS oldest_due, u.id AS unit_id';

    /** The p16-A "How much does unit 310 owe right now?" result: three units named 310, three unions. */
    private function unit310Plan(array $balances = [0.0, 0.0, 0.0]): DataAnswerPlan
    {
        config([
            'ai.response_generation.currency_symbol' => '$',
            'entities' => [
                'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['money_properties' => ['outstanding_balance']]],
                'App\\Models\\Invoice' => ['graph' => ['label' => 'Invoice'], 'metadata' => ['money_properties' => ['due_amount']]],
            ],
        ]);
        $ctx = new PipelineContext();
        $ctx->question = 'How much does unit 310 owe right now?';
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'en', searchQuery: 'unit 310 current balance', entityTypes: ['Unit', 'Invoice']);
        $ctx->queryResult = new QueryGenerationResult(cypher: self::UNIT_310_CYPHER);
        $ctx->executionResult = new QueryExecutionResult(success: true, data: [
            ['union_name' => 'SDC Carré Soho Phase 3', 'unit_name' => '310', 'balance' => $balances[0], 'open_invoices' => 0, 'invoices_due' => $balances[0], 'oldest_due' => null, 'unit_id' => 159],
            ['union_name' => 'SDC Les Terrasses 7250 St-Hubert', 'unit_name' => '310', 'balance' => $balances[1], 'open_invoices' => 0, 'invoices_due' => $balances[1], 'oldest_due' => null, 'unit_id' => 301],
            ['union_name' => '825 de Bruxelles', 'unit_name' => '310', 'balance' => $balances[2], 'open_invoices' => 0, 'invoices_due' => $balances[2], 'oldest_due' => null, 'unit_id' => 472],
        ]);

        return DataAnswerPlan::for($ctx);
    }

    /**
     * @test eval p16-A (310 t0 and ambiguous t1, identical prompts, a different answer shape per run):
     * the block demanded, at once, (a) each record's figures in the FIRST sentence, (b) a first
     * sentence stating the merged totals and (c) "do NOT list the rows". When the homonym group IS
     * the whole result and the table shows every row, the lead only says there are N different
     * records of that name (one per <by>); the table carries each record's own figures.
     */
    public function a_whole_result_homonym_group_gets_one_lead_instruction(): void
    {
        $prompt = $this->unit310Plan([10.5, 20.25, 0.0])->promptBlock(true);

        $homonyms = $this->lineWith($prompt, 'HOMONYMS');
        $this->assertStringContainsString("Your FIRST sentence must say that there are 3 different Unit Name '310' records (one per Union Name)", $homonyms);
        $this->assertStringNotContainsString("give each one's figures", $prompt);
        $this->assertStringContainsString('the table shows each one', $homonyms);

        // (b) no merged total: neither computed, nor asked for in the lead.
        $this->assertStringNotContainsString('COMPUTED TOTALS (computed by the system', $prompt);
        $this->assertStringNotContainsString('TOTAL of', $prompt);
        $this->assertStringNotContainsString('FIRST sentence states the number of records', $prompt);
        $this->assertStringNotContainsString('30.75', $prompt, 'a sum across different records sharing a name answers no question');

        // (c) still no list in prose — and exactly ONE instruction about the first sentence.
        $this->assertStringContainsString('Do NOT list the rows', $prompt);
        $this->assertSame(1, substr_count($prompt, 'FIRST sentence'));
    }

    /** @test G2: each record's own figure stays verifiable — in the summary the model reads and the user table. */
    public function each_homonym_keeps_its_own_figure_in_the_table(): void
    {
        $plan = $this->unit310Plan([10.5, 20.25, 0.0]);

        $prompt = $plan->promptBlock(true);
        $table = $plan->tableMarkdown('en');

        foreach ([$prompt, $table] as $text) {
            $this->assertStringContainsString('SDC Carré Soho Phase 3 | 310 | $10.50 |', $text);
            $this->assertStringContainsString('SDC Les Terrasses 7250 St-Hubert | 310 | $20.25 |', $text);
            $this->assertStringContainsString('825 de Bruxelles | 310 | $0.00 |', $text);
        }
        $this->assertSame([], $plan->grandTotals(), 'the validator is never handed a merged total to patch an answer to');
    }

    /**
     * @test G2: without a code-rendered table (terse style / kill switch) nothing else carries the
     * per-record figures — the prose must, as before. Still no merged total.
     */
    public function without_a_rendered_table_the_prose_still_gives_each_homonyms_figures(): void
    {
        $prompt = $this->unit310Plan([10.5, 20.25, 0.0])->promptBlock(false);

        $this->assertStringContainsString("and give each one's figures with its Union Name", $prompt);
        $this->assertStringNotContainsString('TOTAL of', $prompt);
    }

    /** @test G2: a result that is NOT one homonym group keeps its totals and the count + total lead (E1). */
    public function a_result_without_whole_result_homonyms_keeps_its_totals(): void
    {
        config(['ai.response_generation.currency_symbol' => '$']);

        $prompt = $this->arrearsPlan()->promptBlock(true);

        $this->assertStringContainsString('TOTAL of Balance over all 4 rows = $1,211.48', $prompt);
        $this->assertStringContainsString('Your FIRST sentence states the number of records (4) and the total', $prompt);
    }

    // =========================================================================
    // G1 (eval p16-A board, 0.50): THE COMPOSER IS TOLD HOW THE ROWS GROUP
    // =========================================================================

    /**
     * @test "Who is on our board of directors?" — 37 members over 8+ unions, answered as "the
     * members of your board of directors": nothing in the block said the rows belong to several
     * unions (the 10-row summary showed three). The grouping is computed over ALL rows: the
     * column of ANOTHER node than the listed record (un.name beside Contact rows), with few
     * distinct values, is stated with each group's row count — and the lead must reflect it.
     */
    public function the_rows_grouping_by_another_nodes_column_is_stated(): void
    {
        $plan = $this->plan($this->boardRows(), self::BOARD_CYPHER);

        $prompt = $plan->promptBlock(true);
        $line = $this->lineWith($prompt, 'GROUPS:');

        $this->assertStringContainsString(
            "The 12 rows span 3 different Union Name: 825 de Bruxelles (5); SDC 2205 Chemin d'oka (3); SDC Carré Soho Phase 2 (4).",
            $line
        );
        $this->assertStringContainsString('never present them as one single group (the rows of one Union Name)', $line);
        $this->assertLessThan(strpos($prompt, 'ANSWER FORMAT'), strpos($prompt, 'GROUPS:'));
        $this->assertGreaterThan(strpos($prompt, 'DATA SUMMARY'), strpos($prompt, 'GROUPS:'));

        // The lead must reflect it — as THE one first-sentence instruction of the answer format.
        $this->assertStringContainsString(
            '- Your FIRST sentence states that the rows cover 3 different Union Name (see GROUPS above), then answers the question.',
            $prompt
        );
        $this->assertSame(1, substr_count($prompt, 'FIRST sentence'));
    }

    /**
     * @test G1 + E1: a grouped result that also has grand totals keeps ONE first-sentence
     * instruction carrying the count, the groups and the total (never two competing leads —
     * the G2 defect class).
     */
    public function groups_and_grand_totals_share_one_lead_instruction(): void
    {
        config([
            'ai.response_generation.currency_symbol' => '$',
            'entities' => ['App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['money_properties' => ['outstanding_balance']]]],
        ]);
        $rows = [];
        foreach (['Alpha' => 3, 'Beta' => 2] as $union => $units) {
            for ($i = 1; $i <= $units; $i++) {
                $n = count($rows) + 1;
                $rows[] = ['union_name' => $union, 'unit_name' => (string) (300 + $n), 'balance' => 100.5 * $n, 'unit_id' => 150 + $n];
            }
        }

        $prompt = $this->plan(
            $rows,
            'MATCH (u:Unit)-[:UNION]->(un:Union) RETURN un.name AS union_name, u.name AS unit_name, round(u.outstanding_balance, 2) AS balance, u.id AS unit_id'
        )->promptBlock(true);

        $this->assertStringContainsString(
            '- Your FIRST sentence states the number of records (5), that the rows cover 2 different Union Name (see GROUPS above) '
                . 'and the total (Balance = $1,507.50), copied verbatim, then answers the question.',
            $prompt
        );
        $this->assertSame(1, substr_count($prompt, 'FIRST sentence'));
    }

    /** @test G1: without a code-rendered table the lead requirement rides the GROUPS line itself. */
    public function without_a_rendered_table_the_groups_line_carries_the_lead(): void
    {
        $line = $this->lineWith($this->plan($this->boardRows(), self::BOARD_CYPHER)->promptBlock(false), 'GROUPS:');

        $this->assertStringContainsString('Your FIRST sentence must say that the rows cover 3 different Union Name.', $line);
    }

    /** @test G1: the groups are counted over EVERY retrieved row, not the 10-row summary or the 25-row table. */
    public function the_grouping_covers_rows_beyond_the_summary_and_the_display_cap(): void
    {
        $seats = [];
        foreach (range(1, 9) as $i) {
            $seats["Union {$i}"] = $i === 9 ? 5 : 4;
        }
        $plan = $this->plan($this->boardRows($seats), self::BOARD_CYPHER);

        $line = $this->lineWith($plan->promptBlock(true), 'GROUPS:');

        $this->assertSame(25, $plan->shown());
        $this->assertStringContainsString('The 37 rows span 9 different Union Name: Union 1 (4); ', $line);
        $this->assertStringContainsString('Union 9 (5)', $line);
    }

    /** @test G1: a long group list is capped — the count stays exact, the tail is summarized. */
    public function a_long_group_list_is_capped(): void
    {
        $seats = [];
        foreach (range(1, 14) as $i) {
            $seats["Union {$i}"] = 2;
        }
        $line = $this->lineWith($this->plan($this->boardRows($seats), self::BOARD_CYPHER)->promptBlock(true), 'GROUPS:');

        $this->assertStringContainsString('The 28 rows span 14 different Union Name: Union 1 (2); ', $line);
        $this->assertStringContainsString('Union 10 (2); … and 4 more', $line);
        $this->assertStringNotContainsString('Union 11', $line);
    }

    /** @test G1 silent: one group only (four units of ONE union) — there is nothing to tell apart. */
    public function a_single_group_is_never_stated(): void
    {
        $this->assertStringNotContainsString('GROUPS:', $this->arrearsPlan()->promptBlock(true));
        $this->assertStringNotContainsString('GROUPS:', $this->plan($this->boardRows(['Alpha' => 6]), self::BOARD_CYPHER)->promptBlock(true));
    }

    /** @test G1 silent: one group per row (three units, three unions) is no grouping — the homonym note covers it. */
    public function one_group_per_row_is_no_grouping(): void
    {
        $this->assertStringNotContainsString('GROUPS:', $this->unit310Plan()->promptBlock(true));
        $this->assertStringNotContainsString(
            'GROUPS:',
            $this->plan($this->boardRows(['A' => 2, 'B' => 1, 'C' => 1, 'D' => 1]), self::BOARD_CYPHER)->promptBlock(true),
            'groups must hold two rows on average'
        );
    }

    /**
     * @test G1 silent: a repeated value in the record's OWN column (a family name shared by members)
     * is an attribute of the listed record, not a parent — by the projection's node variable,
     * never by the column's name.
     */
    public function the_listed_records_own_column_is_never_a_grouping(): void
    {
        $rows = [];
        foreach (range(1, 8) as $i) {
            $rows[] = ['c.first_name' => "First{$i}", 'c.last_name' => $i % 2 ? 'Tremblay' : 'Roy', 'c.city' => 'Montréal', 'contact_id' => $i];
        }

        $prompt = $this->plan($rows, 'MATCH (c:Contact)-[:LIVES_IN]->(un:Union) RETURN c.first_name, c.last_name, c.city, c.id AS contact_id')->promptBlock(true);

        $this->assertStringNotContainsString('GROUPS:', $prompt);
    }

    /** @test G1 silent: without record ids the listed record is unknown — nothing is guessed from values. */
    public function without_record_identity_no_grouping_is_stated(): void
    {
        $rows = array_map(static function (array $row): array {
            unset($row['contact_id']);

            return $row;
        }, $this->boardRows());

        $this->assertStringNotContainsString('GROUPS:', $this->plan($rows, self::BOARD_CYPHER)->promptBlock(true));
    }

    /** @test G1: a number, a date or an aggregate of another node is a per-row fact, never a group. */
    public function only_a_text_column_groups_the_rows(): void
    {
        $rows = $this->boardRows(['Alpha' => 6]);
        foreach ($rows as $i => $row) {
            $rows[$i]['cur.year'] = $i % 2 ? 2021 : 2022;
            $rows[$i]['since'] = $i % 2 ? '2021-01-01T00:00:00Z' : '2022-01-01T00:00:00Z';
        }
        $cypher = str_replace('cur.year,', 'cur.year, cur.started_at AS since,', self::BOARD_CYPHER);

        $this->assertStringNotContainsString('GROUPS:', $this->plan($rows, $cypher)->promptBlock(true));
    }

    /**
     * @test G1: when only part of the result was retrieved the groups are a lower bound — said
     * so, without per-group counts that would read as totals.
     */
    public function a_partially_retrieved_result_states_a_lower_bound_without_counts(): void
    {
        $ctx = new PipelineContext();
        $ctx->question = 'Who is on our boards?';
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'en');
        $ctx->queryResult = new QueryGenerationResult(cypher: self::BOARD_CYPHER);
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $this->boardRows(), metadata: ['true_total' => 90]);

        $prompt = DataAnswerPlan::for($ctx)->promptBlock(true);
        $line = $this->lineWith($prompt, 'GROUPS:');

        $this->assertStringContainsString("The 12 retrieved rows (of 90) span at least 3 different Union Name: 825 de Bruxelles; SDC 2205 Chemin d'oka; SDC Carré Soho Phase 2.", $line);
        $this->assertStringNotContainsString('(5)', $line);
        // (p17 P3: the known total of a truncated list joins the same first-sentence instruction.)
        $this->assertStringContainsString(
            'states the number of records (90 in all) and that the rows cover at least 3 different Union Name (see GROUPS above)',
            $prompt
        );
        $this->assertSame(1, substr_count($prompt, 'FIRST sentence'));
    }

    /**
     * @test eval p16-A board: "who is the president?" over rows whose role column is empty on every
     * row. No column can single a row out — the NOT RECORDED line says so by the column's own
     * header (never by what the question called it) and forbids naming a record for it.
     */
    public function an_unrecorded_column_can_never_single_out_a_record(): void
    {
        $line = $this->lineWith($this->plan($this->boardRows(), self::BOARD_CYPHER)->promptBlock(true), 'NOT RECORDED');

        $this->assertStringContainsString('Bm Role is empty on all 12 rows', $line);
        $this->assertStringContainsString('which record has a given Bm Role', $line);
        $this->assertStringContainsString('never name a record for it', $line);
    }

    /** The p17-B cold-roster-excel cypher: 523 emails keyed (and headed) "contact_info". */
    private const CONTACT_INFO_CYPHER = 'MATCH (u:User) WHERE u.current_team_id = 3 RETURN u.name AS name, u.email AS contact_info, u.id AS user_id LIMIT 100';

    private function contactInfoRows(): array
    {
        return [
            ['name' => 'Julie Rousseau', 'contact_info' => 'j.rousseau@novogestion.ca', 'user_id' => 13],
            ['name' => 'Luc Tremblay', 'contact_info' => 'luc@novo.test', 'user_id' => 14],
        ];
    }

    private function planIn(string $language, array $rows, string $cypher): DataAnswerPlan
    {
        $ctx = new PipelineContext();
        $ctx->question = 'members with contact info';
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: $language);
        $ctx->queryResult = new QueryGenerationResult(cypher: $cypher);
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $rows);

        return DataAnswerPlan::for($ctx);
    }

    /**
     * @test Eval p17-B cold-roster-excel (same family: p13 `u.email AS contact`, p14
     * `n.due_amount AS contribution`): the column holding the emails was headed "Contact Info".
     * A plain `v.prop AS alias` whose alias shares no word with the property is headed by the
     * PROPERTY — in the user's table and in the summary the model narrates from, alike.
     */
    public function a_column_aliased_after_the_users_word_is_headed_by_the_property_it_holds(): void
    {
        $plan = $this->plan($this->contactInfoRows(), self::CONTACT_INFO_CYPHER);

        $this->assertStringContainsString('| Name | Email |', $plan->finalize('{{table}}', true, 'en'));
        $this->assertStringContainsString('| Name | Email |', $plan->promptBlock(true));
        $this->assertStringNotContainsString('Contact Info', $plan->promptBlock(true));
    }

    /** @test One record reads as its fields: the field name is the property too. */
    public function a_single_record_block_is_labelled_by_the_property(): void
    {
        $plan = $this->plan(
            [['invoice_number' => 'F-0042', 'contribution' => 350.25, 'invoiced_at' => '2026-01-01', 'unit' => '310', 'status' => 'open', 'invoice_id' => 9]],
            'MATCH (i:Invoice)-[:CUSTOMER]->(u:Unit) RETURN i.invoice_number AS invoice_number, i.due_amount AS contribution, '
            . 'i.invoiced_at AS invoiced_at, u.name AS unit, i.status AS status, i.id AS invoice_id LIMIT 100'
        );

        $this->assertTrue($plan->isRecord());
        $block = $plan->finalize('{{table}}', true, 'en');
        $this->assertStringContainsString('- **Due Amount:** 350.25', $block);
        // `u.name AS unit` names the record itself (u:Unit): the generator's name stays.
        $this->assertStringContainsString('- **Unit:** 310', $block);
        $this->assertStringContainsString('- **Invoice Number:** F-0042', $block);
        $this->assertStringNotContainsString('Contribution', $block);
    }

    /** @test A one-row answer the model states from FACTS names its values by the property as well. */
    public function facts_are_labelled_by_the_property(): void
    {
        $prompt = $this->plan(
            [['unit_name' => '310', 'contribution' => 350.25]],
            'MATCH (u:Unit)<-[:CUSTOMER]-(i:Invoice) RETURN u.name AS unit_name, i.due_amount AS contribution LIMIT 1'
        )->promptBlock(true);

        $this->assertStringContainsString('- Due Amount: 350.25', $prompt);
        $this->assertStringContainsString('- Unit Name: 310', $prompt);
        $this->assertStringNotContainsString('Contribution', $prompt);
    }

    /** @test The system-computed TOTAL line names the column as the table heads it. */
    public function a_total_line_names_the_column_by_its_table_header(): void
    {
        config(['entities' => [
            'App\\Models\\Invoice' => ['graph' => ['label' => 'Invoice'], 'metadata' => ['money_properties' => ['due_amount']]],
        ]]);

        $prompt = $this->plan(
            [['invoice_number' => 'F-1', 'contribution' => 100.5], ['invoice_number' => 'F-2', 'contribution' => 200.25]],
            'MATCH (i:Invoice) RETURN i.invoice_number AS invoice_number, i.due_amount AS contribution LIMIT 100'
        )->promptBlock(true);

        $this->assertStringContainsString('| Invoice Number | Due Amount |', $prompt);
        $this->assertStringContainsString('TOTAL of Due Amount over all 2 rows = 300.75', $prompt);
    }

    /**
     * @test eval p16/p17 french-flow (`u.name AS membre, tr.role AS rôle`): in an answer that is
     * not in the schema's language the generator's alias is the only header in the user's
     * language — "Membre" must never become "Name" in a French table.
     */
    public function a_localized_alias_is_kept_in_an_answer_in_another_language(): void
    {
        $plan = $this->planIn(
            'fr',
            [['membre' => 'Julie Rousseau', 'rôle' => 'lead'], ['membre' => 'Luc Tremblay', 'rôle' => 'contact']],
            'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
            . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id RETURN u.name AS membre, tr.role AS rôle LIMIT 100'
        );

        $this->assertStringContainsString('| Membre | Rôle |', $plan->finalize('{{table}}', true, 'fr'));
        $this->assertStringContainsString('| Membre | Rôle |', $plan->promptBlock(true));
    }

    /**
     * @test A single row whose nested list IS the table (eval supplier-payables): the table's
     * columns are the collected map's keys, not the RETURN's — a RETURN alias of the same name
     * says nothing about them.
     */
    public function a_nested_table_is_never_headed_from_the_return_projections(): void
    {
        $plan = $this->plan(
            [['contact' => 'billing@novo.test', 'items' => [['contact' => '514-555-0101', 'job' => 'Plumbing'], ['contact' => '514-555-0102', 'job' => 'Roof']]]],
            'MATCH (t:Team)-[:HAS]->(s:Supplier) WITH t, collect({contact: s.phone, job: s.job}) AS items RETURN t.email AS contact, items'
        );

        $this->assertStringContainsString('| Contact | Job |', $plan->finalize('{{table}}', true, 'en'));
        $this->assertStringContainsString('- Email: ', $plan->promptBlock(true), 'the scalar FACT beside the nested table is the RETURN column');
    }

    /**
     * @test The rows of an empty-result FALLBACK (execution metadata empty_fallback) come from the
     * host's own fallback query, not from the turn's cypher: its projections say nothing about them.
     */
    public function fallback_rows_are_never_headed_from_the_turns_own_cypher(): void
    {
        $ctx = new PipelineContext();
        $ctx->question = 'When is our next meeting?';
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'en');
        $ctx->queryResult = new QueryGenerationResult(cypher: 'MATCH (m:Meeting) RETURN m.start_date AS name, m.place AS venue');
        $ctx->executionResult = new QueryExecutionResult(
            success: true,
            data: [['name' => 'AGA 2025', 'venue' => 'Salle A'], ['name' => 'AGA 2024', 'venue' => 'Salle B']],
            metadata: ['empty_fallback' => ['label' => 'Upcoming meetings', 'fallback_label' => 'Recent meetings']]
        );

        $this->assertStringContainsString('| Name | Venue |', DataAnswerPlan::for($ctx)->finalize('{{table}}', true, 'en'));
    }

    private function lineWith(string $text, string $needle): string
    {
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }

        $this->fail("no line contains {$needle}:\n{$text}");
    }
}
