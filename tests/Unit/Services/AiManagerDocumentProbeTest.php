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
use Condoedge\Ai\Services\Retrieval\DocumentProbeWriter;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\Fixtures\ScriptedUsageLlm;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The focused document-search probe call in the pipeline (eval p15 decision).
 *
 * The probes used to be two output keys of EVERY Query-Understanding call. They
 * are now written by DocumentProbeWriter, called from the file-context stage
 * ONLY when document search actually runs for the turn — never on a skipped
 * (data) turn, a file-listing turn, or when QU itself failed — and they reach
 * the search through the same fan-out angles as before. A failing probe call
 * costs the turn nothing but the probes.
 */
class AiManagerDocumentProbeTest extends TestCase
{
    /** The probe writer's reply: one key per requested probe. */
    private const PROBES = [
        'fr_1' => 'sonde formelle un',
        'fr_2' => 'sonde formelle deux',
        'en_1' => 'formal probe one',
        'en_2' => 'formal probe two',
    ];

    private ContextRetrieverInterface $mockContext;
    private EmbeddingProviderInterface $mockEmbedding;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;
    private VectorStoreInterface $mockVectorStore;
    private FileContextProvider $mockFileContextProvider;

    /** @var string[] Every query the fan-out sent to the file context provider. */
    private array $capturedQueries = [];

    public function setUp(): void
    {
        parent::setUp();

        config(['logging.default' => 'null']);
        config(['ai.security.enabled' => false]);
        config(['ai.file_context.enabled' => true]);
        config(['ai.file_context.skip_for_question_types' => ['data_query']]);
        // The fake provider finds nothing; the recall-floor retry would only repeat every angle.
        config(['ai.file_context.recall_floor' => 0]);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockEmbedding = Mockery::mock(EmbeddingProviderInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->mockFileContextProvider = Mockery::mock(FileContextProvider::class);

        $this->capturedQueries = [];
        $this->mockFileContextProvider->shouldReceive('getFileContext')
            ->andReturnUsing(function ($query) {
                $this->capturedQueries[] = $query;

                return ['relevant_files' => [], 'file_count' => 0];
            });
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function manager(?LlmProviderInterface $probeLlm, array $writerConfig = []): AiManager
    {
        $writer = $probeLlm === null ? null : new DocumentProbeWriter($probeLlm, $writerConfig + [
            'corpus_languages' => ['fr', 'en'],
            'corpus_register' => 'aviation maintenance regulation terminology (airworthiness directive)',
        ]);

        return new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            $this->mockEmbedding,
            Mockery::mock(LlmProviderInterface::class),
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            $this->mockVectorStore,
            $this->mockFileContextProvider,
            probeWriter: $writer,
        );
    }

    private function context(QueryUnderstandingResult $qu, string $question = 'Can I just swap out this broken part myself?'): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = $qu;
        $ctx->similarQueries = [];
        $ctx->graphSchema = ['labels' => ['Unit']];

        return $ctx;
    }

    private function runTurn(AiManager $manager, PipelineContext $ctx, array $options = []): array
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn(array_fill(0, 8, 0.1));
        $this->mockEmbedding->shouldReceive('embedBatch')->andReturnUsing(
            static fn (array $texts): array => array_map(static fn () => array_fill(0, 8, 0.1), $texts)
        );

        $this->mockQueryGenerator->shouldReceive('generate')->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (u:Unit) RETURN u',
            explanation: '',
            confidence: 0.9,
            warnings: [],
            metadata: [],
        ));
        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(new QueryExecutionResult(
            success: true, data: [['id' => 1]], stats: [], metadata: [], errors: [],
        ));
        $this->mockResponseGenerator->shouldReceive('generate')->andReturn([
            'answer' => 'ok', 'insights' => [], 'visualizations' => [], 'metadata' => [],
        ]);

        return $manager->answerQuestion($ctx->question, $options + ['user' => (object) ['id' => 1]]);
    }

    private function probeLlm(): ScriptedUsageLlm
    {
        return new ScriptedUsageLlm(json: self::PROBES);
    }

    /** @test */
    public function a_knowledge_turn_writes_probes_once_and_searches_with_each_of_them(): void
    {
        $llm = $this->probeLlm();
        $ctx = $this->context(new QueryUnderstandingResult(searchQuery: 'swap broken part', questionType: 'knowledge_query'));

        $this->runTurn($this->manager($llm), $ctx);

        $this->assertCount(1, $llm->calls, 'ONE probe call per turn');
        foreach (['sonde formelle un', 'sonde formelle deux', 'formal probe one', 'formal probe two'] as $probe) {
            $this->assertContains($probe, $this->capturedQueries, "probe [{$probe}] must be a search angle");
        }
        $this->assertContains($ctx->question, $this->capturedQueries, 'the user\'s own wording stays an angle');
    }

    /**
     * @test
     * The probes replace the joined-tags search only. The clarified question keeps
     * searching (eval p16: without it four probes stating one hypothesis were four
     * of six fusion votes, and the passage the plain wording finds was evicted).
     */
    public function written_probes_replace_only_the_joined_tags_angle(): void
    {
        $ctx = $this->context(new QueryUnderstandingResult(
            searchQuery: 'swap broken part',
            questionType: 'knowledge_query',
            clarifiedQuestion: 'What are the rules for replacing a part yourself?',
            searchTags: ['part', 'replacement', 'maintenance'],
        ));

        $this->runTurn($this->manager($this->probeLlm()), $ctx);

        $this->assertSame(
            [
                'swap broken part',
                $ctx->question,
                'What are the rules for replacing a part yourself?',
                'sonde formelle un',
                'formal probe one',
                'sonde formelle deux',
                'formal probe two',
            ],
            $this->capturedQueries
        );
    }

    /**
     * @test
     * Eval p16-B: "Where can I add new members?" is an ask the APP answers (QU's
     * navigation_target is set). The probe writer rewrote it in the corpus register
     * all the same — law-style probes that filled three of five passages with the
     * law. The structural signal skips the call; the plain angles search, as they
     * did before the probe writer existed.
     */
    public function an_app_navigation_ask_never_pays_for_probes(): void
    {
        $llm = $this->probeLlm();
        $observer = new RecordingPipelineObserver();
        $ctx = $this->context(new QueryUnderstandingResult(
            searchQuery: 'add new team members',
            questionType: 'knowledge_query',
            clarifiedQuestion: 'Where in the application can I add new members to my team?',
            searchTags: ['team', 'members', 'add'],
            navigationTarget: 'own_team',
        ), 'Where can I add new members?');

        $this->runTurn($this->manager($llm), $ctx, ['observer' => $observer]);

        $this->assertSame([], $llm->calls, 'no probe call for an app how-to / navigation ask');
        $this->assertSame(
            ['add new team members', 'Where can I add new members?', 'Where in the application can I add new members to my team?', 'team members add'],
            $this->capturedQueries
        );
        $this->assertSame(['skipped' => 'navigation_target'], $observer->stageData('file_context')['search_probes']);
    }

    /** @test */
    public function without_written_probes_the_paraphrase_angles_still_search(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturn(['fr_1' => '   ', 'unrelated' => true]);
        $ctx = $this->context(new QueryUnderstandingResult(
            searchQuery: 'swap broken part',
            questionType: 'knowledge_query',
            clarifiedQuestion: 'What are the rules for replacing a part yourself?',
            searchTags: ['part', 'replacement', 'maintenance'],
        ));

        $this->runTurn($this->manager($llm), $ctx);

        $this->assertSame(
            ['swap broken part', $ctx->question, 'What are the rules for replacing a part yourself?', 'part replacement maintenance'],
            $this->capturedQueries
        );
    }

    /** @test */
    public function a_hybrid_turn_writes_probes_too(): void
    {
        $llm = $this->probeLlm();

        $this->runTurn($this->manager($llm), $this->context(new QueryUnderstandingResult(questionType: 'hybrid')));

        $this->assertCount(1, $llm->calls);
        $this->assertContains('formal probe one', $this->capturedQueries);
    }

    /** @test */
    public function a_data_turn_never_pays_for_probes(): void
    {
        $llm = $this->probeLlm();

        $this->runTurn($this->manager($llm), $this->context(new QueryUnderstandingResult(questionType: 'data_query')));

        $this->assertSame([], $llm->calls, 'file search is skipped, so no probe call is made');
        $this->assertSame([], $this->capturedQueries);
    }

    /** @test */
    public function a_question_type_the_host_skips_never_pays_for_probes(): void
    {
        config(['ai.file_context.skip_for_question_types' => ['data_query', 'hybrid']]);
        $llm = $this->probeLlm();

        $this->runTurn($this->manager($llm), $this->context(new QueryUnderstandingResult(questionType: 'hybrid')));

        $this->assertSame([], $llm->calls);
    }

    /** @test */
    public function a_file_listing_turn_never_pays_for_probes(): void
    {
        config(['ai.file_context.enumeration.enabled' => true]);
        config(['ai.file_context.enumeration.intents' => ['file_listing']]);
        $llm = $this->probeLlm();

        $this->mockFileContextProvider->shouldReceive('getFileEnumerationContext')->once()->andReturn([
            'relevant_files' => [
                ['file_id' => 10, 'filename' => 'manual.pdf', 'type' => 'pdf', 'snippet' => '', 'relevance' => null, 'listing_only' => true, 'source' => 'database', 'match_type' => 'enumeration'],
            ],
            'file_count' => 1,
            'has_physical' => false,
            'has_database' => true,
            'enumeration' => true,
        ]);

        $this->runTurn($this->manager($llm), $this->context(
            new QueryUnderstandingResult(intent: 'file_listing', questionType: 'knowledge_query'),
            'Which files can I see?'
        ));

        $this->assertSame([], $llm->calls);
    }

    /** @test */
    public function a_listing_turn_that_falls_back_to_search_still_writes_no_probes(): void
    {
        config(['ai.file_context.enumeration.enabled' => true]);
        config(['ai.file_context.enumeration.intents' => ['file_listing']]);
        $llm = $this->probeLlm();

        // Nothing enumerable: the turn falls through to the plain semantic search.
        $this->mockFileContextProvider->shouldReceive('getFileEnumerationContext')->once()->andReturn([]);

        $this->runTurn($this->manager($llm), $this->context(
            new QueryUnderstandingResult(intent: 'file_listing', questionType: 'knowledge_query'),
            'Which files can I see?'
        ));

        $this->assertSame([], $llm->calls, 'a request for the list of files has no passage to probe for');
        $this->assertNotSame([], $this->capturedQueries, 'the fall-through search still runs');
    }

    /** @test */
    public function no_probes_are_written_when_file_search_is_disabled(): void
    {
        config(['ai.file_context.enabled' => false]);
        $llm = $this->probeLlm();

        $this->runTurn($this->manager($llm), $this->context(new QueryUnderstandingResult(questionType: 'knowledge_query')));

        $this->assertSame([], $llm->calls);
    }

    /** @test */
    public function no_probes_are_written_when_query_understanding_itself_failed(): void
    {
        $llm = $this->probeLlm();

        // QU failed (provider down): do not queue a second call behind the same outage.
        $this->runTurn($this->manager($llm), $this->context(QueryUnderstandingResult::failed()));

        $this->assertSame([], $llm->calls);
        $this->assertNotSame([], $this->capturedQueries, 'document search still runs on the question itself');
    }

    /** @test */
    public function no_call_is_made_when_the_host_configured_neither_languages_nor_a_register(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldNotReceive('chatJson');
        $manager = $this->manager($llm, ['corpus_languages' => [], 'corpus_register' => '']);

        $this->runTurn($manager, $this->context(new QueryUnderstandingResult(questionType: 'knowledge_query')));

        $this->assertNotSame([], $this->capturedQueries);
    }

    /** @test */
    public function a_failing_probe_call_never_costs_the_turn(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andThrow(new \RuntimeException('provider timeout'));
        $observer = new RecordingPipelineObserver();
        $ctx = $this->context(new QueryUnderstandingResult(searchQuery: 'swap broken part', questionType: 'knowledge_query'));

        $result = $this->runTurn($this->manager($llm), $ctx, ['observer' => $observer]);

        $this->assertSame('ok', $result['answer']);
        $this->assertContains($ctx->question, $this->capturedQueries, 'the search ran on its other angles');
        $this->assertContains('swap broken part', $this->capturedQueries);
        $this->assertContains(
            ['stage' => 'search_probes', 'class' => \RuntimeException::class, 'message' => 'provider timeout'],
            $observer->errors()
        );
        $this->assertSame(0, $observer->stageData('file_context')['search_probes']['count']);
    }

    /** @test */
    public function the_probe_call_gets_the_question_its_standalone_form_and_its_language(): void
    {
        $llm = $this->probeLlm();
        $ctx = $this->context(new QueryUnderstandingResult(
            searchQuery: 'operator duty before dispatch',
            questionType: 'knowledge_query',
            clarifiedQuestion: 'What must the operator do before dispatch with an inoperative component?',
            language: 'en',
        ), 'tell me more about that duty');

        $this->runTurn($this->manager($llm), $ctx);

        $user = $llm->calls[0][1]['content'];
        $this->assertStringContainsString('tell me more about that duty', $user);
        $this->assertStringContainsString('What must the operator do before dispatch with an inoperative component?', $user);
        $this->assertStringContainsString('(written in "en")', $user);
    }

    /** @test */
    public function the_turn_trace_shows_the_probe_call(): void
    {
        $llm = $this->probeLlm();
        $observer = new RecordingPipelineObserver();

        $this->runTurn(
            $this->manager($llm),
            $this->context(new QueryUnderstandingResult(questionType: 'knowledge_query')),
            ['observer' => $observer]
        );

        $this->assertSame([$llm->calls[0]], $observer->prompts('search_probes'));
        $this->assertContains(['stage' => 'search_probes', 'usage' => ScriptedUsageLlm::USAGE], $observer->usages());

        $stage = $observer->stageData('file_context');
        $this->assertSame(4, $stage['search_probes']['count']);
        $this->assertArrayHasKey('ms', $stage['search_probes']);
        $this->assertContains('formal probe two', $stage['angles']);
    }

    /** @test */
    public function without_a_probe_writer_the_fan_out_is_unchanged(): void
    {
        $ctx = $this->context(new QueryUnderstandingResult(
            searchQuery: 'swap broken part',
            questionType: 'knowledge_query',
            clarifiedQuestion: 'clarified form',
            searchTags: ['part', 'replacement'],
        ));

        $this->runTurn($this->manager(null), $ctx);

        $this->assertSame(
            ['swap broken part', $ctx->question, 'clarified form', 'part replacement'],
            $this->capturedQueries
        );
    }
}
