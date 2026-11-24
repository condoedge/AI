<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Tests\TestCase;

class FigureOutweighsSkillTest extends TestCase
{
    public function test_a_figure_matched_at_least_as_well_as_the_references_drops_the_skill(): void
    {
        $ctx = $this->decide(calculation: 0.71, reference: 0.64);

        $this->assertSame([], $ctx->skill);
    }

    public function test_a_reference_matched_better_than_the_figure_keeps_the_skill(): void
    {
        $ctx = $this->decide(calculation: 0.58, reference: 0.64);

        $this->assertSame('accounting', $ctx->skill['name']);
    }

    public function test_a_calculation_declared_with_skill_keeps_it(): void
    {
        config(['ai.calculations.owed.with_skill' => true]);

        $ctx = $this->decide(calculation: 0.71, reference: 0.64);

        $this->assertSame('accounting', $ctx->skill['name']);
    }

    private function decide(float $calculation, float $reference): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->selectionInfo = ['calculations' => ['matched' => ['owed' => $calculation]]];
        $ctx->skill = [
            'name' => 'accounting',
            'references' => [['id' => 'a', 'similarity' => $reference]],
            'disclaimer' => ['fr' => 'Je ne suis pas comptable.'],
        ];

        $manager = (new \ReflectionClass(AiManager::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AiManager::class, 'letFigureOutweighSkill');
        $method->setAccessible(true);

        return $method->invoke($manager, $ctx, 'owed');
    }
}
