<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Attach;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\Attach\ActionAttacher;
use Condoedge\Ai\Services\Actions\Attach\AttachedActions;
use Condoedge\Ai\Services\Response\FollowUpWriter;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\Fixtures\ScriptedUsageLlm;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Follow-up question chips of a DOCUMENT-GROUNDED answer: computed after composition by
 * ONE small call (FollowUpWriter) and merged into the turn's chips.
 *
 * Pinned here: when the call is made at all (structural signals only — documents
 * retrieved AND cited, a real answer, the source switched on, an authenticated user), and
 * what the response carries afterwards (the chips after the existing ones, the passage —
 * and article — each came from as trace evidence, one observed stage).
 */
class AnswerFollowUpsTest extends TestCase
{
    use AttachFixtures;

    private const ANSWER = 'Before each flight the operator inspects the aircraft [1].';

    /**
     * Per excerpt, the fact it states and the question that asks for it: with self::ANSWER
     * the excerpts are passages [2] and [3] (the ones the answer did not use).
     */
    private const REPLY = [
        'f1' => 'A defect may be deferred only when the list allows it',
        'q1' => 'When may a defect be deferred?',
        'f2' => 'Maintenance records are kept for five years',
        'q2' => 'How long are maintenance records kept?',
    ];

    private ScriptedUsageLlm $llm;

    public function setUp(): void
    {
        parent::setUp();
        $this->configureCatalog();
        config(['logging.default' => 'null']);
        $this->llm = new ScriptedUsageLlm(json: self::REPLY);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function attacher(?LlmProviderInterface $llm = null): ActionAttacher
    {
        return new ActionAttacher(new AiActionRegistry(), new FollowUpWriter($llm ?? $this->llm));
    }

    /** @return list<array<string, mixed>> */
    private function passages(): array
    {
        return [
            ['file_id' => 7, 'chunk_index' => 3, 'filename' => 'manual.md', 'heading' => 'Part 4 › Line maintenance', 'snippet' => 'The operator inspects the aircraft before each flight.'],
            ['file_id' => 7, 'chunk_index' => 9, 'filename' => 'manual.md', 'heading' => 'Part 4 › Deferred defects', 'snippet' => 'A defect may be deferred only when the minimum equipment list allows it.'],
            ['file_id' => 7, 'chunk_index' => 14, 'filename' => 'manual.md', 'heading' => 'Part 5 › Records', 'snippet' => 'Maintenance records are kept for five years.'],
        ];
    }

    /**
     * A knowledge turn: documents retrieved, no data rows.
     *
     * @param array<string, mixed> $qu
     */
    private function knowledgeTurn(array $qu = []): PipelineContext
    {
        $ctx = $this->turn([], null, $qu + ['questionType' => 'knowledge_query']);
        $ctx->question = 'What must be checked before each flight?';
        $ctx->fileContext = ['relevant_files' => $this->passages(), 'file_count' => 3];
        $ctx->attachedActions = AttachedActions::none();

        return $ctx;
    }

    /**
     * @param array<string, mixed> $responseMetadata
     * @return array<string, mixed> the final response of a turn
     */
    private function response(string $answer = self::ANSWER, array $responseMetadata = ['style' => 'friendly']): array
    {
        return [
            'question' => 'What must be checked before each flight?',
            'answer' => $answer,
            'metadata' => ['query' => ['source' => 'file_context'], 'response' => $responseMetadata],
        ];
    }

    // ------------------------------------------------------------- the chips

    /** @test */
    public function a_document_grounded_answer_gets_its_follow_up_chips(): void
    {
        $ctx = $this->knowledgeTurn();
        $observer = new RecordingPipelineObserver();
        $ctx->observer = $observer;

        $response = $this->attacher()->withAnswerFollowUps($this->response(), $ctx, ['user' => $this->resident()]);
        $metadata = $response['metadata']['response'];

        $this->assertSame([
            'ask_follow_up|||When may a defect be deferred?',
            'ask_follow_up|||How long are maintenance records kept?',
        ], $this->chipLines($metadata['invocations']));
        $this->assertSame(
            ['When may a defect be deferred?', 'How long are maintenance records kept?'],
            array_column(array_column($metadata['invocations'], 'params'), 'question'),
            'the click sends the question itself'
        );
        $this->assertSame(['follow_up', 'follow_up'], array_column($metadata['attached_actions'], 'role'));
        // Trace evidence: the passage each chip came from.
        $this->assertSame([
            ['passage' => 2, 'question' => 'When may a defect be deferred?'],
            ['passage' => 3, 'question' => 'How long are maintenance records kept?'],
        ], $metadata['follow_ups']);
        $this->assertSame('friendly', $metadata['style'], 'the rest of the metadata is untouched');
        $this->assertSame(self::ANSWER, $response['answer'], 'the answer is never rewritten');

        $this->assertSame($metadata['invocations'], $ctx->attachedActions->invocations(), 'the turn\'s chips hold them too');
        $this->assertSame([], $ctx->attachedActions->labels(), 'never named to a narrator');

        // ONE call, one observed stage: counts and passage numbers, no question text.
        $this->assertCount(1, $this->llm->calls);
        $this->assertSame(['follow_ups'], $observer->finishedStages());
        $this->assertSame(
            // (excerpts: what the writer was shown — passage and tier, never text.)
            ['proposed' => 2, 'kept' => 2, 'dropped' => [], 'passages' => [2, 3], 'excerpts' => [['passage' => 2, 'tier' => 3], ['passage' => 3, 'tier' => 3]]],
            $observer->stageData('follow_ups')
        );
        $this->assertSame([['stage' => 'follow_ups', 'usage' => ScriptedUsageLlm::USAGE]], $observer->usages());
    }

    /** @test A hybrid turn (rows AND cited documents): the follow-ups come after the record chips. */
    public function follow_ups_come_after_the_chips_the_turn_already_has(): void
    {
        $ctx = $this->turn($this->unitRoster(2), self::ROSTER_CYPHER, ['intent' => 'list', 'questionType' => 'hybrid']);
        $ctx->fileContext = ['relevant_files' => $this->passages()];
        $attacher = $this->attacher();
        $ctx->attachedActions = $attacher->forTurn($ctx, ['user' => $this->manager()]);
        $response = $this->response(self::ANSWER, $ctx->attachedActions->applyTo(['style' => 'friendly']));

        $metadata = $attacher->withAnswerFollowUps($response, $ctx, ['user' => $this->manager()])['metadata']['response'];

        $this->assertSame([
            'view|Unit|1001|Lot 1',
            'view|Unit|1002|Lot 2',
            'ask_follow_up|||When may a defect be deferred?',
            'ask_follow_up|||How long are maintenance records kept?',
        ], $this->chipLines($metadata['invocations']));
        $this->assertTrue($ctx->attachedActions->hasRecordRefs());
    }

    /**
     * @test The relevance gate: the minimum comes from config, the kept chips carry their
     * similarity to the user's question as evidence, and the stage says what went and why.
     */
    public function the_relevance_minimum_comes_from_config_and_the_similarity_rides_the_evidence(): void
    {
        config(['ai.attached_actions.follow_ups.min_relevance' => 0.3]);
        $embedding = new \Condoedge\Ai\Tests\Unit\Services\Response\PlaneEmbeddingProvider(
            ['When may a defect be deferred?' => 0.52, 'How long are maintenance records kept?' => 0.21],
            'What must be checked before each flight?'
        );
        $attacher = new ActionAttacher(new AiActionRegistry(), new FollowUpWriter($this->llm, $embedding));
        $ctx = $this->knowledgeTurn();
        $observer = new RecordingPipelineObserver();
        $ctx->observer = $observer;

        $metadata = $attacher->withAnswerFollowUps($this->response(), $ctx, ['user' => $this->resident()])['metadata']['response'];

        $this->assertSame(['When may a defect be deferred?'], array_column($metadata['invocations'], 'text'));
        $this->assertSame(
            [['passage' => 2, 'similarity' => 0.52, 'question' => 'When may a defect be deferred?']],
            $metadata['follow_ups']
        );
        $this->assertSame(
            [
                'proposed' => 2, 'kept' => 1, 'dropped' => ['off_topic' => 1], 'passages' => [2], 'min_relevance' => 0.3,
                // Every measured question: numbers only, never its text.
                'relevance' => [
                    ['passage' => 2, 'similarity' => 0.52, 'kept' => true],
                    ['passage' => 3, 'similarity' => 0.21, 'kept' => false],
                ],
                'excerpts' => [['passage' => 2, 'tier' => 3], ['passage' => 3, 'tier' => 3]],
            ],
            $observer->stageData('follow_ups')
        );
        $this->assertCount(1, $embedding->batches, 'ONE embedding call');
    }

    /**
     * @test No minimum configured: who gates follows who judges. A writer whose MODEL
     * declines tangential excerpts needs no floor — no embedding call is made at all.
     */
    public function without_a_configured_minimum_a_model_that_declines_is_not_gated(): void
    {
        config(['ai.attached_actions.follow_ups' => ['enabled' => true]]);
        $embedding = new \Condoedge\Ai\Tests\Unit\Services\Response\PlaneEmbeddingProvider([], 'What must be checked before each flight?');
        $attacher = new ActionAttacher(new AiActionRegistry(), new FollowUpWriter($this->llm, $embedding));
        $ctx = $this->knowledgeTurn();
        $observer = new RecordingPipelineObserver();
        $ctx->observer = $observer;

        $metadata = $attacher->withAnswerFollowUps($this->response(), $ctx, ['user' => $this->resident()])['metadata']['response'];

        $this->assertCount(2, $metadata['invocations']);
        $this->assertSame([], $embedding->batches, 'no embedding call');
        $this->assertArrayNotHasKey('similarity', $metadata['follow_ups'][0]);
        $this->assertArrayNotHasKey('min_relevance', $observer->stageData('follow_ups'));
    }

    /** @test …and a code-gated writer gets the measured default floor. */
    public function without_a_configured_minimum_a_code_gated_writer_gets_the_default_floor(): void
    {
        config(['ai.attached_actions.follow_ups' => ['enabled' => true]]);
        $embedding = new \Condoedge\Ai\Tests\Unit\Services\Response\PlaneEmbeddingProvider(
            [
                'When may a defect be deferred?' => FollowUpWriter::DEFAULT_MIN_RELEVANCE + 0.01,
                'How long are maintenance records kept?' => FollowUpWriter::DEFAULT_MIN_RELEVANCE - 0.01,
            ],
            'What must be checked before each flight?'
        );
        $attacher = new ActionAttacher(new AiActionRegistry(), new FollowUpWriter($this->llm, $embedding, false));

        $metadata = $attacher->withAnswerFollowUps($this->response(), $this->knowledgeTurn(), ['user' => $this->resident()])['metadata']['response'];

        $this->assertSame(['When may a defect be deferred?'], array_column($metadata['invocations'], 'text'));
    }

    /** @test Never unfiltered: with a minimum asked for and no way to measure, no chip is attached. */
    public function a_gate_that_cannot_be_applied_attaches_nothing(): void
    {
        config(['ai.attached_actions.follow_ups.min_relevance' => 0.3]);
        $response = $this->response();
        $ctx = $this->knowledgeTurn();
        $observer = new RecordingPipelineObserver();
        $ctx->observer = $observer;

        // This attacher's writer has no embedding provider.
        $this->assertSame($response, $this->attacher()->withAnswerFollowUps($response, $ctx, ['user' => $this->resident()]));
        $this->assertSame(['relevance_unavailable' => 2], $observer->stageData('follow_ups')['dropped']);
    }

    /** @test */
    public function the_cap_comes_from_config(): void
    {
        config(['ai.attached_actions.max_follow_ups' => 1]);

        $metadata = $this->attacher()->withAnswerFollowUps($this->response(), $this->knowledgeTurn(), ['user' => $this->resident()])['metadata']['response'];

        $this->assertSame(['When may a defect be deferred?'], array_column($metadata['invocations'], 'text'));
        $this->assertSame([['passage' => 2, 'question' => 'When may a defect be deferred?']], $metadata['follow_ups']);
    }

    /** @test */
    public function the_question_language_and_on_a_follow_up_turn_its_resolved_form_reach_the_writer(): void
    {
        $rewrite = 'What must be checked before a night flight?';

        // A follow-up turn (QU): the question only makes sense resolved against its conversation.
        $followUp = $this->knowledgeTurn(['language' => 'fr', 'clarifiedQuestion' => $rewrite, 'followUpReference' => true]);
        $this->attacher()->withAnswerFollowUps($this->response(), $followUp, ['user' => $this->resident()]);
        // A first message: QU's rewrite is a mere rewording of it.
        $first = $this->knowledgeTurn(['language' => 'fr', 'clarifiedQuestion' => $rewrite]);
        $this->attacher()->withAnswerFollowUps($this->response(), $first, ['user' => $this->resident()]);

        $this->assertStringContainsString('QUESTION (written in "fr"): What must be checked before each flight?', $this->llm->calls[0][1]['content']);
        $this->assertStringContainsString('resolved against its conversation: ' . $rewrite, $this->llm->calls[0][1]['content']);
        $this->assertStringNotContainsString('resolved against its conversation', $this->llm->calls[1][1]['content']);
    }

    /** @test The article a chip came from rides the trace evidence beside its passage. */
    public function the_evidence_names_the_article_when_the_passage_has_articles(): void
    {
        $ctx = $this->knowledgeTurn();
        $ctx->fileContext['relevant_files'][1]['snippet'] = "**410.** A defect may be deferred only when the minimum equipment list allows it.";
        $attacher = $this->attacher(new ScriptedUsageLlm(json: ['f1' => 'A defect may be deferred', 'q1' => 'When may a defect be deferred?']));

        $metadata = $attacher->withAnswerFollowUps($this->response(), $ctx, ['user' => $this->resident()])['metadata']['response'];

        $this->assertSame([['passage' => 2, 'article' => 410, 'question' => 'When may a defect be deferred?']], $metadata['follow_ups']);
    }

    /**
     * @test The turn's own deepening contract reaches the writer: what an earlier answer of
     * the conversation cited (the conversation memory's `last_referenced_files`) is not new
     * material — no chip re-asks it (eval p19-B rights t1).
     */
    public function what_the_conversation_already_cited_gives_no_chip(): void
    {
        $ctx = $this->knowledgeTurn();
        // The previous answer cited the passage on deferred defects (this turn's [2]).
        $ctx->conversationContext = ['last_referenced_files' => [['id' => 7, 'chunk_index' => 9, 'name' => 'manual.md']]];
        $observer = new RecordingPipelineObserver();
        $ctx->observer = $observer;

        $metadata = $this->attacher()->withAnswerFollowUps($this->response(), $ctx, ['user' => $this->resident()])['metadata']['response'];

        $this->assertStringNotContainsString('Deferred defects', $this->llm->calls[0][1]['content']);
        $this->assertSame([['passage' => 3, 'question' => 'How long are maintenance records kept?']], $metadata['follow_ups']);
        // The model's question on the covered passage has no excerpt behind it.
        $this->assertSame(['unsupported' => 1], $observer->stageData('follow_ups')['dropped']);
    }

    // ------------------------------------------------------------- no call

    /**
     * @test An app how-to (QU's navigation_target — the structural signal that already
     * skips the document probes): the answer's door is the page chip, and the writer
     * declined every excerpt of such a turn (eval p19-B: 1.1-1.3 s and ~700 tokens each,
     * for nothing).
     */
    public function a_navigation_turn_makes_no_call(): void
    {
        foreach (['own_team', 'own_profile'] as $target) {
            $this->assertUntouchedWithoutACall(
                $this->response(),
                $this->knowledgeTurn(['navigationTarget' => $target]),
                ['user' => $this->resident()]
            );
        }
    }

    /** @param array<string, mixed> $response */
    private function assertUntouchedWithoutACall(array $response, PipelineContext $ctx, array $options, ?ActionAttacher $attacher = null): void
    {
        $observer = new RecordingPipelineObserver();
        $ctx->observer = $observer;

        $this->assertSame($response, ($attacher ?? $this->attacher())->withAnswerFollowUps($response, $ctx, $options));
        $this->assertSame([], $this->llm->calls, 'no LLM call');
        $this->assertSame([], $observer->events(), 'no stage');
    }

    /** @test */
    public function without_a_writer_nothing_happens(): void
    {
        $this->assertUntouchedWithoutACall(
            $this->response(),
            $this->knowledgeTurn(),
            ['user' => $this->resident()],
            new ActionAttacher(new AiActionRegistry())
        );
    }

    /** @test A data turn retrieved no document: there is nothing to ask about. */
    public function a_data_turn_makes_no_call(): void
    {
        $ctx = $this->turn($this->unitRoster(3), self::ROSTER_CYPHER, ['intent' => 'list']);
        $ctx->attachedActions = AttachedActions::none();

        $this->assertUntouchedWithoutACall($this->response('Three units.'), $ctx, ['user' => $this->manager()]);
    }

    /** @test Documents merely in sight: an answer that cites none of them is not document-grounded. */
    public function an_answer_that_cites_no_passage_makes_no_call(): void
    {
        foreach (['The documents I can read do not cover this.', 'Three units are listed.', 'See [9] and [0].'] as $answer) {
            $this->assertUntouchedWithoutACall($this->response($answer), $this->knowledgeTurn(), ['user' => $this->resident()]);
        }
    }

    /**
     * @test
     * @dataProvider unanswered
     */
    public function an_error_a_block_or_an_abstention_makes_no_call(array $responseMetadata): void
    {
        $this->assertUntouchedWithoutACall(
            $this->response(self::ANSWER, $responseMetadata),
            $this->knowledgeTurn(),
            ['user' => $this->resident()]
        );
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function unanswered(): array
    {
        return [
            'error' => [['error' => true, 'error_type' => 'RuntimeException']],
            'understanding failed' => [['status' => 'understanding_failed', 'error' => true]],
            'data part failed' => [['status' => 'partial_data_failed']],
            'blocked by scoping' => [['status' => 'blocked_by_scoping']],
            'empty result' => [['empty_result' => true, 'result_count' => 0]],
            'canned fallback' => [['empty_result' => true, 'fallback' => true]],
        ];
    }

    /** @test */
    public function a_file_listing_makes_no_call(): void
    {
        $ctx = $this->knowledgeTurn();
        $ctx->fileContext['enumeration'] = true;

        $this->assertUntouchedWithoutACall($this->response('You can read [1], [2] and [3].'), $ctx, ['user' => $this->resident()]);
    }

    /** @test */
    public function an_export_turn_makes_no_call(): void
    {
        $this->assertUntouchedWithoutACall($this->response(), $this->knowledgeTurn(['exportFormat' => 'pdf']), ['user' => $this->resident()]);
    }

    /** @test */
    public function a_guest_makes_no_call(): void
    {
        $this->assertUntouchedWithoutACall($this->response(), $this->knowledgeTurn(), []);
    }

    /**
     * @test
     * @dataProvider switchesOff
     */
    public function a_switch_turns_the_call_off(array $config): void
    {
        config($config);

        $this->assertUntouchedWithoutACall($this->response(), $this->knowledgeTurn(), ['user' => $this->resident()]);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function switchesOff(): array
    {
        return [
            'attached actions off' => [['ai.attached_actions.enabled' => false]],
            'follow-ups off' => [['ai.attached_actions.follow_ups.enabled' => false]],
            'this source off' => [['ai.attached_actions.follow_ups.knowledge' => false]],
            'cap at zero' => [['ai.attached_actions.max_follow_ups' => 0]],
        ];
    }

    /** @test A branch that assembled its own chips is left alone. */
    public function a_response_whose_chips_are_not_the_turns_own_makes_no_call(): void
    {
        $foreign = ['invocations' => [['key' => 'export_pdf', 'entity_type' => null, 'entity_id' => null, 'params' => [], 'text' => 'Download PDF']]];

        $this->assertUntouchedWithoutACall($this->response(self::ANSWER, $foreign), $this->knowledgeTurn(), ['user' => $this->resident()]);
    }

    // ------------------------------------------------------------- nothing kept, failures

    /** @test */
    public function when_no_question_survives_the_response_is_unchanged(): void
    {
        $response = $this->response();
        $attacher = $this->attacher(new ScriptedUsageLlm(json: ['f1' => 'A defect may be deferred', 'q1' => 'What must be checked before each flight?']));
        $ctx = $this->knowledgeTurn();
        $observer = new RecordingPipelineObserver();
        $ctx->observer = $observer;

        $this->assertSame($response, $attacher->withAnswerFollowUps($response, $ctx, ['user' => $this->resident()]));
        $this->assertTrue($ctx->attachedActions->isEmpty());
        $this->assertSame(
            ['proposed' => 1, 'kept' => 0, 'dropped' => ['repeats_question' => 1], 'passages' => [], 'excerpts' => [['passage' => 2, 'tier' => 3], ['passage' => 3, 'tier' => 3]]],
            $observer->stageData('follow_ups')
        );
    }

    /** @test */
    public function a_failed_call_never_costs_the_answer(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->andThrow(new \RuntimeException('provider timeout'));
        $response = $this->response();
        $ctx = $this->knowledgeTurn();
        $observer = new RecordingPipelineObserver();
        $ctx->observer = $observer;

        $this->assertSame($response, $this->attacher($llm)->withAnswerFollowUps($response, $ctx, ['user' => $this->resident()]));
        $this->assertSame(
            [['stage' => 'follow_ups', 'class' => \RuntimeException::class, 'message' => 'provider timeout']],
            $observer->errors()
        );
    }

    /** @test A malformed response (no metadata, no answer) is returned as it came. */
    public function a_malformed_response_is_returned_untouched(): void
    {
        foreach ([[], ['answer' => self::ANSWER], ['answer' => self::ANSWER, 'metadata' => 'x'], ['answer' => null, 'metadata' => ['response' => []]]] as $response) {
            $this->assertSame($response, $this->attacher()->withAnswerFollowUps($response, $this->knowledgeTurn(), ['user' => $this->resident()]));
        }
        $this->assertSame([], $this->llm->calls);
    }
}
