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
        // QU's export judgment (the only export signal since Phase 2.B).
        $ctx->qu = new QueryUnderstandingResult(exportFormat: 'pdf', language: 'en');
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
        $ctx->qu = new QueryUnderstandingResult(isPossessive: true, exportFormat: 'pdf', language: 'en');

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
        // QU judged: a PDF ask, written in English — its language beats the app locale.
        $ctx->qu = new QueryUnderstandingResult(exportFormat: 'pdf', language: 'en');
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
    // Export intent: QU only (the cold-roster-excel MUST-FLIP)
    // =========================================================================

    /** @test */
    public function qu_export_format_fires_the_export_path_when_the_phrase_regex_misses(): void
    {
        // The live user failure: "Can i get an excel..." — no verb from the
        // deterministic list, no document-shape match. QU's semantic judgment
        // drives the export (it is the only export signal).
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

    /**
     * @test Eval p12-B french t1: a French export was titled "Team members roles" — the
     * title came from search_query (English-normalized for semantic search). The
     * user-visible title is QU's subject_label (the question's language), and the turn's
     * metadata.subject (recorded as last_subject) is that same label.
     */
    public function a_french_current_result_export_is_titled_by_the_localized_subject_label(): void
    {
        config()->set('ai.file_context.enabled', false);

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(
            entityTypes: ['User'],
            searchQuery: 'team members roles',
            questionType: 'data_query',
            exportFormat: 'excel',
            language: 'fr',
            subjectLabel: "Rôles des membres de l'équipe",
        );
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: 'MATCH (u:User) RETURN u.name AS member_name, u.role AS role', explanation: '', confidence: 0.9, warnings: [], metadata: []));
        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(success: true, data: [['member_name' => 'Julie Rousseau', 'role' => 'lead']], stats: [], metadata: [], errors: []));
        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $result = $this->manager->answerQuestion("Peux-tu me donner un Excel des membres de l'équipe avec leurs rôles ?");

        $this->assertSame('current_result', $result['metadata']['response']['export_source']);
        $document = $result['metadata']['response']['export_document_markdown'];
        $this->assertStringContainsString("# Rôles des membres de l'équipe", $document);
        $this->assertStringNotContainsString('Team members roles', $document);
        $this->assertStringNotContainsString('Team members roles', $result['answer']);
        // Recorded as last_subject: a later previous-result export keeps this title.
        $this->assertSame("Rôles des membres de l'équipe", $result['metadata']['subject']);
    }

    /** @test Without a label (older QU reply), the subject falls back to search_query. */
    public function without_a_subject_label_the_recorded_subject_falls_back_to_search_query(): void
    {
        config()->set('ai.file_context.enabled', false);

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(searchQuery: 'team members roles', questionType: 'data_query', exportFormat: 'excel', language: 'en');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: 'MATCH (u:User) RETURN u.name AS member_name', explanation: '', confidence: 0.9, warnings: [], metadata: []));
        $this->mockVectorStore->shouldReceive('collectionExists')->andReturn(true);
        $this->mockEmbedding->shouldReceive('embed')->andReturn([0.1]);
        $this->mockVectorStore->shouldReceive('upsert')->andReturn(true);
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(success: true, data: [['member_name' => 'Julie Rousseau']], stats: [], metadata: [], errors: []));
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion('Excel of the team members with their roles');

        $this->assertStringContainsString('# Team members roles', $result['metadata']['response']['export_document_markdown']);
        $this->assertSame('team members roles', $result['metadata']['subject']);
    }

    /**
     * @test A previous-result export is titled by the RECORDED label (last_subject) — the
     * language of the turn that produced the rows — never by this turn's own subject.
     */
    public function a_previous_result_export_is_titled_by_the_recorded_subject_label(): void
    {
        config()->set('ai.file_context.enabled', false);

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(searchQuery: 'excel', exportFormat: 'excel', language: 'fr', followUpReference: true, subjectLabel: 'Fichier Excel');
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: null, explanation: '', confidence: 0.0, warnings: [], metadata: []));
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []));
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion("Parfait, donne-moi l'excel.", [
            'conversation_context' => [
                'last_subject' => "Rôles des membres de l'équipe",
                'focused_entity' => 'User',
                'last_result_count' => 1,
                'last_result_sample' => [['member_name' => 'Julie Rousseau', 'role' => 'lead']],
            ],
        ]);

        $this->assertSame('previous_result', $result['metadata']['response']['export_source']);
        $document = $result['metadata']['response']['export_document_markdown'];
        $this->assertStringContainsString("# Rôles des membres de l'équipe", $document);
        $this->assertStringNotContainsString('Fichier Excel', $document);
    }

    /** @test */
    public function without_a_qu_export_format_no_word_list_turns_the_turn_into_an_export(): void
    {
        // Phase 2.B: QU exportFormat is the ONLY export signal. With it null (e.g.
        // the QU call failed), "export that to Excel" is answered through the
        // normal path — the former EN/FR verb+format regex fallback is gone.
        config()->set('ai.file_context.enabled', false);

        $ctx = new PipelineContext(); // default QU result: exportFormat null
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: null, explanation: '', confidence: 0.0, warnings: [], metadata: []));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []));

        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')
            ->once()
            ->andReturn(['answer' => 'No results.', 'metadata' => []]);

        $result = $this->manager->answerQuestion('export that to Excel', [
            'conversation_context' => [
                'last_result_sample' => [['name' => 'Alice']],
            ],
        ]);

        $this->assertArrayNotHasKey('export_intent', $result['metadata']['response']);
        // A default (not failed) QU result: nothing flagged as unavailable.
        $this->assertArrayNotHasKey('export_intent_unavailable', $result['metadata']);
    }

    /** @test */
    public function a_failed_qu_call_is_stamped_export_intent_unavailable_in_metadata_and_composition(): void
    {
        // With the regex fallback gone, "no export ask" after a QU FAILURE means
        // "unknown" — stamped so the eval can distinguish it from a judged "no".
        config()->set('ai.file_context.enabled', false);

        $ctx = new PipelineContext();
        $ctx->qu = QueryUnderstandingResult::failed();
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: null, explanation: '', confidence: 0.0, warnings: [], metadata: []));
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []));
        // Eval p11 D3: a failed understanding with nothing retrieved is a temporary
        // problem — never the "no results" abstention.
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();

        $observer = new \Condoedge\Ai\Support\RecordingPipelineObserver();
        $result = $this->manager->answerQuestion('export that to Excel', ['observer' => $observer]);

        $this->assertSame('qu_failed', $result['metadata']['export_intent_unavailable'] ?? null);
        $this->assertSame('qu_failed', $observer->stageData('composition')['export_intent_unavailable'] ?? null);
        $this->assertSame('understanding_failed', $result['metadata']['response']['status'] ?? null);
    }

    // =========================================================================
    // Follow-up export: re-execute the recorded cypher for the FULL set
    // =========================================================================

    /** @test */
    public function follow_up_export_re_executes_the_recorded_cypher_through_the_scoped_executor(): void
    {
        config()->set('ai.file_context.enabled', false);
        // Document limit == display cap: the recorded query runs as recorded (the
        // re-run past the display LIMIT is AiManagerExportFanOutTest's subject).
        config()->set('ai.export.document_row_limit', 100);

        $recordedCypher = 'MATCH (u:User) RETURN u.name AS name, u.email AS email LIMIT 100';
        $fullRows = [];
        for ($i = 1; $i <= 100; $i++) {
            $fullRows[] = ['name' => "Member {$i}", 'email' => "m{$i}@x.com"];
        }
        $user = (object) ['id' => 13];

        $ctx = new PipelineContext();
        // QU's export judgment (the only export signal since Phase 2.B).
        $ctx->qu = new QueryUnderstandingResult(exportFormat: 'excel', language: 'en');
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

    /**
     * Task 1.27 (g): wire a QU follow-up export turn where the recorded
     * previous result has $previousRows and THIS turn's fresh query returns
     * $currentRows.
     */
    private function followUpExportTurn(array $previousRows, array $currentRows): array
    {
        config()->set('ai.file_context.enabled', false);

        $recordedCypher = 'MATCH (u:User) RETURN u.name AS member_name, u.role AS role';
        $currentCypher = 'MATCH (u:User) OPTIONAL MATCH (c:Contact) WHERE c.user_id = u.id RETURN u.name AS member_name, u.role AS role, c.invitation_email AS contact_email';

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(
            entityTypes: ['User'],
            searchQuery: 'team members contact information',
            questionType: 'data_query',
            exportFormat: 'excel',
            language: 'en',
            followUpReference: true,
        );
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: $currentCypher, explanation: '', confidence: 0.9, warnings: [], metadata: []));

        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturnUsing(function ($cypher) use ($recordedCypher, $previousRows, $currentRows) {
                return new QueryExecutionResult(
                    success: true,
                    // The recorded query is re-run with an explicit document LIMIT (review I1).
                    data: str_starts_with((string) $cypher, $recordedCypher) ? $previousRows : $currentRows,
                    stats: [], metadata: [], errors: [],
                );
            });

        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        return $this->manager->answerQuestion('Can you give me an excel with those members and their contact info?', [
            'user' => (object) ['id' => 13],
            'conversation_context' => [
                'last_cypher_query' => $recordedCypher,
                'last_result_count' => count($previousRows),
                'last_result_sample' => $previousRows,
            ],
        ]);
    }

    /**
     * @test Eval p17-B cold-roster-excel#0, replayed end to end: the generator returned
     * `u.email AS contact_info`; the file was right (every email) but headed "Contact Info",
     * so the user's Excel had no "Email" column. The turn's own cypher now reaches the export
     * composer: the header names the property the column holds, the row keys stay.
     */
    public function a_cold_export_heads_a_column_aliased_after_the_users_word_by_its_property(): void
    {
        config()->set('ai.file_context.enabled', false);

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(
            entityTypes: ['User'],
            searchQuery: 'current team members contact info',
            questionType: 'data_query',
            exportFormat: 'excel',
            language: 'en',
        );
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (u:User) WHERE u.current_team_id = 3 RETURN u.name AS name, u.email AS contact_info, u.id AS user_id LIMIT 100',
            explanation: '', confidence: 0.9, warnings: [], metadata: [],
        ));
        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(new QueryExecutionResult(
            success: true,
            data: [
                ['name' => 'Julie Rousseau', 'contact_info' => 'j.rousseau@novogestion.ca', 'user_id' => 13],
                ['name' => 'Service Comptable', 'contact_info' => 'service_comptable@novogestion.ca', 'user_id' => 14],
            ],
            stats: [], metadata: [], errors: [],
        ));
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion(
            'Can I get an excel with all the members from my current team? With contact info',
            ['user' => (object) ['id' => 13]]
        );

        $response = $result['metadata']['response'];
        $this->assertSame('current_result', $response['export_source']);
        $this->assertStringContainsString('| Name | Email |', $response['export_document_markdown']);
        $this->assertStringContainsString('Fields included: Name, Email.', $result['answer']);
        $this->assertSame(['name' => 'Name', 'contact_info' => 'Email'], $response['export_document_headers']);
        $this->assertSame(['name', 'contact_info'], array_keys($response['export_document_rows'][0]));
    }

    /**
     * @test "Give me that as an Excel" exports the PREVIOUS result: its columns are headed
     * through the RECORDED cypher (the query the rows came from), never this turn's.
     */
    public function a_previous_result_export_is_headed_through_the_recorded_cypher(): void
    {
        config()->set('ai.file_context.enabled', false);

        $recorded = 'MATCH (u:User) WHERE u.current_team_id = 3 RETURN u.name AS name, u.email AS contact LIMIT 100';
        $rows = [['name' => 'Julie Rousseau', 'contact' => 'j.rousseau@novogestion.ca']];

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(
            entityTypes: ['User'], questionType: 'data_query', exportFormat: 'excel', language: 'en', followUpReference: true,
        );
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: null, explanation: '', confidence: 0.0, warnings: [], metadata: []));
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(success: true, data: $rows, stats: [], metadata: [], errors: []));
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion('Ok, give me that as an excel', [
            'user' => (object) ['id' => 13],
            'conversation_context' => [
                'last_cypher_query' => $recorded,
                'last_result_count' => 1,
                'last_result_sample' => $rows,
            ],
        ]);

        $response = $result['metadata']['response'];
        $this->assertSame('previous_result', $response['export_source']);
        $this->assertStringContainsString('| Name | Email |', $response['export_document_markdown']);
        $this->assertSame(['name' => 'Name', 'contact' => 'Email'], $response['export_document_headers']);
    }

    /** @test */
    public function a_follow_up_export_whose_fresh_query_adds_requested_columns_exports_the_current_result(): void
    {
        // Live roster-excel t1: the fresh query succeeded WITH contact_email but the
        // follow-up policy exported the previous (name + role) rows — the file lacked
        // exactly what was asked for.
        $result = $this->followUpExportTurn(
            [['member_name' => 'Julie Rousseau', 'role' => 'lead'], ['member_name' => 'Karen Douglas', 'role' => 'contact']],
            [
                ['member_name' => 'Julie Rousseau', 'role' => 'lead', 'contact_email' => 'julie@x.test'],
                ['member_name' => 'Karen Douglas', 'role' => 'contact', 'contact_email' => 'karen@x.test'],
            ],
        );

        $response = $result['metadata']['response'];
        $this->assertSame('current_result', $response['export_source']);
        $this->assertStringContainsString('| Member Name | Role | Contact Email |', $response['export_document_markdown']);
        $this->assertStringContainsString('karen@x.test', $response['export_document_markdown']);
        $this->assertStringContainsString('Fields included: Member Name, Role, Contact Email.', $result['answer']);
        $this->assertStringNotContainsString('not included', $result['answer']);
    }

    /** @test */
    public function a_follow_up_export_whose_fresh_query_is_not_a_superset_keeps_the_previous_result_and_discloses_the_missing_field(): void
    {
        // Same grain (shares member_name) but drops role: the previous result stays
        // authoritative — and the answer must say the requested contact_email is
        // NOT in the file instead of listing "Fields included" as if complete.
        $result = $this->followUpExportTurn(
            [['member_name' => 'Julie Rousseau', 'role' => 'lead']],
            [['member_name' => 'Julie Rousseau', 'contact_email' => 'julie@x.test']],
        );

        $response = $result['metadata']['response'];
        $this->assertSame('previous_result', $response['export_source']);
        $this->assertStringContainsString('Fields included: Member Name, Role.', $result['answer']);
        $this->assertStringContainsString('Requested fields not included: Contact Email.', $result['answer']);
    }

    /** @test */
    public function a_re_anchored_superset_with_generic_aliases_but_different_values_keeps_the_previous_result(): void
    {
        // Task 1.28 I1: aliases are LLM-chosen and generic. A re-anchored TEAM query
        // [name, email, phone] is a column-name superset of a members list
        // [name, email] — but its VALUES are a different entity. Name-only
        // comparison exported Team rows under the members title.
        $result = $this->followUpExportTurn(
            [
                ['name' => 'Julie Rousseau', 'email' => 'julie@x.test'],
                ['name' => 'Karen Douglas', 'email' => 'karen@x.test'],
            ],
            [['name' => 'Gestion Centrale', 'email' => 'info@gc.test', 'phone' => '514-555-0100']],
        );

        $this->assertSame('previous_result', $result['metadata']['response']['export_source']);
        $this->assertStringContainsString('Julie Rousseau', $result['metadata']['response']['export_document_markdown']);
        $this->assertStringNotContainsString('not included', $result['answer'], 'a re-anchored query never yields a false missing-field line');
    }

    /** @test */
    public function the_label_marker_is_not_a_column_in_the_superset_comparison(): void
    {
        // Task 1.28 M2: `_label` is a decoration (DECORATION_KEYS), not data.
        $result = $this->followUpExportTurn(
            [['name' => 'Julie Rousseau', 'email' => 'julie@x.test', '_label' => 'User']],
            [['name' => 'Julie Rousseau', 'email' => 'julie@x.test', 'phone' => '514-555-0100']],
        );

        $this->assertSame('current_result', $result['metadata']['response']['export_source']);
    }

    /** @test */
    public function a_superset_matches_values_case_and_whitespace_insensitively(): void
    {
        $result = $this->followUpExportTurn(
            [['name' => 'Julie Rousseau', 'email' => 'JULIE@x.test ']],
            [['name' => ' julie rousseau', 'email' => 'julie@x.test', 'phone' => '514-555-0100']],
        );

        $this->assertSame('current_result', $result['metadata']['response']['export_source']);
    }

    /** @test */
    public function a_re_anchored_fresh_query_with_no_shared_columns_keeps_the_previous_result_without_disclosure(): void
    {
        // The anaphora-suspect case (live: "excel of it" exported the TEAM row):
        // an unrelated fresh query is neither exported nor treated as "requested".
        $result = $this->followUpExportTurn(
            [['member_name' => 'Julie Rousseau', 'role' => 'lead']],
            [['team_name' => 'Gestion Centrale', 'team_type' => 2, 'website' => 'x.test']],
        );

        $this->assertSame('previous_result', $result['metadata']['response']['export_source']);
        $this->assertStringNotContainsString('not included', $result['answer']);
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
        // QU's export judgment (the only export signal since Phase 2.B).
        $ctx->qu = new QueryUnderstandingResult(exportFormat: 'excel', language: 'en');
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
        // QU's export judgment (the only export signal since Phase 2.B).
        $ctx->qu = new QueryUnderstandingResult(exportFormat: 'excel', language: 'en');
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
        // QU's export judgment (the only export signal since Phase 2.B).
        $ctx->qu = new QueryUnderstandingResult(exportFormat: 'excel', language: 'en');
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

    /**
     * @test "Where can I add new members?" comes back intent=page_explanation on part of the runs
     * (eval p17–p23 resilience t2: 4 of 8; under the strict reply schema: every measured run)
     * together with navigation_target=own_team. An ask QU judged to be about ANOTHER page — the
     * user's own team or profile — is not a request to describe the page the chat was opened
     * from: the described page must not answer it. Structured QU signals only.
     */
    public function a_navigation_ask_is_never_answered_by_narrating_the_current_page(): void
    {
        config()->set('ai.file_context.enabled', false);
        config()->set('ai.chat.page_explanation_intents', ['page_explanation']);
        config()->set('ai.chat.page_descriptions', [
            'Unit' => 'This page shows a single unit: its owners, balance and payment history.',
        ]);

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(intent: 'page_explanation', questionType: 'knowledge_query', navigationTarget: 'own_team');

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')
            ->andReturn(new QueryGenerationResult(cypher: null, explanation: '', confidence: 0.0, warnings: [], metadata: []));
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturn(new QueryExecutionResult(success: true, data: [], stats: [], metadata: [], errors: []));

        // The turn takes the ordinary path (here: nothing retrieved → the empty answer, which
        // points at the navigation target) — never the narration of the unit page.
        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockResponseGenerator->shouldReceive('generateEmptyResponse')
            ->once()
            ->andReturn([
                'answer' => 'You can add members from your team settings.',
                'insights' => [],
                'visualizations' => [],
                'format' => 'text',
                'metadata' => ['empty_result' => true, 'result_count' => 0],
            ]);

        $result = $this->manager->answerQuestion('Where can I add new members?', [
            'page_context' => ['route' => 'units.show', 'entity_type' => 'Unit', 'entity_id' => 402, 'label' => 'Unit 402'],
        ]);

        $this->assertSame('You can add members from your team settings.', $result['answer']);
        $this->assertNotSame('page_explanation', $result['metadata']['response']['intent'] ?? null);
        $this->assertNotSame('page_description', $result['metadata']['query']['source'] ?? null);
    }
}
