<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\PipelineObserverInterface;
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
use Condoedge\Ai\Support\NullPipelineObserver;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Phase 4.1 — the PipelineObserver seam on the PRODUCTION pipeline.
 *
 * A RecordingPipelineObserver passed via options['observer'] must see the
 * stage sequence of the branch production actually took, per branch. The
 * observer is observation only: every assertion on the returned answer stays
 * what the existing branch tests already pin.
 */
class AiManagerPipelineObserverTest extends TestCase
{
    private AiManager $manager;
    private ContextRetrieverInterface $mockContext;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;
    private FileContextProvider $mockFileContextProvider;
    private LlmProviderInterface $mockLlm;

    /** @var array<int, array<string, mixed>> */
    private array $files = [];

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.security.enabled' => false]);
        config(['ai.file_context.enabled' => true]);
        config(['ai.understanding.clarify_enabled' => true]);
        config(['ai.attention_sources' => []]);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockFileContextProvider = Mockery::mock(FileContextProvider::class);
        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);

        $this->files = [];
        $this->mockFileContextProvider->shouldReceive('getFileContext')
            ->andReturnUsing(fn () => ['relevant_files' => $this->files, 'file_count' => count($this->files)]);

        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $vectorStore->shouldReceive('collectionExists')->andReturn(true)->byDefault();
        $vectorStore->shouldReceive('upsert')->andReturn(true)->byDefault();
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn(array_fill(0, 8, 0.1))->byDefault();

        $this->manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            $embedding,
            $this->mockLlm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            $vectorStore,
            $this->mockFileContextProvider
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function ctx(QueryUnderstandingResult $qu, string $question = 'How many units are there?'): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = $qu;
        $ctx->graphSchema = ['labels' => ['Unit']];

        return $ctx;
    }

    /** @test */
    public function the_default_binding_is_the_null_observer(): void
    {
        $this->assertInstanceOf(NullPipelineObserver::class, app(PipelineObserverInterface::class));
    }

    /** @test */
    public function a_normal_data_turn_emits_understanding_file_context_generation_execution_composition(): void
    {
        $qu = new QueryUnderstandingResult(entityTypes: ['Unit'], searchQuery: 'units count', intent: 'count', questionType: 'data_query', language: 'en');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($this->ctx($qu));
        $this->mockQueryGenerator->shouldReceive('generate')->once()->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (u:Unit) RETURN count(u) AS n',
            confidence: 0.9,
            metadata: ['retry_count' => 1, 'repair_attempts' => 1, 'precedence_repaired' => ['u.a = 1 OR u.b = 2 AND u.c = 3']],
        ));
        $this->mockQueryExecutor->shouldReceive('execute')->once()->andReturn(new QueryExecutionResult(
            success: true,
            data: [['n' => 42]],
        ));
        $this->mockResponseGenerator->shouldReceive('generate')->once()->andReturn([
            'answer' => 'There are 42 units.',
            'metadata' => [],
        ]);

        $observer = new RecordingPipelineObserver();
        $result = $this->manager->answerQuestion('How many units are there?', ['observer' => $observer]);

        $this->assertSame('There are 42 units.', $result['answer']);
        $this->assertSame(
            ['understanding', 'file_context', 'query_generation', 'execution', 'composition'],
            $observer->finishedStages()
        );
        $this->assertSame($observer->finishedStages(), $observer->startedStages(), 'every started stage finishes');

        $understanding = $observer->stageData('understanding');
        $this->assertSame('data_query', $understanding['qu']['question_type']);
        $this->assertSame(['Unit'], $understanding['qu']['entity_types']);

        $this->assertSame('question_type', $observer->stageData('file_context')['skipped']);

        $generation = $observer->stageData('query_generation');
        $this->assertSame('MATCH (u:Unit) RETURN count(u) AS n', $generation['cypher']);
        $this->assertSame(2, $generation['attempts']);
        // Eval p22-B: an OR / AND predicate sent back for parentheses shows in the stage trace.
        $this->assertSame(1, $generation['repair_attempts']);
        $this->assertSame(['u.a = 1 OR u.b = 2 AND u.c = 3'], $generation['precedence_repaired']);
        $this->assertSame([], $generation['precedence_violations']);

        $execution = $observer->stageData('execution');
        $this->assertSame(1, $execution['row_count']);
        $this->assertTrue($execution['success']);
        $this->assertSame([], $execution['errors']);

        $composition = $observer->stageData('composition');
        $this->assertSame('data', $composition['branch']);
        $this->assertSame(mb_strlen('There are 42 units.'), $composition['answer_length']);
        $this->assertSame([], $observer->errors());

        // The observer object never leaks into downstream options.
        $this->mockQueryExecutor->shouldHaveReceived('execute')->with(
            Mockery::any(),
            Mockery::any(),
            Mockery::on(fn (array $o) => !array_key_exists('observer', $o))
        );
    }

    /** @test */
    public function a_clarify_turn_emits_understanding_clarify_composition_only(): void
    {
        $qu = new QueryUnderstandingResult(
            searchQuery: 'balance',
            questionType: 'data_query',
            language: 'en',
            needsClarification: true,
            candidateReadings: ['Your account', 'The syndicate'],
            clarifyingQuestion: 'Which balance do you mean?',
        );
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($this->ctx($qu, 'Show me the balance'));
        $this->mockQueryGenerator->shouldReceive('generate')->never();

        $observer = new RecordingPipelineObserver();
        $result = $this->manager->answerQuestion('Show me the balance', ['observer' => $observer]);

        $this->assertSame('Which balance do you mean?', $result['answer']);
        $this->assertSame(['understanding', 'clarify', 'composition'], $observer->finishedStages());
        $this->assertSame(2, $observer->stageData('clarify')['candidate_readings']);
        $this->assertSame('clarify', $observer->stageData('composition')['branch']);
    }

    /** @test */
    public function a_doc_only_knowledge_turn_emits_file_context_then_knowledge_query_then_composition(): void
    {
        $this->files = [[
            'file_id' => 77,
            'filename' => 'bylaws.pdf',
            'snippet' => 'Pets are allowed.',
            'relevance' => 0.83,
            'chunk_index' => 4,
            'source' => 'database',
        ]];
        $qu = new QueryUnderstandingResult(searchQuery: 'pet policy', intent: 'lookup', questionType: 'knowledge_query', language: 'en');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($this->ctx($qu, 'Are pets allowed?'));
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->once()->andReturn([
            'answer' => 'Yes, pets are allowed [1].',
            'metadata' => [],
        ]);

        $observer = new RecordingPipelineObserver();
        $this->manager->answerQuestion('Are pets allowed?', ['observer' => $observer]);

        $this->assertSame(['understanding', 'file_context', 'knowledge_query', 'composition'], $observer->finishedStages());

        $fileContext = $observer->stageData('file_context');
        $this->assertSame(77, $fileContext['files'][0]['id']);
        $this->assertSame(4, $fileContext['files'][0]['chunk_index']);
        $this->assertEqualsWithDelta(0.83, $fileContext['files'][0]['relevance'], 1e-9);
        $this->assertContains('pet policy', $fileContext['angles']);

        $this->assertSame('knowledge_query', $observer->stageData('composition')['branch']);
    }

    /** @test */
    public function an_execution_error_emits_stage_error_and_composition_still_runs(): void
    {
        $qu = new QueryUnderstandingResult(entityTypes: ['Unit'], searchQuery: 'units', questionType: 'data_query', language: 'en');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($this->ctx($qu));
        $this->mockQueryGenerator->shouldReceive('generate')->once()->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (u:Unit) RETURN u',
            confidence: 0.9,
        ));
        $this->mockQueryExecutor->shouldReceive('execute')->once()->andThrow(new \RuntimeException('Neo4j SyntaxError near RETURN'));
        $this->mockResponseGenerator->shouldReceive('generateErrorResponse')->once()->andReturn([
            'answer' => 'I encountered an issue.',
            'metadata' => ['error' => true],
        ]);

        $observer = new RecordingPipelineObserver();
        $result = $this->manager->answerQuestion('How many units are there?', ['observer' => $observer]);

        $this->assertSame('I encountered an issue.', $result['answer']);
        $this->assertSame(
            ['understanding', 'file_context', 'query_generation', 'execution', 'composition'],
            $observer->finishedStages()
        );

        $errors = $observer->errors();
        $this->assertCount(1, $errors);
        $this->assertSame('execution', $errors[0]['stage']);
        $this->assertSame(\RuntimeException::class, $errors[0]['class']);
        $this->assertStringContainsString('SyntaxError', $errors[0]['message']);

        $this->assertFalse($observer->stageData('execution')['success']);
        $this->assertSame('error', $observer->stageData('composition')['branch']);
    }

    /** @test */
    public function an_escaping_exception_is_attributed_to_its_stage_and_the_error_answer_is_composed(): void
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()->andThrow(new \RuntimeException('QU exploded'));
        $this->mockResponseGenerator->shouldReceive('generateErrorResponse')->once()->andReturn([
            'answer' => 'Something went wrong.',
            'metadata' => ['error' => true],
        ]);

        $observer = new RecordingPipelineObserver();
        $result = $this->manager->answerQuestion('Anything', ['observer' => $observer]);

        $this->assertSame('Something went wrong.', $result['answer']);
        $this->assertSame(['understanding', 'composition'], $observer->finishedStages());
        $this->assertSame('understanding', $observer->errors()[0]['stage']);
        $this->assertTrue($observer->stageData('understanding')['failed']);
        $this->assertSame('error', $observer->stageData('composition')['branch']);
    }

    /** @test */
    public function a_digest_turn_emits_digest_with_sources_run_and_failed(): void
    {
        config(['ai.attention_sources' => [
            'open_tasks' => [
                'label' => 'Open tasks',
                'description' => 'Tasks not yet closed.',
                'cypher' => 'MATCH (t:Task) RETURN t.id AS id',
                'limit' => 5,
            ],
            'late_bills' => [
                'label' => 'Late bills',
                'description' => 'Unpaid bills.',
                'cypher' => 'MATCH (b:Bill) RETURN b.id AS id',
                'limit' => 5,
            ],
        ]]);
        $qu = new QueryUnderstandingResult(searchQuery: 'attention', intent: 'list', questionType: 'data_query', language: 'en', digestRequest: true);
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($this->ctx($qu, 'What needs my attention?'));
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockQueryExecutor->shouldReceive('execute')->andReturnUsing(function (?string $cypher) {
            if (str_contains((string) $cypher, 'Bill')) {
                throw new \RuntimeException('bills source down');
            }

            return new QueryExecutionResult(success: true, data: [['id' => 1]]);
        });
        $this->mockLlm->shouldReceive('chat')->once()->andReturn('Your digest.');

        $observer = new RecordingPipelineObserver();
        $result = $this->manager->answerQuestion('What needs my attention?', ['observer' => $observer]);

        // p12 H1: the model frames; code renders the sections and the could-not-be-checked line.
        $this->assertStringStartsWith('Your digest.', $result['answer']);
        $this->assertStringContainsString('Late bills could not be checked right now.', $result['answer']);
        $this->assertSame(['understanding', 'digest', 'composition'], $observer->finishedStages());

        $digest = $observer->stageData('digest');
        $this->assertSame(2, $digest['sources_run']);
        $this->assertSame(['late_bills'], $digest['sources_failed']);
        $this->assertSame('digest', $observer->stageData('composition')['branch']);

        // The digest composer's ONE call reports its verbatim messages.
        $prompts = $observer->prompts('composition');
        $this->assertCount(1, $prompts);
        $this->assertSame('system', $prompts[0][0]['role']);
        $this->assertNotSame('', $prompts[0][1]['content']);
    }

    /** @test */
    public function an_export_turn_emits_the_export_stage_with_its_source(): void
    {
        $qu = new QueryUnderstandingResult(entityTypes: ['Unit'], searchQuery: 'units', questionType: 'data_query', exportFormat: 'excel', language: 'en');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($this->ctx($qu, 'Excel of all units'));
        $this->mockQueryGenerator->shouldReceive('generate')->once()->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (u:Unit) RETURN u.name AS name',
            confidence: 0.9,
        ));
        $this->mockQueryExecutor->shouldReceive('execute')->once()->andReturn(new QueryExecutionResult(
            success: true,
            data: [['name' => 'A'], ['name' => 'B']],
        ));

        $observer = new RecordingPipelineObserver();
        $this->manager->answerQuestion('Excel of all units', ['observer' => $observer]);

        $this->assertSame(
            ['understanding', 'file_context', 'query_generation', 'execution', 'export', 'composition'],
            $observer->finishedStages()
        );
        $this->assertSame('current_result', $observer->stageData('export')['export_source']);
        $this->assertSame('excel', $observer->stageData('export')['format']);
        $this->assertSame('export', $observer->stageData('composition')['branch']);
        // The exported rows themselves (first 10) ride the in-memory stage data.
        $this->assertSame([['name' => 'A'], ['name' => 'B']], $observer->stageData('export')['sample']);
    }

    /** @test */
    public function a_scripted_provider_with_usage_feeds_totals_into_the_message_trace(): void
    {
        config(['ai.attention_sources' => [
            'open_tasks' => ['label' => 'Open tasks', 'description' => 'Open.', 'cypher' => 'MATCH (t:Task) RETURN t.id AS id', 'limit' => 5],
        ]]);
        $llm = new \Condoedge\Ai\Tests\Fixtures\ScriptedUsageLlm(text: 'Your digest.');
        $manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            Mockery::mock(EmbeddingProviderInterface::class),
            $llm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            Mockery::mock(VectorStoreInterface::class),
            $this->mockFileContextProvider
        );
        $qu = new QueryUnderstandingResult(searchQuery: 'attention', questionType: 'data_query', language: 'en', digestRequest: true);
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($this->ctx($qu, 'What needs my attention?'));
        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(new QueryExecutionResult(success: true, data: [['id' => 1]]));

        $trace = new \Condoedge\Ai\Services\Chat\Support\MessageTraceObserver();
        $manager->answerQuestion('What needs my attention?', ['observer' => $trace]);

        $out = $trace->toArray();
        $this->assertSame(['input' => 100, 'output' => 20, 'total' => 120, 'calls' => 1], $out['usage']['by_stage']['composition']);
        $this->assertSame(120, $out['usage']['total']['total']);
        $this->assertSame(['scripted-model'], $out['usage']['models']);
        $this->assertSame(['understanding', 'digest', 'composition'], array_column($out['stages'], 'stage'));
    }

    /**
     * Run the same turn with the null observer and with an observer that throws
     * from every method; return both answer arrays.
     *
     * @return array{0: array, 1: array}
     */
    private function answerWithNullAndThrowing(string $question): array
    {
        $null = $this->manager->answerQuestion($question, ['observer' => new NullPipelineObserver()]);
        $throwing = $this->manager->answerQuestion($question, ['observer' => new \Condoedge\Ai\Tests\Fixtures\ThrowingPipelineObserver()]);

        return [$null, $throwing];
    }

    /** @test */
    public function a_throwing_observer_leaves_a_data_turn_byte_identical(): void
    {
        $qu = new QueryUnderstandingResult(entityTypes: ['Unit'], searchQuery: 'units count', intent: 'count', questionType: 'data_query', language: 'en');
        $this->mockContext->shouldReceive('retrieveContext')->twice()->andReturnUsing(fn () => $this->ctx($qu));
        $this->mockQueryGenerator->shouldReceive('generate')->twice()->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (u:Unit) RETURN count(u) AS n',
            confidence: 0.9,
        ));
        $this->mockQueryExecutor->shouldReceive('execute')->twice()->andReturn(new QueryExecutionResult(success: true, data: [['n' => 42]]));
        $this->mockResponseGenerator->shouldReceive('generate')->twice()->andReturn(['answer' => 'There are 42 units.', 'metadata' => []]);

        [$null, $throwing] = $this->answerWithNullAndThrowing('How many units are there?');

        $this->assertSame('There are 42 units.', $null['answer']);
        $this->assertSame(serialize($null), serialize($throwing));
    }

    /** @test */
    public function a_throwing_observer_leaves_an_execution_error_turn_byte_identical(): void
    {
        $qu = new QueryUnderstandingResult(entityTypes: ['Unit'], searchQuery: 'units', questionType: 'data_query', language: 'en');
        $this->mockContext->shouldReceive('retrieveContext')->twice()->andReturnUsing(fn () => $this->ctx($qu));
        $this->mockQueryGenerator->shouldReceive('generate')->twice()->andReturn(new QueryGenerationResult(cypher: 'MATCH (u:Unit) RETURN u', confidence: 0.9));
        $this->mockQueryExecutor->shouldReceive('execute')->twice()->andThrow(new \RuntimeException('Neo4j SyntaxError'));
        $this->mockResponseGenerator->shouldReceive('generateErrorResponse')->twice()->andReturn(['answer' => 'I encountered an issue.', 'metadata' => ['error' => true]]);

        [$null, $throwing] = $this->answerWithNullAndThrowing('How many units are there?');

        $this->assertSame('I encountered an issue.', $null['answer']);
        $this->assertSame(serialize($null), serialize($throwing));
    }

    /** @test */
    public function a_throwing_observer_leaves_an_escaping_error_turn_byte_identical(): void
    {
        $this->mockContext->shouldReceive('retrieveContext')->twice()->andThrow(new \RuntimeException('QU exploded'));
        $this->mockResponseGenerator->shouldReceive('generateErrorResponse')->twice()->andReturn(['answer' => 'Something went wrong.', 'metadata' => ['error' => true]]);

        [$null, $throwing] = $this->answerWithNullAndThrowing('Anything');

        $this->assertSame('Something went wrong.', $null['answer']);
        $this->assertSame(serialize($null), serialize($throwing));
    }

    /** @test */
    public function without_an_observer_option_the_pipeline_runs_unchanged(): void
    {
        $qu = new QueryUnderstandingResult(searchQuery: 'balance', needsClarification: true, clarifyingQuestion: 'Which one?');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($this->ctx($qu));

        $result = $this->manager->answerQuestion('Show the balance');

        $this->assertSame('Which one?', $result['answer']);
    }
}
