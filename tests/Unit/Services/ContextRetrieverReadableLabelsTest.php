<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\RowScopeResolverInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Services\Security\AiSecurityService;
use Condoedge\Ai\Services\Security\TeamPathResolver;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Labels the asker cannot read (no row path for a narrowed user, outside the audience's
 * list) are offered neither to Query Understanding nor in the generator's schema.
 */
class ContextRetrieverReadableLabelsTest extends TestCase
{
    private array $entities = [
        'App\\Models\\Meeting' => ['graph' => ['label' => 'Meeting'], 'security' => ['team_path' => 'team_id', 'row_scope_path' => 'union_id']],
        'App\\Models\\Supplier' => ['graph' => ['label' => 'Supplier'], 'security' => ['team_path' => 'team_id']],
    ];

    private array $catalogSeen = [];

    public function setUp(): void
    {
        parent::setUp();

        config(['entities' => $this->entities]);
        config(['ai.security.default_team_path' => null]);
        $adapter = Mockery::mock(AiAuthAdapterInterface::class);
        $adapter->shouldReceive('isEnabled')->andReturn(true);
        $this->app->instance(AiSecurityService::class, new AiSecurityService($adapter, new TeamPathResolver()));
        $this->app->instance('test.row_scope', new class implements RowScopeResolverInterface {
            public function rowScopeIds(mixed $user): ?array { return [8]; }
        });
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function a_narrowed_user_is_offered_only_the_labels_with_a_row_path(): void
    {
        config(['ai.security.row_scope_resolver' => 'test.row_scope']);

        $ctx = $this->retrieve();

        $this->assertSame(['Meeting'], $ctx->graphSchema['labels']);
        $this->assertArrayNotHasKey('Supplier', $ctx->graphSchema['properties']);
        $this->assertStringContainsString('Meeting', json_encode($this->catalogSeen));
        $this->assertStringNotContainsString('Supplier', json_encode($this->catalogSeen));
    }

    /** @test */
    public function an_unnarrowed_user_is_offered_every_label(): void
    {
        config(['ai.security.row_scope_resolver' => null]);

        $ctx = $this->retrieve();

        $this->assertSame(['Meeting', 'Supplier'], $ctx->graphSchema['labels']);
        $this->assertStringContainsString('Supplier', json_encode($this->catalogSeen));
    }

    private function retrieve(): \Condoedge\Ai\DTOs\PipelineContext
    {
        $vectors = Mockery::mock(VectorStoreInterface::class);
        $vectors->shouldReceive('search')->andReturn([])->byDefault();
        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('getSchema')->andReturn(['labels' => ['Meeting', 'Supplier'], 'relationshipTypes' => [], 'propertyKeys' => []]);
        $graph->shouldReceive('query')->andReturn([
            ['label' => 'Meeting', 'property' => 'name'],
            ['label' => 'Supplier', 'property' => 'name'],
        ])->byDefault();
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn([0.1, 0.2])->byDefault();

        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTrace')->andReturn('trace')->byDefault();
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        foreach (['info', 'debug', 'error', 'warning', 'fallback'] as $level) {
            $logger->shouldReceive($level)->byDefault();
        }

        $retriever = new ContextRetriever($vectors, $graph, $embedding, $logger, $this->entities);
        $service = Mockery::mock(QueryUnderstandingService::class);
        $service->shouldReceive('analyze')->once()->andReturnUsing(function (string $q, array $catalog) {
            $this->catalogSeen = $catalog;

            return new QueryUnderstandingResult(searchQuery: 'meetings', questionType: 'data_query', language: 'en');
        });
        $retriever->setQueryUnderstanding($service);

        return $retriever->retrieveContext('list the meetings', ['user' => (object) ['id' => 35], 'useSemanticSelection' => false]);
    }
}
