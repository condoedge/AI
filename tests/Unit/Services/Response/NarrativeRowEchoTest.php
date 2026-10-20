<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\NarrativeRowEcho;
use PHPUnit\Framework\TestCase;

/**
 * NarrativeRowEcho — the structural rule itself (eval p17 row echo before the table):
 * a list / pipe-table block whose entries mostly contain the rendered rows' own label
 * values is an echo. No wording is ever read; the replayed p17 answers are covered through
 * DataAnswerPlan::finalize in DataAnswerPlanLeadTest.
 */
class NarrativeRowEchoTest extends TestCase
{
    private const KEYS = ['Julie Rousseau', 'Service Comptable', 'Fernande Martin', 'Chloé Monnier', 'Karen Douglas'];

    /** @test */
    public function a_list_naming_the_rows_is_removed_and_the_text_around_it_kept(): void
    {
        [$text, $dropped] = NarrativeRowEcho::strip(
            "Here are the members:\n\n- Julie Rousseau: Lead\n- Service Comptable: Accountant\n- Fernande Martin: Owner\n\nYou are the lead.",
            self::KEYS
        );

        $this->assertSame(1, $dropped);
        $this->assertStringContainsString('Here are the members:', $text);
        $this->assertStringContainsString('You are the lead.', $text);
        $this->assertStringNotContainsString('Service Comptable', $text);
    }

    /** @test Emphasis, escapes, case, numbering and a non-breaking space before a colon do not hide a row value. */
    public function the_match_ignores_markup_case_and_spacing(): void
    {
        [, $dropped] = NarrativeRowEcho::strip(
            "1. **JULIE ROUSSEAU**\u{00A0}: Gestionnaire\n2) _Service  Comptable_ : Comptable\n3. Chlo\u{00E9} Monnier",
            self::KEYS
        );

        $this->assertSame(1, $dropped);
    }

    /** @test A loose list (blank lines between entries) and indented continuation lines are one block. */
    public function a_loose_list_with_continuation_lines_is_one_block(): void
    {
        [$text, $dropped] = NarrativeRowEcho::strip(
            "Members:\n\n- Julie Rousseau\n  Lead union manager\n\n- Service Comptable\n  Accountant\n\n- Karen Douglas\n  Owner\n\nThat is the team.",
            self::KEYS
        );

        $this->assertSame(1, $dropped);
        $this->assertSame("Members:\n\n\nThat is the team.", $text);
    }

    /** @test Below the ratio the list is the model's own content with a few names in it — kept. */
    public function a_list_that_mostly_does_not_name_rows_is_kept(): void
    {
        $list = "- Julie Rousseau approves invoices\n- The accountant posts them\n- Owners receive a statement\n- Service Comptable files the report\n- Reports are monthly";

        [$text, $dropped] = NarrativeRowEcho::strip($list, self::KEYS);

        $this->assertSame(0, $dropped, '2 of 5 entries name a row: under the 60% ratio');
        $this->assertSame($list, $text);
    }

    /** @test One value on every line is the subject of the list, not a list of rows. */
    public function entries_all_naming_the_same_single_value_are_kept(): void
    {
        $list = "- Julie Rousseau manages the team\n- Julie Rousseau approves invoices\n- Julie Rousseau signs the minutes";

        $this->assertSame(0, NarrativeRowEcho::strip($list, self::KEYS)[1]);
    }

    /** @test A value is matched as a whole word: unit "106" is not found in "art. 1064". */
    public function a_value_is_matched_as_a_whole_word(): void
    {
        $legal = "- art. 1064 sets each co-owner's share\n- art. 3051 covers the contingency fund\n- art. 1072 fixes the contributions";

        $this->assertSame(0, NarrativeRowEcho::strip($legal, ['106', '305', '107'])[1]);
        $this->assertSame(1, NarrativeRowEcho::strip("- Unit 106: \$10.00\n- Unit 305: \$20.00\n- Unit 107: \$0.00", ['106', '305', '107'])[1]);
    }

    /** @test A block citing a passage is document-grounded content, whatever it names. */
    public function a_block_with_a_citation_marker_is_kept(): void
    {
        $list = "- Julie Rousseau must convene the meeting [1]\n- Service Comptable keeps the register [2]";

        $this->assertSame(0, NarrativeRowEcho::strip($list, self::KEYS)[1]);
    }

    /** @test A pipe table: its data rows are the entries — the header and the separator are not. */
    public function a_pipe_table_naming_the_rows_is_removed(): void
    {
        [$text, $dropped] = NarrativeRowEcho::strip(
            "Your team:\n\n| Name | Role |\n|---|---|\n| Julie Rousseau | Lead |\n| Karen Douglas | Owner |\n\nDone.",
            self::KEYS
        );

        $this->assertSame(1, $dropped);
        $this->assertStringNotContainsString('|', $text);
    }

    /** @test Only the echoing block goes: another list of the same lead stays. */
    public function each_block_is_judged_on_its_own(): void
    {
        [$text, $dropped] = NarrativeRowEcho::strip(
            "Two things to know:\n\n- Roles are set per team\n- An owner can hold several\n\nThe members:\n\n- Julie Rousseau\n- Karen Douglas",
            self::KEYS
        );

        $this->assertSame(1, $dropped);
        $this->assertStringContainsString('- Roles are set per team', $text);
        $this->assertStringNotContainsString('- Karen Douglas', $text);
    }

    /** @test No keys, a single entry, or prose: nothing is touched and the text is returned as it came. */
    public function nothing_is_dropped_without_keys_entries_or_a_block(): void
    {
        $prose = 'Julie Rousseau, Service Comptable and Karen Douglas are on your team.';

        $this->assertSame([$prose, 0], NarrativeRowEcho::strip($prose, self::KEYS));
        $this->assertSame(0, NarrativeRowEcho::strip("- Julie Rousseau\n- Karen Douglas", [])[1]);
        $this->assertSame(0, NarrativeRowEcho::strip('- Julie Rousseau is the lead', self::KEYS)[1]);
        $this->assertSame(0, NarrativeRowEcho::strip("- Julie Rousseau\n- Karen Douglas", ['J', 'K'])[1], 'one-character values identify nothing');
    }

    // =========================================================================
    // THE FIRST SENTENCE (eval p19: 2 of 6 leads were two sentences beside "ONE sentence")
    // =========================================================================

    /**
     * @test
     * @dataProvider sentenceCuts
     */
    public function the_first_sentence_is_cut_at_a_safe_boundary_only(string $lead, string $kept, string $cut): void
    {
        $this->assertSame([$kept, $cut], NarrativeRowEcho::firstSentence($lead));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function sentenceCuts(): array
    {
        return [
            'p19-A owner-arrears' => [
                'The total balance of owners behind on their fees in SDC Carré Soho Phase 3 is $1,211.48. Here are the details of the owners who are currently overdue on their fees.',
                'The total balance of owners behind on their fees in SDC Carré Soho Phase 3 is $1,211.48.',
                'Here are the details of the owners who are currently overdue on their fees.',
            ],
            'p19-A board' => [
                'The information about the board of directors and the president is available in the records. You can find the details in the table below.',
                'The information about the board of directors and the president is available in the records.',
                'You can find the details in the table below.',
            ],
            'one sentence' => ['You are the Lead union manager.', 'You are the Lead union manager.', ''],
            'no terminal punctuation' => ['Here are the members of your team along with their roles:', 'Here are the members of your team along with their roles:', ''],
            'a decimal is not a boundary' => ['The three of them owe $1,211.48 in all right now.', 'The three of them owe $1,211.48 in all right now.', ''],
            'an initial is not a boundary' => [
                'The president of the board is J. Rousseau. She was elected in 2022.',
                'The president of the board is J. Rousseau.',
                'She was elected in 2022.',
            ],
            'art. 12 is not a boundary' => [
                'This follows art. 12 of the by-laws. It applies to every unit.',
                'This follows art. 12 of the by-laws.',
                'It applies to every unit.',
            ],
            'a hyphenated St- name' => [
                'The unit is in SDC Les Terrasses 7250 St-Hubert. It owes nothing at all.',
                'The unit is in SDC Les Terrasses 7250 St-Hubert.',
                'It owes nothing at all.',
            ],
            'an abbreviation before a lower-case word' => [
                'The wall is common vs. private in that case. Ask the board first.',
                'The wall is common vs. private in that case.',
                'Ask the board first.',
            ],
            'a short capitalized abbreviation before a name: unsure, so no cut there' => [
                'The largest bill is owed to BFL Canada Inc. Embellissements Roli-Vert follows it.',
                'The largest bill is owed to BFL Canada Inc. Embellissements Roli-Vert follows it.',
                '',
            ],
            'St. Hubert is skipped, the next boundary is used' => [
                'The building stands on St. Hubert street. Nothing is due there.',
                'The building stands on St. Hubert street.',
                'Nothing is due there.',
            ],
            'dotted acronym' => [
                'The last meeting was the A.G.A. Soho of October. It was held online.',
                'The last meeting was the A.G.A. Soho of October.',
                'It was held online.',
            ],
            'a one-word opener is not the answer: the sentence after it is kept too' => [
                'Yes. You are the Lead union manager. Here is everyone else.',
                'Yes. You are the Lead union manager.',
                'Here is everyone else.',
            ],
            'an exclamation opener' => [
                'Good news! None of the three units owes anything. The table shows each one.',
                'Good news! None of the three units owes anything.',
                'The table shows each one.',
            ],
            'a paragraph break is a boundary' => [
                "You are the Lead union manager of this team\n\nHere is everyone else.",
                'You are the Lead union manager of this team',
                'Here is everyone else.',
            ],
            'extra text citing a passage is content (hybrid turn): nothing is cut' => [
                'Four units are behind on their fees. The by-laws allow interest on arrears [2].',
                'Four units are behind on their fees. The by-laws allow interest on arrears [2].',
                '',
            ],
            'a list in the lead: unsure, nothing is cut' => [
                "Two things apply here. Read them first.\n\n- Roles are set per team\n- An owner can hold several",
                "Two things apply here. Read them first.\n\n- Roles are set per team\n- An owner can hold several",
                '',
            ],
            'french' => [
                'Le solde total est de 1 211,48 $. Voici le détail des unités concernées.',
                'Le solde total est de 1 211,48 $.',
                'Voici le détail des unités concernées.',
            ],
        ];
    }

    // =========================================================================
    // THE SENTENCES OF A TEXT (eval p21: an excerpt reached its reader cut inside its rule)
    // =========================================================================

    /**
     * @test The same safe boundaries as the first-sentence cut, all of them: a text that is
     * shortened by whole sentences is never cut inside one.
     * @dataProvider sentenceSplits
     *
     * @param list<string> $sentences
     */
    public function a_text_is_split_into_its_sentences_at_safe_boundaries_only(string $text, array $sentences): void
    {
        $this->assertSame($sentences, NarrativeRowEcho::sentences($text));
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function sentenceSplits(): array
    {
        return [
            'three sentences' => [
                'The operator inspects the aircraft before each flight. Every defect found is entered in the log! Is the log kept on board? It is.',
                ['The operator inspects the aircraft before each flight.', 'Every defect found is entered in the log!', 'Is the log kept on board?', 'It is.'],
            ],
            'a clause number opens its rule: it is not a sentence of its own' => [
                '1069. A person who acquires a fraction is bound to pay the expenses due. The statement is adjusted to the last budget.',
                ['1069. A person who acquires a fraction is bound to pay the expenses due.', 'The statement is adjusted to the last budget.'],
            ],
            'an initial, a dotted token and a short capitalized token end nothing' => [
                'It was signed by J. Rousseau of Novo Inc. Montreal at the A.G.A. Then the board met again.',
                ['It was signed by J. Rousseau of Novo Inc. Montreal at the A.G.A. Then the board met again.'],
            ],
            'a decimal and a reference in the text' => [
                'The fund holds 0.5% of the cost under art. 1071 of the code. The board fixes it each year.',
                ['The fund holds 0.5% of the cost under art. 1071 of the code.', 'The board fixes it each year.'],
            ],
            'no terminal punctuation: one sentence' => [
                'the following are common portions: the ground; the stairways; the main walls',
                ['the following are common portions: the ground; the stairways; the main walls'],
            ],
            'a blank line is a boundary' => [
                "The roster is shown below\n\nEvery member has one role.",
                ['The roster is shown below', 'Every member has one role.'],
            ],
            'french' => [
                'Le syndicat tient un registre à la disposition des copropriétaires. Il contient les procès-verbaux des assemblées.',
                ['Le syndicat tient un registre à la disposition des copropriétaires.', 'Il contient les procès-verbaux des assemblées.'],
            ],
            'empty' => ['  ', []],
        ];
    }

    // =========================================================================
    // A LEAD THAT ONLY RESTATES (eval p19: the lead has nothing left to say)
    // =========================================================================

    /**
     * @test
     * @dataProvider restatingLeads
     */
    public function a_lead_made_of_the_question_and_the_system_line_is_a_restatement(string $lead, string $question, string $facts): void
    {
        $this->assertTrue(NarrativeRowEcho::restates($lead, [$question, $facts]));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function restatingLeads(): array
    {
        $roster = 'Who are the members of my team and what are their roles?';
        $board = 'Who is on our board of directors and who is the president?';
        $boardFacts = '37 board members across 12 unions. Not recorded: Board role.';

        return [
            'p19-B resilience t0' => ['Here are the members of your team along with their roles:', 'I wanna know the members of my team with their roles.', '523 members.'],
            'p19-B french t0' => ['Voici la liste des membres de votre équipe avec leurs rôles :', 'Peux-tu me donner la liste des membres de mon équipe avec leurs rôles ?', '523 membres.'],
            'an introduction' => ['Here are the members of your team and their roles.', $roster, '523 members.'],
            'the count again' => ['Your team has 523 members with their roles.', $roster, '523 members.'],
            'the not-recorded line again' => ['The president is not recorded.', $board, $boardFacts],
            'the count and groups again' => ['There are 37 board members across 12 unions.', $board, $boardFacts],
            'the homonym line again' => ['There are 3 different units named 310, one per union.', 'How much does unit 310 owe right now?', '3 different units named 310 — one per union.'],
            'emphasis does not hide it' => ['Here are the **members** of your team and their _roles_.', $roster, '523 members.'],
        ];
    }

    /**
     * @test
     * @dataProvider answeringLeads
     */
    public function a_lead_that_says_something_of_its_own_is_kept(string $lead, string $question, string $facts): void
    {
        $this->assertFalse(NarrativeRowEcho::restates($lead, [$question, $facts]));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function answeringLeads(): array
    {
        $roster = 'Who are the members of my team and what are their roles?';
        $board = 'Who is on our board of directors and who is the president?';
        $boardFacts = '37 board members across 12 unions. Not recorded: Board role.';
        $unit = 'How much does unit 310 owe right now?';
        $unitFacts = '3 different units named 310 — one per union.';
        $arrears = 'Which owners in SDC Carré Soho Phase 3 are behind on their fees?';

        return [
            'a value of the rows' => ['You are the Lead union manager.', $roster, '523 members.'],
            'a name' => ['The president is Amélie Quirion.', $board, $boardFacts],
            'a figure' => ['The total balance of owners behind on their fees in SDC Carré Soho Phase 3 is $1,211.48.', $arrears, '4 units.'],
            'a short figure' => ['They owe $1,211.48 in all.', $arrears, '4 units.'],
            'a negative answer' => ['None of them owes anything right now.', $unit, $unitFacts],
            'a negative answer in the question\'s words' => ['No unit named 310 owes anything.', $unit, $unitFacts],
            'a negation made of short words only' => ['No one is behind on their fees.', $arrears, '4 units.'],
            'a short answer of the question\'s words' => ['No member has a role.', $roster, '523 members.'],
            'one word' => ['Yes.', $roster, '523 members.'],
            'p19-B resilience t1' => ['I have included the last login information for each team member.', 'Ok, but could you add their last login to it?', '523 members.'],
            'p19-A roster (a hedge, not a restatement)' => ['Your team consists of several members with various roles, including yourself as the Lead Union Manager.', $roster, '523 members.'],
            'p19-A unit-310 (a merge, not a restatement)' => ['Unit 310 currently owes $0.00 across all associated unions, with no open invoices or amounts due.', $unit, $unitFacts],
            'p19-A board (a false sentence, not a restatement)' => ['The information about the board of directors and the president is available in the records.', $board, $boardFacts],
            'a scoping introduction' => ['Here are the current board members of the unions you manage.', $board, $boardFacts],
            'french value' => ['Vous êtes la gestionnaire en chef.', 'Peux-tu me donner la liste des membres de mon équipe avec leurs rôles ?', '523 membres.'],
            'a cited sentence' => ['The members of your team and their roles [1].', $roster, '523 members.'],
            'empty' => ['', $roster, '523 members.'],
            'nothing to compare with' => ['Here are the members of your team.', '', ''],
        ];
    }
}
