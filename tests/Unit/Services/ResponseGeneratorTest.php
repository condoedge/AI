<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * ResponseGenerator Service Unit Tests
 *
 * Tests the Response Generator service that transforms query results
 * into natural language explanations.
 */
class ResponseGeneratorTest extends TestCase
{
    private ResponseGenerator $generator;
    private LlmProviderInterface $llmMock;
    private array $config;

    public function setUp(): void
    {
        parent::setUp();

        $this->llmMock = Mockery::mock(LlmProviderInterface::class);

        $this->config = [
            'default_format' => 'text',
            'default_style' => 'detailed',
            'default_max_length' => 200,
            'temperature' => 0.3,
            'summarize_threshold' => 10,
        ];

        $this->generator = new ResponseGenerator($this->llmMock, $this->config);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Build the single-path PipelineContext the generator now consumes.
     *
     * The legacy ($question, $queryResult, $cypher) arguments are mapped onto
     * the DTO: question -> ->question, the result rows -> executionResult->data,
     * and the cypher string -> queryResult->cypher.
     */
    private function makeContext(string $question, array $data, ?string $cypher): PipelineContext
    {
        $context = new PipelineContext();
        $context->question = $question;
        $context->queryResult = new QueryGenerationResult(cypher: $cypher);
        $context->executionResult = new QueryExecutionResult(success: true, data: $data);

        return $context;
    }

    /** @test */
    public function empty_response_carries_the_identity_system_prompt_not_null(): void
    {
        // Regression guard: the abstention/empty path must NOT call the LLM with a
        // null system prompt — that bypasses IdentitySection/non-disclosure and lets
        // a "which model are you?" question self-identify the provider.
        $capturedSystem = 'NEVER_CALLED';
        $capturedUser = '';
        $this->llmMock->shouldReceive('complete')->once()
            ->andReturnUsing(function ($user, $system, $opts) use (&$capturedSystem, &$capturedUser) {
                $capturedSystem = $system;
                $capturedUser = $user;
                return 'I could not find anything matching that.';
            });

        $this->generator->generateEmptyResponse('Which AI model are you?', 'MATCH (n:Invoice) RETURN n', []);

        $this->assertNotNull($capturedSystem, 'empty path sent a null system prompt — identity/non-disclosure bypassed');
        $this->assertNotSame('', $capturedSystem);
        $this->assertStringContainsStringIgnoringCase('never reveal', $capturedSystem);
        // The executed query/schema must not be leaked into the prompt on the empty path.
        $this->assertStringNotContainsString('MATCH (n:Invoice)', $capturedUser);
    }

    /** @test */
    public function empty_response_states_what_was_checked_from_the_qu_structure(): void
    {
        // Task 1.27 (c), live rights t2: "So how much is my contribution this
        // year?" for a user owning no unit answered a bare "No results were
        // found…". The abstention must say WHAT was checked — from QU's subject,
        // entity types and possessive flag (structured fields, no word lists).
        $capturedUser = '';
        $this->llmMock->shouldReceive('complete')->once()
            ->andReturnUsing(function ($user) use (&$capturedUser) {
                $capturedUser = $user;
                return 'I checked your contribution records and found none linked to you.';
            });

        $this->generator->generateEmptyResponse('So how much is my contribution this year?', 'MATCH (u:Unit) RETURN u', [
            'checked_subject' => 'annual contribution of my unit',
            'checked_entity_types' => ['Unit', 'Invoice'],
            'checked_own_data' => true,
        ]);

        $this->assertStringContainsString('WHAT WAS CHECKED: annual contribution of my unit', $capturedUser);
        // (p18: the kinds are the EXECUTED query's — it read units only; see the tests below.)
        $this->assertStringContainsString('(record kinds: unit)', $capturedUser);
        $this->assertStringContainsString("the user's OWN", $capturedUser);
        $this->assertStringContainsString('never a bare "no results were found"', $capturedUser);
        $this->assertStringNotContainsString('MATCH (u:Unit)', $capturedUser);
    }

    /** The prompt of one empty answer. */
    private function emptyPrompt(string $question, ?string $cypher, array $options): string
    {
        $captured = '';
        $this->llmMock->shouldReceive('complete')->once()
            ->andReturnUsing(function ($user) use (&$captured) {
                $captured = $user;

                return 'Nothing matched.';
            });

        $this->generator->generateEmptyResponse($question, $cypher, $options);

        return $captured;
    }

    /**
     * @test Eval p18-B rights t2 ("So how much is my contribution this year?"): "I checked … in
     * invoices, union records, and fund records". The kinds were QU's guess (Invoice, Union,
     * Fund); the query that RAN summed invoices of the user's units. WHAT WAS CHECKED names the
     * kinds the executed query READS — by the host's own word for them — never the nodes it
     * only walks through, and never the query text.
     */
    public function p18_b_rights_t2_the_checked_kinds_are_the_executed_querys(): void
    {
        config(['entities' => [
            'App\\Models\\Invoice' => ['graph' => ['label' => 'Invoice'], 'metadata' => ['display_name' => 'invoice', 'display_name_plural' => 'invoices']],
            'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['display_name' => 'unit', 'display_name_plural' => 'units']],
            'App\\Models\\Contact' => ['graph' => ['label' => 'Contact'], 'metadata' => ['display_name_plural' => 'contacts']],
        ]]);
        $cypher = 'MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL '
            . "MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.customer_type = 'unit' AND i.customer_id = u.id AND i.status IN ['2', '3', '4'] "
            . "AND substring(i.invoiced_at, 0, 4) = '2026' RETURN SUM(i.total_amount) AS contribution_amount";

        $prompt = $this->emptyPrompt('So how much is my contribution this year?', $cypher, [
            'checked_subject' => 'annual contribution amount for this year',
            'checked_entity_types' => ['Invoice', 'Union', 'Fund'],
            'checked_scope' => 'own',
            'ownership_check' => ['entity' => 'Unit', 'display_name' => 'unit', 'count' => 0],
        ]);

        $this->assertStringContainsString('WHAT WAS CHECKED: annual contribution amount for this year (record kinds: invoices)', $prompt);
        $this->assertStringNotContainsString('Union', $prompt);
        $this->assertStringNotContainsString('Fund', $prompt);
        $this->assertStringNotContainsString('contact', $prompt, 'a node the query only walks through is not what was checked');
        $this->assertStringNotContainsString('MATCH (', $prompt);
        $this->assertStringContainsString('VERIFIED BY THE SYSTEM: this user has NO unit linked to their account', $prompt);
    }

    /**
     * @test Several kinds read by the RETURN are named in its order: the host's plural, else its
     * display name, else the label as words — never a raw type name. A kind the host marks as
     * not askable (a structural entity) is not named.
     */
    public function the_checked_kinds_follow_the_return_and_the_hosts_words(): void
    {
        config(['entities' => [
            'App\\Models\\Union' => ['graph' => ['label' => 'Union'], 'metadata' => ['display_name_plural' => 'unions']],
            'App\\Models\\Contact' => ['graph' => ['label' => 'Contact'], 'metadata' => ['display_name' => 'contact']],
            'App\\Models\\BoardMember' => ['graph' => ['label' => 'BoardMember'], 'metadata' => []],
            'App\\Models\\AccountBalance' => ['graph' => ['label' => 'AccountBalance'], 'metadata' => ['askable' => false]],
        ]]);
        $cypher = 'MATCH (b:BoardMember)-[:BELONGS_TO_UNION]->(un:Union)<-[:OF]-(ab:AccountBalance) WHERE un.team_id IN $ai_team_ids '
            . "MATCH (b)-[:BELONGS_TO_CONTACT]->(c:Contact) WHERE b.bm_role = 'president' "
            . 'RETURN un.name AS union_name, c.first_name, c.last_name, b.year, ab.amount AS balance';

        $prompt = $this->emptyPrompt('Who is the president of our boards?', $cypher, [
            'checked_subject' => 'board presidents',
            'checked_entity_types' => ['BoardMember'],
            'checked_scope' => 'team',
        ]);

        $this->assertStringContainsString('(record kinds: unions, contact, board member)', $prompt);
    }

    /** @test A RETURN of a WITH alias: the kinds are those the WITH computed it from. */
    public function the_checked_kinds_are_read_through_a_with_alias(): void
    {
        $cypher = 'MATCH (un:Union)<-[:UNION]-(u:Unit) WHERE un.team_id IN $ai_team_ids MATCH (i:Invoice)-[:CUSTOMER]->(u) '
            . "WHERE i.name STARTS WITH 'INV' WITH un, sum(i.due_amount) AS due WHERE due > 0 RETURN due";

        $prompt = $this->emptyPrompt('What is overdue?', $cypher, [
            'checked_subject' => 'overdue amounts',
            'checked_entity_types' => ['Fund'],
        ]);

        $this->assertStringContainsString('(record kinds: invoice)', $prompt);
    }

    /** @test No query ran (or nothing can be read from it): QU's kinds stand, as before. */
    public function without_an_executed_query_the_checked_kinds_are_qus(): void
    {
        $prompt = $this->emptyPrompt('So how much is my contribution this year?', null, [
            'checked_subject' => 'annual contribution',
            'checked_entity_types' => ['Unit', 'Invoice'],
        ]);
        $this->assertStringContainsString('(record kinds: Unit, Invoice)', $prompt);

        $unreadable = $this->emptyPrompt('So how much is my contribution this year?', 'RETURN 1 AS one', [
            'checked_subject' => 'annual contribution',
            'checked_entity_types' => ['Unit', 'Invoice'],
        ]);
        $this->assertStringContainsString('(record kinds: Unit, Invoice)', $unreadable);
    }

    /** @test */
    public function the_possessive_example_appears_only_for_the_users_own_data(): void
    {
        // Task 1.28 M5: "(e.g. that no such records are linked to them)" is a
        // possessive framing — wrong for a workspace-wide empty result.
        $prompts = [];
        $this->llmMock->shouldReceive('complete')->twice()
            ->andReturnUsing(function ($user) use (&$prompts) {
                $prompts[] = $user;
                return 'Nothing matched.';
            });

        $this->generator->generateEmptyResponse('Any overdue invoices?', null, [
            'checked_subject' => 'overdue invoices', 'checked_own_data' => false,
        ]);
        $this->generator->generateEmptyResponse('Do I have overdue invoices?', null, [
            'checked_subject' => 'my overdue invoices', 'checked_own_data' => true,
        ]);

        $this->assertStringContainsString('WHAT WAS CHECKED: overdue invoices', $prompts[0]);
        $this->assertStringNotContainsString('linked to them', $prompts[0]);
        $this->assertStringContainsString('linked to them', $prompts[1]);
    }

    /** @test */
    public function a_team_wide_empty_result_is_never_described_as_the_users_own_records(): void
    {
        // Eval p16-A meetings: "When is our next meeting?" ran a TEAM-scoped query; the
        // abstention said "no meetings linked to you". The scope is the executed query's.
        $capturedUser = '';
        $this->llmMock->shouldReceive('complete')->once()
            ->andReturnUsing(function ($user) use (&$capturedUser) {
                $capturedUser = $user;
                return 'No upcoming meeting was found for your team.';
            });

        $this->generator->generateEmptyResponse('When is our next meeting?', null, [
            'checked_subject' => 'next meeting',
            'checked_entity_types' => ['Meeting'],
            'checked_scope' => 'team',
        ]);

        $this->assertStringContainsString('WHAT WAS CHECKED: next meeting', $capturedUser);
        $this->assertStringContainsString("whole team", $capturedUser);
        $this->assertStringNotContainsString("the user's OWN linked records", $capturedUser);
        $this->assertStringNotContainsString('(e.g. that no such records are linked to them)', $capturedUser);
        $this->assertStringContainsString('never that no such records exist at all', $capturedUser);
    }

    /** @test */
    public function an_own_data_empty_states_the_verified_reason_the_user_has_no_such_record(): void
    {
        // Eval p16-B rights t2: Julie owns no unit. "No matching records linked to you" hid
        // the reason; the ownership count is a FACT the abstention must state — and it says
        // nothing about what the team owes.
        $capturedUser = '';
        $this->llmMock->shouldReceive('complete')->once()
            ->andReturnUsing(function ($user) use (&$capturedUser) {
                $capturedUser = $user;
                return 'You have no unit linked to your account.';
            });

        $this->generator->generateEmptyResponse('So how much is my contribution this year?', null, [
            'checked_subject' => 'annual contribution',
            'checked_entity_types' => ['Invoice'],
            'checked_scope' => 'own',
            'ownership_check' => ['entity' => 'Unit', 'display_name' => 'unit', 'count' => 0],
        ]);

        $this->assertStringContainsString("within the user's OWN linked records", $capturedUser);
        $this->assertStringContainsString('VERIFIED BY THE SYSTEM: this user has NO unit linked to their account', $capturedUser);
        $this->assertStringContainsString('That is the reason nothing matched', $capturedUser);
        $this->assertStringContainsString("never state or imply that the team", $capturedUser);
    }

    /** @test */
    public function an_own_data_empty_never_claims_the_user_has_none_when_the_count_found_some(): void
    {
        $capturedUser = '';
        $this->llmMock->shouldReceive('complete')->once()
            ->andReturnUsing(function ($user) use (&$capturedUser) {
                $capturedUser = $user;
                return 'Nothing matched.';
            });

        $this->generator->generateEmptyResponse('How much is my contribution this year?', null, [
            'checked_subject' => 'annual contribution',
            'checked_scope' => 'own',
            'ownership_check' => ['entity' => 'Unit', 'display_name' => 'unit', 'count' => 2],
        ]);

        $this->assertStringContainsString('VERIFIED BY THE SYSTEM: this user DOES have unit records linked to their account (2 found)', $capturedUser);
        $this->assertStringNotContainsString('has NO unit', $capturedUser);
    }

    /** @test */
    public function an_ownership_fact_is_only_stated_for_an_own_data_scope(): void
    {
        $capturedUser = '';
        $this->llmMock->shouldReceive('complete')->once()
            ->andReturnUsing(function ($user) use (&$capturedUser) {
                $capturedUser = $user;
                return 'Nothing matched.';
            });

        $this->generator->generateEmptyResponse('Any overdue invoices?', null, [
            'checked_subject' => 'overdue invoices',
            'checked_scope' => 'team',
            'ownership_check' => ['entity' => 'Unit', 'display_name' => 'unit', 'count' => 0],
        ]);

        $this->assertStringNotContainsString('VERIFIED BY THE SYSTEM', $capturedUser);
    }

    // =========================================================================
    // WHAT WAS CHECKED, printed by code (eval p20-B rights t2)
    // =========================================================================

    /** The three executed queries of the stored rights t2 turns ("So how much is my contribution this year?"). */
    private const RIGHTS_T2 = [
        'p18' => 'MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL '
            . "MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.customer_type = 'unit' AND i.customer_id = u.id AND i.status IN ['2', '3', '4'] "
            . "AND substring(i.invoiced_at, 0, 4) = '2026' RETURN SUM(i.total_amount) AS contribution_amount",
        'p19' => 'MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL '
            . "MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.budget_id IS NOT NULL AND i.status IN ['2', '3', '4'] AND i.due_amount >= 0.01 "
            . 'MATCH (i)-[:BELONGS_TO_UNION]->(un:Union) WHERE un.team_id IN $ai_team_ids '
            . 'RETURN un.name AS union_name, sum(i.total_amount) AS annual_contribution LIMIT 100',
        'p20' => 'MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL '
            . "MATCH (n:Invoice)-[:BELONGS_TO_UNION]->(un:Union) WHERE (n.customer_type = 'unit' AND n.customer_id = u.id) AND n.budget_id IS NOT NULL "
            . "AND substring(n.invoiced_at, 0, 4) = '2026' "
            . 'RETURN n.invoice_number AS invoice_number, n.due_amount AS amount, un.name AS union_name, n.id AS invoice_id LIMIT 100',
    ];

    private const OWN_NO_UNIT = [
        'checked_subject' => 'contribution amount for this year',
        'checked_entity_types' => ['Invoice', 'Union'],
        'checked_scope' => 'own',
        'ownership_check' => ['entity' => 'Unit', 'display_name' => 'unit', 'count' => 0],
        'question_language' => 'en',
    ];

    /** The host nouns CondoEdge declares for the kinds involved. */
    private function declareCheckedKinds(): void
    {
        config([
            'ai.response_generation.property_header_language' => 'en',
            'entities' => [
                'App\\Models\\Invoice' => ['graph' => ['label' => 'Invoice'], 'metadata' => ['display_name' => 'invoice', 'display_name_plural' => 'invoices']],
                'App\\Models\\Union' => ['graph' => ['label' => 'Union'], 'metadata' => ['display_name' => 'union', 'display_name_plural' => 'unions']],
                'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['display_name' => 'unit', 'display_name_plural' => 'units']],
                'App\\Models\\Meeting' => ['graph' => ['label' => 'Meeting'], 'metadata' => ['display_name' => 'meeting', 'display_name_plural' => 'meetings']],
                'App\\Models\\Contact' => ['graph' => ['label' => 'Contact'], 'metadata' => ['display_name_plural' => 'contacts']],
            ],
        ]);
    }

    /**
     * One empty answer: [the result, the prompt the model received].
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function emptyAnswer(string $modelAnswer, ?string $cypher, array $options, string $question = 'So how much is my contribution this year?'): array
    {
        $captured = '';
        $this->llmMock->shouldReceive('complete')->once()
            ->andReturnUsing(function ($user) use (&$captured, $modelAnswer) {
                $captured = $user;

                return $modelAnswer;
            });

        return [$this->generator->generateEmptyResponse($question, $cypher, $options), $captured];
    }

    /**
     * @test eval p20-B rights t2, replayed: the model left out what was checked ("This is why nothing
     * matched your question regarding the contribution amount."); p19 said "invoices and other
     * related records". What the executed query READ (its RETURN's entities), within WHICH records
     * (the query is anchored on the user, through the units the ownership check counted) and that
     * nothing matched are FACTS: code prints them on a line of its own below the model's answer,
     * in the host's plurals — and the model is told, so it does not restate them.
     */
    public function p20_b_rights_t2_what_was_checked_is_printed_by_code(): void
    {
        $this->declareCheckedKinds();
        $model = 'You have no unit linked to your account, so there is nothing of your own to show for your contribution this year.';

        [$result, $prompt] = $this->emptyAnswer($model, self::RIGHTS_T2['p20'], self::OWN_NO_UNIT);

        $line = 'Checked: invoices, unions for your units — nothing matched your question.';
        $this->assertSame($model . "\n\n" . $line, $result['answer']);
        $this->assertSame($line, $result['metadata']['checked_line'] ?? null);
        $this->assertSame(['invoices', 'unions'], $result['metadata']['checked_kinds'] ?? null);

        $this->assertStringContainsString(
            'The system itself prints, on a line right below your answer, what was checked: "' . $line . '" — do NOT restate it: '
                . 'do not list the record kinds and do not say again that they were checked.',
            $prompt
        );
        $this->assertStringNotContainsString('State plainly what was checked', $prompt);
        $this->assertStringNotContainsString('Describe the record kinds', $prompt);
        // What stays the model's: that nothing matched, with the question's qualifiers, and the verified reason.
        $this->assertStringContainsString('never a bare "no results were found"', $prompt);
        $this->assertStringContainsString("Keep the question's own qualifiers", $prompt);
        $this->assertStringContainsString('VERIFIED BY THE SYSTEM: this user has NO unit linked to their account', $prompt);
    }

    /** The report button the stored rights t2 turns carry. */
    private function receivablesReport(): \Condoedge\Ai\Services\Actions\Attach\AttachedActions
    {
        return new \Condoedge\Ai\Services\Actions\Attach\AttachedActions([[
            'role' => \Condoedge\Ai\Services\Actions\Attach\AttachedActions::ROLE_NEXT,
            'invocation' => new \Condoedge\Ai\Services\Actions\ActionInvocation('receivables_report', null, null, [], 'Receivables report'),
        ]]);
    }

    /**
     * @test eval p21-B rights t2, replayed: the model copied the narrator instruction's own label
     * into the answer ("Attached below this answer: Receivables report."). A sentence that is only
     * this turn's button labels after a colon is dropped by code (the button is right below), and
     * the trace says so. The instruction no longer offers that phrase to copy.
     */
    public function p21_b_rights_t2_a_copied_prompt_label_is_dropped_from_the_empty_answer(): void
    {
        $this->declareCheckedKinds();
        $said = 'You have no unit linked to your account, so there are no contributions to show for this year.';
        $echo = 'Attached below this answer: Receivables report.';

        [$result, $prompt] = $this->emptyAnswer($said . "\n\n" . $echo, self::RIGHTS_T2['p20'], self::OWN_NO_UNIT + ['attached_actions' => $this->receivablesReport()]);

        $this->assertSame($said . "\n\nChecked: invoices, unions for your units — nothing matched your question.", $result['answer']);
        $this->assertSame([$echo], $result['metadata']['label_echo_dropped'] ?? null);

        $this->assertStringContainsString('The buttons attached below this answer are "Receivables report" — the user already sees them: never list or repeat them.', $prompt);
        $this->assertStringNotContainsString('): Receivables report', $prompt);
    }

    /** @test A sentence of the model's own that points at the button is prose: it stays, and nothing is recorded. */
    public function a_sentence_pointing_at_the_attached_button_stays_in_the_empty_answer(): void
    {
        $this->declareCheckedKinds();
        // eval p20-B / p20x-B rights t2, as stored.
        $said = 'You have no unit linked to your account, so there are no contributions to show for this year. '
            . 'You can check the receivables report for more details.';

        [$result] = $this->emptyAnswer($said, self::RIGHTS_T2['p20'], self::OWN_NO_UNIT + ['attached_actions' => $this->receivablesReport()]);

        $this->assertStringStartsWith($said . "\n\nChecked: ", $result['answer']);
        $this->assertArrayNotHasKey('label_echo_dropped', $result['metadata']);
    }

    /** @test A navigation ask is ASKED to name the attached page: its answer is never read for an echo. */
    public function a_navigation_answer_naming_its_page_is_left_alone(): void
    {
        $profile = new \Condoedge\Ai\Services\Actions\Attach\AttachedActions([[
            'role' => \Condoedge\Ai\Services\Actions\Attach\AttachedActions::ROLE_IDENTITY,
            'invocation' => new \Condoedge\Ai\Services\Actions\ActionInvocation('my_profile', null, null, [], 'My profile'),
        ]]);
        $said = "Here you go.\n\nAttached below: My profile.";

        [$result] = $this->emptyAnswer($said, null, ['attached_actions' => $profile, 'navigation_target' => 'own_profile', 'question_language' => 'en'], 'Can you give me the link to my profile?');

        $this->assertSame($said, $result['answer']);
        $this->assertArrayNotHasKey('label_echo_dropped', $result['metadata']);
    }

    /**
     * @test eval p22-B app-nav: the echo over two lines (a colon line, then a list of only the
     * button's label) is dropped from an empty-result answer — and, like the one-line echo,
     * never on a navigation ask, which is ASKED to name the attached page.
     */
    public function a_label_only_list_under_a_colon_line_is_dropped_but_never_on_a_navigation_ask(): void
    {
        $this->declareCheckedKinds();
        $said = 'You have no unit linked to your account, so there are no contributions to show for this year.';
        $list = "Here are the next steps you can take:\n\n- Receivables report";

        [$result] = $this->emptyAnswer($said . "\n\n" . $list, self::RIGHTS_T2['p20'], self::OWN_NO_UNIT + ['attached_actions' => $this->receivablesReport()]);

        $this->assertStringStartsWith($said . "\n\nChecked: ", $result['answer']);
        $this->assertStringNotContainsString('next steps', $result['answer']);
        $this->assertSame(['Here are the next steps you can take:', '- Receivables report'], $result['metadata']['label_echo_dropped'] ?? null);

        $profile = new \Condoedge\Ai\Services\Actions\Attach\AttachedActions([[
            'role' => \Condoedge\Ai\Services\Actions\Attach\AttachedActions::ROLE_IDENTITY,
            'invocation' => new \Condoedge\Ai\Services\Actions\ActionInvocation('my_profile', null, null, [], 'My profile'),
        ]]);
        $navigation = "Here you go. You can open it here:\n\n- My profile";

        [$kept] = $this->emptyAnswer($navigation, null, ['attached_actions' => $profile, 'navigation_target' => 'own_profile', 'question_language' => 'en'], 'Can you give me the link to my profile?');

        $this->assertSame($navigation, $kept['answer']);
        $this->assertArrayNotHasKey('label_echo_dropped', $kept['metadata']);
    }

    /**
     * @test eval p23-B rights t2, replayed: the model copied the line the prompt QUOTES ("The system
     * itself prints … what was checked: "Checked: …"") as its own last line, and code appended its
     * copy below — the line stood twice. The model's copy is dropped before code prints its own,
     * and the trace says so.
     */
    public function p23_b_rights_t2_the_models_copy_of_the_checked_line_is_dropped(): void
    {
        $this->declareCheckedKinds();
        $said = 'You have no unit linked to your account, so there are no contributions to show for this year. '
            . 'You may want to check the receivables report for more information.';
        $line = 'Checked: invoices for your units — nothing matched your question.';

        // As stored: a trailing space, a blank line, then the copy.
        [$result] = $this->emptyAnswer($said . " \n\n" . $line, self::RIGHTS_T2['p18'], self::OWN_NO_UNIT + ['attached_actions' => $this->receivablesReport()]);

        $this->assertSame($said . "\n\n" . $line, $result['answer']);
        $this->assertSame(1, substr_count($result['answer'], 'Checked:'));
        $this->assertSame($line, $result['metadata']['checked_line'] ?? null);
        $this->assertSame([$line], $result['metadata']['checked_line_echo'] ?? null);
    }

    /**
     * @test The copy is recognised by its WORDS, in order — never by its bytes: another dash, no
     * final period, other casing, the prompt's quotes or an emphasis around it, a list bullet, or
     * the line glued to the end of the model's paragraph are all the same line.
     */
    public function the_copy_of_the_checked_line_is_recognised_whatever_its_punctuation_or_position(): void
    {
        $this->declareCheckedKinds();
        $said = 'You have no unit linked to your account, so there are no contributions to show for this year.';
        $line = 'Checked: invoices for your units — nothing matched your question.';
        $copies = [
            "\n\n" . '"checked: invoices for your units - nothing matched your question"',
            "\n\n" . '*Checked: invoices for your units – nothing matched your question.*',
            "\n- Checked : invoices for your units, nothing matched your question.",
            ' Checked: invoices for your units — nothing matched your question.',
        ];

        foreach ($copies as $copy) {
            [$result] = $this->emptyAnswer($said . $copy, self::RIGHTS_T2['p18'], self::OWN_NO_UNIT);

            $this->assertSame($said . "\n\n" . $line, $result['answer'], 'copy: ' . $copy);
            $this->assertCount(1, $result['metadata']['checked_line_echo'] ?? [], 'copy: ' . $copy);
        }

        // An answer that is ONLY the copy: the code's line is the answer, once.
        [$only] = $this->emptyAnswer($line, self::RIGHTS_T2['p18'], self::OWN_NO_UNIT);
        $this->assertSame($line, $only['answer']);
        $this->assertSame([$line], $only['metadata']['checked_line_echo'] ?? null);
    }

    /** @test A sentence of the model's own about what was checked is prose: it stays, nothing is recorded. */
    public function a_sentence_of_the_models_own_about_what_was_checked_is_not_an_echo(): void
    {
        $this->declareCheckedKinds();
        $said = 'I checked the invoices for your units and nothing matched your question about this year.';

        [$result] = $this->emptyAnswer($said, self::RIGHTS_T2['p18'], self::OWN_NO_UNIT);

        $this->assertSame($said . "\n\nChecked: invoices for your units — nothing matched your question.", $result['answer']);
        $this->assertArrayNotHasKey('checked_line_echo', $result['metadata']);
    }

    /** @test The stored p18 and p19 turns through the same path: the kinds are each executed query's own. */
    public function p18_and_p19_rights_t2_replayed_through_the_code_written_line(): void
    {
        $this->declareCheckedKinds();

        [$p19] = $this->emptyAnswer('You have no unit linked to your account.', self::RIGHTS_T2['p19'], self::OWN_NO_UNIT);
        $this->assertStringEndsWith("\n\nChecked: unions, invoices for your units — nothing matched your question.", $p19['answer']);

        [$p18] = $this->emptyAnswer('You have no unit linked to your account.', self::RIGHTS_T2['p18'], self::OWN_NO_UNIT);
        $this->assertStringEndsWith("\n\nChecked: invoices for your units — nothing matched your question.", $p18['answer']);
    }

    /** @test The line is said in the answer's language: the French template, the host's French plurals. */
    public function the_checked_line_follows_the_answer_language(): void
    {
        $this->declareCheckedKinds();
        app('translator')->get('ai.response.checked', [], 'fr');
        app('translator')->addLines(['*.invoices' => 'factures', '*.unions' => 'syndicats', '*.units' => 'unités'], 'fr');

        [$result, $prompt] = $this->emptyAnswer(
            "Aucune unité n'est liée à votre compte.",
            self::RIGHTS_T2['p20'],
            ['question_language' => 'fr'] + self::OWN_NO_UNIT,
            'Alors, à combien s\'élève ma contribution cette année ?'
        );

        $line = 'Vérifié : factures, syndicats pour vos unités — aucun résultat pour votre question.';
        $this->assertStringEndsWith("\n\n" . $line, $result['answer']);
        $this->assertStringContainsString('"' . $line . '"', $prompt);
    }

    /**
     * @test Only what is PROVEN is written. The scope words follow the executed query's scope: the
     * user's own records through the counted ownership path, their own records without one, the
     * team's records, or no scope at all.
     */
    public function the_checked_line_states_only_the_scope_that_is_established(): void
    {
        $this->declareCheckedKinds();
        $invoices = 'MATCH (i:Invoice) WHERE i.due_amount > 0 RETURN i.invoice_number AS invoice_number, i.id AS invoice_id';
        $meetings = 'MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids AND m.start_date > datetime() RETURN m.name AS name, m.id AS meeting_id';
        $base = ['checked_subject' => 'x', 'question_language' => 'en'];

        [$ownOnly] = $this->emptyAnswer('Nothing matched.', $invoices, $base + ['checked_scope' => 'own']);
        $this->assertStringEndsWith('Checked: invoices (your own records) — nothing matched your question.', $ownOnly['answer']);

        [$team] = $this->emptyAnswer('No upcoming meeting.', $meetings, $base + ['checked_scope' => 'team'], 'When is our next meeting?');
        $this->assertStringEndsWith("Checked: meetings (your team's records) — nothing matched your question.", $team['answer']);

        [$unknown] = $this->emptyAnswer('Nothing matched.', $invoices, $base);
        $this->assertStringEndsWith('Checked: invoices — nothing matched your question.', $unknown['answer']);

        // The owned kind IS what was looked for: "units for your units" says nothing — the plain own line.
        $units = 'MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) RETURN u.name AS unit_name, u.id AS unit_id';
        [$owned] = $this->emptyAnswer('You have no unit.', $units, $base + ['checked_scope' => 'own', 'ownership_check' => ['entity' => 'Unit', 'display_name' => 'unit', 'count' => 0]]);
        $this->assertStringEndsWith('Checked: units (your own records) — nothing matched your question.', $owned['answer']);

        // An ownership fact is only used for an own-data scope.
        [$teamWithFact] = $this->emptyAnswer('Nothing matched.', $invoices, $base + ['checked_scope' => 'team', 'ownership_check' => ['entity' => 'Unit', 'display_name' => 'unit', 'count' => 0]]);
        $this->assertStringEndsWith("Checked: invoices (your team's records) — nothing matched your question.", $teamWithFact['answer']);
    }

    /**
     * @test No line where its facts are not established or cannot be said: no executed query (QU's
     * kinds are a guess), a kind the host gave no plural, a plural it cannot say in the answer's
     * language, a navigation ask, a failed completion. The model's own instruction stands there.
     */
    public function no_checked_line_is_printed_without_its_facts_or_its_words(): void
    {
        $this->declareCheckedKinds();
        $own = self::OWN_NO_UNIT;

        [$noQuery, $prompt] = $this->emptyAnswer('Nothing matched.', null, $own);
        $this->assertSame('Nothing matched.', $noQuery['answer']);
        $this->assertArrayNotHasKey('checked_line', $noQuery['metadata']);
        $this->assertStringContainsString('State plainly what was checked', $prompt);
        $this->assertStringNotContainsString('The system itself prints', $prompt);

        // (French answer, no French line for the host's plurals: never "Vérifié : invoices…".)
        [$unsayable] = $this->emptyAnswer('Rien.', self::RIGHTS_T2['p20'], ['question_language' => 'fr'] + $own);
        $this->assertSame('Rien.', $unsayable['answer']);

        config(['entities' => ['App\\Models\\Invoice' => ['graph' => ['label' => 'Invoice'], 'metadata' => ['display_name' => 'invoice']]]]);
        [$noPlural] = $this->emptyAnswer('Nothing matched.', self::RIGHTS_T2['p18'], $own);
        $this->assertSame('Nothing matched.', $noPlural['answer']);
    }

    /** @test */
    public function it_generates_response_from_query_results(): void
    {
        $question = "How many customers do we have?";
        $data = [
            ['count' => 42]
        ];
        $cypher = "MATCH (c:Customer) RETURN count(c) as count";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("You have 42 customers in total.");

        $result = $this->generator->generate($this->makeContext($question, $data, $cypher));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('answer', $result);
        $this->assertArrayHasKey('format', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertEquals("You have 42 customers in total.", $result['answer']);
    }

    /** @test */
    public function it_generates_response_in_markdown_format(): void
    {
        $question = "Show top customers";
        $data = [
            ['name' => 'Alice', 'orders' => 10],
            ['name' => 'Bob', 'orders' => 8]
        ];
        $cypher = "MATCH (c:Customer) RETURN c.name, c.orders ORDER BY c.orders DESC LIMIT 2";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("## Top Customers\n\n- Alice: 10 orders\n- Bob: 8 orders");

        $result = $this->generator->generate($this->makeContext($question, $data, $cypher), [
            'format' => 'markdown'
        ]);

        $this->assertEquals('markdown', $result['format']);
        $this->assertStringContainsString('##', $result['answer']);
    }

    /** @test */
    public function it_generates_response_in_concise_style(): void
    {
        $question = "How many orders?";
        $data = [['count' => 100]];
        $cypher = "MATCH (o:Order) RETURN count(o)";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("100 orders.");

        $result = $this->generator->generate($this->makeContext($question, $data, $cypher), [
            'style' => 'concise'
        ]);

        $this->assertEquals('concise', $result['metadata']['style']);
        $this->assertLessThan(50, strlen($result['answer']));
    }

    /** @test */
    public function it_summarizes_large_result_sets(): void
    {
        $question = "List all customers";

        // Create 15 rows of data
        $data = [];
        for ($i = 1; $i <= 15; $i++) {
            $data[] = ['id' => $i, 'name' => "Customer $i"];
        }

        $cypher = "MATCH (c:Customer) RETURN c.id, c.name";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("Here are the first 10 customers out of 15 total...");

        $result = $this->generator->generate($this->makeContext($question, $data, $cypher));

        $this->assertTrue($result['metadata']['summarized']);
        $this->assertEquals(15, $result['metadata']['result_count']);
    }

    /** @test */
    public function it_generates_empty_response_when_no_results(): void
    {
        // No-results handling now lives in the dedicated generateEmptyResponse()
        // path (the orchestrator routes empty executions there), so the
        // empty_result contract is asserted through that method.
        $question = "Find customers named Zzzz";
        $cypher = "MATCH (c:Customer {name: 'Zzzz'}) RETURN c";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("No customers found with that name. Try a different search term.");

        $result = $this->generator->generateEmptyResponse($question, $cypher);

        $this->assertTrue($result['metadata']['empty_result']);
        $this->assertEquals(0, $result['metadata']['result_count']);
        $this->assertStringContainsString('No', $result['answer']);
    }

    /** @test */
    public function it_generates_empty_response_directly(): void
    {
        $question = "Find non-existent data";
        $cypher = "MATCH (n:NonExistent) RETURN n";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("No results were found. The data you're looking for might not exist.");

        $result = $this->generator->generateEmptyResponse($question, $cypher);

        $this->assertTrue($result['metadata']['empty_result']);
        $this->assertEquals(0, $result['metadata']['result_count']);
        $this->assertEquals('text', $result['format']);
    }

    /** @test */
    public function it_handles_llm_failure_in_empty_response(): void
    {
        $question = "Find something";
        $cypher = "MATCH (n) RETURN n";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andThrow(new \RuntimeException("LLM API failed"));

        $result = $this->generator->generateEmptyResponse($question, $cypher);

        $this->assertTrue($result['metadata']['empty_result']);
        $this->assertTrue($result['metadata']['fallback']);
        $this->assertStringContainsString('No results', $result['answer']);
    }

    /** @test */
    public function it_generates_error_response(): void
    {
        $question = "Complex query";
        $error = new \RuntimeException("Query timeout exceeded");

        $result = $this->generator->generateErrorResponse($question, $error);

        $this->assertTrue($result['metadata']['error']);
        $this->assertEquals('RuntimeException', $result['metadata']['error_type']);
        $this->assertStringContainsString('issue', $result['answer']);
        // The implementation checks for 'timeout' in error message and adds 'too long' guidance
        $this->assertStringContainsString('too long', $result['answer']);
    }

    /** @test */
    public function it_generates_error_response_with_syntax_guidance(): void
    {
        $question = "Bad query";
        $error = new \RuntimeException("syntax error in query");

        $result = $this->generator->generateErrorResponse($question, $error);

        // The implementation checks for 'syntax' (lowercase) in error message
        $this->assertStringContainsString('issue with the generated query', $result['answer']);
        $this->assertStringContainsString('rephrasing', $result['answer']);
    }

    /** @test */
    public function error_response_follows_the_question_language_not_the_app_locale(): void
    {
        // The iter-3 live failure: FRENCH canned error ("J'ai rencontré un
        // problème...") answered an ENGLISH question because __() rendered under
        // the app locale. With the QU-detected question language threaded in, the
        // error copy must follow the QUESTION's language.
        app()->setLocale('fr');

        $result = $this->generator->generateErrorResponse(
            'I mean what unit 310 owes.',
            new \RuntimeException('HTTP 429 rate limit'),
            ['question_language' => 'en']
        );

        $this->assertStringContainsString('I encountered an issue', $result['answer']);
        $this->assertStringNotContainsString('J\'ai rencontré un problème', $result['answer']);
        $this->assertStringNotContainsString('Veuillez', $result['answer']);
    }

    /** @test */
    public function error_response_keeps_the_app_locale_when_no_question_language_is_threaded(): void
    {
        // Unchanged behavior when QU produced no language: the app locale decides
        // (a French host answering with no language signal stays French).
        app()->setLocale('fr');

        $result = $this->generator->generateErrorResponse(
            'Une question quelconque',
            new \RuntimeException('boom')
        );

        $this->assertStringContainsString('J\'ai rencontré un problème', $result['answer']);
    }

    /** @test */
    public function error_response_renders_french_for_a_french_question_in_an_english_host(): void
    {
        app()->setLocale('en');

        $result = $this->generator->generateErrorResponse(
            'Montre-moi le solde.',
            new \RuntimeException('boom'),
            ['question_language' => 'fr']
        );

        $this->assertStringContainsString('J\'ai rencontré un problème', $result['answer']);
    }

    /** @test */
    public function it_includes_error_details_when_requested(): void
    {
        $question = "Test query";
        $error = new \RuntimeException("Detailed error message");

        $result = $this->generator->generateErrorResponse($question, $error, [
            'include_details' => true
        ]);

        $this->assertArrayHasKey('error_message', $result['metadata']);
        $this->assertEquals("Detailed error message", $result['metadata']['error_message']);
    }

    /** @test */
    public function it_throws_exception_when_response_generation_fails(): void
    {
        $question = "Test question";
        $data = [['id' => 1]];
        $cypher = "MATCH (n) RETURN n";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andThrow(new \RuntimeException("API Error"));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Response generation failed");

        $this->generator->generate($this->makeContext($question, $data, $cypher));
    }

    /** @test */
    public function it_respects_custom_temperature(): void
    {
        $question = "Test";
        $data = [['id' => 1]];
        $cypher = "MATCH (n) RETURN n";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->with(
                Mockery::type('string'),
                // Identity/security ride in the real system role, so the second
                // argument is now the system prompt string rather than null.
                Mockery::type('string'),
                Mockery::on(function ($options) {
                    return isset($options['temperature']) && $options['temperature'] === 0.7;
                })
            )
            ->andReturn("Creative response.");

        $result = $this->generator->generate($this->makeContext($question, $data, $cypher), [
            'temperature' => 0.7
        ]);

        $this->assertNotEmpty($result['answer']);
    }

    /** @test */
    public function it_respects_custom_max_length(): void
    {
        $question = "Test";
        $data = [['id' => 1]];
        $cypher = "MATCH (n) RETURN n";

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->with(
                Mockery::type('string'),
                // System prompt string is supplied in the real system role.
                Mockery::type('string'),
                Mockery::on(function ($options) {
                    // max_length 100 words ≈ 133 tokens (100 / 0.75)
                    return isset($options['max_tokens']) && $options['max_tokens'] >= 130;
                })
            )
            ->andReturn("Short response.");

        $result = $this->generator->generate($this->makeContext($question, $data, $cypher), [
            'max_length' => 100
        ]);

        $this->assertNotEmpty($result['answer']);
    }

    /** @test */
    public function a_prompt_section_that_fails_to_load_is_skipped_not_fatal(): void
    {
        // A host config may still reference a section class that was removed/renamed
        // (its file deleted from the package). Loading it must skip-and-log, never
        // crash the whole response pipeline. Reproduces the live CondoEdge failure
        // where a stale config referenced the removed QueryInfoSection.
        config()->set('ai.response_generator_sections', [
            ThrowingPromptSection::class,
            \Condoedge\Ai\Services\ResponseSections\GuidelinesSection::class,
        ]);

        $generator = new ResponseGenerator($this->llmMock, $this->config);

        // Construction did not crash despite the unloadable section.
        $this->assertInstanceOf(ResponseGenerator::class, $generator);
    }
}

/**
 * Stands in for a host config that references a section whose class file was
 * deleted (the deleted-file autoload also throws); construction fails hard.
 */
class ThrowingPromptSection
{
    public function __construct()
    {
        throw new \RuntimeException('section unavailable');
    }
}
