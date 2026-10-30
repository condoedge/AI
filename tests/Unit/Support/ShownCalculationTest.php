<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\Support\ShownCalculation;
use PHPUnit\Framework\TestCase;

class ShownCalculationTest extends TestCase
{
    /** @test */
    public function an_operation_on_the_turns_figures_gives_its_result(): void
    {
        $this->assertSame([2.6], ShownCalculation::results('The result is 1.05 × 2 + 0.5 = 2.60.', [1.05, 0.5]));
    }

    /** @test */
    public function the_answers_own_typography_is_understood(): void
    {
        $this->assertSame([2.6], ShownCalculation::results('Résultat : 1,05 $ x 2 + 0,50 $ = **2,60 $**', [1.05, 0.5]));
        $this->assertSame([2.6], ShownCalculation::results('$1.05 * 2 + $0.50 = $2.60 CAD', [1.05, 0.5]));
        $this->assertSame([2.6], ShownCalculation::results('\\( 1.05 \\times 2 + 0.5 = 2.60 \\)', [1.05, 0.5]));
        $this->assertSame([12000.0], ShownCalculation::results('10 000 $ × 1,20 = 12 000 $', [10000.0, 1.2]));
    }

    /** @test */
    public function precedence_parentheses_and_percentages_are_respected(): void
    {
        $this->assertSame([3.1], ShownCalculation::results('(1.05 + 0.5) × 2 = 3.10', [1.05, 0.5]));
        $this->assertSame([2.6], ShownCalculation::results('0.5 + 1.05 × 2 = 2.60', [1.05, 0.5]));
        $this->assertSame([50.0], ShownCalculation::results('1 000 × 5 % = 50', [1000.0, 5.0]));
        $this->assertSame([2.5], ShownCalculation::results('10 ÷ 4 = 2.50', [10.0, 4.0]));
    }

    /** @test */
    public function a_step_may_use_the_result_of_the_one_before(): void
    {
        $this->assertSame([2.1, 2.6], ShownCalculation::results('1.05 × 2 = 2.10, then 2.10 + 0.5 = 2.60', [1.05, 0.5]));
        $this->assertSame([2.1, 2.6], ShownCalculation::results('1.05 × 2 = 2.10 + 0.5 = 2.60', [1.05, 0.5]));
    }

    /** @test The measured failure this exists for: a model-summed total off by exactly $1,000. */
    public function a_sum_that_does_not_add_up_gives_nothing(): void
    {
        $this->assertSame([], ShownCalculation::results('5,000.00 + 6,493.17 = 10,493.17', [5000.0, 6493.17]));
        $this->assertSame([11493.17], ShownCalculation::results('5,000.00 + 6,493.17 = 11,493.17', [5000.0, 6493.17]));
    }

    /** @test */
    public function an_operand_the_turn_does_not_hold_gives_nothing(): void
    {
        $this->assertSame([], ShownCalculation::results('999.99 × 2 = 1,999.98', [1.05]));
    }

    /** @test A rounded result is the operation's, at the precision the answer states. */
    public function rounding_stops_at_the_stated_precision(): void
    {
        $this->assertSame([3.33], ShownCalculation::results('10 ÷ 3 = 3.33', [10.0, 3.0]));
        $this->assertSame([3.0], ShownCalculation::results('10 ÷ 3 = 3', [10.0, 3.0]));
        $this->assertSame([], ShownCalculation::results('10 ÷ 3 = 3.40', [10.0, 3.0]));
    }

    /** @test */
    public function what_is_not_an_operation_gives_nothing(): void
    {
        $this->assertSame([], ShownCalculation::results('Total = 2.60', [2.6]));
        $this->assertSame([], ShownCalculation::results('10 ÷ 0 = 2', [10.0, 0.0]));
        $this->assertSame([], ShownCalculation::results('(1.05 + 2 = 3.05', [1.05]));
        $this->assertSame([], ShownCalculation::results('No calculation in 1.05 and 2.', [1.05]));
    }

    /** @test */
    public function a_percentage_of_the_turn_may_be_written_as_its_factor(): void
    {
        $this->assertSame([105.0], ShownCalculation::results('100 × 1,05 = 105', [100.0, 5.0]));
        $this->assertSame([95.0], ShownCalculation::results('100 × 0.95 = 95', [100.0, 5.0]));
        $this->assertSame([5.0], ShownCalculation::results('100 × 0.05 = 5', [100.0, 5.0]));
    }

    /** @test The measured slip: 11,996.81 × 1.05 stated as 12,586.65. */
    public function a_result_that_slipped_is_corrected_to_what_the_operation_gives(): void
    {
        $corrections = ShownCalculation::corrections('11 996,81 $ x 1,05 = 12 586,65 $', [11996.81, 5.0]);

        $this->assertEqualsWithDelta(12596.65, $corrections['12 586,65'], 0.01);
        $this->assertSame([], ShownCalculation::corrections('1.05 × 2 + 0.5 = 2.60', [1.05, 0.5]));
    }

    /** @test */
    public function a_correction_carries_to_the_next_step(): void
    {
        $corrections = ShownCalculation::corrections('1.05 × 2 = 2.20, then 2.20 + 0.5 = 2.70', [1.05, 0.5]);

        $this->assertEqualsWithDelta(2.10, $corrections['2.20'], 0.001);
        $this->assertEqualsWithDelta(2.60, $corrections['2.70'], 0.001);
    }

    /** @test Text that merely looks like an operation is never rewritten. */
    public function a_result_far_from_the_operation_is_not_its_result(): void
    {
        $this->assertSame([], ShownCalculation::corrections('2021-3 = 15 bills', [2021.0, 3.0]));
        $this->assertSame([], ShownCalculation::results('2021-3 = 15 bills', [2021.0, 3.0]));
    }

    /** @test */
    public function the_text_through_its_last_operation(): void
    {
        $lead = "The amount is 1.05. Doubled:\n\n1.05 x 2 + 0.5 = 2.60 CAD.\n\nHere is the bill:";

        $this->assertSame("The amount is 1.05. Doubled:\n\n1.05 x 2 + 0.5 = 2.60 CAD.", ShownCalculation::through($lead));
        $this->assertNull(ShownCalculation::through('The amount is 1.05. Here is the bill:'));
    }

    /** @test Measured: correct math written the natural way was rejected. */
    public function an_operation_may_be_written_the_way_people_write_it(): void
    {
        $this->assertSame([158393.69], ShownCalculation::results("57,015.71 (Fonds d'exploitation) + 101,377.98 (Fonds de prévoyance) = 158,393.69", [57015.71, 101377.98]));
        $this->assertSame([36.0], ShownCalculation::results('(57,015.71 / 158,393.80) * 100 = 36.0 %', [57015.71, 158393.80]));
        $this->assertContains(920.0, ShownCalculation::results('$800 + ($800 * 0.15) = $800 + $120 = $920', [800.0, 15.0]));
        $this->assertContains(2.6, ShownCalculation::results('1.05 * 2 + 0.5 = 2.10 + 0.50 = 2.60 CAD', [1.05, 0.5]));
    }

    /** @test */
    public function an_expansion_that_disagrees_with_its_operation_stands_for_nothing(): void
    {
        $this->assertNotContains(777.0, ShownCalculation::results('$800 + $120 = $700 + $77 = $777', [800.0, 120.0]));
    }

    /** @test */
    public function a_factor_glued_to_its_sign_is_understood(): void
    {
        $this->assertSame([2.6], ShownCalculation::results('1.05 x2 + 0.5 = 2.60', [1.05, 0.5]));
    }

    /** @test */
    public function small_factors_are_free_but_a_decimal_is_not(): void
    {
        $this->assertSame([12.6], ShownCalculation::results('1.05 × 12 = 12.60', [1.05]));
        $this->assertSame([], ShownCalculation::results('1.05 × 1.5 = 1.58', [1.05]));
    }
}
