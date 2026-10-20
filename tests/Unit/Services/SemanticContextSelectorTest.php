<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\SelectedEntity;
use Condoedge\Ai\Services\SemanticContextSelector;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Selection-map keying + provenance (Task 5.10).
 *
 * The relationship expansion used to key new entities by the relationship's
 * target_label (a SHORT label) while every other path keys by the config FQCN —
 * so the expanded entity carried config=null and a later FQCN-keyed insert of the
 * same entity (e.g. Query Understanding) duplicated it under a second key.
 */
class SemanticContextSelectorTest extends TestCase
{
    private function selector(array $hits): SemanticContextSelector
    {
        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('getCollectionInfo')->andReturn(['points_count' => 10]);
        $vector->shouldReceive('search')->andReturn($hits);
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn([0.1, 0.2]);

        return new SemanticContextSelector($vector, $embedding, new AiLogger());
    }

    private function configs(): array
    {
        return [
            'App\\Models\\Unit' => [
                'graph' => [
                    'label' => 'Unit',
                    'relationships' => [
                        ['type' => 'UNION', 'target_label' => 'Union', 'foreign_key' => 'union_id'],
                    ],
                ],
            ],
            'App\\Models\\Union' => ['graph' => ['label' => 'Union']],
        ];
    }

    public function test_relationship_expansion_keys_the_expanded_entity_by_its_config_fqcn(): void
    {
        $selector = $this->selector([
            ['score' => 0.9, 'metadata' => ['type' => 'entity', 'entity' => 'App\\Models\\Unit', 'text' => 'Unit']],
        ]);

        $result = $selector->selectRelevantContext('units', $this->configs(), ['threshold' => 0.5]);

        $this->assertArrayHasKey('App\\Models\\Union', $result->entities);
        $this->assertArrayNotHasKey('Union', $result->entities);
        $this->assertSame($this->configs()['App\\Models\\Union'], $result->entities['App\\Models\\Union']->config);
    }

    public function test_selected_entities_carry_their_provenance(): void
    {
        $selector = $this->selector([
            ['score' => 0.9, 'metadata' => ['type' => 'entity', 'entity' => 'App\\Models\\Unit', 'text' => 'Unit']],
        ]);

        $result = $selector->selectRelevantContext('units', $this->configs(), ['threshold' => 0.5]);

        $this->assertSame(SelectedEntity::SOURCE_VECTOR, $result->entities['App\\Models\\Unit']->source);
        $this->assertSame(SelectedEntity::SOURCE_EXPANSION, $result->entities['App\\Models\\Union']->source);
    }

    // ------------------------------------------------------------ alias spellings

    /** The texts ai:index-context embeds for these configs. */
    private function indexedTexts(array $configs): array
    {
        $texts = [];
        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('ensureCollection')->once();
        $vector->shouldReceive('deleteAll')->once()->andReturn(true);
        $vector->shouldReceive('upsertBatch')->andReturn(true);
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embedBatch')->once()->andReturnUsing(function (array $batch) use (&$texts) {
            $texts = $batch;

            return array_fill(0, count($batch), [0.1, 0.2]);
        });

        (new SemanticContextSelector($vector, $embedding, new AiLogger()))->indexContext($configs);

        return $texts;
    }

    private function accessConfig(array $aliases): array
    {
        return ['App\\Models\\Condo\\Access' => [
            'graph' => ['label' => 'Access'],
            'metadata' => ['description' => 'Physical access device', 'aliases' => $aliases],
        ]];
    }

    public function test_the_index_embeds_each_synonym_once_not_every_spelling_of_it(): void
    {
        $texts = $this->indexedTexts($this->accessConfig([
            'access', 'accesses', 'accès', 'acces', 'fob', 'télécommande', 'telecommande', 'fobs', 'télécommandes',
        ]));

        $this->assertSame([
            'Access - Physical access device',
            'access (Access)',
            'accesses (Access)',
            'accès (Access)',
            'fob (Access)',
            'télécommande (Access)',
        ], $texts);
    }

    public function test_the_index_is_the_same_whether_or_not_the_config_lists_the_derivable_spellings(): void
    {
        $noisy = $this->indexedTexts($this->accessConfig([
            'access', 'accesses', 'accès', 'acces', 'fob', 'télécommande', 'telecommande', 'fobs', 'télécommandes',
        ]));
        $clean = $this->indexedTexts($this->accessConfig(['accesses', 'accès', 'fob', 'télécommande']));

        $this->assertSame($noisy, $clean);
    }

    public function test_the_index_embeds_the_entity_name_as_words_and_every_name_it_goes_by(): void
    {
        $texts = $this->indexedTexts([
            'App\\Models\\Condo\\VisitDetail' => ['graph' => ['label' => 'VisitDetail'], 'metadata' => ['aliases' => ['visit_details', 'visit report']]],
            'App\\Models\\Condo\\Unit' => ['graph' => ['label' => 'Lot'], 'metadata' => ['aliases' => ['lots', 'units', 'apartment']]],
        ]);

        $this->assertSame([
            'VisitDetail',
            'visit detail (VisitDetail)',
            'visit report (VisitDetail)',
            'Unit',
            'lot (Unit)',
            'unit (Unit)',
            'apartment (Unit)',
        ], $texts);
    }

    public function test_the_keyword_fallback_detects_an_entity_by_an_unlisted_spelling(): void
    {
        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('getCollectionInfo')->andReturn(['points_count' => 0]);
        $selector = new SemanticContextSelector($vector, Mockery::mock(EmbeddingProviderInterface::class), new AiLogger());

        $configs = [
            'App\\Models\\Condo\\Access' => ['graph' => ['label' => 'Access'], 'metadata' => ['aliases' => ['accès', 'fobs']]],
            'App\\Models\\Condo\\VisitDetail' => ['graph' => ['label' => 'VisitDetail'], 'metadata' => ['aliases' => ['rapport de visite']]],
            'App\\Models\\Finance\\Invoice' => ['graph' => ['label' => 'Invoice'], 'metadata' => ['aliases' => ['facture']]],
        ];

        $detected = fn (string $question): array => array_keys($selector->selectRelevantContext($question, $configs)->entities);

        $this->assertSame(['App\\Models\\Condo\\Access'], $detected('Liste des acces actifs'));
        $this->assertSame(['App\\Models\\Condo\\Access'], $detected('is this fob active'));
        $this->assertSame(['App\\Models\\Condo\\VisitDetail'], $detected('show the visit_details of May'));
        $this->assertSame(['App\\Models\\Condo\\VisitDetail'], $detected('les rapports de visites du mois'));
        $this->assertSame([], $detected('how is the weather'));
    }

    public function test_selected_entity_source_defaults_to_vector(): void
    {
        $this->assertSame(SelectedEntity::SOURCE_VECTOR, (new SelectedEntity(0.5, 'x'))->source);
    }

    private function scopeHit(float $score, string $entity, string $scope, string $text = 'example'): array
    {
        return ['score' => $score, 'metadata' => ['type' => 'scope', 'entity' => $entity, 'scope_key' => $scope, 'text' => $text]];
    }

    private function boardConfigs(): array
    {
        return [
            'App\Models\BoardMember' => ['graph' => ['label' => 'BoardMember']],
            'App\Models\Component' => ['graph' => ['label' => 'Component']],
            'App\Models\Team' => ['graph' => ['label' => 'Team']],
        ];
    }

    public function test_a_lower_scoring_hit_never_overwrites_a_scope_with_the_same_key(): void
    {
        // Results arrive score-descending; the former last-write-wins keyed map let
        // Component.for_union (0.52) overwrite BoardMember.for_union (0.74).
        $selector = $this->selector([
            $this->scopeHit(0.74, 'App\Models\BoardMember', 'for_union'),
            $this->scopeHit(0.52, 'App\Models\Component', 'for_union'),
        ]);

        $result = $selector->selectRelevantContext('board', $this->boardConfigs(), ['threshold' => 0.45]);

        $this->assertSame('App\Models\BoardMember', $result->scopes['for_union']->entity);
        $this->assertSame(0.74, $result->scopes['for_union']->score);
    }

    public function test_the_seed_entities_scopes_are_searched_and_ranked_first(): void
    {
        config(['ai.semantic_context.seed_scope_search' => true]);
        $filters = [];

        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('getCollectionInfo')->andReturn(['points_count' => 10]);
        $vector->shouldReceive('search')->andReturnUsing(function ($collection, $vec, $limit, $filter = []) use (&$filters) {
            $filters[] = $filter;
            if ($filter === []) {
                // Unfiltered: noise scopes on unrelated entities outrank the seed's.
                return [
                    $this->scopeHit(0.53, 'App\Models\Team', 'members'),
                    $this->scopeHit(0.52, 'App\Models\Component', 'for_union'),
                ];
            }

            return [
                $this->scopeHit(0.61, 'App\Models\BoardMember', 'current_board_members'),
                $this->scopeHit(0.47, 'App\Models\BoardMember', 'for_union'),
                $this->scopeHit(0.30, 'App\Models\BoardMember', 'below_threshold'),
            ];
        });
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn([0.1, 0.2]);
        $selector = new SemanticContextSelector($vector, $embedding, new AiLogger());

        $result = $selector->selectRelevantContext('board', $this->boardConfigs(), [
            'threshold' => 0.45,
            'query_understanding' => ['entity_types' => ['BoardMember']],
        ]);

        // Seed scopes first (a seed's own scope wins its key), then the rest; top 3.
        $this->assertSame(['current_board_members', 'for_union', 'members'], array_keys($result->scopes));
        $this->assertSame('App\Models\BoardMember', $result->scopes['for_union']->entity);
        $this->assertArrayNotHasKey('below_threshold', $result->scopes);

        // The second search is restricted to the seed entities' scope points.
        $this->assertCount(2, $filters);
        $this->assertSame([
            'must' => [
                ['key' => 'type', 'match' => ['value' => 'scope']],
                ['key' => 'entity', 'match' => ['any' => ['App\Models\BoardMember']]],
            ],
        ], $filters[1]);
    }

    public function test_the_seed_scope_search_can_be_disabled(): void
    {
        config(['ai.semantic_context.seed_scope_search' => false]);
        $calls = 0;

        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('getCollectionInfo')->andReturn(['points_count' => 10]);
        $vector->shouldReceive('search')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return [];
        });
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn([0.1, 0.2]);

        (new SemanticContextSelector($vector, $embedding, new AiLogger()))->selectRelevantContext('board', $this->boardConfigs(), [
            'query_understanding' => ['entity_types' => ['BoardMember']],
        ]);

        $this->assertSame(1, $calls);
    }
}
