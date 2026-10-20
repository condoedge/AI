<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Attach;

use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\Attach\AttachedActions;
use PHPUnit\Framework\TestCase;

/**
 * THE LABEL ECHO (eval p21-B rights t2). The narrator instruction named the attached
 * buttons — "Attached below this answer (the user already sees these buttons; never list or
 * repeat them): Receivables report." — and the model copied the prompt's own label into the
 * answer: "Attached below this answer: Receivables report."
 *
 * Two layers:
 *  - the instruction gives the labels INSIDE a sentence, never as a colon-introduced list
 *    that reads like a sentence of the answer;
 *  - code drops, from the model's narrative, a sentence that is nothing but this turn's
 *    button labels introduced by a colon ({@see AttachedActions::withoutLabelEcho()}).
 *
 * Measured on the stored answers of eval p16–p21 (116 answers with chips, 775 sentences):
 * 68 sentences outside the digest hold a chip label verbatim; 63 are prose with the label
 * inside the sentence ("You can check the receivables report for more details."), 4 are a
 * record's own value line ("Meeting Name: <the record>"), 1 is the echo.
 */
class AttachedActionsLabelEchoTest extends TestCase
{
    private function chip(string $role, string $key, string $text, ?string $entity = null, ?string $id = null): array
    {
        return ['role' => $role, 'invocation' => new ActionInvocation($key, $entity, $id, [], $text)];
    }

    private function actions(): AttachedActions
    {
        return new AttachedActions([
            $this->chip(AttachedActions::ROLE_RECORD, 'view', 'Annual general meeting 2023', 'Meeting', '140'),
            $this->chip(AttachedActions::ROLE_NEXT, 'receivables_report', 'Receivables report'),
            $this->chip(AttachedActions::ROLE_IDENTITY, 'team_settings', 'Team settings'),
            $this->chip(AttachedActions::ROLE_NEXT, 'export_excel', 'Download Excel'),
            $this->chip(AttachedActions::ROLE_FOLLOW_UP, 'ask_follow_up', 'Can I ask for a statement before buying?'),
        ]);
    }

    // ------------------------------------------------------------- the instruction

    /**
     * @test The labels ride INSIDE a sentence of the instruction: no phrase of it, read on
     * its own, is "<a few words>: <label>." — the shape the model copied.
     */
    public function the_instruction_names_the_buttons_inside_a_sentence_not_after_a_colon(): void
    {
        $instruction = $this->actions()->narratorInstruction();

        $this->assertStringStartsWith(AttachedActions::NARRATOR_LINE, $instruction);
        // Every button is still named (the prose may point at one), follow-up chips never.
        foreach (['"Annual general meeting 2023"', '"Receivables report"', '"Team settings"', '"Download Excel"'] as $label) {
            $this->assertStringContainsString($label, $instruction);
        }
        $this->assertStringNotContainsString('statement before buying', $instruction);
        // The prohibition is not weakened.
        $this->assertStringContainsString('the user already sees', $instruction);
        $this->assertStringContainsString('never list or repeat them', $instruction);

        // No label is introduced by a colon…
        $this->assertDoesNotMatchRegularExpression('/:\s*"?(Annual general meeting 2023|Receivables report|Team settings|Download Excel)/u', $instruction);
        // …and nothing in the instruction is itself an echo-shaped sentence, also with its
        // parentheses read away (the former wording: "Attached below this answer (…): X.").
        foreach ([$instruction, (string) preg_replace('/\s*\([^)]*\)/u', '', $instruction)] as $read) {
            $this->assertSame([], $this->actions()->withoutLabelEcho($read)[1]);
        }
    }

    // ------------------------------------------------------------- the guard

    /**
     * @test
     * @dataProvider echoes
     */
    public function a_sentence_that_is_only_the_buttons_labels_after_a_colon_is_dropped(string $narrative, string $kept, string $dropped): void
    {
        $this->assertSame([$kept, [$dropped]], $this->actions()->withoutLabelEcho($narrative));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function echoes(): array
    {
        $answer = 'You have no unit linked to your account, so there are no contributions to show for this year.';

        return [
            'p21-B rights t2 (a paragraph of its own)' => [
                $answer . "\n\nAttached below this answer: Receivables report.",
                $answer,
                'Attached below this answer: Receivables report.',
            ],
            'a sentence at the end of a paragraph' => [
                $answer . ' Attached below this answer: Receivables report.',
                $answer,
                'Attached below this answer: Receivables report.',
            ],
            'several labels' => [
                "Attached: Team settings; Download Excel.\n\n" . $answer,
                $answer,
                'Attached: Team settings; Download Excel.',
            ],
            'emphasis, a list marker, a short connector' => [
                $answer . "\n- **Buttons below:** Team settings and Download Excel",
                $answer,
                '- **Buttons below:** Team settings and Download Excel',
            ],
            'another case, with a record chip beside a button' => [
                $answer . "\n\nAttached below this answer: annual general meeting 2023; RECEIVABLES REPORT.",
                $answer,
                'Attached below this answer: annual general meeting 2023; RECEIVABLES REPORT.',
            ],
            'a lead in front of the code-rendered block' => [
                "Attached below this answer: Download Excel.\n\n{{table}}",
                '{{table}}',
                'Attached below this answer: Download Excel.',
            ],
            'in another language (the words before the colon are not read)' => [
                "Vous n'avez aucune unité liée à votre compte.\n\nJoint sous cette réponse : Receivables report.",
                "Vous n'avez aucune unité liée à votre compte.",
                'Joint sous cette réponse : Receivables report.',
            ],
        ];
    }

    /**
     * @test Prose stays — every shape below is a stored answer's or a variation of one.
     * @dataProvider prose
     */
    public function prose_that_names_a_button_is_kept(string $narrative): void
    {
        $this->assertSame([$narrative, []], $this->actions()->withoutLabelEcho($narrative));
    }

    /** @return array<string, array{0: string}> */
    public static function prose(): array
    {
        return [
            'a pointer sentence' => ['You have no unit linked to your account. You can see them in the Receivables report.'],
            'p21-B resilience t2' => ['For more details, you can access the Team settings.'],
            'p20x-B rights t2' => ['You can check the receivables report for more details.'],
            'the label BEFORE the colon (a step of a how-to)' => ['1. **Open Team Settings**: Click on the user menu in the top-right corner and select **Team Settings**.'],
            'the label opens a statement' => ['The Receivables report shows: 75 overdue invoices across the portfolio.'],
            'more than the labels after the colon' => ['Start here: the Team settings page lists every pending invitation.'],
            'a figure after the label' => ['Attached below this answer: Receivables report for 2026.'],
            'a record\'s own value line (a record chip only)' => ['**Meeting Name:** Annual general meeting 2023'],
            'a real sentence leads to the label' => ['Everything you need to manage the members of your team is in one place: Team settings.'],
            'a table row' => ["| Page: | Team settings |\n| --- | --- |"],
            'no colon at all' => ['Team settings; Download Excel.'],
            'a label inside a longer word' => ['Open: Team settingsX.'],
            'the whole answer (dropping it would leave nothing)' => ['Attached below this answer: Receivables report.'],
        ];
    }

    // ------------------------------------------------- the echo over two lines (p22-B)

    /** The stored answer of eval p22-1001-B app-navigation t0, as the model wrote it. */
    private const P22_APP_NAV = "To add new members to your team, you need to follow these steps in the **Team Settings**:\n\n"
        . "1. Open **Team Settings** from the user menu in the top-right navbar.\n"
        . "2. In the invitations section, enter the new member's **email address** and select their role.\n"
        . "3. Send the invitation. The invitee will receive an email with a signed link to register and join the team.\n"
        . "4. You can view pending invitations on the same page until they are accepted.\n\n"
        . "Please note that adding a team member is different from adding a contact (owner/resident record) [1]. \n\n"
        . "For more details, you can access the **Team Settings** directly. ";

    /**
     * @test eval p22-1001-B app-navigation t0: "Here are the next steps you can take:" then a
     * bullet "- Team settings". Read one line at a time neither is an echo — the heading holds
     * the colon and no label, the bullet the label and no colon. Together they are the button
     * list again: the heading goes with its list. The steps above — a colon line too, followed
     * by a list — are prose items and stay.
     */
    public function p22_b_app_nav_a_colon_line_and_its_label_only_list_are_dropped_together(): void
    {
        $narrative = self::P22_APP_NAV . "\n\nHere are the next steps you can take: \n\n- Team settings";

        $this->assertSame(
            [rtrim(self::P22_APP_NAV), ['Here are the next steps you can take:', '- Team settings']],
            $this->actions()->withoutLabelEcho($narrative)
        );
    }

    /**
     * @test
     * @dataProvider listEchoes
     * @param list<string> $dropped
     */
    public function a_colon_line_followed_by_a_list_of_only_button_labels_is_dropped_with_it(string $narrative, string $kept, array $dropped): void
    {
        $this->assertSame([$kept, $dropped], $this->actions()->withoutLabelEcho($narrative));
    }

    /** @return array<string, array{0: string, 1: string, 2: list<string>}> */
    public static function listEchoes(): array
    {
        $answer = 'You can invite a member from the team page [1].';

        return [
            'several items, a bold heading, star bullets' => [
                $answer . "\n\n**Next steps:**\n\n* Team settings\n* Download Excel",
                $answer,
                ['**Next steps:**', '* Team settings', '* Download Excel'],
            ],
            'numbered items with emphasis and a full stop' => [
                $answer . "\n\nHere is what you can open:\n1. **Team settings**.\n2. **Download Excel**.",
                $answer,
                ['Here is what you can open:', '1. **Team settings**.', '2. **Download Excel**.'],
            ],
            'the heading is the last sentence of a paragraph' => [
                $answer . " Here are the next steps you can take:\n- Team settings",
                $answer,
                ['Here are the next steps you can take:', '- Team settings'],
            ],
            'a loose list (blank lines between the items), then prose' => [
                "Here are the buttons you can use:\n\n- Team settings\n\n- Receivables report\n\n" . $answer,
                $answer,
                ['Here are the buttons you can use:', '- Team settings', '- Receivables report'],
            ],
            'two labels on one item, a record chip beside a button' => [
                $answer . "\n\nYou can go further here:\n- Team settings and Download Excel\n- Annual general meeting 2023",
                $answer,
                ['You can go further here:', '- Team settings and Download Excel', '- Annual general meeting 2023'],
            ],
            'the label on a line of its own, without a list marker' => [
                $answer . "\n\nHere are the next steps you can take:\nTeam settings",
                $answer,
                ['Here are the next steps you can take:', 'Team settings'],
            ],
            'in another language (the heading is not read)' => [
                "Vous pouvez inviter un membre depuis la page de l'équipe.\n\nVoici les prochaines étapes possibles :\n- Team settings",
                "Vous pouvez inviter un membre depuis la page de l'équipe.",
                ['Voici les prochaines étapes possibles :', '- Team settings'],
            ],
        ];
    }

    /**
     * @test A list under a colon line stays when ANY of its items says something.
     * @dataProvider listsThatStay
     */
    public function a_list_that_says_something_is_kept_with_its_heading(string $narrative): void
    {
        $this->assertSame([$narrative, []], $this->actions()->withoutLabelEcho($narrative));
    }

    /** @return array<string, array{0: string}> */
    public static function listsThatStay(): array
    {
        return [
            'p22-B app-nav without its echo: the steps of a how-to' => [rtrim(self::P22_APP_NAV)],
            'an instruction that names the page' => ["Do this:\n- Go to Team settings and click Add member"],
            'one item of the list is prose' => ["Next steps:\n- Team settings\n- Then send the invitation from that page"],
            'an item continues on the next line' => ["Next steps:\n- Team settings\n  where every pending invitation is listed"],
            'records only (a list of the rows, not of the buttons)' => ["The meeting on record:\n- Annual general meeting 2023"],
            'the heading states a figure' => ["You have 75 overdue invoices, all listed here:\n- Receivables report"],
            'the heading carries a citation' => ["Members are invited from one page [1]:\n- Team settings"],
            'the heading is two short sentences' => ["Yes. Here they are:\n- Team settings"],
            'no colon above the list' => ["You can manage the team from one page.\n\n- Team settings"],
            'a paragraph under the colon line' => ["Please note:\nTeam settings lists every pending invitation."],
            'a table under the colon line' => ["The pages:\n\n| Team settings |\n| --- |"],
            'the list is all there is' => ["Here are the next steps you can take:\n\n- Team settings"],
            'a label inside a longer word' => ["Next steps:\n- Team settingsX"],
        ];
    }

    /** @test Nothing to echo: no chip, or follow-up question chips only. */
    public function without_buttons_nothing_is_touched(): void
    {
        $narrative = "Some answer.\n\nAttached below this answer: Receivables report.";

        $this->assertSame([$narrative, []], AttachedActions::none()->withoutLabelEcho($narrative));

        $followUpsOnly = new AttachedActions([$this->chip(AttachedActions::ROLE_FOLLOW_UP, 'ask_follow_up', 'Receivables report')]);
        $this->assertSame([$narrative, []], $followUpsOnly->withoutLabelEcho($narrative));
    }
}
