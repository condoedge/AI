<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\AnswerValidator;
use Condoedge\Ai\Services\Response\EntityIdIndex;
use Condoedge\Ai\Support\ShownCalculation;
use Condoedge\Ai\Support\StatedNumber;
use Condoedge\Ai\Tests\TestCase;

/**
 * AnswerValidator (Phase 8 batch B): the ONE post-composition validation pass.
 * Every check is structural over the masked answer + the turn's own evidence.
 * The truncation and self-reference checks are ported from the former
 * ResponseGenerator guards (their generator-level tests still pin the flow).
 */
class AnswerValidatorTest extends TestCase
{
    private const PASSAGES = [
        1 => "1068. The syndicate keeps a register of co-owners.\n",
        2 => "1069. A co-owner who sells his fraction is bound, with the buyer, to the common expenses owed.\n",
        3 => "1097. Decisions are taken by a majority representing three-quarters of the votes.\n",
    ];

    private function types(array $violations): array
    {
        return AnswerValidator::types($violations);
    }

    // =========================================================================
    // Citations
    // =========================================================================

    /** @test */
    public function the_measured_placeholder_forms_are_detected_and_patched_to_real_markers(): void
    {
        $validator = new AnswerValidator(passages: self::PASSAGES, citationsRequired: true);
        $answer = 'The buyer is bound to the arrears ([N] 2). See also the register [N].';

        $violations = $validator->validate($answer);
        $this->assertSame([AnswerValidator::CITATION_PLACEHOLDER], $this->types($violations),
            '"([N] 2)" normalizes to a valid [2], so the answer is not marker-less');

        [$patched, $types] = $validator->patch($answer, $violations);
        $this->assertSame('The buyer is bound to the arrears [2]. See also the register.', $patched);
        $this->assertSame([AnswerValidator::CITATION_PLACEHOLDER], $types);
        $this->assertSame([], $validator->validate($patched));
    }

    /**
     * @test Eval p16-B rights t0: the model cited as "[1, art. 1063]" - a fully cited answer was
     * judged marker-less (retry + no source links). The annotated form is normalized to the
     * standard marker, keeping the annotation for the reader.
     */
    public function an_annotated_citation_is_normalized_to_a_standard_marker(): void
    {
        $validator = new AnswerValidator(passages: self::PASSAGES, citationsRequired: true);

        $this->assertSame(
            'You may use your private portion (art. 1063) [1]. You must contribute (arts. 1064–1066) [2].',
            $validator->normalizeAnnotatedCitations('You may use your private portion [1, art. 1063]. You must contribute [2; arts. 1064–1066].')
        );
        // A list of markers is split; an unknown passage number is left for the range check.
        $this->assertSame('Both apply [1][2].', $validator->normalizeAnnotatedCitations('Both apply [1, 2].'));
        $this->assertSame('See [9, art. 1].', $validator->normalizeAnnotatedCitations('See [9, art. 1].'));
        // Plain markers, markdown links and ordinary bracketed text are untouched.
        $plain = 'Fine [1] and [the page](http://x) and [note, see below].';
        $this->assertSame($plain, $validator->normalizeAnnotatedCitations($plain));

        $normalized = $validator->normalizeAnnotatedCitations('The buyer is bound [2, art. 1069].');
        $this->assertSame([], $validator->validate($normalized), 'the normalized answer is cited');
    }

    /** @test */
    public function a_marker_naming_a_passage_that_does_not_exist_is_stripped(): void
    {
        $validator = new AnswerValidator(passages: self::PASSAGES);
        $answer = 'The register is kept by the syndicate [1] [7].';

        $violations = $validator->validate($answer);
        $this->assertSame([AnswerValidator::CITATION_OUT_OF_RANGE], $this->types($violations));

        [$patched] = $validator->patch($answer, $violations);
        $this->assertSame('The register is kept by the syndicate [1].', $patched);
    }

    /** @test */
    public function an_article_cited_with_the_wrong_passage_is_moved_to_the_one_passage_that_contains_it(): void
    {
        $validator = new AnswerValidator(passages: self::PASSAGES);
        $answer = "Under art. 1069, the buyer is bound to the arrears [1].\nThe register is kept by the syndicate [1].";

        $violations = $validator->validate($answer);
        $this->assertSame([AnswerValidator::CITATION_ARTICLE_MISMATCH], $this->types($violations));
        $this->assertTrue($violations[0]['fixable_deterministically']);
        $this->assertSame([2], $violations[0]['found_in']);

        [$patched] = $validator->patch($answer, $violations);
        // Only the sentence naming the article moves; the register sentence keeps [1].
        $this->assertSame("Under art. 1069, the buyer is bound to the arrears [2].\nThe register is kept by the syndicate [1].", $patched);
    }

    /**
     * Live 2026-09-30: "specifically Article 1078, …" carried no marker of its own;
     * the paragraph's citation came one sentence later. An unmarked article
     * sentence answers to the NEXT marker of its paragraph (recorded, not patched).
     *
     * @test
     */
    public function an_unmarked_article_sentence_answers_to_the_next_marker_of_its_paragraph(): void
    {
        $validator = new AnswerValidator(passages: self::PASSAGES);

        $violations = $validator->validate('Specifically Article 1069 applies. You could be held responsible [1].');
        $this->assertSame([AnswerValidator::CITATION_ARTICLE_MISMATCH], $this->types($violations));
        $this->assertFalse($violations[0]['fixable_deterministically'], 'the marker sits in another sentence');

        $this->assertSame([], $validator->validate('Specifically Article 1069 applies. It binds the buyer [2].'));
        // A new paragraph never inherits.
        $this->assertSame([], $validator->validate("Specifically Article 1069 applies.\n\nThe register is kept [1]."));
    }

    /**
     * M1: the move target must DEFINE the article; a passage that merely mentions
     * it (cross-reference) is detection evidence only.
     *
     * @test
     */
    public function a_citation_moves_only_to_the_passage_that_defines_the_article(): void
    {
        $passages = [
            1 => "**1060.** Amendments are filed in the register held in accordance with article 1070.\n",
            2 => "**1070.** The syndicate keeps a register of co-owners.\n",
            3 => "**1097.** Three-quarters of the votes.\n",
        ];
        $validator = new AnswerValidator(passages: $passages);

        $answer = 'Under art. 1070 the register is kept by the syndicate [3].';
        $violations = $validator->validate($answer);
        $this->assertTrue($violations[0]['fixable_deterministically']);
        [$patched] = $validator->patch($answer, $violations);
        $this->assertSame('Under art. 1070 the register is kept by the syndicate [2].', $patched);

        // Only a mention exists (no passage defines 1062): recorded, never moved.
        $mentionOnly = new AnswerValidator(passages: [1 => "**1060.** See article 1062.\n", 2 => "**1097.** Votes.\n"]);
        $this->assertFalse($mentionOnly->validate('Under art. 1062 it is filed [2].')[0]['fixable_deterministically']);
    }

    /**
     * M3: a continuation chunk without a line-start number is located by its chunk
     * metadata; with no article information at all it is never flagged.
     *
     * @test
     */
    public function continuation_chunks_use_their_metadata_and_unknown_passages_are_never_flagged(): void
    {
        $text = [1 => '…with interest, at the time of the acquisition.', 2 => '**1097.** Votes.'];

        $unknown = new AnswerValidator(passages: $text);
        $this->assertSame([], $unknown->validate('Under art. 1069 the buyer is bound [1].'), 'passage [1] has no known articles');

        $located = new AnswerValidator(passages: $text, passageArticles: [1 => [1069], 2 => [1097]]);
        $this->assertSame([], $located->validate('Under art. 1069 the buyer is bound [1].'));

        $elsewhere = new AnswerValidator(passages: $text, passageArticles: [1 => [1070], 2 => [1097]]);
        $this->assertSame([AnswerValidator::CITATION_ARTICLE_MISMATCH], $this->types($elsewhere->validate('Under art. 1069 the buyer is bound [1].')));
    }

    /** @test */
    public function an_article_in_no_passage_is_recorded_but_not_patched(): void
    {
        $validator = new AnswerValidator(passages: self::PASSAGES);
        $violations = $validator->validate('Article 1072 requires a contingency fund [2].');

        $this->assertSame([AnswerValidator::CITATION_ARTICLE_MISMATCH], $this->types($violations));
        $this->assertFalse($violations[0]['fixable_deterministically']);
    }

    /** @test */
    public function a_document_grounded_answer_without_any_valid_marker_violates(): void
    {
        $validator = new AnswerValidator(passages: self::PASSAGES, citationsRequired: true);

        $this->assertSame([AnswerValidator::CITATION_MISSING], $this->types($validator->validate('The buyer is liable.')));
        $this->assertSame([], $validator->validate('The buyer is liable [2].'));
        // Without the requirement (data turn / terse style) a marker-less answer is fine.
        $this->assertSame([], (new AnswerValidator(passages: self::PASSAGES))->validate('The buyer is liable.'));
    }

    // =========================================================================
    // Numbers
    // =========================================================================

    /** @test */
    public function numbers_must_come_from_the_evidence_with_formatting_tolerance(): void
    {
        $validator = new AnswerValidator(evidence: [11493.17, 27.0, 4120.5]);

        $this->assertSame([], $validator->validate('You owe $11,493.17 to 27 suppliers.'));
        $this->assertSame([], $validator->validate('Vous devez 11 493,17 $ à vos fournisseurs.'));
        $this->assertSame([], $validator->validate('About $11,493 in total.'), 'rounded to the answer\'s precision');
        $this->assertSame([], $validator->validate('The largest bill is 4,120.50 $.'));

        $violations = $validator->validate('You owe $22,753.51 in total.');
        $this->assertSame([AnswerValidator::NUMBER_UNSUPPORTED], $this->types($violations));
        $this->assertSame('22,753.51', $violations[0]['detail']);
    }

    /** @test */
    public function day_sized_integers_years_dates_markers_and_tokens_are_not_quantities_to_check(): void
    {
        $validator = new AnswerValidator(evidence: [1.0]);

        $this->assertSame([], $validator->validate(
            "1. Pay within 15 days of the notice dated 2026-07-23 at 14:30 (fiscal 2025) [3].\n"
            . 'Contact [email#12] or see [the unit](entity://Unit/4411/view).'
        ));
    }

    private function totalsValidator(): AnswerValidator
    {
        // Money rows 6000 + 5450 + 43.17 = grand total 11,493.17.
        return new AnswerValidator(
            evidence: [11493.17, 6000.0, 5450.0, 43.17],
            grandTotals: [11493.17],
            moneyRowFigures: [6000.0, 5450.0, 43.17],
        );
    }

    /** @test */
    public function a_transposed_or_mis_rounded_total_is_patched_to_the_known_grand_total(): void
    {
        $validator = $this->totalsValidator();

        $violations = $validator->validate('In total you owe $11,943.17 to your suppliers.');
        $this->assertTrue($violations[0]['fixable_deterministically'], 'digit transposition of 11,493.17');
        [$patched, $types] = $validator->patch('In total you owe $11,943.17 to your suppliers.', $violations);
        $this->assertSame('In total you owe $11,493.17 to your suppliers.', $patched);
        $this->assertSame([AnswerValidator::NUMBER_UNSUPPORTED], $types);

        $this->assertTrue($validator->validate('You owe 11 470,00 $.')[0]['fixable_deterministically'], 'within 0.5%');
    }

    /** @test */
    public function a_correct_looking_derived_figure_is_never_overwritten_with_the_total(): void
    {
        $validator = $this->totalsValidator();

        // Subset sum within 0.5% of the total (all but the 43.17 bill): recorded, not replaced.
        $this->assertFalse($validator->validate('Excluding Hydro you owe $11,450.00.')[0]['fixable_deterministically']);
        // Far from the total (another subset, a difference, an annualized figure): recorded.
        $this->assertFalse($validator->validate('The two largest bills come to $11,000.50.')[0]['fixable_deterministically']);
        // The measured $10,493.17 mis-sum (8.7% off) goes to the retry, never a silent rewrite.
        $this->assertFalse($validator->validate('You owe $10,493.17.')[0]['fixable_deterministically']);
        // Without a single known grand total nothing is ever replaced.
        $this->assertFalse((new AnswerValidator(evidence: [1.0], grandTotals: [5.0, 6.0]))->validate('$11,943.17')[0]['fixable_deterministically']);
    }

    /** @test */
    public function a_calculated_figure_stands_only_with_an_operation_that_gives_it(): void
    {
        $validator = new AnswerValidator(evidence: [1.05, 0.5]);

        $this->assertSame([], $validator->validate('The result is 1.05 × 2 + 0.5 = 2.60.'));
        $this->assertSame([AnswerValidator::NUMBER_UNSUPPORTED], $this->types($validator->validate('The result is 2.60.')));
        $this->assertSame([AnswerValidator::NUMBER_UNSUPPORTED], $this->types($validator->validate('The result is 1.05 × 2 + 0.5 = 3.70.')));
        $this->assertSame([AnswerValidator::NUMBER_UNSUPPORTED], $this->types($validator->validate('The result is 7.77 × 2 = 15.54.')));
    }

    /** @test */
    public function a_result_that_slipped_is_patched_without_a_retry_in_the_answers_typography(): void
    {
        $validator = new AnswerValidator(evidence: [11996.81, 5.0]);
        $answer = 'Cela donne 11 996,81 $ x 1,05 = 12 586,65 $.';

        $violations = $validator->validate($answer);
        $this->assertTrue($violations[0]['fixable_deterministically']);

        [$patched] = $validator->patch($answer, $violations);
        $this->assertStringContainsString("12\u{00A0}596,65", $patched);
        $this->assertSame([], $validator->validate($patched));
    }

    /** @test Measured 3 of 3: the 0.5 was never added, and the step's own figure was stated as the result. */
    public function a_step_stated_as_the_result_is_corrected_and_the_step_stays(): void
    {
        $validator = new AnswerValidator(evidence: [819.82, 0.5]);
        $answer = '$819.82 x 2 + 0.5 = $1639.64 + 0.5 = $1639.64.';

        $violations = $validator->validate($answer);
        $this->assertTrue($violations[0]['fixable_deterministically']);

        [$patched] = $validator->patch($answer, $violations);
        $this->assertSame('$819.82 x 2 + 0.5 = $1639.64 + 0.5 = $1,640.14.', $patched);
        $this->assertSame([], $validator->validate($patched));
    }

    /** @test Every figure of a calculation that holds may be stated, the steps and the factor too. */
    public function nothing_of_a_calculation_that_holds_is_flagged(): void
    {
        $validator = new AnswerValidator(evidence: [800.0, 15.0]);

        $this->assertSame([], $validator->validate('With tax: $800 + ($800 * 0.15) = $800 + $120 = $920.'));
        $this->assertSame([], (new AnswerValidator(evidence: [57015.71, 158393.80]))->validate('(57,015.71 / 158,393.80) * 100 = 36.0 %'));
    }

    /** @test */
    public function the_retry_says_how_to_show_a_calculation_the_user_asked_for(): void
    {
        $validator = new AnswerValidator(evidence: [1.05, 0.5]);
        $violations = $validator->validate('The result is 2.60.');

        $this->assertStringContainsString(ShownCalculation::INSTRUCTION, $validator->retryInstruction($violations, 'The result is 2.60.'));
    }

    /** @test */
    public function the_number_check_is_off_without_evidence(): void
    {
        $this->assertSame([], (new AnswerValidator())->validate('There are 4,312 owners.'));
    }

    // =========================================================================
    // Links
    // =========================================================================

    /** @test */
    public function a_link_id_must_belong_to_the_linked_label(): void
    {
        // Measured: entity://Union/3/view where 3 was the TEAM id.
        $index = new EntityIdIndex(
            [['team_id' => 3, 'team' => 'Novo', 'union_id' => 11, 'union' => 'Le Jardin']],
            'MATCH (t:Team)-[:MANAGES]->(u:Union) RETURN t.id AS team_id, t.name AS team, u.id AS union_id, u.name AS union'
        );
        $validator = new AnswerValidator(entityIds: $index);

        $this->assertSame([], $validator->validate('[Le Jardin](entity://Union/11/view)'));
        $this->assertSame([], $validator->validate('[Novo](entity://Team/3/view)'));

        $answer = 'See [Le Jardin](entity://Union/3/view) and [the meeting](entity://Meeting/ID/view).';
        $violations = $validator->validate($answer);
        $this->assertCount(2, $violations);
        $this->assertSame([AnswerValidator::LINK_INVALID], $this->types($violations));

        [$patched] = $validator->patch($answer, $violations);
        $this->assertSame('See Le Jardin and the meeting.', $patched);
    }

    /** @test */
    public function row_labels_action_links_and_unattributed_ids_are_honoured(): void
    {
        $index = new EntityIdIndex([
            ['id' => 7, '_label' => 'Unit'],
            ['entity_type' => 'Person', 'id' => 152463, 'action_links' => ['[View](entity://Person/152463/profile)']],
            ['id' => 99], // nothing attributes it — accepted for any label
        ]);
        $validator = new AnswerValidator(entityIds: $index);

        $this->assertSame([], $validator->validate('[U](entity://Unit/7/view) [P](entity://Person/152463/profile) [X](entity://Invoice/99/view)'));
        $this->assertCount(1, $validator->validate('[P](entity://Person/7/profile)'), '7 is a Unit id');
        $this->assertCount(1, $validator->validate('[Pay](action://pay_bill?unit_id=12345)'), 'an invented id for a label the rows hold');
        $this->assertSame([], $validator->validate('[Pay](action://pay_bill?unit_id=7)'));
    }

    /**
     * I3: action parameters are only checked when their prefix names a label the
     * index holds; the turn's identity ids (current user, current team) are seeded,
     * so team_settings / create-form defaults are never stripped.
     *
     * @test
     */
    public function action_parameters_are_checked_only_against_labels_the_index_holds(): void
    {
        $index = (new EntityIdIndex([['id' => 7, '_label' => 'Unit']]))->withIdentity('13', ['5', '8']);
        $validator = new AnswerValidator(entityIds: $index);

        $this->assertSame([], $validator->validate('[Team settings](action://team_settings?team_id=5)'), 'current team id');
        $this->assertSame([], $validator->validate('[Profile](action://profile?user_id=13)'), 'current user id');
        $this->assertSame([], $validator->validate('[New](action://open_modal?modal=createBill&supplier_id=44)'), 'no Supplier ids in the index — left alone');
        $this->assertSame([], $validator->validate('[Open](action://open_modal?id=999)'), 'a bare id names no label');
        $this->assertCount(1, $validator->validate('[Team settings](action://team_settings?team_id=77)'), 'an invented team id');
        $this->assertCount(1, $validator->validate('[Pay](action://pay_bill?unitId=99)'), 'camelCase prefix');
    }

    /** @test M2 */
    public function label_lookup_is_case_insensitive(): void
    {
        $index = new EntityIdIndex([['id' => 11, '_label' => 'Union'], ['board_member_id' => 4]]);

        $this->assertTrue($index->has('union', '11'));
        $this->assertTrue($index->has('UNION', '11'));
        $this->assertTrue($index->has('BoardMember', '4'));
        $this->assertSame(['11'], $index->idsFor('union'));
        $this->assertTrue($index->holdsLabel('boardMember'));
        $this->assertFalse($index->holdsLabel('Supplier'));
    }

    // =========================================================================
    // Truncation + self-reference (ported)
    // =========================================================================

    /** @test */
    public function truncation_requires_the_total_and_the_shown_count_or_remainder(): void
    {
        $validator = new AnswerValidator(truncation: ['total' => 100, 'shown' => 10]);

        $this->assertSame([], $validator->validate('Your team has 100 members; here are the first 10.'));
        $this->assertSame([], $validator->validate('Your team has 100 members — 90 more are not shown.'));

        $violations = $validator->validate('There are more than 100 results.');
        $this->assertSame([AnswerValidator::TRUNCATION_UNDISCLOSED], $this->types($violations));

        [$patched] = $validator->patch('Here are your members.', $violations);
        $this->assertStringContainsString("Here are your members.\n\nShowing 10 of 100", $patched);
    }

    /** @test */
    public function the_own_email_needs_a_marker_on_its_own_line(): void
    {
        $validator = new AnswerValidator(selfNeedle: '[email#1]');

        $this->assertSame([], $validator->validate("Your contact is [email#1] — that's you."));
        $this->assertSame([], $validator->validate('Your team is Novo Gestion.'));

        $answer = "| Julie (that's you) | lead |\n\nYour team can be reached at [email#1].";
        $violations = $validator->validate($answer);
        $this->assertSame([AnswerValidator::SELF_REFERENCE_UNMARKED], $this->types($violations));

        [$patched] = $validator->patch($answer, $violations);
        $this->assertStringContainsString("reached at [email#1] (that's you — your own contact details).", $patched);
    }

    // =========================================================================
    // The combined retry instruction
    // =========================================================================

    /** @test */
    public function the_retry_lists_every_violation_with_the_real_choices_and_never_quotes_a_placeholder(): void
    {
        $index = new EntityIdIndex([['id' => 11, '_label' => 'Union']]);
        $validator = new AnswerValidator(
            passages: self::PASSAGES,
            citationsRequired: true,
            evidence: [11493.17],
            keyFigures: [11493.17, 27.0],
            entityIds: $index,
        );
        $draft = 'You owe $22,753.51 ([N] 2). See [Le Jardin](entity://Union/3/view).';

        $instruction = $validator->retryInstruction($validator->validate($draft), $draft);

        $this->assertStringContainsString('ANSWER VALIDATION', $instruction);
        $this->assertStringContainsString('[1], [2] or [3]', $instruction);
        $this->assertStringContainsString('22,753.51 does not appear', $instruction);
        $this->assertStringContainsString('11,493.17', $instruction);
        $this->assertStringContainsString('valid Union ids: 11', $instruction);
        $this->assertStringNotContainsString('[N]', $instruction);
        $this->assertStringContainsString('You owe $22,753.51 [2].', $instruction, 'the draft is quoted with its placeholder normalized');
    }

    // =========================================================================
    // The uncited closing paragraph of a document-grounded answer (eval p19-B #6)
    // =========================================================================

    /** The passages of the p19-B rights turns, shortened (CCQ art. 1054, 1063–1064, 1072, 1094). */
    private const LAW = [
        1 => "**1063.** Each co-owner has the disposal of his fraction; he has free use and enjoyment of his private portion and of the common portions, "
            . "provided he complies with the by-laws of the immovable and does not impair the rights of the other co-owners or the destination of the immovable.\n"
            . "**1064.** Each co-owner contributes to the common expenses in proportion to the relative value of his fraction. The co-owners who use common "
            . 'portions for restricted use contribute alone to the costs of maintenance and ordinary repairs of those portions.',
        2 => "**1072.** Each year, the board of directors, after consultation with the general meeting of the co-owners, fixes their contribution to the "
            . "common expenses, after determining the sums required to meet the expenses arising from the co-ownership and from the operation of the immovable "
            . "and the amounts to be paid into the contingency fund and the self-insurance fund. The syndicate, without delay, notifies each co-owner of the "
            . 'amount of his contribution and the date on which it is payable.',
        3 => "**1094.** A co-owner who has not paid his share of the common expenses or his contribution to the contingency fund or the self-insurance fund "
            . 'for more than three months is deprived of his right to vote.',
        4 => "**1054.** The by-laws of the immovable contain the rules on the enjoyment, use and upkeep of the private and common portions, and those on the "
            . "operation and administration of the co-ownership, including the procedure of assessment and collection of contributions to the common expenses.\n"
            . '*Civil Code of Québec, 1991, c. 64, a. 1054.*',
    ];

    private const RIGHTS_T1 = "As a co-owner in a condominium, you have specific obligations regarding contributions to common expenses. Here are the key points:\n\n"
        . "### Contribution to Common Expenses\n"
        . "- **Proportional Contribution**: Each co-owner is required to contribute to the common expenses based on the relative value of their fraction (art. 1064) [1].\n  \n"
        . "- **Types of Expenses**: This encompasses contributions to contingency and self-insurance funds established by the syndicate (art. 1072) [2].\n\n"
        . "### Non-Compliance Consequences\n"
        . '- **Voting Rights**: If a co-owner fails to pay their share for more than three months, they lose their right to vote (art. 1094) [3].';

    private function law(): AnswerValidator
    {
        return new AnswerValidator(passages: self::LAW, citationsRequired: true);
    }

    /**
     * @test eval p20-B rights t1, replayed: the answer cites every point, then closes with a
     * platitude — against the prompt, in different words every run. A last paragraph without a
     * marker after two cited blocks that is grounded in NOTHING (its content words are neither
     * the passages', nor the cited blocks', nor the question's) is dropped.
     */
    public function p20_b_rights_t1_the_ungrounded_closing_paragraph_is_dropped(): void
    {
        $closing = 'Understanding these obligations will help you fulfill your responsibilities as a co-owner and ensure the '
            . 'smooth operation of your condominium community.';

        [$answer, $dropped] = $this->law()->withoutUncitedClosing(
            self::RIGHTS_T1 . "\n\n" . $closing,
            'Tell me more about my duty to contribute to common expenses.'
        );

        $this->assertSame(self::RIGHTS_T1, $answer);
        $this->assertSame($closing, $dropped);
        $this->assertSame([], $this->law()->validate($answer), 'what ships still cites');
    }

    /**
     * @test The price of keeping every conclusion (p20-A tear-down lost its only one): a closer made
     * of the answer's and the question's own words is GROUNDED and stays — p19-B rights t1's
     * "These points summarize your duty to contribute to common expenses… feel free to ask!" is
     * such a one (measured 6 of 10 content words grounded on the stored turn, 5 of 10 here). No
     * wording tells it from a summary, so it is an accepted cost; the prompt bans it.
     */
    public function p19_b_rights_t1_an_invitation_in_the_answers_own_words_survives(): void
    {
        $answer = self::RIGHTS_T1 . "\n\n"
            . 'These points summarize your duty to contribute to common expenses as a co-owner in your condominium. '
            . 'If you have any specific questions about your contributions or the declaration of co-ownership, feel free to ask!';

        $this->assertSame(
            [$answer, null],
            $this->law()->withoutUncitedClosing($answer, 'Tell me more about my duty to contribute to common expenses.')
        );
    }

    // -- The uncited CONCLUSION of a yes/no law answer (eval p20-A tear-down) -----------------

    private const WALL = 'Can I tear down the wall between my kitchen and living room in my unit?';

    private const ART_1045 = '**1045.** Partitions or walls that are not part of the foundations and main walls of a building but which separate a '
        . 'private portion from a common portion or from another private portion are presumed common.';

    private const ART_1098_1100 = "**1098.** Decisions on the following matters require a majority of three-quarters of the co-owners representing 90% of the "
        . "votes of all the co-owners:\n1°  to change the destination of the immovable;\n2°  to authorize the alienation of common portions the retention "
        . "of which is necessary to maintain the destination of the immovable;\n"
        . '**1100.** The co-owners of contiguous private portions may alter the boundaries between their private portions without obtaining the approval '
        . 'of the general meeting provided they obtain the consent of their hypothecary creditors and of the syndicate. No alteration may increase or '
        . "decrease the relative value of the group of private portions altered or the total of the voting rights attached to them.\n"
        . 'The syndicate amends the declaration of co-ownership and the cadastral plan at the expense of the co-owners contemplated in the first paragraph.';

    private const ART_1066 = '**1066.** No co-owner may interfere with the carrying out, even inside his private portion, of work required for the '
        . 'preservation of the immovable decided upon by the syndicate or of urgent work.';

    private const ART_1056 = '**1056.** No declaration of co-ownership may impose any restriction on the rights of the co-owners except restrictions '
        . 'justified by the destination, characteristics or location of the immovable.';

    /** The p20-A tear-down answer as the model wrote it, before its last paragraph. */
    private const TEAR_DOWN_P20 = "To determine whether you can tear down the wall between your kitchen and living room, it's important to consider the "
        . "following points based on the Quebec Civil Code regarding co-ownership:\n\n"
        . '1. **Private vs. Common Portions**: The wall you wish to remove may be classified as a common portion if it separates a private portion from '
        . "another private portion or from a common portion. According to the law, such walls are presumed to be common [1].\n\n"
        . '2. **Approval Required**: If the wall is indeed a common portion, you would need to obtain the approval of the syndicate (the governing body of '
        . "the co-ownership) and possibly the consent of other co-owners. Major alterations typically require a majority vote among co-owners [2].\n\n"
        . "3. **Boundary Alterations**: If you are altering boundaries between private portions, you can do so without a general meeting's approval, but "
        . "you must still obtain consent from the syndicate and any relevant creditors [2]. \n\n"
        . '4. **Declaration of Co-Ownership**: Check the declaration of co-ownership for any specific restrictions or requirements regarding '
        . 'modifications to your unit [4].';

    /** The p19-A tear-down answer as the model wrote it, before its last paragraph. */
    private const TEAR_DOWN_P19 = "To determine whether you can tear down the wall between your kitchen and living room in your unit, consider the "
        . "following points based on the Quebec Civil Code:\n\n"
        . "1. **Classification of the Wall**: \n   - If the wall is part of your private portion, you may have more flexibility to make changes. "
        . "However, if it separates a private portion from a common portion or another private portion, it is presumed to be a common wall [3].\n\n"
        . "2. **Consent Requirements**: \n   - According to Article 1100, you can alter the boundaries between private portions without needing approval "
        . "from the general meeting, provided you obtain consent from your hypothecary creditors and the syndicate [1].\n\n"
        . "3. **Approval for Common Portions**: \n   - If the wall is deemed a common portion, any alterations would typically require approval from the "
        . "syndicate and possibly other co-owners, especially if the change affects the destination or use of the immovable [1].\n\n"
        . "4. **Legal and Procedural Steps**: \n   - If you proceed with the alteration, you will need to ensure that the syndicate amends the "
        . 'declaration of co-ownership and the cadastral plan at your expense [1].';

    /**
     * @test eval p20-A tear-down, replayed: "In summary, you will likely need to confirm whether the
     * wall is a common portion and seek the necessary approvals…" was the only sentence shaped as
     * an answer to a yes/no question, and the drop removed it. It SUMMARIZES the cited blocks (4 of
     * its 11 content words are theirs or the passages' — the lowest share of the ten stored
     * conclusions): grounded, so it stays.
     */
    public function p20_a_tear_down_the_uncited_conclusion_is_kept(): void
    {
        $validator = new AnswerValidator(
            passages: [1 => self::ART_1045, 2 => self::ART_1098_1100, 3 => self::ART_1066, 4 => self::ART_1056],
            citationsRequired: true
        );
        $answer = self::TEAR_DOWN_P20 . "\n\n"
            . 'In summary, you will likely need to confirm whether the wall is a common portion and seek the necessary approvals before '
            . 'proceeding with any demolition.';

        $this->assertSame([$answer, null], $validator->withoutUncitedClosing($answer, self::WALL));
    }

    /** @test eval p19-A tear-down, replayed: "In summary, whether you can tear down the wall depends on its classification…" stays. */
    public function p19_a_tear_down_the_uncited_conclusion_is_kept(): void
    {
        $validator = new AnswerValidator(
            passages: [1 => self::ART_1098_1100, 2 => self::ART_1066, 3 => self::ART_1045],
            citationsRequired: true
        );
        $answer = self::TEAR_DOWN_P19 . "\n\n"
            . 'In summary, whether you can tear down the wall depends on its classification (private vs. common) and whether you can obtain the '
            . 'necessary consents. It would be advisable to consult with your syndicate for guidance on the specific steps you need to take.';

        $this->assertSame([$answer, null], $validator->withoutUncitedClosing($answer, self::WALL));
    }

    /**
     * @test Advice in the answer's own terms is grounded too (all 5 stored "Before proceeding, it's
     * advisable to consult…" paragraphs are kept) — a platitude after the SAME cited answer is not.
     */
    public function after_the_same_answer_grounded_advice_stays_and_a_platitude_goes(): void
    {
        $validator = new AnswerValidator(
            passages: [1 => self::ART_1045, 2 => self::ART_1098_1100, 3 => self::ART_1066, 4 => self::ART_1056],
            citationsRequired: true
        );
        $advice = self::TEAR_DOWN_P20 . "\n\nBefore proceeding, it's advisable to consult with your syndicate to understand the specific "
            . 'requirements and obtain the necessary permissions.';
        $platitude = 'These precautions are designed to ensure a smooth renovation process and a harmonious environment for everyone involved.';

        $this->assertSame([$advice, null], $validator->withoutUncitedClosing($advice, self::WALL));
        $this->assertSame(
            [self::TEAR_DOWN_P20, $platitude],
            $validator->withoutUncitedClosing(self::TEAR_DOWN_P20 . "\n\n" . $platitude, self::WALL)
        );
    }

    /**
     * @test
     * @dataProvider closers
     */
    public function a_stored_closing_paragraph_is_dropped(string $closing, string $question): void
    {
        [$answer, $dropped] = $this->law()->withoutUncitedClosing(self::RIGHTS_T1 . "\n\n" . $closing, $question);

        $this->assertSame(self::RIGHTS_T1, $answer);
        $this->assertSame($closing, $dropped);
    }

    /** @return array<string, array{0: string, 1: string}> trailing paragraphs of the stored p16–p19 law answers */
    public static function closers(): array
    {
        $rights = 'What are my main rights and duties as a co-owner here?';
        $wall = 'Can I tear down the wall between my kitchen and living room in my unit?';

        return [
            'p19-B rights t0 — a platitude' => ['These rights and duties are designed to ensure the smooth operation and maintenance of the condominium, promoting a harmonious living environment for all co-owners.', $rights],
            'p18-B rights t1 — a platitude' => ['Understanding these obligations is crucial for maintaining good standing within the co-ownership and ensuring the smooth operation of the condominium.', 'Tell me more about my duty to contribute to common expenses.'],
            'p20-B rights t0 — a platitude' => ['These rights and duties are designed to ensure a harmonious living environment and the proper management of the condominium.', $rights],
            'p17-B rights t1 — an invitation' => ['Understanding these obligations is crucial for maintaining the property and ensuring that all co-owners contribute fairly to its upkeep. If you have further questions about specific expenses or your obligations, feel free to ask!', 'Tell me more about my duty to contribute to common expenses.'],
            'a question' => ['Would you like assistance with anything else related to your unit or the renovation process?', $wall],
            'a pointer to nothing of the answer' => ['Next steps are attached below for your convenience.', $rights],
        ];
    }

    /**
     * @test
     * @dataProvider keptEndings
     */
    public function an_ending_that_is_content_or_out_of_the_rules_reach_is_kept(string $answer, string $question = ''): void
    {
        $this->assertSame([$answer, null], $this->law()->withoutUncitedClosing($answer, $question));
    }

    /** @return array<string, array{0: string, 1?: string}> */
    public static function keptEndings(): array
    {
        $cited = "Each co-owner contributes in proportion to the relative value of the fraction [1].\n\nThe syndicate notifies each co-owner of the amount [2].";

        return [
            'the last paragraph cites' => [$cited . "\n\nA co-owner three months late loses the right to vote [3]."],
            'a single cited block before it (a how-to answer and its pointer)' => ["Open the settings page [1].\n\nThen invite the member.\n\nYou can do it from the button below."],
            'two blocks only' => ["The syndicate notifies each co-owner of the amount [2].\n\nThat is all there is to it."],
            'no citation at all' => ["First paragraph.\n\nSecond paragraph.\n\nThird paragraph."],
            'the last block is a list' => [$cited . "\n\n- check the by-laws\n- ask the board"],
            'the last block is a heading' => [$cited . "\n\n### Next"],
            'the last block is a table' => [$cited . "\n\n| a | b |\n| --- | --- |\n| 1 | 2 |"],
            'a paragraph holding a list' => [$cited . "\n\nKeep in mind:\n- the by-laws\n- the board"],
            'an article of the passages (a number they hold)' => [$cited . "\n\nMind the delay of art. 1094 before the next meeting."],
            'a proper noun of the passages' => [
                "Each co-owner contributes in proportion [1].\n\nThe syndicate notifies each co-owner [2].\n\nThe rest is set by the Civil Code of Québec.",
            ],
            'it reads as the passages do (their content words)' => [
                $cited . "\n\nThe board of directors fixes the contribution after consultation with the general meeting, including the amounts paid into the contingency fund.",
            ],
            'p18-A buyer — the direct answer, in the question\'s words the passages do not hold' => [
                $cited . "\n\nIn summary, yes, you would be responsible for the previous owner's unpaid condo fees unless you obtain a statement indicating otherwise before the purchase.",
                "If I buy a unit, am I responsible for the previous owner's unpaid condo fees?",
            ],
            'what the documents lack, named as the question names it' => [
                $cited . "\n\nNothing in these texts covers the legal recovery of unpaid amounts.",
                'What about legal recovery if I do not pay?',
            ],
        ];
    }

    /** @test Without passages there is nothing to judge against: the answer is returned as it came. */
    public function without_passages_no_closing_is_dropped(): void
    {
        $answer = self::RIGHTS_T1 . "\n\nFeel free to ask!";

        $this->assertSame([$answer, null], (new AnswerValidator())->withoutUncitedClosing($answer));
    }

    // =========================================================================
    // StatedNumber::quantities
    // =========================================================================

    /** @test */
    public function quantities_parse_locale_groupings(): void
    {
        $q = StatedNumber::quantities('Total: 11 493,17 $ and 1,500 units, rate 0.125, on 2026-07-23.');

        $this->assertSame([11493.17], $q[0]['values']);
        $this->assertSame(2, $q[0]['decimals']);
        $this->assertTrue($q[0]['money']);
        $this->assertSame([1500.0, 1.5], $q[1]['values']);
        $this->assertSame([0.125], $q[2]['values']);
        $this->assertCount(3, $q, 'the date is not a quantity');
    }
}
