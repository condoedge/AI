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
 * Multi-chunk retrieval: the cross-angle merge may surface up to
 * ai.file_context.max_chunks_per_file DISTINCT chunks of the SAME file as
 * separate references (primary merged reference first, extras ranked by their
 * own relevance and required to clear the CONFIDENT threshold), with the final
 * reference list still bounded by max_references overall. One chunk per turn
 * structurally starves deepening ("tell me more") questions.
 */
class AiManagerMultiChunkMergeTest extends TestCase
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
        config(['ai.file_context.min_relevance_score' => 0.4]);

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
            'file_id' => $id,
            'filename' => "file-{$id}.pdf",
            'snippet' => $snippet,
            'relevance' => $relevance,
            'chunk_index' => $chunkIndex,
            'source' => 'database',
        ];
    }

    private function makeContext(array $translations): PipelineContext
    {
        // 'hybrid' keeps file search on AND still runs the query generator, whose
        // mock is where the merged fileContext is captured (a knowledge_query with
        // file context short-circuits generation entirely).
        $qu = new QueryUnderstandingResult(
            searchQuery: 'angle-1',
            questionType: 'hybrid',
            corpusTranslations: $translations,
        );
        $ctx = new PipelineContext();
        $ctx->question = 'q';
        $ctx->qu = $qu;
        $ctx->similarQueries = [];
        $ctx->graphSchema = ['labels' => ['Unit']];

        return $ctx;
    }

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
    public function distinct_confident_chunks_of_the_same_file_become_separate_references(): void
    {
        config(['ai.file_context.max_chunks_per_file' => 3]);

        $ctx = $this->makeContext(['fr' => 'angle-2', 'en' => 'angle-3']);

        // Three angles surface three DISTINCT chunks of file 7, all confident.
        $this->perAngle = [
            'angle-1' => ['relevant_files' => [$this->fileRef(7, 0.80, 10, 'chunk ten')]],
            'q' => ['relevant_files' => [$this->fileRef(7, 0.72, 24, 'chunk twenty-four')]],
            'angle-2' => ['relevant_files' => [$this->fileRef(7, 0.65, 40, 'chunk forty')]],
            'angle-3' => ['relevant_files' => [$this->fileRef(7, 0.80, 10, 'chunk ten')]],
        ];

        $files = $this->captureMergedFiles($ctx);

        $this->assertCount(3, $files, 'Three distinct confident chunks must surface as three references');

        $chunkIndexes = array_column($files, 'chunk_index');
        sort($chunkIndexes);
        $this->assertSame([10, 24, 40], $chunkIndexes);

        // The primary reference carries the file's strongest-angle relevance and leads.
        $this->assertEqualsWithDelta(0.80, $files[0]['relevance'], 0.0001);
    }

    /** @test */
    public function the_max_chunks_per_file_cap_is_respected(): void
    {
        config(['ai.file_context.max_chunks_per_file' => 2]);

        $ctx = $this->makeContext(['fr' => 'angle-2', 'en' => 'angle-3']);

        // Four distinct confident chunks of the same file, cap 2.
        $this->perAngle = [
            'angle-1' => ['relevant_files' => [$this->fileRef(7, 0.80, 10, 'a')]],
            'q' => ['relevant_files' => [$this->fileRef(7, 0.75, 20, 'b')]],
            'angle-2' => ['relevant_files' => [$this->fileRef(7, 0.70, 30, 'c')]],
            'angle-3' => ['relevant_files' => [$this->fileRef(7, 0.65, 41, 'd')]],
        ];

        $files = $this->captureMergedFiles($ctx);

        $this->assertCount(2, $files, 'Cap of 2 chunks per file must hold');
    }

    /** @test */
    public function extra_chunks_below_the_confident_threshold_are_not_promoted(): void
    {
        config(['ai.file_context.max_chunks_per_file' => 3]);

        $ctx = $this->makeContext(['fr' => 'angle-2']);

        // A weak (0.30 < 0.4) deep chunk is noise, not extra grounding.
        $this->perAngle = [
            'angle-1' => ['relevant_files' => [$this->fileRef(7, 0.80, 10, 'strong')]],
            'q' => ['relevant_files' => [$this->fileRef(7, 0.30, 99, 'weak deep')]],
        ];

        $files = $this->captureMergedFiles($ctx);

        $this->assertCount(1, $files, 'A sub-threshold chunk must not become a second reference');
        $this->assertSame(10, $files[0]['chunk_index']);
    }

    /** @test */
    public function the_final_reference_list_is_bounded_by_max_references_overall(): void
    {
        config(['ai.file_context.max_chunks_per_file' => 3]);
        config(['ai.file_context.max_references' => 4]);

        $ctx = $this->makeContext(['fr' => 'angle-2', 'en' => 'angle-3']);

        // Two files x three confident chunks each = 6 candidate references; the
        // overall cap must trim to 4, keeping the strongest.
        $this->perAngle = [
            'angle-1' => ['relevant_files' => [
                $this->fileRef(7, 0.90, 10, 'f7-a'),
                $this->fileRef(8, 0.60, 5, 'f8-a'),
            ]],
            'q' => ['relevant_files' => [
                $this->fileRef(7, 0.85, 20, 'f7-b'),
                $this->fileRef(8, 0.55, 15, 'f8-b'),
            ]],
            'angle-2' => ['relevant_files' => [
                $this->fileRef(7, 0.82, 30, 'f7-c'),
                $this->fileRef(8, 0.50, 25, 'f8-c'),
            ]],
            'angle-3' => ['relevant_files' => []],
        ];

        $files = $this->captureMergedFiles($ctx);

        $this->assertCount(4, $files, 'max_references bounds the flattened multi-chunk list');

        // The three strong file-7 chunks and the strongest file-8 reference win.
        $file7 = array_filter($files, fn ($f) => $f['file_id'] === 7);
        $file8 = array_filter($files, fn ($f) => $f['file_id'] === 8);
        $this->assertCount(3, $file7);
        $this->assertCount(1, $file8);
    }

    /** @test */
    public function chunks_within_the_primary_snippet_window_are_deduplicated_not_promoted(): void
    {
        config(['ai.file_context.max_chunks_per_file' => 3]);
        config(['ai.file_context.context_window' => 2]);

        $ctx = $this->makeContext(['fr' => 'angle-2', 'en' => 'angle-3']);

        // Snippets are window-expanded ±context_window chunks, so chunk 6 sits
        // INSIDE the primary's (chunk 5) window — its text already ships within
        // the primary's snippet. Chunk 9 is outside every window: distinct.
        $this->perAngle = [
            'angle-1' => ['relevant_files' => [$this->fileRef(7, 0.80, 5, 'chunk five')]],
            'q' => ['relevant_files' => [$this->fileRef(7, 0.60, 6, 'chunk six')]],
            'angle-2' => ['relevant_files' => [$this->fileRef(7, 0.55, 9, 'chunk nine')]],
            'angle-3' => ['relevant_files' => []],
        ];

        $files = $this->captureMergedFiles($ctx);

        $chunkIndexes = array_column($files, 'chunk_index');
        sort($chunkIndexes);
        $this->assertSame(
            [5, 9],
            $chunkIndexes,
            'Adjacent chunk 6 duplicates the primary window-expanded snippet; only chunk 9 is a distinct passage'
        );
    }

    /** @test */
    public function extra_chunks_are_window_deduplicated_against_each_other_too(): void
    {
        config(['ai.file_context.max_chunks_per_file' => 3]);
        config(['ai.file_context.context_window' => 2]);

        $ctx = $this->makeContext(['fr' => 'angle-2', 'en' => 'angle-3']);

        // Chunks 20 and 21 overlap each other's windows almost fully — only the
        // stronger of the pair (21 at 0.65) may ship beside the primary, so a
        // near-duplicate never consumes a max_references slot.
        $this->perAngle = [
            'angle-1' => ['relevant_files' => [$this->fileRef(7, 0.80, 10, 'ten')]],
            'q' => ['relevant_files' => [$this->fileRef(7, 0.60, 20, 'twenty')]],
            'angle-2' => ['relevant_files' => [$this->fileRef(7, 0.65, 21, 'twenty-one')]],
            'angle-3' => ['relevant_files' => []],
        ];

        $files = $this->captureMergedFiles($ctx);

        $chunkIndexes = array_column($files, 'chunk_index');
        sort($chunkIndexes);
        $this->assertSame(
            [10, 21],
            $chunkIndexes,
            'Of two window-overlapping extras only the stronger showing ships'
        );
    }

    /** @test */
    public function cap_of_one_preserves_the_legacy_single_reference_merge(): void
    {
        config(['ai.file_context.max_chunks_per_file' => 1]);

        $ctx = $this->makeContext(['fr' => 'angle-2']);

        $this->perAngle = [
            'angle-1' => ['relevant_files' => [$this->fileRef(7, 0.80, 10, 'strong shallow')]],
            'q' => ['relevant_files' => [$this->fileRef(7, 0.75, 24, 'operative deep')]],
        ];

        $files = $this->captureMergedFiles($ctx);

        $this->assertCount(1, $files);
        // Snippet-depth selection still applies within the single reference:
        // within the margin, the deeper chunk's snippet wins at max relevance.
        $this->assertSame(24, $files[0]['chunk_index']);
        $this->assertEqualsWithDelta(0.80, $files[0]['relevance'], 0.0001);
    }

    /**
     * Task 1.29 (supersedes M-2): a rank-fusion score is per-angle and file-level,
     * so the cross-angle merge never ranks by it. File 1's snippet comes from the
     * deeper 'q' angle (within the depth margin) whose fusion is weak; the file
     * still ranks by its strongest angle's COSINE (0.90), and the merged
     * reference carries no fusion_score. The snippet chunk's own cosine is kept
     * as chunk_relevance.
     *
     * @test
     */
    public function the_merge_ranks_by_strongest_angle_relevance_never_by_a_per_angle_fusion_score(): void
    {
        config(['ai.file_context.max_chunks_per_file' => 1]);

        $ctx = $this->makeContext([]);

        $this->perAngle = [
            'angle-1' => ['relevant_files' => [
                $this->fileRef(1, 0.90, 0, 'a') + ['fusion_score' => 0.005],
                $this->fileRef(2, 0.50, 0, 'b') + ['fusion_score' => 0.033],
            ]],
            'q' => ['relevant_files' => [
                $this->fileRef(1, 0.85, 3, 'a deeper') + ['fusion_score' => 0.005],
            ]],
        ];

        $files = $this->captureMergedFiles($ctx);

        $this->assertSame([1, 2], array_column($files, 'file_id'));
        $this->assertSame('a deeper', $files[0]['snippet']);
        $this->assertEqualsWithDelta(0.90, $files[0]['relevance'], 1e-9);
        $this->assertEqualsWithDelta(0.85, $files[0]['chunk_relevance'], 1e-9);
        foreach ($files as $file) {
            $this->assertArrayNotHasKey('fusion_score', $file);
        }
    }

    /**
     * Task 1.12: in the merge too, a host priority_score is only a tiebreak.
     *
     * @test
     */
    public function the_cross_angle_merge_uses_priority_score_only_as_a_tiebreak(): void
    {
        config(['ai.file_context.max_chunks_per_file' => 1]);

        $ctx = $this->makeContext([]);

        $this->perAngle = [
            'angle-1' => ['relevant_files' => [
                $this->fileRef(1, 0.41, 0, 'team') + ['priority_score' => 1.41],
                $this->fileRef(2, 0.80, 0, 'law') + ['priority_score' => 1.30],
            ]],
            'q' => ['relevant_files' => []],
        ];

        $files = $this->captureMergedFiles($ctx);

        $this->assertSame([2, 1], array_column($files, 'file_id'), 'the 0.80 law chunk leads the 0.41 boosted team file');
    }
}
