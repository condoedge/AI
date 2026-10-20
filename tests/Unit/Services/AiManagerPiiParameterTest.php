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
 * P-1 (Phase 8-A review): a PII token the generator copied from the masked
 * conversation is rebound as `$pii_…` — AiManager must EXECUTE it with the real
 * value as a bound parameter, and record the parameters for the next turn.
 */
class AiManagerPiiParameterTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function bound_pii_parameters_reach_the_executor_and_the_response(): void
    {
        config(['ai.security.enabled' => false, 'ai.file_context.enabled' => false]);

        $ctx = new PipelineContext();
        $ctx->question = 'only that member';
        $ctx->qu = new QueryUnderstandingResult(searchQuery: 'member', intent: 'list', questionType: 'data_query', language: 'en');

        $context = Mockery::mock(ContextRetrieverInterface::class);
        $context->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1))->byDefault();
        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('collectionExists')->andReturn(true)->byDefault();
        $vector->shouldReceive('upsert')->andReturn(true)->byDefault();

        $generator = Mockery::mock(QueryGeneratorInterface::class);
        $generator->shouldReceive('generate')->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (u:User) WHERE u.email = $pii_0123456789ab RETURN u.name AS name',
            confidence: 0.9,
            parameters: ['pii_0123456789ab' => 'karen@x.test'],
        ));

        $seen = [];
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->andReturnUsing(function ($cypher, $params) use (&$seen) {
            $seen[] = $params;
            return new QueryExecutionResult(success: true, data: [['name' => 'Karen']]);
        });

        $responder = Mockery::mock(ResponseGeneratorInterface::class);
        $responder->shouldReceive('generate')->andReturn(['answer' => 'Karen.', 'format' => 'text', 'metadata' => []]);

        $manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $context, $embedding, Mockery::mock(LlmProviderInterface::class),
            $generator, $executor, $responder, $vector,
            Mockery::mock(FileContextProvider::class),
        );

        $result = $manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13], 'store_examples' => false]);

        $this->assertSame(['pii_0123456789ab' => 'karen@x.test'], $seen[0] ?? null);
        $this->assertSame(['pii_0123456789ab' => 'karen@x.test'], $result['cypher_parameters']);
        $this->assertStringNotContainsString('karen@x.test', (string) $result['cypher'], 'never inlined');
    }

    /** @test Review #5: only `pii_<12 hex>` snapshot keys ever reach the re-executed query. */
    public function recorded_parameters_are_filtered_to_pii_keys_before_re_execution(): void
    {
        config(['ai.security.enabled' => false, 'ai.file_context.enabled' => false]);
        $recorded = 'MATCH (u:User) WHERE u.email = $pii_0123456789ab RETURN u.name AS member_name';

        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(
            entityTypes: ['User'], searchQuery: 'member', questionType: 'data_query',
            exportFormat: 'excel', language: 'en', followUpReference: true,
        );
        $context = Mockery::mock(ContextRetrieverInterface::class);
        $context->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1))->byDefault();
        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('collectionExists')->andReturn(true)->byDefault();
        $generator = Mockery::mock(QueryGeneratorInterface::class);
        $generator->shouldReceive('generate')->andReturn(new QueryGenerationResult(cypher: '', confidence: 1.0));

        $seen = [];
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->andReturnUsing(function ($cypher, $params) use (&$seen, $recorded) {
            if (str_starts_with((string) $cypher, $recorded)) {
                $seen[] = $params;
            }
            return new QueryExecutionResult(success: true, data: [['member_name' => 'Karen']]);
        });
        $executor->shouldReceive('executeCount')->andReturn(1);
        $responder = Mockery::mock(ResponseGeneratorInterface::class);

        $manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $context, $embedding, Mockery::mock(LlmProviderInterface::class),
            $generator, $executor, $responder, $vector,
            Mockery::mock(FileContextProvider::class),
        );

        $manager->answerQuestion('excel of that', [
            'user' => (object) ['id' => 13],
            'store_examples' => false,
            'conversation_context' => [
                'last_cypher_query' => $recorded,
                'last_result_count' => 1,
                'last_result_sample' => [['member_name' => 'Karen']],
                'last_cypher_parameters' => [
                    'pii_0123456789ab' => 'karen@x.test',
                    'ai_team_ids' => [999],
                    'pii_short' => 'x',
                    'PII_0123456789AB' => 'y',
                    'other' => 'z',
                ],
            ],
        ]);

        $this->assertNotEmpty($seen, 'the recorded query was re-executed');
        $this->assertSame(['pii_0123456789ab' => 'karen@x.test'], $seen[0]);
    }
}
