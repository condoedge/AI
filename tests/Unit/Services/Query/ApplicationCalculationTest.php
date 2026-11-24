<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Query;

use Condoedge\Ai\Exceptions\QueryGenerationException;
use Condoedge\Ai\Services\Query\ApplicationCalculation;
use Condoedge\Ai\Tests\TestCase;

class ApplicationCalculationTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config()->set('ai.calculations', [
            'balance' => [
                'resolver' => fn (mixed $user, array $values): array => [['asker' => $user, 'unit' => $values['UNIT NAME'][0] ?? null, 'balance' => 12.5]],
                'values' => ['UNIT NAME?' => 'the unit the question names'],
            ],
            'by_year' => [
                'resolver' => fn (mixed $user, array $values): array => [],
                'values' => ['YEAR' => 'four digits'],
            ],
            'written' => ['cypher' => 'MATCH (u:Org) RETURN count(u) AS orgs'],
        ]);
    }

    /** @test */
    public function only_a_calculation_with_a_resolver_is_computed_by_the_application(): void
    {
        $this->assertTrue(ApplicationCalculation::configured('balance'));
        $this->assertFalse(ApplicationCalculation::configured('written'));
        $this->assertFalse(ApplicationCalculation::configured('unknown'));
    }

    /** @test */
    public function the_resolver_receives_the_asker_and_the_values_and_its_rows_come_back_as_they_are(): void
    {
        $rows = ApplicationCalculation::named('balance', ['<unit_name?>' => ' 101 '])->rows('the asker');

        $this->assertSame([['asker' => 'the asker', 'unit' => '101', 'balance' => 12.5]], $rows);
    }

    /** @test */
    public function an_optional_value_may_be_left_out_and_a_required_one_may_not(): void
    {
        $this->assertSame([], ApplicationCalculation::named('balance', [])->values);

        $this->expectException(QueryGenerationException::class);
        ApplicationCalculation::named('by_year', []);
    }

    /** @test */
    public function a_value_the_calculation_does_not_declare_is_refused(): void
    {
        $this->expectException(QueryGenerationException::class);
        ApplicationCalculation::named('balance', ['OWNER' => 'Tremblay']);
    }

    /** @test */
    public function a_calculation_the_prompt_did_not_offer_is_refused(): void
    {
        $this->expectException(QueryGenerationException::class);
        ApplicationCalculation::named('balance', [], ['by_year']);
    }
}
