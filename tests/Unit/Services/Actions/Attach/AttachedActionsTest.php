<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Attach;

use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\Attach\AttachedActions;
use PHPUnit\Framework\TestCase;

/**
 * AttachedActions::narratorInstruction() (actions plan B5, eval p9 F5): the narrator is told
 * what is attached — and, when nothing is, never told that anything is "attached below".
 */
class AttachedActionsTest extends TestCase
{
    /** @test No chips and no record refs: the "nothing attached" line, no "attached below" claim. */
    public function nothing_attached_says_so(): void
    {
        $instruction = AttachedActions::none()->narratorInstruction();

        $this->assertSame(AttachedActions::NARRATOR_NONE_LINE, $instruction);
        $this->assertStringNotContainsString(AttachedActions::NARRATOR_LINE, $instruction);
    }

    /** @test With chips: the one line + the chip labels the user already sees. */
    public function chips_are_named_after_the_one_line(): void
    {
        $actions = new AttachedActions([
            ['role' => AttachedActions::ROLE_NEXT, 'invocation' => new ActionInvocation('meetings', null, null, [], 'Meetings')],
            ['role' => AttachedActions::ROLE_NEXT, 'invocation' => new ActionInvocation('new_meeting', null, null, [], 'New meeting')],
        ]);

        $instruction = $actions->narratorInstruction();

        $this->assertStringStartsWith(AttachedActions::NARRATOR_LINE, $instruction);
        // (Inside the sentence, quoted — never a colon-introduced list: AttachedActionsLabelEchoTest.)
        $this->assertStringContainsString('The buttons attached below this answer are "Meetings"; "New meeting" — the user already sees them: never list or repeat them.', $instruction);
        $this->assertStringNotContainsString(AttachedActions::NARRATOR_NONE_LINE, $instruction);
        $this->assertStringNotContainsString(AttachedActions::RECORD_LINE, $instruction);
    }

    /** @test With record refs: the {{record:N}} rule is added. */
    public function record_refs_add_the_record_rule(): void
    {
        $open = new ActionInvocation('view', 'Unit', '159', [], 'Unit 310');
        $actions = new AttachedActions([['role' => AttachedActions::ROLE_RECORD, 'invocation' => $open]], [1 => $open]);

        $instruction = $actions->narratorInstruction();

        $this->assertStringStartsWith(AttachedActions::NARRATOR_LINE, $instruction);
        $this->assertStringContainsString('Unit 310', $instruction);
        $this->assertStringEndsWith(AttachedActions::RECORD_LINE, $instruction);
    }
}
