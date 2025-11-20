<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Discovery\CypherPatternGenerator;
use Condoedge\Ai\Tests\TestCase;
use InvalidArgumentException;

/**
 * Tests for CypherPatternGenerator
 *
 * Verifies conversion of Eloquent calls to Cypher patterns
 */
class CypherPatternGeneratorTest extends TestCase
{
    private CypherPatternGenerator $generator;

    public function setUp(): void
    {
        parent::setUp();
        $this->generator = new CypherPatternGenerator();
    }

    /** @test */
    public function it_generates_simple_where_pattern()
    {
        $calls = [
            [
                'method' => 'where',
                'type' => 'basic',
                'column' => 'status',
                'operator' => '=',
                'value' => 'active',
                'boolean' => 'and',
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("n.status = 'active'", $pattern);
    }

    /** @test */
    public function it_generates_where_pattern_with_greater_than()
    {
        $calls = [
            [
                'method' => 'where',
                'type' => 'basic',
                'column' => 'total',
                'operator' => '>',
                'value' => 1000,
                'boolean' => 'and',
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("n.total > 1000", $pattern);
    }

    /** @test */
    public function it_generates_where_pattern_with_less_than()
    {
        $calls = [
            [
                'method' => 'where',
                'type' => 'basic',
                'column' => 'age',
                'operator' => '<',
                'value' => 18,
                'boolean' => 'and',
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("n.age < 18", $pattern);
    }

    /** @test */
    public function it_generates_where_pattern_with_not_equal()
    {
        $calls = [
            [
                'method' => 'where',
                'type' => 'basic',
                'column' => 'status',
                'operator' => '!=',
                'value' => 'cancelled',
                'boolean' => 'and',
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("n.status <> 'cancelled'", $pattern);
    }

    /** @test */
    public function it_generates_where_in_pattern()
    {
        $calls = [
            [
                'method' => 'whereIn',
                'type' => 'in',
                'column' => 'status',
                'values' => ['active', 'pending', 'completed'],
                'boolean' => 'and',
                'not' => false,
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("n.status IN ['active', 'pending', 'completed']", $pattern);
    }

    /** @test */
    public function it_generates_where_not_in_pattern()
    {
        $calls = [
            [
                'method' => 'whereIn',
                'type' => 'in',
                'column' => 'status',
                'values' => ['cancelled', 'failed'],
                'boolean' => 'and',
                'not' => true,
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("n.status NOT IN ['cancelled', 'failed']", $pattern);
    }

    /** @test */
    public function it_generates_where_null_pattern()
    {
        $calls = [
            [
                'method' => 'whereNull',
                'type' => 'null',
                'column' => 'deleted_at',
                'boolean' => 'and',
                'not' => false,
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("n.deleted_at IS NULL", $pattern);
    }

    /** @test */
    public function it_generates_where_not_null_pattern()
    {
        $calls = [
            [
                'method' => 'whereNull',
                'type' => 'null',
                'column' => 'email',
                'boolean' => 'and',
                'not' => true,
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("n.email IS NOT NULL", $pattern);
    }

    /** @test */
    public function it_generates_where_date_pattern()
    {
        $calls = [
            [
                'method' => 'whereDate',
                'type' => 'date',
                'column' => 'created_at',
                'operator' => '>=',
                'value' => '2024-01-01',
                'boolean' => 'and',
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("date(n.created_at) >= date('2024-01-01')", $pattern);
    }

    /** @test */
    public function it_generates_where_time_pattern()
    {
        $calls = [
            [
                'method' => 'whereTime',
                'type' => 'time',
                'column' => 'created_at',
                'operator' => '>',
                'value' => '12:00:00',
                'boolean' => 'and',
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("time(n.created_at) > time('12:00:00')", $pattern);
    }

    /** @test */
    public function it_generates_where_between_pattern()
    {
        $calls = [
            [
                'method' => 'whereBetween',
                'type' => 'between',
                'column' => 'total',
                'values' => [100, 500],
                'boolean' => 'and',
                'not' => false,
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("n.total >= 100 AND n.total <= 500", $pattern);
    }

    /** @test */
    public function it_generates_where_not_between_pattern()
    {
        $calls = [
            [
                'method' => 'whereBetween',
                'type' => 'between',
                'column' => 'total',
                'values' => [100, 500],
                'boolean' => 'and',
                'not' => true,
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertStringContainsString('NOT', $pattern);
    }

    /** @test */
    public function it_generates_where_column_pattern()
    {
        $calls = [
            [
                'method' => 'whereColumn',
                'type' => 'column',
                'first' => 'start_date',
                'operator' => '<',
                'second' => 'end_date',
                'boolean' => 'and',
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("n.start_date < n.end_date", $pattern);
    }

    /** @test */
    public function it_generates_where_has_pattern()
    {
        $calls = [
            [
                'method' => 'whereHas',
                'type' => 'relationship',
                'relation' => 'orders',
                'nested_calls' => [],
                'operator' => '>=',
                'count' => 1,
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertStringContainsString('MATCH', $pattern);
        $this->assertStringContainsString('HAS_ORDERS', $pattern);
    }

    /** @test */
    public function it_generates_where_has_pattern_with_nested_conditions()
    {
        $calls = [
            [
                'method' => 'whereHas',
                'type' => 'relationship',
                'relation' => 'orders',
                'nested_calls' => [
                    [
                        'method' => 'where',
                        'type' => 'basic',
                        'column' => 'status',
                        'operator' => '=',
                        'value' => 'completed',
                        'boolean' => 'and',
                    ],
                ],
                'operator' => '>=',
                'count' => 1,
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertStringContainsString('MATCH', $pattern);
        $this->assertStringContainsString('WHERE', $pattern);
        $this->assertStringContainsString("o.status = 'completed'", $pattern);
    }

    /** @test */
    public function it_generates_where_doesnt_have_pattern()
    {
        $calls = [
            [
                'method' => 'whereHas',
                'type' => 'relationship',
                'relation' => 'orders',
                'nested_calls' => [],
                'operator' => '<',
                'count' => 1,
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertStringContainsString('NOT EXISTS', $pattern);
    }

    /** @test */
    public function it_combines_multiple_conditions_with_and()
    {
        $calls = [
            [
                'method' => 'where',
                'type' => 'basic',
                'column' => 'status',
                'operator' => '=',
                'value' => 'active',
                'boolean' => 'and',
            ],
            [
                'method' => 'where',
                'type' => 'basic',
                'column' => 'total',
                'operator' => '>',
                'value' => 100,
                'boolean' => 'and',
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertStringContainsString('AND', $pattern);
        $this->assertStringContainsString("n.status = 'active'", $pattern);
        $this->assertStringContainsString("n.total > 100", $pattern);
    }

    /** @test */
    public function it_combines_conditions_with_or()
    {
        $calls = [
            [
                'method' => 'where',
                'type' => 'basic',
                'column' => 'status',
                'operator' => '=',
                'value' => 'active',
                'boolean' => 'and',
            ],
            [
                'method' => 'where',
                'type' => 'basic',
                'column' => 'status',
                'operator' => '=',
                'value' => 'pending',
                'boolean' => 'or',
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertStringContainsString('OR', $pattern);
    }

    /** @test */
    public function it_handles_boolean_values()
    {
        $calls = [
            [
                'method' => 'where',
                'type' => 'basic',
                'column' => 'is_active',
                'operator' => '=',
                'value' => true,
                'boolean' => 'and',
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("n.is_active = true", $pattern);
    }

    /** @test */
    public function it_handles_null_values()
    {
        $calls = [
            [
                'method' => 'where',
                'type' => 'basic',
                'column' => 'notes',
                'operator' => '=',
                'value' => null,
                'boolean' => 'and',
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("n.notes = null", $pattern);
    }

    /** @test */
    public function it_escapes_string_values_with_quotes()
    {
        $calls = [
            [
                'method' => 'where',
                'type' => 'basic',
                'column' => 'name',
                'operator' => '=',
                'value' => "O'Brien",
                'boolean' => 'and',
            ],
        ];

        $pattern = $this->generator->generate($calls);

        // Should double the single quote
        $this->assertStringContainsString("O''Brien", $pattern);
    }

    /** @test */
    public function it_converts_like_operator_to_contains()
    {
        $calls = [
            [
                'method' => 'where',
                'type' => 'basic',
                'column' => 'name',
                'operator' => 'LIKE',
                'value' => '%John%',
                'boolean' => 'and',
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertStringContainsString('CONTAINS', $pattern);
        $this->assertStringContainsString('John', $pattern);
    }

    /** @test */
    public function it_generates_full_cypher_query_from_structure()
    {
        $structure = [
            'entity' => 'Customer',
            'relationships' => [
                [
                    'type' => 'HAS_ORDER',
                    'target' => 'Order',
                ],
            ],
            'conditions' => [
                [
                    'entity' => 'o',
                    'field' => 'status',
                    'op' => '=',
                    'value' => 'completed',
                ],
            ],
        ];

        $query = $this->generator->generateFullQuery($structure);

        $this->assertStringContainsString('MATCH (n:Customer)', $query);
        $this->assertStringContainsString('HAS_ORDER', $query);
        $this->assertStringContainsString('Order', $query);
        $this->assertStringContainsString('WHERE', $query);
        $this->assertStringContainsString("o.status = 'completed'", $query);
        $this->assertStringContainsString('RETURN DISTINCT n', $query);
    }

    /** @test */
    public function it_throws_exception_for_full_query_without_entity()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity must be specified');

        $this->generator->generateFullQuery([]);
    }

    /** @test */
    public function it_returns_empty_string_for_empty_calls()
    {
        $pattern = $this->generator->generate([]);

        $this->assertEquals('', $pattern);
    }

    /** @test */
    public function it_uses_custom_node_variable()
    {
        $calls = [
            [
                'method' => 'where',
                'type' => 'basic',
                'column' => 'status',
                'operator' => '=',
                'value' => 'active',
                'boolean' => 'and',
            ],
        ];

        $pattern = $this->generator->generate($calls, 'customer');

        $this->assertStringContainsString('customer.status', $pattern);
    }

    /** @test */
    public function it_handles_numeric_values_in_where_in()
    {
        $calls = [
            [
                'method' => 'whereIn',
                'type' => 'in',
                'column' => 'id',
                'values' => [1, 2, 3, 4, 5],
                'boolean' => 'and',
                'not' => false,
            ],
        ];

        $pattern = $this->generator->generate($calls);

        $this->assertEquals("n.id IN [1, 2, 3, 4, 5]", $pattern);
    }
}
