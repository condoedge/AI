<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\DTOs\SemanticSelectionResult;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Services\SemanticContextSelector;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * G2/L5 item B — the retrieval side of the augmentation anchor.
 *
 * When QU flags a follow-up (followUpReference) but produces NO entity types
 * (the exact resilience-t1 failure signature), retrieval must not go blind:
 * the PREVIOUS turn's entity types recorded in the conversation snapshot
 * (last_entity_types, with focused_entity as fallback) are deterministically
 * seeded into semantic selection — the same force-include mechanism QU entity
 * types use, gated on the recorded data anchor (last_cypher_query).
 */
class ContextRetrieverAnchorSeedTest extends TestCase
{
    private ContextRetriever $service;
    private $mockSelector;

    public function setUp(): void
    {
        parent::setUp();

        $mockVector = Mockery::mock(VectorStoreInterface::class);
        $mockVector->shouldReceive('collectionExists')->andReturn(false)->byDefault();
        $mockGraph = Mockery::mock(GraphStoreInterface::class);
        $mockEmbedding = Mockery::mock(EmbeddingProviderInterface::class);
        $mockEmbedding->shouldReceive('embed')->andReturn([0.1, 0.2])->byDefault();

        $this->mockSelector = Mockery::mock(SemanticContextSelector::class);
        $this->mockSelector->shouldReceive('selectRelevantContext')
            ->andReturn(new SemanticSelectionResult())
            ->byDefault();

        $this->service = new ContextRetriever(
            $mockVector,
            $mockGraph,
            $mockEmbedding,
            new AiLogger(),
            [
                'Team' => ['graph' => ['label' => 'Team']],
                'User' => ['graph' => ['label' => 'User']],
            ]
        );
        $this->service->setContextSelector($this->mockSelector);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function withQu(QueryUnderstandingResult $qu): void
    {
        $mockQu = Mockery::mock(QueryUnderstandingService::class);
        $mockQu->shouldReceive('analyze')->andReturn($qu);
        $this->service->setQueryUnderstanding($mockQu);
    }

    private function retrieve(array $conversationContext): \Condoedge\Ai\DTOs\PipelineContext
    {
        return $this->service->retrieveContext('Ok, but could you add their last login to it?', [
            'includeSchema' => false,
            'includeExamples' => false,
            'conversation_context' => $conversationContext,
        ]);
    }

    /** @test */
    public function an_anchored_follow_up_with_empty_qu_entities_is_seeded_from_the_previous_turn(): void
    {
        $this->withQu(new QueryUnderstandingResult(
            entityTypes: [],
            questionType: 'knowledge_query',
            followUpReference: true,
        ));

        $ctx = $this->retrieve([
            'focused_entity' => 'Team',
            'last_entity_types' => ['Team', 'User'],
            'last_cypher_query' => 'MATCH (t:Team)-[:HAS_MEMBER]->(u:User) RETURN u.name',
        ]);

        $this->assertArrayHasKey('Team', $ctx->semanticSelection->entities);
        $this->assertArrayHasKey('User', $ctx->semanticSelection->entities, 'the anchor must carry EVERY previous entity type');
        $this->assertStringContainsString(
            'anchor',
            strtolower($ctx->semanticSelection->entities['User']->matchedText),
            'anchor-seeded entities must be identifiable in the trace'
        );
    }

    /** @test */
    public function without_a_recorded_anchor_no_seeding_happens(): void
    {
        $this->withQu(new QueryUnderstandingResult(
            entityTypes: [],
            questionType: 'knowledge_query',
            followUpReference: true,
        ));

        // No last_cypher_query → no data anchor → nothing seeded beyond the
        // (existing) conversation-focus mechanism.
        $ctx = $this->retrieve([
            'last_entity_types' => ['Team', 'User'],
        ]);

        $this->assertArrayNotHasKey('User', $ctx->semanticSelection->entities);
    }

    /** @test */
    public function a_non_follow_up_turn_is_never_anchor_seeded(): void
    {
        $this->withQu(new QueryUnderstandingResult(
            entityTypes: [],
            questionType: 'data_query',
            followUpReference: false,
        ));

        $ctx = $this->retrieve([
            'last_entity_types' => ['Team', 'User'],
            'last_cypher_query' => 'MATCH (t:Team) RETURN t',
        ]);

        $this->assertArrayNotHasKey('User', $ctx->semanticSelection->entities);
    }
}
