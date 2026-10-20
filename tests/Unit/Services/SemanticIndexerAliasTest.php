<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Services\SemanticIndexer;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * ai:index-semantic embeds each WORD for an entity once — the entity name as
 * words plus its real synonyms — not every derivable spelling (AliasNormalizer),
 * and embeds the same points whether or not the config lists those spellings.
 */
class SemanticIndexerAliasTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return array<int, array{0: string, 1: string}> [type, text] of every embedded entity point */
    private function indexedPoints(array $aliases): array
    {
        $points = [];
        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('collectionExists')->andReturn(true);
        $vector->shouldReceive('upsert')->andReturnUsing(function (string $collection, array $batch) use (&$points) {
            foreach ($batch as $point) {
                $points[] = [$point['payload']['type'], $point['payload']['text']];
            }

            return true;
        });
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embedBatch')->andReturnUsing(fn (array $texts) => array_fill(0, count($texts), [0.1, 0.2]));

        (new SemanticIndexer($embedding, $vector, [
            'App\\Models\\Condo\\VisitDetail' => [
                'graph' => ['label' => 'VisitDetail'],
                'metadata' => ['description' => 'Outcome of a visit', 'aliases' => $aliases],
            ],
        ]))->indexEntities();

        return $points;
    }

    /** @test */
    public function each_synonym_is_embedded_once_and_the_name_as_words(): void
    {
        $points = $this->indexedPoints([
            'visit_detail', 'visit_details', 'visit detail', 'visit details', 'rapport de visite', 'rapports de visite', 'visit report',
        ]);

        $this->assertSame([
            ['name', 'App\\Models\\Condo\\VisitDetail'],
            ['alias', 'visit detail'],
            ['alias', 'rapport de visite'],
            ['alias', 'visit report'],
            ['description', 'Outcome of a visit'],
        ], $points);
    }

    /** @test */
    public function the_points_do_not_depend_on_the_config_listing_the_derivable_spellings(): void
    {
        $noisy = $this->indexedPoints(['visit_detail', 'visit_details', 'visit detail', 'rapport de visite', 'rapports de visite']);
        $clean = $this->indexedPoints(['rapport de visite']);

        $this->assertSame($noisy, $clean);
    }
}
