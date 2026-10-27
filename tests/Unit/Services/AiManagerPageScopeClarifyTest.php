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
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Owner 2026-10-27: on a record's page, "our profit" may mean that record or the whole organisation. When a
 * per-record formula matched and the query ran over every record of the page's kind, the user is asked which,
 * with one button per reading, instead of guessing (live 8-data profit-two-screens, orders-count).
 */
class AiManagerPageScopeClarifyTest extends TestCase
{
    private const TEAM_WIDE = 'MATCH (c:Campaign) WHERE c.team_id IN $ai_team_ids RETURN c.name AS campaign, round(c.profit, 2) AS profit LIMIT 100';
    private const PAGE = ['entity_type' => 'Campaign', 'entity_id' => 3454, 'label' => 'Gala 2026', 'route' => 'dashboard'];

    private AiManager $manager;
    private $mockContext;
    private $mockQueryGenerator;
    private $mockQueryExecutor;
    private $mockResponseGenerator;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('logging.default', 'null');
        config()->set('ai.log_channel', 'null');
        config()->set('ai.file_context.enabled', false);
        config()->set('ai.attached_actions.project_record_ids', false);
        config()->set('ai.calculations', ['campaign_profit' => ['label' => 'Profit of a campaign',
            'cypher' => 'MATCH (c:Campaign) WHERE c.id = <CAMPAIGN ID> RETURN round(c.profit, 2) AS profit']]);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            Mockery::mock(EmbeddingProviderInterface::class),
            Mockery::mock(LlmProviderInterface::class),
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            Mockery::mock(VectorStoreInterface::class)
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function turn(string $cypher, array $conversation = []): void
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', intent: 'lookup', entityTypes: ['Campaign'],
            isPossessive: true, possessiveScope: QueryUnderstandingResult::SCOPE_COLLECTIVE, language: 'fr');
        $ctx->selectionInfo['calculations'] = ['matched' => ['campaign_profit' => 0.8]];
        $ctx->conversationContext = $conversation;
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')->andReturn(new QueryGenerationResult(cypher: $cypher, confidence: 0.9));
        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(new QueryExecutionResult(success: true,
            data: [['campaign' => 'Gala 2026', 'profit' => 10.0], ['campaign' => 'Souper', 'profit' => 20.0]], metadata: ['security_filter_applied' => true]));
    }

    private function ask(): array
    {
        return $this->manager->answerQuestion('C\'est quoi notre profit?', ['user' => (object) ['id' => 13, 'current_team_id' => 3],
            'store_examples' => false, 'page_context' => self::PAGE]);
    }

    /** @test */
    public function a_per_record_figure_asked_over_every_record_from_a_records_page_asks_which(): void
    {
        $this->turn(self::TEAM_WIDE);
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->ask();

        $this->assertSame(trans('ai.clarify.page-scope', ['record' => 'Gala 2026'], 'fr'), $result['answer']);
        $this->assertNotSame('ai.clarify.page-scope', $result['answer']);
        $this->assertCount(2, $result['metadata']['response']['clarification']['candidate_readings']);
        $this->assertStringContainsString('Gala 2026', $result['metadata']['response']['clarification']['candidate_readings'][0]);
    }

    /** @test */
    public function a_query_pinned_to_a_record_or_the_answer_to_that_question_is_answered(): void
    {
        $this->turn('MATCH (c:Campaign) WHERE c.id = 3454 RETURN round(c.profit, 2) AS profit');
        $this->mockResponseGenerator->shouldReceive('generate')->once()->andReturn(['answer' => 'ok', 'format' => 'text', 'metadata' => []]);
        $this->assertSame('ok', $this->ask()['answer']);
    }

    /** @test */
    public function the_reply_to_that_question_is_answered_not_asked_again(): void
    {
        $asked = trans('ai.clarify.page-scope', ['record' => 'Gala 2026'], 'fr');
        $this->turn(self::TEAM_WIDE, ['recent_exchanges' => [['role' => 'assistant', 'question' => null, 'answer_summary' => $asked]]]);
        $this->mockResponseGenerator->shouldReceive('generate')->once()->andReturn(['answer' => 'ok', 'format' => 'text', 'metadata' => []]);

        $this->assertSame('ok', $this->ask()['answer']);
    }
}
