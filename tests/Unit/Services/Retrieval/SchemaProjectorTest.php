<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\SelectedEntity;
use Condoedge\Ai\DTOs\SelectedRelationship;
use Condoedge\Ai\DTOs\SemanticSelectionResult;
use Condoedge\Ai\Services\Retrieval\SchemaProjector;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class SchemaProjectorTest extends TestCase
{
    private $vectorStore;
    private $embeddingProvider;

    public function setUp(): void
    {
        parent::setUp();

        $this->vectorStore = Mockery::mock(VectorStoreInterface::class);
        $this->embeddingProvider = Mockery::mock(EmbeddingProviderInterface::class);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeProjector(array $entityConfigs = []): SchemaProjector
    {
        return new SchemaProjector(
            $this->vectorStore,
            $this->embeddingProvider,
            new AiLogger(),
            $entityConfigs
        );
    }

    /** @test */
    public function filter_by_relevance_keeps_only_selected_labels_and_their_properties(): void
    {
        $fullSchema = [
            'labels' => ['Team', 'Person', 'Order'],
            'relationships' => ['MEMBER_OF', 'PLACED'],
            'properties' => [
                'Team' => ['id', 'name'],
                'Person' => ['id', 'email'],
                'Order' => ['id', 'total'],
            ],
            'propertyKeys' => ['id', 'name', 'email', 'total'],
        ];

        $selection = new SemanticSelectionResult(
            entities: ['App\\Models\\Team' => new SelectedEntity(0.9, 'team')],
        );

        $filtered = $this->makeProjector()->filterByRelevance($fullSchema, $selection);

        $this->assertSame(['Team'], $filtered['labels']);
        $this->assertSame(['Team' => ['id', 'name']], $filtered['properties']);
        $this->assertSame(['id', 'name'], $filtered['propertyKeys']);
        $this->assertSame([], $filtered['relationships']);
    }

    /** @test */
    public function filter_by_relevance_pulls_in_relationship_connected_entities(): void
    {
        $fullSchema = [
            'labels' => ['Team', 'Person'],
            'relationships' => ['MEMBER_OF'],
            'properties' => [
                'Team' => ['id'],
                'Person' => ['id', 'email'],
            ],
            'propertyKeys' => ['id', 'email'],
        ];

        $selection = new SemanticSelectionResult(
            entities: ['App\\Models\\Team' => new SelectedEntity(0.9, 'team')],
            relationships: [
                'MEMBER_OF' => new SelectedRelationship(0.8, 'Person', 'Team', 'MEMBER_OF'),
            ],
        );

        $filtered = $this->makeProjector()->filterByRelevance($fullSchema, $selection);

        $this->assertContains('Team', $filtered['labels']);
        $this->assertContains('Person', $filtered['labels']);
        $this->assertSame(['MEMBER_OF'], $filtered['relationships']);
        $this->assertArrayHasKey('Person', $filtered['properties']);
    }

    /** @test */
    public function filter_by_labels_restricts_labels_and_properties(): void
    {
        $schema = [
            'labels' => ['Team', 'Person', 'Order'],
            'properties' => [
                'Team' => ['id'],
                'Person' => ['id'],
                'Order' => ['id'],
            ],
        ];

        $filtered = $this->makeProjector()->filterByLabels($schema, ['Team', 'Order']);

        $this->assertSame(['Team', 'Order'], array_values($filtered['labels']));
        $this->assertArrayHasKey('Team', $filtered['properties']);
        $this->assertArrayHasKey('Order', $filtered['properties']);
        $this->assertArrayNotHasKey('Person', $filtered['properties']);
    }

    /** @test */
    public function find_relevant_entity_labels_normalizes_fqcn_and_expands_category(): void
    {
        $this->embeddingProvider->shouldReceive('embed')->andReturn([0.1]);
        $this->vectorStore->shouldReceive('search')
            ->with('semantic_entities', [0.1], 10)
            ->andReturn([
                ['payload' => ['label' => 'App\\Models\\Team']],
            ]);

        // Team and Person share the 'Models' category segment, so finding Team
        // should expand to include Person.
        $entityConfigs = [
            'App\\Models\\Team' => ['graph' => ['label' => 'Team']],
            'App\\Models\\Person' => ['graph' => ['label' => 'Person']],
        ];

        $labels = $this->makeProjector($entityConfigs)->findRelevantEntityLabels('teams');

        $this->assertContains('Team', $labels);
        $this->assertContains('Person', $labels);
    }

    /** @test */
    public function find_relevant_entity_labels_returns_empty_on_failure(): void
    {
        $this->embeddingProvider->shouldReceive('embed')->andThrow(new \Exception('down'));

        $labels = $this->makeProjector()->findRelevantEntityLabels('teams');

        $this->assertSame([], $labels);
    }
}
