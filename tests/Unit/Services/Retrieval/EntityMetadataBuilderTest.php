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
    public function all_returns_only_entities_with_metadata(): void
    {
        $builder = new EntityMetadataBuilder($this->entityConfigs, null);

        $all = $builder->all();

        $this->assertArrayHasKey('App\\Models\\Person', $all);
        $this->assertArrayNotHasKey('App\\Models\\Team', $all);
    }
}
