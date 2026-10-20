<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Understanding\ClarityChecker;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\Fixtures\ScriptedUsageLlm;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The focused clarity call in the pipeline (eval p15 decision).
 *
 * "Is this question genuinely ambiguous?" used to be asked of EVERY
 * Query-Understanding call and never came back true (0 of 148 eval turns). It is
 * now judged by ClarityChecker, called right before the clarify branch ONLY when
 * its structural preconditions hold: a first message, asking for data, whose
 * text names no kind of record and no person. When it finds a fork, the turn is
 * answered with its ONE clarifying question — no file search, no query
 * generation, no response-generation call, and none of what Query Understanding
 * guessed about the subject-less question is followed. A failing call costs the
 * turn nothing.
 */
class AiManagerClarityCheckTest extends TestCase
{
    private const QUESTION = 'What is the status?';

    private const FORK = [
        'asked' => 'the status',
        'first_person' => [],
        'forms' => [],
        'named' => '',
        'candidates' => ['aircraft', 'work card'],
        'readings' => ['the status of an aircraft', 'the status of a work card'],
        'question' => 'Do you mean the status of an aircraft or of a work card?',
    ];

    private ContextRetrieverInterface $mockContext;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;
    private FileContextProvider $mockFileContextProvider;
    private LlmProviderInterface $mockLlm;

    private int $searchCalls = 0;

    public function setUp(): void
    {
        parent::setUp();

        config(['logging.default' => 'null']);
        config(['ai.security.enabled' => false]);
        config(['ai.file_context.enabled' => true]);
        config(['ai.file_context.skip_for_question_types' => []]);
        config(['ai.file_context.recall_floor' => 0]);
        config(['ai.understanding.clarify_enabled' => true]);
        config(['ai.query_understanding.enabled' => true]);
        config(['entities' => [
            'App\\Models\\Fleet\\Aircraft' => ['graph' => ['label' => 'Aircraft'], 'metadata' => ['aliases' => ['plane']]],
            'App\\Models\\Fleet\\WorkCard' => ['graph' => ['label' => 'WorkCard'], 'metadata' => ['aliases' => ['task card']]],
        ]]);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockFileContextProvider = Mockery::mock(FileContextProvider::class);
        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);

        $this->searchCalls = 0;
        $this->mockFileContextProvider->shouldReceive('getFileContext')
            ->andReturnUsing(function () {
                $this->searchCalls++;

                return ['relevant_files' => [], 'file_count' => 0];
            });
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function manager(?LlmProviderInterface $clarityLlm): AiManager
    {
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn(array_fill(0, 8, 0.1));
        $embedding->shouldReceive('embedBatch')->andReturnUsing(
            static fn (array $texts): array => array_map(static fn () => array_fill(0, 8, 0.1), $texts)
        );
        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $vectorStore->shouldReceive('collectionExists')->andReturn(true);
        $vectorStore->shouldReceive('upsert')->andReturn(true);

        return new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            $embedding,
            $this->mockLlm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            $vectorStore,
            $this->mockFileContextProvider,
            clarityChecker: $clarityLlm === null ? null : new ClarityChecker($clarityLlm),
        );
    }

    /** What Query Understanding returns for the subject-less question — invented owner included. */
    private function context(array $quOverrides = [], string $question = self::QUESTION): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = new QueryUnderstandingResult(...$quOverrides + [
            'entityTypes' => ['WorkCard'],
            'propertyConcepts' => ['status'],
            'searchQuery' => 'status of my work cards',
            'isPossessive' => true,
            'intent' => 'lookup',
            'questionType' => 'data_query',
            'clarifiedQuestion' => 'What is the status of the work cards assigned to me?',
            'language' => 'en',
            'subjectLabel' => 'My work card status',
        ]);
        $ctx->graphSchema = ['labels' => ['Aircraft', 'WorkCard']];

        return $ctx;
    }

    /** Wire the mocks for a turn the NORMAL pipeline must own. */
    private function expectNormalTurn(PipelineContext $ctx): void
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (w:WorkCard) RETURN w.status AS status',
            explanation: '',
            confidence: 0.9,
        ));
        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(new QueryExecutionResult(
            success: true, data: [['status' => 'open']], stats: [], metadata: [], errors: [],
        ));
        $this->mockResponseGenerator->shouldReceive('generate')->andReturn([
            'answer' => 'normal answer', 'insights' => [], 'visualizations' => [], 'metadata' => [],
        ]);
    }

    /** @test */
    public function a_subject_less_first_message_is_answered_with_the_one_clarifying_question(): void
    {
        $llm = new ScriptedUsageLlm(json: self::FORK);
        $ctx = $this->context();

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        // The whole point: ONE clarity call, then NO retrieval, NO generation, NO response LLM call.
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockQueryExecutor->shouldReceive('execute')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockLlm->shouldReceive('chat')->never();
        $this->mockLlm->shouldReceive('chatJson')->never();
        $this->mockLlm->shouldReceive('complete')->never();
        $observer = new RecordingPipelineObserver();

        $result = $this->manager($llm)->answerQuestion(self::QUESTION, ['user' => (object) ['id' => 13], 'observer' => $observer]);

        $this->assertSame('Do you mean the status of an aircraft or of a work card?', $result['answer']);
        $this->assertSame('clarification', $result['metadata']['query']['source'] ?? null);
        $this->assertSame(
            ['the status of an aircraft', 'the status of a work card'],
            $result['metadata']['response']['clarification']['candidate_readings'] ?? null
        );
        $this->assertNull($result['cypher']);
        $this->assertSame([], $result['data']);
        $this->assertCount(1, $llm->calls, 'exactly ONE clarity call, and no further LLM call');
        $this->assertSame(0, $this->searchCalls, 'file search must never run on a clarify turn');

        $this->assertSame(['understanding', 'clarity_check', 'clarify', 'composition'], $observer->finishedStages());
        $stage = $observer->stageData('clarity_check');
        $this->assertTrue($stage['ask']);
        $this->assertSame('ambiguous', $stage['reason']);
        $this->assertSame(2, $stage['readings']);
        $this->assertSame([$llm->calls[0]], $observer->prompts('clarity_check'));
    }

    /** @test */
    public function what_query_understanding_guessed_about_the_subject_less_question_is_not_followed(): void
    {
        // Live (eval p12-p16): for "Show me the balance." QU set is_possessive, rewrote the
        // question to "…associated with my account", and the turn then ran the
        // personal-ownership query to 0 rows. A fork is neither answered on that guess
        // nor labelled with it.
        $ctx = $this->context();

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')->never();

        $result = $this->manager(new ScriptedUsageLlm(json: self::FORK))->answerQuestion(self::QUESTION, ['user' => (object) ['id' => 13]]);

        $this->assertSame('clarification', $result['metadata']['query']['source'] ?? null);
        $this->assertNull($result['metadata']['subject'], 'the guessed subject ("My work card status") must not label an unresolved question');
        $this->assertArrayNotHasKey('entity_types', $result['metadata'], 'nothing for the next turn to focus on');
        $this->assertStringNotContainsString('my', strtolower($result['answer']));
    }

    /** @test */
    public function the_clarifying_question_follows_the_question_language_reported_by_understanding(): void
    {
        $llm = new ScriptedUsageLlm(json: ['question' => 'Voulez-vous le statut d\'un avion ou d\'une carte de travail ?'] + self::FORK);
        $ctx = $this->context(['language' => 'fr'], 'Quel est le statut ?');

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $result = $this->manager($llm)->answerQuestion('Quel est le statut ?', ['user' => (object) ['id' => 13]]);

        $this->assertSame('Voulez-vous le statut d\'un avion ou d\'une carte de travail ?', $result['answer']);
        $this->assertStringContainsString('(written in "fr")', $llm->calls[0][1]['content']);
    }

    /** @test */
    public function readings_without_a_usable_question_ask_the_lang_key_fallback_in_the_questions_language(): void
    {
        app()->setLocale('en');
        $llm = new ScriptedUsageLlm(json: [
            'readings' => ['le statut d\'un avion', 'le statut d\'une carte de travail'],
            'question' => '',
        ] + self::FORK);
        $ctx = $this->context(['language' => 'fr'], 'Quel est le statut ?');

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')->never();

        $result = $this->manager($llm)->answerQuestion('Quel est le statut ?', ['user' => (object) ['id' => 13]]);

        $this->assertStringContainsString('Pouvez-vous préciser', $result['answer']);
        $this->assertStringContainsString('le statut d\'un avion', $result['answer']);
    }

    /**
     * @test
     * @dataProvider turnsWhosePreconditionsFail
     */
    public function no_clarity_call_is_made_when_a_precondition_fails(array $quOverrides, string $question, array $options): void
    {
        $llm = new ScriptedUsageLlm(json: self::FORK);
        $ctx = $this->context($quOverrides, $question);
        $this->expectNormalTurn($ctx);
        $observer = new RecordingPipelineObserver();

        $result = $this->manager($llm)->answerQuestion($question, $options + ['user' => (object) ['id' => 13], 'observer' => $observer]);

        $this->assertNotSame('clarification', $result['metadata']['query']['source'] ?? null);
        $this->assertNotSame(self::FORK['question'], $result['answer']);
        $this->assertSame([], $llm->calls, 'the focused call is not even made');
        $this->assertNotContains('clarity_check', $observer->finishedStages());
    }

    public static function turnsWhosePreconditionsFail(): array
    {
        return [
            'the question names a kind of record' => [[], 'What is the status of the aircraft?', []],
            'the question names a synonym of one' => [[], 'What is the status of the plane?', []],
            'a person is named' => [['personNames' => ['Ana Diaz']], self::QUESTION, []],
            'a conversation is under way' => [[], self::QUESTION, ['conversation_context' => [
                'recent_exchanges' => [
                    ['role' => 'user', 'question' => 'List the grounded aircraft', 'answer_summary' => null],
                    ['role' => 'assistant', 'question' => null, 'answer_summary' => 'Two aircraft are grounded.'],
                ],
            ]]],
            'a result is recorded' => [[], self::QUESTION, ['conversation_context' => ['last_result_count' => 2]]],
            'the chat was opened from a page' => [[], self::QUESTION, ['page_context' => ['route' => 'aircraft.show', 'entity_type' => 'Aircraft', 'entity_id' => 7]]],
            'an export ask' => [['exportFormat' => 'excel'], self::QUESTION, []],
            'a follow-up' => [['followUpReference' => true], self::QUESTION, []],
            'QU failed' => [['failed' => true], self::QUESTION, []],
        ];
    }

    /** @test */
    public function a_question_for_documents_makes_no_clarity_call(): void
    {
        $llm = new ScriptedUsageLlm(json: self::FORK);
        $ctx = $this->context(['questionType' => 'knowledge_query'], 'Can I do this myself?');

        $this->expectNormalTurn($ctx);

        $result = $this->manager($llm)->answerQuestion('Can I do this myself?', ['user' => (object) ['id' => 13]]);

        $this->assertSame('normal answer', $result['answer']);
        $this->assertSame([], $llm->calls);
    }

    /** @test */
    public function the_feature_switch_stops_the_call(): void
    {
        config(['ai.understanding.clarify_enabled' => false]);
        $llm = new ScriptedUsageLlm(json: self::FORK);
        $this->expectNormalTurn($this->context());

        $result = $this->manager($llm)->answerQuestion(self::QUESTION, ['user' => (object) ['id' => 13]]);

        $this->assertSame('normal answer', $result['answer']);
        $this->assertSame([], $llm->calls);
    }

    /** @test */
    public function a_question_the_checker_finds_clear_runs_the_normal_pipeline(): void
    {
        // The question states its owner ("my"): the call is made (nothing structural says
        // so), finds it, and the turn is answered.
        $llm = new ScriptedUsageLlm(json: ['first_person' => ['my'], 'forms' => ['possessive']] + self::FORK);
        $ctx = $this->context([], 'What is my status?');
        $this->expectNormalTurn($ctx);
        $observer = new RecordingPipelineObserver();

        $result = $this->manager($llm)->answerQuestion('What is my status?', ['user' => (object) ['id' => 13], 'observer' => $observer]);

        $this->assertSame('normal answer', $result['answer']);
        $this->assertCount(1, $llm->calls);
        $stage = $observer->stageData('clarity_check');
        $this->assertFalse($stage['ask']);
        $this->assertSame('owner_stated', $stage['reason']);
        $this->assertNotContains('clarify', $observer->finishedStages());
    }

    /** @test */
    public function a_failing_clarity_call_never_costs_the_turn(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andThrow(new \RuntimeException('provider timeout'));
        $this->expectNormalTurn($this->context());
        $observer = new RecordingPipelineObserver();

        $result = $this->manager($llm)->answerQuestion(self::QUESTION, ['user' => (object) ['id' => 13], 'observer' => $observer]);

        $this->assertSame('normal answer', $result['answer']);
        $this->assertContains(
            ['stage' => 'clarity_check', 'class' => \RuntimeException::class, 'message' => 'provider timeout'],
            $observer->errors()
        );
        $this->assertSame('failed', $observer->stageData('clarity_check')['reason']);
    }

    /** @test */
    public function without_a_clarity_checker_the_pipeline_is_unchanged(): void
    {
        $this->expectNormalTurn($this->context());
        $observer = new RecordingPipelineObserver();

        $result = $this->manager(null)->answerQuestion(self::QUESTION, ['user' => (object) ['id' => 13], 'observer' => $observer]);

        $this->assertSame('normal answer', $result['answer']);
        $this->assertNotContains('clarity_check', $observer->finishedStages());
    }
}
