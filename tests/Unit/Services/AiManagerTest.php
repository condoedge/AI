<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Exceptions\QueryGenerationException;
use Mockery;

/**
 * Tests for AiManager facade pass-through methods.
 *
 * Covers:
 * - P0-1: syncRelationships() delegates to the ingestion service (the observer
 *   calls AI::syncRelationships([...]) so the method must exist).
 * - P0-5: searchSimilar(string, array $options) must unpack the options array
 *   into the retriever's (string $collection, int $limit) signature without a
 *   TypeError under strict_types.
 * - P0-6: getExampleEntities(array $labels, int $limit) must call the retriever
 *   per label (string $label) and merge results without a TypeError.
 */
class AiManagerTest extends TestCase
{
    private AiManager $manager;
    private DataIngestionServiceInterface $mockIngestion;
    private ContextRetrieverInterface $mockContext;
    private EmbeddingProviderInterface $mockEmbedding;
    private LlmProviderInterface $mockLlm;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;
    private VectorStoreInterface $mockVectorStore;

    public function setUp(): void
    {
        parent::setUp();

        $this->mockIngestion = Mockery::mock(DataIngestionServiceInterface::class);
        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockEmbedding = Mockery::mock(EmbeddingProviderInterface::class);
        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockVectorStore = Mockery::mock(VectorStoreInterface::class);

        $this->manager = new AiManager(
            $this->mockIngestion,
            $this->mockContext,
            $this->mockEmbedding,
            $this->mockLlm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            $this->mockVectorStore
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // P0-1: syncRelationships pass-through
    // =========================================================================

    /** @test */
    public function sync_relationships_delegates_to_ingestion_service(): void
    {
        $entities = [
            Mockery::mock(\Condoedge\Ai\Domain\Contracts\Nodeable::class),
            Mockery::mock(\Condoedge\Ai\Domain\Contracts\Nodeable::class),
        ];

        $summary = [
            'total_entities' => 2,
            'total_relationships_checked' => 4,
            'relationships_created' => 1,
            'relationships_skipped' => 3,
            'relationships_failed' => 0,
            'errors' => [],
        ];

        $this->mockIngestion->shouldReceive('syncRelationships')
            ->once()
            ->with($entities)
            ->andReturn($summary);

        $result = $this->manager->syncRelationships($entities);

        $this->assertSame($summary, $result);
    }

    // =========================================================================
    // P0-5: searchSimilar unpacks options array
    // =========================================================================

    /** @test */
    public function search_similar_unpacks_options_without_type_error(): void
    {
        $question = 'Find all customers';
        $expected = [
            ['question' => 'Find all customers', 'query' => 'MATCH (c:Customer) RETURN c', 'score' => 0.9],
        ];

        // The retriever signature is (string $collection, int $limit) — passing the
        // raw options array would be a TypeError. The manager must unpack it.
        $this->mockContext->shouldReceive('searchSimilar')
            ->once()
            ->with($question, 'people', 10)
            ->andReturn($expected);

        $result = $this->manager->searchSimilar($question, [
            'collection' => 'people',
            'limit' => 10,
        ]);

        $this->assertSame($expected, $result);
    }

    /** @test */
    public function search_similar_uses_defaults_when_options_empty(): void
    {
        $question = 'Find all teams';

        $this->mockContext->shouldReceive('searchSimilar')
            ->once()
            ->with($question, 'questions', 5)
            ->andReturn([]);

        $result = $this->manager->searchSimilar($question);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // P0-6: getExampleEntities loops labels and merges
    // =========================================================================

    /** @test */
    public function get_example_entities_loops_labels_and_merges_without_type_error(): void
    {
        $teamExamples = [
            ['id' => 1, 'name' => 'Alpha Team'],
            ['id' => 2, 'name' => 'Beta Team'],
        ];
        $personExamples = [
            ['id' => 10, 'name' => 'Alice'],
        ];

        // The retriever signature is (string $label, int $limit) — passing the
        // labels array would be a TypeError. The manager must call per label.
        $this->mockContext->shouldReceive('getExampleEntities')
            ->once()
            ->with('Team', 3)
            ->andReturn($teamExamples);

        $this->mockContext->shouldReceive('getExampleEntities')
            ->once()
            ->with('Person', 3)
            ->andReturn($personExamples);

        $result = $this->manager->getExampleEntities(['Team', 'Person']);

        $this->assertSame(
            ['Team' => $teamExamples, 'Person' => $personExamples],
            $result
        );
    }

    /** @test */
    public function get_example_entities_forwards_limit_per_label(): void
    {
        $this->mockContext->shouldReceive('getExampleEntities')
            ->once()
            ->with('Customer', 5)
            ->andReturn([['id' => 1]]);

        $result = $this->manager->getExampleEntities(['Customer'], 5);

        $this->assertSame(['Customer' => [['id' => 1]]], $result);
    }

    // =========================================================================
    // P1-D3: knowledge-query abstention (no confident ungrounded answers)
    // =========================================================================

    /**
     * Build a manager whose context returns a knowledge_query PipelineContext
     * with no file context found anywhere.
     */
    private function makeKnowledgeQueryManagerWithoutFiles(): AiManager
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'knowledge_query');

        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn($ctx);

        $fileProvider = Mockery::mock(\Condoedge\Ai\Services\Context\FileContextProvider::class);
        $fileProvider->shouldReceive('getFileContext')
            ->andReturn([
                'relevant_files' => [],
                'file_count' => 0,
                'has_physical' => false,
                'has_database' => false,
            ]);

        return new AiManager(
            $this->mockIngestion,
            $this->mockContext,
            $this->mockEmbedding,
            $this->mockLlm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            $this->mockVectorStore,
            $fileProvider
        );
    }

    /** @test */
    public function knowledge_query_without_files_does_not_assert_confidence_one_from_documents(): void
    {
        config()->set('ai.file_context.enabled', true);

        $manager = $this->makeKnowledgeQueryManagerWithoutFiles();

        // When no documents were found, the pipeline must NOT short-circuit into a
        // "answered from documents" confidence-1.0 result. It falls through to the
        // normal query path, which here returns no Cypher/no data, so the final
        // response is the dedicated empty/abstention response — NOT generate().
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn([
                'cypher' => null,
                'explanation' => '',
                'confidence' => 0.0,
                'warnings' => [],
                'metadata' => [],
            ]);

        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn([
                'data' => [],
                'stats' => [],
                'metadata' => [],
                'errors' => [],
            ]);

        // The abstention path must be used: generateEmptyResponse, not generate.
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')
            ->once()
            ->andReturn([
                'answer' => 'I could not find any source for that.',
                'insights' => [],
                'visualizations' => [],
                'format' => 'text',
                'metadata' => ['empty_result' => true, 'result_count' => 0],
            ]);

        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $manager->answerQuestion('What does the policy say about pets?');

        $this->assertSame('I could not find any source for that.', $result['answer']);
        $this->assertTrue($result['metadata']['response']['empty_result']);
        // No confident "answered from documents" provenance leaked through.
        $this->assertNotSame(1.0, $result['metadata']['query']['confidence'] ?? null);
    }

    /** @test */
    public function empty_results_with_no_file_context_emit_abstention_response(): void
    {
        config()->set('ai.file_context.enabled', false);

        $ctx = new PipelineContext();
        // Default questionType is 'hybrid' — a normal data query path.
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn([
                'cypher' => 'MATCH (c:Customer {name:"Zzzz"}) RETURN c',
                'explanation' => 'lookup',
                'confidence' => 0.7,
                'warnings' => [],
                'metadata' => [],
            ]);

        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);

        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn([
                'data' => [],
                'stats' => [],
                'metadata' => [],
                'errors' => [],
            ]);

        // Empty data + no files => dedicated abstention method, never generate().
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')
            ->once()
            ->andReturn([
                'answer' => 'No results were found.',
                'insights' => [],
                'visualizations' => [],
                'format' => 'text',
                'metadata' => ['empty_result' => true, 'result_count' => 0],
            ]);

        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion('Find customers named Zzzz');

        $this->assertSame('No results were found.', $result['answer']);
        $this->assertTrue($result['metadata']['response']['empty_result']);
    }

    /** @test */
    public function infrastructure_failure_is_reported_honestly_not_as_a_no_results_claim(): void
    {
        // When the LLM/query pipeline is unavailable (e.g. the circuit breaker is OPEN),
        // query generation exhausts its retries and throws. AiManager::generateQuery catches
        // that and surfaces metadata.error=true with a null cypher. The pipeline must NOT
        // collapse that into "No results were found for your question" — telling the user
        // their data does not exist when in fact the service is down is a correctness bug.
        config()->set('ai.file_context.enabled', false);

        $ctx = new PipelineContext();
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andThrow(new QueryGenerationException(
                "Failed to generate valid query after 3 attempts. Last error: Circuit breaker 'llm' is OPEN. Service unavailable."
            ));

        // execute(null) ran nothing — no rows, no errors of its own.
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn([
                'data' => [],
                'stats' => [],
                'metadata' => [],
                'errors' => [],
            ]);

        // The honest error response must be used — never the empty/abstention path.
        $this->mockResponseGenerator->shouldReceive('generateErrorResponse')
            ->once()
            ->andReturn([
                'answer' => 'The assistant is temporarily unavailable. Please try again in a moment.',
                'insights' => [],
                'visualizations' => [],
                'format' => 'text',
                'metadata' => ['error' => true, 'error_type' => QueryGenerationException::class],
            ]);

        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion('How much do I owe?');

        $this->assertTrue($result['metadata']['response']['error'] ?? false);
        $this->assertArrayNotHasKey('empty_result', $result['metadata']['response']);
    }

    /** @test */
    public function knowledge_query_with_files_answers_from_documents(): void
    {
        config()->set('ai.file_context.enabled', true);

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'knowledge_query');

        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn($ctx);

        $fileProvider = Mockery::mock(\Condoedge\Ai\Services\Context\FileContextProvider::class);
        $fileProvider->shouldReceive('getFileContext')
            ->andReturn([
                'relevant_files' => [
                    ['file_id' => 1, 'filename' => 'policy.pdf', 'snippet' => 'Pets allowed', 'relevance' => 0.9, 'source' => 'database'],
                ],
                'file_count' => 1,
                'has_physical' => false,
                'has_database' => true,
            ]);

        $manager = new AiManager(
            $this->mockIngestion,
            $this->mockContext,
            $this->mockEmbedding,
            $this->mockLlm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            $this->mockVectorStore,
            $fileProvider
        );

        // With documents present, the normal generate() path runs (no abstention,
        // no Cypher generation for a knowledge query).
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockQueryExecutor->shouldReceive('execute')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn([
                'answer' => 'Pets are allowed per the policy [1].',
                'insights' => [],
                'visualizations' => [],
                'format' => 'text',
                'metadata' => [],
            ]);

        $result = $manager->answerQuestion('What does the policy say about pets?');

        $this->assertStringContainsString('Pets are allowed', $result['answer']);
    }

    // =========================================================================
    // File enumeration routing (capability A — Q4)
    // =========================================================================

    /** @test */
    public function file_listing_intent_routes_to_enumeration_not_semantic_search(): void
    {
        config()->set('ai.file_context.enabled', true);
        config()->set('ai.file_context.enumeration.enabled', true);
        config()->set('ai.file_context.enumeration.intents', ['file_listing']);

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(intent: 'file_listing');

        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn($ctx);

        $fileProvider = Mockery::mock(\Condoedge\Ai\Services\Context\FileContextProvider::class);

        // The enumeration path must be used — NOT semantic getFileContext().
        $fileProvider->shouldReceive('getFileEnumerationContext')
            ->once()
            ->andReturn([
                'relevant_files' => [
                    ['file_id' => 10, 'filename' => 'lease.pdf', 'type' => 'pdf', 'source' => 'database'],
                    ['file_id' => 11, 'filename' => 'rules.docx', 'type' => 'docx', 'source' => 'database'],
                ],
                'file_count' => 2,
                'has_physical' => false,
                'has_database' => true,
                'enumeration' => true,
            ]);
        $fileProvider->shouldReceive('getFileContext')->never();

        $manager = new AiManager(
            $this->mockIngestion,
            $this->mockContext,
            $this->mockEmbedding,
            $this->mockLlm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            $this->mockVectorStore,
            $fileProvider
        );

        // Enumeration grounds the answer on the file list — no Cypher, no executor,
        // no abstention. The normal grounded generate() path produces the listing.
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockQueryExecutor->shouldReceive('execute')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->andReturn([
                'answer' => 'You have access to lease.pdf and rules.docx.',
                'insights' => [],
                'visualizations' => [],
                'format' => 'text',
                'metadata' => [],
            ]);

        $result = $manager->answerQuestion('Which files do I have access to?');

        $this->assertStringContainsString('lease.pdf', $result['answer']);
        // metadata.query is the queryResult metadata array; enumeration tags it.
        $this->assertSame('file_context', $result['metadata']['query']['source'] ?? null);
        $this->assertSame('file_listing', $result['metadata']['query']['intent'] ?? null);
    }

    /** @test */
    public function file_listing_intent_falls_back_to_search_when_no_accessible_files(): void
    {
        config()->set('ai.file_context.enabled', true);
        config()->set('ai.file_context.enumeration.enabled', true);
        config()->set('ai.file_context.enumeration.intents', ['file_listing']);

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(intent: 'file_listing');

        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn($ctx);

        $fileProvider = Mockery::mock(\Condoedge\Ai\Services\Context\FileContextProvider::class);

        // Enumeration finds nothing the user can access → fall through to normal
        // semantic retrieval rather than asserting an empty list.
        $fileProvider->shouldReceive('getFileEnumerationContext')
            ->once()
            ->andReturn([
                'relevant_files' => [],
                'file_count' => 0,
                'has_physical' => false,
                'has_database' => false,
                'enumeration' => true,
            ]);
        $fileProvider->shouldReceive('getFileContext')
            ->andReturn([
                'relevant_files' => [],
                'file_count' => 0,
                'has_physical' => false,
                'has_database' => false,
            ]);

        $manager = new AiManager(
            $this->mockIngestion,
            $this->mockContext,
            $this->mockEmbedding,
            $this->mockLlm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            $this->mockVectorStore,
            $fileProvider
        );

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn([
                'cypher' => null,
                'explanation' => '',
                'confidence' => 0.0,
                'warnings' => [],
                'metadata' => [],
            ]);
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(['data' => [], 'stats' => [], 'metadata' => [], 'errors' => []]);

        // No files anywhere → honest abstention, not a fabricated listing.
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')
            ->once()
            ->andReturn([
                'answer' => 'I could not find any files you can access.',
                'insights' => [],
                'visualizations' => [],
                'format' => 'text',
                'metadata' => ['empty_result' => true, 'result_count' => 0],
            ]);
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $manager->answerQuestion('Which files do I have access to?');

        $this->assertStringContainsString('could not find', $result['answer']);
    }
}
