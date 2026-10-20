<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\Support\EnumLegend;
use PHPUnit\Framework\TestCase;

/**
 * THE structural parse of an enum legend declared in an entity-config property
 * description ("Status (1=Draft, 2=Approved, 10=Voided)") — shared by the schema
 * cards (inline {code=label} legends) and the digest tables (code → label cells).
 */
class EnumLegendTest extends TestCase
{
    public function test_code_label_pairs_are_parsed_in_declaration_order(): void
    {
        $this->assertSame(
            ['-2' => 'Low', '-1' => 'Regular', '1' => 'High'],
            EnumLegend::parse('Priority flag (-2=Low, -1=Regular, 1=High)')
        );
        $this->assertSame(
            ['1' => 'Open', '2' => 'Pending', '3' => 'Processing', '4' => 'Closed'],
            EnumLegend::parse('Task status (1=Open, 2=Pending, 3=Processing, 4=Closed)')
        );
    }

    public function test_a_legend_after_prose_and_a_range_entry_are_handled(): void
    {
        $this->assertSame(
            ['1' => 'None', '2' => 'Sort', '3' => 'Wait', '4' => 'Reply', '6' => 'Custom'],
            EnumLegend::parse('Flag status (1=None, 2=Sort, 3=Wait, 4=Reply, 5-6=Custom)')
        );
        $this->assertSame(
            ['1' => 'Received', '2' => 'Approval Sent', '3' => 'Payment Approved'],
            EnumLegend::parse("Status code stored as a STRING, compare quoted (b.status IN ['3', '5']): 1=Received, 2=Approval Sent, 3=Payment Approved")
        );
    }

    /** Eval p16-B E7: TeamRole.role holds STRING keys ("lead", "manager", "contact"). */
    public function test_string_codes_are_parsed_when_no_integer_legend_is_declared(): void
    {
        $this->assertSame(
            [
                'lead' => 'Lead union manager',
                'manager' => 'Union manager',
                'assistant' => 'Administrative assistant',
                'contact' => 'Unit Owner or Renter',
                'super-admin' => 'Super administrator',
            ],
            EnumLegend::parse('Role key of the member in the team (lead=Lead union manager, manager=Union manager, assistant=Administrative assistant, contact=Unit Owner or Renter, super-admin=Super administrator)')
        );
        $this->assertSame(
            ['open' => 'Open', 'on_hold' => 'On hold (waiting)', 'payé' => 'Paid'],
            EnumLegend::parse('Ticket state: open=Open; on_hold=On hold (waiting); payé=Paid.')
        );
    }

    public function test_an_integer_legend_is_never_widened_by_word_pairs(): void
    {
        $this->assertSame(
            ['1' => 'Unit', '2' => 'Parking'],
            EnumLegend::parse('Kind (1=Unit, 2=Parking). Related flags: shared=Common portion, private=Private portion')
        );
    }

    public function test_prose_that_is_not_a_list_of_pairs_is_no_string_legend(): void
    {
        // One pair; a formula; property paths (`tr.role = …` is a predicate, not a code).
        $this->assertSame([], EnumLegend::parse('State (active=Currently in use)'));
        $this->assertSame([], EnumLegend::parse('Balance still due (due_amount = total_amount - paid_amount)'));
        $this->assertSame([], EnumLegend::parse("Stored lowercase, compare quoted (tr.role = 'manager', u.state = 'active')"));
        $this->assertSame([], EnumLegend::parse('True when a == b, false when a != b'));
    }

    public function test_fewer_than_two_pairs_is_no_legend(): void
    {
        $this->assertSame([], EnumLegend::parse('Status'));
        $this->assertSame([], EnumLegend::parse('Amount (1=one dollar)'));
        $this->assertSame([], EnumLegend::parse(''));
    }
}
