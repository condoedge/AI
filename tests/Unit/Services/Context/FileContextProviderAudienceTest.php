<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\DTOs\ResolvedAudience;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FilenameExtractor;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Tests\Fixtures\FixedAudienceResolver;
use Mockery;

/**
 * Every document search of a turn — by file name, by content, and the ranking the
 * passage selection and the document map read — carries the asker's readable audiences.
 */
class FileContextProviderAudienceTest extends TestCase
{
    /** @var list<array{0: string, 1: array}> */
    private array $searches = [];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function provider(): FileContextProvider
    {
        $search = Mockery::mock(FileSearchService::class);
        foreach (['searchByContent', 'searchByFilename'] as $method) {
            $search->shouldReceive($method)->andReturnUsing(function ($query, array $options = []) use ($method) {
                $this->searches[] = [$method, $options];

                return [];
            });
        }
        $access = Mockery::mock(FileAccessResolverInterface::class);
        $access->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $access->shouldReceive('isPhysicalFile')->andReturn(true);
        $access->shouldReceive('filterAccessibleFileIds')->andReturnUsing(static fn (array $ids): array => $ids);
        $access->shouldReceive('getAccessibleFileIds')->andReturn([]);
        $filenames = Mockery::mock(FilenameExtractor::class);
        $filenames->shouldReceive('extract')->andReturn(['billets.md']);

        return new FileContextProvider($search, $access, $filenames);
    }

    private function runTurn(): void
    {
        $provider = $this->provider();
        $user = (object) ['id' => 5];
        $provider->searchRelevantFiles('Comment rembourser des billets?', $user);
        $provider->rankedChunks('Comment rembourser des billets?', $user);
    }

    /** @test */
    public function every_search_carries_the_askers_audiences(): void
    {
        FixedAudienceResolver::$next = new ResolvedAudience('buyer');
        config(['ai.audiences' => ['resolver' => FixedAudienceResolver::class, 'map' => ['buyer' => ['documents' => ['acheteur']]]]]);

        $this->runTurn();

        $this->assertSame(['searchByFilename', 'searchByContent', 'searchByContent'], array_column($this->searches, 0));
        foreach ($this->searches as [$method, $options]) {
            $this->assertSame(['acheteur'], $options['audiences'] ?? null, $method);
        }
    }

    /** @test */
    public function without_audiences_configured_no_search_is_filtered(): void
    {
        $this->runTurn();

        foreach ($this->searches as [, $options]) {
            $this->assertArrayNotHasKey('audiences', $options);
        }
    }
}
