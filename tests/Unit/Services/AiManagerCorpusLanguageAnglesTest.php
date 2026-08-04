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
 * D2a — cross-lingual retrieval. When the corpus is multilingual, the file-search
 * fan-out must include the question translated into each configured corpus
 * language as a SEPARATE angle (a pure single-language probe), so a question in
 * one language still retrieves documents written in another.
 *
 * The translations are produced once by the Query-Understanding layer and carried
 * on QueryUnderstandingResult::corpusTranslations. AiManager::retrieveAndSetFileContext
 * adds each as an extra fan-out angle. With no configured corpus languages (and
 * thus no translations), the fan-out is unchanged — zero added cost.
 */
class AiManagerCorpusLanguageAnglesTest extends TestCase
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
        $ctx->question = 'Can i tear down a wall keeping in mind rules of quebec?';
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
    public function it_adds_a_fan_out_angle_for_each_corpus_translation(): void
    {
        $qu = new QueryUnderstandingResult(
            searchQuery: 'tear down wall Quebec condominium rules',
            corpusTranslations: [
                'fr' => 'Puis-je démolir un mur en tenant compte des règles du Québec?',
                'en' => 'Can I tear down a wall keeping in mind the rules of Quebec?',
            ],
        );

        $ctx = $this->makePipelineContext($qu);
        $this->wirePipelineTail($ctx);

        $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 1]]);

        // Each pure-language translation must appear as its own fan-out angle.
        $this->assertContains(
            'Puis-je démolir un mur en tenant compte des règles du Québec?',
            $this->capturedQueries,
            'French corpus-language angle must be present in the fan-out'
        );
        $this->assertContains(
            'Can I tear down a wall keeping in mind the rules of Quebec?',
            $this->capturedQueries,
            'English corpus-language angle must be present in the fan-out'
        );
    }

    /** @test */
    public function it_leaves_the_fan_out_unchanged_when_there_are_no_corpus_translations(): void
    {
        $qu = new QueryUnderstandingResult(
            searchQuery: 'tear down wall Quebec condominium rules',
            corpusTranslations: [],
        );

        $ctx = $this->makePipelineContext($qu);
        $this->wirePipelineTail($ctx);

        $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 1]]);

        // No translations -> only the existing angles (searchQuery + question).
        // The exact set is the question, the searchQuery rewrite, and nothing else
        // multilingual is injected.
        $this->assertContains('tear down wall Quebec condominium rules', $this->capturedQueries);
        $this->assertContains($ctx->question, $this->capturedQueries);

        foreach ($this->capturedQueries as $query) {
            $this->assertStringNotContainsString('Puis-je', $query, 'No translated angle should appear when none configured');
        }
    }
}
