<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Tutorial;

use Condoedge\Ai\Tests\TestCase;

/**
 * The AI tutorial feature is OPT-IN: the package default must ship it OFF so
 * existing chat behaviour is byte-identical until a host turns it on. This boots
 * the package config (Testbench) and asserts the shipped defaults.
 */
class TutorialConfigDefaultTest extends TestCase
{
    /** @test */
    public function tutorials_are_disabled_by_default(): void
    {
        $this->assertFalse(config('ai.chat.tutorials.enabled'));
    }

    /** @test */
    public function tutorials_ship_a_sane_step_cap_and_role(): void
    {
        $this->assertSame(5, config('ai.chat.tutorials.max_steps'));
        $this->assertSame('response_generation', config('ai.chat.tutorials.role'));
    }
}
