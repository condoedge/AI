<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Services\Retrieval\ReferenceSlots;
use PHPUnit\Framework\TestCase;

/**
 * ReferenceSlots: when the passages of a turn come from documents of two KINDS —
 * a general document (a project file) and a record's own (an uploaded file) —
 * the reference budget keeps at least one passage of each. Measured live: on
 * "the seller has not paid — do I, the buyer, pay?" the five slots all went to
 * the building's declaration and the general law's article on that very point
 * never reached the answer.
 *
 * Structural: the kind of a passage's document, its rank, nothing else.
 */
class ReferenceSlotsTest extends TestCase
{
    /** @test */
    public function a_kind_the_budget_left_out_takes_the_last_slot_of_the_kind_that_has_several(): void
    {
        // Ranked: five passages of the record's own document, then the law's best.
        $order = [0, 1, 2, 3, 4, 5, 6];
        $kinds = [0 => 'own', 1 => 'own', 2 => 'own', 3 => 'own', 4 => 'own', 5 => 'general', 6 => 'general'];

        $top = ReferenceSlots::reserveOnePerKind(array_slice($order, 0, 5), $order, $kinds);

        $this->assertSame([0, 1, 2, 3, 5], $top, 'the best-ranked general passage, in place of the lowest-ranked own one');
    }

    /** @test */
    public function it_works_the_other_way_round(): void
    {
        $order = [0, 1, 2, 3];
        $kinds = [0 => 'general', 1 => 'general', 2 => 'general', 3 => 'own'];

        $this->assertSame([0, 1, 3], ReferenceSlots::reserveOnePerKind([0, 1, 2], $order, $kinds));
    }

    /** @test */
    public function nothing_changes_when_every_kind_already_has_a_slot_or_there_is_one_kind_only(): void
    {
        $order = [0, 1, 2, 3];

        $this->assertSame([0, 1], ReferenceSlots::reserveOnePerKind([0, 1], $order, [0 => 'own', 1 => 'general', 2 => 'own', 3 => 'general']));
        $this->assertSame([0, 1], ReferenceSlots::reserveOnePerKind([0, 1], $order, [0 => 'own', 1 => 'own', 2 => 'own', 3 => 'own']));
        $this->assertSame([0, 1], ReferenceSlots::reserveOnePerKind([0, 1], $order, []), 'passages that say nothing about their document');
    }

    /** @test */
    public function a_kind_never_loses_its_only_slot_and_a_protected_passage_is_never_the_one_given_up(): void
    {
        // One slot in all: nothing can be reserved.
        $this->assertSame([0], ReferenceSlots::reserveOnePerKind([0], [0, 1], [0 => 'own', 1 => 'general']));

        // The last own passage is protected (an article the user named): the one before it goes.
        $order = [0, 1, 2, 3];
        $kinds = [0 => 'own', 1 => 'own', 2 => 'own', 3 => 'general'];
        $this->assertSame([0, 2, 3], ReferenceSlots::reserveOnePerKind([0, 1, 2], $order, $kinds, [2 => true]));

        // Every candidate to give up is protected: nothing changes.
        $this->assertSame([0, 1, 2], ReferenceSlots::reserveOnePerKind([0, 1, 2], $order, $kinds, [0 => true, 1 => true, 2 => true]));
    }

    /** @test */
    public function a_passage_of_no_known_kind_is_neither_reserved_for_nor_given_up(): void
    {
        $order = [0, 1, 2, 3];
        $kinds = [0 => 'own', 1 => null, 2 => 'own', 3 => 'general'];

        $this->assertSame([0, 1, 3], ReferenceSlots::reserveOnePerKind([0, 1, 2], $order, $kinds));
    }

    /** @test */
    public function the_kind_of_a_passage_is_read_from_whose_document_it_is(): void
    {
        $this->assertSame('general', ReferenceSlots::kindOf(['source' => 'physical']));
        $this->assertSame('own', ReferenceSlots::kindOf(['source' => 'database', 'owner' => 'unit:873']));
        $this->assertSame('own', ReferenceSlots::kindOf(['source' => 'database', 'owner' => null]), 'the organisation\'s own document');
        $this->assertNull(ReferenceSlots::kindOf(['source' => 'database']), 'a legacy passage says nothing');
    }
}
