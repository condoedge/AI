<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\QueryExecutor;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Owner request 2026-10-06: a rate limit is not an error. The turn is admitted up front
 * (waiting in line when needed); a wait that is too long gets a specific, no-cost answer
 * with the real number of seconds — never "something went wrong", never after paying for
 * the understanding / generation LLM calls.
 */
class AiManagerAdmissionTest extends TestCase
{
    private $context;
    private $executor;
    private $generator;
    private $responder;

    public function setUp(): void
    {
        parent::setUp();
        config(['ai.security.enabled' => false, 'ai.file_context.enabled' => false, 'ai.rate_limits.max_wait_seconds' => 20]);

        $this->context = Mockery::mock(ContextRetrieverInterface::class);
        $this->executor = Mockery::mock(QueryExecutor::class);
        $this->generator = Mockery::mock(QueryGeneratorInterface::class);
        $this->responder = Mockery::mock(ResponseGeneratorInterface::class);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function manager(): AiManager
    {
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn(array_fill(0, 8, 0.1));
        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('collectionExists')->andReturn(true);
        $vector->shouldReceive('upsert')->andReturn(true);

        return new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->context,
            $embedding,
            Mockery::mock(LlmProviderInterface::class),
            $this->generator,
            $this->executor,
            $this->responder,
            $vector,
            Mockery::mock(FileContextProvider::class)
        );
    }

    /** @test */
    public function a_turn_that_cannot_be_admitted_gets_the_busy_answer_without_any_llm_call(): void
    {
        $this->executor->shouldReceive('admit')->once()
            ->with(Mockery::on(fn ($o) => str_starts_with((string) ($o['rate_limit_batch'] ?? ''), 'turn:')), 20)
            ->andReturn(['admitted' => false, 'waited_seconds' => 0, 'retry_after' => 42]);
        // Nothing downstream may run: no understanding, no generation, no execution, no composition.
        $this->context->shouldNotReceive('retrieveContext');
        $this->generator->shouldNotReceive('generate');
        $this->executor->shouldNotReceive('execute');
        $this->responder->shouldNotReceive('generate');

        $result = $this->manager()->answerQuestion('How many members does my team have?', ['user' => (object) ['id' => 13]]);

        $this->assertStringContainsString('42', $result['answer']);
        $this->assertStringContainsString('your question is saved', $result['answer']);
        $this->assertStringNotContainsString('went wrong', $result['answer']);
        $this->assertSame('rate_limited', $result['metadata']['response']['status']);
        $this->assertSame(42, $result['metadata']['response']['retry_after']);
    }

    /** @test */
    public function the_busy_answer_follows_the_question_language_when_known(): void
    {
        $this->executor->shouldReceive('admit')->andReturn(['admitted' => false, 'waited_seconds' => 0, 'retry_after' => 7]);

        $result = $this->manager()->answerQuestion('Combien de membres ?', ['user' => (object) ['id' => 13], 'question_language' => 'fr']);

        $this->assertStringContainsString('environ 7 secondes', $result['answer']);
    }

    /** @test */
    public function an_admitted_turn_runs_normally_even_after_waiting_in_line(): void
    {
        $this->executor->shouldReceive('admit')->once()->andReturn(['admitted' => true, 'waited_seconds' => 12, 'retry_after' => 0]);

        $ctx = new PipelineContext();
        $ctx->question = 'How many members does my team have?';
        $ctx->qu = new QueryUnderstandingResult(searchQuery: 'team member count', intent: 'count', questionType: 'data_query');
        $ctx->similarQueries = [];
        $ctx->graphSchema = ['labels' => ['User']];
        $this->context->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->generator->shouldReceive('generate')->andReturn(new QueryGenerationResult(cypher: 'MATCH (u:User) RETURN count(u) AS c', explanation: '', confidence: 0.9, warnings: [], metadata: []));
        $this->executor->shouldReceive('execute')->andReturn(new QueryExecutionResult(success: true, data: [['c' => 12]], stats: [], metadata: [], errors: []));
        $this->executor->shouldReceive('executeCount')->andReturn(1);
        $this->responder->shouldReceive('generate')->andReturn(['answer' => 'You have 12 members.', 'insights' => [], 'visualizations' => [], 'metadata' => []]);

        $result = $this->manager()->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertSame('You have 12 members.', $result['answer']);
    }
}
