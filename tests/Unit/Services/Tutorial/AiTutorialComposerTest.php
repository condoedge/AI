<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Tutorial;

use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\DTOs\TutorialPlan;
use Condoedge\Ai\Services\Tutorial\AiTutorialComposer;
use PHPUnit\Framework\TestCase;

/**
 * Tests the AI tutorial composer's plan PARSING + safety clamps (offline).
 *
 * parsePlan() is exercised directly with stubbed LLM payloads so no API key or
 * network is required — exactly the JudgeServiceTest idiom. compose() with a null
 * resolver must degrade gracefully to a skipped (empty) plan and never throw, so
 * a failed compose can fall back to a normal text answer.
 */
class AiTutorialComposerTest extends TestCase
{
    private AiTutorialComposer $composer;

    protected function setUp(): void
    {
        parent::setUp();
        // Whitelist drives the `element` filter; pure-logic, no container.
        $this->composer = new AiTutorialComposer(
            llmResolver: null,
            config: ['max_steps' => 4],
            markdown: null,
        );
    }

    /** @test */
    public function it_turns_a_how_do_i_request_plus_page_context_into_a_multi_step_plan(): void
    {
        $page = new PageContext(route: 'invoices.form', entityType: 'Invoice', label: 'Invoice #42');

        $plan = $this->composer->parsePlan(
            [
                'title' => 'Record a payment',
                'steps' => [
                    ['intro' => 'Open the **Payments** tab.', 'element' => '#payments-tab'],
                    ['intro' => 'Click *Record payment*.', 'element' => '#record-payment-btn'],
                    ['intro' => 'Enter the amount and save.', 'element' => '#payment-amount'],
                ],
            ],
            $page,
            ['#payments-tab', '#record-payment-btn', '#payment-amount']
        );

        $this->assertInstanceOf(TutorialPlan::class, $plan);
        $this->assertFalse($plan->skipped);
        $this->assertSame('Record a payment', $plan->title);
        $this->assertSame(3, $plan->stepCount());

        $array = $plan->toArray();
        $this->assertSame('#payments-tab', $array['steps'][0]['element']);
        // intro is markdown-rendered to safe HTML, not raw markdown.
        $this->assertStringContainsString('<strong', $array['steps'][0]['intro']);
        $this->assertStringNotContainsString('**', $array['steps'][0]['intro']);
    }

    /** @test */
    public function it_clamps_the_step_count_to_the_configured_maximum(): void
    {
        $steps = [];
        for ($i = 0; $i < 9; $i++) {
            $steps[] = ['intro' => "step {$i}"];
        }

        $plan = $this->composer->parsePlan(['title' => 'Long', 'steps' => $steps], null, []);

        $this->assertSame(4, $plan->stepCount());
    }

    /** @test */
    public function it_drops_steps_whose_element_is_not_in_the_host_whitelist(): void
    {
        $plan = $this->composer->parsePlan(
            [
                'title' => 'Mixed',
                'steps' => [
                    ['intro' => 'Allowed', 'element' => '.safe'],
                    ['intro' => 'Forbidden DOM', 'element' => 'body script'],
                    ['intro' => 'Floating, no element'],
                ],
            ],
            null,
            ['.safe']
        );

        // The forbidden-selector step has its element nulled (NOT pointed at arbitrary
        // DOM); it survives only as a floating step. The allowed + floating steps stay.
        $array = $plan->toArray();
        $this->assertSame(3, $plan->stepCount());
        $this->assertSame('.safe', $array['steps'][0]['element']);
        $this->assertArrayNotHasKey('element', $array['steps'][1]);
        $this->assertArrayNotHasKey('element', $array['steps'][2]);
    }

    /** @test */
    public function an_empty_whitelist_strips_every_element_to_floating_steps(): void
    {
        $plan = $this->composer->parsePlan(
            ['title' => 't', 'steps' => [['intro' => 'a', 'element' => '#anything']]],
            null,
            []
        );

        $this->assertArrayNotHasKey('element', $plan->toArray()['steps'][0]);
    }

    /** @test */
    public function it_parses_a_json_string_with_code_fences(): void
    {
        $payload = "```json\n" . json_encode([
            'title' => 'Fenced',
            'steps' => [['intro' => 'a']],
        ]) . "\n```";

        $plan = $this->composer->parsePlan($payload, null, []);

        $this->assertFalse($plan->skipped);
        $this->assertSame('Fenced', $plan->title);
        $this->assertSame(1, $plan->stepCount());
    }

    /** @test */
    public function it_drops_steps_with_an_empty_intro(): void
    {
        $plan = $this->composer->parsePlan(
            ['title' => 't', 'steps' => [['intro' => ''], ['intro' => '  '], ['intro' => 'real']]],
            null,
            []
        );

        $this->assertSame(1, $plan->stepCount());
    }

    /** @test */
    public function an_unparseable_response_yields_a_skipped_plan(): void
    {
        $plan = $this->composer->parsePlan('not json at all', null, []);

        $this->assertTrue($plan->skipped);
        $this->assertTrue($plan->isEmpty());
    }

    /** @test */
    public function a_plan_with_no_usable_steps_is_skipped(): void
    {
        $plan = $this->composer->parsePlan(['title' => 't', 'steps' => []], null, []);

        $this->assertTrue($plan->skipped);
    }

    /** @test */
    public function compose_degrades_gracefully_with_no_resolver(): void
    {
        $plan = $this->composer->compose(
            'How do I record a payment?',
            new PageContext(entityType: 'Invoice'),
            ['#pay']
        );

        $this->assertInstanceOf(TutorialPlan::class, $plan);
        $this->assertTrue($plan->skipped);
        $this->assertTrue($plan->isEmpty());
    }

    /** @test */
    public function compose_degrades_gracefully_on_an_empty_request(): void
    {
        $plan = $this->composer->compose('   ', null, ['#pay']);

        $this->assertTrue($plan->skipped);
    }
}
