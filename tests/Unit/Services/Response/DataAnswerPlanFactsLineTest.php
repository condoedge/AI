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
 * DataAnswerPlan — THE FACTS LINE (eval p18, grading A defect 2 / 6, B defect 7 / 8).
 *
 * The count, the grouping, the homonym statement and "not recorded" are FACTS code already
 * knows. p18 asked the model to say them and got the instruction read back: "There are 37
 * records covering 12 different Union Names" (A board), "Unit 310 has three different records"
 * (A unit-310), "There are 523 records in total" (B resilience t1), and "The information about
 * the board of directors and the president is not specified in the available data" above a
 * table of 25 board members. Code owns facts, the model narrates: these are ONE short
 * code-written line right above the table / record block, built from lang templates
 * (ai.response.facts_*) and HOST nouns (entity config metadata.display_name /
 * display_name_plural, said in the answer's language). A noun the host did not declare, or
 * that cannot be said in the answer's language, leaves that part to the model as before.
 *
 * Also here: the block's HEADERS are said in the answer's language through the host's
 * translator (p18-B french t0: "Name / Email / Role" in a French roster).
 */
class DataAnswerPlanFactsLineTest extends TestCase
{
    private const ROSTER_CYPHER = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id '
        . 'RETURN u.name AS name, u.email AS email, tr.role AS role LIMIT 100';

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
        . 't.address AS team_address LIMIT 100';

    private const ARREARS_CYPHER = "MATCH (un:Union)<-[:UNION]-(u:Unit) WHERE un.team_id IN \$ai_team_ids AND u.outstanding_balance > 0 "
        . 'RETURN u.name AS unit_name, round(u.outstanding_balance, 2) AS balance, u.id AS unit_id ORDER BY balance DESC';

    public function setUp(): void
    {
        parent::setUp();

        config([
            'ai.response_generation.deterministic_tables' => true,
            'ai.response_generation.table_max_rows' => 25,
            'ai.response_generation.currency_symbol' => '$',
            'ai.response_generation.property_header_language' => 'en',
            'ai.access_control.mask_identifying_fields' => true,
            'ai.access_control.identifying_fields' => ['*' => ['email', 'phone']],
            // CondoEdge's shape: the app falls back to French, its entity config is English.
            'app.fallback_locale' => 'fr',
            'entities' => $this->entities(),
        ]);

        // (Load the package's lines first: addLines() on a locale not loaded yet would mark
        // it loaded and hide them.)
        app('translator')->get('ai.response.table_showing_of', [], 'fr');
        app('translator')->get('ai.response.table_showing_of', [], 'en');
        app('translator')->addLines([
            '*.members' => 'membres',
            '*.units' => 'unités',
            '*.union' => 'copropriété',
            '*.unions' => 'copropriétés',
            '*.contacts' => 'contacts',
            '*.Name' => 'Nom',
            '*.Email' => 'Courriel',
            '*.Role' => 'Rôle',
            '*.Union Name' => 'Nom de la copropriété',
            '*.Bm Role' => 'Rôle au conseil',
            '*.Lead union manager' => 'Gestionnaire en chef',
            '*.Unit Owner or Renter' => 'Copropriétaire ou locataire',
        ], 'fr');
    }

    /**
     * The host nouns CondoEdge declares (metadata.display_name / display_name_plural).
     *
     * @param array<string, array<string, mixed>> $override label => metadata replacing the declared nouns
     * @return array<string, array<string, mixed>>
     */
    private function entities(array $override = []): array
    {
        $nouns = $override + [
            'User' => ['display_name' => 'member', 'display_name_plural' => 'members'],
            'Unit' => ['display_name' => 'unit', 'display_name_plural' => 'units'],
            'Union' => ['display_name' => 'union', 'display_name_plural' => 'unions'],
            'Contact' => ['display_name' => 'contact', 'display_name_plural' => 'contacts'],
        ];

        return [
            'App\\Models\\User' => ['graph' => ['label' => 'User'], 'metadata' => $nouns['User']],
            'App\\Models\\Team' => ['graph' => ['label' => 'Team'], 'metadata' => []],
            'App\\Models\\TeamRole' => ['graph' => ['label' => 'TeamRole'], 'metadata' => ['common_properties' => [
                'role' => 'Role key (lead=Lead union manager, contact=Unit Owner or Renter)',
            ]]],
            'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => $nouns['Unit'] + ['money_properties' => ['outstanding_balance']]],
            'App\\Models\\Union' => ['graph' => ['label' => 'Union'], 'metadata' => $nouns['Union']],
            'App\\Models\\Invoice' => ['graph' => ['label' => 'Invoice'], 'metadata' => ['money_properties' => ['due_amount']]],
            'App\\Models\\BoardMember' => ['graph' => ['label' => 'BoardMember'], 'metadata' => []],
            'App\\Models\\Contact' => ['graph' => ['label' => 'Contact'], 'metadata' => $nouns['Contact'] + ['name_properties' => ['first_name', 'last_name']]],
        ];
    }

    private function planFor(string $question, string $cypher, array $rows, array $metadata = [], string $language = 'en', array $entityTypes = []): DataAnswerPlan
    {
        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: $language, entityTypes: $entityTypes);
        $ctx->queryResult = new QueryGenerationResult(cypher: $cypher);
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $rows, metadata: $metadata);

        return DataAnswerPlan::for($ctx);
    }

    /** The p18 roster: 100 retrieved of 523, the companion counts stamped (anchor = User). */
    private function rosterPlan(string $language = 'en'): DataAnswerPlan
    {
        $rows = [['name' => 'Julie Rousseau', 'email' => 'j.rousseau@novogestion.ca', 'role' => 'lead']];
        for ($i = 2; $i <= 100; $i++) {
            $rows[] = ['name' => "Member {$i}", 'email' => "member{$i}@example.test", 'role' => 'contact'];
        }

        return $this->planFor(
            'Who are the members of my team and what are their roles?',
            self::ROSTER_CYPHER,
            $rows,
            ['true_total' => 523, 'anchor_total' => 523, 'anchor_variable' => 'u', 'anchor_label' => 'User', 'anchor_first_item' => true],
            $language,
            ['Team', 'User', 'TeamRole']
        );
    }

    private function unit310Plan(string $language = 'en', string $name = '310'): DataAnswerPlan
    {
        return $this->planFor('How much does unit 310 owe right now?', self::UNIT_310_CYPHER, [
            ['union_name' => 'SDC Carré Soho Phase 3', 'unit_name' => $name, 'balance' => 0, 'open_invoices' => 0, 'invoices_due' => 0, 'oldest_due' => null, 'unit_id' => 159],
            ['union_name' => 'SDC Les Terrasses 7250 St-Hubert', 'unit_name' => $name, 'balance' => 0, 'open_invoices' => 0, 'invoices_due' => 0, 'oldest_due' => null, 'unit_id' => 301],
            ['union_name' => '825 de Bruxelles', 'unit_name' => $name, 'balance' => 0, 'open_invoices' => 0, 'invoices_due' => 0, 'oldest_due' => null, 'unit_id' => 472],
        ], [], $language, ['Unit', 'Invoice']);
    }

    /** The p18 board: 37 seats over 12 unions, the role empty on every row, 25 shown. */
    private function boardPlan(string $language = 'en', int $seats = 37): DataAnswerPlan
    {
        // Three seats per union; the 37th seat is a fourth one in the twelfth union.
        $rows = [];
        for ($i = 1; $i <= $seats; $i++) {
            $union = 'Union ' . min(12, 1 + intdiv($i - 1, 3));
            $rows[] = ['union_name' => $union, 'c.first_name' => "First{$i}", 'c.last_name' => "Last{$i}", 'cur.bm_role' => null, 'cur.year' => 2021, 'contact_id' => 2000 + $i];
        }

        return $this->planFor('Who is on our board of directors and who is the president?', self::BOARD_CYPHER, $rows, [], $language, ['BoardMember', 'Contact']);
    }

    // =========================================================================
    // L1 — THE LINE
    // =========================================================================

    /**
     * @test eval p18-A board, replayed: "There are 37 records covering 12 different Union Names.
     * The information about the board of directors and the president is not specified in the
     * available data." The count, the groups and the not-recorded field are printed by code.
     */
    public function p18_a_board_the_count_the_groups_and_the_missing_field_are_printed_by_code(): void
    {
        $plan = $this->boardPlan();

        $answer = $plan->finalize("Here are the current board members of the unions you manage.\n\n{{table}}", true, 'en');

        $this->assertStringStartsWith(
            "Here are the current board members of the unions you manage.\n\n"
                . "37 contacts across 12 unions. Not recorded: Bm Role.\n\n"
                . '| Union Name | Name | Year |',
            $answer
        );
        $this->assertStringEndsWith('Showing 25 of 37 contacts — ask for the full list or an export.', $answer);
        $this->assertSame(['count', 'groups', 'not_recorded'], $plan->factsStated());
    }

    /** @test …and the model is no longer asked to say any of it: one sentence that answers the question. */
    public function p18_a_board_the_lead_instruction_no_longer_demands_what_code_prints(): void
    {
        $prompt = $this->boardPlan()->promptBlock(true);
        $lead = $this->lineWith($prompt, 'Write ONLY the lead');

        $this->assertStringNotContainsString('FIRST sentence', $prompt);
        $this->assertStringNotContainsString('the number of', $prompt);
        $this->assertStringNotContainsString('that the rows cover', $prompt);
        $this->assertStringContainsString('ONE sentence that answers the question', $lead);
        $this->assertStringNotContainsString('one or two sentences', $lead);
        $this->assertStringNotContainsString('GROUPS', $lead);
        $this->assertStringNotContainsString('NOT RECORDED', $lead);

        $prints = $this->lineWith($prompt, 'The system itself prints');
        $this->assertStringContainsString('right above the table', $prints);
        $this->assertStringContainsString('how many contacts there are and how they are grouped', $prints);
        $this->assertStringContainsString('which fields are not recorded', $prints);
        $this->assertStringContainsString('do NOT restate', $prints);

        // The notes stay as information, in the host's nouns, without a demand of their own.
        $groups = $this->lineWith($prompt, 'GROUPS:');
        $this->assertStringContainsString('span 12 unions (column Union Name)', $groups);
        $this->assertStringContainsString('NOT one group', $groups);
        $notRecorded = $this->lineWith($prompt, 'NOT RECORDED:');
        $this->assertStringContainsString('the system says so itself right above the table', $notRecorded);
        $this->assertStringContainsString('never name a record for it', $notRecorded);
        $this->assertStringNotContainsString('say plainly in your answer', $notRecorded);
    }

    /**
     * @test eval p18-A unit-310, replayed: "Unit 310 has three different records". The homonym
     * statement names the records by the entity their NAME column belongs to and tells them
     * apart by the entity of the column they differ by — never by a humanized header.
     */
    public function p18_a_unit_310_the_homonym_statement_is_printed_by_code(): void
    {
        $plan = $this->unit310Plan();

        $answer = $plan->finalize("None of them owes anything right now.\n\n{{table}}", true, 'en');

        $this->assertStringStartsWith(
            "None of them owes anything right now.\n\n3 different units named 310 — one per union.\n\n| Union Name | Unit Name | Balance |",
            $answer
        );
        $this->assertSame(['homonyms'], $plan->factsStated());

        $prompt = $plan->promptBlock(true);
        $this->assertStringNotContainsString('FIRST sentence', $prompt);
        $homonyms = $this->lineWith($prompt, 'HOMONYMS:');
        $this->assertStringContainsString('never merge them', $homonyms);
        $this->assertStringContainsString('The system states this itself right above the table', $homonyms);
        $this->assertStringContainsString('never add them up into one figure', $homonyms);
        $this->assertStringNotContainsString('HOMONYMS', $this->lineWith($prompt, 'Write ONLY the lead'));
        $this->assertStringContainsString('different records sharing one name', $this->lineWith($prompt, 'The system itself prints'));
        // A computed column with nothing to compute is not a field the data fails to record.
        $this->assertStringNotContainsString('Not recorded', $answer);
    }

    /**
     * @test eval p20-A unit-310 t0 / t1: "Unit 310 currently owes $0.00 across all unions." right above
     * "3 different units named 310 — one per union." — the lead merges the namesakes into ONE unit.
     * When code prints the homonym statement, the prompt hands the model the PLURAL SUBJECT, built
     * by code from the same facts (the count, the host's plural, the shared name), in the answer's
     * language — and says the lead speaks of them as that, never as one.
     */
    public function p20_a_unit_310_the_prompt_gives_the_lead_its_plural_subject(): void
    {
        $prompt = $this->unit310Plan()->promptBlock(true);

        $this->assertStringContainsString(
            '- The rows are 3 DIFFERENT records, so the SUBJECT of your sentence is PLURAL — "the 3 units named 310": say what is true of all '
                . 'of them or of each of them, never of ONE record under the shared name.',
            $prompt
        );
        // It follows the line that quotes what the system prints, inside the answer format.
        $this->assertGreaterThan(strpos($prompt, 'The system prints: "3 different units named 310 — one per union."'), strpos($prompt, 'the SUBJECT of your sentence is PLURAL'));
    }

    /** @test …in the answer's language: the French template and the host's French plural. */
    public function the_plural_subject_follows_the_answer_language(): void
    {
        $prompt = $this->unit310Plan('fr')->promptBlock(true);

        $this->assertStringContainsString('The system prints: "3 unités portent le nom 310 — à distinguer par copropriété."', $prompt);
        $this->assertStringContainsString('the SUBJECT of your sentence is PLURAL — "les 3 unités portant le nom 310": say what is true', $prompt);
    }

    /**
     * @test The subject is only given where code states the homonyms itself (the same nouns, the
     * same proof): without the host's nouns the HOMONYMS note keeps the model's own instruction,
     * and a list that is not one set of namesakes gets none.
     */
    public function no_plural_subject_without_the_code_written_homonym_statement(): void
    {
        config(['entities' => $this->entities(['Union' => ['display_name_plural' => 'unions']])]); // no singular
        $this->assertStringNotContainsString('SUBJECT of your sentence', $this->unit310Plan()->promptBlock(true));

        config(['entities' => $this->entities()]);
        $this->assertStringNotContainsString('SUBJECT of your sentence', $this->rosterPlan()->promptBlock(true));
        $this->assertStringNotContainsString('SUBJECT of your sentence', $this->boardPlan()->promptBlock(true));
    }

    /** @test A name is tenant text: masked / quoted in the subject as it is in the quoted line. */
    public function the_plural_subject_never_breaks_out_of_its_quotes(): void
    {
        $prompt = $this->unit310Plan('en', 'A "1" B')->promptBlock(true);

        $this->assertStringContainsString("— \"the 3 units named A '1' B\": say what is true", $prompt);
    }

    /** @test eval p18-A roster / B resilience t1 ("523 records in total"): the full count, by its host noun. */
    public function p18_roster_the_full_count_of_a_truncated_list_is_printed_by_code(): void
    {
        $plan = $this->rosterPlan();

        // (p19: a lead that only restates the question is dropped — this one says something.)
        $answer = $plan->finalize("You are the Lead union manager.\n\n{{table}}", true, 'en');

        $this->assertStringStartsWith("You are the Lead union manager.\n\n523 members.\n\n| Name | Email | Role |", $answer);
        $this->assertStringEndsWith('Showing 25 of 523 members — ask for the full list or an export.', $answer);
        $this->assertSame(['count'], $plan->factsStated());

        $prompt = $plan->promptBlock(true);
        $this->assertStringNotContainsString('FIRST sentence', $prompt);
        $this->assertStringNotContainsString('the count you state', $prompt);
        $this->assertStringContainsString('never call it complete', $prompt);
        $this->assertStringContainsString('how many members there are', $this->lineWith($prompt, 'The system itself prints'));
    }

    /** @test The line is written in the answer's language: French templates, the host's French nouns. */
    public function the_line_follows_the_answer_language(): void
    {
        $this->assertStringContainsString(
            "\n\n523 membres.\n\n| Nom | Courriel | Rôle |",
            $this->rosterPlan('fr')->finalize("Voici les membres de votre équipe.\n\n{{table}}", true, 'fr')
        );
        $this->assertStringContainsString(
            "37 contacts dans 12 copropriétés. Non renseigné : Rôle au conseil.\n\n| Nom de la copropriété |",
            $this->boardPlan('fr')->finalize("Voici les membres des conseils.\n\n{{table}}", true, 'fr')
        );
        $this->assertStringContainsString(
            "\n\n3 unités portent le nom 310 — à distinguer par copropriété.\n\n",
            $this->unit310Plan('fr')->finalize("Aucune ne doit quoi que ce soit.\n\n{{table}}", true, 'fr')
        );
    }

    /** @test A complete list with a grand total: the count is the system's, the total stays the model's answer. */
    public function a_grand_total_stays_the_models_while_the_count_is_printed(): void
    {
        $plan = $this->planFor('Which units are behind on their fees?', self::ARREARS_CYPHER, [
            ['unit_name' => '101', 'balance' => 500.25, 'unit_id' => 1],
            ['unit_name' => '102', 'balance' => 411.23, 'unit_id' => 2],
            ['unit_name' => '103', 'balance' => 300.0, 'unit_id' => 3],
        ], [], 'en', ['Unit']);

        $this->assertStringContainsString("\n\n3 units.\n\n| Unit Name | Balance |", $plan->finalize("They owe \$1,211.48 in all.\n\n{{table}}", true, 'en'));

        $first = $this->lineWith($plan->promptBlock(true), 'FIRST sentence');
        $this->assertStringContainsString('the total (Balance = $1,211.48), copied verbatim', $first);
        $this->assertStringNotContainsString('the number of', $first);
    }

    // =========================================================================
    // L1 — FALLBACKS: A NOUN THE HOST DID NOT DECLARE LEAVES THAT PART TO THE MODEL
    // =========================================================================

    /** @test No noun for the group's entity: the count is printed, the grouping is still asked of the model. */
    public function without_a_noun_for_the_group_entity_the_grouping_stays_with_the_model(): void
    {
        config(['entities' => $this->entities(['Union' => []])]);
        $plan = $this->boardPlan();

        $this->assertStringContainsString("\n\n37 contacts. Not recorded: Bm Role.\n\n|", $plan->finalize("Here they are.\n\n{{table}}", true, 'en'));
        $this->assertSame(['count', 'not_recorded'], $plan->factsStated());

        $prompt = $plan->promptBlock(true);
        $first = $this->lineWith($prompt, 'FIRST sentence');
        $this->assertStringContainsString('that the rows cover 12 different Union Name', $first);
        $this->assertStringNotContainsString('the number of', $first);
        $this->assertStringContainsString('GROUPS', $this->lineWith($prompt, 'Write ONLY the lead'));
    }

    /** @test No record kind at all: nothing about the count or the groups is printed — today's instruction stands. */
    public function without_a_record_kind_the_count_and_groups_stay_with_the_model(): void
    {
        config(['entities' => $this->entities(['Contact' => []])]);
        $plan = $this->boardPlan();

        $answer = $plan->finalize("Here they are.\n\n{{table}}", true, 'en');
        $this->assertStringStartsWith("Here they are.\n\nNot recorded: Bm Role.\n\n| Union Name |", $answer);
        $this->assertSame(['not_recorded'], $plan->factsStated());

        $first = $this->lineWith($plan->promptBlock(true), 'FIRST sentence');
        $this->assertStringContainsString('the number of records (37 in all)', $first);
        $this->assertStringContainsString('12 different Union Name', $first);
    }

    /** @test A noun the host cannot say in the answer's language is not used there (no "37 contacts dans 12 unions"). */
    public function a_noun_that_cannot_be_said_in_the_answer_language_falls_back(): void
    {
        config(['entities' => $this->entities(['Union' => ['display_name' => 'condo', 'display_name_plural' => 'condos']])]);
        $plan = $this->boardPlan('fr');

        $answer = $plan->finalize("Voici les membres.\n\n{{table}}", true, 'fr');

        $this->assertStringContainsString("\n\n37 contacts. Non renseigné : Rôle au conseil.\n\n", $answer);
        $this->assertStringNotContainsString('condos', $answer);
        $this->assertStringContainsString('that the rows cover 12 different', $plan->promptBlock(true));
    }

    /** @test Homonyms without both nouns: the HOMONYMS note keeps its lead instruction. */
    public function without_the_homonym_nouns_the_statement_stays_with_the_model(): void
    {
        config(['entities' => $this->entities(['Union' => ['display_name_plural' => 'unions']])]); // no singular
        $plan = $this->unit310Plan();

        $answer = $plan->finalize("Unit 310 exists in three unions.\n\n{{table}}", true, 'en');
        $this->assertStringStartsWith("Unit 310 exists in three unions.\n\n| Union Name |", $answer);
        $this->assertSame([], $plan->factsStated());

        $prompt = $plan->promptBlock(true);
        $this->assertStringContainsString("Your FIRST sentence must say that there are 3 different Unit Name '310' records", $prompt);
        $this->assertStringNotContainsString('The system itself prints', $prompt);
        $this->assertStringContainsString('one or two sentences', $this->lineWith($prompt, 'Write ONLY the lead'));
    }

    /** @test "One per <by>" is only said when it is true: two of the homonyms in the SAME union → the model's note. */
    public function homonyms_not_told_apart_one_to_one_are_not_stated_by_code(): void
    {
        $plan = $this->planFor('How much does unit 310 owe?', self::UNIT_310_CYPHER, [
            ['union_name' => 'Union A', 'unit_name' => '310', 'balance' => 0, 'open_invoices' => 0, 'invoices_due' => 0, 'oldest_due' => null, 'unit_id' => 1],
            ['union_name' => 'Union A', 'unit_name' => '310', 'balance' => 0, 'open_invoices' => 0, 'invoices_due' => 0, 'oldest_due' => null, 'unit_id' => 2],
            ['union_name' => 'Union B', 'unit_name' => '310', 'balance' => 0, 'open_invoices' => 0, 'invoices_due' => 0, 'oldest_due' => null, 'unit_id' => 3],
        ], [], 'en', ['Unit']);

        $plan->finalize("Here they are.\n\n{{table}}", true, 'en');

        $this->assertNotContains('homonyms', $plan->factsStated());
    }

    /** @test A truncated RETRIEVAL knows neither the groups nor that a field is empty everywhere: only the count is printed. */
    public function a_partial_retrieval_states_neither_groups_nor_missing_fields(): void
    {
        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = ['union_name' => 'Union ' . (1 + intdiv($i - 1, 10)), 'c.first_name' => "F{$i}", 'c.last_name' => "L{$i}", 'cur.bm_role' => null, 'cur.year' => 2021, 'contact_id' => $i];
        }
        $plan = $this->planFor('Who is on our boards?', self::BOARD_CYPHER, $rows, [
            'true_total' => 240, 'anchor_total' => 240, 'anchor_variable' => 'c', 'anchor_label' => 'Contact',
        ]);

        $this->assertStringContainsString("\n\n240 contacts.\n\n|", $plan->finalize("Here they are.\n\n{{table}}", true, 'en'));
        $this->assertSame(['count'], $plan->factsStated());

        $prompt = $plan->promptBlock(true);
        $this->assertStringContainsString('at least 10 different Union Name', $this->lineWith($prompt, 'FIRST sentence'));
        $this->assertStringContainsString('say plainly in your answer that it is not recorded', $this->lineWith($prompt, 'NOT RECORDED:'));
    }

    // =========================================================================
    // L1 — ONE RECORD, NO TABLE, ESCAPING, PER-LANGUAGE NOUNS
    // =========================================================================

    /** @test ONE record: a stored field it does not hold is named above the record block. */
    public function a_record_block_names_its_unrecorded_fields(): void
    {
        $plan = $this->planFor('Can I get information about my team?', self::TEAM_CYPHER, [[
            'team_name' => 'Novo Gestion', 'team_type' => 'Management company', 'members' => 523, 'contact_email' => 'info@novogestion.ca', 'team_address' => null,
        ]], [], 'en', ['Team']);

        $answer = $plan->finalize("Here is your team.\n\n{{table}}", true, 'en');

        // "Here is your team." names nothing of the record: beside the line it is dropped (iteration 5).
        $this->assertStringStartsWith("Not recorded: Team Address.\n\n- **Team Name:** Novo Gestion", $answer);
        $this->assertSame(['not_recorded'], $plan->factsStated());

        $prompt = $plan->promptBlock(true);
        $this->assertStringContainsString('right above the record', $prompt);
        $this->assertStringNotContainsString('NOT RECORDED', $this->lineWith($prompt, 'Write ONLY the lead'));
    }

    /** @test Without a code-rendered block (terse styles) there is no line: the model's instructions are today's. */
    public function without_a_rendered_table_nothing_is_printed(): void
    {
        $plan = $this->boardPlan();

        $this->assertSame('Here they are.', $plan->finalize('Here they are.', false, 'en'));
        $this->assertSame([], $plan->factsStated());
        $prompt = $plan->promptBlock(false);
        $this->assertStringNotContainsString('The system itself prints', $prompt);
        $this->assertStringContainsString('Your FIRST sentence must say that the rows cover', $prompt);
    }

    /** @test A record name is tenant text: escaped in the sentence exactly as in a table cell (P-5). */
    public function a_homonym_name_is_markdown_escaped(): void
    {
        $answer = $this->unit310Plan('en', '[310](https://phish.test)')->finalize("Here they are.\n\n{{table}}", true, 'en');

        $this->assertStringContainsString('3 different units named \[310\]\(https://phish.test\) — one per union.', $answer);
    }

    /**
     * @test A noun can be declared per language in the entity config itself — for a word the
     * host's lang files already use as a key for something else.
     */
    public function a_noun_declared_per_language_in_the_config_is_used(): void
    {
        config(['entities' => $this->entities(['User' => ['display_name_plural' => ['en' => 'team members', 'fr' => "membres de l'équipe"]]])]);

        $this->assertStringContainsString("\n\n523 team members.\n\n", $this->rosterPlan()->finalize("Here they are.\n\n{{table}}", true, 'en'));
        $this->assertStringContainsString("\n\n523 membres de l'équipe.\n\n", $this->rosterPlan('fr')->finalize("Les voici.\n\n{{table}}", true, 'fr'));
    }

    // =========================================================================
    // L1 — THE KIND THE QUESTION COUNTS (the seat behind each person)
    // =========================================================================

    /** The board entities with the host's declared BoardMember → Contact link (a foreign key: to-one). */
    private function boardEntities(array $boardMember = []): array
    {
        $entities = $this->entities();
        $entities['App\\Models\\BoardMember'] = ['graph' => ['label' => 'BoardMember', 'relationships' => [
            ['type' => 'BELONGS_TO_CONTACT', 'target_label' => 'Contact', 'foreign_key' => 'contact_id', 'direction' => 'outgoing'],
            ['type' => 'BELONGS_TO_UNION', 'target_label' => 'Union', 'foreign_key' => 'union_id', 'direction' => 'outgoing'],
        ]], 'metadata' => ['display_name' => 'board member', 'display_name_plural' => 'board members']];

        return array_replace_recursive($entities, ['App\\Models\\BoardMember' => $boardMember]);
    }

    private function boardRows(int $seats = 37): array
    {
        $rows = [];
        for ($i = 1; $i <= $seats; $i++) {
            $union = 'Union ' . min(12, 1 + intdiv($i - 1, 3));
            $rows[] = ['union_name' => $union, 'c.first_name' => "First{$i}", 'c.last_name' => "Last{$i}", 'cur.bm_role' => null, 'cur.year' => 2021, 'contact_id' => 2000 + $i];
        }

        return $rows;
    }

    /**
     * @test eval p18-A board ("Who is on our board of directors…"): the rows carry CONTACT ids, so
     * they resolve to 37 contacts — but the question (QU: BoardMember) counts board seats. The
     * host declares BoardMember → Contact as a foreign key (each seat has ONE contact), the
     * query matches each row's seat through it (not optionally) and projects the seat's own
     * columns ungrouped: 37 distinct contacts are then 37 distinct board members, and the line
     * names them as the question does.
     */
    public function p18_a_board_rows_resolved_to_contacts_are_counted_as_the_board_members_asked_about(): void
    {
        config(['entities' => $this->boardEntities()]);
        $plan = $this->planFor('Who is on our board of directors and who is the president?', self::BOARD_CYPHER, $this->boardRows(), [], 'en', ['BoardMember']);

        $answer = $plan->finalize("Here are the current boards of the unions you manage.\n\n{{table}}", true, 'en');

        $this->assertStringContainsString("\n\n37 board members across 12 unions. Not recorded: Bm Role.\n\n| Union Name |", $answer);
        $this->assertStringEndsWith('Showing 25 of 37 board members — ask for the full list or an export.', $answer);
        $this->assertStringContainsString('how many board members there are and how they are grouped', $plan->promptBlock(true));
    }

    /** @test The question's own kind wins: when QU names the resolved record first, it stays. */
    public function the_resolved_kind_stays_when_the_question_names_it_first(): void
    {
        config(['entities' => $this->boardEntities()]);
        $plan = $this->planFor('Which contacts sit on a board?', self::BOARD_CYPHER, $this->boardRows(), [], 'en', ['Contact', 'BoardMember']);

        $this->assertStringContainsString('37 contacts across 12 unions.', $plan->finalize("Here they are.\n\n{{table}}", true, 'en'));
    }

    /**
     * @test Nothing is guessed: without the declared foreign key (the link may be to-many), without
     * a noun for the asked kind, or when QU names neither kind, the rows are counted as what
     * their ids say they are.
     */
    public function the_asked_kind_is_only_used_when_it_is_proven_and_named(): void
    {
        $contacts = '37 contacts across 12 unions.';

        config(['entities' => $this->boardEntities(['graph' => ['relationships' => [0 => ['foreign_key' => null]]]])]);
        $this->assertStringContainsString($contacts, $this->planFor('Board?', self::BOARD_CYPHER, $this->boardRows(), [], 'en', ['BoardMember'])->finalize("x\n\n{{table}}", true, 'en'));

        config(['entities' => $this->boardEntities(['metadata' => ['display_name_plural' => null]])]);
        $this->assertStringContainsString($contacts, $this->planFor('Board?', self::BOARD_CYPHER, $this->boardRows(), [], 'en', ['BoardMember'])->finalize("x\n\n{{table}}", true, 'en'));

        config(['entities' => $this->boardEntities()]);
        $this->assertStringContainsString($contacts, $this->planFor('Board?', self::BOARD_CYPHER, $this->boardRows(), [], 'en', ['Meeting'])->finalize("x\n\n{{table}}", true, 'en'));
        $this->assertStringContainsString($contacts, $this->planFor('Board?', self::BOARD_CYPHER, $this->boardRows(), [], 'en', [])->finalize("x\n\n{{table}}", true, 'en'));
    }

    /**
     * @test The proof needs one seat per row: an OPTIONAL match (a contact without a seat), a
     * grouped RETURN (seats counted per contact) or a seat the RETURN no longer binds do not
     * give it — the rows stay contacts.
     */
    public function rows_that_are_not_one_asked_record_each_keep_their_resolved_kind(): void
    {
        config(['entities' => $this->boardEntities()]);
        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = ['union_name' => 'Union ' . (1 + intdiv($i - 1, 3)), 'first_name' => "First{$i}", 'seat_year' => 2021, 'seats' => 1, 'contact_id' => 100 + $i];
        }
        $queries = [
            'optional' => 'MATCH (c:Contact)-[:OF]->(un:Union) OPTIONAL MATCH (cur:BoardMember)-[:BELONGS_TO_CONTACT]->(c) '
                . 'RETURN un.name AS union_name, c.first_name AS first_name, cur.year AS seat_year, c.id AS contact_id',
            'grouped' => 'MATCH (cur:BoardMember)-[:BELONGS_TO_CONTACT]->(c:Contact)-[:OF]->(un:Union) '
                . 'RETURN un.name AS union_name, c.first_name AS first_name, max(cur.year) AS seat_year, count(cur) AS seats, c.id AS contact_id',
            'unbound' => 'MATCH (cur:BoardMember)-[:BELONGS_TO_CONTACT]->(c:Contact)-[:OF]->(un:Union) WITH DISTINCT c, un '
                . 'RETURN un.name AS union_name, c.first_name AS first_name, c.id AS contact_id',
        ];

        foreach ($queries as $case => $cypher) {
            $answer = $this->planFor('Who is on our boards?', $cypher, $rows, [], 'en', ['BoardMember'])->finalize("x\n\n{{table}}", true, 'en');
            $this->assertStringContainsString('6 contacts across 2 unions.', $answer, $case);
            $this->assertStringNotContainsString('board members', $answer, $case);
        }
    }

    /** @test The link may be written from either end of the pattern. */
    public function the_link_is_read_in_either_direction(): void
    {
        config(['entities' => $this->boardEntities()]);
        $cypher = 'MATCH (un:Union)<-[:BELONGS_TO_UNION]-(cur:BoardMember) MATCH (c:Contact)<-[:BELONGS_TO_CONTACT]-(cur) '
            . 'RETURN un.name AS union_name, c.first_name, c.last_name, cur.bm_role, cur.year, c.id AS contact_id';

        $answer = $this->planFor('Who is on our boards?', $cypher, $this->boardRows(6), [], 'en', ['BoardMember'])->finalize("x\n\n{{table}}", true, 'en');

        $this->assertStringContainsString('6 board members across 2 unions.', $answer);
    }

    // =========================================================================
    // L2 — HEADERS IN THE ANSWER'S LANGUAGE
    // =========================================================================

    /**
     * @test eval p18-B french t0, replayed: `RETURN u.name AS name, u.email AS email, tr.role AS
     * role` headed a French roster "Name / Email / Role". The humanized header goes through the
     * host's translator for the answer's locale; one the host has no line for stays.
     */
    public function p18_b_french_roster_the_table_headers_are_french(): void
    {
        $answer = $this->rosterPlan('fr')->finalize("Voici les membres de votre équipe.\n\n{{table}}", true, 'fr');

        $this->assertStringContainsString('| Nom | Courriel | Rôle |', $answer);
        $this->assertStringNotContainsString('| Name | Email | Role |', $answer);
        $this->assertStringContainsString('| Julie Rousseau | j.rousseau@novogestion.ca | Gestionnaire en chef |', $answer);
    }

    /** @test The same headers name the columns to the model (summary, notes), so its words match the table's. */
    public function the_prompt_names_the_columns_as_the_table_heads_them(): void
    {
        $prompt = $this->boardPlan('fr')->promptBlock(true);

        $this->assertStringContainsString('| Nom de la copropriété | Nom | Year |', $prompt);
        $this->assertStringContainsString('NOT RECORDED: Rôle au conseil is empty', $prompt);
        $this->assertSame(['Rôle au conseil'], $this->boardPlan('fr')->hiddenColumns());
    }

    /** @test A record block is headed the same way. */
    public function a_record_block_is_headed_in_the_answer_language(): void
    {
        app('translator')->addLines(['*.Team Name' => "Nom de l'équipe", '*.Members' => 'Membres'], 'fr');
        $plan = $this->planFor('Infos sur mon équipe ?', self::TEAM_CYPHER, [[
            'team_name' => 'Novo Gestion', 'team_type' => 'Entreprise de gestion', 'members' => 523, 'contact_email' => 'info@novogestion.ca', 'team_address' => '1 rue X',
        ]], [], 'fr', ['Team']);

        $answer = $plan->finalize("Voici votre équipe.\n\n{{table}}", true, 'fr');

        $this->assertStringContainsString("- **Nom de l'équipe:** Novo Gestion", $answer);
        $this->assertStringContainsString('- **Membres:** 523', $answer);
        $this->assertStringContainsString('- **Team Type:** Entreprise de gestion', $answer, 'no host line: the header stands');
        $this->assertSame("Nom de l'équipe", $plan->columnLabel('team_name'));
    }

    /**
     * @test An English answer keeps its headers — the host's UI lines are not consulted in the
     * schema's language — unless the host gives the column a dedicated header line.
     */
    public function english_headers_stand_unless_the_host_overrides_them(): void
    {
        app('translator')->addLines(['*.Role' => 'Position held', '*.Bm Role' => 'Seat'], 'en');
        $this->assertStringContainsString('| Name | Email | Role |', $this->rosterPlan()->finalize('{{table}}', true, 'en'));
        $this->assertStringContainsString('Not recorded: Bm Role.', $this->boardPlan()->finalize('{{table}}', true, 'en'));

        $this->hostJsonLines(['en' => ['ai.header.Bm Role' => 'Board role']]);
        $this->assertStringContainsString('Not recorded: Board role.', $this->boardPlan()->finalize('{{table}}', true, 'en'));
    }

    // =========================================================================
    // L3 — THE LEAD BESIDE THE LINE (eval p19): IT MAY BE EMPTY, IT IS ONE SENTENCE, IT ADDS
    // =========================================================================

    /** @test (a) Nothing to add: the model writes only the placeholder — the line, then the block. */
    public function p19_an_empty_lead_ships_the_line_and_the_block(): void
    {
        foreach (['{{table}}', "  \n\n{{table}}\n", '', "\u{00A0}\n{{table}}", ":\n\n{{table}}"] as $narrative) {
            $plan = $this->rosterPlan();

            $answer = $plan->finalize($narrative, true, 'en');

            $this->assertStringStartsWith("523 members.\n\n| Name | Email | Role |", $answer, json_encode($narrative));
            $this->assertSame([], $plan->leadTrimmed());
        }
    }

    /** @test (a) + (b) The prompt allows the empty lead and quotes the EXACT line the system prints. */
    public function p19_the_prompt_quotes_the_line_and_allows_an_empty_lead(): void
    {
        $roster = $this->rosterPlan()->promptBlock(true);
        $this->assertStringContainsString('The system prints: "523 members."', $this->lineWith($roster, 'The system itself prints'));
        $this->assertStringContainsString('never contradict it', $this->lineWith($roster, 'The system itself prints'));
        $empty = $this->lineWith($roster, 'may be EMPTY');
        $this->assertStringContainsString('write ONLY {{table}}', $empty);
        $this->assertStringContainsString('nothing to ADD', $empty);

        $board = $this->boardPlan()->promptBlock(true);
        $this->assertStringContainsString(
            'The system prints: "37 contacts across 12 unions. Not recorded: Bm Role."',
            $this->lineWith($board, 'The system itself prints')
        );
        $unit = $this->unit310Plan()->promptBlock(true);
        $this->assertStringContainsString(
            'The system prints: "3 different units named 310 — one per union."',
            $this->lineWith($unit, 'The system itself prints')
        );
        // (A note that only speaks "if the question asks" — NO VALUE here — does not make a lead owed.)
        $this->assertStringContainsString('NO VALUE note', $this->lineWith($unit, 'Write ONLY the lead'));
        $this->assertStringContainsString('may be EMPTY', $unit);
        // The line is quoted in the answer's language, as it will be printed.
        $this->assertStringContainsString('The system prints: "523 membres."', $this->rosterPlan('fr')->promptBlock(true));

        // A lead the model still OWES is never optional: a count and groups code cannot word…
        config(['entities' => $this->entities(['Contact' => [], 'Union' => []])]);
        $this->assertStringNotContainsString('may be EMPTY', $this->boardPlan()->promptBlock(true));
        // …or a grand total its first sentence must state.
        config(['entities' => $this->entities()]);
        $arrears = $this->planFor('Which units are behind on their fees?', self::ARREARS_CYPHER, [
            ['unit_name' => '101', 'balance' => 500.25, 'unit_id' => 1],
            ['unit_name' => '102', 'balance' => 411.23, 'unit_id' => 2],
        ], [], 'en', ['Unit'])->promptBlock(true);
        $this->assertStringContainsString('The system prints: "2 units."', $arrears);
        $this->assertStringNotContainsString('may be EMPTY', $arrears);
    }

    /** @test (b) A record's line is quoted too. */
    public function p19_a_record_prompt_quotes_its_line(): void
    {
        $plan = $this->planFor('Can I get information about my team?', self::TEAM_CYPHER, [[
            'team_name' => 'Novo Gestion', 'team_type' => 'Management company', 'members' => 523, 'contact_email' => 'info@novogestion.ca', 'team_address' => null,
        ]], [], 'en', ['Team']);

        $this->assertStringContainsString('The system prints: "Not recorded: Team Address."', $plan->promptBlock(true));
    }

    /**
     * @test (c) eval p19-A board, replayed: "The information about the board of directors and the
     * president is available in the records. You can find the details in the table below." —
     * two sentences beside "ONE sentence". The second is cut; what was cut is recorded. And the
     * first, which names nothing of the rows beside "Not recorded: Board role.", goes too
     * (iteration 5): the line and the table are the answer.
     */
    public function p19_a_board_the_second_sentence_is_cut(): void
    {
        config(['entities' => $this->boardEntities()]);
        $plan = $this->planFor('Who is on our board of directors and who is the president?', self::BOARD_CYPHER, $this->boardRows(), [], 'en', ['BoardMember']);

        $answer = $plan->finalize(
            "The information about the board of directors and the president is available in the records. You can find the details in the table below.\n\n{{table}}",
            true,
            'en'
        );

        $this->assertStringStartsWith('37 board members across 12 unions.', $answer);
        $this->assertStringNotContainsString('table below', $answer);
        $this->assertStringNotContainsString('available in the records', $answer);
        $this->assertSame(['one_sentence', 'not_recorded'], array_column($plan->leadTrimmed(), 'rule'));
        $this->assertSame('You can find the details in the table below.', $plan->leadTrimmed()[0]['text']);
    }

    /**
     * @test iteration 5 live check, board: with the line saying "Not recorded: Board role." the
     * lead came back as "…is not recorded in the available data." — above 37 board members. What
     * is and is not recorded is the LINE's to say: a lead beside it that names no value of the
     * rows has nothing of the data in it and is dropped.
     */
    public function a_lead_naming_no_row_value_is_dropped_beside_a_not_recorded_line(): void
    {
        config(['entities' => $this->boardEntities()]);
        $plan = $this->planFor('Who is on our board of directors and who is the president?', self::BOARD_CYPHER, $this->boardRows(), [], 'en', ['BoardMember']);

        $answer = $plan->finalize(
            "The information about the board of directors and the president is not recorded in the available data.\n\n{{table}}",
            true,
            'en'
        );

        $this->assertContains('not_recorded', $plan->factsStated());
        $this->assertStringStartsWith('37 board members across 12 unions.', $answer);
        $this->assertSame('not_recorded', $plan->leadTrimmed()[0]['rule']);
    }

    /** @test …but a lead that names someone of the rows answers from the data and stays. */
    public function a_lead_naming_a_row_value_stays_beside_a_not_recorded_line(): void
    {
        config(['entities' => $this->boardEntities()]);
        $plan = $this->planFor('Who is on our board of directors and who is the president?', self::BOARD_CYPHER, $this->boardRows(), [], 'en', ['BoardMember']);

        $answer = $plan->finalize("First1 Last1 has sat there the longest.\n\n{{table}}", true, 'en');

        $this->assertStringStartsWith("First1 Last1 has sat there the longest.\n\n37 board members", $answer);
        $this->assertSame([], $plan->leadTrimmed());
    }

    /** @test (c) eval p19-A owner-arrears, replayed: the sentence with the total stays, the filler after it goes. */
    public function p19_a_owner_arrears_the_filler_sentence_is_cut(): void
    {
        $plan = $this->planFor('Which units are behind on their fees?', self::ARREARS_CYPHER, [
            ['unit_name' => '101', 'balance' => 500.25, 'unit_id' => 1],
            ['unit_name' => '102', 'balance' => 411.23, 'unit_id' => 2],
            ['unit_name' => '103', 'balance' => 300.0, 'unit_id' => 3],
        ], [], 'en', ['Unit']);

        $answer = $plan->finalize(
            "The total balance of the units behind on their fees is \$1,211.48. Here are the details of the owners who are currently overdue on their fees.\n\n{{table}}",
            true,
            'en'
        );

        $this->assertStringStartsWith("The total balance of the units behind on their fees is \$1,211.48.\n\n3 units.\n\n| Unit Name | Balance |", $answer);
        $this->assertSame('one_sentence', $plan->leadTrimmed()[0]['rule']);
    }

    /** @test (c) Extra text that cites a passage is content of a data + document turn: it stays. */
    public function p19_an_extra_sentence_citing_a_passage_is_kept(): void
    {
        $plan = $this->rosterPlan();

        $answer = $plan->finalize("You are the Lead union manager of this team. The by-laws let a lead name the other managers [2].\n\n{{table}}", true, 'en');

        $this->assertStringStartsWith('You are the Lead union manager of this team. The by-laws let a lead name the other managers [2].', $answer);
        $this->assertSame([], $plan->leadTrimmed());
    }

    /** @test (c) A sentence a NOTE of the block asks for is not "extra": the lead keeps both. */
    public function p19_a_sentence_a_note_asks_for_is_not_cut(): void
    {
        config(['entities' => $this->entities(['Union' => []])]); // no noun for the groups: GROUPS stays the model's
        $plan = $this->boardPlan();
        $plan->promptBlock(true);

        $answer = $plan->finalize("The rows cover 12 different Union Names. Nobody chairs more than one of them.\n\n{{table}}", true, 'en');

        $this->assertStringStartsWith('The rows cover 12 different Union Names. Nobody chairs more than one of them.', $answer);
        $this->assertSame([], $plan->leadTrimmed());
    }

    /**
     * @test (d) eval p19-B resilience t0 / french t0, replayed: for a LIST question the table is the
     * answer — "Here are the members of your team along with their roles:" says nothing the
     * question and "523 members." do not. A lead that only restates is dropped.
     */
    public function p19_b_a_lead_that_only_restates_is_dropped(): void
    {
        $plan = $this->planFor(
            'I wanna know the members of my team with their roles.',
            self::ROSTER_CYPHER,
            $this->rosterRows(),
            ['true_total' => 523, 'anchor_total' => 523, 'anchor_variable' => 'u', 'anchor_label' => 'User'],
            'en',
            ['Team', 'User', 'TeamRole']
        );
        $answer = $plan->finalize("Here are the members of your team along with their roles:\n\n{{table}}", true, 'en');

        $this->assertStringStartsWith("523 members.\n\n| Name | Email | Role |", $answer);
        $this->assertSame([['rule' => 'restatement', 'text' => 'Here are the members of your team along with their roles:']], $plan->leadTrimmed());

        $french = $this->planFor(
            'Peux-tu me donner la liste des membres de mon équipe avec leurs rôles ?',
            self::ROSTER_CYPHER,
            $this->rosterRows(),
            ['true_total' => 523, 'anchor_total' => 523, 'anchor_variable' => 'u', 'anchor_label' => 'User'],
            'fr',
            ['Team', 'User', 'TeamRole']
        );
        $this->assertStringStartsWith(
            "523 membres.\n\n| Nom | Courriel | Rôle |",
            $french->finalize("Voici la liste des membres de votre équipe avec leurs rôles :\n\n{{table}}", true, 'fr')
        );
        $this->assertSame('restatement', $french->leadTrimmed()[0]['rule']);
    }

    /**
     * @test (d) eval p20-A roster, replayed: "Here are the members of your team, Novo Gestion, along
     * with their roles:" survived the restatement rule — "Gestion" is a content word neither the
     * question nor the line says. It is the user's OWN team name, which the prompt gave the model
     * (CURRENT USER CONTEXT): an identity value is known, not information. With them the lead is
     * the restatement it reads as.
     */
    public function p20_a_roster_an_intro_naming_the_users_own_team_is_a_restatement(): void
    {
        $lead = 'Here are the members of your team, Novo Gestion, along with their roles:';

        // Without the identity values: p20's behaviour — the lead ships.
        $before = $this->rosterPlan();
        $this->assertStringStartsWith($lead . "\n\n523 members.", $before->finalize("{$lead}\n\n{{table}}", true, 'en'));

        $plan = $this->rosterPlan()->knownIdentity(['Julie Rousseau', 'Novo Gestion']);
        $answer = $plan->finalize("{$lead}\n\n{{table}}", true, 'en');

        $this->assertStringStartsWith("523 members.\n\n| Name | Email | Role |", $answer);
        $this->assertSame([['rule' => 'restatement', 'text' => $lead]], $plan->leadTrimmed());
    }

    /** @test …and a lead that says something of the rows beside the team's name stays: a role is a value, not identity. */
    public function a_lead_stating_a_row_value_beside_the_users_own_team_stays(): void
    {
        $plan = $this->rosterPlan()->knownIdentity(['Julie Rousseau', 'Novo Gestion']);

        $answer = $plan->finalize("You are the Lead union manager of Novo Gestion.\n\n{{table}}", true, 'en');

        $this->assertStringStartsWith("You are the Lead union manager of Novo Gestion.\n\n523 members.", $answer);
        $this->assertSame([], $plan->leadTrimmed());
    }

    /** @test Identity values are words to compare, nothing else: blanks and non-strings are ignored. */
    public function blank_identity_values_change_nothing(): void
    {
        $plan = $this->rosterPlan()->knownIdentity(['', '  ', null, 12]);
        $lead = 'Here are the members of your team, Novo Gestion, along with their roles:';

        $this->assertStringStartsWith($lead, $plan->finalize("{$lead}\n\n{{table}}", true, 'en'));
    }

    // ---------------------------------------- a follow-up's subject lives in the conversation (eval p21)

    private const ROSTER_LOGIN_CYPHER = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id '
        . 'RETURN u.name AS name, u.email AS email, tr.role AS role, u.last_logged_at AS last_login LIMIT 100';

    /**
     * B resilience t1, "Ok, but could you add their last login to it?", asked after the roster
     * question — the turn QU judges a follow-up (or not: $followUp).
     */
    private function rosterLoginPlan(bool $followUp = true, ?array $exchanges = null): DataAnswerPlan
    {
        // (Every row holds the added column: the facts line is the count alone, as in p20.)
        $rows = [['name' => 'Julie Rousseau', 'email' => 'j.rousseau@novogestion.ca', 'role' => 'lead', 'last_login' => '2026-10-06 09:12:00']];
        for ($i = 2; $i <= 100; $i++) {
            $rows[] = ['name' => "Member {$i}", 'email' => "member{$i}@example.test", 'role' => 'contact', 'last_login' => '2026-09-01 08:00:00'];
        }

        $ctx = new PipelineContext();
        $ctx->question = 'Ok, but could you add their last login to it?';
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'en', entityTypes: ['Team', 'User', 'TeamRole'], followUpReference: $followUp);
        $ctx->queryResult = new QueryGenerationResult(cypher: self::ROSTER_LOGIN_CYPHER);
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $rows, metadata: [
            'true_total' => 523, 'anchor_total' => 523, 'anchor_variable' => 'u', 'anchor_label' => 'User', 'anchor_first_item' => true,
        ]);
        $ctx->conversationContext = ['recent_exchanges' => $exchanges ?? [
            ['role' => 'user', 'question' => 'I wanna know the members of my team with their roles.', 'answer_summary' => null],
            ['role' => 'assistant', 'question' => null, 'answer_summary' => '523 members.'],
        ]];

        return DataAnswerPlan::for($ctx)->knownIdentity(['Julie Rousseau', 'Novo Gestion']);
    }

    /**
     * @test (d) eval p20-B resilience t1, replayed: "Here are the last login times for the members
     * of your team, Novo Gestion." above "523 members." survived — "the", "of", "team" are words
     * of the PREVIOUS question, and the every-word test knew only this turn's ("Ok, but could you
     * add their last login to it?"). A follow-up's subject lives in the conversation: on a turn QU
     * judges a follow-up, the question it follows is known — and so are the headers of the block
     * shown right below, on every turn.
     */
    public function p20_b_a_follow_up_lead_restating_the_conversation_is_dropped(): void
    {
        $lead = 'Here are the last login times for the members of your team, Novo Gestion.';

        $plan = $this->rosterLoginPlan();
        $answer = $plan->finalize("{$lead}\n\n{{table}}", true, 'en');

        $this->assertStringStartsWith('523 members.', $answer);
        $this->assertSame([['rule' => 'restatement', 'text' => $lead]], $plan->leadTrimmed());

        // Not a follow-up (QU): the conversation's earlier question is not this turn's subject.
        $cold = $this->rosterLoginPlan(false);
        $this->assertStringStartsWith($lead, $cold->finalize("{$lead}\n\n{{table}}", true, 'en'));
        // No conversation recorded: as before.
        $alone = $this->rosterLoginPlan(true, []);
        $this->assertStringStartsWith($lead, $alone->finalize("{$lead}\n\n{{table}}", true, 'en'));
    }

    /**
     * @test …and what answers stays, on the same follow-up turn: a value of the rows, a word of the
     * lead's own. Eval p21-B resilience t1 is one of them — "Here are the last login details for
     * your team members:" holds a word ("details") that neither question, the line nor a header
     * says, and no structure tells such a word from information: when unsure, the lead stays.
     * @dataProvider followUpLeadsThatStay
     */
    public function a_follow_up_lead_with_a_word_of_its_own_stays(string $lead): void
    {
        $plan = $this->rosterLoginPlan();

        $this->assertStringStartsWith($lead, $plan->finalize("{$lead}\n\n{{table}}", true, 'en'));
        $this->assertSame([], $plan->leadTrimmed());
    }

    /** @return array<string, array{0: string}> */
    public static function followUpLeadsThatStay(): array
    {
        return [
            'p21-B resilience t1' => ['Here are the last login details for your team members:'],
            'p19-B resilience t1' => ['I have included the last login information for each team member.'],
            'a negative answer' => ['No member has logged in this week.'],
        ];
    }

    /**
     * @test (d) The headers of the block shown below are known words on any turn: a lead that only
     * lists them is a restatement. (Without them five of this lead's nine words were known: 0.56,
     * under the 0.6 a restatement needs.)
     */
    public function a_lead_that_only_lists_the_shown_columns_is_a_restatement(): void
    {
        $lead = 'Here are the members with their names and emails:';

        $plan = $this->rosterPlan();
        $answer = $plan->finalize("{$lead}\n\n{{table}}", true, 'en');

        $this->assertStringStartsWith("523 members.\n\n| Name | Email | Role |", $answer);
        $this->assertSame([['rule' => 'restatement', 'text' => $lead]], $plan->leadTrimmed());

        // A real answer made of a header word and the question's words still stays.
        $kept = $this->rosterPlan();
        $this->assertStringStartsWith('No member has a role.', $kept->finalize("No member has a role.\n\n{{table}}", true, 'en'));
    }

    /** @test (d) A sentence that answers — a value of the rows, a negative, an added column — stays whole. */
    public function p19_a_lead_that_answers_is_kept(): void
    {
        $roster = $this->rosterPlan();
        $this->assertStringStartsWith("You are the Lead union manager.\n\n523 members.", $roster->finalize("You are the Lead union manager.\n\n{{table}}", true, 'en'));
        $this->assertSame([], $roster->leadTrimmed());

        $unit = $this->unit310Plan();
        $this->assertStringStartsWith('None of them owes anything right now.', $unit->finalize("None of them owes anything right now.\n\n{{table}}", true, 'en'));
        $this->assertSame([], $unit->leadTrimmed());

        // p19-B resilience t1 — the follow-up's own answer.
        $this->assertStringStartsWith(
            'I have included the last login information for each team member.',
            $this->rosterPlan()->finalize("I have included the last login information for each team member.\n\n{{table}}", true, 'en')
        );
    }

    /**
     * @test (d) is not a judgement of truth: the p19-A roster hedge and the unit-310 merge are said in
     * words of their own, so no structural test removes them — the quoted line and the empty
     * lead in the prompt are what address them.
     */
    public function p19_a_hedge_or_a_merge_in_its_own_words_is_out_of_structural_reach(): void
    {
        $hedge = 'Your team consists of several members with various roles, including yourself as the Lead Union Manager.';
        $this->assertStringStartsWith($hedge, $this->rosterPlan()->finalize("{$hedge}\n\n{{table}}", true, 'en'));

        $merge = 'Unit 310 currently owes $0.00 across all associated unions, with no open invoices or amounts due.';
        $this->assertStringStartsWith($merge, $this->unit310Plan()->finalize("{$merge}\n\n{{table}}", true, 'en'));
    }

    /** @test Without a line printed by code the lead is the model's as before: two sentences, an introduction. */
    public function p19_without_a_line_the_lead_is_not_bounded(): void
    {
        config(['entities' => $this->entities(['User' => []])]); // no noun: no count line
        $plan = $this->rosterPlan();
        $lead = 'Here are the members of your team and their roles. There are 523 of them.';

        $this->assertStringStartsWith($lead . "\n\n| Name | Email | Role |", $plan->finalize("{$lead}\n\n{{table}}", true, 'en'));
        $this->assertSame([], $plan->leadTrimmed());
    }

    // =========================================================================
    // L5 — AN ASKED-FOR COLUMN THAT IS MOSTLY BLANK (eval p20-B resilience t1)
    // =========================================================================

    private const LOGIN_QUESTION = 'Ok, but could you add their last login to it?';

    /**
     * The p20-B resilience t1 turn: the roster of the previous turn plus `u.last_logged_at`, 100
     * retrieved of 523, the login recorded on $filled rows (by 1-based row number). QU named NO
     * property concept on this turn (p18, p19 and p20 alike) — the column is the one the
     * follow-up ADDED to the recorded result.
     *
     * @param list<int> $filled
     */
    private function loginPlan(
        array $filled = [1, 2, 40],
        string $language = 'en',
        string $alias = 'last_logged_at',
        int $rows = 100,
        ?int $total = 523,
        ?QueryUnderstandingResult $qu = null,
        ?array $conversation = null,
        string $question = self::LOGIN_QUESTION
    ): DataAnswerPlan {
        $data = [];
        for ($i = 1; $i <= $rows; $i++) {
            $data[] = ['name' => "Member {$i}", 'email' => "member{$i}@example.test", 'role' => 'contact',
                $alias => in_array($i, $filled, true) ? '2023-11-24 22:21:00' : null, 'user_id' => 100 + $i];
        }

        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = $qu ?? new QueryUnderstandingResult(
            questionType: 'data_query',
            language: $language,
            searchQuery: 'last login for team members',
            followUpReference: true,
            subjectLabel: 'Team members last login'
        );
        $ctx->conversationContext = $conversation ?? [
            'last_cypher_query' => self::ROSTER_CYPHER,
            'last_result_sample' => [['name' => 'Member 1', 'email' => 'member1@example.test', 'role' => 'contact', 'user_id' => 101]],
        ];
        $ctx->queryResult = new QueryGenerationResult(cypher: str_replace(' LIMIT 100', ", u.last_logged_at AS {$alias}, u.id AS user_id LIMIT 100", self::ROSTER_CYPHER));
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $data, metadata: $total === null ? [] : [
            'true_total' => $total, 'anchor_total' => $total, 'anchor_variable' => 'u', 'anchor_label' => 'User',
        ]);

        return DataAnswerPlan::for($ctx);
    }

    /**
     * @test eval p20-B resilience t1, replayed ("…could you add their last login to it?"): the added
     * column is blank on 23 of the 25 rows shown and nothing said so. For a column the question
     * asked for, code states over how many of the rows it PROVABLY covers the value is recorded —
     * here the 25 shown: 100 of 523 were retrieved, so nothing is claimed about the rest.
     */
    public function p20_b_resilience_t1_a_mostly_blank_asked_for_column_is_stated_over_the_shown_rows(): void
    {
        $plan = $this->loginPlan();

        $answer = $plan->finalize("{{table}}", true, 'en');

        $this->assertStringStartsWith("523 members. Last Logged At recorded for 2 of the 25 shown.\n\n| Name | Email | Role | Last Logged At |", $answer);
        $this->assertSame(['count', 'partly_recorded'], $plan->factsStated());

        // The model is shown the exact line and told it is the system's to say.
        $prints = $this->lineWith($plan->promptBlock(true), 'The system itself prints');
        $this->assertStringContainsString('for how many rows a field the question asks for is recorded', $prints);
        $this->assertStringContainsString('The system prints: "523 members. Last Logged At recorded for 2 of the 25 shown."', $prints);
    }

    /** @test …in the answer's language: the French template, the header as the French table heads it. */
    public function the_partly_recorded_statement_follows_the_answer_language(): void
    {
        app('translator')->addLines(['*.Last Logged At' => 'Dernière connexion'], 'fr');

        $answer = $this->loginPlan([1, 2, 40], 'fr')->finalize('{{table}}', true, 'fr');

        $this->assertStringStartsWith("523 membres. Dernière connexion : renseigné pour 2 des 25 lignes affichées.\n\n| Nom | Courriel | Rôle | Dernière connexion |", $answer);
    }

    /**
     * @test When the plan holds EVERY row of the result the count is over all of them — also the
     * rows below the display cap ("of 30", 25 shown) — and says so without "shown".
     */
    public function over_a_fully_retrieved_result_the_statement_counts_every_row(): void
    {
        $plan = $this->loginPlan([1, 2, 27, 28, 29], 'en', 'last_logged_at', 30, null);

        $answer = $plan->finalize('{{table}}', true, 'en');

        $this->assertStringStartsWith("30 members. Last Logged At recorded for 5 of 30.\n\n| Name |", $answer);
        $this->assertStringContainsString("| Member 25 |", $answer);
        $this->assertStringNotContainsString('| Member 26 |', $answer, '25 shown, 30 counted');

        $french = $this->loginPlan([1, 2, 27, 28, 29], 'fr', 'last_logged_at', 30, null)->finalize('{{table}}', true, 'fr');
        $this->assertStringStartsWith('30 membres. Last Logged At : renseigné pour 5 sur 30.', $french);
    }

    /**
     * @test "Asked for" is established three ways, all structural: a property concept QU named that
     * the column's alias or property spells; the turn's subject / the question naming the column;
     * a column a follow-up ADDED to the recorded result. Without any of them a partly blank column
     * is not commented on.
     */
    public function the_asked_for_column_is_told_by_concept_by_subject_or_as_the_follow_ups_addition(): void
    {
        $cold = ['last_cypher_query' => null, 'last_result_sample' => []];
        $line = '523 members. Last Login recorded for 2 of the 25 shown.';

        // (a) a QU property concept ("last login" ↔ last_login / last_logged_at), no conversation.
        $concept = new QueryUnderstandingResult(questionType: 'data_query', language: 'en', propertyConcepts: ['last login'], subjectLabel: 'Team roster');
        $this->assertStringStartsWith($line, $this->loginPlan([1, 2], 'en', 'last_login', 100, 523, $concept, $cold, 'Who is on my team?')->finalize('{{table}}', true, 'en'));

        // (b) the turn's subject names the column.
        $subject = new QueryUnderstandingResult(questionType: 'data_query', language: 'en', subjectLabel: 'Team members last login');
        $this->assertStringStartsWith($line, $this->loginPlan([1, 2], 'en', 'last_login', 100, 523, $subject, $cold, 'Show it')->finalize('{{table}}', true, 'en'));

        // (b) …or the question itself does.
        $none = new QueryUnderstandingResult(questionType: 'data_query', language: 'en', subjectLabel: 'Team roster');
        $this->assertStringStartsWith($line, $this->loginPlan([1, 2], 'en', 'last_login', 100, 523, $none, $cold)->finalize('{{table}}', true, 'en'));

        // (c) the follow-up's addition — whatever the generator called it (p20: `last_logged_at`).
        $this->assertStringStartsWith('523 members. Last Logged At recorded for 2 of the 25 shown.', $this->loginPlan([1, 2])->finalize('{{table}}', true, 'en'));

        // None of the three: nobody asked for it.
        $plan = $this->loginPlan([1, 2], 'en', 'last_logged_at', 100, 523, $none, $cold, 'Who is on my team?');
        $this->assertStringStartsWith("523 members.\n\n| Name |", $plan->finalize('{{table}}', true, 'en'));
        $this->assertSame(['count'], $plan->factsStated());
    }

    /**
     * @test Nothing is said of a column that is filled on every counted row, nor of one empty on
     * every retrieved row (that is the "Not recorded" path), nor of a column the turn did not ask
     * for that merely carries over from the recorded result.
     */
    public function a_full_an_empty_or_a_carried_over_column_gets_no_statement(): void
    {
        $full = $this->loginPlan(range(1, 100));
        $this->assertStringStartsWith("523 members.\n\n| Name | Email | Role | Last Logged At |", $full->finalize('{{table}}', true, 'en'));
        $this->assertSame(['count'], $full->factsStated());

        // Empty on all 30 rows of a fully retrieved result: "Not recorded", once, and nothing else.
        $empty = $this->loginPlan([], 'en', 'last_logged_at', 30, null);
        $this->assertStringStartsWith("30 members. Not recorded: Last Logged At.\n\n| Name | Email | Role |", $empty->finalize('{{table}}', true, 'en'));
        $this->assertSame(['count', 'not_recorded'], $empty->factsStated());

        // The recorded result's own column (role), blank on some rows: carried over, not asked for.
        $ctx = new PipelineContext();
        $ctx->question = self::LOGIN_QUESTION;
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'en', followUpReference: true, subjectLabel: 'Team members last login');
        $ctx->conversationContext = ['last_cypher_query' => self::ROSTER_CYPHER, 'last_result_sample' => [['name' => 'A', 'email' => 'a@x.test', 'role' => 'contact']]];
        $ctx->queryResult = new QueryGenerationResult(cypher: str_replace(' LIMIT 100', ', u.last_logged_at AS last_logged_at, u.id AS user_id LIMIT 100', self::ROSTER_CYPHER));
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['name' => "Member {$i}", 'email' => "m{$i}@x.test", 'role' => $i % 2 ? 'contact' : null, 'last_logged_at' => '2023-11-24 22:21:00', 'user_id' => $i];
        }
        $ctx->executionResult = new QueryExecutionResult(success: true, data: $rows);
        $carried = DataAnswerPlan::for($ctx);
        $this->assertStringStartsWith("30 members.\n\n| Name |", $carried->finalize('{{table}}', true, 'en'));
        $this->assertSame(['count'], $carried->factsStated());
    }

    /** @test Empty on the rows shown, recorded further down a partial retrieval: said as it is — "0 of the 25 shown". */
    public function a_column_blank_on_every_shown_row_of_a_partial_retrieval_is_stated_too(): void
    {
        $answer = $this->loginPlan([40, 41])->finalize('{{table}}', true, 'en');

        $this->assertStringStartsWith('523 members. Last Logged At recorded for 0 of the 25 shown.', $answer);
    }

    // =========================================================================
    // L4 — ONE NAME COLUMN (eval p19-A board: "First Name | Last Name")
    // =========================================================================

    /** The first rows of the p19-A board trace (data_sample), two unions. */
    private const P19_BOARD_ROWS = [
        ['union_name' => '825 de Bruxelles', 'c.first_name' => 'Amélie', 'c.last_name' => 'Quirion', 'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => 779],
        ['union_name' => '825 de Bruxelles', 'c.first_name' => 'Mauricio', 'c.last_name' => 'Ruiz', 'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => 1436],
        ['union_name' => '825 de Bruxelles', 'c.first_name' => 'Denis', 'c.last_name' => 'Bertrand', 'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => 876],
        ['union_name' => 'SDC Carré Soho Phase 3', 'c.first_name' => 'Cédric', 'c.last_name' => 'Motte', 'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => 1434],
        ['union_name' => 'SDC Carré Soho Phase 3', 'c.first_name' => 'Hélène', 'c.last_name' => 'Gingras', 'cur.bm_role' => null, 'cur.year' => 2022, 'contact_id' => 790],
    ];

    /**
     * @test eval p19-A board, replayed (cypher + data_sample): a person's name sat in two columns.
     * Contact declares `name_properties: [first_name, last_name]` and the query projects both from
     * `c` — the table, the summary the model reads and the notes show ONE "Name" column.
     */
    public function p19_a_board_the_name_is_one_column_on_screen_and_in_the_prompt(): void
    {
        config(['entities' => $this->boardEntities()]);
        $plan = $this->planFor('Who is on our board of directors and who is the president?', self::BOARD_CYPHER, self::P19_BOARD_ROWS, [], 'en', ['BoardMember']);

        $answer = $plan->finalize('{{table}}', true, 'en');

        $this->assertSame(
            "5 board members across 2 unions. Not recorded: Bm Role.\n\n"
                . "| Union Name | Name | Year |\n| --- | --- | --- |\n"
                . "| 825 de Bruxelles | Amélie Quirion | 2022 |\n"
                . "| 825 de Bruxelles | Mauricio Ruiz | 2022 |\n"
                . "| 825 de Bruxelles | Denis Bertrand | 2022 |\n"
                . "| SDC Carré Soho Phase 3 | Cédric Motte | 2022 |\n"
                . '| SDC Carré Soho Phase 3 | Hélène Gingras | 2022 |',
            $answer
        );

        $prompt = $plan->promptBlock(true);
        $this->assertStringContainsString("| Union Name | Name | Year |\n| --- | --- | --- |\n| 825 de Bruxelles | Amélie Quirion | 2022 |", $prompt);
        $this->assertStringNotContainsString('First Name', $prompt);
        $this->assertStringNotContainsString('Last Name', $prompt);
    }

    /** @test A list the model wrote with the FULL names is the rows again: the row-echo guard sees the merged values. */
    public function a_list_of_the_merged_names_is_a_row_echo(): void
    {
        config(['entities' => $this->boardEntities()]);
        $plan = $this->planFor('Who is on our boards?', self::BOARD_CYPHER, self::P19_BOARD_ROWS, [], 'en', ['BoardMember']);

        $answer = $plan->finalize("The current boards:\n\n- Amélie Quirion\n- Mauricio Ruiz\n- Denis Bertrand\n\n{{table}}", true, 'en');

        $this->assertSame(1, $plan->rowEchoDropped());
        $this->assertStringNotContainsString('- Amélie Quirion', $answer);
    }

    /** @test ONE record: its fields list the name once. */
    public function a_record_block_lists_the_name_once(): void
    {
        $plan = $this->planFor(
            'Show me the contact Amélie Quirion',
            'MATCH (c:Contact) WHERE c.id = 779 RETURN c.first_name, c.last_name, c.city, c.id AS contact_id',
            [['c.first_name' => 'Amélie', 'c.last_name' => 'Quirion', 'c.city' => 'Montréal', 'contact_id' => 779]],
            [],
            'en',
            ['Contact']
        );

        $this->assertSame("Here she is.\n\n- **Name:** Amélie Quirion\n- **City:** Montréal", $plan->finalize("Here she is.\n\n{{table}}", true, 'en'));
        $this->assertStringContainsString('(Name, City)', $plan->promptBlock(true));
    }

    /** @test The merged header is said in the answer's language like any other ("Nom"), beside its record when two collide. */
    public function the_merged_name_is_headed_in_the_answer_language(): void
    {
        config(['entities' => $this->boardEntities()]);

        $french = $this->planFor('Qui siège à nos conseils ?', self::BOARD_CYPHER, self::P19_BOARD_ROWS, [], 'fr', ['BoardMember']);
        $this->assertStringContainsString('| Nom de la copropriété | Nom | Year |', $french->finalize('{{table}}', true, 'fr'));

        $two = $this->planFor(
            'Which contacts have a user account?',
            'MATCH (c:Contact)-[:HAS_USER]->(u:User) RETURN u.name AS name, c.first_name, c.last_name, c.id AS contact_id',
            [
                ['name' => 'jrousseau', 'c.first_name' => 'Julie', 'c.last_name' => 'Rousseau', 'contact_id' => 1],
                ['name' => 'aquirion', 'c.first_name' => 'Amélie', 'c.last_name' => 'Quirion', 'contact_id' => 2],
            ],
            [],
            'en',
            ['Contact']
        );
        $this->assertStringContainsString('| Name | Contact Name |', $two->finalize('{{table}}', true, 'en'));
    }

    /**
     * @test Masking is decided per SOURCE column, before the merge: a name part the host lists as
     * identifying reaches the model as its token inside the merged cell, never as the value — and
     * the user still reads the real name.
     */
    public function a_masked_name_part_stays_masked_inside_the_merged_cell(): void
    {
        config([
            'entities' => $this->boardEntities(),
            'ai.access_control.identifying_fields' => ['*' => ['email', 'phone', 'last_name']],
        ]);
        $plan = $this->planFor('Who is on our boards?', self::BOARD_CYPHER, self::P19_BOARD_ROWS, [], 'en', ['BoardMember']);

        $prompt = $plan->promptBlock(true);

        $this->assertStringNotContainsString('Quirion', $prompt);
        $this->assertMatchesRegularExpression('/\| 825 de Bruxelles \| Amélie \[last_name#\d+\] \| 2022 \|/', $prompt);
        $this->assertStringContainsString('| 825 de Bruxelles | Amélie Quirion | 2022 |', $plan->finalize('{{table}}', true, 'en'));
    }

    /** @test Only part of the name projected, or no declaration: the columns are the query's, as before. */
    public function a_partial_name_is_not_merged(): void
    {
        config(['entities' => $this->boardEntities()]);
        $partial = $this->planFor(
            'Board first names?',
            'MATCH (cur:BoardMember)-[:BELONGS_TO_CONTACT]->(c:Contact) RETURN c.first_name, cur.year, c.id AS contact_id',
            [['c.first_name' => 'Amélie', 'cur.year' => 2022, 'contact_id' => 779], ['c.first_name' => 'Denis', 'cur.year' => 2022, 'contact_id' => 876]],
            [],
            'en',
            ['BoardMember']
        );
        $this->assertStringContainsString('| First Name | Year |', $partial->finalize('{{table}}', true, 'en'));

        $entities = $this->boardEntities();
        $entities['App\\Models\\Contact']['metadata']['name_properties'] = [];
        config(['entities' => $entities]);
        $undeclared = $this->planFor('Who is on our boards?', self::BOARD_CYPHER, self::P19_BOARD_ROWS, [], 'en', ['BoardMember']);
        $this->assertStringContainsString('| Union Name | First Name | Last Name | Year |', $undeclared->finalize('{{table}}', true, 'en'));
    }

    /** @return array<int, array<string, mixed>> the p18 / p19 roster rows (100 retrieved of 523) */
    private function rosterRows(): array
    {
        $rows = [['name' => 'Julie Rousseau', 'email' => 'j.rousseau@novogestion.ca', 'role' => 'lead']];
        for ($i = 2; $i <= 100; $i++) {
            $rows[] = ['name' => "Member {$i}", 'email' => "member{$i}@example.test", 'role' => 'contact'];
        }

        return $rows;
    }

    /**
     * Host lang JSON lines, loaded as a host ships them (`lang/<locale>.json`): a DOTTED key
     * ("ai.header.Bm Role") is one flat JSON key — addLines() would nest it.
     *
     * @param array<string, array<string, string>> $byLocale locale => [key => line]
     */
    private function hostJsonLines(array $byLocale): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ai-host-lang-' . bin2hex(random_bytes(6));
        mkdir($dir);
        foreach ($byLocale as $locale => $lines) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . $locale . '.json', json_encode($lines, JSON_UNESCAPED_UNICODE));
        }
        $this->beforeApplicationDestroyed(static function () use ($dir): void {
            array_map('unlink', glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: []);
            @rmdir($dir);
        });

        app('translator')->addJsonPath($dir);
        app('translator')->setLoaded([]); // locales loaded before the path was added are read again
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
