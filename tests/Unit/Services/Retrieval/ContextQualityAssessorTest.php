<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Retrieval\ContextQualityAssessor;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;

class ContextQualityAssessorTest extends TestCase
{
    private ContextQualityAssessor $assessor;

    public function setUp(): void
    {
        parent::setUp();
        $this->assessor = new ContextQualityAssessor(new AiLogger());
    }

    /** @test */
    public function high_when_all_four_signals_present(): void
    {
        $ctx = new PipelineContext();
        $ctx->similarQueries = [['question' => 'q', 'query' => 'c', 'score' => 0.9]];
        $ctx->graphSchema = ['labels' => ['Team']];
        $ctx->entityMetadata = ['detected_entities' => ['Team']];
        $ctx->errors = [];

        $quality = $this->assessor->assess($ctx);

        $this->assertSame('high', $quality['level']);
        $this->assertSame(4, $quality['score']);
        $this->assertSame(4, $quality['max']);
    }

    /** @test */
    public function medium_when_two_signals_present(): void
    {
        $ctx = new PipelineContext();
        $ctx->graphSchema = ['labels' => ['Team']];
        $ctx->entityMetadata = ['detected_entities' => ['Team']];
        $ctx->errors = ['boom'];

        $quality = $this->assessor->assess($ctx);

        $this->assertSame('medium', $quality['level']);
        $this->assertSame(2, $quality['score']);
    }

    /** @test */
    public function low_when_zero_or_one_signal_present(): void
    {
        $ctx = new PipelineContext();
        $ctx->errors = ['boom'];

        $quality = $this->assessor->assess($ctx);

        $this->assertSame('low', $quality['level']);
        $this->assertLessThanOrEqual(1, $quality['score']);
        $this->assertContains('level', array_keys($quality));
        $this->assertIsArray($quality['details']);
    }
}
