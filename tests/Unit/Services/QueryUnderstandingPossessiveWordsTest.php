<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * "Is the user asking about THEIR OWN data?" — the model quotes, code verifies.
 *
 * Measured live (2026-10-06, Query-Understanding model): asked for a bare
 * "is_possessive" boolean, the model answered true for questions that contain no
 * possessive at all ("Show me the balance.", "Show me the overdue invoices." — 2 of
 * 2 runs each) and rewrote them with an invented owner ("…associated with my
 * account"). The turn then followed the own-data path and answered "no overdue
 * invoices linked to you" to a manager asking for the portfolio's.
 *
 * The prompt now asks for the first-person WORDS of the question, copied exactly,
 * the grammatical FORM of each, and the SCOPE (the asker alone / the asker's
 * group). isPossessive is DERIVED: true only when a quoted word literally occurs in
 * the question and its form is "subject" or "possessive". No word list in code.
 */
class QueryUnderstandingPossessiveWordsTest extends TestCase
{
    /** @var list<array<int, array<string, string>>> */
    private array $prompts = [];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $reply the ownership keys the model "returned"
     */
    private function analyze(string $question, array $reply): QueryUnderstandingResult
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages) use ($reply) {
            $this->prompts[] = $messages;

            return $reply + [
                'entity_types' => [],
                'person_names' => [],
                'property_concepts' => [],
                'search_query' => 'q',
                'intent' => 'lookup',
                'question_type' => 'data_query',
                'clarified_question' => 'the rewrite',
                'search_tags' => [],
            ];
        });
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('debug')->byDefault();
        $logger->shouldReceive('error')->byDefault();

        return (new QueryUnderstandingService($llm, $logger, []))->analyze($question, []);
    }

    /**
     * @param list<mixed> $words
     * @param list<mixed> $forms
     * @return array<string, mixed>
     */
    private function owned(array $words, array $forms, mixed $scope = 'personal'): array
    {
        return ['possessive_words' => $words, 'possessive_forms' => $forms, 'possessive_scope' => $scope];
    }

    /** @test */
    public function the_prompt_asks_for_the_words_and_no_longer_for_a_bare_judgment(): void
    {
        $this->analyze('What is my balance?', []);
        $system = $this->prompts[0][0]['content'];

        $this->assertStringContainsString('- "possessive_words":', $system);
        $this->assertStringContainsString('copied EXACTLY as written', $system);
        $this->assertStringContainsString('- "possessive_forms":', $system);
        $this->assertStringContainsString('"subject"', $system);
        $this->assertStringContainsString('"object"', $system);
        $this->assertStringContainsString('- "possessive_scope":', $system);
        $this->assertStringContainsString('"personal"', $system);
        $this->assertStringContainsString('"collective"', $system);
        $this->assertStringNotContainsString('is_possessive', $system);

        // The new docs stand where the boolean's doc stood: after subject_label, before intent.
        $this->assertLessThan(strpos($system, '- "possessive_words":'), strpos($system, '- "subject_label":'));
        $this->assertLessThan(strpos($system, '- "intent":'), strpos($system, '- "possessive_scope":'));
    }

    /** @test */
    public function the_rewrite_doc_forbids_an_owner_the_question_does_not_state(): void
    {
        $this->analyze('What is my balance?', []);
        $system = $this->prompts[0][0]['content'];

        $clarified = substr($system, (int) strpos($system, '- "clarified_question":'), (int) strpos($system, '- "search_tags":') - (int) strpos($system, '- "clarified_question":'));
        $this->assertStringContainsString('Never add an owner the question does not state', $clarified);
        $this->assertStringContainsString('"possessive_scope" is not null', $clarified);
    }

    /** @test */
    public function a_quoted_word_the_question_contains_makes_it_possessive(): void
    {
        $result = $this->analyze("What's my balance?", $this->owned(['my'], ['possessive']));

        $this->assertTrue($result->isPossessive);
        $this->assertSame(['my'], $result->possessiveWords);
        $this->assertSame('personal', $result->possessiveScope);
    }

    /** @test The measured defect: "Show me the balance." came back with an invented owner. */
    public function an_invented_word_is_ignored(): void
    {
        $result = $this->analyze('Show me the balance.', $this->owned(['my'], ['possessive']));

        $this->assertFalse($result->isPossessive);
        $this->assertSame([], $result->possessiveWords);
        $this->assertNull($result->possessiveScope);
    }

    /** @test */
    public function the_models_own_boolean_is_not_trusted(): void
    {
        $this->assertFalse($this->analyze('Show me the overdue invoices.', ['is_possessive' => true])->isPossessive);
        $this->assertFalse($this->analyze('Show me the overdue invoices.', ['is_possessive' => true, 'possessive_scope' => 'personal'])->isPossessive);
    }

    /** @test The one who receives the answer is not an owner: "show me", "give us". */
    public function a_word_tagged_object_states_no_owner(): void
    {
        $result = $this->analyze('Show me the balance.', $this->owned(['me'], ['object']));

        $this->assertFalse($result->isPossessive);
        $this->assertNull($result->possessiveScope);

        // …but a real owner next to it still counts.
        $mixed = $this->analyze('Can you give me the link to my profile?', $this->owned(['me', 'my'], ['object', 'possessive']));
        $this->assertTrue($mixed->isPossessive);
        $this->assertSame(['my'], $mixed->possessiveWords);
    }

    /** @test A word counts only with a form the model affirmed: "subject" or "possessive". */
    public function a_missing_or_unknown_form_does_not_count(): void
    {
        $this->assertFalse($this->analyze("What's my balance?", $this->owned(['my'], []))->isPossessive);
        $this->assertFalse($this->analyze("What's my balance?", $this->owned(['my'], ['collective']))->isPossessive);
        $this->assertFalse($this->analyze("What's my balance?", $this->owned(['my'], [['possessive']]))->isPossessive);
        $this->assertFalse($this->analyze("What's my balance?", ['possessive_words' => ['my']])->isPossessive);

        $this->assertTrue($this->analyze("What's my balance?", $this->owned(['my'], [' Possessive ']))->isPossessive);
        $this->assertTrue($this->analyze('Do I have any reservations?', $this->owned(['I'], ['SUBJECT']))->isPossessive);
    }

    /** @test */
    public function forms_are_read_by_position(): void
    {
        // Measured reply: the invented "my" trails the real subject pronoun.
        $result = $this->analyze('Do I have any reservations?', $this->owned(['Do', 'I', 'my'], ['subject', 'subject', 'possessive']));

        $this->assertTrue($result->isPossessive);
        $this->assertContains('I', $result->possessiveWords);
        $this->assertNotContains('my', $result->possessiveWords);
    }

    /** @test */
    public function matching_ignores_case_and_accents(): void
    {
        $this->assertTrue($this->analyze('Mes factures en retard', $this->owned(['mes'], ['possessive']))->isPossessive);
        $this->assertTrue($this->analyze('quel est MON solde ?', $this->owned(['mon'], ['possessive']))->isPossessive);
        // The accent is the question's; the quote dropped it (and the other way round).
        $this->assertTrue($this->analyze('Lequel est le nôtre ?', $this->owned(['notre'], ['possessive'], 'collective'))->isPossessive);
        $this->assertTrue($this->analyze('Ou est notre assemblee ?', $this->owned(['nôtre'], ['possessive'], 'collective'))->isPossessive);
        // Decomposed (NFD) question, precomposed (NFC) quote.
        $this->assertTrue($this->analyze("Lequel est le no\u{0302}tre ?", $this->owned(['nôtre'], ['possessive'], 'collective'))->isPossessive);
        $this->assertTrue($this->analyze('¿Cuál es mi saldo?', $this->owned(['mi'], ['possessive']))->isPossessive);
    }

    /** @test French elision and hyphenated inversion: the apostrophe and the hyphen separate words. */
    public function elided_and_hyphenated_words_are_matched_as_words(): void
    {
        $question = "Est-ce que j'ai des réservations ?";
        foreach (["j'ai", "j'", 'j', "J'AI", 'j’ai'] as $quote) {
            $this->assertTrue($this->analyze($question, $this->owned([$quote], ['subject']))->isPossessive, $quote);
        }
        // Typographic apostrophe in the question, straight one in the quote.
        $this->assertTrue($this->analyze('Est-ce que j’ai des réservations ?', $this->owned(["j'ai"], ['subject']))->isPossessive);

        $this->assertTrue($this->analyze("Quel est l'état de mon compte ?", $this->owned(['mon'], ['possessive']))->isPossessive);
        $this->assertTrue($this->analyze('Combien devons-nous à nos fournisseurs ?', $this->owned(['nous'], ['subject'], 'collective'))->isPossessive);
        $this->assertTrue($this->analyze("Qu'est-ce qu'on doit à nos fournisseurs ?", $this->owned(['nos'], ['possessive'], 'collective'))->isPossessive);
        $this->assertTrue($this->analyze("What's my balance?", $this->owned(['my'], ['possessive']))->isPossessive);
    }

    /** @test */
    public function a_quote_must_be_whole_words_of_the_question(): void
    {
        // "I" is not in "invoices", "me" not in "members", "my" not in "mystery", "nos" not in "nostalgie".
        $this->assertFalse($this->analyze('List the invoices', $this->owned(['I'], ['subject']))->isPossessive);
        $this->assertFalse($this->analyze('List the members', $this->owned(['me'], ['possessive']))->isPossessive);
        $this->assertFalse($this->analyze('Explain the mystery fee', $this->owned(['my'], ['possessive']))->isPossessive);
        $this->assertFalse($this->analyze('La nostalgie du syndicat', $this->owned(['nos'], ['possessive'], 'collective'))->isPossessive);
        // The quote "j'" is the word "j", not the letter inside another word.
        $this->assertFalse($this->analyze('Liste des projets', $this->owned(["j'"], ['subject']))->isPossessive);
    }

    /** @test A script written without spaces has no word to match: the quote is a run of the question's characters. */
    public function a_quote_in_an_unspaced_script_is_matched_as_a_run_of_characters(): void
    {
        $this->assertTrue($this->analyze('我的余额是多少？', $this->owned(['我的'], ['possessive']))->isPossessive);
        $this->assertFalse($this->analyze('显示余额', $this->owned(['我的'], ['possessive']))->isPossessive);
        // Never for a script that does separate its words.
        $this->assertFalse($this->analyze('Explain the mystery fee', $this->owned(['my'], ['possessive']))->isPossessive);
    }

    /** @test */
    public function a_quoted_phrase_must_be_consecutive_words_of_the_question(): void
    {
        $this->assertTrue($this->analyze('Do I have any reservations?', $this->owned(['Do I have'], ['subject']))->isPossessive);
        $this->assertTrue($this->analyze("What's my balance?", $this->owned(['my balance'], ['possessive']))->isPossessive);

        $this->assertFalse($this->analyze('Show me the balance.', $this->owned(['my balance'], ['possessive']))->isPossessive);
        $this->assertFalse($this->analyze('Do I really have reservations?', $this->owned(['I have'], ['subject']))->isPossessive);
    }

    /** @test */
    public function empty_or_malformed_extractions_are_not_possessive(): void
    {
        foreach ([
            [],
            $this->owned([], [], null),
            $this->owned(['', '   ', '?!'], ['possessive', 'possessive', 'possessive']),
            $this->owned([42, null, ['my'], true], ['possessive', 'possessive', 'possessive', 'possessive']),
            ['possessive_words' => 'my', 'possessive_forms' => 'possessive', 'possessive_scope' => 'personal'],
            ['possessive_words' => ['word' => 'my'], 'possessive_forms' => ['possessive']],
            ['possessive_words' => null, 'possessive_forms' => null, 'possessive_scope' => null],
        ] as $i => $reply) {
            $result = $this->analyze("What's my balance?", $reply);

            $this->assertFalse($result->isPossessive, "case {$i}");
            $this->assertSame([], $result->possessiveWords, "case {$i}");
            $this->assertNull($result->possessiveScope, "case {$i}");
        }
    }

    /** @test */
    public function the_scope_is_personal_collective_or_unknown_and_only_for_a_verified_owner(): void
    {
        $this->assertSame('personal', $this->analyze("What's my balance?", $this->owned(['my'], ['possessive'], ' Personal '))->possessiveScope);
        $this->assertSame('collective', $this->analyze('When is our next meeting?', $this->owned(['our'], ['possessive'], 'collective'))->possessiveScope);

        // An unusable scope leaves the owner verified and the scope unknown.
        foreach ([null, 'team', 7, ['collective']] as $scope) {
            $result = $this->analyze('When is our next meeting?', $this->owned(['our'], ['possessive'], $scope));
            $this->assertTrue($result->isPossessive);
            $this->assertNull($result->possessiveScope);
        }

        // A scope without a verified owner is the model's claim alone: dropped.
        $this->assertNull($this->analyze('Show me the balance.', $this->owned(['me'], ['object'], 'personal'))->possessiveScope);
        $this->assertNull($this->analyze('Show me the balance.', $this->owned([], [], 'collective'))->possessiveScope);
    }

    /**
     * @test Measured live: whenever the model listed the "me" of "Show me…" (tagged
     * "object", scope "personal" all the same) its rewrite named the user as the owner
     * ("Which invoice entities are overdue for the user?"). A rewrite written under an
     * ownership the question does not state is not passed on.
     */
    public function the_rewrite_is_dropped_when_the_model_claimed_an_owner_the_question_does_not_state(): void
    {
        $rewrite = ['clarified_question' => 'Which invoice entities are overdue for the user?'];

        // Scope claimed, nothing verified.
        $this->assertSame('', $this->analyze('Show me the overdue invoices.', $rewrite + $this->owned(['me'], ['object'], 'personal'))->clarifiedQuestion);
        // An owner word claimed that the question does not contain.
        $this->assertSame('', $this->analyze('Show me the overdue invoices.', $rewrite + $this->owned(['my'], ['possessive'], null))->clarifiedQuestion);

        // No claim at all: the rewrite stands.
        $kept = ['clarified_question' => 'Which invoice entities are overdue?'];
        $this->assertSame('Which invoice entities are overdue?', $this->analyze('Show me the overdue invoices.', $kept + $this->owned([], [], null))->clarifiedQuestion);
        $this->assertSame('Which invoice entities are overdue?', $this->analyze('Show me the overdue invoices.', $kept)->clarifiedQuestion);
        // An object word with no scope claimed is no ownership claim.
        $this->assertSame('Which invoice entities are overdue?', $this->analyze('Show me the overdue invoices.', $kept + $this->owned(['me'], ['object'], null))->clarifiedQuestion);

        // A verified owner: the first-person rewrite is what was asked.
        $own = ['clarified_question' => 'Which invoice entities are overdue for the user?'];
        $this->assertSame(
            'Which invoice entities are overdue for the user?',
            $this->analyze('Show me my overdue invoices.', $own + $this->owned(['me', 'my'], ['object', 'possessive'], 'personal'))->clarifiedQuestion
        );
    }

    /** @test The own-profile entity rule reads the DERIVED value, not the model's boolean. */
    public function the_own_profile_rule_follows_the_verified_value(): void
    {
        config(['ai.domain.ambiguous_profile_labels' => ['Contact']]); // CondoEdge's value
        $catalog = QueryUnderstandingService::buildEntityCatalog([
            'App\\Models\\Contact' => ['graph' => ['label' => 'Contact'], 'metadata' => []],
            'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => []],
        ]);
        $run = function (string $question, array $ownership) use ($catalog): QueryUnderstandingResult {
            $llm = Mockery::mock(LlmProviderInterface::class);
            $llm->shouldReceive('chatJson')->once()->andReturn($ownership + [
                'entity_types' => ['Contact', 'Unit'], 'person_names' => [], 'property_concepts' => [],
                'search_query' => 'q', 'intent' => 'lookup', 'question_type' => 'data_query',
                'clarified_question' => '', 'search_tags' => [],
            ]);
            $logger = Mockery::mock(AiLogger::class);
            $logger->shouldReceive('startTimer', 'stopTimer', 'info', 'debug', 'error')->byDefault();

            return (new QueryUnderstandingService($llm, $logger, []))->analyze($question, $catalog);
        };

        $this->assertSame(['Unit'], $run('What is my contact information?', $this->owned(['my'], ['possessive']))->entityTypes);
        $this->assertSame(['Contact', 'Unit'], $run('Show me the contact information.', $this->owned(['my'], ['possessive']))->entityTypes);
        $this->assertSame(['Contact', 'Unit'], $run('Show me the contact information.', ['is_possessive' => true])->entityTypes);
    }

    /** @test */
    public function the_trace_snapshot_carries_the_derived_fields(): void
    {
        $snapshot = $this->analyze('When is our next meeting?', $this->owned(['our'], ['possessive'], 'collective'))->toArray();

        $this->assertTrue($snapshot['is_possessive']);
        $this->assertSame(['our'], $snapshot['possessive_words']);
        $this->assertSame('collective', $snapshot['possessive_scope']);
    }
}
