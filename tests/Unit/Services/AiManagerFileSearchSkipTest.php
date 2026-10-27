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
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Data-query latency skip: when the QU question_type is in
 * ai.file_context.skip_for_question_types (default ['data_query']), file search
 * is skipped ENTIRELY — no fan-out and, structurally, no recall-floor retry, so
 * borderline chunks can never be admitted into a skipped turn. Knowledge/hybrid
 * turns and hosts that disable the key keep full file search. The explicit
 * file-listing intent takes precedence over the skip.
 */
class AiManagerFileSearchSkipTest extends TestCase
{
    private AiManager $manager;
    private ContextRetrieverInterface $mockContext;
    private EmbeddingProviderInterface $mockEmbedding;
    private LlmProviderInterface $mockLlm;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;
    private VectorStoreInterface $mockVectorStore;
    private FileContextProvider $mockFileContextProvider;

    /** @var int Number of times any file search (any angle) was invoked. */
    private int $searchCalls = 0;

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.security.enabled' => false]);
        config(['ai.file_context.enabled' => true]);
        // Recall floor deliberately active so the tests prove the skip silences it.
        config(['ai.file_context.min_relevance_score' => 0.4]);
        config(['ai.file_context.recall_floor' => 0.3]);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockEmbedding = Mockery::mock(EmbeddingProviderInterface::class);
        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->mockFileContextProvider = Mockery::mock(FileContextProvider::class);

        $this->searchCalls = 0;
        $this->mockFileContextProvider->shouldReceive('getFileContext')
            ->andReturnUsing(function ($query, $user) {
                $this->searchCalls++;
                return ['relevant_files' => [], 'file_count' => 0];
            });

        $this->manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            $this->mockEmbedding,
            $this->mockLlm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            $this->mockVectorStore,
            $this->mockFileContextProvider
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeContext(string $questionType, string $intent = 'lookup'): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = 'How many members does my team have?';
        $ctx->qu = new QueryUnderstandingResult(
            searchQuery: 'team member count',
            intent: $intent,
            questionType: $questionType,
        );
        $ctx->similarQueries = [];
        $ctx->graphSchema = ['labels' => ['User']];

        return $ctx;
    }

    private function runTurn(PipelineContext $ctx): array
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

        $this->mockQueryGenerator->shouldReceive('generate')->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (u:User) RETURN count(u) AS c',
            explanation: '',
            confidence: 0.9,
            warnings: [],
            metadata: [],
        ));
        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(new QueryExecutionResult(
            success: true, data: [['c' => 12]], stats: [], metadata: [], errors: [],
        ));
        $this->mockResponseGenerator->shouldReceive('generate')->andReturn([
            'answer' => 'ok', 'insights' => [], 'visualizations' => [], 'metadata' => [],
        ]);

        return $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 1]]);
    }

    /** @test */
    public function a_data_query_skips_file_search_entirely_including_the_recall_floor(): void
    {
        $ctx = $this->makeContext('data_query');

        $result = $this->runTurn($ctx);

        // ZERO search invocations: no fan-out angle AND no recall-floor retry.
        $this->assertSame(0, $this->searchCalls, 'File search must not run at all for a skipped data query');
        $this->assertSame([], $ctx->fileContext, 'No chunks may be admitted into a skipped turn');
        $this->assertSame('ok', $result['answer'], 'The data pipeline still answers normally');
    }

    /**
     * @test Eval p15: every execution of ONE turn (main query, companion counts, collapse and
     * export re-runs, repairs) shares one rate-limit batch key - one charge per user action;
     * the next turn gets a new key.
     */
    public function all_executions_of_a_turn_share_one_rate_limit_batch(): void
    {
        $batches = [];
        $this->mockQueryExecutor->shouldReceive('execute')->andReturnUsing(function ($cypher, $params = [], $options = []) use (&$batches) {
            $batches[] = $options['rate_limit_batch'] ?? null;

            return new QueryExecutionResult(success: true, data: [['c' => 12]], stats: [], metadata: [], errors: []);
        });
        $this->mockQueryExecutor->shouldReceive('executeCount')->andReturnUsing(function ($cypher, $params = [], $options = []) use (&$batches) {
            $batches[] = $options['rate_limit_batch'] ?? null;

            return 1;
        });

        $this->runTurn($this->makeContext('data_query'));
        $first = array_values(array_unique($batches));
        $this->assertCount(1, $first, 'one batch key for the whole turn');
        $this->assertStringStartsWith('turn:', (string) $first[0]);

        $batches = [];
        $this->mockContext->shouldReceive('retrieveContext')->andReturn($this->makeContext('data_query'));
        $this->manager->answerQuestion('How many members does my team have?', ['user' => (object) ['id' => 1]]);
        $second = array_values(array_unique($batches));
        $this->assertCount(1, $second);
        $this->assertNotSame($first[0], $second[0], 'a new turn is a new charge');
    }

    /** @test */
    public function knowledge_queries_keep_full_file_search(): void
    {
        $ctx = $this->makeContext('knowledge_query');

        $this->runTurn($ctx);

        $this->assertGreaterThan(0, $this->searchCalls, 'File search must run for knowledge_query');
    }

    /** @test */
    public function hybrid_queries_keep_full_file_search(): void
    {
        $ctx = $this->makeContext('hybrid');

        $this->runTurn($ctx);

        $this->assertGreaterThan(0, $this->searchCalls, 'File search must run for hybrid');
    }

    /** @test */
    public function disabling_the_key_restores_file_search_for_data_queries(): void
    {
        config(['ai.file_context.skip_for_question_types' => []]);

        $ctx = $this->makeContext('data_query');

        $this->runTurn($ctx);

        $this->assertGreaterThan(0, $this->searchCalls, 'Empty skip list = host disabled the skip');
    }

    /** @test */
    public function the_skip_list_is_config_driven(): void
    {
        config(['ai.file_context.skip_for_question_types' => ['data_query', 'hybrid']]);

        $ctx = $this->makeContext('hybrid');

        $this->runTurn($ctx);

        $this->assertSame(0, $this->searchCalls, 'A host may skip additional question types');
    }

    /** @test */
    public function file_listing_intent_takes_precedence_over_the_data_query_skip(): void
    {
        config(['ai.file_context.enumeration.enabled' => true]);
        config(['ai.file_context.enumeration.intents' => ['file_listing']]);

        $ctx = $this->makeContext('data_query', 'file_listing');

        $this->mockFileContextProvider->shouldReceive('getFileEnumerationContext')
            ->once()
            ->andReturn([
                'relevant_files' => [
                    ['file_id' => 10, 'filename' => 'lease.pdf', 'type' => 'pdf', 'snippet' => '', 'relevance' => null, 'listing_only' => true, 'source' => 'database', 'match_type' => 'enumeration'],
                ],
                'file_count' => 1,
                'has_physical' => false,
                'has_database' => true,
                'enumeration' => true,
            ]);

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
        $this->mockResponseGenerator->shouldReceive('generate')->andReturn([
            'answer' => 'You can access lease.pdf', 'insights' => [], 'visualizations' => [], 'metadata' => [],
        ]);

        $result = $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 1]]);

        $this->assertTrue((bool) ($ctx->fileContext['enumeration'] ?? false), 'Enumeration must still fire');
        $this->assertSame(0, $this->searchCalls, 'Enumeration answers the listing without semantic search');
        $this->assertStringContainsString('lease.pdf', $result['answer']);
    }

    /**
     * @test
     * Eval p10-A excel: "give me that as an Excel with their contact info" after a roster
     * turn came back intent=file_listing + follow_up_reference=true; enumeration answered
     * with 50 files and no query ran. A follow-up on a recorded DATA result is never a
     * file listing — the data pipeline (extend the previous query) must run.
     */
    public function a_follow_up_on_a_recorded_data_result_is_never_a_file_listing(): void
    {
        config(['ai.file_context.enumeration.enabled' => true]);
        config(['ai.file_context.enumeration.intents' => ['file_listing']]);

        $ctx = new PipelineContext();
        $ctx->question = 'Give me that as an Excel with their contact info';
        $ctx->qu = new QueryUnderstandingResult(
            searchQuery: 'team members contact info',
            intent: 'file_listing',
            questionType: 'data_query',
            followUpReference: true,
            exportFormat: 'excel',
        );
        $ctx->similarQueries = [];
        $ctx->graphSchema = ['labels' => ['User']];
        $ctx->conversationContext = [
            'last_result_sample' => [['member_name' => 'Ana', 'role' => 'Admin']],
            'last_cypher_query' => 'MATCH (u:User) RETURN u.name AS member_name',
        ];

        $this->mockFileContextProvider->shouldNotReceive('getFileEnumerationContext');

        $this->runTurn($ctx);

        $this->assertFalse((bool) ($ctx->fileContext['enumeration'] ?? false), 'No file enumeration on a data follow-up');
        $this->assertNotNull($ctx->queryResult->cypher ?? null, 'The data query must run');
    }

    /**
     * @test
     * Live CondoEdge 2026-10-26: every new export request ("I need an Excel of the owners, how do I generate it?",
     * "export it to Excel") came back intent=file_listing; the file list replaced the document search, so the
     * help article on generating that export was never read. Asking to RECEIVE something as a file is not
     * asking which files one has.
     */
    public function a_request_to_receive_a_file_is_never_a_file_listing(): void
    {
        config(['ai.file_context.enumeration.enabled' => true]);
        config(['ai.file_context.enumeration.intents' => ['file_listing']]);
        config(['ai.file_context.skip_for_question_types' => []]);

        $ctx = new PipelineContext();
        $ctx->question = 'I need an Excel file with the names and addresses of all owners, how do I generate it?';
        $ctx->qu = new QueryUnderstandingResult(
            searchQuery: 'owners names addresses excel',
            intent: 'file_listing',
            questionType: 'data_query',
            exportFormat: 'excel',
        );
        $ctx->similarQueries = [];
        $ctx->graphSchema = ['labels' => ['User']];

        $this->mockFileContextProvider->shouldNotReceive('getFileEnumerationContext');

        $this->runTurn($ctx);

        $this->assertFalse((bool) ($ctx->fileContext['enumeration'] ?? false), 'No file listing for a file request');
        $this->assertGreaterThan(0, $this->searchCalls, 'The documents are searched as on any other turn');
    }

    /** @test */
    public function an_empty_enumeration_on_a_listing_turn_falls_through_to_semantic_retrieval_despite_the_skip(): void
    {
        config(['ai.file_context.enumeration.enabled' => true]);
        config(['ai.file_context.enumeration.intents' => ['file_listing']]);

        // QU stamps the listing turn with BOTH a skippable question_type
        // (data_query) AND the file_listing intent; enumeration yields nothing.
        // The documented fall-through to normal retrieval must then actually
        // run — the question_type skip must not swallow the degraded path.
        $ctx = $this->makeContext('data_query', 'file_listing');

        $this->mockFileContextProvider->shouldReceive('getFileEnumerationContext')
            ->once()
            ->andReturn([]);

        $this->runTurn($ctx);

        $this->assertGreaterThan(
            0,
            $this->searchCalls,
            'Empty enumeration must fall through to the semantic fan-out, not dead-end on the data_query skip'
        );
    }
}
