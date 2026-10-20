<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Support\AliasNormalizer;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The Query-Understanding entity catalog shows the model an entity's REAL synonyms.
 *
 * Measured on the host config (2026-10-06): 440 of 751 declared aliases were
 * derivable spellings of the label or of an earlier alias (plural, accent-less,
 * snake_case, glued). They filled the "first 5" slots, so 196 of the 311 real
 * synonyms never reached the model (Access: fob, télécommande; Extra: storage,
 * casier; VisitDetail: visit report). Derivable spellings are dropped by
 * AliasNormalizer and matched structurally instead.
 */
class QueryUnderstandingEntityCatalogTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Entity configs written the way the generators wrote them: every spelling listed. */
    private function noisyConfigs(): array
    {
        return [
            'App\\Models\\Condo\\Access' => [
                'graph' => ['label' => 'Access'],
                'metadata' => [
                    'aliases' => [
                        'access', 'accesses', 'accès', 'acces', 'clé', 'cle', 'fob', 'télécommande',
                        'telecommande', 'clés', 'cles', 'fobs', 'télécommandes', 'telecommandes',
                    ],
                    'description' => 'Physical access device assigned to a unit',
                ],
            ],
            'App\\Models\\Condo\\Extra' => [
                'graph' => ['label' => 'Extra'],
                'metadata' => [
                    'aliases' => [
                        'extra', 'extras', 'parking', 'rangement', 'stationnement', 'storage',
                        'casier', 'parkings', 'rangements', 'casiers',
                    ],
                    'description' => 'Additional private lot attached to a unit',
                ],
            ],
            'App\\Models\\Condo\\VisitDetail' => [
                'graph' => ['label' => 'VisitDetail'],
                'metadata' => [
                    'aliases' => [
                        'visit_detail', 'visit_details', 'visit detail', 'visit details',
                        'détail de visite', 'détails de visite', 'rapport de visite', 'visit report',
                    ],
                    'description' => 'Outcome of a maintenance visit',
                ],
            ],
        ];
    }

    private function aliasesOf(array $catalog, string $label): array
    {
        foreach ($catalog as $entry) {
            if ($entry['label'] === $label) {
                return $entry['aliases'];
            }
        }

        $this->fail("No catalog entry for {$label}");
    }

    private function catalogText(array $catalog): string
    {
        $service = new QueryUnderstandingService(Mockery::mock(LlmProviderInterface::class), Mockery::mock(AiLogger::class), []);
        $format = new \ReflectionMethod($service, 'formatCatalog');
        $format->setAccessible(true);

        return $format->invoke($service, $catalog);
    }

    /** @test */
    public function derivable_spellings_of_the_label_or_of_an_earlier_alias_are_not_shown(): void
    {
        $catalog = QueryUnderstandingService::buildEntityCatalog($this->noisyConfigs());

        $this->assertSame(['accesses', 'accès', 'clé', 'fob', 'télécommande'], $this->aliasesOf($catalog, 'Access'));
        $this->assertSame(['parking', 'rangement', 'stationnement', 'storage', 'casier'], $this->aliasesOf($catalog, 'Extra'));
        $this->assertSame(['détail de visite', 'rapport de visite', 'visit report'], $this->aliasesOf($catalog, 'VisitDetail'));
    }

    /** @test */
    public function synonyms_that_the_first_five_raw_aliases_hid_now_reach_the_prompt(): void
    {
        $configs = $this->noisyConfigs();
        $text = $this->catalogText(QueryUnderstandingService::buildEntityCatalog($configs));

        // None of these was among the first five declared aliases of its entity.
        foreach (['fob', 'télécommande', 'storage', 'casier', 'rapport de visite', 'visit report'] as $hidden) {
            $this->assertStringContainsString($hidden, $text);
        }
        $this->assertStringContainsString('- Access (aka: accesses, accès, clé, fob, télécommande): Physical access device', $text);
    }

    /** @test */
    public function the_catalog_is_not_larger_than_the_first_five_raw_aliases_were(): void
    {
        $configs = $this->noisyConfigs();

        $legacy = [];
        $legacySynonyms = 0;
        foreach ($configs as $fqcn => $config) {
            $shown = array_slice($config['metadata']['aliases'], 0, 5);
            $legacySynonyms += count(AliasNormalizer::distinct($shown, $config['graph']['label']));
            $legacy[] = ['label' => $config['graph']['label'], 'aliases' => $shown, 'description' => $config['metadata']['description']];
        }

        $catalog = QueryUnderstandingService::buildEntityCatalog($configs);
        $synonyms = array_sum(array_map(fn (array $entry) => count($entry['aliases']), $catalog));

        $this->assertLessThanOrEqual(strlen($this->catalogText($legacy)), strlen($this->catalogText($catalog)));
        $this->assertSame(7, $legacySynonyms, 'what the model saw before: 3 + 3 + 1 distinct synonyms');
        $this->assertSame(13, $synonyms, 'every real synonym is visible now');
    }

    /** @test */
    public function the_synonyms_shown_per_entity_are_capped(): void
    {
        $many = [];
        for ($i = 0; $i < 40; $i++) {
            $many[] = 'synonym' . chr(97 + intdiv($i, 26)) . chr(97 + $i % 26);
        }

        $catalog = QueryUnderstandingService::buildEntityCatalog([
            'App\\Models\\Thing' => ['graph' => ['label' => 'Thing'], 'metadata' => ['aliases' => $many]],
        ]);

        $this->assertCount(QueryUnderstandingService::CATALOG_MAX_ALIASES, $catalog[0]['aliases']);
        $this->assertSame(array_slice($many, 0, QueryUnderstandingService::CATALOG_MAX_ALIASES), $catalog[0]['aliases']);
    }

    /** @test */
    public function an_entity_whose_aliases_are_all_spellings_of_its_label_shows_none(): void
    {
        $catalog = QueryUnderstandingService::buildEntityCatalog([
            'App\\Models\\Finance\\BillDetail' => [
                'graph' => ['label' => 'BillDetail'],
                'metadata' => ['aliases' => ['bill_detail', 'bill_details', 'bill detail', 'bill details', 'billdetail', 'billdetails']],
            ],
        ]);

        $this->assertSame([], $catalog[0]['aliases']);
        $this->assertSame('- BillDetail', $this->catalogText($catalog));
    }

    // ---------------------------------------------------------------- entity_types

    private function entityTypesFor(array $llmEntityTypes, array $entityConfigs): array
    {
        config(['entities' => $entityConfigs]);

        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturn([
            'entity_types' => $llmEntityTypes,
            'person_names' => [],
            'property_concepts' => [],
            'search_query' => 'rewritten',
            'possessive_words' => [],
            'intent' => 'list',
            'question_type' => 'data_query',
            'clarified_question' => '',
            'search_tags' => [],
        ]);

        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('debug')->byDefault();
        $logger->shouldReceive('error')->byDefault();

        return (new QueryUnderstandingService($llm, $logger, []))
            ->analyze('whatever question', QueryUnderstandingService::buildEntityCatalog($entityConfigs))
            ->entityTypes;
    }

    /** @test */
    public function an_entity_type_spelled_as_a_variant_of_a_label_resolves_to_the_label(): void
    {
        $types = $this->entityTypesFor(['visit_details', 'Extras', 'ACCESS'], $this->noisyConfigs());

        $this->assertSame(['VisitDetail', 'Extra', 'Access'], $types);
    }

    /** @test */
    public function an_entity_type_given_as_a_synonym_resolves_to_its_entity_even_in_an_unlisted_spelling(): void
    {
        // The config lists none of these spellings after the cleanup.
        $configs = [
            'App\\Models\\Condo\\Access' => ['graph' => ['label' => 'Access'], 'metadata' => ['aliases' => ['accès', 'fob', 'télécommande']]],
            'App\\Models\\Condo\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['aliases' => ['apartment']]],
        ];

        $this->assertSame(['Access'], $this->entityTypesFor(['Acces'], $configs));
        $this->assertSame(['Access'], $this->entityTypesFor(['fobs'], $configs));
        $this->assertSame(['Access', 'Unit'], $this->entityTypesFor(['Télécommandes', 'apartments'], $configs));
    }

    /** @test */
    public function a_synonym_claimed_by_two_entities_or_an_unknown_word_is_still_dropped(): void
    {
        $configs = [
            'App\\Models\\Announcement' => ['graph' => ['label' => 'Announcement'], 'metadata' => ['aliases' => ['avis', 'annonce']]],
            'App\\Models\\Notification' => ['graph' => ['label' => 'Notification'], 'metadata' => ['aliases' => ['avis', 'alerte']]],
        ];

        $this->assertSame([], $this->entityTypesFor(['avis', 'Dragon'], $configs));
        $this->assertSame(['Notification'], $this->entityTypesFor(['alertes'], $configs));
    }
    /**
     * @test Structural-only entities (`metadata.askable => false`): mapped, joinable and scoped,
     * but never offered to QU as the SUBJECT of a question. Eval p12–p16: the GL snapshot entity
     * "AccountBalance" was picked for every "balance" question ("Show me the balance",
     * "What's my balance?") and mis-routed it.
     */
    public function an_entity_marked_not_askable_is_left_out_of_the_catalog(): void
    {
        $catalog = QueryUnderstandingService::buildEntityCatalog([
            'App\Models\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['aliases' => ['apartment']]],
            'App\Models\AccountBalance' => ['graph' => ['label' => 'AccountBalance'], 'metadata' => ['askable' => false, 'aliases' => ['opening balance']]],
            'App\Models\Fund' => ['graph' => ['label' => 'Fund'], 'metadata' => ['askable' => true]],
        ]);

        $this->assertSame(['Unit', 'Fund'], array_column($catalog, 'label'));
    }
}
