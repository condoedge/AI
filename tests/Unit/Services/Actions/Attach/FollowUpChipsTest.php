<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Attach;

use Condoedge\Ai\DTOs\Digest\DigestSourceResult;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\Attach\ActionAttacher;
use Condoedge\Ai\Services\Actions\Attach\AttachedActions;
use Condoedge\Ai\Services\Actions\BuiltIn\AskFollowUpAction;
use Condoedge\Ai\Tests\TestCase;

/**
 * Follow-up question chips attached by CODE, with no LLM call:
 *
 *  - a clarifying question: one chip per candidate reading (label = the reading,
 *    capitalized; the click sends the reading itself);
 *  - an attention digest: one "go through this section" chip per section that shows only
 *    a part of its records, after the section / record / export chips.
 *
 * And the chip model itself: its own role, never named to the narrator, deduped by
 * question (two questions = two chips), switched by config.
 */
class FollowUpChipsTest extends TestCase
{
    use AttachFixtures;

    public function setUp(): void
    {
        parent::setUp();
        $this->configureCatalog();
    }

    private function attacher(): ActionAttacher
    {
        return new ActionAttacher(new AiActionRegistry());
    }

    /** @param list<string> $readings */
    private function clarifyTurn(array $readings, string $language = 'en'): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = 'q';
        $ctx->qu = new QueryUnderstandingResult(
            questionType: 'data_query',
            language: $language,
            needsClarification: true,
            candidateReadings: $readings,
            clarifyingQuestion: 'Which one do you mean?',
        );

        return $ctx;
    }

    private function digestTurn(string $language = 'en', ?string $exportFormat = null): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = 'q';
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', language: $language, digestRequest: true, exportFormat: $exportFormat);

        return $ctx;
    }

    /**
     * @param list<array<string, mixed>> $invocations
     * @return list<array<string, mixed>> the follow-up chips only
     */
    private function followUps(array $invocations): array
    {
        return array_values(array_filter($invocations, static fn (array $i): bool => $i['key'] === AskFollowUpAction::KEY));
    }

    // ------------------------------------------------------------- the chip model

    /** @test */
    public function follow_up_chips_are_persisted_but_never_named_to_the_narrator(): void
    {
        $ask = AskFollowUpAction::invocation('Who pays for the roof?');
        $attached = new AttachedActions([
            ['role' => AttachedActions::ROLE_NEXT, 'invocation' => new ActionInvocation('meetings', null, null, [], 'Meetings')],
            ['role' => AttachedActions::ROLE_FOLLOW_UP, 'invocation' => $ask],
        ]);

        $this->assertSame(['Meetings'], $attached->labels());
        $this->assertStringNotContainsString('roof', $attached->narratorInstruction());
        $this->assertSame(['meetings', 'ask_follow_up'], array_column($attached->invocations(), 'key'));
        $this->assertSame(
            ['role' => 'follow_up', 'key' => 'ask_follow_up', 'text' => 'Who pays for the roof?'],
            $attached->summary()[1]
        );
    }

    /** @test Only follow-up chips: the narrator is told nothing is attached (it names no button). */
    public function only_follow_up_chips_leave_the_narrator_with_nothing_to_point_at(): void
    {
        $attached = new AttachedActions([
            ['role' => AttachedActions::ROLE_FOLLOW_UP, 'invocation' => AskFollowUpAction::invocation('Who pays for the roof?')],
        ]);

        $this->assertFalse($attached->isEmpty());
        $this->assertSame(AttachedActions::NARRATOR_NONE_LINE, $attached->narratorInstruction());
    }

    /** @test */
    public function chips_added_later_keep_the_existing_ones_and_dedupe_by_fingerprint(): void
    {
        $open = new ActionInvocation('view', 'Unit', '159', [], 'Unit 310');
        $roof = ['role' => AttachedActions::ROLE_FOLLOW_UP, 'invocation' => AskFollowUpAction::invocation('Who pays for the roof?')];
        $windows = ['role' => AttachedActions::ROLE_FOLLOW_UP, 'invocation' => AskFollowUpAction::invocation('Who pays for the windows?')];
        $attached = new AttachedActions([['role' => AttachedActions::ROLE_RECORD, 'invocation' => $open], $roof], [1 => $open]);

        $more = $attached->with([$roof, $windows]);

        $this->assertSame(['Unit 310', 'Who pays for the roof?', 'Who pays for the windows?'], array_column($more->invocations(), 'text'));
        $this->assertTrue($more->hasRecordRefs(), 'the inline record references survive');
        $this->assertCount(2, $attached->invocations(), 'the original is unchanged');
    }

    /** @test The ONE place the response metadata of every branch gets its chips. */
    public function apply_to_writes_the_invocations_and_their_summary(): void
    {
        $attached = new AttachedActions([
            ['role' => AttachedActions::ROLE_FOLLOW_UP, 'invocation' => AskFollowUpAction::invocation('Who pays for the roof?')],
        ]);

        $metadata = $attached->applyTo(['style' => 'friendly']);

        $this->assertSame('friendly', $metadata['style']);
        $this->assertSame($attached->invocations(), $metadata['invocations']);
        $this->assertSame($attached->summary(), $metadata['attached_actions']);

        $this->assertSame(['style' => 'friendly'], AttachedActions::none()->applyTo(['style' => 'friendly']), 'nothing attached: untouched');
    }

    // ------------------------------------------------------------- clarify

    /** @test */
    public function each_reading_of_a_clarifying_question_becomes_a_chip(): void
    {
        $ctx = $this->clarifyTurn(['the balance of your own unit', 'the receivables of the whole building']);

        $attached = $this->attacher()->forClarification($ctx, ['user' => $this->resident()]);

        $this->assertSame([
            [
                'key' => 'ask_follow_up', 'entity_type' => null, 'entity_id' => null,
                'params' => ['question' => 'the balance of your own unit'],
                'text' => 'The balance of your own unit',
            ],
            [
                'key' => 'ask_follow_up', 'entity_type' => null, 'entity_id' => null,
                'params' => ['question' => 'the receivables of the whole building'],
                'text' => 'The receivables of the whole building',
            ],
        ], $attached->invocations());
        $this->assertSame(['follow_up', 'follow_up'], array_column($attached->summary(), 'role'));
        $this->assertSame([], $attached->labels(), 'nothing the narrator could point at');
    }

    /** @test The first letter is upper-cased as a letter of its script, not as a byte. */
    public function a_reading_is_capitalized_unicode_aware(): void
    {
        $ctx = $this->clarifyTurn(['état des comptes de la copropriété', 'Solde de mon unité', '310 — le solde'], 'fr');

        $labels = array_column($this->attacher()->forClarification($ctx, ['user' => $this->resident()])->invocations(), 'text');

        $this->assertSame(['État des comptes de la copropriété', 'Solde de mon unité', '310 — le solde'], $labels);
    }

    /** @test Every reading the question names gets its chip: the suggestions cap does not cut the choices. */
    public function readings_are_not_cut_by_the_suggestions_cap(): void
    {
        config(['ai.attached_actions.max_follow_ups' => 2]);
        $ctx = $this->clarifyTurn(['reading one', 'reading two', 'reading three', 'reading four']);

        $this->assertCount(4, $this->attacher()->forClarification($ctx, ['user' => $this->resident()])->invocations());
    }

    /** @test */
    public function blank_markup_only_and_repeated_readings_give_no_extra_chip(): void
    {
        $ctx = $this->clarifyTurn(['the balance of a unit', '  ', '**', 'The balance of a unit', "the balance\nof a unit"]);

        $questions = array_column(array_column($this->attacher()->forClarification($ctx, ['user' => $this->resident()])->invocations(), 'params'), 'question');

        $this->assertSame(['the balance of a unit'], $questions);
    }

    /** @test */
    public function a_guest_gets_no_reading_chip(): void
    {
        $ctx = $this->clarifyTurn(['reading one', 'reading two']);

        $this->assertTrue($this->attacher()->forClarification($ctx, [])->isEmpty());
    }

    /**
     * @test
     * @dataProvider switchesOff
     */
    public function a_switch_turns_the_reading_chips_off(array $config): void
    {
        config($config);
        $ctx = $this->clarifyTurn(['reading one', 'reading two']);

        $this->assertTrue($this->attacher()->forClarification($ctx, ['user' => $this->resident()])->isEmpty());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function switchesOff(): array
    {
        return [
            'attached actions off' => [['ai.attached_actions.enabled' => false]],
            'follow-ups off' => [['ai.attached_actions.follow_ups.enabled' => false]],
            'this source off' => [['ai.attached_actions.follow_ups.clarify' => false]],
        ];
    }

    /** @test The other sources' switches do not touch the readings. */
    public function another_source_switch_leaves_the_reading_chips(): void
    {
        config(['ai.attached_actions.follow_ups.knowledge' => false, 'ai.attached_actions.follow_ups.digest' => false]);
        $ctx = $this->clarifyTurn(['reading one', 'reading two']);

        $this->assertCount(2, $this->attacher()->forClarification($ctx, ['user' => $this->resident()])->invocations());
    }

    // ------------------------------------------------------------- digest

    /** @return list<DigestSourceResult> */
    private function sources(): array
    {
        $row = static fn (int $id): array => ['id' => $id, 'name' => 'Item ' . $id];

        return [
            // Shows 2 of 130: truncated.
            new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [$row(1), $row(2)], rowCount: 2, trueTotal: 130, capHit: true, actionKey: 'support'),
            // Complete: all shown.
            new DigestSourceResult(key: 'meetings', label: 'Upcoming meetings', rows: [['id' => 140, 'name' => 'AGM']], rowCount: 1, actionKey: 'meetings', entity: 'Meeting'),
            // Fallback rows, cap hit and total unknown: more exist than shown.
            new DigestSourceResult(key: 'bills', label: 'Bills to pay', rows: [$row(7)], rowCount: 1, capHit: true, usedFallback: true, fallbackLabel: 'Most recent bills'),
            // Failed, and plain empty: not sections.
            new DigestSourceResult(key: 'broken', label: 'Broken', trueTotal: 50, error: 'boom'),
            new DigestSourceResult(key: 'empty', label: 'Nothing here', rows: [], rowCount: 0, trueTotal: 0),
            new DigestSourceResult(key: 'insurance', label: 'Expired insurance', rows: [$row(9)], rowCount: 1, trueTotal: 189, capHit: true),
            new DigestSourceResult(key: 'requests', label: 'Pending requests', rows: [$row(11)], rowCount: 1, trueTotal: 12, capHit: true),
        ];
    }

    /** @test */
    public function each_truncated_section_gets_a_go_through_chip_after_the_other_chips(): void
    {
        $attached = $this->attacher()->forDigest($this->digestTurn('en', 'excel'), $this->sources(), ['user' => $this->manager()]);
        $invocations = $attached->invocations();

        $this->assertSame([
            'support|||Open tasks',
            'meetings|||Upcoming meetings',
            'view|Meeting|140|AGM',
            'export_excel|||Download Excel',
            'ask_follow_up|||Go through Open tasks',
            'ask_follow_up|||Go through Most recent bills',
            'ask_follow_up|||Go through Expired insurance',
        ], $this->chipLines($invocations), 'registry order, the fallback section under its fallback label, capped at 3');

        $this->assertSame(
            ['Go through Open tasks', 'Go through Most recent bills', 'Go through Expired insurance'],
            array_column(array_column($this->followUps($invocations), 'params'), 'question'),
            'the click sends the same sentence'
        );
        $this->assertSame(['Open tasks', 'Upcoming meetings', 'AGM', 'Download Excel'], $attached->labels(), 'the composer is told about the page buttons only');
    }

    /** @test */
    public function the_cap_is_configurable_and_zero_means_none(): void
    {
        config(['ai.attached_actions.max_follow_ups' => 1]);
        $one = $this->attacher()->forDigest($this->digestTurn(), $this->sources(), ['user' => $this->manager()]);
        $this->assertSame(['Go through Open tasks'], array_column($this->followUps($one->invocations()), 'text'));

        config(['ai.attached_actions.max_follow_ups' => 0]);
        $none = $this->attacher()->forDigest($this->digestTurn(), $this->sources(), ['user' => $this->manager()]);
        $this->assertSame([], $this->followUps($none->invocations()));
    }

    /** @test The sentence follows the QUESTION's language; the section label is the host's own. */
    public function the_section_follow_up_is_worded_in_the_questions_language(): void
    {
        $attached = $this->attacher()->forDigest($this->digestTurn('fr'), $this->sources(), ['user' => $this->manager()]);

        $this->assertSame(
            ['Passer en revue : Open tasks', 'Passer en revue : Most recent bills', 'Passer en revue : Expired insurance'],
            array_column($this->followUps($attached->invocations()), 'text')
        );
    }

    /** @test */
    public function a_digest_whose_sections_are_all_complete_gets_no_follow_up(): void
    {
        $sources = [
            new DigestSourceResult(key: 'meetings', label: 'Upcoming meetings', rows: [['id' => 140, 'name' => 'AGM']], rowCount: 1, trueTotal: 1, actionKey: 'meetings', entity: 'Meeting'),
        ];

        $attached = $this->attacher()->forDigest($this->digestTurn(), $sources, ['user' => $this->manager()]);

        $this->assertSame(['meetings', 'view'], array_column($attached->invocations(), 'key'));
    }

    /**
     * @test
     * @dataProvider digestSwitchesOff
     */
    public function a_switch_turns_the_section_follow_ups_off_and_keeps_the_other_chips(array $config): void
    {
        config($config);

        $attached = $this->attacher()->forDigest($this->digestTurn(), $this->sources(), ['user' => $this->manager()]);

        $this->assertSame(['support', 'meetings', 'view'], array_column($attached->invocations(), 'key'));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function digestSwitchesOff(): array
    {
        return [
            'follow-ups off' => [['ai.attached_actions.follow_ups.enabled' => false]],
            'this source off' => [['ai.attached_actions.follow_ups.digest' => false]],
        ];
    }

    /** @test A section label is host text: whatever it holds, the chip is one plain line. */
    public function a_section_label_with_markup_is_sanitized(): void
    {
        $sources = [
            new DigestSourceResult(key: 'x', label: "**Open**\n[tasks](https://evil.example)", rows: [['id' => 1]], rowCount: 1, trueTotal: 9),
        ];

        $attached = $this->attacher()->forDigest($this->digestTurn(), $sources, ['user' => $this->manager()]);

        $this->assertSame(['Go through Open tasks'], array_column($this->followUps($attached->invocations()), 'text'));
    }
}
