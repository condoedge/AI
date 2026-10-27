<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Tasks;

use Condoedge\Ai\DTOs\Tasks\TaskDraft;
use Condoedge\Ai\DTOs\Tasks\TaskField;
use Condoedge\Ai\Tests\Fixtures\Tasks\BookRoomTask;
use Orchestra\Testbench\TestCase;

class TaskFieldTest extends TestCase
{
    /** @test */
    public function an_unknown_type_or_risk_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TaskField('x', 'x', 'colour');
    }

    /** @test */
    public function an_unknown_risk_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TaskField('x', 'x', 'text', risk: 'huge');
    }

    /** @test */
    public function outward_is_a_known_risk(): void
    {
        $this->assertSame('outward', (new TaskField('x', 'x', 'text', risk: 'outward'))->risk);
    }

    /** @test */
    public function an_enum_needs_options(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TaskField('x', 'x', 'enum');
    }

    /** @test */
    public function a_callback_must_be_a_class_method_pair_or_a_closure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TaskField('x', 'x', 'text', requiredWhen: 'trim');
    }

    /** @test */
    public function enum_accepts_only_its_options(): void
    {
        $field = new TaskField('room', 'room', 'enum', options: ['small' => 'a', 'large' => 'b']);

        $this->assertTrue($field->isValid('small'));
        $this->assertFalse($field->isValid('huge'));
        $this->assertFalse($field->isValid(true));
        $this->assertFalse($field->isValid(null));
    }

    /** @test */
    public function list_accepts_a_subset_of_its_options_including_none(): void
    {
        $field = new TaskField('pick', 'pick', 'list', options: ['dates' => 'a', 'goal' => 'b']);

        $this->assertTrue($field->isValid(['goal']));
        $this->assertTrue($field->isValid(['dates', 'goal']));
        $this->assertTrue($field->isValid([]));
        $this->assertFalse($field->isValid(['goal', 'goal']));
        $this->assertFalse($field->isValid(['rate']));
        $this->assertFalse($field->isValid('goal'));
        $this->assertFalse($field->isValid(['x' => 'goal']));
    }

    /** @test */
    public function bool_accepts_booleans_and_zero_one(): void
    {
        $field = new TaskField('on', 'on', 'bool');

        $this->assertTrue($field->isValid(true));
        $this->assertTrue($field->isValid(false));
        $this->assertTrue($field->isValid(0));
        $this->assertTrue($field->isValid(1));
        $this->assertFalse($field->isValid(2));
        $this->assertFalse($field->isValid('yes'));
    }

    /** @test */
    public function int_accepts_integers_within_its_rules(): void
    {
        $field = new TaskField('n', 'n', 'int', rules: ['min:1', 'max:20']);

        $this->assertTrue($field->isValid(12));
        $this->assertFalse($field->isValid(21));
        $this->assertFalse($field->isValid(0));
        $this->assertFalse($field->isValid(1.5));
        $this->assertFalse($field->isValid('12'));
    }

    /** @test */
    public function money_accepts_non_negative_amounts_with_two_decimals_at_most(): void
    {
        $field = new TaskField('m', 'm', 'money');

        $this->assertTrue($field->isValid(250));
        $this->assertTrue($field->isValid(19.99));
        $this->assertFalse($field->isValid(19.999));
        $this->assertFalse($field->isValid(-5));
        $this->assertFalse($field->isValid('20'));
    }

    /** @test */
    public function percent_accepts_zero_to_one_hundred_and_its_rules(): void
    {
        $field = new TaskField('p', 'p', 'percent', rules: ['numeric', 'max:50']);

        $this->assertTrue($field->isValid(0));
        $this->assertTrue($field->isValid(12.5));
        $this->assertFalse($field->isValid(60));
        $this->assertFalse($field->isValid(-1));
        $this->assertFalse($field->isValid(101));
        $this->assertFalse((new TaskField('p', 'p', 'percent'))->isValid(101));
    }

    /** @test */
    public function date_accepts_a_real_iso_day(): void
    {
        $field = new TaskField('d', 'd', 'date', rules: ['after_or_equal:2026-01-01']);

        $this->assertTrue($field->isValid('2026-03-03'));
        $this->assertFalse($field->isValid('2026-02-30'));
        $this->assertFalse($field->isValid('03/03/2026'));
        $this->assertFalse($field->isValid('2025-12-31'));
    }

    /** @test */
    public function text_accepts_a_non_blank_string_within_its_rules(): void
    {
        $field = new TaskField('t', 't', 'text', rules: ['max:10']);

        $this->assertTrue($field->isValid('Board'));
        $this->assertFalse($field->isValid('   '));
        $this->assertFalse($field->isValid('far too long a title'));
        $this->assertFalse($field->isValid(5));
    }

    /** @test */
    public function the_condition_and_default_callbacks_read_the_draft(): void
    {
        $budget = (new BookRoomTask())->fields(null, new TaskDraft())[4];
        $title = (new BookRoomTask())->fields(null, new TaskDraft())[6];

        $this->assertFalse($budget->applies(new TaskDraft(['catering' => 0]), null));
        $this->assertTrue($budget->applies(new TaskDraft(['catering' => 1]), null));
        $this->assertTrue($title->applies(new TaskDraft(), null));
        $this->assertSame('Meeting in the large room', $title->defaultFor(new TaskDraft(['room' => 'large']), null));
        $this->assertNull($budget->defaultFor(new TaskDraft(), null));
    }

    /** @test */
    public function a_closure_condition_is_accepted(): void
    {
        $field = new TaskField('x', 'x', 'text', requiredWhen: fn (TaskDraft $draft) => $draft->has('y'));

        $this->assertTrue($field->applies(new TaskDraft(['y' => 1]), null));
        $this->assertFalse($field->applies(new TaskDraft(), null));
    }

    /** @test */
    public function a_field_holds_no_personal_data_unless_flagged(): void
    {
        $this->assertSame(0, (new TaskField('x', 'x', 'text'))->pii);
        $this->assertSame(1, (new TaskField('email', 'x', 'text', pii: 1))->pii);
    }
}
