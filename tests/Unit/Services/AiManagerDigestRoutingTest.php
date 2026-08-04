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
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Digest routing (design §2.3): a QU-judged digest turn is answered by the
 * deterministic source walk — normal query generation and file search are
 * SKIPPED entirely (the branch returns before both, like page narration).
 * Guards: followUpReference wins (a follow-up is never a digest), an empty
 * registry keeps the normal pipeline, and the combined digest+export turn
 * (amendment B1) acknowledges the export ask instead of dropping it.
 */
class AiManagerDigestRoutingTest extends TestCase
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

    /** @var array Captured digest-composition chat messages. */
    private array $chatMessages = [];

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.security.enabled' => false]);
        config(['ai.file_context.enabled' => true]);

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
            ->andReturnUsing(function () {
                $this->searchCalls++;
                return ['relevant_files' => [], 'file_count' => 0];
            });

        // The digest composer's single LLM call rides the injected provider.
        $this->chatMessages = [];
        $this->mockLlm->shouldReceive('chat')
            ->andReturnUsing(function ($messages) {
                $this->chatMessages[] = $messages;
                return 'Your attention digest.';
            })
            ->byDefault();

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

    private function configureSource(): void
    {
        config(['ai.attention_sources' => [
            'open_tasks' => [
                'label' => 'Open tasks',
                'description' => 'Tasks not yet closed.',
                'cypher' => 'MATCH (t:Task) RETURN t.id AS id, t.title AS title',
                'action_key' => 'tasks',
                'limit' => 5,
            ],
        ]]);
    }

    private function makeContext(bool $digestRequest, bool $followUp = false, ?string $exportFormat = null): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = 'What should I take care of next?';
        $ctx->qu = new QueryUnderstandingResult(
            searchQuery: 'workspace attention overview',
            intent: 'list',
            questionType: 'data_query',
            exportFormat: $exportFormat,
            followUpReference: $followUp,
            digestRequest: $digestRequest,
        );
        $ctx->graphSchema = ['labels' => ['Task']];

        return $ctx;
    }

    /** @test */
    public function a_digest_turn_is_answered_by_the_source_walk_skipping_query_gen_and_file_search(): void
    {
        $this->configureSource();
        $ctx = $this->makeContext(digestRequest: true);

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $captured = null;
        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturnUsing(function ($cypher, $params, $options) use (&$captured) {
                $captured = ['cypher' => $cypher, 'options' => $options];
                return new QueryExecutionResult(
                    success: true,
                    data: [['id' => 1, 'title' => 'Fix roof']],
                    stats: [], metadata: [], errors: [],
                );
            });

        $user = (object) ['id' => 13];
        $result = $this->manager->answerQuestion($ctx->question, ['user' => $user, 'conversation_id' => 'c-9']);

        // The deterministic source loop replaced query generation entirely.
        $this->assertSame('MATCH (t:Task) RETURN t.id AS id, t.title AS title', $captured['cypher']);
        $this->assertSame($user, $captured['options']['user'], 'the pipeline user threads into every source execution');
        $this->assertSame($ctx->question, $captured['options']['original_question']);
        $this->assertSame('c-9', $captured['options']['conversation_id']);
        $this->assertArrayNotHasKey('skip_security', $captured['options']);
        $this->assertSame(0, $this->searchCalls, 'file search must never run on a digest turn');

        $this->assertSame('Your attention digest.', $result['answer']);
        $this->assertNull($result['cypher']);
        $this->assertSame('open_tasks', $result['metadata']['response']['digest']['sources'][0]['key']);
        $this->assertSame([['id' => 1, 'title' => 'Fix roof', 'attention_source' => 'open_tasks']], $result['data'], 'digest rows feed the conversation write-back');
    }

    /** @test */
    public function a_follow_up_reference_is_never_a_digest(): void
    {
        $this->configureSource();
        $ctx = $this->makeContext(digestRequest: true, followUp: true);

        $this->runNormalTurn($ctx);

        $result = $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertSame('normal answer', $result['answer'], 'the normal pipeline owns follow-up turns');
    }

    /** @test */
    public function an_empty_registry_keeps_the_normal_pipeline(): void
    {
        config(['ai.attention_sources' => []]);
        $ctx = $this->makeContext(digestRequest: true);

        $this->runNormalTurn($ctx);

        $result = $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertSame('normal answer', $result['answer']);
    }

    /** @test */
    public function a_non_digest_turn_runs_the_normal_pipeline_unchanged(): void
    {
        $this->configureSource();
        $ctx = $this->makeContext(digestRequest: false);

        $this->runNormalTurn($ctx);

        $result = $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertSame('normal answer', $result['answer']);
    }

    /** @test */
    public function a_combined_digest_and_export_ask_runs_the_digest_and_acknowledges_the_export(): void
    {
        // Amendment B1: digestRequest + exportFormat must not silently drop the
        // export — the composer is told, offers the follow-up as the single
        // deepening offer, and stamps the marker for graders.
        $this->configureSource();
        $ctx = $this->makeContext(digestRequest: true, exportFormat: 'excel');

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockQueryExecutor->shouldReceive('execute')->once()->andReturn(new QueryExecutionResult(
            success: true, data: [['id' => 1, 'title' => 'Fix roof']], stats: [], metadata: [], errors: [],
        ));

        $result = $this->manager->answerQuestion(
            'Give me an excel of everything needing my attention',
            ['user' => (object) ['id' => 13]]
        );

        $this->assertSame('excel', $result['metadata']['response']['digest']['export_followup_offered']);
        $this->assertArrayNotHasKey('export_intent', $result['metadata']['response'], 'no file is produced in-turn in v1');

        $prompt = '';
        foreach ($this->chatMessages[0] ?? [] as $message) {
            $prompt .= ($message['content'] ?? '') . "\n";
        }
        $this->assertStringContainsString('export that', $prompt, 'the composer is instructed to offer the follow-up export');
        $this->assertSame(0, $this->searchCalls);
    }

    /**
     * Wire the mocks for a turn the NORMAL pipeline must own.
     */
    private function runNormalTurn(PipelineContext $ctx): void
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true)->byDefault();
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true)->byDefault();
        $this->mockEmbedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1))->byDefault();

        $this->mockQueryGenerator->shouldReceive('generate')->once()->andReturn(new QueryGenerationResult(
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
            'answer' => 'normal answer', 'insights' => [], 'visualizations' => [], 'metadata' => [],
        ]);
    }
}
