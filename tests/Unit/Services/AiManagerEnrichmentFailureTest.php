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
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Response\ResponseEntityEnricher;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Task 1.27 (e): entity-action enrichment is a UI nicety — it must never end
 * the turn. An enricher that throws leaves the rows un-enriched and the answer
 * is still generated from them.
 */
class AiManagerEnrichmentFailureTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function a_throwing_enricher_does_not_kill_the_answer(): void
    {
        config(['ai.security.enabled' => false]);
        config(['ai.file_context.enabled' => false]);

        $ctx = new PipelineContext();
        $ctx->question = 'Info about my team';
        $ctx->qu = new QueryUnderstandingResult(searchQuery: 'team info', intent: 'list', questionType: 'data_query');
        $ctx->graphSchema = ['labels' => ['Team']];

        $context = Mockery::mock(ContextRetrieverInterface::class);
        $context->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1))->byDefault();
        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('collectionExists')->andReturn(true)->byDefault();
        $vector->shouldReceive('upsert')->andReturn(true)->byDefault();

        $generator = Mockery::mock(QueryGeneratorInterface::class);
        $generator->shouldReceive('generate')->once()->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (t:Team) RETURN t.name AS name, t.team_type AS type',
            explanation: '', confidence: 0.9, warnings: [], metadata: [],
        ));
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->andReturn(new QueryExecutionResult(
            success: true, data: [['name' => 'Board', 'type' => 2]], stats: [], metadata: [], errors: [],
        ));

        $seenRows = null;
        $responder = Mockery::mock(ResponseGeneratorInterface::class);
        $responder->shouldReceive('generate')->andReturnUsing(function ($c) use (&$seenRows) {
            $seenRows = $c->executionResult->data ?? null;
            return ['answer' => 'Your team is Board.', 'insights' => [], 'visualizations' => [], 'metadata' => []];
        });

        $enricher = Mockery::mock(ResponseEntityEnricher::class);
        $enricher->shouldReceive('enrichEntityResults')->andThrow(new \TypeError('boom'));

        $manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $context,
            $embedding,
            Mockery::mock(LlmProviderInterface::class),
            $generator,
            $executor,
            $responder,
            $vector,
            Mockery::mock(FileContextProvider::class),
            responseEntityEnricher: $enricher,
        );

        $result = $manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertSame('Your team is Board.', $result['answer']);
        $this->assertSame([['name' => 'Board', 'type' => 2]], $seenRows, 'the un-enriched rows reach composition');
    }

    /** @test */
    public function an_enriched_current_result_export_carries_no_enrichment_or_label_columns(): void
    {
        // Task 1.27 follow-up: rows enriched via `_label` (has_actions,
        // available_actions, entity_type, action_links) exported as current_result
        // must not ship those columns — neither in the document table nor in the
        // xlsx built from it (documentToExcel parses that table).
        config(['ai.security.enabled' => false, 'ai.file_context.enabled' => false]);
        config(['entities' => ['App\\Models\\Team' => ['graph' => ['label' => 'Team']]]]);

        $ctx = new PipelineContext();
        $ctx->question = 'Excel of my teams';
        $ctx->qu = new QueryUnderstandingResult(searchQuery: 'teams', intent: 'list', questionType: 'data_query', exportFormat: 'excel', language: 'en');

        $context = Mockery::mock(ContextRetrieverInterface::class);
        $context->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1))->byDefault();
        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('collectionExists')->andReturn(true)->byDefault();
        $vector->shouldReceive('upsert')->andReturn(true)->byDefault();
        $generator = Mockery::mock(QueryGeneratorInterface::class);
        $generator->shouldReceive('generate')->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (t:Team) RETURN t.id AS id, t.name AS name, labels(t)[0] AS _label',
            explanation: '', confidence: 0.9, warnings: [], metadata: [],
        ));
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->andReturn(new QueryExecutionResult(
            success: true, data: [['id' => 3, 'name' => 'Board', '_label' => 'Team']], stats: [], metadata: [], errors: [],
        ));
        $responder = Mockery::mock(ResponseGeneratorInterface::class);
        $responder->shouldReceive('generate')->never();

        $discovery = Mockery::mock(\Condoedge\Ai\Services\Discovery\EntityAutoDiscovery::class);
        $discovery->shouldReceive('getAuthorizedEntityActions')
            ->andReturn([['action_key' => 'view', 'label' => 'View', 'aliases' => []]]);

        $manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $context, $embedding, Mockery::mock(LlmProviderInterface::class),
            $generator, $executor, $responder, $vector,
            Mockery::mock(FileContextProvider::class),
            responseEntityEnricher: new ResponseEntityEnricher($discovery),
        );

        $result = $manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertTrue($result['data'][0]['has_actions'], 'precondition: the rows WERE enriched');
        $response = $result['metadata']['response'];
        $this->assertSame('current_result', $response['export_source']);

        $markdown = $response['export_document_markdown'];
        $tables = (new \Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter())->extractMarkdownTables($markdown);
        // Plain `id` is a data column under the existing policy (only *_id/_id/neo4j_id are internal).
        $this->assertSame(['Id', 'Name'], $tables[0]['headers'] ?? null, 'xlsx matrix headers (parsed from the document table)');
        foreach (['Has Actions', 'Available Actions', 'Entity Type', 'Action Links', 'Label'] as $leak) {
            $this->assertStringNotContainsString($leak, $markdown);
            $this->assertStringNotContainsString($leak, $result['answer']);
        }
    }

    /** @test */
    public function the_public_enrichment_seam_is_non_fatal(): void
    {
        $enricher = Mockery::mock(ResponseEntityEnricher::class);
        $enricher->shouldReceive('enrichEntityResults')->andThrow(new \RuntimeException('boom'));

        $manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            Mockery::mock(ContextRetrieverInterface::class),
            Mockery::mock(EmbeddingProviderInterface::class),
            Mockery::mock(LlmProviderInterface::class),
            Mockery::mock(QueryGeneratorInterface::class),
            Mockery::mock(QueryExecutorInterface::class),
            Mockery::mock(ResponseGeneratorInterface::class),
            Mockery::mock(VectorStoreInterface::class),
            responseEntityEnricher: $enricher,
        );

        \Illuminate\Support\Facades\Log::spy();

        $in = new QueryExecutionResult(success: true, data: [['a' => 1]], stats: [], metadata: [], errors: []);
        $out = (function ($r) { return $this->enrichResultsWithEntityActions($r); })->call($manager, $in);

        $this->assertSame([['a' => 1]], $out->data);
        // Task 1.28 M1: the exception object itself is logged, so the trace survives.
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => ($context['exception'] ?? null) instanceof \RuntimeException)
            ->once();
    }
}
