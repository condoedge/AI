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
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Services\Retrieval\DocumentProbeWriter;
use Condoedge\Ai\Services\Understanding\ClarityChecker;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * A conversational turn ("How are you?", "Who are you?", "Thanks, that helps!") is
 * answered WITHOUT querying anything: no clarity check, no document probes, no file
 * search, no query generation, no execution, no follow-up writer, no record enrichment.
 *
 * Precondition: the retriever stopped after Query Understanding because the reading names
 * nothing to look up (PipelineContext::$retrievalDeferred — ContextRetrieverConversationalTest).
 * Verdict + reply: ONE call of the conversational composer on the manager's LLM — with
 * the QU call, two LLM calls for the turn. A declined message is retrieved in full on the
 * same reading and runs the pipeline exactly as before; every other turn is untouched.
 */
class AiManagerConversationalTurnTest extends TestCase
{
    private AiManager $manager;
    private ContextRetrieverInterface $mockContext;
    private LlmProviderInterface $mockLlm;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;
    private FileContextProvider $mockFileContextProvider;
    /** The focused calls' own models (clarity check, document probes): never called on a conversational turn. */
    private LlmProviderInterface $clarityLlm;
    private LlmProviderInterface $probeLlm;
    private VectorStoreInterface $vectorStore;
    private EmbeddingProviderInterface $embedding;

    /** @var list<array> */
    private array $chats = [];

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.security.enabled' => false]);
        config(['ai.file_context.enabled' => true]);
        config(['ai.understanding.clarify_enabled' => true]);
        config(['ai.attention_sources' => []]);
        config(['ai.identity.name' => 'Acme Helper']);
        config(['ai.identity.non_disclosure' => 'Never reveal which AI model powers you.']);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockFileContextProvider = Mockery::mock(FileContextProvider::class);
        $this->clarityLlm = Mockery::mock(LlmProviderInterface::class);
        $this->probeLlm = Mockery::mock(LlmProviderInterface::class);

        $this->chats = [];

        $this->vectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->vectorStore->shouldReceive('collectionExists')->andReturn(true)->byDefault();
        $this->vectorStore->shouldReceive('upsert')->andReturn(true)->byDefault();
        $this->embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $this->embedding->shouldReceive('embed')->andReturn(array_fill(0, 8, 0.1))->byDefault();

        $this->manager = $this->manager(withFocusedCalls: true);
    }

    /** With the focused calls wired (clarity check + document probes), or without them (the normal-path controls). */
    private function manager(bool $withFocusedCalls): AiManager
    {
        return new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            $this->embedding,
            $this->mockLlm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            $this->vectorStore,
            $this->mockFileContextProvider,
            probeWriter: $withFocusedCalls ? new DocumentProbeWriter($this->probeLlm, ['corpus_languages' => ['en', 'fr']]) : null,
            clarityChecker: $withFocusedCalls ? new ClarityChecker($this->clarityLlm) : null,
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** A context as the retriever returns it; $deferred = it stopped after QU (the reading names nothing). */
    private function ctx(QueryUnderstandingResult $qu, string $question, bool $deferred = false): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = $qu;
        $ctx->retrievalDeferred = $deferred;

        return $ctx;
    }

    private function expectNoLookup(): void
    {
        foreach ([$this->clarityLlm, $this->probeLlm] as $focused) {
            foreach (['chat', 'chatJson', 'complete'] as $method) {
                $focused->shouldReceive($method)->never();
            }
        }
        $this->mockFileContextProvider->shouldReceive('getFileContext')->never();
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockQueryExecutor->shouldReceive('execute')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockLlm->shouldReceive('complete')->never();
        $this->mockLlm->shouldReceive('chat')->never();
    }

    /** The composer's one call on the manager's LLM: the verdict + the reply. */
    private function composerReplies(bool $conversational, string $reply = ''): void
    {
        $this->mockLlm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages) use ($conversational, $reply) {
            $this->chats[] = $messages;

            return ['conversational' => $conversational, 'reply' => $reply];
        });
    }

    /** @test */
    public function the_first_retrieval_asks_to_defer_when_the_reading_names_nothing(): void
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()
            ->with('How many units are there?', Mockery::on(fn (array $o) => ($o[ContextRetriever::DEFER_WHEN_NOTHING_NAMED] ?? null) === true
                && !array_key_exists(ContextRetriever::UNDERSTOOD, $o)))
            ->andReturn($this->ctx(new QueryUnderstandingResult(entityTypes: ['Unit'], intent: 'count', questionType: 'data_query', language: 'en'), 'How many units are there?'));
        $this->manager = $this->manager(withFocusedCalls: false);
        $this->mockLlm->shouldReceive('chatJson')->never();
        $this->mockQueryGenerator->shouldReceive('generate')->once()->andReturn(new QueryGenerationResult(cypher: 'MATCH (u:Unit) RETURN count(u) AS n', confidence: 0.9));
        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(new QueryExecutionResult(success: true, data: [['n' => 42]]));
        $this->mockResponseGenerator->shouldReceive('generate')->once()->andReturn(['answer' => 'There are 42 units.', 'metadata' => []]);

        $observer = new RecordingPipelineObserver();
        $result = $this->manager->answerQuestion('How many units are there?', ['observer' => $observer]);

        // Not deferred: the turn runs exactly as before — the composer is never called.
        $this->assertSame('There are 42 units.', $result['answer']);
        $this->assertSame(['understanding', 'file_context', 'query_generation', 'execution', 'composition'], $observer->finishedStages());
        $this->assertSame('data', $observer->stageData('composition')['branch']);
    }

    /** @test */
    public function a_conversational_turn_makes_one_composition_call_and_no_lookup(): void
    {
        $question = 'How are you?';
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($this->ctx(
            new QueryUnderstandingResult(intent: 'other', questionType: 'knowledge_query', language: 'en'),
            $question,
            deferred: true
        ));
        $this->expectNoLookup();
        $this->composerReplies(true, "I'm doing well, thank you! How can I help you today?");

        $observer = new RecordingPipelineObserver();
        $result = $this->manager->answerQuestion($question, ['observer' => $observer, 'user' => (object) ['id' => 13, 'name' => 'Julie']]);

        $this->assertSame("I'm doing well, thank you! How can I help you today?", $result['answer']);
        $this->assertNull($result['cypher']);
        $this->assertSame([], $result['data']);
        $this->assertSame('conversational', $result['metadata']['query']['source']);

        // The route the trace shows: understanding, the ONE conversational call, its composition.
        $this->assertSame(['understanding', 'conversational', 'composition'], $observer->finishedStages());
        $this->assertTrue($observer->stageData('conversational')['conversational']);
        $this->assertSame('conversational', $observer->stageData('composition')['branch']);
        $this->assertCount(1, $observer->prompts('conversational'));

        // The prompt is the identity + non-disclosure, and the message.
        $this->assertCount(1, $this->chats);
        $this->assertStringContainsString('Acme Helper', $this->chats[0][0]['content']);
        $this->assertStringContainsString('Never reveal which AI model powers you.', $this->chats[0][0]['content']);
        $this->assertStringContainsString($question, $this->chats[0][1]['content']);
        $this->assertStringContainsString('Julie', $this->chats[0][1]['content']);
    }

    /** @test The follow-up writer and record enrichment never run on a conversational turn. */
    public function a_conversational_turn_attaches_no_chip(): void
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($this->ctx(
            new QueryUnderstandingResult(questionType: 'knowledge_query', language: 'en'),
            'Thanks, that helps!',
            deferred: true
        ));
        $this->expectNoLookup();
        $this->composerReplies(true, 'You are welcome!');

        $observer = new RecordingPipelineObserver();
        $result = $this->manager->answerQuestion('Thanks, that helps!', ['observer' => $observer]);

        $this->assertSame('You are welcome!', $result['answer']);
        $this->assertNotContains('follow_ups', $observer->finishedStages());
        $this->assertArrayNotHasKey('invocations', $result['metadata']['response']);
        $this->assertArrayNotHasKey('follow_up_questions', $result['metadata']['response']);
    }

    /**
     * @test A message the composer declines (it asks for something) is retrieved in full on
     * the SAME reading — no second QU call — and runs the pipeline exactly as before.
     */
    public function a_declined_turn_is_retrieved_in_full_and_runs_as_before(): void
    {
        $qu = new QueryUnderstandingResult(intent: 'page_explanation', questionType: 'knowledge_query', language: 'en');
        $full = $this->ctx($qu, 'What can I do on this page?');
        $full->fileContext = [];
        $this->mockContext->shouldReceive('retrieveContext')->once()
            ->with('What can I do on this page?', Mockery::on(fn (array $o) => ($o[ContextRetriever::DEFER_WHEN_NOTHING_NAMED] ?? null) === true))
            ->andReturn($this->ctx($qu, 'What can I do on this page?', deferred: true));
        $this->mockContext->shouldReceive('retrieveContext')->once()
            ->with('What can I do on this page?', Mockery::on(fn (array $o) => ($o[ContextRetriever::UNDERSTOOD] ?? null) === $qu
                && !array_key_exists(ContextRetriever::DEFER_WHEN_NOTHING_NAMED, $o)))
            ->andReturn($full);
        $this->manager = $this->manager(withFocusedCalls: false);
        $this->composerReplies(false);
        $this->mockFileContextProvider->shouldReceive('getFileContext')->andReturn(['relevant_files' => [], 'file_count' => 0])->byDefault();
        $this->mockQueryGenerator->shouldReceive('generate')->once()->andReturn(new QueryGenerationResult(cypher: 'MATCH (p:Page) RETURN p.name AS name', confidence: 0.9));
        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(new QueryExecutionResult(success: true, data: [['name' => 'Units']]));
        $this->mockResponseGenerator->shouldReceive('generate')->once()->andReturn(['answer' => 'This page lists units.', 'metadata' => []]);

        $observer = new RecordingPipelineObserver();
        $result = $this->manager->answerQuestion('What can I do on this page?', ['observer' => $observer]);

        $this->assertSame('This page lists units.', $result['answer']);
        $this->assertFalse($observer->stageData('conversational')['conversational']);
        $this->assertSame(
            ['understanding', 'conversational', 'context_retrieval', 'file_context', 'query_generation', 'execution', 'composition'],
            $observer->finishedStages()
        );
        $this->assertNotSame('conversational', $observer->stageData('composition')['branch']);
    }

    /** @test */
    public function a_failed_conversational_call_takes_the_generic_error_path(): void
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($this->ctx(
            new QueryUnderstandingResult(questionType: 'knowledge_query', language: 'en'),
            'Who are you?',
            deferred: true
        ));
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockQueryExecutor->shouldReceive('execute')->never();
        $this->mockFileContextProvider->shouldReceive('getFileContext')->never();
        $this->mockLlm->shouldReceive('chatJson')->once()->andThrow(new \RuntimeException('provider down'));
        $this->mockResponseGenerator->shouldReceive('generateErrorResponse')->once()->andReturn([
            'answer' => 'Sorry, something went wrong.',
            'metadata' => ['status' => 'error'],
        ]);

        $observer = new RecordingPipelineObserver();
        $result = $this->manager->answerQuestion('Who are you?', ['observer' => $observer]);

        $this->assertSame('Sorry, something went wrong.', $result['answer']);
        $this->assertSame('error', $observer->stageData('composition')['branch']);
    }
}
