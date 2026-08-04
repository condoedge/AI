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
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Exceptions\QueryGenerationException;
use Mockery;

/**
 * Tests for AiManager facade pass-through methods.
 *
 * Covers:
 * - P0-1: syncRelationships() delegates to the ingestion service (the observer
 *   calls AI::syncRelationships([...]) so the method must exist).
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

        config()->set('logging.default', 'null');
        config()->set('ai.log_channel', 'null');

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
            ->andReturn(new QueryGenerationResult(
                cypher: null,
                explanation: '',
                confidence: 0.0,
                warnings: [],
                metadata: [],
            ));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(
                success: true,
                data: [],
                stats: [],
                metadata: [],
                errors: [],
            ));

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
            ->andReturn(new QueryGenerationResult(
                cypher: 'MATCH (c:Customer {name:"Zzzz"}) RETURN c',
                explanation: 'lookup',
                confidence: 0.7,
                warnings: [],
                metadata: [],
            ));

        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);

        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(
                success: true,
                data: [],
                stats: [],
                metadata: [],
                errors: [],
            ));

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
    public function export_pdf_intent_uses_previous_result_sample_instead_of_empty_result_fallback(): void
    {
        config()->set('ai.file_context.enabled', false);

        $ctx = new PipelineContext();
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(
                cypher: null,
                explanation: '',
                confidence: 0.0,
                warnings: [],
                metadata: [],
            ));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []));

        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion('Can you create a pdf of my information?', [
            'conversation_context' => [
                'last_result_sample' => [
                    [
                        'name' => 'Alice Martin',
                        'role' => 'Owner',
                        'user_id' => 123,
                        'neo4j_id' => 'abc',
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('I prepared the PDF', $result['answer']);
        $this->assertStringContainsString('Fields included: Name, Role.', $result['answer']);
        $this->assertStringContainsString('action://export_pdf?mode=document', $result['answer']);
        $this->assertStringNotContainsString('| Name | Role |', $result['answer']);
        $this->assertStringNotContainsString('User Id', $result['answer']);
        $this->assertStringNotContainsString('neo4j', strtolower($result['answer']));
        $document = $result['metadata']['response']['export_document_markdown'];
        // Previous-result export with no recorded subject: the request wording is
        // BANNED as a title (live bug: echoed filenames) — generic title instead.
        $this->assertStringContainsString('# Requested Information', $document);
        $this->assertStringNotContainsString('Can you create a pdf', $document);
        $this->assertStringContainsString('| Name | Role |', $document);
        $this->assertStringContainsString('Alice Martin', $document);
        $this->assertStringNotContainsString('Download PDF', $document);
        $this->assertTrue($result['metadata']['response']['export_intent']);
        $this->assertSame('previous_result', $result['metadata']['response']['export_source']);
    }

    /** @test */
    public function possessive_export_without_rows_uses_configured_identity_context_before_clarifying(): void
    {
        config()->set('ai.file_context.enabled', false);
        config()->set('ai.export.identity_fields', [
            ['label' => 'Name', 'path' => 'user.name'],
            ['label' => 'Email', 'path' => 'user.email'],
            ['label' => 'Team', 'path' => 'team.team_name'],
        ]);

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(isPossessive: true);

        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(
                cypher: null,
                explanation: '',
                confidence: 0.0,
                warnings: [],
                metadata: [],
            ));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []));

        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion('Can you create a pdf with my information?', [
            'user' => (object) [
                'name' => 'Julie Rousseau',
                'email' => 'j.rousseau@novogestion.ca',
                'phone' => '514-555-0100',
                'address' => '123 Rue Principale',
                'password' => 'secret',
                'current_team_id' => 3,
            ],
            'team' => (object) [
                'team_name' => 'Gestion Centrale',
                'website' => 'https://example.test',
                'id' => 3,
            ],
        ]);

        $document = $result['metadata']['response']['export_document_markdown'];
        $this->assertStringContainsString('# User Information', $document);
        $this->assertStringContainsString('| Name | Email | Team |', $document);
        $this->assertStringContainsString('Julie Rousseau', $document);
        $this->assertStringContainsString('j.rousseau@novogestion.ca', $document);
        $this->assertStringContainsString('Gestion Centrale', $document);
        $this->assertStringContainsString('User Phone', $document);
        $this->assertStringContainsString('514-555-0100', $document);
        $this->assertStringContainsString('User Address', $document);
        $this->assertStringContainsString('123 Rue Principale', $document);
        $this->assertStringContainsString('Team Website', $document);
        $this->assertStringNotContainsString('password', strtolower($document));
        $this->assertStringNotContainsString('current_team_id', strtolower($document));
        $this->assertStringContainsString('Fields included:', $result['answer']);
        $this->assertStringContainsString('User Phone', $result['answer']);
        $this->assertStringNotContainsString('| Name | Email | Team |', $result['answer']);
        $this->assertStringNotContainsString('which information to include', $result['answer']);
        $this->assertSame('current_identity', $result['metadata']['response']['export_source']);
    }

    /** @test */
    public function english_export_pdf_intent_without_source_asks_for_clarification_even_when_locale_is_french(): void
    {
        config()->set('ai.file_context.enabled', false);
        app()->setLocale('fr');

        $ctx = new PipelineContext();
        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(
                cypher: null,
                explanation: '',
                confidence: 0.0,
                warnings: [],
                metadata: [],
            ));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []));

        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion('Can you create a pdf of my team?');

        $this->assertStringContainsString('I can create a PDF', $result['answer']);
        $this->assertStringContainsString('which information to include', $result['answer']);
        $this->assertStringNotContainsString('Je peux', $result['answer']);
        $this->assertStringNotContainsString('No results', $result['answer']);
        $this->assertTrue($result['metadata']['response']['export_intent']);
        $this->assertTrue($result['metadata']['response']['needs_export_source']);
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
            ->andReturn(new QueryExecutionResult(
                success: true,
                data: [],
                stats: [],
                metadata: [],
                errors: [],
            ));

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

    // =========================================================================
    // Export intent: QU-first, regex fallback (the cold-roster-excel MUST-FLIP)
    // =========================================================================

    /** @test */
    public function qu_export_format_fires_the_export_path_when_the_phrase_regex_misses(): void
    {
        // The live user failure: "Can i get an excel..." — no verb from the
        // deterministic list, no document-shape match. QU's semantic judgment
        // must drive the export; the composer regex is only a fallback.
        config()->set('ai.file_context.enabled', false);

        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = ['user_name' => "Member {$i}", 'user_email' => "m{$i}@x.com"];
        }

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(exportFormat: 'excel', language: 'en');

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(
                cypher: 'MATCH (u:User) RETURN u.name AS user_name, u.email AS user_email LIMIT 100',
                explanation: '',
                confidence: 0.9,
                warnings: [],
                metadata: [],
            ));

        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: $rows, stats: [], metadata: [], errors: []));

        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $result = $this->manager->answerQuestion(
            'Can i get an excel with all the members from my current team? With contact info'
        );

        $this->assertTrue($result['metadata']['response']['export_intent']);
        $this->assertSame('excel', $result['metadata']['response']['export_format']);
        $this->assertSame('current_result', $result['metadata']['response']['export_source']);

        // FULL result set, not the 10-row display sample.
        $this->assertSame(100, $result['metadata']['response']['result_count']);
        $document = $result['metadata']['response']['export_document_markdown'];
        $this->assertStringContainsString('Member 1', $document);
        $this->assertStringContainsString('Member 100', $document);
    }

    /** @test */
    public function composer_regex_remains_the_fallback_when_qu_produced_no_export_format(): void
    {
        // qu->exportFormat null (e.g. QU call failed) + a phrase the deterministic
        // regex DOES catch → the export path still fires.
        config()->set('ai.file_context.enabled', false);

        $ctx = new PipelineContext(); // default QU result: exportFormat null
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: null, explanation: '', confidence: 0.0, warnings: [], metadata: []));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []));

        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion('export that to Excel', [
            'conversation_context' => [
                'last_result_sample' => [['name' => 'Alice']],
            ],
        ]);

        $this->assertTrue($result['metadata']['response']['export_intent']);
        $this->assertSame('excel', $result['metadata']['response']['export_format']);
        $this->assertSame('previous_result', $result['metadata']['response']['export_source']);
    }

    // =========================================================================
    // Follow-up export: re-execute the recorded cypher for the FULL set
    // =========================================================================

    /** @test */
    public function follow_up_export_re_executes_the_recorded_cypher_through_the_scoped_executor(): void
    {
        config()->set('ai.file_context.enabled', false);

        $recordedCypher = 'MATCH (u:User) RETURN u.name AS name, u.email AS email LIMIT 100';
        $fullRows = [];
        for ($i = 1; $i <= 100; $i++) {
            $fullRows[] = ['name' => "Member {$i}", 'email' => "m{$i}@x.com"];
        }
        $user = (object) ['id' => 13];

        $ctx = new PipelineContext();
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: null, explanation: '', confidence: 0.0, warnings: [], metadata: []));

        // First execute: the (null-cypher) pipeline execution. Second execute: the
        // previous-result re-execution — MUST receive the recorded cypher AND the
        // same identity options (tenant scoping happens inside the executor from
        // options.user), never a raw unscoped path.
        $this->mockQueryExecutor->shouldReceive('execute')
            ->twice()
            ->andReturnUsing(function ($cypher, $params, $options) use ($recordedCypher, $fullRows, $user) {
                if ($cypher === $recordedCypher) {
                    $this->assertSame($user, $options['user'] ?? null, 're-execution must carry the pipeline user for tenant scoping');
                    $this->assertSame('Perfect - give me that as an Excel with their contact info.', $options['original_question'] ?? null);
                    return new QueryExecutionResult(success: true, data: $fullRows, stats: [], metadata: [], errors: []);
                }

                return new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []);
            });

        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        // The 100-row cap hit triggers the companion count; here it FAILS, so the
        // pre-existing "true total unknown" behavior must be preserved unchanged.
        $this->mockQueryExecutor->shouldReceive('executeCount')
            ->andThrow(new \RuntimeException('count backend unavailable'));

        $result = $this->manager->answerQuestion('Perfect - give me that as an Excel with their contact info.', [
            'user' => $user,
            'conversation_context' => [
                'last_cypher_query' => $recordedCypher,
                'last_result_count' => 100,
                'last_result_sample' => array_slice($fullRows, 0, 10),
            ],
        ]);

        $this->assertSame('previous_result', $result['metadata']['response']['export_source']);
        // 100 rows == the executor's silent LIMIT cap and the companion count
        // failed: the true total is unknown, so completeness must NOT be claimed
        // (reviewer finding: a capped export claiming complete suppresses the
        // "more may exist" disclosure).
        $this->assertFalse($result['metadata']['response']['export_source_complete']);
        $this->assertSame(100, $result['metadata']['response']['result_count']);

        $document = $result['metadata']['response']['export_document_markdown'];
        $this->assertStringContainsString('Member 100', $document, 'export must carry the FULL re-executed set, not the 10-row sample');
        $this->assertStringContainsString('more may exist', $document, 'a cap-hit export must disclose that more rows may exist');
    }

    /** @test */
    public function follow_up_export_falls_back_to_the_sample_with_disclosure_when_re_execution_fails(): void
    {
        config()->set('ai.file_context.enabled', false);

        $recordedCypher = 'MATCH (u:User) RETURN u.name AS name LIMIT 100';
        $sample = [];
        for ($i = 1; $i <= 10; $i++) {
            $sample[] = ['name' => "Member {$i}"];
        }

        $ctx = new PipelineContext();
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: null, explanation: '', confidence: 0.0, warnings: [], metadata: []));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->twice()
            ->andReturnUsing(function ($cypher) use ($recordedCypher) {
                if ($cypher === $recordedCypher) {
                    throw new \RuntimeException('Neo4j unavailable');
                }

                return new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []);
            });

        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion('give me that as an Excel of my team', [
            'conversation_context' => [
                'last_cypher_query' => $recordedCypher,
                'last_result_count' => 100,
                'last_result_sample' => $sample,
            ],
        ]);

        $this->assertSame('previous_result', $result['metadata']['response']['export_source']);
        $this->assertFalse($result['metadata']['response']['export_source_complete']);
        $this->assertSame(10, $result['metadata']['response']['result_count']);
        $this->assertSame(100, $result['metadata']['response']['result_total']);
        // Explicit "10 of 100" disclosure in the visible answer AND the payload.
        $this->assertStringContainsString('10 of 100', $result['answer']);
        $this->assertStringContainsString('Showing 10 of 100 rows.', $result['metadata']['response']['export_document_markdown']);
    }

    /** @test */
    public function follow_up_export_without_recorded_cypher_uses_the_sample_and_discloses_the_recorded_total(): void
    {
        config()->set('ai.file_context.enabled', false);

        $ctx = new PipelineContext();
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: null, explanation: '', confidence: 0.0, warnings: [], metadata: []));

        // Only the pipeline execution — no cypher recorded, so NO re-execution call.
        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []));

        $result = $this->manager->answerQuestion('export that to Excel', [
            'conversation_context' => [
                'last_result_count' => 42,
                'last_result_sample' => [['name' => 'Alice'], ['name' => 'Bob']],
            ],
        ]);

        $this->assertSame(42, $result['metadata']['response']['result_total']);
        $this->assertStringContainsString('2 of 42', $result['answer']);
    }

    /** @test */
    public function follow_up_export_after_a_digest_turn_carries_every_persisted_row_and_is_complete(): void
    {
        config()->set('ai.file_context.enabled', false);

        // B1: a digest turn records no cypher but persists its FULL
        // display-capped row set as the snapshot sample. The follow-up
        // "export that" must materialize ALL of it and be marked complete —
        // the digest turn promised "the file with everything shown here".
        $sample = [];
        for ($i = 1; $i <= 28; $i++) {
            $sample[] = ['id' => $i, 'title' => "Task {$i}", 'attention_source' => 'open_tasks'];
        }

        $ctx = new PipelineContext();
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: null, explanation: '', confidence: 0.0, warnings: [], metadata: []));

        // Only the (null-cypher) pipeline execution — no cypher recorded, so
        // NO re-execution call.
        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturn(new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []));

        $result = $this->manager->answerQuestion('export that to Excel', [
            'conversation_context' => [
                'last_cypher_query' => '',
                'last_result_count' => 28,
                'last_result_sample' => $sample,
            ],
        ]);

        $this->assertSame('previous_result', $result['metadata']['response']['export_source']);
        $this->assertTrue(
            $result['metadata']['response']['export_source_complete'],
            'the full persisted digest set IS complete — the B1 promise holds'
        );
        $this->assertSame(28, $result['metadata']['response']['result_count']);
        $this->assertStringContainsString(
            'Task 28',
            $result['metadata']['response']['export_document_markdown'],
            'every digest row reaches the export document, not a 10-row slice'
        );
    }

    // =========================================================================
    // Error-path language policy (ambiguous-balance t1 regression companion)
    // =========================================================================

    /** @test */
    public function infrastructure_error_response_receives_the_qu_detected_question_language(): void
    {
        // iter-3 live failure: an ENGLISH question hit the generation error path in
        // a French-locale host and got the FRENCH canned error. The QU-detected
        // question language must be threaded into generateErrorResponse options.
        config()->set('ai.file_context.enabled', false);

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(language: 'en');

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andThrow(new QueryGenerationException('Failed after 3 attempts. Last error: HTTP 429 rate limit'));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []));

        $this->mockResponseGenerator->shouldReceive('generateErrorResponse')
            ->once()
            ->with(
                'I mean what unit 310 owes.',
                Mockery::type(\Throwable::class),
                Mockery::on(fn ($options) => ($options['question_language'] ?? null) === 'en')
            )
            ->andReturn([
                'answer' => 'I ran into a problem answering that. Please try again.',
                'insights' => [],
                'visualizations' => [],
                'format' => 'text',
                'metadata' => ['error' => true, 'error_type' => 'RuntimeException'],
            ]);

        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion('I mean what unit 310 owes.');

        $this->assertTrue($result['metadata']['response']['error']);
        $this->assertStringContainsString('try again', $result['answer']);
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
            ->andReturn(new QueryGenerationResult(
                cypher: null,
                explanation: '',
                confidence: 0.0,
                warnings: [],
                metadata: [],
            ));
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []));

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

    // =========================================================================
    // C4: "Explain this page" — page-explanation narration (read-only, no query)
    // =========================================================================

    /** @test */
    public function page_explanation_intent_with_configured_description_narrates_without_a_query(): void
    {
        config()->set('ai.file_context.enabled', false);
        config()->set('ai.chat.page_explanation_intents', ['page_explanation']);
        config()->set('ai.chat.page_descriptions', [
            'Unit' => 'This page shows a single unit: its owners, balance and payment history.',
        ]);

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(intent: 'page_explanation');

        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn($ctx);

        // Narration is grounded ONLY on the host page description + route — never a
        // graph query, never the empty-result abstention path.
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockQueryExecutor->shouldReceive('execute')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        // The grounded generate() path runs once, with the host description and the
        // current route stamped onto the context the section reads.
        $captured = null;
        $this->mockResponseGenerator->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function (PipelineContext $c) use (&$captured) {
                $captured = $c;
                return true;
            }), Mockery::any())
            ->andReturn([
                'answer' => 'This screen lets you review a unit and its payment history.',
                'insights' => [],
                'visualizations' => [],
                'format' => 'text',
                'metadata' => [],
            ]);

        $result = $this->manager->answerQuestion('What is this page and what can I do here?', [
            'page_context' => [
                'route' => 'units.show',
                'entity_type' => 'Unit',
                'entity_id' => 402,
                'label' => 'Unit 402',
            ],
        ]);

        $this->assertStringContainsString('payment history', $result['answer']);
        // It is narration, not a query attempt: no Cypher, no rows.
        $this->assertNull($result['cypher']);
        $this->assertSame([], $result['data']);
        $this->assertSame('page_explanation', $result['metadata']['response']['intent'] ?? null);

        // The host description AND the route the chat was opened from reached the
        // grounding the responder narrated over.
        $this->assertInstanceOf(PipelineContext::class, $captured);
        $this->assertStringContainsString('payment history', $captured->pageDescription);
        $this->assertSame('units.show', $captured->pageContext['route'] ?? null);
    }

    /** @test */
    public function page_explanation_intent_runs_the_normal_pipeline_when_no_description_is_configured(): void
    {
        // Default-off: with no page_descriptions configured, a page-explanation intent
        // is just a normal turn — the query/abstention path is unchanged.
        config()->set('ai.file_context.enabled', false);
        config()->set('ai.chat.page_descriptions', []);

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(intent: 'page_explanation');

        $this->mockContext->shouldReceive('retrieveContext')
            ->once()
            ->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(
                cypher: null,
                explanation: '',
                confidence: 0.0,
                warnings: [],
                metadata: [],
            ));
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []));

        // No description to narrate over → the ordinary empty/abstention path, not narration.
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

        $result = $this->manager->answerQuestion('What is this page?', [
            'page_context' => [
                'route' => 'units.show',
                'entity_type' => 'Unit',
                'entity_id' => 402,
                'label' => 'Unit 402',
            ],
        ]);

        $this->assertSame('No results were found.', $result['answer']);
        $this->assertTrue($result['metadata']['response']['empty_result']);
    }
}
