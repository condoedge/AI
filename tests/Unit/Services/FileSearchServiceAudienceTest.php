<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Mockery;

/**
 * The asker's readable audiences reach every search of every collection, by content and by
 * file name; a search without them is unchanged.
 */
class FileSearchServiceAudienceTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: array}> method, collection ('' = uploads), filters */
    private array $calls = [];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function service(): FileSearchService
    {
        $store = Mockery::mock(ChunkStoreInterface::class);
        foreach (['searchByContent', 'searchByFilename'] as $method) {
            $store->shouldReceive($method)->andReturnUsing(function ($query, $limit, $filters, $collection = null) use ($method) {
                $this->calls[] = [$method, $collection ?? '', $filters];

                return [];
            });
        }
        $builder = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $builder->shouldReceive('get')->andReturn(collect());
        $model = Mockery::mock(Model::class)->makePartial();
        $model->shouldReceive('whereIn')->andReturn($builder);
        $this->app->instance('file-model', $model);

        return new FileSearchService($store, Mockery::mock(GraphStoreInterface::class), ['documentation_chunks']);
    }

    /** @test */
    public function every_collection_is_searched_with_the_askers_audiences(): void
    {
        $service = $this->service();
        $service->searchByContent('refund', ['audiences' => ['buyer']]);
        $service->searchByFilename('refund.md', ['audiences' => ['buyer']]);

        $this->assertCount(4, $this->calls);
        foreach ($this->calls as [$method, $collection, $filters]) {
            $this->assertSame(['buyer'], $filters['audiences'] ?? null, "{$method} on [{$collection}]");
        }
    }

    /** @test */
    public function no_audiences_option_no_filter(): void
    {
        $service = $this->service();
        $service->searchByContent('refund');
        $service->searchByFilename('refund.md');

        foreach ($this->calls as [, , $filters]) {
            $this->assertArrayNotHasKey('audiences', $filters);
            $this->assertArrayNotHasKey('slugs', $filters);
        }
    }

    /** @test */
    public function a_slug_restriction_reaches_every_collection_searched_by_content(): void
    {
        $service = $this->service();
        $service->searchByContent('refund', ['slugs' => ['rooms']]);

        $this->assertCount(2, $this->calls);
        foreach ($this->calls as [, $collection, $filters]) {
            $this->assertSame(['rooms'], $filters['slugs'] ?? null, "on [{$collection}]");
        }
    }
}
