<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Query\RecordedQuery;
use Condoedge\Ai\Services\Response\DataAnswerPlan;
use Condoedge\Ai\Tests\TestCase;

/**
 * DataAnswerPlan — the model's LEAD in front of the code-rendered block (eval p17).
 *
 * p16 dropped everything the model wrote AFTER {{table}}; the p17 wording then invited
 * "anything else you have to say" BEFORE it, and the padding moved there: a 10-row bullet
 * list echoing the table (A roster, B resilience t0, B french t0), a numbered list of the
 * three unit-310 rows, a lead restating every field of a one-record block (A team-info).
 *
 *   P1 (a) the lead is bounded by the instruction (one or two sentences, plus what the notes
 *          of the same block require);
 *      (b) finalize() drops, by STRUCTURE, a model-written list / table whose items restate
 *          the rows the system renders (they contain the rows' own label values) — never by
 *          wording; a list that does not name the rows stays.
 *   P2 the record lead leaves the field values to the block.
 *   P3 a truncated list states its true total, and the code-rendered count line names the
 *      record kind when the host declares its plural.
 *
 * The replayed narratives are the pre-table text of the shipped p17 answers
 * (CondoEdge/storage/eval/p17-1001-A.json / -B.json).
 */
class DataAnswerPlanLeadTest extends TestCase
{
    private const EMAIL = 'j.rousseau@novogestion.ca';

    /** The p17 roster query (A roster t0, B resilience t0). */
    private const ROSTER_CYPHER = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id '
        . 'RETURN u.name AS name, u.email AS email, tr.role AS role LIMIT 100';

    private const ROSTER_CYPHER_FR = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id '
        . 'RETURN u.name AS membre, tr.role AS rôle LIMIT 100';

    private const UNIT_310_CYPHER = "MATCH (u:Unit) WHERE u.name = '310' MATCH (u)-[:UNION]->(un:Union) WHERE un.team_id IN \$ai_team_ids "
        . "OPTIONAL MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.status <> '1' AND i.due_amount >= 0.01 "
        . 'RETURN un.name AS union_name, u.name AS unit_name, round(u.outstanding_balance, 2) AS balance, count(i) AS open_invoices, '
        . 'round(sum(i.due_amount), 2) AS invoices_due, min(i.due_at) AS oldest_due, u.id AS unit_id';

    private const BOARD_CYPHER = 'MATCH (b:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) WHERE un.team_id IN $ai_team_ids AND un.deleted_at IS NULL '
        . 'WITH un, max(b.year) AS latest_year MATCH (cur:BoardMember)-[:BELONGS_TO_UNION]->(un) WHERE cur.year = latest_year '
        . 'MATCH (cur)-[:BELONGS_TO_CONTACT]->(c:Contact) '
        . 'RETURN un.name AS union_name, c.first_name, c.last_name, cur.bm_role, cur.year, c.id AS contact_id ORDER BY un.name';

    private const TEAM_CYPHER = 'MATCH (t:Team) WHERE t.id = 3 OPTIONAL MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'RETURN t.name AS team_name, t.team_type AS team_type, count(DISTINCT u) AS members, t.contact_email AS contact_email, '
        . 't.contact_phone AS contact_phone LIMIT 100';

    /** @test Measured: "what is the difference between the two years?" was told never to add them up. */
    public function rows_the_request_works_on_are_material_and_the_rules_that_present_rows_yield(): void
    {
        $prompt = function (array $metadata): string {
            $context = new PipelineContext();
            $context->question = 'What is the difference between the two years?';
            $context->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'en');
            $context->queryResult = new QueryGenerationResult(
                cypher: 'MATCH (u:Union) RETURN u.name AS union_name, u.year AS year, u.supplier AS supplier, sum(u.billed) AS billed',
                metadata: $metadata
            );
            $context->executionResult = new QueryExecutionResult(success: true, data: [
                ['union_name' => 'Soho', 'year' => '2021', 'supplier' => 'Hydro', 'billed' => 100.50],
                ['union_name' => 'Soho', 'year' => '2022', 'supplier' => 'Hydro', 'billed' => 40.25],
            ]);

            return DataAnswerPlan::for($context)->promptBlock(true);
        };

        $fresh = $prompt([]);
        $this->assertStringContainsString('HOMONYMS', $fresh);
        $this->assertStringNotContainsString('ALREADY SHOWN', $fresh);

        $material = $prompt(['source' => RecordedQuery::SOURCE, 'recorded_query_reason' => RecordedQuery::REASON_GENERATOR]);
        $this->assertStringContainsString('ALREADY SHOWN', $material);
        $this->assertStringNotContainsString('HOMONYMS', $material);
        $this->assertStringNotContainsString('Your FIRST sentence states', $material);

        // The same question asked again gets the rows presented as the first time.
        $this->assertStringContainsString('HOMONYMS', $prompt(['source' => RecordedQuery::SOURCE, 'recorded_query_reason' => RecordedQuery::REASON_SAME_QUESTION]));
    }

    /** The first ten roster rows of the p17 trace (name, email, role code). */
    private const ROSTER_HEAD = [
        ['Julie Rousseau', 'j.rousseau@novogestion.ca', 'lead'],
        ['Service Comptable', 'service_comptable@novogestion.ca', 'accountant'],
        ['Responsable Volet administratif', 'info@novogestion.ca', 'assistant'],
        ['Fernande Martin', 'fernandemartin21@gmail.com', 'contact'],
        ['Chloé Monnier', 'zecama44@hotmail.fr', 'contact'],
        ['Karen Douglas', 'karina@bell.net', 'contact'],
        ['Alfonso Peccia', 'alpec@bell.net', 'contact'],
        ['Lucie Mallette', 'lmallette@hotmail.com', 'contact'],
        ['Francine Tremblay', 'francine.tremblay581@gmail.com', 'contact'],
        ['Sylvie Ouellet', 'sylvie2006@videotron.ca', 'contact'],
    ];

    public function setUp(): void
    {
        parent::setUp();

        config([
            'ai.response_generation.deterministic_tables' => true,
            'ai.response_generation.table_max_rows' => 25,
            'ai.response_generation.currency_symbol' => '$',
            'ai.access_control.mask_identifying_fields' => true,
            'ai.access_control.identifying_fields' => ['*' => ['email', 'phone']],
            'entities' => $this->entities(),
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function entities(array $userMetadata = []): array
    {
        return [
            'App\\Models\\User' => ['graph' => ['label' => 'User'], 'metadata' => $userMetadata],
            'App\\Models\\Team' => ['graph' => ['label' => 'Team'], 'metadata' => ['common_properties' => [
                'team_type' => 'Kind of team (1=Union, 2=Management company)',
            ]]],
            'App\\Models\\TeamRole' => ['graph' => ['label' => 'TeamRole'], 'metadata' => ['common_properties' => [
                'role' => 'Role key (lead=Lead union manager, accountant=Accountant, assistant=Administrative assistant, contact=Unit Owner or Renter)',
            ]]],
            'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['money_properties' => ['outstanding_balance']]],
            'App\\Models\\Invoice' => ['graph' => ['label' => 'Invoice'], 'metadata' => ['money_properties' => ['due_amount']]],
            'App\\Models\\Contact' => ['graph' => ['label' => 'Contact'], 'metadata' => ['name_properties' => ['first_name', 'last_name']]],
        ];
    }

    private function planFor(
        string $question,
        string $cypher,
        array $rows,
        array $metadata = [],
        string $language = 'en',
        string $searchQuery = '',
        array $entityTypes = []
    ): DataAnswerPlan {
        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: $language, searchQuery: $searchQuery, entityTypes: $entityTypes);
        $ctx->queryResult = new QueryGenerationResult(cypher: $cypher);
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $rows, metadata: $metadata);

        return DataAnswerPlan::for($ctx);
    }

    /** The p17 roster: 100 retrieved of 523, the companion counts stamped (anchor = User). */
    private function rosterPlan(): DataAnswerPlan
    {
        $rows = [];
        foreach (self::ROSTER_HEAD as [$name, $email, $role]) {
            $rows[] = ['name' => $name, 'email' => $email, 'role' => $role];
        }
        for ($i = count($rows) + 1; $i <= 100; $i++) {
            $rows[] = ['name' => "Member {$i}", 'email' => "member{$i}@example.test", 'role' => 'contact'];
        }

        return $this->planFor(
            'Who are the members of my team and what are their roles?',
            self::ROSTER_CYPHER,
            $rows,
            ['true_total' => 523, 'anchor_total' => 523, 'anchor_variable' => 'u', 'anchor_label' => 'User', 'anchor_first_item' => true],
            'en',
            'team members roles',
            ['Team', 'User', 'TeamRole']
        );
    }

    private function rosterPlanFr(): DataAnswerPlan
    {
        // (Load the package's French lines first: addLines() on a locale not loaded yet
        // would mark it loaded and hide them.)
        app('translator')->get('ai.response.table_showing_of', [], 'fr');
        app('translator')->addLines([
            '*.Lead union manager' => 'Gestionnaire en chef',
            '*.Accountant' => 'Comptable',
            '*.Administrative assistant' => 'Adjoint.e administratif',
            '*.Unit Owner or Renter' => 'Copropriétaire ou locataire',
        ], 'fr');

        $rows = [];
        foreach (self::ROSTER_HEAD as [$name, , $role]) {
            $rows[] = ['membre' => $name, 'rôle' => $role];
        }
        for ($i = count($rows) + 1; $i <= 100; $i++) {
            $rows[] = ['membre' => "Membre {$i}", 'rôle' => 'contact'];
        }

        return $this->planFor(
            'Peux-tu me donner la liste des membres de mon équipe avec leurs rôles ?',
            self::ROSTER_CYPHER_FR,
            $rows,
            ['true_total' => 523, 'anchor_total' => 523, 'anchor_variable' => 'u', 'anchor_label' => 'User', 'anchor_first_item' => true],
            'fr',
            'membres équipe rôles',
            ['Team', 'User', 'TeamRole']
        );
    }

    private function unit310Plan(): DataAnswerPlan
    {
        return $this->planFor('How much does unit 310 owe right now?', self::UNIT_310_CYPHER, [
            ['union_name' => 'SDC Carré Soho Phase 3', 'unit_name' => '310', 'balance' => 0, 'open_invoices' => 0, 'invoices_due' => 0, 'oldest_due' => null, 'unit_id' => 159],
            ['union_name' => 'SDC Les Terrasses 7250 St-Hubert', 'unit_name' => '310', 'balance' => 0, 'open_invoices' => 0, 'invoices_due' => 0, 'oldest_due' => null, 'unit_id' => 301],
            ['union_name' => '825 de Bruxelles', 'unit_name' => '310', 'balance' => 0, 'open_invoices' => 0, 'invoices_due' => 0, 'oldest_due' => null, 'unit_id' => 472],
        ], [], 'en', 'unit 310 current balance', ['Unit', 'Invoice']);
    }

    /** The p17 board: 37 seats over 12 unions (grouped), 25 shown. */
    private function boardPlan(): DataAnswerPlan
    {
        $head = [
            ['825 de Bruxelles', 'Amélie', 'Quirion', 779], ['825 de Bruxelles', 'Mauricio', 'Ruiz', 1436], ['825 de Bruxelles', 'Denis', 'Bertrand', 876],
            ['825 de Bruxelles', 'Cédric', 'Motte', 1434], ['825 de Bruxelles', 'Hélène', 'Gingras', 790],
            ["SDC 2205 Chemin d'oka", 'Julien', 'Ménard', 63], ["SDC 2205 Chemin d'oka", 'Olivier', 'Houle', 880], ["SDC 2205 Chemin d'oka", 'Sylvie', 'Haviernick', 904],
            ['SDC Carré Soho Phase 2', 'Bertrand', 'Bossé', 491], ['SDC Carré Soho Phase 2', 'Francine', 'Voisine', 453],
        ];
        $rows = [];
        foreach ($head as [$union, $first, $last, $id]) {
            $rows[] = ['union_name' => $union, 'c.first_name' => $first, 'c.last_name' => $last, 'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => $id];
        }
        for ($i = count($rows) + 1; $i <= 37; $i++) {
            $union = 'Union ' . (3 + intdiv($i - 11, 3));
            $rows[] = ['union_name' => $union, 'c.first_name' => "First{$i}", 'c.last_name' => "Last{$i}", 'cur.bm_role' => null, 'cur.year' => 2021, 'contact_id' => 2000 + $i];
        }

        return $this->planFor('Who is on our board of directors and who is the president?', self::BOARD_CYPHER, $rows, [], 'en', 'board of directors president', ['BoardMember', 'Contact']);
    }

    private function teamPlan(): DataAnswerPlan
    {
        return $this->planFor('Can I get information about my team?', self::TEAM_CYPHER, [[
            'team_name' => 'Novo Gestion', 'team_type' => 2, 'members' => 523, 'contact_email' => self::EMAIL, 'contact_phone' => '514-400-7414',
        ]], [], 'en', 'team information', ['Team']);
    }

    // =========================================================================
    // P1 (a): THE TABLE LEAD IS BOUNDED BY ITS INSTRUCTION
    // =========================================================================

    /**
     * @test p17 regression (A roster -0.05; B regression 5): "a direct answer first and anything
     * else you have to say" invited the padding the p16 tail-drop had removed. The table lead is
     * worded like the record lead — one or two sentences — and told the rows are printed right
     * after it.
     */
    public function the_table_lead_is_one_or_two_sentences_and_nothing_else_is_invited(): void
    {
        $prompt = $this->rosterPlan()->promptBlock(true);
        $lead = $this->lineWith($prompt, 'Write ONLY the lead');

        $this->assertStringNotContainsString('anything else you have to say', $prompt);
        $this->assertStringNotContainsString('Write ONLY the narrative', $prompt);
        $this->assertStringContainsString('one or two sentences that answer the question', $lead);
        $this->assertStringContainsString('as the LAST line of your answer', $lead);
        $this->assertStringContainsString('the system prints the rows there', $lead);
        $this->assertStringContainsString('Do NOT list the rows', $prompt);
    }

    /**
     * @test The sentences a note of the same block asks for are still allowed — named by the notes
     * present, no other. (p18: a note whose statement CODE prints above the table asks nothing —
     * "not recorded" needs no host noun, so it is always code's on a fully retrieved result.)
     */
    public function the_lead_may_carry_what_the_blocks_own_notes_require(): void
    {
        $board = $this->lineWith($this->boardPlan()->promptBlock(true), 'Write ONLY the lead');
        $this->assertStringContainsString('GROUPS', $board);
        $this->assertStringNotContainsString('NOT RECORDED', $board);
        $this->assertStringNotContainsString('HOMONYMS', $board);

        $roster = $this->lineWith($this->rosterPlan()->promptBlock(true), 'Write ONLY the lead');
        $this->assertStringNotContainsString('GROUPS', $roster);
        $this->assertStringNotContainsString('NOT RECORDED', $roster);

        $this->assertStringContainsString('HOMONYMS', $this->lineWith($this->unit310Plan()->promptBlock(true), 'Write ONLY the lead'));
    }

    /**
     * @test p17-A 310 t0 (grading defect 7): the HOMONYMS note opened with "report each one
     * separately with its Union Name" right before "do not list them again in prose" — and the
     * answer was a numbered list. With a code-rendered table the first clause is gone.
     */
    public function the_homonym_note_no_longer_asks_for_a_per_record_report_beside_a_table(): void
    {
        $withTable = $this->lineWith($this->unit310Plan()->promptBlock(true), 'HOMONYMS:');
        $this->assertStringNotContainsString('report each one separately', $withTable);
        $this->assertStringContainsString('never merge them', $withTable);
        $this->assertStringContainsString('do not list them again in prose', $withTable);

        // No table (terse style): the prose is all there is — it still reports each one.
        $this->assertStringContainsString('report each one separately', $this->lineWith($this->unit310Plan()->promptBlock(false), 'HOMONYMS:'));
    }

    // =========================================================================
    // P1 (b): A LIST / TABLE THAT ECHOES THE ROWS IS DROPPED — BY STRUCTURE
    // =========================================================================

    /** @test eval p17-A roster t0, replayed: the 10-bullet list repeats the first ten table rows. */
    public function p17_a_roster_the_bullet_list_echoing_the_rows_is_dropped(): void
    {
        $plan = $this->rosterPlan();
        $shipped = "The members of your team at Novo Gestion and their roles are as follows:\n\n"
            . "- Julie Rousseau: Lead union manager\n- Service Comptable: Accountant\n- Responsable Volet administratif: Administrative assistant\n"
            . "- Fernande Martin: Unit Owner or Renter\n- Chloé Monnier: Unit Owner or Renter\n- Karen Douglas: Unit Owner or Renter\n"
            . "- Alfonso Peccia: Unit Owner or Renter\n- Lucie Mallette: Unit Owner or Renter\n- Francine Tremblay: Unit Owner or Renter\n"
            . "- Sylvie Ouellet: Unit Owner or Renter\n\nYou are listed as the Lead union manager.\n\n{{table}}";

        $answer = $plan->finalize($shipped, true, 'en');

        $this->assertStringStartsWith(
            "The members of your team at Novo Gestion and their roles are as follows:\n\nYou are listed as the Lead union manager.\n\n| Name | Email | Role |",
            $answer
        );
        $this->assertStringNotContainsString('- Julie Rousseau', $answer);
        $this->assertStringContainsString('| Julie Rousseau | j.rousseau@novogestion.ca | Lead union manager |', $answer, 'the table itself is untouched');
        $this->assertSame(1, $plan->rowEchoDropped());
    }

    /** @test eval p17-B resilience t0, replayed (same list, another frame around it). */
    public function p17_b_resilience_the_bullet_list_echoing_the_rows_is_dropped(): void
    {
        $plan = $this->rosterPlan();
        $shipped = "Here are the members of your team, Novo Gestion, along with their roles:\n\n"
            . "- Julie Rousseau: Lead union manager\n- Service Comptable: Accountant\n- Responsable Volet administratif: Administrative assistant\n"
            . "- Fernande Martin: Unit Owner or Renter\n- Chloé Monnier: Unit Owner or Renter\n- Karen Douglas: Unit Owner or Renter\n"
            . "- Alfonso Peccia: Unit Owner or Renter\n- Lucie Mallette: Unit Owner or Renter\n- Francine Tremblay: Unit Owner or Renter\n"
            . "- Sylvie Ouellet: Unit Owner or Renter\n\nYou can find the complete list of team members in the table below.\n\n{{table}}";

        $answer = $plan->finalize($shipped, true, 'en');

        $this->assertStringNotContainsString('- Fernande Martin', $answer);
        $this->assertStringStartsWith('Here are the members of your team, Novo Gestion, along with their roles:', $answer);
        $this->assertSame(1, $plan->rowEchoDropped());
    }

    /** @test eval p17-B french t0, replayed: no wording is read — the French list goes the same way. */
    public function p17_b_french_the_list_is_dropped_whatever_its_language(): void
    {
        $plan = $this->rosterPlanFr();
        $shipped = "Voici la liste des membres de votre équipe, Novo Gestion, avec leurs rôles :\n\n"
            . "- Julie Rousseau : Gestionnaire en chef\n- Service Comptable : Comptable\n- Responsable Volet administratif : Adjoint.e administratif\n"
            . "- Fernande Martin : Copropriétaire ou locataire\n- Chloé Monnier : Copropriétaire ou locataire\n\n"
            . "Vous pouvez consulter plus de détails dans le tableau ci-dessous.\n\n{{table}}";

        $answer = $plan->finalize($shipped, true, 'fr');

        $this->assertStringNotContainsString('- Julie Rousseau', $answer);
        $this->assertStringContainsString('| Julie Rousseau | Gestionnaire en chef |', $answer);
        $this->assertStringStartsWith('Voici la liste des membres de votre équipe, Novo Gestion, avec leurs rôles :', $answer);
        $this->assertSame(1, $plan->rowEchoDropped());
    }

    /**
     * @test eval p17-A unit-310 t0, replayed: a NUMBERED list of the three rows. The rows are
     * told apart by their union (the unit name is the same on every row and identifies none).
     */
    public function p17_a_unit_310_the_numbered_list_echoing_the_rows_is_dropped(): void
    {
        $plan = $this->unit310Plan();
        $shipped = "Unit 310 has three different records, each with its own balance. Here are the details:\n\n"
            . "1. For SDC Carré Soho Phase 3, the balance is \$0.00.\n2. For SDC Les Terrasses 7250 St-Hubert, the balance is \$0.00.\n"
            . "3. For 825 de Bruxelles, the balance is \$0.00.\n\nAll records show that there are no open invoices and no amounts due.\n\n{{table}}";

        $answer = $plan->finalize($shipped, true, 'en');

        $this->assertStringStartsWith(
            "Unit 310 has three different records, each with its own balance. Here are the details:\n\n"
                . "All records show that there are no open invoices and no amounts due.\n\n| Union Name | Unit Name | Balance |",
            $answer
        );
        $this->assertStringNotContainsString('1. For SDC', $answer);
        $this->assertSame(1, $plan->rowEchoDropped());
    }

    /** @test A table the model wrote itself in front of the system's is the same echo. */
    public function a_model_written_table_echoing_the_rows_is_dropped(): void
    {
        $plan = $this->unit310Plan();
        $shipped = "Three different units are named 310.\n\n| Union | Balance |\n| --- | --- |\n| SDC Carré Soho Phase 3 | \$0.00 |\n"
            . "| SDC Les Terrasses 7250 St-Hubert | \$0.00 |\n| 825 de Bruxelles | \$0.00 |\n\n{{table}}";

        $answer = $plan->finalize($shipped, true, 'en');

        $this->assertStringStartsWith("Three different units are named 310.\n\n| Union Name | Unit Name | Balance |", $answer);
        $this->assertStringNotContainsString('| Union | Balance |', $answer, 'one table: the system\'s');
        $this->assertSame(1, $plan->rowEchoDropped());
    }

    /**
     * @test A list that does NOT name the rows stays — the legal conditions of a data + document
     * turn, cited or not: nothing is decided by the list being a list.
     */
    public function a_list_that_does_not_name_the_rows_stays(): void
    {
        $plan = $this->unit310Plan();
        $conditions = "- The work must not affect a bearing wall.\n- The syndicate must be notified in writing.\n- The declaration may require a vote.";
        $shipped = "Three different units are named 310. Before any work in one of them:\n\n{$conditions}\n\n{{table}}";

        $answer = $plan->finalize($shipped, true, 'en');

        $this->assertStringContainsString($conditions, $answer);
        $this->assertSame(0, $plan->rowEchoDropped());
    }

    /** @test A list carrying citation markers is document-grounded content — kept even when it names the rows. */
    public function a_cited_list_is_never_dropped(): void
    {
        $plan = $this->unit310Plan();
        $cited = "- SDC Carré Soho Phase 3: partitions need the board's consent [1].\n- 825 de Bruxelles: no restriction is recorded [2].";

        $answer = $plan->finalize("Three different units are named 310.\n\n{$cited}\n\n{{table}}", true, 'en');

        $this->assertStringContainsString($cited, $answer);
        $this->assertSame(0, $plan->rowEchoDropped());
    }

    /**
     * @test A summary per GROUP is not a row echo: its items name the unions the rows are spread
     * over (a column repeated on many rows identifies none of them), with counts no table row
     * holds. The board's per-union list stays; a list naming the members themselves goes.
     */
    public function a_per_group_summary_stays_but_a_list_of_the_grouped_rows_goes(): void
    {
        $groups = "- 825 de Bruxelles: 5 members\n- SDC 2205 Chemin d'oka: 3 members\n- SDC Carré Soho Phase 2: 2 members";
        $plan = $this->boardPlan();
        $answer = $plan->finalize("Your 37 board members sit on 12 different boards:\n\n{$groups}\n\n{{table}}", true, 'en');
        $this->assertStringContainsString($groups, $answer);
        $this->assertSame(0, $plan->rowEchoDropped());

        $members = "- Amélie Quirion (825 de Bruxelles)\n- Mauricio Ruiz (825 de Bruxelles)\n- Julien Ménard (SDC 2205 Chemin d'oka)";
        $plan = $this->boardPlan();
        $answer = $plan->finalize("Your 37 board members sit on 12 different boards:\n\n{$members}\n\n{{table}}", true, 'en');
        $this->assertStringNotContainsString('- Amélie Quirion', $answer);
        $this->assertSame(1, $plan->rowEchoDropped());
    }

    /**
     * @test eval p17-A board, replayed: the generic closer in front of the table is a SENTENCE —
     * no structure tells it from an answer, and no phrase is ever detected. It ships (the bounded
     * lead instruction is what stops inviting it).
     */
    public function a_closer_sentence_before_the_table_is_not_detected(): void
    {
        $plan = $this->boardPlan();
        $shipped = "The rows cover 12 different Union Names.\n\nIf you need further details or specific names, please let me know!\n\n{{table}}";

        $answer = $plan->finalize($shipped, true, 'en');

        $this->assertStringContainsString('please let me know!', $answer);
        $this->assertSame(0, $plan->rowEchoDropped());
    }

    /** @test A single bullet naming one row may be the answer itself ("the president is …") — never an echo. */
    public function a_single_item_is_never_an_echo(): void
    {
        $plan = $this->unit310Plan();

        $answer = $plan->finalize("Three units are named 310. The one you manage:\n\n- 825 de Bruxelles\n\n{{table}}", true, 'en');

        $this->assertStringContainsString("- 825 de Bruxelles\n", $answer);
        $this->assertSame(0, $plan->rowEchoDropped());
    }

    /** @test The echo of ONE record is a list of its fields: dropped the same way in front of the record block. */
    public function a_field_list_echoing_the_record_block_is_dropped(): void
    {
        $plan = $this->teamPlan();
        $shipped = "Here is your team:\n\n- Team Name: Novo Gestion\n- Team Type: Management company\n- Members: 523\n- Contact Phone: 514-400-7414\n\n{{table}}";

        $answer = $plan->finalize($shipped, true, 'en');

        $this->assertStringStartsWith("Here is your team:\n\n- **Team Name:** Novo Gestion", $answer);
        $this->assertSame(1, substr_count($answer, 'Management company'));
        $this->assertSame(1, $plan->rowEchoDropped());
    }

    /** @test Nothing to drop: the counter stays at zero and the lead ships byte for byte. */
    public function a_plain_lead_is_untouched(): void
    {
        $plan = $this->rosterPlan();

        $answer = $plan->finalize("Your team, Novo Gestion, has 523 members.\n\n{{table}}", true, 'en');

        $this->assertStringStartsWith("Your team, Novo Gestion, has 523 members.\n\n| Name | Email | Role |", $answer);
        $this->assertSame(0, $plan->rowEchoDropped());
    }

    // =========================================================================
    // P2: THE RECORD LEAD LEAVES THE VALUES TO THE BLOCK
    // =========================================================================

    /**
     * @test eval p17-A team-info: "one or two sentences … any value copied verbatim from the row"
     * let the lead restate all five fields the block lists right below. One sentence now; it may
     * state the one value the question asks for, the block carries the rest.
     */
    public function the_record_lead_is_one_sentence_and_does_not_restate_the_fields(): void
    {
        $prompt = $this->teamPlan()->promptBlock(true);
        $lead = $this->lineWith($prompt, 'Write ONLY the lead');

        $this->assertStringContainsString('ANSWER FORMAT — THE SYSTEM LISTS THE RECORD:', $prompt);
        $this->assertStringContainsString('ONE sentence that answers the question or introduces the record', $lead);
        $this->assertStringNotContainsString('one or two sentences', $lead);
        $this->assertStringContainsString('the one value the question literally asks for', $prompt);
        $this->assertStringContainsString('do NOT restate', $prompt);
        $this->assertStringContainsString('(Team Name, Team Type, Members, Contact Email, Contact Phone)', $prompt);
    }

    // =========================================================================
    // P3: A TRUNCATED LIST STATES ITS TRUE TOTAL, BY ITS RECORD KIND
    // =========================================================================

    /**
     * @test eval p17-A roster: "if you give a count, the full count is 523" made the total
     * optional — the answer gave none and only the system line said "523 rows". With the true
     * total known and the table truncated, the first sentence MUST state it.
     */
    public function a_truncated_list_with_a_known_total_must_lead_with_the_total(): void
    {
        $prompt = $this->rosterPlan()->promptBlock(true);
        $first = $this->lineWith($prompt, 'FIRST sentence');

        $this->assertStringContainsString('Your FIRST sentence states the number of records (523', $first);
        $this->assertStringContainsString('never as "rows"', $first);
        $this->assertSame(1, substr_count($prompt, 'FIRST sentence'));
        $this->assertStringNotContainsString('if you give a count', $prompt);
        $this->assertStringContainsString('do NOT state how many rows are shown', $prompt);
        $this->assertStringContainsString('the count you state is the full count, 523', $prompt);
    }

    /** @test An unknown total (row cap hit, no companion count) cannot be demanded: the old hedge stays. */
    public function an_unknown_total_is_never_demanded(): void
    {
        config(['ai.query_execution.default_limit' => 100]);
        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = ['name' => "Member {$i}", 'email' => "m{$i}@example.test", 'role' => 'contact'];
        }

        $prompt = $this->planFor('Who is on my team?', self::ROSTER_CYPHER, $rows)->promptBlock(true);

        $this->assertStringNotContainsString('FIRST sentence', $prompt);
        $this->assertStringContainsString('if you give a count, the full count is at least 100', $prompt);
    }

    /**
     * @test The record kind is the host's: the counted entity's declared plural
     * (metadata.display_name_plural), resolved in the answer's language. Named in the
     * code-rendered count line — "Showing 25 of 523 members" — and (p18) in the facts line code
     * prints above the table, so the lead is no longer asked for the count.
     */
    public function the_count_line_names_the_record_kind_the_host_declares(): void
    {
        config(['entities' => $this->entities(['display_name' => 'member', 'display_name_plural' => 'members'])]);
        $plan = $this->rosterPlan();

        $prompt = $plan->promptBlock(true);
        $this->assertStringNotContainsString('FIRST sentence', $prompt);
        $this->assertStringContainsString('how many members there are', $this->lineWith($prompt, 'The system itself prints'));

        $answer = $plan->finalize("Here is your team.\n\n{{table}}", true, 'en');
        $this->assertStringStartsWith("Here is your team.\n\n523 members.\n\n| Name | Email | Role |", $answer);
        $this->assertStringEndsWith('Showing 25 of 523 members — ask for the full list or an export.', $answer);
    }

    /** @test In a French answer the kind is the host's French plural when it ships one. */
    public function the_record_kind_follows_the_answer_language(): void
    {
        config(['entities' => $this->entities(['display_name_plural' => 'members'])]);
        $plan = $this->rosterPlanFr();
        app('translator')->addLines(['*.members' => 'membres'], 'fr');

        $this->assertStringContainsString('how many membres there are', $this->lineWith($plan->promptBlock(true), 'The system itself prints'));
        $answer = $plan->finalize("Voici votre équipe.\n\n{{table}}", true, 'fr');
        $this->assertStringStartsWith("Voici votre équipe.\n\n523 membres.\n\n| Membre | Rôle |", $answer);
        $this->assertStringEndsWith('Affichage de 25 membres sur 523 — demandez la liste complète ou un export.', $answer);
    }

    /**
     * @test A plural the host declares but does not ship in the answer's language is not used
     * there: "Affichage de 25 members sur 523" would mix languages — the neutral French line
     * stands and the lead names the records in the question's own words.
     */
    public function a_kind_that_cannot_be_said_in_the_answer_language_is_not_used(): void
    {
        config(['entities' => $this->entities(['display_name_plural' => 'members']), 'app.fallback_locale' => 'en']);
        $plan = $this->rosterPlanFr();

        $this->assertStringEndsWith(
            'Affichage de 25 lignes sur 523 — demandez la liste complète ou un export.',
            $plan->finalize("Votre équipe compte 523 membres.\n\n{{table}}", true, 'fr')
        );
        $this->assertStringContainsString('the number of records (523 in all)', $plan->promptBlock(true));
    }

    /**
     * @test Eval p18: CondoEdge's app falls back to FRENCH while its entity config is written in
     * English - English answers lost the kind ("Showing 25 of 523 rows"). The declared plural
     * is in the SCHEMA language (ai.response_generation.property_header_language), whatever
     * the app's fallback locale is.
     */
    public function the_declared_plural_is_used_in_the_schema_language_whatever_the_fallback_locale(): void
    {
        config([
            'entities' => $this->entities(['display_name_plural' => 'members']),
            'app.fallback_locale' => 'fr',
            'ai.response_generation.property_header_language' => 'en',
        ]);

        $this->assertStringEndsWith(
            'Showing 25 of 523 members — ask for the full list or an export.',
            $this->rosterPlan()->finalize("Your team has 523 members.

{{table}}", true, 'en')
        );
    }

    /** @test No declared plural: the line keeps today's wording (no noun is guessed or inflected). */
    public function without_a_declared_plural_the_count_line_keeps_its_neutral_wording(): void
    {
        config(['entities' => $this->entities(['display_name' => 'member'])]);

        $this->assertStringEndsWith(
            'Showing 25 of 523 rows — ask for the full list or an export.',
            $this->rosterPlan()->finalize("Your team has 523 members.\n\n{{table}}", true, 'en')
        );
    }

    /**
     * @test The kind is named only when the stated total COUNTS that kind: a fan-out the
     * collapse could not fold (1354 member×contact rows for 523 members) is counted in rows.
     */
    public function a_row_total_that_is_not_a_record_count_is_never_given_a_kind(): void
    {
        config(['entities' => $this->entities(['display_name_plural' => 'members'])]);
        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = ['name' => 'Member ' . intdiv($i + 1, 2), 'email' => "m{$i}@example.test", 'role' => 'contact'];
        }
        $plan = $this->planFor('Who is on my team?', self::ROSTER_CYPHER, $rows, [
            'true_total' => 1354, 'anchor_total' => 523, 'anchor_variable' => 'u', 'anchor_label' => 'User', 'anchor_first_item' => true,
        ]);

        $this->assertStringEndsWith('Showing 25 of 1354 rows — ask for the full list or an export.', $plan->finalize('{{table}}', true, 'en'));
        // …and the lead is not made to state a row count as if it counted members.
        $prompt = $plan->promptBlock(true);
        $this->assertStringNotContainsString('FIRST sentence', $prompt);
        $this->assertStringContainsString('if you give a count, the full count is 1354', $prompt);
    }

    /**
     * @test Without a companion count the kind is the rows' own record (RecordResolver): one
     * label, one distinct record per row — the board's 37 contacts, 25 shown.
     */
    public function an_uncapped_list_is_counted_in_its_resolved_record_kind(): void
    {
        $entities = $this->entities();
        $entities['App\\Models\\Contact']['metadata']['display_name_plural'] = 'contacts';
        config(['entities' => $entities]);
        $plan = $this->boardPlan();

        $answer = $plan->finalize('{{table}}', true, 'en');
        $this->assertStringEndsWith('Showing 25 of 37 contacts — ask for the full list or an export.', $answer);
        // p18: the count is code's ("37 contacts."); the union has no host noun here, so the
        // grouping is still the model's one first-sentence instruction.
        $this->assertStringStartsWith('37 contacts. Not recorded: Bm Role.', $answer);
        $first = $this->lineWith($plan->promptBlock(true), 'FIRST sentence');
        $this->assertStringNotContainsString('the number of', $first);
        $this->assertStringContainsString('12 different Union Name', $first);
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
