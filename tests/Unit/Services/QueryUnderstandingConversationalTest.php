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
 * "Thanks, that helps!", "Which AI model are you?") ran the whole lookup pipeline and
 * answered "No results were found for that question." (measured 2026-10-21, user 13).
 *
 * The conversational precondition is STRUCTURAL and read from Query Understanding as it
 * is: QueryUnderstandingResult::asksForNothingToLookUp() — no entity type, person,
 * property concept, navigation target, export format, digest ask, follow-up, file listing
 * or clarification need, and a QU call that did not fail. The verdict is the
 * conversational composer's (AiManagerConversationalTurnTest).
 *
 * The QU prompt and schema are NOT changed for it. Measured 2026-10-21 (replays of the
 * eval p27 QU prompts, gpt-4o-mini, temperature 0, strict schema): with a fourth
 * "conversational" question type documented in the question_type line, "Create a PDF of
 * this." (after a balance answer) came back conversational on 3-5 of 5 replies, and its
 * other keys moved with it — follow_up_reference false and no entity type, where the
 * prompt of before gave follow_up_reference true and the entity types on 8 of 8; in the
 * live suite run the turn then generated a new query instead of exporting the recorded
 * result. Without the value, the readings of all nine small-talk / identity probes name
 * nothing (27 of 27 replays), and every turn of the two eval suites names something.
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

    /** QU's reading of "How are you?" as measured live (question_type knowledge_query, nothing named). */
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
        'question_type' => 'knowledge_query',
        'clarified_question' => 'How is the assistant doing?',
        'search_tags' => [],
        'export_format' => null,
        'language' => 'en',
        'navigation_target' => null,
        'follow_up_reference' => false,
    ];

    private function analyze(array $reply, string $question = 'How are you?', array $catalog = []): QueryUnderstandingResult
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages, $options) use ($reply) {
            $this->calls[] = ['messages' => $messages, 'options' => $options];

            return $reply;
        });
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        foreach (['info', 'debug', 'warning', 'error'] as $level) {
            $logger->shouldReceive($level)->byDefault();
        }

        return (new QueryUnderstandingService($llm, $logger, []))->analyze($question, $catalog);
    }

    /** @test The prompt and the schema keep the three question types of before (see the class doc). */
    public function query_understanding_is_not_asked_whether_a_message_is_conversational(): void
    {
        $this->analyze(self::SMALL_TALK);

        $this->assertStringContainsString(
            "\"hybrid\" (needs both data and documents)\n",
            str_replace("\r\n", "\n", $this->calls[0]['messages'][0]['content'])
        );
        $this->assertStringNotContainsString('conversational', $this->calls[0]['messages'][0]['content']);
        $this->assertSame(
            ['data_query', 'knowledge_query', 'hybrid'],
            $this->calls[0]['options']['json_schema']['schema']['properties']['question_type']['enum']
        );
    }

    /** @test */
    public function a_small_talk_reading_asks_for_nothing_to_look_up(): void
    {
        $this->assertTrue($this->analyze(self::SMALL_TALK)->asksForNothingToLookUp());

        // "What can you do?" is read as a page explanation: it names nothing either (the
        // composer tells a question about the assistant from one about the page).
        $this->assertTrue($this->analyze(['intent' => 'page_explanation'] + self::SMALL_TALK, 'What can you do?')->asksForNothingToLookUp());
    }

    /**
     * @test The truth table: ONE structural signal of something to look up and the reading
     * is not a conversational candidate — a mixed "Hi! Who is on our board?" names a kind
     * of record and keeps the full pipeline.
     *
     * @dataProvider lookupSignals
     */
    public function a_reading_naming_anything_to_look_up_is_not_a_candidate(array $override, array $catalog = []): void
    {
        $this->assertFalse($this->analyze($override + self::SMALL_TALK, 'Hi! Who is on our board of directors?', $catalog)->asksForNothingToLookUp());
    }

    public static function lookupSignals(): array
    {
        return [
            'an entity type' => [['entity_types' => ['BoardMember']], [['label' => 'BoardMember', 'aliases' => [], 'description' => '']]],
            'a person name' => [['person_names' => ['Maria']]],
            'a property concept' => [['property_concepts' => ['balance']]],
            'a navigation target' => [['navigation_target' => 'own_team']],
            'an export format' => [['export_format' => 'pdf']],
            'a follow-up on the previous result' => [['follow_up_reference' => true]],
            'a file listing intent' => [['intent' => 'file_listing']],
        ];
    }

    /** @test */
    public function a_digest_ask_is_not_a_candidate(): void
    {
        config(['ai.attention_sources' => [
            'open_tasks' => ['label' => 'Open tasks', 'cypher' => 'MATCH (t:Task) RETURN t'],
        ]]);

        $this->assertFalse($this->analyze(['digest_request' => true] + self::SMALL_TALK, 'What needs my attention?')->asksForNothingToLookUp());
    }

    /** @test The DTO reads its own fields: a failed or clarifying reading is never a candidate. */
    public function the_result_dto_applies_the_gate_to_its_own_fields(): void
    {
        $this->assertTrue((new QueryUnderstandingResult(questionType: 'knowledge_query'))->asksForNothingToLookUp());
        $this->assertTrue(QueryUnderstandingResult::empty()->asksForNothingToLookUp());

        $this->assertFalse((new QueryUnderstandingResult(entityTypes: ['Unit']))->asksForNothingToLookUp());
        $this->assertFalse((new QueryUnderstandingResult(propertyConcepts: ['fees']))->asksForNothingToLookUp());
        $this->assertFalse((new QueryUnderstandingResult(personNames: ['Maria']))->asksForNothingToLookUp());
        $this->assertFalse((new QueryUnderstandingResult(navigationTarget: 'own_profile'))->asksForNothingToLookUp());
        $this->assertFalse((new QueryUnderstandingResult(exportFormat: 'excel'))->asksForNothingToLookUp());
        $this->assertFalse((new QueryUnderstandingResult(digestRequest: true))->asksForNothingToLookUp());
        $this->assertFalse((new QueryUnderstandingResult(followUpReference: true))->asksForNothingToLookUp());
        $this->assertFalse((new QueryUnderstandingResult(intent: 'file_listing'))->asksForNothingToLookUp());
        $this->assertFalse((new QueryUnderstandingResult(needsClarification: true, clarifyingQuestion: 'Which one?'))->asksForNothingToLookUp());
        $this->assertFalse(QueryUnderstandingResult::failed()->asksForNothingToLookUp());
    }
}
