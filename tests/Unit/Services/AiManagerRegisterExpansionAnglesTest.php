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
 * Lay->domain-register expansion (angle wiring). QU carries the register
 * reformulations on QueryUnderstandingResult::registerExpansions; the file-search
 * fan-out must add each as a SEPARATE angle — exactly like corpus-translation
 * angles — so a casually-worded question also probes the corpus with the
 * documents' own terminology. With no expansions the fan-out is unchanged.
 */
class AiManagerRegisterExpansionAnglesTest extends TestCase
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

    /** @var string[] Every query the fan-out sent to the file context provider. */
    private array $capturedQueries = [];

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

        $this->capturedQueries = [];
        $this->mockFileContextProvider->shouldReceive('getFileContext')
            ->andReturnUsing(function ($query, $user) {
                $this->capturedQueries[] = $query;
                return ['relevant_files' => []];
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

    private function makePipelineContext(QueryUnderstandingResult $qu): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = 'Can I tear down the wall between my kitchen and living room?';
        $ctx->qu = $qu;
        $ctx->similarQueries = [];
        $ctx->graphSchema = ['labels' => ['Unit']];
        $ctx->relevantEntities = [];

        return $ctx;
    }

    private function wirePipelineTail(PipelineContext $ctx): void
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

        $this->mockQueryGenerator->shouldReceive('generate')->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (u:Unit) RETURN u',
            explanation: 'List units',
            confidence: 0.9,
            warnings: [],
            metadata: [],
        ));

        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(new QueryExecutionResult(
            success: true,
            data: [['id' => 1]],
            stats: [],
            metadata: [],
            errors: [],
        ));

        $this->mockResponseGenerator->shouldReceive('generate')->andReturn([
            'answer' => 'ok',
            'insights' => [],
            'visualizations' => [],
            'metadata' => [],
        ]);
    }

    /** @test */
    public function it_adds_a_fan_out_angle_for_each_register_expansion(): void
    {
        $qu = new QueryUnderstandingResult(
            searchQuery: 'tear down wall unit rules',
            questionType: 'knowledge_query',
            registerExpansions: [
                'alteration of a private portion affecting a partition wall',
                'authorization of the syndicate for works on the immovable',
            ],
        );

        $ctx = $this->makePipelineContext($qu);
        $this->wirePipelineTail($ctx);

        $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 1]]);

        $this->assertContains(
            'alteration of a private portion affecting a partition wall',
            $this->capturedQueries,
            'Each register expansion must be its own fan-out angle'
        );
        $this->assertContains(
            'authorization of the syndicate for works on the immovable',
            $this->capturedQueries
        );
    }

    /** @test */
    public function it_leaves_the_fan_out_unchanged_when_there_are_no_register_expansions(): void
    {
        $qu = new QueryUnderstandingResult(
            searchQuery: 'tear down wall unit rules',
            questionType: 'knowledge_query',
            registerExpansions: [],
        );

        $ctx = $this->makePipelineContext($qu);
        $this->wirePipelineTail($ctx);

        $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 1]]);

        $this->assertContains('tear down wall unit rules', $this->capturedQueries);
        $this->assertContains($ctx->question, $this->capturedQueries);

        foreach ($this->capturedQueries as $query) {
            $this->assertStringNotContainsString('private portion', $query, 'No register angle should appear when none produced');
        }
    }

    /** @test */
    public function register_angles_combine_with_corpus_translation_angles(): void
    {
        // Both expansion families are additive probes on the SAME single QU call;
        // the fan-out must include each independently.
        $qu = new QueryUnderstandingResult(
            searchQuery: 'tear down wall',
            questionType: 'knowledge_query',
            corpusTranslations: ['fr' => 'travaux de démolition d\'un mur en copropriété'],
            registerExpansions: ['alteration of a private portion'],
        );

        $ctx = $this->makePipelineContext($qu);
        $this->wirePipelineTail($ctx);

        $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 1]]);

        $this->assertContains('travaux de démolition d\'un mur en copropriété', $this->capturedQueries);
        $this->assertContains('alteration of a private portion', $this->capturedQueries);
    }
}
