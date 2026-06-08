<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\Services\ContextRetriever;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Tests for ContextRetriever::computeRetrievalConfidence() — a pure,
 * additive read of the top-k score distribution intended to feed a future
 * rephrase loop. It must not alter retrieval behavior.
 */
class ContextRetrieverConfidenceTest extends TestCase
{
    private ContextRetriever $retriever;

    public function setUp(): void
    {
        parent::setUp();

        $this->retriever = new ContextRetriever(
            Mockery::mock(VectorStoreInterface::class),
            Mockery::mock(GraphStoreInterface::class),
            Mockery::mock(EmbeddingProviderInterface::class),
            Mockery::mock(AiLogger::class), // not used by computeRetrievalConfidence
            [] // empty entity configs
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function empty_distribution_yields_zero_confidence(): void
    {
        $c = $this->retriever->computeRetrievalConfidence([]);

        $this->assertEquals(0.0, $c['overall']);
        $this->assertEquals(0, $c['considered']);
        $this->assertEquals(0.0, $c['top_score']);
    }

    /** @test */
    public function strong_clearly_separated_top_match_is_high_confidence(): void
    {
        // A clear leader (0.95) well above the rest.
        $c = $this->retriever->computeRetrievalConfidence([0.95, 0.55, 0.50, 0.45, 0.40]);

        $this->assertEqualsWithDelta(0.95, $c['top_score'], 0.0001);
        $this->assertGreaterThan(0.0, $c['margin']);
        $this->assertGreaterThanOrEqual(0.5, $c['overall']);
        $this->assertLessThanOrEqual(1.0, $c['overall']);
    }

    /** @test */
    public function flat_low_distribution_is_low_confidence(): void
    {
        // Everything weak and tied -> ambiguous, low confidence, ~no margin.
        $c = $this->retriever->computeRetrievalConfidence([0.20, 0.20, 0.19, 0.19, 0.18]);

        $this->assertLessThan(0.3, $c['overall']);
        $this->assertEqualsWithDelta(0.0, $c['margin'], 0.05);
    }

    /** @test */
    public function it_considers_only_top_k_scores(): void
    {
        // 10 scores but k=3 should consider only the three highest.
        $scores = [0.9, 0.8, 0.7, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1];

        $c = $this->retriever->computeRetrievalConfidence($scores, 3);

        $this->assertEquals(3, $c['considered']);
        $this->assertEqualsWithDelta(0.9, $c['top_score'], 0.0001);
        // strength = mean(0.9, 0.8, 0.7) = 0.8
        $this->assertEqualsWithDelta(0.8, $c['strength'], 0.0001);
    }

    /** @test */
    public function it_clamps_overall_into_unit_interval(): void
    {
        $c = $this->retriever->computeRetrievalConfidence([1.0, 1.0, 0.0]);

        $this->assertGreaterThanOrEqual(0.0, $c['overall']);
        $this->assertLessThanOrEqual(1.0, $c['overall']);
    }
}
