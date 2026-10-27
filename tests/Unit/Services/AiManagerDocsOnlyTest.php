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
use Condoedge\Ai\DTOs\ResolvedAudience;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Tests\Fixtures\FixedAudienceResolver;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Docs-only mode (ai.query_generation.enabled = false): no turn generates or runs a
 * query. Every question searches the documents — a data-looking one too, so the answer
 * can say where to see the data — and is answered from them, or from nothing.
 */
class AiManagerDocsOnlyTest extends TestCase
{
    private ContextRetrieverInterface $context;
    private EmbeddingProviderInterface $embedding;
    private QueryGeneratorInterface $generator;
    private QueryExecutorInterface $executor;
    private ResponseGeneratorInterface $responses;
    private VectorStoreInterface $vectors;
    private FileContextProvider $files;

    private int $searchCalls = 0;

    /** @var array|null the PipelineContext the composer was handed */
    private ?PipelineContext $composed = null;

    public function setUp(): void
    {
        parent::setUp();
        config([
            'ai.security.enabled' => false,
            'ai.file_context.enabled' => true,
            'ai.query_generation.enabled' => false,
        ]);

        $this->context = Mockery::mock(ContextRetrieverInterface::class);
        $this->embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $this->generator = Mockery::mock(QueryGeneratorInterface::class);
        $this->executor = Mockery::mock(QueryExecutorInterface::class);
        $this->responses = Mockery::mock(ResponseGeneratorInterface::class);
        $this->vectors = Mockery::mock(VectorStoreInterface::class);
        $this->files = Mockery::mock(FileContextProvider::class);

        $this->generator->shouldNotReceive('generate');
        $this->executor->shouldNotReceive('execute');
        $this->vectors->shouldReceive('collectionExists')->andReturn(true);
        $this->vectors->shouldReceive('upsert')->andReturn(true);
        $this->embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
        // A turn that fails is a test failure with its own exception, not an error answer.
        $this->responses->shouldReceive('generateErrorResponse')->andReturnUsing(static fn ($question, \Throwable $e) => throw $e);
        $this->responses->shouldReceive('generate')->andReturnUsing(function (PipelineContext $ctx) {
            $this->composed = $ctx;

            return ['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'metadata' => []];
        });
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function turn(string $questionType, array $relevantFiles): array
    {
        $this->files->shouldReceive('getFileContext')->andReturnUsing(function () use ($relevantFiles) {
            $this->searchCalls++;

            return ['relevant_files' => $relevantFiles, 'file_count' => count($relevantFiles)];
        });

        $ctx = new PipelineContext();
        $ctx->question = 'Combien de billets ai-je vendus?';
        $ctx->qu = new QueryUnderstandingResult(searchQuery: 'billets vendus', intent: 'count', questionType: $questionType, language: 'fr');
        $ctx->similarQueries = [];
        $ctx->graphSchema = [];
        $this->context->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->context,
            $this->embedding,
            Mockery::mock(LlmProviderInterface::class),
            $this->generator,
            $this->executor,
            $this->responses,
            $this->vectors,
            $this->files
        );

        return $manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 1]]);
    }

    private function article(): array
    {
        return [
            'file_id' => 'physical:aide/organisateur/ventes-voir-les-ventes.md',
            'file_name' => 'ventes-voir-les-ventes.md',
            'source' => 'physical',
            'relevance' => 0.71,
            'snippet' => 'Les ventes se voient dans « Transactions ».',
            'match_type' => 'content',
        ];
    }

    /** @test */
    public function a_data_looking_question_searches_the_documents_and_is_answered_from_them(): void
    {
        $result = $this->turn('data_query', [$this->article()]);

        $this->assertGreaterThan(0, $this->searchCalls, 'docs-only never skips the document search');
        $this->assertSame('ok', $result['answer']);
        $this->assertNull($this->composed->queryResult->cypher);
        $this->assertSame('file_context', $this->composed->queryResult->metadata['source'] ?? null);
        $this->assertSame('fr', $result['metadata']['question_language'] ?? null, 'the answer says which language the question was in');
    }

    /** @test */
    public function an_asker_whose_audience_reads_no_data_is_answered_from_the_documents_when_queries_are_on(): void
    {
        FixedAudienceResolver::$next = new ResolvedAudience('buyer');
        config([
            'ai.query_generation.enabled' => true,
            'ai.audiences' => ['resolver' => FixedAudienceResolver::class, 'map' => ['buyer' => ['data_entities' => []]]],
        ]);

        $result = $this->turn('data_query', [$this->article()]);

        $this->assertGreaterThan(0, $this->searchCalls, 'a data-looking question still searches the documents');
        $this->assertSame('ok', $result['answer']);
        $this->assertNull($this->composed->queryResult->cypher);
        $this->assertSame('file_context', $this->composed->queryResult->metadata['source'] ?? null);
    }

    /** @test */
    public function with_no_document_found_nothing_is_generated_and_the_abstention_says_no_document_answers(): void
    {
        $emptyOptions = null;
        $this->responses->shouldReceive('generateEmptyResponse')->andReturnUsing(function ($question, $cypher, array $options) use (&$emptyOptions) {
            $emptyOptions = $options + ['cypher' => $cypher];

            return ['answer' => 'none', 'metadata' => []];
        });

        $result = $this->turn('hybrid', []);

        $this->assertSame('none', $result['answer']);
        $this->assertNull($emptyOptions['cypher']);
        $this->assertTrue($emptyOptions['docs_only']);
    }
}
