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
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * G2/L5 item B — augmentation anchor-carry (iteration-11 sentinel red).
 *
 * Observed failure (resilience t1, 0.15): a column-add follow-up ("Ok, but
 * could you add their last login to it?") lost the prior data anchor entirely —
 * QU emitted entity_types=[], question_type=knowledge_query, and the pipeline's
 * knowledge-skip answered from documents with cypher=null / 0 rows.
 *
 * MECHANISM PINNED HERE: when QU flags followUpReference AND the conversation
 * snapshot records a data anchor (last_cypher_query), query generation MUST
 * run — the knowledge-skip is bypassed for the anchored turn, and the
 * generator receives the previous query + entity types through the
 * conversation context so it EXTENDS the prior query instead of starting
 * blind. Export follow-ups (exportFormat set) keep their existing route: the
 * previous-result export path owns them, no forced generation.
 */
class AiManagerAugmentationAnchorTest extends TestCase
{
    private AiManager $manager;
    private ContextRetrieverInterface $mockContext;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;
    private FileContextProvider $mockFileContextProvider;

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.security.enabled' => false]);
        config(['ai.file_context.enabled' => true]);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockFileContextProvider = Mockery::mock(FileContextProvider::class);

        // Every file-search angle finds ONE relevant document, so the
        // knowledge-skip's hasFileContext precondition is genuinely met.
        $this->mockFileContextProvider->shouldReceive('getFileContext')
            ->andReturn([
                'relevant_files' => [[
                    'file_id' => 'doc-1',
                    'filename' => 'bylaws.pdf',
                    'relevance' => 0.9,
                    'chunk_index' => 4,
                    'snippet' => 'Some bylaw text.',
                ]],
                'file_count' => 1,
            ])
            ->byDefault();

        $this->manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            Mockery::mock(EmbeddingProviderInterface::class),
            Mockery::mock(LlmProviderInterface::class),
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            Mockery::mock(VectorStoreInterface::class),
            $this->mockFileContextProvider
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private const PREVIOUS_CYPHER = 'MATCH (t:Team)-[:HAS_MEMBER]->(u:User) RETURN u.name, u.email';

    private function anchorConversationContext(): array
    {
        return [
            'focused_entity' => 'Team',
            'last_entity_types' => ['Team', 'User'],
            'last_cypher_query' => self::PREVIOUS_CYPHER,
            'last_result_count' => 5,
            'last_result_sample' => [['name' => 'Ana', 'email' => 'a@x.io']],
        ];
    }

    private function makeContext(bool $followUp, ?string $exportFormat = null): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = 'Ok, but could you add their last login to it?';
        // The exact failure signature: QU produced NO entity types and typed the
        // augmentation follow-up as a knowledge query.
        $ctx->qu = new QueryUnderstandingResult(
            entityTypes: [],
            searchQuery: 'add last login column',
            intent: 'list',
            questionType: 'knowledge_query',
            exportFormat: $exportFormat,
            followUpReference: $followUp,
        );
        $ctx->graphSchema = ['labels' => ['Team', 'User']];

        return $ctx;
    }

    /** @test */
    public function an_anchored_follow_up_bypasses_the_knowledge_skip_and_generates_a_query(): void
    {
        $ctx = $this->makeContext(followUp: true);

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $generated = null;
        $this->mockQueryGenerator->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function ($question, PipelineContext $context) use (&$generated) {
                $generated = $context;
                return new QueryGenerationResult(
                    cypher: 'MATCH (t:Team)-[:HAS_MEMBER]->(u:User) RETURN u.name, u.email, u.last_logged_at',
                    explanation: 'Extended the previous query with the requested column.',
                    confidence: 0.9,
                );
            });
        $this->mockQueryExecutor->shouldReceive('execute')->once()->andReturn(new QueryExecutionResult(
            success: true,
            data: [['name' => 'Ana', 'email' => 'a@x.io', 'last_logged_at' => '2026-07-01']],
            stats: [], metadata: [], errors: [],
        ));
        $this->mockResponseGenerator->shouldReceive('generate')->once()->andReturn([
            'answer' => 'augmented answer', 'insights' => [], 'visualizations' => [], 'metadata' => [],
        ]);

        $result = $this->manager->answerQuestion($ctx->question, [
            'user' => (object) ['id' => 13],
            'conversation_context' => $this->anchorConversationContext(),
        ]);

        $this->assertSame('augmented answer', $result['answer']);
        $this->assertNotNull($result['cypher'], 'the anchored follow-up must produce a query, never cypher=null');
        $this->assertCount(1, $result['data']);
        // The generator saw the anchor (previous query threaded via conversation context).
        $this->assertSame(
            self::PREVIOUS_CYPHER,
            $generated->conversationContext['last_cypher_query'] ?? null,
            'the recorded cypher must reach the generator as the extension anchor'
        );
    }

    /** @test */
    public function without_a_recorded_anchor_the_knowledge_skip_is_preserved(): void
    {
        $ctx = $this->makeContext(followUp: true);

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockQueryExecutor->shouldReceive('execute')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->once()->andReturn([
            'answer' => 'docs answer', 'insights' => [], 'visualizations' => [], 'metadata' => [],
        ]);

        // Conversation context WITHOUT a data anchor (no last_cypher_query).
        $result = $this->manager->answerQuestion($ctx->question, [
            'user' => (object) ['id' => 13],
            'conversation_context' => ['focused_entity' => 'Team'],
        ]);

        $this->assertSame('docs answer', $result['answer']);
        $this->assertNull($result['cypher']);
    }

    /** @test */
    public function a_non_follow_up_knowledge_query_keeps_the_skip_even_with_an_anchor_present(): void
    {
        $ctx = $this->makeContext(followUp: false);

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockQueryExecutor->shouldReceive('execute')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->once()->andReturn([
            'answer' => 'docs answer', 'insights' => [], 'visualizations' => [], 'metadata' => [],
        ]);

        $result = $this->manager->answerQuestion($ctx->question, [
            'user' => (object) ['id' => 13],
            'conversation_context' => $this->anchorConversationContext(),
        ]);

        $this->assertSame('docs answer', $result['answer']);
        $this->assertNull($result['cypher']);
    }

    /** @test */
    public function an_export_follow_up_keeps_the_previous_result_export_route(): void
    {
        // "Export it" (followUpReference + exportFormat): the previous-result
        // export path owns the turn — the anchor must NOT force fresh generation.
        $ctx = $this->makeContext(followUp: true, exportFormat: 'excel');

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')->never();

        // The export path re-executes the RECORDED cypher (full result set).
        $this->mockQueryExecutor->shouldReceive('execute')
            ->once()
            ->andReturnUsing(function ($cypher) {
                $this->assertSame(self::PREVIOUS_CYPHER, $cypher);
                return new QueryExecutionResult(
                    success: true,
                    data: [['name' => 'Ana', 'email' => 'a@x.io']],
                    stats: [], metadata: [], errors: [],
                );
            });

        $result = $this->manager->answerQuestion('Could you export it?', [
            'user' => (object) ['id' => 13],
            'conversation_context' => $this->anchorConversationContext(),
        ]);

        $this->assertTrue($result['metadata']['response']['export_intent'] ?? false);
        $this->assertSame('previous_result', $result['metadata']['response']['export_source'] ?? null);
    }
}
