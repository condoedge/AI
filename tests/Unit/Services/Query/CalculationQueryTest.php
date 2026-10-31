<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Query;

use Condoedge\Ai\Exceptions\QueryGenerationException;
use Condoedge\Ai\Services\Query\CalculationQuery;
use Condoedge\Ai\Tests\TestCase;

class CalculationQueryTest extends TestCase
{
    private const SPEND = 'MATCH (a:Account)-[:OF]->(un:Org) WHERE a.group = 4 AND toLower(un.name) CONTAINS toLower("<ORG NAME?>") '
        . 'AND (toLower(a.name) CONTAINS "<TOPIC>" OR toLower(a.type) CONTAINS "<TOPIC>") '
        . 'OPTIONAL MATCH (e:Entry)-[:ACCOUNT]->(a) WHERE left(e.at, 4) = "<YEAR>" RETURN un.name AS org, sum(e.debit) AS spent';

    public function setUp(): void
    {
        parent::setUp();

        config()->set('ai.calculations', [
            'spend' => [
                'cypher' => self::SPEND,
                'variants' => ['per_year' => ['when' => 'asked per year', 'cypher' => 'MATCH (e:Entry) WHERE e.topic = "<TOPIC>" RETURN left(e.at, 4) AS year, sum(e.debit) AS spent']],
            ],
            'one_id' => ['cypher' => 'MATCH (c:Campaign) WHERE c.id = <CAMPAIGN ID> RETURN c.raised AS raised'],
            'only_name' => ['cypher' => 'MATCH (u:Org) WHERE u.name = "<ORG NAME?>" RETURN u.id AS id'],
            'either' => ['cypher' => 'MATCH (u:Org) WHERE u.name = "<ORG NAME?>" OR u.alias = "x" RETURN u.id AS id'],
            'in_a_with' => ['cypher' => 'MATCH (u:Org) WITH u, "<ORG NAME?>" AS wanted RETURN u.id AS id'],
            'no_filter' => ['cypher' => 'MATCH (u:Org) RETURN count(u) AS orgs'],
            'starts' => ['cypher' => 'MATCH (u:Org) WHERE u.name STARTS WITH "a" AND u.code = "<CODE?>" WITH u RETURN u.id AS id'],
        ]);
    }

    /** @test */
    public function values_take_the_place_of_the_placeholders_and_the_rest_is_exactly_what_the_host_wrote(): void
    {
        $query = CalculationQuery::named('spend')->fill(['ORG NAME' => 'Soho', 'TOPIC' => 'neigement', 'YEAR' => '2021']);

        $this->assertSame(strtr(self::SPEND, ['<ORG NAME?>' => 'Soho', '<TOPIC>' => 'neigement', '<YEAR>' => '2021']), $query);
    }

    /** @test */
    public function a_quote_in_a_value_can_never_end_the_string(): void
    {
        $query = CalculationQuery::named('only_name')->fill(['ORG NAME' => 'Chemin d\'oka" OR true //']);

        $this->assertStringContainsString('u.name = "Chemin d\'oka\" OR true //"', $query);
    }

    /** @test */
    public function a_backslash_cannot_escape_the_closing_quote(): void
    {
        $this->assertStringContainsString('u.name = "x\\\\"', CalculationQuery::named('only_name')->fill(['ORG NAME' => 'x\\']));
    }

    /** @test */
    public function a_bare_placeholder_takes_only_a_number(): void
    {
        $this->assertStringContainsString('c.id = 42 RETURN', CalculationQuery::named('one_id')->fill(['CAMPAIGN ID' => 42]));

        foreach (['1 OR true', '1) RETURN 1 //', 'abc', '1e5'] as $value) {
            try {
                CalculationQuery::named('one_id')->fill(['CAMPAIGN ID' => $value]);
                $this->fail("Accepted: {$value}");
            } catch (QueryGenerationException $e) {
                $this->assertStringContainsString('takes a number', $e->getMessage());
            }
        }
    }

    /** @test */
    public function a_required_placeholder_left_out_is_refused_with_what_to_do(): void
    {
        $this->expectException(QueryGenerationException::class);
        $this->expectExceptionMessage('needs a value for <YEAR>');

        CalculationQuery::named('spend')->fill(['TOPIC' => 'neigement']);
    }

    /** @test */
    public function an_optional_placeholder_left_out_takes_its_condition_with_it(): void
    {
        $query = CalculationQuery::named('spend')->fill(['TOPIC' => 'neigement', 'YEAR' => '2021']);

        $this->assertStringNotContainsString('un.name) CONTAINS', $query);
        $this->assertStringContainsString('WHERE a.group = 4 AND (toLower(a.name) CONTAINS "neigement"', $query);
        $this->assertStringContainsString('OPTIONAL MATCH (e:Entry)', $query, 'The next clause is untouched.');
    }

    /** @test */
    public function the_only_condition_of_a_where_takes_the_where_with_it(): void
    {
        $this->assertSame('MATCH (u:Org) RETURN u.id AS id', CalculationQuery::named('only_name')->fill([]));
    }

    /** @test A STARTS WITH is not a clause. */
    public function a_with_inside_a_condition_does_not_end_the_where(): void
    {
        $this->assertSame('MATCH (u:Org) WHERE u.name STARTS WITH "a" WITH u RETURN u.id AS id', CalculationQuery::named('starts')->fill([]));
    }

    /** @test Leaving these out could widen the result or break it: refused, never guessed. */
    public function an_optional_condition_that_cannot_be_proven_safe_to_remove_is_refused(): void
    {
        foreach (['either', 'in_a_with'] as $key) {
            try {
                CalculationQuery::named($key)->fill([]);
                $this->fail("Removed from: {$key}");
            } catch (QueryGenerationException $e) {
                $this->assertStringContainsString('needs a value', $e->getMessage());
            }
        }
    }

    /** @test */
    public function several_values_repeat_the_or_group_holding_the_placeholder(): void
    {
        $query = CalculationQuery::named('spend')->fill(['TOPIC' => ['lectricit', 'hydro'], 'YEAR' => '2021']);

        $this->assertStringContainsString(
            'AND (toLower(a.name) CONTAINS "lectricit" OR toLower(a.type) CONTAINS "lectricit" OR toLower(a.name) CONTAINS "hydro" OR toLower(a.type) CONTAINS "hydro") OPTIONAL',
            $query
        );
    }

    /** @test */
    public function several_values_are_refused_where_there_is_no_group_to_repeat(): void
    {
        $this->expectException(QueryGenerationException::class);
        $this->expectExceptionMessage('takes one value');

        CalculationQuery::named('spend')->fill(['ORG NAME' => ['a', 'b'], 'TOPIC' => 'x', 'YEAR' => '2021']);
    }

    /** @test */
    public function a_variant_is_named_by_its_calculation(): void
    {
        $this->assertStringContainsString('left(e.at, 4) AS year', CalculationQuery::named('spend.per_year')->fill(['TOPIC' => 'neigement']));
    }

    /** @test A value the query has no place for would silently widen the answer. */
    public function a_value_for_a_placeholder_the_query_does_not_have_is_refused(): void
    {
        $this->expectException(QueryGenerationException::class);
        $this->expectExceptionMessage('has no <ORG NAME>');

        CalculationQuery::named('no_filter')->fill(['ORG NAME' => 'Soho']);
    }

    /** @test */
    public function the_values_are_read_however_the_model_spells_the_placeholder(): void
    {
        $this->assertStringContainsString('u.name = "a"', CalculationQuery::named('only_name')->fill(['<org name?>' => 'a']));
    }

    /** @test */
    public function a_calculation_this_prompt_did_not_offer_is_refused(): void
    {
        $this->expectException(QueryGenerationException::class);
        $this->expectExceptionMessage('not one of the calculations');

        CalculationQuery::named('spend', ['only_name']);
    }

    /** @test */
    public function an_unknown_calculation_or_variant_is_refused(): void
    {
        foreach (['nope', 'spend.nope'] as $name) {
            try {
                CalculationQuery::named($name);
                $this->fail("Accepted: {$name}");
            } catch (QueryGenerationException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
