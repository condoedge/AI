<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FilenameExtractor;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Mockery;

/**
 * Task 1.29: cross-collection rank fusion must not flatten per-chunk relevance.
 *
 * FileSearchService fuses the primary (file_chunks) and documentation collections
 * with RRF. Its fusion_score is a FILE-level, per-QUERY number (the sum of the
 * file's chunk ranks in that one angle's result list): every chunk of a long
 * document that owns the whole result list gets the same value (CondoEdge: every
 * CCQ chunk traced as 0.5560599 = sum 1/(60+r), r=1..45). Once that score was
 * carried out of the per-angle provider into the cross-angle merge and the final
 * order, the chunks of ONE file were ranked by "how dominant the file was in the
 * angle that surfaced them" instead of by their own cosine similarity.
 *
 * Every collection is searched with the SAME query embedding (one provider, one
 * metric), so cosine similarity is comparable across collections; fusion only
 * decides which files survive each angle's candidate cut.
 *
 * Drives the REAL FileSearchService + FileContextProvider + AiManager fan-out
 * over a fake chunk store that returns distinct cosine scores in two collections.
 */
class FusedRetrievalChunkOrderTest extends TestCase
{
    private const DOCS = 'documentation_chunks';

    /** @var array<string,array{primary:array,docs:array}> per-angle store results */
    private array $store = [];

    public function setUp(): void
    {
        parent::setUp();

        config([
            'ai.file_context.enabled' => true,
            'ai.file_context.min_relevance_score' => 0.4,
            'ai.file_context.max_references' => 5,
            'ai.file_context.max_chunks_per_file' => 2,
            'ai.file_context.context_window' => 0,
            'ai.file_context.skip_for_question_types' => [],
        ]);

        // FileSearchService loads DB file models by id; physical ids have none.
        $builder = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $builder->shouldReceive('get')->andReturn(collect([]));
        $model = Mockery::mock(Model::class)->makePartial();
        $model->shouldReceive('whereIn')->andReturn($builder);
        $this->app->instance('file-model', $model);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function chunk(string|int $fileId, int $index): FileChunk
    {
        return new FileChunk(
            fileId: $fileId,
            fileName: is_string($fileId) ? basename($fileId) : "file-{$fileId}.pdf",
            content: "chunk {$index} of {$fileId}",
            embedding: [],
            chunkIndex: $index,
            totalChunks: 60,
            startPosition: 0,
            endPosition: 10,
            metadata: []
        );
    }

    private function manager(): AiManager
    {
        $chunkStore = Mockery::mock(ChunkStoreInterface::class);
        $chunkStore->shouldReceive('searchByContent')->andReturnUsing(function (...$args) {
            $angle = $this->store[$args[0]] ?? ['primary' => [], 'docs' => []];
            $collection = $args[3] ?? null;

            return $collection === self::DOCS ? $angle['docs'] : $angle['primary'];
        });

        $search = new FileSearchService($chunkStore, Mockery::mock(GraphStoreInterface::class), [self::DOCS]);

        $access = Mockery::mock(FileAccessResolverInterface::class);
        $access->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $access->shouldReceive('isPhysicalFile')
            ->andReturnUsing(fn ($id) => is_string($id) && str_starts_with($id, 'physical:'));
        $access->shouldReceive('filterAccessibleFileIds')->andReturnUsing(fn ($ids) => $ids);

        $filenames = Mockery::mock(FilenameExtractor::class);
        $filenames->shouldReceive('extract')->andReturn([]);

        $provider = new FileContextProvider($search, $access, $filenames, null);

        return new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            Mockery::mock(ContextRetrieverInterface::class),
            Mockery::mock(EmbeddingProviderInterface::class),
            Mockery::mock(LlmProviderInterface::class),
            Mockery::mock(QueryGeneratorInterface::class),
            Mockery::mock(QueryExecutorInterface::class),
            Mockery::mock(ResponseGeneratorInterface::class),
            Mockery::mock(VectorStoreInterface::class),
            $provider
        );
    }

    private function context(): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = 'q';
        $ctx->qu = new QueryUnderstandingResult(
            searchQuery: 'angle-1',
            questionType: 'knowledge_query',
            corpusTranslations: ['fr' => 'angle-2'],
        );

        return $ctx;
    }

    /** @test */
    public function chunks_of_one_file_are_chosen_and_ordered_by_their_own_cosine_not_the_file_fusion_score(): void
    {
        $law = 'physical:laws/ccq.md';
        $team = 42;

        // Both collections contribute on every angle, so every angle is FUSED.
        $this->store = [
            // Best law passage: chunk 30 at 0.80, but it is the law's ONLY hit in
            // this angle -> weak file fusion (1/61). (It is the user's own wording
            // that finds it: the question angle's best passage always keeps a slot
            // — AiManagerProbeFusionTest — which is not what this test is about.)
            'q' => [
                'docs' => [['chunk' => $this->chunk($law, 30), 'score' => 0.80]],
                'primary' => [['chunk' => $this->chunk($team, 0), 'score' => 0.45]],
            ],
            // A mediocre generic passage (chunk 5, 0.55) — but the law owns the
            // whole result list here, so its file fusion is the HIGHEST (~0.08).
            'angle-1' => [
                'docs' => [
                    ['chunk' => $this->chunk($law, 5), 'score' => 0.55],
                    ['chunk' => $this->chunk($law, 6), 'score' => 0.54],
                    ['chunk' => $this->chunk($law, 7), 'score' => 0.53],
                    ['chunk' => $this->chunk($law, 8), 'score' => 0.52],
                    ['chunk' => $this->chunk($law, 9), 'score' => 0.51],
                ],
                'primary' => [['chunk' => $this->chunk($team, 0), 'score' => 0.44]],
            ],
            // The second-best law passage (chunk 50, 0.66; outside the snippet-depth
            // margin of 0.80) with a middling file fusion (~0.03).
            'angle-2' => [
                'docs' => [
                    ['chunk' => $this->chunk($law, 50), 'score' => 0.66],
                    ['chunk' => $this->chunk($law, 51), 'score' => 0.41],
                ],
                'primary' => [['chunk' => $this->chunk($team, 0), 'score' => 0.43]],
            ],
        ];

        $ctx = (function ($c) {
            return $this->retrieveAndSetFileContext($c, 'q', ['user' => (object) ['id' => 1]]);
        })->call($this->manager(), $this->context());
        $files = $ctx->fileContext['relevant_files'] ?? [];

        $this->assertSame(
            [[$law, 30], [$law, 50], [$team, 0]],
            array_map(fn ($f) => [$f['file_id'], $f['chunk_index']], $files),
            'within the law file the 0.66 passage beats the 0.55 generic one, and the list follows cosine'
        );

        // Displayed/recorded relevance is each reference's cosine, never a rank-fusion number.
        $this->assertEqualsWithDelta([0.80, 0.66, 0.45], array_column($files, 'relevance'), 1e-9);
        foreach ($files as $file) {
            $this->assertArrayNotHasKey('fusion_score', $file, 'a per-angle, file-level RRF score must not leave the provider');
        }
    }
}
