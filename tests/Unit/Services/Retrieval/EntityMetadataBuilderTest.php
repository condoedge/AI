<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\DTOs\SelectedEntity;
use Condoedge\Ai\DTOs\SelectedScope;
use Condoedge\Ai\DTOs\SemanticSelectionResult;
use Condoedge\Ai\Services\Retrieval\EntityMetadataBuilder;
use Condoedge\Ai\Tests\TestCase;

class EntityMetadataBuilderTest extends TestCase
{
    private array $entityConfigs;

    public function setUp(): void
    {
        parent::setUp();

        $this->entityConfigs = [
            'App\\Models\\Person' => [
                'graph' => ['label' => 'Person'],
                'metadata' => [
                    'aliases' => ['people', 'users'],
                    'description' => 'People',
                    'scopes' => [
                        'volunteers' => [
                            'description' => 'People who volunteer',
                            'filter' => ['type' => 'volunteer'],
                            'cypher_pattern' => "type = 'volunteer'",
                        ],
                    ],
                ],
            ],
            'App\\Models\\Team' => [
                'graph' => ['label' => 'Team'],
                // No metadata - should be skipped by string detection.
            ],
        ];
    }

    /** @test */
    public function from_question_detects_entity_by_alias_and_scope(): void
    {
        $builder = new EntityMetadataBuilder($this->entityConfigs, null);

        $result = $builder->fromQuestion('How many volunteers do we have?');

        $this->assertContains('App\\Models\\Person', $result['detected_entities']);
        $this->assertArrayHasKey('volunteers', $result['detected_scopes']);
        $this->assertSame('Person', $result['detected_scopes']['volunteers']['entity']);
        $this->assertSame("type = 'volunteer'", $result['detected_scopes']['volunteers']['cypher_pattern']);
    }

    /**
     * @test
     * A derivable spelling (plural, accent-less, snake_case, spaced) of a label or
     * alias is detected without being listed in the config — AliasNormalizer.
     * @dataProvider unlistedSpellings
     */
    public function from_question_detects_an_entity_by_an_unlisted_spelling(string $question, string $expected): void
    {
        $builder = new EntityMetadataBuilder([
            'App\\Models\\Condo\\Access' => [
                'graph' => ['label' => 'Access'],
                'metadata' => ['aliases' => ['accès', 'fobs', 'télécommande']],
            ],
            'App\\Models\\Condo\\VisitDetail' => [
                'graph' => ['label' => 'VisitDetail'],
                'metadata' => ['aliases' => ['rapport de visite']],
            ],
            'App\\Models\\Condo\\Unit' => [
                'graph' => ['label' => 'Lot'],
                'metadata' => ['description' => 'A private fraction'],
            ],
        ], null);

        $result = $builder->fromQuestion($question);

        $this->assertSame([$expected], $result['detected_entities']);
    }

    public static function unlistedSpellings(): array
    {
        return [
            'accent-less alias' => ['Liste des acces actifs', 'App\\Models\\Condo\\Access'],
            'singular of a plural alias' => ['Is this fob active?', 'App\\Models\\Condo\\Access'],
            'accent-less plural alias' => ['Combien de telecommandes?', 'App\\Models\\Condo\\Access'],
            'snake_case plural label' => ['show the visit_details of May', 'App\\Models\\Condo\\VisitDetail'],
            'spaced label' => ['Show every Visit Detail', 'App\\Models\\Condo\\VisitDetail'],
            'plural words of an alias' => ['les rapports de visites du mois', 'App\\Models\\Condo\\VisitDetail'],
            'graph label that is not the class name' => ['combien de lots?', 'App\\Models\\Condo\\Unit'],
        ];
    }

    /** @test */
    public function from_question_skips_entities_without_metadata(): void
    {
        $builder = new EntityMetadataBuilder($this->entityConfigs, null);

        $result = $builder->fromQuestion('Show me all teams');

        $this->assertNotContains('App\\Models\\Team', $result['detected_entities']);
        $this->assertArrayNotHasKey('App\\Models\\Team', $result['entity_metadata']);
    }

    /** @test */
    public function from_semantic_selection_builds_entities_and_scopes(): void
    {
        $builder = new EntityMetadataBuilder($this->entityConfigs, null);

        $selection = new SemanticSelectionResult(
            entities: [
                'App\\Models\\Person' => new SelectedEntity(0.9, 'people', $this->entityConfigs['App\\Models\\Person']),
            ],
            scopes: [
                'volunteers' => new SelectedScope(0.85, 'Person', 'show volunteers'),
            ],
        );

        $result = $builder->fromSemanticSelection('show volunteers', $selection);

        $this->assertContains('App\\Models\\Person', $result['detected_entities']);
        $this->assertArrayHasKey('volunteers', $result['detected_scopes']);
        $this->assertSame('semantic', $result['detected_scopes']['volunteers']['match_type']);
        $this->assertSame(0.85, $result['detected_scopes']['volunteers']['match_score']);
    }

    /** @test */
    public function from_semantic_selection_resolves_scope_configs_when_the_scope_carries_the_full_entity_class_name(): void
    {
        // ROOT CAUSE of the 2026-07-23 empty CURRENT_BOARD_MEMBERS prompt block:
        // the context_index stores scope points with the FULL config key
        // ('App\Models\Person'), but findFullEntityName only compared basenames —
        // the lookup missed, the scope config resolved to [], and the scope
        // emitted as an EMPTY block (no concept, no cypher_pattern).
        $builder = new EntityMetadataBuilder($this->entityConfigs, null);

        $selection = new SemanticSelectionResult(
            entities: [
                'App\\Models\\Person' => new SelectedEntity(0.9, 'people', $this->entityConfigs['App\\Models\\Person']),
            ],
            scopes: [
                'volunteers' => new SelectedScope(0.85, 'App\\Models\\Person', 'show volunteers'),
            ],
        );

        $result = $builder->fromSemanticSelection('show volunteers', $selection);

        $this->assertArrayHasKey('volunteers', $result['detected_scopes']);
        $this->assertSame("type = 'volunteer'", $result['detected_scopes']['volunteers']['cypher_pattern']);
        $this->assertSame(['type' => 'volunteer'], $result['detected_scopes']['volunteers']['filter']);
    }

    /** @test */
    public function all_returns_only_entities_with_metadata(): void
    {
        $builder = new EntityMetadataBuilder($this->entityConfigs, null);

        $all = $builder->all();

        $this->assertArrayHasKey('App\\Models\\Person', $all);
        $this->assertArrayNotHasKey('App\\Models\\Team', $all);
    }
}
