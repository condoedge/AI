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
 * Cross-angle file merge. The fan-out scores the SAME file against several probes,
 * and different probes surface different best chunks of that file. A literal probe
 * often ranks a generic cover/preamble chunk (lowest index) highest, while a
 * concept-rich probe ranks the operative clause (deep in the document) highest at a
 * slightly lower raw cosine. The merge must rank the file by its strongest angle
 * but keep the SNIPPET from the deepest (past-cover) angle within a small relevance
 * margin — otherwise the operative clause is displaced by the cover page.
 */
class AiManagerFileMergeTest extends TestCase
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

    /** @var array<string,array> The file-context payload to return per angle text. */
    private array $perAngle = [];

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.security.enabled' => false]);
        config(['ai.file_context.enabled' => true]);
        // These tests pin the LEGACY single-reference-per-file contract (the
        // snippet-depth selection within ONE merged reference). Multi-chunk
        // emission (max_chunks_per_file > 1) is covered by
        // AiManagerMultiChunkMergeTest.
        config(['ai.file_context.max_chunks_per_file' => 1]);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockEmbedding = Mockery::mock(EmbeddingProviderInterface::class);
        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->mockFileContextProvider = Mockery::mock(FileContextProvider::class);

        $this->mockFileContextProvider->shouldReceive('getFileContext')
            ->andReturnUsing(fn ($query, $user) => $this->perAngle[$query] ?? ['relevant_files' => []]);

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

    private function fileRef(int $id, float $relevance, int $chunkIndex, string $snippet): array
    {
        return [
            'relevant_files' => [[
                'file_id' => $id,
                'filename' => 'decl.pdf',
                'snippet' => $snippet,
                'relevance' => $relevance,
                'chunk_index' => $chunkIndex,
                'source' => 'database',
            ]],
        ];
    }

    /**
     * Capture the merged fileContext that the manager attaches to the pipeline
     * context (read off the PipelineContext the query generator receives).
     */
    private function captureMergedFiles(PipelineContext $ctx): array
    {
        $captured = [];

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));

        $this->mockQueryGenerator->shouldReceive('generate')->andReturnUsing(
            function ($q, $context) use (&$captured) {
                $captured = $context->fileContext['relevant_files'] ?? [];
                return new QueryGenerationResult(
                    cypher: 'MATCH (u:Unit) RETURN u',
                    explanation: '',
                    confidence: 0.9,
                    warnings: [],
                    metadata: [],
                );
            }
        );

        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(new QueryExecutionResult(
            success: true, data: [['id' => 1]], stats: [], metadata: [], errors: [],
        ));
        $this->mockResponseGenerator->shouldReceive('generate')->andReturn([
            'answer' => 'ok', 'insights' => [], 'visualizations' => [], 'metadata' => [],
        ]);

        $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 1]]);

        return $captured;
    }

    /** @test */
    public function it_keeps_the_deep_operative_snippet_over_a_marginally_higher_cover_snippet(): void
    {
        // The literal angle ranks the cover chunk (index 1) highest at 0.481.
        // A concept-rich angle ranks the operative clause (index 24) highest at
        // 0.411 — only 0.07 lower, well within the merge margin. The merge must
        // keep the OPERATIVE snippet while ranking the file by the 0.481 strength.
        $cover = 'COVER PAGE NOTARY PREAMBLE';
        $operative = 'travaux nécessaires à la conservation de l\'immeuble, parties exclusives';

        $qu = new QueryUnderstandingResult(
            searchQuery: 'literal probe',
            corpusTranslations: ['fr' => 'concept probe'],
        );
        $ctx = new PipelineContext();
        $ctx->question = 'Can i tear down a wall keeping in mind rules of quebec?';
        $ctx->qu = $qu;
        $ctx->similarQueries = [];
        $ctx->graphSchema = ['labels' => ['Unit']];
        $ctx->relevantEntities = [];

        // Map each fan-out angle text to the file result it returns.
        $this->perAngle = [
            'literal probe' => $this->fileRef(7, 0.481, 1, $cover),
            $ctx->question => $this->fileRef(7, 0.470, 1, $cover),
            'concept probe' => $this->fileRef(7, 0.411, 24, $operative),
        ];

        $files = $this->captureMergedFiles($ctx);

        $this->assertCount(1, $files, 'The same file across angles must merge to one reference');
        $merged = $files[0];

        // Ranked by the strongest angle...
        $this->assertEqualsWithDelta(0.481, $merged['relevance'], 0.0001);
        // ...but the snippet is the deep operative clause, not the cover page.
        $this->assertStringContainsString('travaux nécessaires', $merged['snippet']);
        $this->assertStringNotContainsString('COVER PAGE', $merged['snippet']);
        $this->assertSame(24, $merged['chunk_index']);
    }

    /** @test */
    public function it_does_not_prefer_a_deep_snippet_that_is_far_below_the_best_relevance(): void
    {
        // When a deep-chunk angle is well outside the margin (much weaker), it is
        // noise, not a better answer: the strongest angle's snippet is kept.
        $strong = 'STRONG SHALLOW CONTENT';
        $weakDeep = 'WEAK DEEP CONTENT';

        $qu = new QueryUnderstandingResult(
            searchQuery: 'strong probe',
            corpusTranslations: ['fr' => 'weak probe'],
        );
        $ctx = new PipelineContext();
        $ctx->question = 'q';
        $ctx->qu = $qu;
        $ctx->similarQueries = [];
        $ctx->graphSchema = ['labels' => ['Unit']];
        $ctx->relevantEntities = [];

        $this->perAngle = [
            'strong probe' => $this->fileRef(7, 0.80, 1, $strong),
            'q' => $this->fileRef(7, 0.79, 1, $strong),
            'weak probe' => $this->fileRef(7, 0.30, 99, $weakDeep), // 0.50 below, outside margin
        ];

        $files = $this->captureMergedFiles($ctx);

        $this->assertCount(1, $files);
        $this->assertStringContainsString('STRONG SHALLOW CONTENT', $files[0]['snippet']);
        $this->assertStringNotContainsString('WEAK DEEP CONTENT', $files[0]['snippet']);
    }
}
