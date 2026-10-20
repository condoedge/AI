<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\DTOs\SelectedEntity;
use Condoedge\Ai\DTOs\SelectedScope;
use Condoedge\Ai\DTOs\SemanticSelectionResult;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Services\SemanticContextSelector;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Task 5.10 — the retriever feeds the EntitySelectionPruner and projects the
 * schema onto the pruned label set (seed > scope > identity > tenant > closure >
 * ownership; vector hits only when nothing seeded the selection).
 */
class ContextRetrieverPruningTest extends TestCase
{
    private const CONFIGS = [
        'App\\Models\\Unit' => [
            'graph' => ['label' => 'Unit', 'properties' => ['id', 'name'], 'relationships' => [
                ['type' => 'UNION', 'target_label' => 'Union', 'foreign_key' => 'union_id'],
            ]],
            'security' => ['team_path' => 'union.team_id'],
        ],
        'App\\Models\\Union' => ['graph' => ['label' => 'Union', 'properties' => ['id', 'name', 'team_id']], 'security' => ['team_path' => 'team_id']],
        'App\\Models\\Invoice' => ['graph' => ['label' => 'Invoice', 'properties' => ['id', 'due_amount']]],
        'App\\Models\\Tax' => ['graph' => ['label' => 'Tax', 'properties' => ['id']]],
        'App\\Models\\Signature' => ['graph' => ['label' => 'Signature', 'properties' => ['id']]],
        'App\\Models\\User' => ['graph' => ['label' => 'User', 'properties' => ['id', 'current_team_id']]],
        'App\\Models\\Team' => ['graph' => ['label' => 'Team', 'properties' => ['id', 'name']]],
    ];

    private $selector;
    private ContextRetriever $service;

    public function setUp(): void
    {
        parent::setUp();
        config(['entities' => self::CONFIGS]);
        config(['ai.semantic_context.always_include_identity_labels' => ['User', 'Team']]);
        config(['ai.security.enabled' => true]);
        config(['ai.semantic_context.pruning' => true]);
        config(['ai.calculations' => []]);

        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('collectionExists')->andReturn(false)->byDefault();
        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('getSchema')->andReturn([
            'labels' => ['Unit', 'Union', 'Invoice', 'Tax', 'Signature', 'User', 'Team'],
            'relationshipTypes' => ['UNION', 'CUSTOMER', 'TEAM', 'BELONGS_TO_TEAM', 'BELONGS_TO_USER'],
            'relationshipPatterns' => [
                ['from_label' => 'Unit', 'type' => 'UNION', 'to_label' => 'Union', 'count' => 10],
                ['from_label' => 'Invoice', 'type' => 'CUSTOMER', 'to_label' => 'Unit', 'count' => 10],
                ['from_label' => 'Union', 'type' => 'TEAM', 'to_label' => 'Team', 'count' => 3],
                ['from_label' => 'Tax', 'type' => 'BELONGS_TO_TEAM', 'to_label' => 'Team', 'count' => 3],
                ['from_label' => 'Signature', 'type' => 'BELONGS_TO_USER', 'to_label' => 'User', 'count' => 3],
            ],
            'propertyKeys' => ['id', 'name', 'team_id', 'due_amount', 'current_team_id'],
        ]);
        $graph->shouldReceive('query')->andReturn([]);
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn([0.1, 0.2]);
        $embedding->shouldReceive('embedBatch')->andReturnUsing(fn (array $texts) => array_fill(0, count($texts), [0.1, 0.2]));
        $embedding->shouldReceive('getModel')->andReturn('fake');

        $this->selector = Mockery::mock(SemanticContextSelector::class);
        $this->service = new ContextRetriever($vector, $graph, $embedding, new AiLogger(), self::CONFIGS);
        $this->service->setContextSelector($this->selector);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Vector selection with hub noise: two direct hits and their fan-out. */
    private function noisySelection(array $scopes = []): SemanticSelectionResult
    {
        return new SemanticSelectionResult(
            entities: [
                'App\\Models\\Tax' => new SelectedEntity(0.7, 'tax', self::CONFIGS['App\\Models\\Tax']),
                'App\\Models\\Signature' => new SelectedEntity(0.6, 'signature', self::CONFIGS['App\\Models\\Signature']),
                'App\\Models\\Team' => new SelectedEntity(0.4, 'Connected to Tax', self::CONFIGS['App\\Models\\Team'], SelectedEntity::SOURCE_EXPANSION),
            ],
            scopes: $scopes,
        );
    }

    private function retrieve(QueryUnderstandingResult $qu, SemanticSelectionResult $selection): PipelineContext
    {
        $this->selector->shouldReceive('selectRelevantContext')->andReturn($selection);
        $qus = Mockery::mock(QueryUnderstandingService::class);
        $qus->shouldReceive('analyze')->andReturn($qu);
        $this->service->setQueryUnderstanding($qus);

        return $this->service->retrieveContext('What does unit 310 owe?');
    }

    public function test_qu_seeds_replace_vector_noise_and_pull_in_the_tenant_hop(): void
    {
        $ctx = $this->retrieve(new QueryUnderstandingResult(entityTypes: ['Unit', 'Invoice']), $this->noisySelection());

        $this->assertSame(['Unit', 'Invoice', 'Union'], $ctx->selectionInfo['selected_entities']);
        $this->assertEqualsCanonicalizing(['Unit', 'Invoice', 'Union'], $ctx->graphSchema['labels']);
        $this->assertSame(['Unit', 'Invoice', 'Union'], array_map('class_basename', array_keys($ctx->semanticSelection->entities)));
        // FQCN-keyed with the real config.
        $this->assertSame(self::CONFIGS['App\\Models\\Union'], $ctx->semanticSelection->entities['App\\Models\\Union']->config);
        $this->assertSame('tenant', $ctx->selectionInfo['selection_tiers']['Union']);
        $this->assertTrue($ctx->selectionInfo['pruned']);
    }

    public function test_identity_labels_are_not_forced_into_a_non_possessive_question(): void
    {
        $ctx = $this->retrieve(new QueryUnderstandingResult(entityTypes: ['Unit']), $this->noisySelection());

        $this->assertNotContains('User', $ctx->graphSchema['labels']);
        $this->assertNotContains('Team', $ctx->graphSchema['labels']);
        $this->assertSame([], $ctx->selectionInfo['identity_entities']);
    }

    public function test_identity_labels_are_kept_for_a_possessive_question(): void
    {
        $ctx = $this->retrieve(new QueryUnderstandingResult(entityTypes: ['Unit'], isPossessive: true), $this->noisySelection());

        $this->assertContains('User', $ctx->graphSchema['labels']);
        $this->assertContains('Team', $ctx->graphSchema['labels']);
        $this->assertSame(['User', 'Team'], $ctx->selectionInfo['identity_entities']);
    }

    public function test_a_collective_possessive_question_keeps_the_identity_anchors_but_no_ownership_path(): void
    {
        // Eval p17 ("How much do we owe our suppliers?"): one flag fed both the
        // identity anchors and the personal ownership-path labels, so a question
        // about the GROUP's records shipped the asker's own ownership tier.
        config(['ai.user_entity_paths' => ['Unit' => ['entities' => ['User', 'Signature', 'Unit']]]]);

        $ctx = $this->retrieve(new QueryUnderstandingResult(
            entityTypes: ['Unit'],
            isPossessive: true,
            possessiveWords: ['we', 'our'],
            possessiveScope: QueryUnderstandingResult::SCOPE_COLLECTIVE,
        ), $this->noisySelection());

        $this->assertSame(['User', 'Team'], $ctx->selectionInfo['identity_entities']);
        $this->assertNotContains('Signature', $ctx->selectionInfo['selected_entities']);
        $this->assertNotContains('ownership', $ctx->selectionInfo['selection_tiers']);
    }

    public function test_a_personal_possessive_question_keeps_its_ownership_path(): void
    {
        config(['ai.user_entity_paths' => ['Unit' => ['entities' => ['User', 'Signature', 'Unit']]]]);

        $ctx = $this->retrieve(new QueryUnderstandingResult(
            entityTypes: ['Unit'],
            isPossessive: true,
            possessiveWords: ['my'],
            possessiveScope: QueryUnderstandingResult::SCOPE_PERSONAL,
        ), $this->noisySelection());

        $this->assertSame(['User', 'Team'], $ctx->selectionInfo['identity_entities']);
        $this->assertSame('ownership', $ctx->selectionInfo['selection_tiers']['Signature']);
    }

    public function test_scope_labels_seed_the_selection(): void
    {
        $scopes = ['open' => new SelectedScope(0.6, 'App\\Models\\Invoice', 'open invoices')];

        $ctx = $this->retrieve(new QueryUnderstandingResult(entityTypes: ['Unit']), $this->noisySelection($scopes));

        $this->assertContains('Invoice', $ctx->selectionInfo['selected_entities']);
        $this->assertSame('scope', $ctx->selectionInfo['selection_tiers']['Invoice']);
    }

    public function test_vector_hits_are_the_fallback_when_nothing_seeded_the_selection(): void
    {
        $ctx = $this->retrieve(new QueryUnderstandingResult(), $this->noisySelection());

        // Direct hits only (the expansion is not a vector hit), in score order.
        $this->assertSame(['Tax', 'Signature'], $ctx->selectionInfo['selected_entities']);
    }

    public function test_the_label_cap_is_configurable(): void
    {
        config(['ai.semantic_context.max_labels' => 1]);

        $ctx = $this->retrieve(new QueryUnderstandingResult(entityTypes: ['Unit', 'Invoice']), $this->noisySelection());

        // A label is only kept together with its tenant hop (Unit needs Union): Unit and
        // its hop do not fit a cap of 1, so the next seed that fits alone is kept.
        $this->assertSame(['Invoice'], $ctx->selectionInfo['selected_entities']);
        $this->assertContains('Unit', $ctx->selectionInfo['pruned_by_cap']);
    }

    public function test_pruning_can_be_disabled(): void
    {
        config(['ai.semantic_context.pruning' => false]);

        $ctx = $this->retrieve(new QueryUnderstandingResult(entityTypes: ['Unit']), $this->noisySelection());

        // Legacy union: vector hits + QU + forced identity labels.
        foreach (['Tax', 'Signature', 'Unit', 'User', 'Team'] as $label) {
            $this->assertContains($label, $ctx->graphSchema['labels']);
        }
        $this->assertArrayNotHasKey('pruned', $ctx->selectionInfo);
    }

    public function test_the_calculation_the_question_asks_for_seeds_its_labels(): void
    {
        config(['ai.calculations' => [
            'receivables' => [
                'label' => 'Receivables outstanding',
                'cypher' => 'MATCH (i:Invoice) WHERE i.due_amount >= 0.01 RETURN sum(i.due_amount)',
            ],
        ]]);

        $ctx = $this->retrieve(new QueryUnderstandingResult(entityTypes: ['Unit']), $this->noisySelection());

        $this->assertContains('Invoice', $ctx->selectionInfo['selected_entities']);
        $this->assertSame('calculation', $ctx->selectionInfo['selection_tiers']['Invoice']);
        $this->assertSame(['receivables'], array_keys($ctx->selectionInfo['calculations']['matched']));
    }

    public function test_calculation_matching_can_be_disabled(): void
    {
        config(['ai.calculation_matching.enabled' => false]);
        config(['ai.calculations' => [
            'receivables' => ['label' => 'Receivables', 'cypher' => 'MATCH (i:Invoice) RETURN count(i)'],
        ]]);

        $ctx = $this->retrieve(new QueryUnderstandingResult(entityTypes: ['Unit']), $this->noisySelection());

        $this->assertNotContains('Invoice', $ctx->selectionInfo['selected_entities']);
        $this->assertArrayNotHasKey('calculations', $ctx->selectionInfo);
    }

    public function test_a_failed_vector_selection_still_prunes_from_the_qu_seeds(): void
    {
        // Live 2026-09-30 (store under load): the selector threw, the turn fell back to
        // the legacy label search and shipped 17 labels. QU's entity types are known.
        $this->selector->shouldReceive('selectRelevantContext')->andThrow(new \RuntimeException('Qdrant timeout'));
        $qus = Mockery::mock(QueryUnderstandingService::class);
        $qus->shouldReceive('analyze')->andReturn(new QueryUnderstandingResult(entityTypes: ['Unit', 'Invoice']));
        $this->service->setQueryUnderstanding($qus);

        $ctx = $this->service->retrieveContext('What does unit 310 owe?');

        $this->assertSame(['Unit', 'Invoice', 'Union'], $ctx->selectionInfo['selected_entities']);
        $this->assertSame('seed_only', $ctx->selectionInfo['method']);
        $this->assertEqualsCanonicalizing(['Unit', 'Invoice', 'Union'], $ctx->graphSchema['labels']);
        $this->assertNotEmpty($ctx->errors, 'the selector failure is still reported');
    }

    public function test_a_matched_calculation_keeps_only_the_concepts_on_its_entities(): void
    {
        // Live q4 ("outstanding receivables"): QU typed it Account, and three Account
        // "receivable accounts" concepts ("USE THIS EXACT PATTERN") out-voted the
        // matched receivables formula over Invoice. The metric the question asks for
        // is the stronger structured signal: concepts off its entities are dropped.
        config(['ai.calculations' => [
            'receivables' => [
                'label' => 'Receivables outstanding',
                'cypher' => 'MATCH (i:Invoice) WHERE i.due_amount >= 0.01 RETURN sum(i.due_amount)',
            ],
        ]]);
        $scopes = [
            'receivable_accounts' => new SelectedScope(0.62, 'App\\Models\\Tax', 'receivable accounts'),
            'open' => new SelectedScope(0.50, 'App\\Models\\Invoice', 'open invoices'),
        ];

        $ctx = $this->retrieve(new QueryUnderstandingResult(entityTypes: ['Unit']), $this->noisySelection($scopes));

        $this->assertSame(['open'], array_keys($ctx->semanticSelection->scopes));
        $this->assertNotContains('Tax', $ctx->selectionInfo['selected_entities']);
        $this->assertContains('Invoice', $ctx->selectionInfo['selected_entities']);
    }

    public function test_an_empty_projection_falls_back_instead_of_shipping_no_schema(): void
    {
        // Defensive (Phase 5C review): if the pruned labels project to nothing (labels
        // unknown to the graph at projection time), never ship an empty schema block.
        $projector = Mockery::mock(\Condoedge\Ai\Services\Retrieval\SchemaProjector::class);
        $projector->shouldReceive('projectLabels')->andReturn([
            'labels' => [], 'relationships' => [], 'relationshipPatterns' => [], 'properties' => [], 'propertyKeys' => [],
        ]);
        $projector->shouldReceive('filterByRelevance')->andReturn([
            'labels' => ['Unit'], 'relationships' => [], 'relationshipPatterns' => [], 'properties' => [], 'propertyKeys' => [],
        ]);

        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('collectionExists')->andReturn(false);
        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('getSchema')->andReturn(['labels' => ['Unit', 'Union'], 'relationshipTypes' => [], 'relationshipPatterns' => [], 'propertyKeys' => []]);
        $graph->shouldReceive('query')->andReturn([]);
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn([0.1, 0.2]);

        $service = new ContextRetriever($vector, $graph, $embedding, new AiLogger(), self::CONFIGS, schemaProjector: $projector);
        $service->setContextSelector($this->selector);
        $this->selector->shouldReceive('selectRelevantContext')->andReturn($this->noisySelection());
        $qus = Mockery::mock(QueryUnderstandingService::class);
        $qus->shouldReceive('analyze')->andReturn(new QueryUnderstandingResult(entityTypes: ['Unit']));
        $service->setQueryUnderstanding($qus);

        $ctx = $service->retrieveContext('What does unit 310 owe?');

        $this->assertSame(['Unit'], $ctx->graphSchema['labels']);
        $this->assertTrue($ctx->selectionInfo['projection_fallback']);
    }

    public function test_the_full_schema_is_the_last_resort_when_both_projections_are_empty(): void
    {
        $empty = ['labels' => [], 'relationships' => [], 'relationshipPatterns' => [], 'properties' => [], 'propertyKeys' => []];
        $projector = Mockery::mock(\Condoedge\Ai\Services\Retrieval\SchemaProjector::class);
        $projector->shouldReceive('projectLabels')->andReturn($empty);
        $projector->shouldReceive('filterByRelevance')->andReturn($empty);

        $vector = Mockery::mock(VectorStoreInterface::class);
        $vector->shouldReceive('collectionExists')->andReturn(false);
        $graph = Mockery::mock(GraphStoreInterface::class);
        $graph->shouldReceive('getSchema')->andReturn(['labels' => ['Unit', 'Union'], 'relationshipTypes' => [], 'relationshipPatterns' => [], 'propertyKeys' => []]);
        $graph->shouldReceive('query')->andReturn([]);
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn([0.1, 0.2]);

        $service = new ContextRetriever($vector, $graph, $embedding, new AiLogger(), self::CONFIGS, schemaProjector: $projector);
        $service->setContextSelector($this->selector);
        $this->selector->shouldReceive('selectRelevantContext')->andReturn($this->noisySelection());
        $qus = Mockery::mock(QueryUnderstandingService::class);
        $qus->shouldReceive('analyze')->andReturn(new QueryUnderstandingResult(entityTypes: ['Unit']));
        $service->setQueryUnderstanding($qus);

        $ctx = $service->retrieveContext('What does unit 310 owe?');

        $this->assertSame(['Unit', 'Union'], $ctx->graphSchema['labels']);
    }
}
