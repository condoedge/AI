<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Discovery\CypherQueryBuilderSpy;
use Condoedge\Ai\Tests\TestCase;

/**
 * Tests for CypherQueryBuilderSpy
 *
 * Verifies that the spy correctly records Eloquent query builder calls
 */
class CypherQueryBuilderSpyTest extends TestCase
{
    private CypherQueryBuilderSpy $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = new CypherQueryBuilderSpy();
    }

    /** @test */
    public function it_records_simple_where_clause()
    {
        $this->spy->where('status', 'active');

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertEquals('where', $calls[0]['method']);
        $this->assertEquals('basic', $calls[0]['type']);
        $this->assertEquals('status', $calls[0]['column']);
        $this->assertEquals('=', $calls[0]['operator']);
        $this->assertEquals('active', $calls[0]['value']);
        $this->assertEquals('and', $calls[0]['boolean']);
    }

    /** @test */
    public function it_records_where_clause_with_operator()
    {
        $this->spy->where('total', '>', 100);

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertEquals('total', $calls[0]['column']);
        $this->assertEquals('>', $calls[0]['operator']);
        $this->assertEquals(100, $calls[0]['value']);
    }

    /** @test */
    public function it_records_or_where_clause()
    {
        $this->spy->where('status', 'active')
                  ->orWhere('status', 'pending');

        $calls = $this->spy->getCalls();

        $this->assertCount(2, $calls);
        $this->assertEquals('and', $calls[0]['boolean']);
        $this->assertEquals('or', $calls[1]['boolean']);
    }

    /** @test */
    public function it_records_where_in_clause()
    {
        $this->spy->whereIn('status', ['active', 'pending', 'completed']);

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertEquals('whereIn', $calls[0]['method']);
        $this->assertEquals('in', $calls[0]['type']);
        $this->assertEquals('status', $calls[0]['column']);
        $this->assertEquals(['active', 'pending', 'completed'], $calls[0]['values']);
        $this->assertFalse($calls[0]['not']);
    }

    /** @test */
    public function it_records_where_not_in_clause()
    {
        $this->spy->whereNotIn('status', ['cancelled', 'failed']);

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertEquals('whereIn', $calls[0]['method']);
        $this->assertTrue($calls[0]['not']);
    }

    /** @test */
    public function it_records_where_null_clause()
    {
        $this->spy->whereNull('deleted_at');

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertEquals('whereNull', $calls[0]['method']);
        $this->assertEquals('null', $calls[0]['type']);
        $this->assertEquals('deleted_at', $calls[0]['column']);
        $this->assertFalse($calls[0]['not']);
    }

    /** @test */
    public function it_records_where_not_null_clause()
    {
        $this->spy->whereNotNull('email');

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertEquals('whereNull', $calls[0]['method']);
        $this->assertTrue($calls[0]['not']);
    }

    /** @test */
    public function it_records_where_has_clause()
    {
        $this->spy->whereHas('orders');

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertEquals('whereHas', $calls[0]['method']);
        $this->assertEquals('relationship', $calls[0]['type']);
        $this->assertEquals('orders', $calls[0]['relation']);
        $this->assertEquals('>=', $calls[0]['operator']);
        $this->assertEquals(1, $calls[0]['count']);
    }

    /** @test */
    public function it_records_where_has_clause_with_nested_conditions()
    {
        $this->spy->whereHas('orders', function($q) {
            $q->where('status', 'completed');
        });

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertEquals('whereHas', $calls[0]['method']);
        $this->assertNotEmpty($calls[0]['nested_calls']);

        $nestedCalls = $calls[0]['nested_calls'];
        $this->assertCount(1, $nestedCalls);
        $this->assertEquals('where', $nestedCalls[0]['method']);
        $this->assertEquals('status', $nestedCalls[0]['column']);
        $this->assertEquals('completed', $nestedCalls[0]['value']);
    }

    /** @test */
    public function it_records_where_doesnt_have_clause()
    {
        $this->spy->whereDoesntHave('orders');

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertEquals('whereHas', $calls[0]['method']);
        $this->assertEquals('<', $calls[0]['operator']);
        $this->assertEquals(1, $calls[0]['count']);
    }

    /** @test */
    public function it_records_where_date_clause()
    {
        $this->spy->whereDate('created_at', '>=', '2024-01-01');

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertEquals('whereDate', $calls[0]['method']);
        $this->assertEquals('date', $calls[0]['type']);
        $this->assertEquals('created_at', $calls[0]['column']);
        $this->assertEquals('>=', $calls[0]['operator']);
        $this->assertEquals('2024-01-01', $calls[0]['value']);
    }

    /** @test */
    public function it_records_where_time_clause()
    {
        $this->spy->whereTime('created_at', '>', '12:00:00');

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertEquals('whereTime', $calls[0]['method']);
        $this->assertEquals('time', $calls[0]['type']);
    }

    /** @test */
    public function it_records_where_between_clause()
    {
        $this->spy->whereBetween('total', [100, 500]);

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertEquals('whereBetween', $calls[0]['method']);
        $this->assertEquals('between', $calls[0]['type']);
        $this->assertEquals('total', $calls[0]['column']);
        $this->assertEquals([100, 500], $calls[0]['values']);
        $this->assertFalse($calls[0]['not']);
    }

    /** @test */
    public function it_records_where_not_between_clause()
    {
        $this->spy->whereNotBetween('total', [100, 500]);

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertTrue($calls[0]['not']);
    }

    /** @test */
    public function it_records_where_column_clause()
    {
        $this->spy->whereColumn('first_name', 'last_name');

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertEquals('whereColumn', $calls[0]['method']);
        $this->assertEquals('column', $calls[0]['type']);
        $this->assertEquals('first_name', $calls[0]['first']);
        $this->assertEquals('=', $calls[0]['operator']);
        $this->assertEquals('last_name', $calls[0]['second']);
    }

    /** @test */
    public function it_records_multiple_chained_calls()
    {
        $this->spy->where('status', 'active')
                  ->where('total', '>', 100)
                  ->whereIn('country', ['US', 'CA']);

        $calls = $this->spy->getCalls();

        $this->assertCount(3, $calls);
    }

    /** @test */
    public function it_records_nested_where_clauses()
    {
        $this->spy->where(function($q) {
            $q->where('status', 'active')
              ->orWhere('status', 'pending');
        });

        $calls = $this->spy->getCalls();

        $this->assertCount(1, $calls);
        $this->assertEquals('nested', $calls[0]['type']);
        $this->assertNotEmpty($calls[0]['nested_calls']);

        $nestedCalls = $calls[0]['nested_calls'];
        $this->assertCount(2, $nestedCalls);
    }

    /** @test */
    public function it_can_check_if_calls_were_recorded()
    {
        $this->assertFalse($this->spy->hasCalls());

        $this->spy->where('status', 'active');

        $this->assertTrue($this->spy->hasCalls());
    }

    /** @test */
    public function it_can_count_recorded_calls()
    {
        $this->assertEquals(0, $this->spy->countCalls());

        $this->spy->where('status', 'active')
                  ->where('total', '>', 100);

        $this->assertEquals(2, $this->spy->countCalls());
    }

    /** @test */
    public function it_can_clear_recorded_calls()
    {
        $this->spy->where('status', 'active');
        $this->assertCount(1, $this->spy->getCalls());

        $this->spy->clearCalls();

        $this->assertCount(0, $this->spy->getCalls());
        $this->assertFalse($this->spy->hasCalls());
    }

    /** @test */
    public function it_can_store_model_class_context()
    {
        $spy = new CypherQueryBuilderSpy('App\\Models\\Customer');

        $this->assertEquals('App\\Models\\Customer', $spy->getModelClass());
    }

    /** @test */
    public function it_returns_self_for_chaining()
    {
        $result = $this->spy->where('status', 'active');

        $this->assertSame($this->spy, $result);
    }
}
