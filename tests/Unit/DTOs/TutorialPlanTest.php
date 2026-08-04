<?php

namespace Condoedge\Ai\Tests\Unit\DTOs;

use Condoedge\Ai\DTOs\TutorialPlan;
use Condoedge\Ai\DTOs\TutorialStep;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the TutorialPlan / TutorialStep DTOs.
 *
 * These are the structured carrier the AiTutorialComposer emits. Their toArray()
 * shape MUST match what window.TutorialEngine.start(plan) consumes — the SAME
 * { title, steps:[{ element, intro, showIf }] } shape Condoedge\Utils\Tutorials\
 * TutorialProcessor::process() returns for static tutorials, so the existing
 * front-end engine renders an AI plan unchanged. No container / side effects, so
 * this runs as a plain PHPUnit TestCase.
 */
class TutorialPlanTest extends TestCase
{
    public function test_step_carries_element_intro_and_showif(): void
    {
        $step = new TutorialStep(
            intro: 'Click <strong>Record payment</strong>.',
            element: '#record-payment-btn',
            showIf: 'user.can_pay',
        );

        $this->assertSame('Click <strong>Record payment</strong>.', $step->intro);
        $this->assertSame('#record-payment-btn', $step->element);
        $this->assertSame('user.can_pay', $step->showIf);
    }

    public function test_step_to_array_omits_null_element_and_showif(): void
    {
        // A floating step (no element to highlight) and no condition must NOT emit
        // null keys — the engine treats a present-but-null `element` differently
        // from an absent one. Absent keys keep the JSON minimal and engine-safe.
        $step = new TutorialStep(intro: 'Welcome to the invoice.');

        $this->assertSame(['intro' => 'Welcome to the invoice.'], $step->toArray());
    }

    public function test_step_to_array_includes_element_and_showif_when_present(): void
    {
        $step = new TutorialStep(
            intro: 'Then press Save.',
            element: '.save',
            showIf: 'invoice.editable',
        );

        $this->assertSame([
            'element' => '.save',
            'intro' => 'Then press Save.',
            'showIf' => 'invoice.editable',
        ], $step->toArray());
    }

    public function test_plan_to_array_matches_tutorial_engine_shape(): void
    {
        $plan = new TutorialPlan(
            title: 'Record a payment',
            steps: [
                new TutorialStep(intro: 'Open the invoice.'),
                new TutorialStep(intro: 'Click pay.', element: '#pay'),
            ],
        );

        $this->assertSame([
            'title' => 'Record a payment',
            'steps' => [
                ['intro' => 'Open the invoice.'],
                ['element' => '#pay', 'intro' => 'Click pay.'],
            ],
        ], $plan->toArray());
    }

    public function test_plan_reports_step_count_and_emptiness(): void
    {
        $empty = new TutorialPlan(title: 'x', steps: []);
        $this->assertSame(0, $empty->stepCount());
        $this->assertTrue($empty->isEmpty());

        $one = new TutorialPlan(title: 'x', steps: [new TutorialStep(intro: 'a')]);
        $this->assertSame(1, $one->stepCount());
        $this->assertFalse($one->isEmpty());
    }

    public function test_skipped_plan_is_empty_and_carries_reason(): void
    {
        $plan = TutorialPlan::skipped('no composer provider / api key');

        $this->assertTrue($plan->skipped);
        $this->assertTrue($plan->isEmpty());
        $this->assertSame('no composer provider / api key', $plan->reason);
        $this->assertSame(0, $plan->stepCount());
    }
}
