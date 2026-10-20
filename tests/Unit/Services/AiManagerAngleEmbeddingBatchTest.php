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
use Condoedge\Ai\EmbeddingProviders\MemoizedEmbeddingProvider;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * File-search latency (measured live 2026-10-06): every fan-out angle embedded its
 * own text with its own provider round-trip (~0.6-2 s each, sequential) — most of
 * the 9-13 s the stage took for 8 angles. The angles are all known before the
 * first search, so they are embedded in ONE batch call; the request-scoped
 * embedding memo then serves each angle's search without another round-trip.
 *
 * Only when the provider IS the memo (otherwise nothing would reuse the batch),
 * and a failing batch changes nothing: each angle embeds itself as before.
 */
class AiManagerAngleEmbeddingBatchTest extends TestCase
{
    /** @var list<list<string>> */
    private array $batches = [];

    /** @var list<string> */
    private array $singles = [];

    private bool $batchFails = false;

    /** @var list<string> */
    private array $searched = [];

    public function setUp(): void
    {
        parent::setUp();

        config(['logging.default' => 'null']);
        config(['ai.security.enabled' => false]);
        config(['ai.file_context.enabled' => true]);
        config(['ai.file_context.recall_floor' => 0]);

        $this->batches = $this->singles = $this->searched = [];
        $this->batchFails = false;
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function countingProvider(): EmbeddingProviderInterface
    {
        $inner = Mockery::mock(EmbeddingProviderInterface::class);
        // A different direction per text: identical vectors would make every probe
        // a duplicate of the question (AiManagerProbeFusionTest covers that rule).
        $vector = static function (string $text): array {
            $vector = array_fill(0, 32, 0.0);
            $vector[crc32($text) % 32] = 1.0;

            return $vector;
        };
        $inner->shouldReceive('embed')->andReturnUsing(function (string $text) use ($vector): array {
            $this->singles[] = $text;

            return $vector($text);
        });
        $inner->shouldReceive('embedBatch')->andReturnUsing(function (array $texts) use ($vector): array {
            if ($this->batchFails) {
                throw new \RuntimeException('embedding provider unavailable');
            }
            $this->batches[] = array_values($texts);

            return array_map($vector, $texts);
        });

        return $inner;
    }

    private function runKnowledgeTurn(EmbeddingProviderInterface $embedding): PipelineContext
    {
        // The file provider embeds each angle through the SAME provider, as the
        // real chunk store does.
        $files = Mockery::mock(FileContextProvider::class);
        $files->shouldReceive('getFileContext')->andReturnUsing(function ($query) use ($embedding) {
            $embedding->embed($query);
            $this->searched[] = $query;

            return ['relevant_files' => [], 'file_count' => 0];
        });

        $ctx = new PipelineContext();
        $ctx->question = 'Can I just swap out this broken part myself?';
        $ctx->qu = new QueryUnderstandingResult(
            searchQuery: 'swap broken part',
            questionType: 'knowledge_query',
            clarifiedQuestion: 'clarified form',
            corpusTranslations: ['fr' => 'sonde'],
        );
        $ctx->similarQueries = [];
        $ctx->graphSchema = ['labels' => ['Unit']];

        $context = Mockery::mock(ContextRetrieverInterface::class);
        $context->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $vectorStore->shouldReceive('collectionExists')->andReturn(true);
        $vectorStore->shouldReceive('upsert')->andReturn(true);
        $generator = Mockery::mock(QueryGeneratorInterface::class);
        $generator->shouldReceive('generate')->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (u:Unit) RETURN u', explanation: '', confidence: 0.9, warnings: [], metadata: [],
        ));
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->andReturn(new QueryExecutionResult(
            success: true, data: [['id' => 1]], stats: [], metadata: [], errors: [],
        ));
        $responses = Mockery::mock(ResponseGeneratorInterface::class);
        $responses->shouldReceive('generate')->andReturn([
            'answer' => 'ok', 'insights' => [], 'visualizations' => [], 'metadata' => [],
        ]);

        $manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $context,
            $embedding,
            Mockery::mock(LlmProviderInterface::class),
            $generator,
            $executor,
            $responses,
            $vectorStore,
            $files
        );
        $manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 1]]);

        return $ctx;
    }

    /** @test */
    public function all_angles_are_embedded_in_one_batch_and_no_angle_pays_its_own_round_trip(): void
    {
        $ctx = $this->runKnowledgeTurn(new MemoizedEmbeddingProvider($this->countingProvider()));

        $angles = ['swap broken part', $ctx->question, 'clarified form', 'sonde'];
        $this->assertSame($angles, $this->searched);
        $this->assertSame([$angles], $this->batches, 'ONE batch call carrying every angle');
        $this->assertSame([], array_values(array_intersect($this->singles, $angles)), 'no angle is embedded on its own');
    }

    /** @test */
    public function a_failing_batch_leaves_every_angle_to_embed_itself(): void
    {
        $this->batchFails = true;

        $ctx = $this->runKnowledgeTurn(new MemoizedEmbeddingProvider($this->countingProvider()));

        $angles = ['swap broken part', $ctx->question, 'clarified form', 'sonde'];
        $this->assertSame($angles, $this->searched, 'the search is unaffected');
        $this->assertSame($angles, array_values(array_intersect($this->singles, $angles)));
    }

    /** @test */
    public function a_provider_without_the_request_memo_is_not_batched(): void
    {
        // Nothing would reuse the batch: it would only add a round-trip.
        $this->runKnowledgeTurn($this->countingProvider());

        $this->assertSame([], $this->batches);
    }
}
