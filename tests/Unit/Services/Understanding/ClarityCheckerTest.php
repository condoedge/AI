<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Understanding;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Understanding\ClarityChecker;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\Fixtures\ScriptedUsageLlm;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The focused clarity call (eval p15 decision): "is this question genuinely
 * ambiguous?" used to be three output keys of EVERY Query-Understanding call and
 * came back true on 0 of 148 eval turns. It is now ONE small LLM call, made only
 * when cheap STRUCTURAL preconditions say the question could be a fork at all: a
 * first message, asking for data, that names no kind of record and no person.
 *
 * Two halves are pinned here:
 *  - blockedBy(): each precondition, alone, rules the call out;
 *  - check(): the reply is turned into a verdict by STRUCTURE — which first-person
 *    words the question has and their grammatical form, which words name records,
 *    how many kinds of records can carry what is asked — never by word lists.
 *
 * White-label: the kinds of records are host configuration; the package prompt
 * holds no domain vocabulary (a deliberately non-host catalogue is used here).
 */
class ClarityCheckerTest extends TestCase
{
    private const QUESTION = 'What is the status?';

    /** A reply that makes the question a fork: no owner, no named record, two kinds. */
    private const FORK = [
        'asked' => 'the status',
        'first_person' => [],
        'forms' => [],
        'named' => '',
        'candidates' => ['aircraft', 'work card', 'part'],
        'readings' => ['the status of an aircraft', 'the status of a work card', 'the status of a part'],
        'question' => 'Do you mean the status of an aircraft, of a work card or of a part?',
    ];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('logging.default', 'null');
    }

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.understanding.clarify_enabled' => true]);
        config(['ai.query_understanding.enabled' => true]);
        config(['entities' => [
            'App\\Models\\Fleet\\Aircraft' => ['graph' => ['label' => 'Aircraft'], 'metadata' => ['aliases' => ['aircrafts', 'plane', 'avion']]],
            'App\\Models\\Fleet\\WorkCard' => ['graph' => ['label' => 'WorkCard'], 'metadata' => ['aliases' => ['task card', 'carte de travail']]],
            'App\\Models\\Fleet\\Part' => ['graph' => ['label' => 'Part'], 'metadata' => ['display_name' => 'part', 'aliases' => ['component', 'pièce']]],
        ]]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function checker(?LlmProviderInterface $llm = null): ClarityChecker
    {
        return new ClarityChecker($llm ?? new ScriptedUsageLlm(json: self::FORK));
    }

    /** What Query Understanding returns for a subject-less data question. */
    private function qu(array $overrides = []): QueryUnderstandingResult
    {
        return new QueryUnderstandingResult(...$overrides + [
            'propertyConcepts' => ['status'],
            'searchQuery' => 'status',
            'intent' => 'lookup',
            'questionType' => 'data_query',
            'language' => 'en',
        ]);
    }

    // ------------------------------------------------------------------
    // Structural preconditions
    // ------------------------------------------------------------------

    /** @test */
    public function a_first_message_asking_for_data_that_names_nothing_passes_every_precondition(): void
    {
        $this->assertNull($this->checker()->blockedBy(self::QUESTION, $this->qu()));
        $this->assertNull($this->checker()->blockedBy(self::QUESTION, $this->qu(['questionType' => 'hybrid'])));
    }

    /**
     * @test
     * @dataProvider blockingUnderstandings
     */
    public function each_understanding_signal_alone_rules_the_call_out(string $expected, array $quOverrides): void
    {
        $this->assertSame($expected, $this->checker()->blockedBy(self::QUESTION, $this->qu($quOverrides)));
    }

    public static function blockingUnderstandings(): array
    {
        return [
            'QU itself failed' => ['qu_failed', ['failed' => true]],
            'a question for documents, not data' => ['question_type', ['questionType' => 'knowledge_query']],
            'a person is named' => ['person_named', ['personNames' => ['Ana Diaz']]],
            'a follow-up on the previous answer' => ['follow_up', ['followUpReference' => true]],
            'a broad what-needs-my-attention ask' => ['digest_request', ['digestRequest' => true]],
            'an export ask' => ['export_request', ['exportFormat' => 'excel']],
            'a navigation ask' => ['navigation_request', ['navigationTarget' => 'own_team']],
        ];
    }

    /** @test */
    public function a_question_type_given_to_a_question_tied_to_nothing_is_not_a_judgment(): void
    {
        // Measured live (2026-10-06): "What is the total?" came back data_query on 3 runs
        // and knowledge_query on 2 — each time with no entity type and no property concept.
        // The type blocks only when QU tied the question to something.
        $tiedToNothing = ['questionType' => 'knowledge_query', 'entityTypes' => [], 'propertyConcepts' => []];

        $this->assertNull($this->checker()->blockedBy(self::QUESTION, $this->qu($tiedToNothing)));
        $this->assertSame('question_type', $this->checker()->blockedBy(
            self::QUESTION,
            $this->qu(['entityTypes' => ['WorkCard']] + $tiedToNothing)
        ));
        $this->assertSame('question_type', $this->checker()->blockedBy(
            self::QUESTION,
            $this->qu(['propertyConcepts' => ['rules']] + $tiedToNothing)
        ));
    }

    /**
     * @test
     * @dataProvider intentsThatAskForNoRecords
     */
    public function an_ask_for_the_users_files_or_about_the_page_rules_the_call_out(string $intent): void
    {
        $this->assertSame('intent', $this->checker()->blockedBy(self::QUESTION, $this->qu(['intent' => $intent])));
    }

    public static function intentsThatAskForNoRecords(): array
    {
        return [['file_listing'], ['page_explanation']];
    }

    /** @test */
    public function the_feature_switch_rules_the_call_out(): void
    {
        config(['ai.understanding.clarify_enabled' => false]);

        $this->assertSame('disabled', $this->checker()->blockedBy(self::QUESTION, $this->qu()));
    }

    /** @test */
    public function without_query_understanding_there_is_nothing_to_judge_from(): void
    {
        config(['ai.query_understanding.enabled' => false]);

        // QU off leaves the neutral default result: its "no follow-up, no export" means unknown.
        $this->assertSame('qu_disabled', $this->checker()->blockedBy(self::QUESTION, new QueryUnderstandingResult()));
    }

    /**
     * @test
     * @dataProvider conversationsThatCanSupplyTheSubject
     */
    public function a_conversation_that_can_supply_the_subject_rules_the_call_out(array $conversation): void
    {
        $this->assertSame(
            'conversation_context',
            $this->checker()->blockedBy(self::QUESTION, $this->qu(), $conversation)
        );
    }

    public static function conversationsThatCanSupplyTheSubject(): array
    {
        return [
            'an earlier exchange' => [['recent_exchanges' => [
                ['role' => 'user', 'question' => 'List the grounded aircraft', 'answer_summary' => null],
                ['role' => 'assistant', 'question' => null, 'answer_summary' => 'Two aircraft are grounded.'],
            ]]],
            'an earlier answer only' => [['recent_exchanges' => [
                ['role' => 'assistant', 'question' => null, 'answer_summary' => 'Two aircraft are grounded.'],
            ]]],
            'a recorded result' => [['last_result_count' => 3, 'recent_exchanges' => []]],
            'a recorded query' => [['last_cypher_query' => 'MATCH (a:Aircraft) RETURN a']],
            'a recorded subject' => [['last_subject' => 'grounded aircraft']],
            'a focused entity' => [['focused_entity' => 'Aircraft']],
            'cited documents' => [['last_referenced_files' => [['name' => 'manual.pdf']]]],
        ];
    }

    /** @test */
    public function an_empty_conversation_snapshot_is_a_first_message(): void
    {
        // The chat hands every turn the full snapshot shape; on a first message it is all empty.
        $firstTurn = [
            'focused_entity' => null,
            'focused_entity_filter' => null,
            'last_result_sample' => [],
            'last_result_count' => 0,
            'last_cypher_query' => null,
            'last_entity_types' => [],
            'last_subject' => null,
            'last_referenced_files' => [],
            'recent_exchanges' => [],
        ];

        $this->assertNull($this->checker()->blockedBy(self::QUESTION, $this->qu(), $firstTurn));
    }

    /** @test */
    public function the_current_question_already_stored_as_the_last_exchange_is_still_a_first_message(): void
    {
        // Regenerate path: the user message was persisted before the snapshot was built.
        $conversation = ['recent_exchanges' => [
            ['role' => 'user', 'question' => self::QUESTION, 'answer_summary' => null],
        ]];

        $this->assertNull($this->checker()->blockedBy(self::QUESTION, $this->qu(), $conversation));
    }

    /** @test */
    public function a_page_the_chat_was_opened_from_rules_the_call_out(): void
    {
        $this->assertSame('page_context', $this->checker()->blockedBy(
            self::QUESTION,
            $this->qu(),
            [],
            ['route' => 'aircraft.show', 'entity_type' => 'Aircraft', 'entity_id' => 7]
        ));
    }

    /**
     * @test
     * @dataProvider questionsThatNameAKindOfRecord
     */
    public function a_question_that_names_a_kind_of_record_rules_the_call_out(string $question): void
    {
        $this->assertSame('entity_named', $this->checker()->blockedBy($question, $this->qu()));
    }

    public static function questionsThatNameAKindOfRecord(): array
    {
        return [
            'the label' => ['What is the status of the aircraft?'],
            'the label, as people type it' => ['Show the open work cards.'],
            'a synonym' => ['What is the status of the plane?'],
            'a synonym in another language, accent-less' => ['Quel est le statut de la piece ?'],
        ];
    }

    /** @test */
    public function what_query_understanding_guessed_about_a_subject_less_question_does_not_rule_the_call_out(): void
    {
        // Measured live (2026-10-06, 3 runs each): for "Show me the balance." QU returned
        // is_possessive TRUE every time (it invents an owner) and a guessed entity type;
        // for "What is the total?" it returned no property concept. None of these is a
        // statement of the QUESTION, so none of them gates the call: the gate reads the
        // question's own text, and the focused call judges whose/which record is stated.
        $this->assertNull($this->checker()->blockedBy(self::QUESTION, $this->qu([
            'isPossessive' => true,
            'entityTypes' => ['WorkCard'],
            'propertyConcepts' => [],
            'clarifiedQuestion' => 'status associated with my work cards',
        ])));
    }

    /** @test */
    public function with_fewer_than_two_kinds_of_records_there_is_no_fork_to_ask_about(): void
    {
        config(['entities' => ['App\\Models\\Fleet\\Aircraft' => ['graph' => ['label' => 'Aircraft']]]]);

        $this->assertSame('no_record_kinds', $this->checker()->blockedBy(self::QUESTION, $this->qu()));
    }

    /** @test */
    public function a_blank_question_rules_the_call_out(): void
    {
        $this->assertSame('empty_question', $this->checker()->blockedBy('   ', $this->qu()));
    }

    // ------------------------------------------------------------------
    // The focused call
    // ------------------------------------------------------------------

    /** @test */
    public function a_fork_comes_back_as_the_readings_and_the_one_clarifying_question(): void
    {
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $verdict = $this->checker($llm)->check(self::QUESTION, 'en');

        $this->assertSame([
            'ask' => true,
            'reason' => 'ambiguous',
            'readings' => ['the status of an aircraft', 'the status of a work card', 'the status of a part'],
            'question' => 'Do you mean the status of an aircraft, of a work card or of a part?',
        ], $verdict);
        $this->assertCount(1, $llm->calls, 'exactly ONE LLM call');
    }

    /** @test */
    public function the_call_is_small_deterministic_and_carries_the_question_and_its_language(): void
    {
        $captured = [];
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function (array $messages, array $options) use (&$captured) {
            $captured = [$messages, $options];

            return self::FORK;
        });

        $this->checker($llm)->check('Quel est le statut ?', 'fr');

        [$messages, $options] = $captured;
        $this->assertSame(0.0, $options['temperature']);
        $this->assertLessThanOrEqual(400, $options['max_tokens']);
        $this->assertSame(['system', 'user'], array_column($messages, 'role'));
        $this->assertStringContainsString('Question (written in "fr"): Quel est le statut ?', $messages[1]['content']);
        $this->assertStringContainsString('Write "readings" and "question" in "fr".', $messages[1]['content']);
        $this->assertStringNotContainsString('Quel est le statut ?', $messages[0]['content'], 'the system prompt is the same for every question');
    }

    /** @test */
    public function the_prompt_lists_the_kinds_of_records_in_plain_words_with_their_synonyms_and_no_raw_label(): void
    {
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $this->checker($llm)->check(self::QUESTION, 'en');

        $system = $llm->calls[0][0]['content'];
        $this->assertStringContainsString('- aircraft (plane, avion)', $system);
        $this->assertStringContainsString('- work card (task card, carte de travail)', $system);
        $this->assertStringContainsString('- part (component, pièce)', $system);
        // A raw label would end up in the user-facing clarifying question.
        $this->assertStringNotContainsString('WorkCard', $system);
        $this->assertStringNotContainsString('App\\Models', $system);
    }

    // ------------------------------------------------------------------
    // Kinds a user can open or list (eval p18-A: "balance of a contact unit")
    // ------------------------------------------------------------------

    /** The host exposes a record page for Aircraft (entity action) and a list page for WorkCard — none for Part. */
    private function exposePagesForAircraftAndWorkCard(): void
    {
        config([
            'ai.entity_actions' => ['Aircraft' => ['view' => ['action' => static fn () => null, 'primary' => true]]],
            'ai.generic_actions' => [
                'work_cards' => ['action' => static fn () => null, 'entity' => ['WorkCard'], 'kind' => 'list'],
                'help' => ['action' => static fn () => null],
            ],
        ]);
    }

    /** @test */
    public function a_kind_of_record_the_application_exposes_no_page_for_is_not_offered(): void
    {
        $this->exposePagesForAircraftAndWorkCard();
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $this->checker($llm)->check(self::QUESTION, 'en');

        $system = $llm->calls[0][0]['content'];
        $this->assertStringContainsString('- aircraft (plane, avion)', $system, 'a record page (entity action) exposes the kind');
        $this->assertStringContainsString('- work card (task card, carte de travail)', $system, 'a generic action placement exposes the kind');
        $this->assertStringNotContainsString('- part', $system, 'a kind no page opens (a link table, a detail row) is not a reading a user means');
    }

    /** @test */
    public function a_page_declared_for_a_kind_without_a_placement_kind_still_exposes_it(): void
    {
        // A page that SHOWS such a record yet is neither its list, create form nor report
        // (the page of the user's current site): `entity` alone, no `kind` — no chip placement.
        config([
            'ai.entity_actions' => ['Aircraft' => ['view' => ['action' => static fn () => null]]],
            'ai.generic_actions' => ['my_part' => ['action' => static fn () => null, 'entity' => 'Part']],
        ]);
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $this->checker($llm)->check(self::QUESTION, 'en');

        $system = $llm->calls[0][0]['content'];
        $this->assertStringContainsString('- part (component, pièce)', $system);
        $this->assertStringNotContainsString('- work card', $system);
    }

    /** @test */
    public function a_candidate_naming_a_kind_without_a_page_is_not_counted_as_a_reading(): void
    {
        $this->exposePagesForAircraftAndWorkCard();
        $reply = ['candidates' => ['work card', 'part'], 'readings' => ['the status of a work card', 'the status of a part']] + self::FORK;

        $verdict = $this->checker(new ScriptedUsageLlm(json: $reply))->check(self::QUESTION, 'en');

        $this->assertFalse($verdict['ask']);
        $this->assertSame('single_reading', $verdict['reason']);
    }

    /** @test */
    public function the_fork_precondition_counts_only_the_kinds_a_page_opens(): void
    {
        config(['ai.entity_actions' => ['Aircraft' => ['view' => ['action' => static fn () => null]]]]);

        $this->assertSame('no_record_kinds', $this->checker()->blockedBy(self::QUESTION, $this->qu()));
    }

    /**
     * @test
     * @dataProvider hostsThatPlaceNoPageOnAnyRecordKind
     */
    public function a_host_that_places_no_page_on_any_kind_keeps_every_askable_kind(array $config): void
    {
        config($config);
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $this->assertNull($this->checker()->blockedBy(self::QUESTION, $this->qu()));
        $this->checker($llm)->check(self::QUESTION, 'en');

        $system = $llm->calls[0][0]['content'];
        $this->assertStringContainsString('- aircraft (plane, avion)', $system);
        $this->assertStringContainsString('- work card (task card, carte de travail)', $system);
        $this->assertStringContainsString('- part (component, pièce)', $system);
    }

    public static function hostsThatPlaceNoPageOnAnyRecordKind(): array
    {
        return [
            'no action configured at all' => [['ai.entity_actions' => [], 'ai.generic_actions' => []]],
            'only pages that belong to no kind of record' => [[
                'ai.entity_actions' => [],
                'ai.generic_actions' => ['help' => ['action' => static fn () => null]],
            ]],
            'actions on labels the host does not hold' => [[
                'ai.entity_actions' => ['Hangar' => ['view' => ['action' => static fn () => null]]],
                'ai.generic_actions' => [],
            ]],
        ];
    }

    /** @test */
    public function a_structural_only_entity_is_never_offered_even_with_a_page(): void
    {
        config(['entities' => config('entities') + [
            'App\\Models\\Fleet\\AircraftPart' => ['graph' => ['label' => 'AircraftPart'], 'metadata' => ['askable' => false]],
        ]]);
        config(['ai.entity_actions' => [
            'Aircraft' => ['view' => ['action' => static fn () => null]],
            'WorkCard' => ['view' => ['action' => static fn () => null]],
            'AircraftPart' => ['view' => ['action' => static fn () => null]],
        ]]);
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $this->checker($llm)->check(self::QUESTION, 'en');

        $this->assertStringNotContainsString('aircraft part', $llm->calls[0][0]['content']);
    }

    /** @test */
    public function a_very_long_synonym_list_is_capped_per_kind(): void
    {
        $synonyms = array_map(static fn (int $n): string => "word{$n}x", range(1, ClarityChecker::MAX_SYNONYMS_PER_KIND + 5));
        config(['entities' => [
            'App\\Models\\Fleet\\Aircraft' => ['graph' => ['label' => 'Aircraft'], 'metadata' => ['aliases' => $synonyms]],
            'App\\Models\\Fleet\\Part' => ['graph' => ['label' => 'Part']],
        ]]);
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $this->checker($llm)->check(self::QUESTION, 'en');

        $system = $llm->calls[0][0]['content'];
        $this->assertStringContainsString('word' . ClarityChecker::MAX_SYNONYMS_PER_KIND . 'x', $system);
        $this->assertStringNotContainsString('word' . (ClarityChecker::MAX_SYNONYMS_PER_KIND + 1) . 'x', $system);
    }

    /** @test */
    public function the_prompt_holds_no_vocabulary_of_any_host_domain(): void
    {
        config(['entities' => [
            'A' => ['graph' => ['label' => 'Alpha']],
            'B' => ['graph' => ['label' => 'Beta']],
        ]]);
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $this->checker($llm)->check('What is the gamma?', 'en');

        // Everything domain-specific in the prompt comes from the host's entity config.
        $system = strtolower($llm->calls[0][0]['content']);
        foreach (['balance', 'invoice', 'condo', 'tenant', 'owner of a unit', 'aircraft'] as $word) {
            $this->assertStringNotContainsString($word, $system);
        }
    }

    /**
     * @test
     * @dataProvider repliesWhereTheQuestionStatesWhoseOrWhich
     */
    public function a_question_that_states_whose_or_which_record_is_answered_not_asked(string $question, array $reply, string $reason): void
    {
        $verdict = $this->checker(new ScriptedUsageLlm(json: $reply + self::FORK))->check($question, 'en');

        $this->assertFalse($verdict['ask']);
        $this->assertSame($reason, $verdict['reason']);
        $this->assertSame([], $verdict['readings']);
        $this->assertSame('', $verdict['question']);
    }

    public static function repliesWhereTheQuestionStatesWhoseOrWhich(): array
    {
        return [
            'a first-person possessive' => [
                'What is my status?',
                ['first_person' => ['my'], 'forms' => ['possessive']],
                'owner_stated',
            ],
            'a first-person subject of the verb' => [
                'How many do we have?',
                ['first_person' => ['we'], 'forms' => ['subject']],
                'owner_stated',
            ],
            'the recipient of the answer AND an owner' => [
                'Show me our status.',
                ['first_person' => ['me', 'our'], 'forms' => ['object', 'possessive']],
                'owner_stated',
            ],
            'a first-person word whose form is missing counts as stated' => [
                'What is my status?',
                ['first_person' => ['my'], 'forms' => []],
                'owner_stated',
            ],
            'a number that identifies the record' => [
                'What is the status of 4471?',
                ['named' => '4471'],
                'record_named',
            ],
            'a name, quoted with other casing and punctuation' => [
                'What is the status of Papa-Kilo Seven?',
                ['named' => 'papa kilo seven'],
                'record_named',
            ],
        ];
    }

    /** @test */
    public function a_first_person_word_that_only_receives_the_answer_states_no_owner(): void
    {
        $reply = ['first_person' => ['me'], 'forms' => ['object']] + self::FORK;

        $verdict = $this->checker(new ScriptedUsageLlm(json: $reply))->check('Show me the status.', 'en');

        $this->assertTrue($verdict['ask']);
    }

    /** @test */
    public function an_owner_or_a_record_the_question_does_not_contain_is_an_invention_and_is_ignored(): void
    {
        // The small model sometimes invents an owner for a subject-less question
        // (measured live). Only words the question itself contains count.
        $reply = ['first_person' => ['I', 'my'], 'forms' => ['subject', 'possessive'], 'named' => 'my account'] + self::FORK;

        $verdict = $this->checker(new ScriptedUsageLlm(json: $reply))->check(self::QUESTION, 'en');

        $this->assertTrue($verdict['ask']);
        $this->assertSame('ambiguous', $verdict['reason']);
    }

    /**
     * @test
     * @dataProvider repliesThatAskForNothing
     */
    public function a_message_that_asks_for_nothing_held_in_records_is_never_asked_about(mixed $asked): void
    {
        // A greeting, thanks, a question about the assistant: whatever else the reply holds.
        $verdict = $this->checker(new ScriptedUsageLlm(json: ['asked' => $asked] + self::FORK))->check('Hello!', 'en');

        $this->assertFalse($verdict['ask']);
        $this->assertSame('nothing_asked', $verdict['reason']);
    }

    public static function repliesThatAskForNothing(): array
    {
        return ['empty' => [''], 'blank' => ['  '], 'not a string' => [null]];
    }

    /** @test */
    public function one_kind_of_record_that_can_carry_what_is_asked_is_a_single_reading(): void
    {
        $reply = ['candidates' => ['work card'], 'readings' => ['the status of a work card']] + self::FORK;

        $verdict = $this->checker(new ScriptedUsageLlm(json: $reply))->check(self::QUESTION, 'en');

        $this->assertFalse($verdict['ask']);
        $this->assertSame('single_reading', $verdict['reason']);
    }

    /** @test */
    public function candidates_are_counted_only_when_they_are_kinds_the_application_holds(): void
    {
        // "task cards" is a spelling of a configured synonym; the two others are not kinds at all.
        $reply = ['candidates' => ['Task Cards', 'weather report', 'mood']] + self::FORK;

        $verdict = $this->checker(new ScriptedUsageLlm(json: $reply))->check(self::QUESTION, 'en');

        $this->assertFalse($verdict['ask']);
        $this->assertSame('single_reading', $verdict['reason']);
    }

    /** @test */
    public function a_fork_without_two_readings_to_offer_is_not_asked(): void
    {
        $reply = ['readings' => ['the status of an aircraft', '  ', 42]] + self::FORK;

        $verdict = $this->checker(new ScriptedUsageLlm(json: $reply))->check(self::QUESTION, 'en');

        $this->assertFalse($verdict['ask']);
        $this->assertSame('no_readings', $verdict['reason']);
    }

    /** @test */
    public function at_most_four_readings_are_kept(): void
    {
        $reply = ['readings' => ['one', 'two', 'three', 'four', 'five']] + self::FORK;

        $verdict = $this->checker(new ScriptedUsageLlm(json: $reply))->check(self::QUESTION, 'en');

        $this->assertSame(['one', 'two', 'three', 'four'], $verdict['readings']);
    }

    /**
     * @test
     * @dataProvider unusableClarifyingQuestions
     */
    public function an_unusable_clarifying_question_is_dropped_and_the_readings_still_ask(mixed $question): void
    {
        // The pipeline then asks its lang-key fallback listing the readings.
        $verdict = $this->checker(new ScriptedUsageLlm(json: ['question' => $question] + self::FORK))->check(self::QUESTION, 'en');

        $this->assertTrue($verdict['ask']);
        $this->assertSame('', $verdict['question']);
        $this->assertCount(3, $verdict['readings']);
    }

    public static function unusableClarifyingQuestions(): array
    {
        return [
            'blank' => ['   '],
            'not a string' => [['nested']],
            'an essay, not one crisp question' => [str_repeat('Which one do you mean? ', 30)],
        ];
    }

    /** @test */
    public function the_clarifying_question_is_single_spaced(): void
    {
        $verdict = $this->checker(new ScriptedUsageLlm(json: ['question' => "  Which\n one  do you mean? "] + self::FORK))
            ->check(self::QUESTION, 'en');

        $this->assertSame('Which one do you mean?', $verdict['question']);
    }

    /**
     * @test
     * @dataProvider malformedReplies
     */
    public function a_malformed_reply_is_no_clarification(array $reply): void
    {
        $verdict = $this->checker(new ScriptedUsageLlm(json: $reply))->check(self::QUESTION, 'en');

        $this->assertFalse($verdict['ask']);
        $this->assertSame([], $verdict['readings']);
        $this->assertSame('', $verdict['question']);
    }

    public static function malformedReplies(): array
    {
        return [
            'empty' => [[]],
            'first_person is not a list' => [['first_person' => 'none'] + self::FORK],
            'first_person is missing' => [array_diff_key(self::FORK, ['first_person' => true])],
            'candidates is not a list' => [['candidates' => 'aircraft, part'] + self::FORK],
            'readings is not a list' => [['readings' => 'x or y'] + self::FORK],
        ];
    }

    /** @test */
    public function a_failing_call_is_no_clarification_and_is_reported_not_thrown(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andThrow(new \RuntimeException('provider timeout'));
        $observer = new RecordingPipelineObserver();

        $verdict = $this->checker($llm)->check(self::QUESTION, 'en', $observer);

        $this->assertSame(['ask' => false, 'reason' => 'failed', 'readings' => [], 'question' => ''], $verdict);
        $this->assertContains(
            ['stage' => 'clarity_check', 'class' => \RuntimeException::class, 'message' => 'provider timeout'],
            $observer->errors()
        );
    }

    /** @test */
    public function the_observer_sees_the_verbatim_prompt_and_the_token_usage(): void
    {
        $llm = new ScriptedUsageLlm(json: self::FORK);
        $observer = new RecordingPipelineObserver();

        $this->checker($llm)->check(self::QUESTION, 'en', $observer);

        $this->assertSame([$llm->calls[0]], $observer->prompts('clarity_check'));
        $this->assertContains(['stage' => 'clarity_check', 'usage' => ScriptedUsageLlm::USAGE], $observer->usages());
    }

    /** @test */
    public function a_blank_question_makes_no_call(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldNotReceive('chatJson');

        $this->assertFalse($this->checker($llm)->check('   ')['ask']);
    }

    // ------------------------------------------------------------------
    // Named quantities (eval p17-A ambiguous t0)
    //
    // The readings came from the kinds of records only ("the balance of an
    // account, an invoice, a bill or a transaction") while the host's own
    // CALCULATION for that very question had matched and was not offered. The
    // calculations the question matches are the application's named, askable
    // quantities: they are handed to the call with the question, the model
    // rewords them, and they are the first readings — the kinds of records
    // fill the rest, never a kind a quantity is already about.
    // ------------------------------------------------------------------

    /** The host's calculations — a deliberately non-host catalogue. */
    private const CALCULATIONS = [
        'aircraft_flight_hours' => [
            'label' => 'Flight hours of one aircraft',
            'description' => 'What a given aircraft has flown.',
            'cypher' => 'MATCH (a:Aircraft) RETURN a.hours',
        ],
        'fleet_open_work' => [
            'label' => 'Open work across the fleet',
            'description' => 'Every work card not yet signed off.',
            'cypher' => 'MATCH (w:WorkCard) RETURN count(w)',
        ],
        'parts_on_order' => [
            'label' => 'Parts on order',
            'description' => 'What was ordered and not received.',
            'cypher' => 'MATCH (p:Part) RETURN count(p)',
        ],
    ];

    /** Matches ONE calculation: parts_on_order (1.00; the two others 0.00). */
    private const ONE_QUANTITY_QUESTION = 'What is still to come?';

    /** Unit vectors by text: similarity = dot product. */
    private const VECTORS = [
        'Flight hours of one aircraft — What a given aircraft has flown.' => [1.0, 0.0, 0.0],
        'Open work across the fleet — Every work card not yet signed off.' => [0.6, 0.8, 0.0],
        'Parts on order — What was ordered and not received.' => [0.0, 0.0, 1.0],
        // fleet_open_work 0.96, aircraft_flight_hours 0.80, parts_on_order 0.00
        self::QUESTION => [0.8, 0.6, 0.0],
        'Quel est le statut ?' => [0.8, 0.6, 0.0],
        self::ONE_QUANTITY_QUESTION => [0.0, 0.0, 1.0],
        // Nothing clears the matcher's minimum (0.45): 0.20 / 0.12 / 0.40
        'What is the gamma?' => [0.2, 0.0, 0.4, 0.894],
    ];

    /** @param array<string, float[]> $vectors */
    private function embedding(array $vectors = self::VECTORS, bool $failing = false): EmbeddingProviderInterface
    {
        return new class($vectors, $failing) implements EmbeddingProviderInterface {
            public function __construct(private array $vectors, private bool $failing)
            {
            }

            public function embed(string $text): array
            {
                if ($this->failing) {
                    throw new \RuntimeException('embedding provider down');
                }

                return $this->vectors[$text] ?? [0.0, 1.0, 0.0];
            }

            public function embedBatch(array $texts): array
            {
                return array_map(fn (string $text): array => $this->embed($text), $texts);
            }

            public function getDimensions(): int
            {
                return 3;
            }

            public function getModel(): string
            {
                return 'fake';
            }

            public function getMaxLength(): int
            {
                return 8000;
            }
        };
    }

    private function quantityChecker(LlmProviderInterface $llm, ?EmbeddingProviderInterface $embedding = null): ClarityChecker
    {
        config(['ai.calculations' => config('ai.calculations') ?: self::CALCULATIONS]);

        return new ClarityChecker($llm, null, $embedding ?? $this->embedding());
    }

    /** @test */
    public function the_calculations_the_question_matches_are_handed_over_with_it_best_first(): void
    {
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $this->quantityChecker($llm)->check(self::QUESTION, 'en');

        [$system, $user] = array_column($llm->calls[0], 'content');
        // EVERY calculation that clears the matcher's minimum, not only the best one: the
        // runner-up (0.80 against 0.96) is exactly the other reading of a fork.
        $this->assertSame(
            'Question (written in "en"): ' . self::QUESTION . "\n"
            . "Figures the application can compute:\n"
            . "Q1: Open work across the fleet — Every work card not yet signed off.\n"
            . "Q2: Flight hours of one aircraft — What a given aircraft has flown.\n"
            . 'Write "readings", "reworded" and "question" in "en".',
            $user
        );
        // The system prompt holds neither the question nor its quantities, a config key or a query.
        $this->assertStringNotContainsString('Open work across the fleet', $system);
        $this->assertStringNotContainsString('fleet_open_work', $system . $user);
        $this->assertStringNotContainsString('MATCH', $system . $user);
        // The model REWORDS the quantities (it does not choose among them) …
        $this->assertMatchesRegularExpression('/- "reworded":[^\n]*FIGURES[^\n]*For each of them[^\n]*said simply/', $system);
        $this->assertStringContainsString('"readings":["…","…"],"reworded":["…"],"question":"…"}', $system);
        // … they come first in the one question, and leave the kinds the remaining readings (4 - 2).
        $this->assertMatchesRegularExpression('/- "question":[^\n]*every entry of "reworded" and then every entry of "readings"/', $system);
        $this->assertStringContainsString('- "readings": for each of the first 2 candidates,', $system);
    }

    /** @test */
    public function a_kind_of_record_a_quantity_is_already_about_is_not_listed(): void
    {
        // Live: with "Outstanding balance of one unit" in sight the model's only candidate was
        // "unit" — the quantity's own reading a second time. The list keeps the OTHER kinds.
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $this->quantityChecker($llm)->check(self::QUESTION, 'en');

        $system = $llm->calls[0][0]['content'];
        // "work card" (Q1's description) and "aircraft" (Q2's label) are covered; "part" is not.
        $this->assertStringNotContainsString("\n- work card", $system);
        $this->assertStringNotContainsString("\n- aircraft", $system);
        $this->assertStringContainsString("\n- part (component, pièce)\n", $system);
    }

    /** @test */
    public function a_kind_is_covered_by_any_of_its_spellings_and_synonyms(): void
    {
        config(['ai.calculations' => [
            'grounded' => ['label' => 'Grounded planes/parts (today)', 'description' => 'Per task-card.'],
        ]]);
        $vectors = ['Grounded planes/parts (today) — Per task-card.' => [1.0, 0.0, 0.0], self::QUESTION => [1.0, 0.0, 0.0]];
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $this->quantityChecker($llm, $this->embedding($vectors))->check(self::QUESTION, 'en');

        // "planes" = a synonym of aircraft in the plural; "parts" glued to it by a slash;
        // "task-card" = a synonym of work card, hyphenated. Nothing is left to list.
        $this->assertStringContainsString("(with other words people use for them):\n\n\nFill the reply", $llm->calls[0][0]['content']);
    }

    /** @test */
    public function the_language_line_names_the_reworded_quantities_too(): void
    {
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $this->quantityChecker($llm)->check('Quel est le statut ?', 'fr');

        $user = $llm->calls[0][1]['content'];
        $this->assertStringStartsWith('Question (written in "fr"): Quel est le statut ?' . "\n", $user);
        $this->assertStringEndsWith('Write "readings", "reworded" and "question" in "fr".', $user);
    }

    /** @test */
    public function a_question_that_matches_no_calculation_gets_the_kinds_only_prompt_unchanged(): void
    {
        $plain = new ScriptedUsageLlm(json: self::FORK);
        $withMatcher = new ScriptedUsageLlm(json: self::FORK);

        $this->checker($plain)->check('What is the gamma?', 'en');
        $this->quantityChecker($withMatcher)->check('What is the gamma?', 'en');

        $this->assertSame($plain->calls[0], $withMatcher->calls[0]);
        $this->assertStringNotContainsString('reworded', $plain->calls[0][0]['content']);
        $this->assertStringNotContainsString('FIGURES', $plain->calls[0][0]['content']);
    }

    /**
     * @test
     * @dataProvider situationsWithoutUsableCalculations
     */
    public function without_usable_calculations_the_call_is_the_kinds_only_one_and_still_asks(array $config, bool $failingEmbedding): void
    {
        config($config);
        $plain = new ScriptedUsageLlm(json: self::FORK);
        $llm = new ScriptedUsageLlm(json: self::FORK);
        $this->checker($plain)->check(self::QUESTION, 'en');

        $verdict = $this->quantityChecker($llm, $this->embedding(failing: $failingEmbedding))->check(self::QUESTION, 'en');

        $this->assertTrue($verdict['ask'], 'named quantities are an enhancement: without them the kinds still ask');
        $this->assertSame($plain->calls[0], $llm->calls[0]);
    }

    public static function situationsWithoutUsableCalculations(): array
    {
        return [
            'the embedding provider fails' => [[], true],
            'calculation matching is switched off' => [['ai.calculation_matching.enabled' => false], false],
            'calculations are not a list of definitions' => [['ai.calculations' => ['broken' => 'not a definition']], false],
        ];
    }

    /** @test */
    public function the_matchers_own_minimum_similarity_decides_what_is_handed_over(): void
    {
        // aircraft_flight_hours scores 0.80: handed over at the default minimum, not at 0.9.
        config(['ai.calculation_matching.min_similarity' => 0.9]);
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $this->quantityChecker($llm)->check(self::QUESTION, 'en');

        $user = $llm->calls[0][1]['content'];
        $this->assertStringContainsString('Q1: Open work across the fleet', $user);
        $this->assertStringNotContainsString('Flight hours of one aircraft', $user);
    }

    /** @test */
    public function no_more_quantities_are_handed_over_than_readings_can_be_offered(): void
    {
        $max = QueryUnderstandingResult::MAX_CANDIDATE_READINGS;
        $calculations = [];
        $vectors = [self::QUESTION => [1.0, 0.0, 0.0]];
        foreach (range(1, $max + 3) as $n) {
            $calculations["calc_{$n}"] = ['label' => "Quantity number {$n}"];
            $vectors["Quantity number {$n}"] = [1.0, 0.0, 0.0];
        }
        config(['ai.calculations' => $calculations]);
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $this->quantityChecker($llm, $this->embedding($vectors))->check(self::QUESTION, 'en');

        [$system, $user] = array_column($llm->calls[0], 'content');
        $this->assertStringContainsString("Q{$max}: ", $user);
        $this->assertStringNotContainsString('Q' . ($max + 1) . ': ', $user);
        // They fill the question: no reading is asked of the kinds.
        $this->assertStringContainsString('- "readings": [].', $system);
    }

    /** @test */
    public function a_calculation_without_a_label_is_named_by_its_key_in_plain_words(): void
    {
        config(['ai.calculations' => ['hours_since_overhaul' => ['description' => 'Time flown since the last overhaul.']]]);
        $vectors = [
            'hours_since_overhaul — Time flown since the last overhaul.' => [1.0, 0.0, 0.0],
            self::QUESTION => [1.0, 0.0, 0.0],
        ];
        $llm = new ScriptedUsageLlm(json: self::FORK);

        $this->quantityChecker($llm, $this->embedding($vectors))->check(self::QUESTION, 'en');

        $user = $llm->calls[0][1]['content'];
        $this->assertStringContainsString('Q1: hours since overhaul — Time flown since the last overhaul.', $user);
        $this->assertStringNotContainsString('hours_since_overhaul', $user, 'a raw key would end up in the user-facing question');
    }

    /** @test */
    public function the_reworded_quantities_are_the_first_readings_and_the_kinds_fill_the_rest(): void
    {
        $reply = [
            'candidates' => ['part'],
            'readings' => ['the status of a part'],
            'reworded' => ['open work on the whole fleet', "  flight hours of\n an aircraft "],
            'question' => 'Do you mean open work on the whole fleet, flight hours of an aircraft or the status of a part?',
        ] + self::FORK;

        $verdict = $this->quantityChecker(new ScriptedUsageLlm(json: $reply))->check(self::QUESTION, 'en');

        $this->assertSame([
            'ask' => true,
            'reason' => 'ambiguous',
            'readings' => ['open work on the whole fleet', 'flight hours of an aircraft', 'the status of a part'],
            'question' => 'Do you mean open work on the whole fleet, flight hours of an aircraft or the status of a part?',
        ], $verdict);
    }

    /** @test */
    public function two_named_quantities_are_a_fork_without_any_kind_of_record(): void
    {
        $reply = ['candidates' => [], 'readings' => [], 'reworded' => ['open work on the fleet', 'flight hours of an aircraft']] + self::FORK;

        $verdict = $this->quantityChecker(new ScriptedUsageLlm(json: $reply))->check(self::QUESTION, 'en');

        $this->assertTrue($verdict['ask']);
        $this->assertSame(['open work on the fleet', 'flight hours of an aircraft'], $verdict['readings']);
    }

    /**
     * @test
     * @dataProvider repliesToAQuestionWithOneNamedQuantity
     */
    public function one_named_quantity_asks_only_next_to_another_reading(array $reply, bool $ask, array $readings = []): void
    {
        // parts_on_order is the one quantity; it is about the kind "part".
        $reply += ['reworded' => ['parts still on order']] + self::FORK;

        $verdict = $this->quantityChecker(new ScriptedUsageLlm(json: $reply))->check(self::ONE_QUANTITY_QUESTION, 'en');

        $this->assertSame($ask, $verdict['ask']);
        $this->assertSame($ask ? 'ambiguous' : 'single_reading', $verdict['reason']);
        $this->assertSame($readings, $verdict['readings']);
    }

    public static function repliesToAQuestionWithOneNamedQuantity(): array
    {
        return [
            'next to a kind of record' => [
                ['candidates' => ['aircraft'], 'readings' => ['the status of an aircraft']],
                true,
                ['parts still on order', 'the status of an aircraft'],
            ],
            // ONE named quantity and nothing else is one reading: there is nothing to choose between.
            'alone' => [['candidates' => [], 'readings' => []], false],
            // The kind the quantity is about is that same reading said twice, not a fork.
            'next to the kind it is already about' => [
                ['candidates' => ['part'], 'readings' => ['the status of a part']],
                false,
            ],
            'next to that kind under a synonym' => [
                ['candidates' => ['Components'], 'readings' => ['the status of a component']],
                false,
            ],
            'next to something that is no kind of record' => [
                ['candidates' => ['weather report'], 'readings' => ['the status of the weather']],
                false,
            ],
            // A quantity the model did not word is not a reading.
            'not reworded: a bare tag' => [
                ['reworded' => ['Q1'], 'candidates' => ['aircraft'], 'readings' => ['the status of an aircraft']],
                false,
            ],
            'not reworded: blank' => [
                ['reworded' => ['  '], 'candidates' => ['aircraft'], 'readings' => ['the status of an aircraft']],
                false,
            ],
            'not reworded: the key is missing altogether' => [
                ['reworded' => null, 'candidates' => ['aircraft'], 'readings' => ['the status of an aircraft']],
                false,
            ],
            'not reworded, but two kinds still make the fork' => [
                ['reworded' => [], 'candidates' => ['aircraft', 'work card'], 'readings' => ['the status of an aircraft', 'the status of a work card']],
                true,
                ['the status of an aircraft', 'the status of a work card'],
            ],
        ];
    }

    /** @test */
    public function the_reading_of_a_kind_a_quantity_is_already_about_is_left_out_and_the_models_sentence_with_it(): void
    {
        // Live ("Show me the balance."): the model listed the covered kind all the same and its
        // sentence offered "outstanding balance of one unit, … balance of a unit, …".
        $reply = [
            'candidates' => ['aircraft', 'part', 'work card'],
            'readings' => ['the status of an aircraft', 'the status of a part', 'the status of a work card'],
            'reworded' => ['parts still on order'],
            'question' => 'Do you mean parts still on order, the status of an aircraft, of a part or of a work card?',
        ] + self::FORK;

        $verdict = $this->quantityChecker(new ScriptedUsageLlm(json: $reply))->check(self::ONE_QUANTITY_QUESTION, 'en');

        $this->assertTrue($verdict['ask']);
        $this->assertSame(['parts still on order', 'the status of an aircraft', 'the status of a work card'], $verdict['readings']);
        // The sentence names a reading the turn does not offer: the readings ask (lang-key fallback).
        $this->assertSame('', $verdict['question']);
    }

    /** @test */
    public function a_left_out_reading_that_only_repeats_a_quantity_keeps_the_models_sentence(): void
    {
        // Live ("Quel est le solde ?"): the covered kind's reading was the quantity's own wording.
        $reply = [
            'candidates' => ['aircraft', 'part'],
            'readings' => ['the status of an aircraft', 'parts still on order'],
            'reworded' => ['parts still on order'],
            'question' => 'Do you mean parts still on order or the status of an aircraft?',
        ] + self::FORK;

        $verdict = $this->quantityChecker(new ScriptedUsageLlm(json: $reply))->check(self::ONE_QUANTITY_QUESTION, 'en');

        $this->assertSame(['parts still on order', 'the status of an aircraft'], $verdict['readings']);
        $this->assertSame('Do you mean parts still on order or the status of an aircraft?', $verdict['question']);
    }

    /** @test */
    public function readings_beyond_the_four_offered_drop_the_models_sentence_too(): void
    {
        $config = ['App\\Models\\Fleet\\Hangar' => ['graph' => ['label' => 'Hangar']], 'App\\Models\\Fleet\\Crew' => ['graph' => ['label' => 'Crew']]];
        config(['entities' => config('entities') + $config]);
        $reply = [
            'candidates' => ['aircraft', 'work card', 'hangar', 'crew'],
            'readings' => ['status of an aircraft', 'status of a work card', 'status of a hangar', 'status of a crew'],
            'reworded' => ['parts still on order'],
            'question' => 'Parts still on order, or the status of an aircraft, a work card, a hangar or a crew?',
        ] + self::FORK;

        $verdict = $this->quantityChecker(new ScriptedUsageLlm(json: $reply))->check(self::ONE_QUANTITY_QUESTION, 'en');

        $this->assertSame(
            ['parts still on order', 'status of an aircraft', 'status of a work card', 'status of a hangar'],
            $verdict['readings']
        );
        $this->assertSame('', $verdict['question']);
    }

    /**
     * @test
     * @dataProvider repliesWhereTheQuestionStatesWhoseOrWhich
     */
    public function a_matched_calculation_never_turns_a_clear_question_into_a_clarification(string $question, array $reply, string $reason): void
    {
        // The structural verdict is unchanged: a stated owner or a named record answers,
        // whatever the calculations suggest (each of these questions matches one at 1.00).
        $reply += ['reworded' => ['flight hours of an aircraft', 'open work on the fleet']] + self::FORK;
        $vectors = [$question => [1.0, 0.0, 0.0]] + self::VECTORS;

        $verdict = $this->quantityChecker(new ScriptedUsageLlm(json: $reply), $this->embedding($vectors))->check($question, 'en');

        $this->assertFalse($verdict['ask']);
        $this->assertSame($reason, $verdict['reason']);
    }

    /** The kinds-only reply to ONE_QUANTITY_QUESTION: three kinds, the covered one among them. */
    private const KINDS_ONLY_FORK = [
        'candidates' => ['aircraft', 'part', 'work card'],
        'readings' => ['the status of an aircraft', 'the status of a part', 'the status of a work card'],
        'question' => 'Do you mean the status of an aircraft, of a part or of a work card?',
    ];

    /** An LLM that answers its successive calls with the given replies (an exception is thrown). */
    private function llmReplying(array|\Throwable ...$replies): LlmProviderInterface
    {
        $this->sequenceCalls = [];
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->times(count($replies))->andReturnUsing(function (array $messages) use (&$replies) {
            $this->sequenceCalls[] = $messages;
            $reply = array_shift($replies);
            if ($reply instanceof \Throwable) {
                throw $reply;
            }

            return $reply;
        });

        return $llm;
    }

    /** @var array<int, array> */
    private array $sequenceCalls = [];

    /** @test */
    public function when_the_quantity_leaves_a_single_reading_the_kinds_are_asked_again_without_it(): void
    {
        // Live (2026-10-06): with the one-unit quantity in sight the model's only candidate
        // was "unit" on 2 runs of 3 of one wording — the fork it lists without quantities
        // (account, invoice, bill…) was simply not looked for. A matched calculation must add
        // a reading, never cost the question.
        $primed = ['candidates' => ['part'], 'readings' => ['the status of a part'], 'reworded' => ['parts still on order']] + self::FORK;
        $llm = $this->llmReplying($primed, self::KINDS_ONLY_FORK + self::FORK);
        $observer = new RecordingPipelineObserver();

        $verdict = $this->quantityChecker($llm)->check(self::ONE_QUANTITY_QUESTION, 'en', $observer);

        $this->assertSame([
            'ask' => true,
            'reason' => 'ambiguous',
            // The quantity first, as the first call reworded it; then the kinds it is not about.
            'readings' => ['parts still on order', 'the status of an aircraft', 'the status of a work card'],
            // The second sentence was written without the quantity: the readings ask.
            'question' => '',
        ], $verdict);

        // The second call is the kinds-only one, word for word — as if no calculation had matched.
        $plain = new ScriptedUsageLlm(json: self::FORK);
        $this->checker($plain)->check(self::ONE_QUANTITY_QUESTION, 'en');
        $this->assertCount(2, $this->sequenceCalls);
        $this->assertStringContainsString('Q1: Parts on order', $this->sequenceCalls[0][1]['content']);
        $this->assertSame($plain->calls[0], $this->sequenceCalls[1]);
        $this->assertSame($this->sequenceCalls, $observer->prompts('clarity_check'), 'both prompts are observed');
    }

    /** @test */
    public function one_reading_with_and_without_the_quantity_is_not_asked(): void
    {
        $primed = ['candidates' => ['part'], 'readings' => ['the status of a part'], 'reworded' => ['parts still on order']] + self::FORK;
        $kindsOnly = ['candidates' => ['part'], 'readings' => ['the status of a part']] + self::FORK;

        $verdict = $this->quantityChecker($this->llmReplying($primed, $kindsOnly))->check(self::ONE_QUANTITY_QUESTION, 'en');

        $this->assertFalse($verdict['ask']);
        $this->assertSame('single_reading', $verdict['reason']);
    }

    /** @test */
    public function what_the_second_call_finds_stated_in_the_question_still_answers_it(): void
    {
        $primed = ['candidates' => ['part'], 'readings' => ['the status of a part'], 'reworded' => ['parts still on order']] + self::FORK;
        $kindsOnly = ['named' => 'still to come'] + self::KINDS_ONLY_FORK + self::FORK;

        $verdict = $this->quantityChecker($this->llmReplying($primed, $kindsOnly))->check(self::ONE_QUANTITY_QUESTION, 'en');

        $this->assertFalse($verdict['ask']);
        $this->assertSame('record_named', $verdict['reason']);
    }

    /**
     * @test
     * @dataProvider firstRepliesThatSettleTheQuestion
     */
    public function a_settled_verdict_makes_no_second_call(array $reply): void
    {
        // times(1): a second chatJson call would fail the expectation.
        $verdict = $this->quantityChecker($this->llmReplying($reply + self::FORK))->check(self::ONE_QUANTITY_QUESTION, 'en');

        $this->assertCount(1, $this->sequenceCalls);
        $this->assertNotContains($verdict['reason'], ['single_reading', 'no_readings']);
    }

    public static function firstRepliesThatSettleTheQuestion(): array
    {
        return [
            'a fork' => [['candidates' => ['aircraft'], 'readings' => ['the status of an aircraft'], 'reworded' => ['parts still on order']]],
            'nothing asked' => [['asked' => '']],
            'a named record' => [['named' => 'still to come']],
            'a malformed reply' => [['candidates' => 'part']],
        ];
    }

    /** @test */
    public function a_failing_second_call_is_no_clarification_and_is_reported_not_thrown(): void
    {
        $primed = ['candidates' => ['part'], 'readings' => ['the status of a part'], 'reworded' => ['parts still on order']] + self::FORK;
        $observer = new RecordingPipelineObserver();

        $verdict = $this->quantityChecker($this->llmReplying($primed, new \RuntimeException('provider timeout')))
            ->check(self::ONE_QUANTITY_QUESTION, 'en', $observer);

        $this->assertSame(['ask' => false, 'reason' => 'failed', 'readings' => [], 'question' => ''], $verdict);
        $this->assertContains(
            ['stage' => 'clarity_check', 'class' => \RuntimeException::class, 'message' => 'provider timeout'],
            $observer->errors()
        );
    }

    /** @test */
    public function the_preconditions_do_not_consult_the_calculations(): void
    {
        // A question that names a kind of record is stopped before any call — no
        // embedding, no LLM — exactly as without calculations.
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldNotReceive('chatJson');
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldNotReceive('embed');
        $embedding->shouldNotReceive('embedBatch');

        $this->assertSame(
            'entity_named',
            $this->quantityChecker($llm, $embedding)->blockedBy('What is the status of the aircraft?', $this->qu())
        );
    }
}
