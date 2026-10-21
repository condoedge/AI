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
 * Small talk and questions about the assistant itself ("How are you?", "Who are you?",
 * "Thanks, that helps!", "Which AI model are you?") were forced into data_query /
 * knowledge_query / hybrid: the turn searched documents, generated a query and answered
 * "No results were found for that question." (measured 2026-10-21, user 13).
 *
 * CONTRACT:
 *  - QU knows a fourth question type, "conversational": in the enum, the strict schema
 *    and the prompt's existing question_type line (no key moved);
 *  - the model classifies, code verifies STRUCTURALLY: the turn is conversational only
 *    when QU said so AND the reply names nothing to look up — no entity type, no person,
 *    no property concept, no navigation target, no export format, no digest ask, no
 *    file listing / page explanation intent, no follow-up on a previous result.
 *    Otherwise the value normalises exactly as before ('hybrid', the old fallback of an
 *    unknown type), so a mixed message ("Hi! Who is on our board?") stays a lookup turn.
 */
class QueryUnderstandingConversationalTest extends TestCase
{
    /** @var list<array{messages: array, options: array}> */
    private array $calls = [];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private const SMALL_TALK = [
        'entity_types' => [],
        'person_names' => [],
        'property_concepts' => [],
        'search_query' => 'assistant wellbeing',
        'subject_label' => 'Greeting',
        'possessive_words' => [],
        'possessive_forms' => [],
        'possessive_scope' => null,
        'intent' => 'other',
        'question_type' => 'conversational',
        'clarified_question' => 'How is the assistant doing?',
        'search_tags' => [],
        'export_format' => null,
        'language' => 'en',
        'navigation_target' => null,
        'follow_up_reference' => false,
    ];

    private const OLD_LINE = '- "question_type": one of "data_query" (needs database/graph lookup), "knowledge_query" (needs file/document content — rules, regulations, policies, "can I do X?"), "hybrid" (needs both data and documents)';

    /**
     * @param list<array|\Throwable> $more the replies of any further call (the old-prompt retry)
     */
    private function analyze(array $reply, string $question = 'How are you?', array $catalog = [], array $more = []): QueryUnderstandingResult
    {
        $script = [$reply, ...$more];
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->times(count($script))->andReturnUsing(function ($messages, $options) use (&$script) {
            $this->calls[] = ['messages' => $messages, 'options' => $options];
            $next = array_shift($script);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        foreach (['info', 'debug', 'warning', 'error'] as $level) {
            $logger->shouldReceive($level)->byDefault();
        }

        return (new QueryUnderstandingService($llm, $logger, []))->analyze($question, $catalog);
    }

    /** The question_type line of a system prompt. */
    private function questionTypeLine(string $system): string
    {
        foreach (preg_split('/\R/', $system) as $candidate) {
            if (str_starts_with($candidate, '- "question_type":')) {
                return $candidate;
            }
        }

        return '';
    }

    /** @test */
    public function the_question_type_line_documents_the_conversational_value_without_moving_any_key(): void
    {
        $this->analyze(self::SMALL_TALK);
        $system = $this->calls[0]['messages'][0]['content'];

        $line = $this->questionTypeLine($system);
        // The three existing values keep their wording and their place; the new one comes last.
        $this->assertStringStartsWith(
            '- "question_type": one of "data_query" (needs database/graph lookup), "knowledge_query" (needs file/document content — rules, regulations, policies, "can I do X?"), "hybrid" (needs both data and documents), "conversational" (',
            $line
        );

        // The key order is unchanged: question_type still sits between intent and clarified_question.
        preg_match_all('/^- "([a-z_]+)":/m', $system, $m);
        $at = array_search('question_type', $m[1], true);
        $this->assertSame('intent', $m[1][$at - 1]);
        $this->assertSame('clarified_question', $m[1][$at + 1]);
    }

    /** @test */
    public function the_strict_schema_allows_the_conversational_value_last(): void
    {
        $this->analyze(self::SMALL_TALK);

        $properties = $this->calls[0]['options']['json_schema']['schema']['properties'];
        $this->assertSame(['data_query', 'knowledge_query', 'hybrid', 'conversational'], $properties['question_type']['enum']);
    }

    /** @test */
    public function a_conversational_reply_that_names_nothing_to_look_up_is_conversational(): void
    {
        $result = $this->analyze(self::SMALL_TALK);

        $this->assertSame('conversational', $result->questionType);
        $this->assertTrue($result->isConversational());
        $this->assertSame('conversational', $result->toArray()['question_type']);
    }

    /** @test */
    public function the_value_is_read_case_and_space_insensitively(): void
    {
        $this->assertSame('conversational', $this->analyze(['question_type' => ' Conversational '] + self::SMALL_TALK)->questionType);
    }

    /**
     * @test The guard truth table: ONE structural signal of something to look up and the
     * reading is not conversational. Measured live (2026-10-21, replays of the eval p27 QU
     * prompts): offered the value, the model also chose it for "Create a PDF of this." with
     * export_format=pdf — and those replies had LOST follow_up_reference and the entity
     * types. So a rejected conversational reading is not patched to another value: Query
     * Understanding is asked AGAIN with the prompt and schema of before (no conversational
     * value), and that reading is the turn's — what the turn got before the value existed.
     *
     * @dataProvider lookupSignals
     */
    public function a_conversational_reply_naming_anything_to_look_up_is_asked_again_without_the_value(array $override, array $catalog = []): void
    {
        $retry = ['question_type' => 'data_query', 'entity_types' => [], 'intent' => 'list'] + self::SMALL_TALK;

        $result = $this->analyze($override + self::SMALL_TALK, 'Hi! Who is on our board of directors?', $catalog, [$retry]);

        $this->assertCount(2, $this->calls);
        $this->assertSame(self::OLD_LINE, $this->questionTypeLine($this->calls[1]['messages'][0]['content']), 'the retry is the prompt of before');
        $this->assertSame(
            ['data_query', 'knowledge_query', 'hybrid'],
            $this->calls[1]['options']['json_schema']['schema']['properties']['question_type']['enum']
        );
        $this->assertSame($this->calls[0]['messages'][1], $this->calls[1]['messages'][1], 'same question');

        $this->assertSame('data_query', $result->questionType, "the retry's reading is the turn's");
        $this->assertSame('list', $result->intent);
        $this->assertFalse($result->isConversational());
    }

    /** @test The retry prompt is the prompt of before: the new one without the clause, byte for byte. */
    public function the_retry_prompt_is_the_new_prompt_without_the_clause(): void
    {
        $this->analyze(['navigation_target' => 'own_team'] + self::SMALL_TALK, 'Where can I add members?', [], [self::SMALL_TALK]);

        $new = $this->calls[0]['messages'][0]['content'];
        $old = $this->calls[1]['messages'][0]['content'];
        $this->assertSame(str_replace($this->questionTypeLine($new), self::OLD_LINE, $new), $old);
        $this->assertStringNotContainsString('conversational', $old);
    }

    /** @test Under the old schema the value cannot come back; if it does anyway (no strict schema) it is hybrid, as before. */
    public function a_retry_that_still_says_conversational_normalises_as_before(): void
    {
        $reply = ['property_concepts' => ['fees']] + self::SMALL_TALK;
        $result = $this->analyze($reply, 'Hi, what are the fees?', [], [$reply]);

        $this->assertCount(2, $this->calls);
        $this->assertSame('hybrid', $result->questionType);
        $this->assertSame(['fees'], $result->propertyConcepts);
    }

    /** @test A failed retry keeps the first reading, normalised as an unknown type always was. */
    public function a_failed_retry_keeps_the_first_reading_as_hybrid(): void
    {
        $result = $this->analyze(['entity_types' => ['Unit']] + self::SMALL_TALK, 'Hi! Which units are vacant?', [], [new \RuntimeException('provider down')]);

        $this->assertCount(2, $this->calls);
        $this->assertSame('hybrid', $result->questionType);
        $this->assertSame(['Unit'], $result->entityTypes);
        $this->assertFalse($result->failed);
    }

    /** @test */
    public function an_accepted_conversational_reading_or_any_other_type_makes_one_call(): void
    {
        $this->analyze(self::SMALL_TALK);
        $this->assertCount(1, $this->calls);

        $this->calls = [];
        $this->analyze(['question_type' => 'data_query', 'entity_types' => ['Unit']] + self::SMALL_TALK);
        $this->assertCount(1, $this->calls);
    }

    public static function lookupSignals(): array
    {
        return [
            'an entity type' => [['entity_types' => ['BoardMember']], [['label' => 'BoardMember', 'aliases' => [], 'description' => '']]],
            // Judged on what the model NAMED, before catalog validation drops an unknown label.
            'an entity type the catalog does not know' => [['entity_types' => ['Board']], [['label' => 'Unit', 'aliases' => [], 'description' => '']]],
            'a person name' => [['person_names' => ['Maria']]],
            'a property concept' => [['property_concepts' => ['balance']]],
            'a navigation target' => [['navigation_target' => 'own_team']],
            'an export format' => [['export_format' => 'pdf']],
            'a follow-up on the previous result' => [['follow_up_reference' => true]],
            'a file listing intent' => [['intent' => 'file_listing']],
            'a page explanation intent' => [['intent' => 'page_explanation']],
        ];
    }

    /** @test */
    public function a_digest_ask_is_never_conversational(): void
    {
        config(['ai.attention_sources' => [
            'open_tasks' => ['label' => 'Open tasks', 'cypher' => 'MATCH (t:Task) RETURN t'],
        ]]);

        $result = $this->analyze(['digest_request' => true] + self::SMALL_TALK, 'What needs my attention?', [], [
            ['digest_request' => true, 'question_type' => 'data_query'] + self::SMALL_TALK,
        ]);

        $this->assertCount(2, $this->calls);
        $this->assertSame('data_query', $result->questionType);
        $this->assertTrue($result->digestRequest);
        $this->assertFalse($result->isConversational());
    }

    /** @test The other values are untouched, an unknown value still falls back to hybrid. */
    public function the_existing_values_normalise_exactly_as_before(): void
    {
        foreach (['data_query', 'knowledge_query', 'hybrid'] as $type) {
            $this->assertSame($type, $this->analyze(['question_type' => $type] + self::SMALL_TALK)->questionType, $type);
        }
        $this->assertSame('hybrid', $this->analyze(['question_type' => 'chit_chat'] + self::SMALL_TALK)->questionType);
        $this->assertSame('hybrid', $this->analyze(['question_type' => null] + self::SMALL_TALK)->questionType);
    }

    /** @test The DTO re-checks the guard itself: a result built directly cannot bypass it. */
    public function the_result_dto_verifies_the_guard_itself(): void
    {
        $this->assertTrue((new QueryUnderstandingResult(questionType: 'conversational'))->isConversational());

        $this->assertFalse((new QueryUnderstandingResult(questionType: 'hybrid'))->isConversational());
        $this->assertFalse((new QueryUnderstandingResult(questionType: 'conversational', entityTypes: ['Unit']))->isConversational());
        $this->assertFalse((new QueryUnderstandingResult(questionType: 'conversational', propertyConcepts: ['fees']))->isConversational());
        $this->assertFalse((new QueryUnderstandingResult(questionType: 'conversational', personNames: ['Maria']))->isConversational());
        $this->assertFalse((new QueryUnderstandingResult(questionType: 'conversational', navigationTarget: 'own_profile'))->isConversational());
        $this->assertFalse((new QueryUnderstandingResult(questionType: 'conversational', exportFormat: 'excel'))->isConversational());
        $this->assertFalse((new QueryUnderstandingResult(questionType: 'conversational', digestRequest: true))->isConversational());
        $this->assertFalse((new QueryUnderstandingResult(questionType: 'conversational', followUpReference: true))->isConversational());
        $this->assertFalse((new QueryUnderstandingResult(questionType: 'conversational', intent: 'file_listing'))->isConversational());
        $this->assertFalse((new QueryUnderstandingResult(questionType: 'conversational', intent: 'page_explanation'))->isConversational());
        $this->assertFalse((new QueryUnderstandingResult(questionType: 'conversational', needsClarification: true, clarifyingQuestion: 'Which one?'))->isConversational());
        $this->assertFalse((new QueryUnderstandingResult(questionType: 'conversational', failed: true))->isConversational());
        $this->assertFalse(QueryUnderstandingResult::failed()->isConversational());
        $this->assertFalse(QueryUnderstandingResult::empty()->isConversational());
    }
}
