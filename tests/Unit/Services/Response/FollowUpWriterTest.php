<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\Response\FollowUpWriter;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\Fixtures\ScriptedUsageLlm;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The focused "what would the user ask next?" call of a document-grounded answer: ONE
 * small LLM call made after composition.
 *
 * CODE chooses what a question may be about — the EXCERPTS: pieces of the retrieved
 * passages the answer did not cover (an unused passage, an article of a used passage the
 * answer did not name). The model writes, per excerpt, the fact it states and the question
 * that asks for it. CODE then verifies: the fact is really stated by an excerpt (that is
 * the passage — and article — the chip carries, whatever slot the model wrote it in), the
 * question is one question-like line, and it repeats neither the user's question nor
 * another one.
 *
 * WHO judges that a question is on the user's matter is a mode of the writer: by default
 * the MODEL (it leaves an excerpt empty rather than write a tangential question — the
 * model of the `follow_up_writer` role can make that judgment); in the code-gated mode,
 * for a model that cannot, every excerpt gets a question and CODE gates (sources the
 * user's own wording retrieved, a minimum similarity to the user's question).
 *
 * White-label: the package prompt holds no domain vocabulary; a deliberately non-host
 * corpus (an aviation maintenance manual) is used here.
 */
class FollowUpWriterTest extends TestCase
{
    private const QUESTION = 'What must be checked before each flight?';

    /** Cites passage 1 and names article 401 only: 402 is new, passages 2-4 were not used. */
    private const ANSWER = 'Before each flight the operator inspects the aircraft (art. 401) [1]. '
        . 'Any defect found is entered in the technical log [1].';

    /** Cites passage 1 without naming an article and carries both of its articles: all of it is covered. */
    private const ANSWER_COVERING_PASSAGE_1 = 'Before each flight the operator inspects the aircraft, and a component past its service life '
        . 'is replaced before the next departure [1].';

    /** Cites passage 1 for art. 401 and carries the FIRST paragraph of {@see self::passagesWithParagraphs()} only. */
    private const ANSWER_FIRST_PARAGRAPH = 'Before each flight the operator inspects the aircraft (art. 401) [1]. '
        . 'Every defect found is entered in the technical log [1].';

    private const SECOND_PARAGRAPH = 'A flight may depart with an open defect only when the commander accepted it in writing during the hour before departure.';

    /**
     * What each excerpt of the fixture states (with self::ANSWER the excerpts are, in the
     * order offered: E1 art. 402 of [1] · E2 art. 410 of [2] · E3 art. 520 of [3] ·
     * E4 passage [4]) — and art. 411 of [2], a fifth excerpt that is not offered.
     */
    private const F402 = 'A component past its service life is replaced';
    private const F410 = 'A defect may be deferred only when the list allows it';
    private const F520 = 'Maintenance records are kept for five years';
    private const FTOOLS = 'Torque wrenches are calibrated every twelve months';
    private const F411 = 'A deferred defect is rectified within the interval';

    private const Q402 = 'When is a component replaced whatever its condition?';
    private const Q410 = 'When may a defect be deferred?';
    private const Q520 = 'How long are maintenance records kept?';
    private const QTOOLS = 'How often are torque wrenches calibrated?';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('logging.default', 'null');
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function passages(): array
    {
        return [
            [
                'file_id' => 7, 'chunk_index' => 3, 'filename' => 'maintenance-manual.md', 'heading' => 'Part 4 › Line maintenance',
                'snippet' => "**401.** The operator inspects the aircraft before each flight.\n\n"
                    . "**402.** A component past its service life is replaced before the next departure, whatever its apparent condition.",
            ],
            [
                'file_id' => 7, 'chunk_index' => 9, 'filename' => 'maintenance-manual.md', 'heading' => 'Part 4 › Deferred defects',
                'snippet' => "**410.** A defect may be deferred only when the minimum equipment list allows it.\n\n"
                    . '**411.** A deferred defect is rectified within the interval the list sets.',
            ],
            [
                'file_id' => 7, 'chunk_index' => 14, 'filename' => 'maintenance-manual.md', 'heading' => 'Part 5 › Records',
                'snippet' => '**520.** Maintenance records are kept for five years after the work was done.',
            ],
            [
                'file_id' => 8, 'chunk_index' => 0, 'filename' => 'hangar-guide.md', 'heading' => 'Hangar guide › Tools',
                'snippet' => 'Torque wrenches are calibrated every twelve months and tagged with the date of their last calibration.',
            ],
        ];
    }

    /**
     * A reply: slot number => [the fact the model copied from the excerpt, its question].
     *
     * @param array<int, array{0: string, 1: string}> $slots
     * @return array<string, string>
     */
    private function reply(array $slots): array
    {
        $reply = [];
        foreach ($slots as $n => [$fact, $question]) {
            $reply["f{$n}"] = $fact;
            $reply["q{$n}"] = $question;
        }

        return $reply;
    }

    /** @param array<string, mixed> $reply */
    private function write(array $reply, ?string $answer = null, int $max = 3, string $question = self::QUESTION): array
    {
        return (new FollowUpWriter(new ScriptedUsageLlm(json: $reply)))
            ->write($question, $answer ?? self::ANSWER, $this->passages(), 'en', null, $max);
    }

    private function userPrompt(string $answer, ?array $passages = null, bool $modelDeclines = true): string
    {
        $llm = new ScriptedUsageLlm(json: []);
        (new FollowUpWriter($llm, null, $modelDeclines))->write(self::QUESTION, $answer, $passages ?? $this->passages(), 'en');

        return $llm->calls[0][1]['content'];
    }

    // ------------------------------------------------------------- the call

    /** @test */
    public function it_returns_the_questions_with_the_passage_and_article_each_came_from(): void
    {
        $llm = new ScriptedUsageLlm(json: $this->reply([
            1 => [self::F402, self::Q402],
            2 => [self::F410, self::Q410],
            3 => [self::F520, self::Q520],
        ]));

        $written = (new FollowUpWriter($llm))->write(self::QUESTION, self::ANSWER, $this->passages(), 'en');

        $this->assertSame([
            ['question' => self::Q402, 'passage' => 1, 'article' => 402],
            ['question' => self::Q410, 'passage' => 2, 'article' => 410],
            ['question' => self::Q520, 'passage' => 3, 'article' => 520],
        ], $written['follow_ups']);
        $this->assertSame(3, $written['proposed']);
        $this->assertSame([], $written['dropped']);
        $this->assertCount(1, $llm->calls, 'exactly ONE LLM call');
    }

    /** @test A passage without article numbers is the unit itself: no article in the evidence. */
    public function a_question_on_a_passage_without_articles_carries_its_passage_only(): void
    {
        $written = $this->write($this->reply([4 => [self::FTOOLS, self::QTOOLS]]));

        $this->assertSame([['question' => self::QTOOLS, 'passage' => 4]], $written['follow_ups']);
    }

    /** @test A small deterministic call: temperature 0, a low output budget. */
    public function the_call_is_small_and_deterministic(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->withArgs(function (array $messages, array $options): bool {
            return $options['temperature'] === 0.0 && $options['max_tokens'] <= 300
                && array_column($messages, 'role') === ['system', 'user'];
        })->andReturn([]);

        $written = (new FollowUpWriter($llm))->write(self::QUESTION, self::ANSWER, $this->passages(), 'en');

        // ('excerpts': what the call was shown — trace evidence, pinned where the tiers are.)
        $this->assertSame(['follow_ups' => [], 'proposed' => 0, 'dropped' => []], array_diff_key($written, ['excerpts' => true]));
    }

    /** @test */
    public function the_prompt_gives_the_question_the_answer_and_the_excerpts_the_answer_did_not_cover(): void
    {
        $llm = new ScriptedUsageLlm(json: []);
        (new FollowUpWriter($llm))->write(self::QUESTION, self::ANSWER, $this->passages(), 'en');

        [$system, $user] = array_column($llm->calls[0], 'content');

        $this->assertSame(implode("\n", [
            'QUESTION (written in "en"): What must be checked before each flight?',
            'ANSWER: Before each flight the operator inspects the aircraft (art. 401) [1]. Any defect found is entered in the technical log [1].',
            'EXCERPTS:',
            // The used passage [1]: only the article the answer did not name (402, not 401).
            'E1 — maintenance-manual.md — Part 4 › Line maintenance — art. 402',
            '    "402. A component past its service life is replaced before the next departure, whatever its apparent condition."',
            // The unused passages, best-ranked first, one excerpt each. (A passage's second
            // article — 411 here — would come after every first one: beyond the four offered.)
            'E2 — maintenance-manual.md — Part 4 › Deferred defects — art. 410',
            '    "410. A defect may be deferred only when the minimum equipment list allows it."',
            'E3 — maintenance-manual.md — Part 5 › Records — art. 520',
            '    "520. Maintenance records are kept for five years after the work was done."',
            'E4 — hangar-guide.md — Hangar guide › Tools',
            '    "Torque wrenches are calibrated every twelve months and tagged with the date of their last calibration."',
            'Write f1, q1, f2, q2, f3, q3, f4, q4 — the questions in "en".',
        ]), $user);

        // Flat reply keys, one pair per excerpt (nested lists were mis-filled by the small
        // model in this codebase): the fact first, then the question that asks for it.
        $this->assertStringContainsString('Return ONLY JSON of this exact form: {"f1":"…","q1":"…","f2":"…","q2":"…","f3":"…","q3":"…","f4":"…","q4":"…"}', $system);
        // The model judges: an excerpt off the user's matter gets no question at all.
        $this->assertStringContainsString('Write a question ONLY when it is a natural next question for THIS user', $system);
        $this->assertStringContainsString('leave its "f" and "q" empty ("")', $system);
        $this->assertStringContainsString('Better no question than a tangential one', $system);
        $this->assertStringContainsString('leaving every excerpt empty is a correct reply', $system);
        $this->assertStringNotContainsString('every key filled', $system);
        $this->assertStringContainsString('For EACH excerpt', $system);
        $this->assertStringContainsString('COPIED from the excerpt', $system);
        $this->assertStringContainsString('at most 14 words', $system);
        $this->assertStringContainsString('that the answer did NOT use', $system);
        $this->assertStringNotContainsString('USED excerpt', $system);
    }

    /** @test A passage the answer cited without an article number and carries whole is covered whole: no excerpt of it. */
    public function a_fully_covered_passage_gives_no_excerpt_when_new_material_exists(): void
    {
        $user = $this->userPrompt(self::ANSWER_COVERING_PASSAGE_1);

        $this->assertStringNotContainsString('Line maintenance', $user);
        $this->assertStringContainsString("EXCERPTS:\nE1 — maintenance-manual.md — Part 4 › Deferred defects — art. 410", $user);
        // With a slot left, the second article of a passage is offered after the first ones.
        $this->assertStringContainsString("E4 — maintenance-manual.md — Part 4 › Deferred defects — art. 411\n    \"411. A deferred defect is rectified", $user);

        // No question can point at the covered passage: its text is in no excerpt.
        $written = $this->write($this->reply([
            1 => ['The operator inspects the aircraft before each flight', 'Who inspects the aircraft before a flight?'],
            2 => [self::F402, self::Q402],
            3 => [self::F411, 'When is a deferred defect rectified?'],
        ]), self::ANSWER_COVERING_PASSAGE_1);

        $this->assertSame([['question' => 'When is a deferred defect rectified?', 'passage' => 2, 'article' => 411]], $written['follow_ups']);
        $this->assertSame(['unsupported' => 2], $written['dropped']);
    }

    /**
     * @test The articles the answer names are read by the ONE shared reference grammar
     * (ArticleLocator::referencedArticles — the same reading the next turn's deepening
     * contract gets): a punctuated list or a range after an article marker.
     */
    public function articles_the_answer_names_in_a_list_or_a_range_are_covered(): void
    {
        foreach (['(art. 401; 402) [1]', '(art. 401, 402) [1]', '(arts. 401–402) [1]', '(art. 401 & 402) [1]'] as $citation) {
            $user = $this->userPrompt("The operator inspects the aircraft and replaces components {$citation}.");

            $this->assertStringNotContainsString('art. 402', $user, $citation);
            $this->assertStringContainsString("EXCERPTS:\nE1 — maintenance-manual.md — Part 4 › Deferred defects — art. 410", $user, $citation);
        }

        // No grammar of its own here: a number the shared grammar does not read as a
        // reference (a word ends its list; a bare number is a quantity) is not covered.
        foreach (['(articles 401 and 402) [1]', '(art. 401) in 402 cases [1]'] as $citation) {
            $this->assertStringContainsString(
                "EXCERPTS:\nE1 — maintenance-manual.md — Part 4 › Line maintenance — art. 402",
                $this->userPrompt("The operator inspects the aircraft and replaces components {$citation}."),
                $citation
            );
        }
    }

    /**
     * @test Nothing new was retrieved at all: the passages the answer used are offered,
     * marked USED — a question may only be about what the answer left out of them.
     */
    public function used_passages_are_offered_only_when_nothing_new_exists(): void
    {
        $passages = array_slice($this->passages(), 0, 2);
        $llm = new ScriptedUsageLlm(json: $this->reply([
            1 => [self::F402, self::Q402],
            2 => [self::F411, 'When is a deferred defect rectified?'],
        ]));

        $answer = 'The operator inspects the aircraft and replaces expired components (art. 401, 402) [1]; some defects may wait (art. 410, 411) [2].';
        $written = (new FollowUpWriter($llm))->write(self::QUESTION, $answer, $passages, 'en');

        [$system, $user] = array_column($llm->calls[0], 'content');
        $this->assertStringContainsString("E1 — USED — Part 4 › Line maintenance\n    \"401. The operator inspects the aircraft before each flight. 402. A component", $user);
        $this->assertStringContainsString('E2 — USED — Part 4 › Deferred defects', $user);
        $this->assertStringContainsString('for a USED excerpt something the ANSWER left out', $system);

        $this->assertSame([
            ['question' => self::Q402, 'passage' => 1],
            ['question' => 'When is a deferred defect rectified?', 'passage' => 2],
        ], $written['follow_ups']);
    }

    // ------------------------------------------------------------- what the conversation covered

    /**
     * @test The turn's own deepening contract: an article an EARLIER answer of the
     * conversation cited is not new, whatever this answer names — and a passage without
     * article numbers that an earlier answer cited is not new either. Live (eval p19-B
     * rights t1): the chip re-asked what the first turn had answered, and the slot spent on
     * that article kept an on-topic one out.
     */
    public function what_an_earlier_answer_of_the_conversation_covered_gives_no_excerpt(): void
    {
        $covered = [
            // The conversation memory's records (`last_referenced_files`): file, chunk, cited articles.
            ['id' => 7, 'chunk_index' => 9, 'cited_articles' => [410]],
            ['id' => 8, 'chunk_index' => 0],
        ];
        $llm = new ScriptedUsageLlm(json: $this->reply([
            1 => [self::F402, self::Q402],
            // The covered article's text is in no excerpt: nothing can point at it.
            2 => [self::F410, self::Q410],
            3 => [self::F411, 'When is a deferred defect rectified?'],
        ]));

        $written = (new FollowUpWriter($llm))->write(self::QUESTION, self::ANSWER, $this->passages(), 'en', null, 3, '', 0.0, $covered);

        $user = $llm->calls[0][1]['content'];
        $this->assertStringContainsString("EXCERPTS:\nE1 — maintenance-manual.md — Part 4 › Line maintenance — art. 402", $user);
        $this->assertStringContainsString('E2 — maintenance-manual.md — Part 4 › Deferred defects — art. 411', $user);
        $this->assertStringContainsString('E3 — maintenance-manual.md — Part 5 › Records — art. 520', $user);
        $this->assertStringNotContainsString('art. 410', $user);
        $this->assertStringNotContainsString('Torque wrenches', $user);
        $this->assertStringEndsWith('Write f1, q1, f2, q2, f3, q3 — the questions in "en".', $user);

        $this->assertSame([
            ['question' => self::Q402, 'passage' => 1, 'article' => 402],
            ['question' => 'When is a deferred defect rectified?', 'passage' => 2, 'article' => 411],
        ], $written['follow_ups']);
        $this->assertSame(['unsupported' => 1], $written['dropped']);
    }

    /** @test Everything retrieved was covered earlier in the conversation or by this answer: nothing is offered as new. */
    public function a_turn_whose_every_passage_the_conversation_covered_offers_only_used_passages(): void
    {
        $passages = array_slice($this->passages(), 0, 2);
        $covered = [['id' => 7, 'chunk_index' => 9, 'cited_articles' => [410, 411]]];

        $llm = new ScriptedUsageLlm(json: []);
        (new FollowUpWriter($llm))->write(self::QUESTION, 'The operator inspects the aircraft (art. 401, 402) [1].', $passages, 'en', null, 3, '', 0.0, $covered);

        $user = $llm->calls[0][1]['content'];
        $this->assertStringContainsString('E1 — USED — Part 4 › Line maintenance', $user);
        $this->assertStringNotContainsString('Deferred defects', $user, 'a passage the conversation covered is never brought back');
    }

    // ------------------------------------------------------------- paragraphs of a cited article

    /**
     * The fixture with a two-paragraph article 401 (and a note line, which is not article
     * text): the first paragraph is what self::ANSWER_FIRST_PARAGRAPH tells.
     *
     * @return list<array<string, mixed>>
     */
    private function passagesWithParagraphs(): array
    {
        $passages = $this->passages();
        $passages[0]['snippet'] = "**401.** The operator inspects the aircraft before each flight and enters every defect found in the technical log.\n"
            . self::SECOND_PARAGRAPH . "\n"
            . "*Amended 2019, s. 4.*\n…\n"
            . '**402.** A component past its service life is replaced before the next departure, whatever its apparent condition.';

        return $passages;
    }

    /**
     * @test A cited article is not used as a whole: a paragraph of it whose content the
     * answer does not carry is the most on-topic new material there is — it is offered
     * first, before the new articles of the cited passage and before the unused passages.
     * Live (eval p19-A buyer): the second paragraph of the cited article was exactly the
     * next thing to ask, and could never become a chip.
     */
    public function a_paragraph_of_a_cited_article_the_answer_does_not_carry_is_offered_first(): void
    {
        $llm = new ScriptedUsageLlm(json: $this->reply([
            1 => ['A flight may depart with an open defect only when the commander accepted it', 'When may a flight depart with an open defect?'],
        ]));

        $written = (new FollowUpWriter($llm))->write(self::QUESTION, self::ANSWER_FIRST_PARAGRAPH, $this->passagesWithParagraphs(), 'en');

        $this->assertStringContainsString(implode("\n", [
            'EXCERPTS:',
            // The unused paragraph of the cited article — its own text, without the note line.
            'E1 — maintenance-manual.md — Part 4 › Line maintenance — art. 401',
            '    "' . self::SECOND_PARAGRAPH . '"',
            // Then the new article of the cited passage, then the unused passages.
            'E2 — maintenance-manual.md — Part 4 › Line maintenance — art. 402',
            '    "402. A component past its service life is replaced before the next departure, whatever its apparent condition."',
            'E3 — maintenance-manual.md — Part 4 › Deferred defects — art. 410',
            '    "410. A defect may be deferred only when the minimum equipment list allows it."',
            'E4 — maintenance-manual.md — Part 5 › Records — art. 520',
        ]), $llm->calls[0][1]['content']);

        $this->assertSame(
            [['question' => 'When may a flight depart with an open defect?', 'passage' => 1, 'article' => 401]],
            $written['follow_ups']
        );
    }

    /** @test An article whose every paragraph the answer carries has nothing left to offer. */
    public function a_cited_article_the_answer_carries_whole_gives_no_paragraph(): void
    {
        $answer = self::ANSWER_FIRST_PARAGRAPH . ' A flight may depart with an open defect when the commander accepted it in writing during the hour before departure [1].';

        $user = $this->userPrompt($answer, $this->passagesWithParagraphs());

        $this->assertStringContainsString("EXCERPTS:\nE1 — maintenance-manual.md — Part 4 › Line maintenance — art. 402", $user);
        $this->assertStringNotContainsString('— art. 401', $user);
    }

    /**
     * @test A paragraph told in OTHER WORD FORMS is carried. Eval p22-B rights t1: the answer
     * said "You will be notified of the amount due and the payment date"; the paragraph says
     * "The syndicate, without delay, notifies each co-owner of the amount of his contribution
     * and the date when it is payable" — five of its eleven words were the answer's, one short
     * of half, "notifies" was not "notified", and the chip re-asked what the answer had said.
     * Two words of seven letters or more that begin with the same seven are one word here.
     */
    public function a_paragraph_the_answer_tells_in_other_word_forms_is_not_offered_again(): void
    {
        $told = 'The dispatcher, without delay, notifies each commander of the quantity of fuel loaded and the slot when departure is authorized.';
        $passages = $this->passages();
        $passages[0]['snippet'] = "**401.** The operator inspects the aircraft before each flight and enters every defect found in the technical log.\n"
            . $told . "\n…\n"
            . '**402.** A component past its service life is replaced before the next departure, whatever its apparent condition.';

        // 6 of the paragraph's 13 words as written (each, commander, quantity, fuel, slot,
        // departure) — and "notified" for "notifies": 7 of 13.
        $answer = self::ANSWER_FIRST_PARAGRAPH . ' The commander is notified of the fuel quantity and of the departure slot [1].';
        $user = $this->userPrompt($answer, $passages);

        $this->assertStringNotContainsString($told, $user);
        $this->assertStringContainsString("EXCERPTS:\nE1 — maintenance-manual.md — Part 4 › Line maintenance — art. 402", $user);

        // The same answer without that sentence: the paragraph is the first thing to offer.
        $this->assertStringContainsString(
            "EXCERPTS:\nE1 — maintenance-manual.md — Part 4 › Line maintenance — art. 401\n    \"" . $told . '"',
            $this->userPrompt(self::ANSWER_FIRST_PARAGRAPH, $passages)
        );
    }

    /**
     * @test A paragraph whose FIGURE the answer never states has not been told, however many of
     * its words the answer shares. Eval p24-A buyer: the answer shared 16 of the 29 words of
     * art. 1069's second paragraph (one over half, by the word forms above) but never said its
     * "15 days" — and the one chip leading to the rule the answer left out was withdrawn.
     * A figure is a numeric token of the paragraph that is not an article reference.
     */
    public function a_paragraph_whose_figure_the_answer_does_not_state_is_still_offered(): void
    {
        $withFigure = 'The dispatcher, within 15 days, notifies each commander of the quantity of fuel loaded and the slot when departure is authorized under article 402.';
        $passages = $this->passages();
        $passages[0]['snippet'] = "**401.** The operator inspects the aircraft before each flight and enters every defect found in the technical log.\n"
            . $withFigure . "\n…\n"
            . '**402.** A component past its service life is replaced before the next departure, whatever its apparent condition.';

        // The same sentence that carried the paragraph in the test above — without the figure.
        $answer = self::ANSWER_FIRST_PARAGRAPH . ' The commander is notified of the fuel quantity and of the departure slot [1].';
        $this->assertStringContainsString(
            "EXCERPTS:\nE1 — maintenance-manual.md — Part 4 › Line maintenance — art. 401\n    \"" . $withFigure . '"',
            $this->userPrompt($answer, $passages)
        );

        // With the figure stated (the article reference "402" is no figure and is not required),
        // the paragraph is told and not offered again.
        $told = self::ANSWER_FIRST_PARAGRAPH . ' The commander is notified within 15 days of the fuel quantity and of the departure slot [1].';
        $this->assertStringNotContainsString($withFigure, $this->userPrompt($told, $passages));
    }

    /**
     * @test …but words that only START alike are not one word. Measured on the eval p19–p22
     * law turns: at seven letters every pair matched is a form of one word (notifies /
     * notified, operation / operating, establishes / establishing); at six "government"
     * meets "govern", at five "under" meets "understand" and "thereupon" meets "therefore".
     */
    public function words_that_only_begin_alike_do_not_carry_a_paragraph(): void
    {
        // department / departure, writers / writing, accounts / accepted, commitment / commander, opening / open.
        $answer = self::ANSWER_FIRST_PARAGRAPH . ' The department writers keep accounts of every commitment since the opening [1].';

        $user = $this->userPrompt($answer, $this->passagesWithParagraphs());

        $this->assertStringContainsString(
            "EXCERPTS:\nE1 — maintenance-manual.md — Part 4 › Line maintenance — art. 401\n    \"" . self::SECOND_PARAGRAPH . '"',
            $user
        );
    }

    /**
     * @test The word test must SEE the article before it says a paragraph is unused: an
     * answer that carries no paragraph of the article it names (another language, a free
     * paraphrase) is not measurable by words — the article stays used as a whole.
     */
    public function an_article_none_of_whose_paragraphs_the_answer_carries_stays_used_whole(): void
    {
        $user = $this->userPrompt("Avant chaque vol, l'exploitant inspecte l'aéronef (art. 401) [1].", $this->passagesWithParagraphs());

        $this->assertStringContainsString("EXCERPTS:\nE1 — maintenance-manual.md — Part 4 › Line maintenance — art. 402", $user);
        $this->assertStringNotContainsString('— art. 401', $user);
    }

    /**
     * @test A passage cited WITHOUT an article number: the articles the answer carries are
     * the used ones — their unused paragraphs come first, and the passage's other articles
     * are new. Live (eval p19-A tear-down): a six-article passage cited as "[3]" was used
     * whole, and the article that held the next question gave no excerpt.
     */
    public function a_passage_cited_without_an_article_number_is_used_as_far_as_the_answer_carries_it(): void
    {
        $answer = 'Before each flight the operator inspects the aircraft and enters every defect found in the technical log [1].';

        $user = $this->userPrompt($answer, $this->passagesWithParagraphs());

        $this->assertStringContainsString(implode("\n", [
            'EXCERPTS:',
            'E1 — maintenance-manual.md — Part 4 › Line maintenance — art. 401',
            '    "' . self::SECOND_PARAGRAPH . '"',
            'E2 — maintenance-manual.md — Part 4 › Line maintenance — art. 402',
        ]), $user);

        // Not measurable by words: the passage stays used as a whole, as before.
        $blind = $this->userPrompt("Avant chaque vol, l'exploitant inspecte l'aéronef [1].", $this->passagesWithParagraphs());
        $this->assertStringNotContainsString('Line maintenance', $blind);
        $this->assertStringContainsString("EXCERPTS:\nE1 — maintenance-manual.md — Part 4 › Deferred defects — art. 410", $blind);
    }

    /**
     * @test A few shared words do not make a neighbouring article a used one. Live
     * (2026-10-07): an answer on losing the right to vote "used" the article on the quorum of
     * a meeting by five of its eight words, its other paragraphs were offered first, and a
     * chip on the quorum followed. The words must SEE the article: a paragraph carried by
     * eight words or more, or almost word for word.
     */
    public function a_short_paragraph_sharing_a_few_words_does_not_make_its_article_a_used_one(): void
    {
        $passages = $this->passagesWithParagraphs();
        $passages[0]['snippet'] .= "\n\n**403.** The operator enters every defect in the technical log kept on board.\n"
            . 'A log left ashore grounds the aircraft until a courier brings it back to the commander.';
        $answer = 'Before each flight the operator inspects the aircraft and enters every defect found in the technical log [1].';

        $user = $this->userPrompt($answer, $passages);

        // Five of the seven words of art. 403's first paragraph are words of the answer:
        // carried, but not SEEN — its second paragraph is not "an unused paragraph of a used
        // article". Art. 401 is seen (its first paragraph, ten words): its unused paragraph
        // comes first; 402 and 403 are new articles of the cited passage, each whole.
        $this->assertStringContainsString(implode("\n", [
            'EXCERPTS:',
            'E1 — maintenance-manual.md — Part 4 › Line maintenance — art. 401',
            '    "' . self::SECOND_PARAGRAPH . '"',
            'E2 — maintenance-manual.md — Part 4 › Line maintenance — art. 402',
            '    "402. A component past its service life is replaced before the next departure, whatever its apparent condition."',
            'E3 — maintenance-manual.md — Part 4 › Line maintenance — art. 403',
            '    "403. The operator enters every defect in the technical log kept on board. A log left ashore grounds the aircraft',
        ]), $user);
    }

    /**
     * @test The new articles of a cited passage are offered nearest first to the article the
     * answer used (a document's neighbouring clauses are its closest ones), not first in
     * the passage.
     */
    public function the_new_articles_of_a_cited_passage_come_nearest_to_the_used_one_first(): void
    {
        $passages = $this->passages();
        $passages[0]['snippet'] = "**398.** Ground handling follows the station manual.\n\n"
            . "**399.** Fuel is sampled before the first departure of the day.\n\n"
            . "**400.** Tyre pressures are read with a calibrated gauge.\n\n"
            . '**401.** The operator inspects the aircraft before each flight.';

        $user = $this->userPrompt(self::ANSWER, $passages);

        $this->assertStringContainsString("E1 — maintenance-manual.md — Part 4 › Line maintenance — art. 400\n", $user);
        $this->assertStringContainsString("E2 — maintenance-manual.md — Part 4 › Line maintenance — art. 399\n", $user);
        $this->assertStringNotContainsString('art. 398', $user, 'a passage gives at most two new articles');
        $this->assertStringContainsString("E3 — maintenance-manual.md — Part 4 › Deferred defects — art. 410\n", $user);
    }

    /** @test A sub-article is an article of its own in the excerpt's label and text. */
    public function a_sub_article_is_labelled_with_its_own_number(): void
    {
        $passages = $this->passages();
        $passages[1]['snippet'] = "**410.1.** A defect may be deferred only when the minimum equipment list allows it.\n\n"
            . '**410.2.** A deferred defect is rectified within the interval the list sets.';

        $written = (new FollowUpWriter($llm = new ScriptedUsageLlm(json: $this->reply([2 => [self::F410, self::Q410]]))))
            ->write(self::QUESTION, self::ANSWER, $passages, 'en');

        $this->assertStringContainsString("E2 — maintenance-manual.md — Part 4 › Deferred defects — art. 410.1\n    \"410.1. A defect may be deferred", $llm->calls[0][1]['content']);
        // The chip's evidence names the sub-article itself (eval p20-A buyer: two chips, on
        // art. 1068.1 and 1068.2, were both recorded as "1068").
        $this->assertSame([['question' => self::Q410, 'passage' => 2, 'article' => '410.1']], $written['follow_ups']);
    }

    // ------------------------------------------------------------- more of the same article family

    private const SUB_ARTICLE = 'After a landing on an unpaved runway the landing gear is examined again by a licensed engineer.';

    /**
     * The fixture with a SUB-ARTICLE of the article self::ANSWER names (401 → 401.1).
     *
     * @return list<array<string, mixed>>
     */
    private function passagesWithSubArticle(): array
    {
        $passages = $this->passages();
        $passages[0]['snippet'] = "**401.** The operator inspects the aircraft before each flight.\n\n"
            . '**401.1.** ' . self::SUB_ARTICLE . "\n\n"
            . '**402.** A component past its service life is replaced before the next departure, whatever its apparent condition.';

        return $passages;
    }

    /**
     * @test MORE OF THE SAME ARTICLE FAMILY. A sub-article of an article the answer used,
     * which the answer neither names nor tells, is the most on-thread material there is —
     * tier 1, before the other articles of the cited passage. Eval p21-B rights t1 ("tell me
     * more about my duty to contribute"): the answer named art. 1072; art. 1072.1 (special
     * contributions) was no "unused paragraph" of it (another number) and no "new article"
     * (the contract counts whole numbers, and 1072 was used) — all four excerpts offered were
     * off the thread, and the turn got no chip.
     */
    public function a_sub_article_of_an_article_the_answer_used_is_offered_first(): void
    {
        $llm = new ScriptedUsageLlm(json: $this->reply([
            1 => ['the landing gear is examined again by a licensed engineer', 'Who examines the landing gear after an unpaved runway?'],
        ]));

        $written = (new FollowUpWriter($llm))->write(self::QUESTION, self::ANSWER, $this->passagesWithSubArticle(), 'en');

        $this->assertStringContainsString(implode("\n", [
            'EXCERPTS:',
            'E1 — maintenance-manual.md — Part 4 › Line maintenance — art. 401.1',
            '    "401.1. ' . self::SUB_ARTICLE . '"',
            'E2 — maintenance-manual.md — Part 4 › Line maintenance — art. 402',
        ]), $llm->calls[0][1]['content']);
        $this->assertSame(['passage' => 1, 'article' => '401.1', 'tier' => 1], $written['excerpts'][0]);
        $this->assertSame(['passage' => 1, 'article' => '402', 'tier' => 2], $written['excerpts'][1]);
        $this->assertSame(
            [['question' => 'Who examines the landing gear after an unpaved runway?', 'passage' => 1, 'article' => '401.1']],
            $written['follow_ups']
        );
    }

    /**
     * @test …but never one the answer already told: named by its own number, carried by its
     * words, or drawn on by a cited statement in the answer's own words.
     * @dataProvider answersTellingTheSubArticle
     */
    public function a_sub_article_the_answer_already_told_is_not_offered(string $statement): void
    {
        $user = $this->userPrompt(self::ANSWER . ' ' . $statement, $this->passagesWithSubArticle());

        $this->assertStringNotContainsString('— art. 401.1', $user);
        $this->assertStringContainsString("EXCERPTS:\nE1 — maintenance-manual.md — Part 4 › Line maintenance — art. 402", $user);
    }

    /** @return array<string, array{0: string}> */
    public static function answersTellingTheSubArticle(): array
    {
        return [
            'named' => ['A second check follows some landings (art. 401.1) [1].'],
            'carried' => ['After a landing on an unpaved runway the landing gear is examined again by a licensed engineer [1].'],
            'told in its own words' => ['An engineer looks at the gear once more when the runway was unpaved [1].'],
        ];
    }

    /**
     * @test The family is the USED article's: a sub-article of an article the answer did not
     * use is a new article like any other (tier 2), and one of an article an EARLIER answer
     * covered is not brought back (what that answer told of the family is not known here).
     */
    public function a_sub_article_is_family_only_of_an_article_this_answer_used(): void
    {
        $passages = $this->passagesWithSubArticle();
        $passages[0]['snippet'] .= "\n\n**402.1.** A replaced component is tagged and kept for thirty days.";

        $written = (new FollowUpWriter(new ScriptedUsageLlm(json: [])))->write(self::QUESTION, self::ANSWER, $passages, 'en');
        $this->assertSame([
            ['passage' => 1, 'article' => '401.1', 'tier' => 1],
            ['passage' => 1, 'article' => '402', 'tier' => 2],
            ['passage' => 1, 'article' => '402.1', 'tier' => 2],
            ['passage' => 2, 'article' => '410', 'tier' => 3],
        ], $written['excerpts']);

        // Art. 401 was covered by an earlier answer of the conversation (and named again).
        $covered = [['id' => 7, 'chunk_index' => 3, 'cited_articles' => [401]]];
        $user = $this->shownPrompt(self::ANSWER, $this->passagesWithSubArticle(), $covered);
        $this->assertStringNotContainsString('— art. 401.1', $user);
        $this->assertStringContainsString("EXCERPTS:\nE1 — maintenance-manual.md — Part 4 › Line maintenance — art. 402", $user);
    }

    /** The user prompt of a call made with the conversation's earlier citations. */
    private function shownPrompt(string $answer, array $passages, array $covered = [], string $standalone = ''): string
    {
        $llm = new ScriptedUsageLlm(json: []);
        (new FollowUpWriter($llm))->write(self::QUESTION, $answer, $passages, 'en', null, 3, $standalone, 0.0, $covered);

        return $llm->calls[0][1]['content'] ?? '';
    }

    /**
     * @test ON A FOLLOW-UP TURN THE THREAD IS WHAT THE ANSWER CITED. A turn that returns to
     * ground the conversation covered ("tell me more about …") — or that Query Understanding
     * judged a follow-up — offers the material of the passages its answer cites first, all
     * of it: no slot is held back for a passage the answer did not use. (On a first
     * question the slots stay reserved per tier: see the test below.)
     */
    public function on_a_follow_up_turn_the_cited_passages_come_before_any_unused_passage(): void
    {
        $passages = $this->passagesWithManyUnusedParagraphs();
        $expected = [
            ['passage' => 1, 'article' => '401', 'tier' => 1],
            ['passage' => 2, 'article' => '410', 'tier' => 1],
            ['passage' => 1, 'article' => '401', 'tier' => 1],
            ['passage' => 2, 'article' => '410', 'tier' => 1],
        ];

        // An earlier answer cited the records passage ([3]): this turn returns to covered ground.
        $covered = [['id' => 7, 'chunk_index' => 14, 'cited_articles' => [520]]];
        $llm = new ScriptedUsageLlm(json: []);
        $written = (new FollowUpWriter($llm))->write(self::QUESTION, self::ANSWER_TWO_CITED, $passages, 'en', null, 3, '', 0.0, $covered);
        $this->assertSame($expected, $written['excerpts']);
        $this->assertStringContainsString('landing gear', $llm->calls[0][1]['content']);
        $this->assertStringNotContainsString('Torque wrenches', $llm->calls[0][1]['content']);

        // Query Understanding's judgment (the resolved form of the question is handed in).
        $llm = new ScriptedUsageLlm(json: []);
        $written = (new FollowUpWriter($llm))->write('And when it is deferred?', self::ANSWER_TWO_CITED, $passages, 'en', null, 3, 'When may a defect found before a flight be deferred?');
        $this->assertSame($expected, $written['excerpts']);
    }

    /**
     * Two CITED passages, each with a carried first paragraph and two paragraphs the answer
     * does not carry; then two passages the answer did not use — a records article the
     * user's own wording did not find, and a hangar guide it did.
     *
     * @return list<array<string, mixed>>
     */
    private function passagesWithManyUnusedParagraphs(): array
    {
        $passages = $this->passagesWithSupport([3 => false, 4 => true]);
        $passages[0]['snippet'] = "**401.** The operator inspects the aircraft before each flight and enters every defect found in the technical log.\n"
            . self::SECOND_PARAGRAPH . "\n"
            . 'The inspection covers the landing gear, the control surfaces and the fluid levels of both engines.';
        $passages[1]['snippet'] = "**410.** A defect may be deferred only when the minimum equipment list allows it.\n"
            . "The deferral is signed by a licensed engineer who states the date by which the repair must be done.\n"
            . 'A placard beside the affected instrument warns the crew for as long as the deferral lasts.';

        return $passages;
    }

    private const ANSWER_TWO_CITED = 'Before each flight the operator inspects the aircraft and enters every defect found in the technical log (art. 401) [1]. '
        . 'A defect may be deferred only when the minimum equipment list allows it (art. 410) [2].';

    /**
     * @test Slots are RESERVED per tier. Eval p20-B rights t0: the unused paragraphs of the
     * cited articles took all four slots, so the passages the answer had not used — where
     * the two threads it lacked were — could never be offered. The cited articles' paragraphs
     * take at most two slots; an unused passage the user's own wording found keeps one; the
     * others follow in tier order.
     */
    public function cited_paragraphs_take_at_most_two_slots_and_a_passage_the_question_found_keeps_one(): void
    {
        $llm = new ScriptedUsageLlm(json: []);
        $written = (new FollowUpWriter($llm))->write(self::QUESTION, self::ANSWER_TWO_CITED, $this->passagesWithManyUnusedParagraphs(), 'en');
        $user = $llm->calls[0][1]['content'];

        $this->assertStringContainsString(implode("\n", [
            'EXCERPTS:',
            'E1 — maintenance-manual.md — Part 4 › Line maintenance — art. 401',
            '    "' . self::SECOND_PARAGRAPH . '"',
            'E2 — maintenance-manual.md — Part 4 › Deferred defects — art. 410',
            '    "The deferral is signed by a licensed engineer who states the date by which the repair must be done."',
            // The passage the question itself found, before the one only a rewrite found.
            'E3 — hangar-guide.md — Hangar guide › Tools',
            '    "Torque wrenches are calibrated every twelve months and tagged with the date of their last calibration."',
            'E4 — maintenance-manual.md — Part 5 › Records — art. 520',
        ]), $user);
        $this->assertStringNotContainsString('landing gear', $user, 'a third paragraph of the cited articles waits behind the other tiers');
        $this->assertStringNotContainsString('placard', $user);

        // Trace evidence: what each excerpt is (never its text).
        $this->assertSame([
            ['passage' => 1, 'article' => '401', 'tier' => 1],
            ['passage' => 2, 'article' => '410', 'tier' => 1],
            ['passage' => 4, 'tier' => 3, 'question_supported' => true],
            ['passage' => 3, 'article' => '520', 'tier' => 3, 'question_supported' => false],
        ], $written['excerpts']);
    }

    /** @test The cap on the cited articles' paragraphs reserves slots — it never wastes them: with nothing else to offer, they fill the four. */
    public function with_nothing_else_to_offer_the_cited_paragraphs_fill_every_slot(): void
    {
        $passages = array_slice($this->passagesWithManyUnusedParagraphs(), 0, 2);

        $user = $this->userPrompt(self::ANSWER_TWO_CITED, $passages);

        $this->assertSame(4, preg_match_all('/^E\d — /mu', $user));
        // (One file only: the labels do not name it.)
        $this->assertStringContainsString('E3 — Part 4 › Line maintenance — art. 401' . "\n" . '    "The inspection covers the landing gear', $user);
        $this->assertStringContainsString('E4 — Part 4 › Deferred defects — art. 410' . "\n" . '    "A placard beside', $user);
    }

    /**
     * @test Never an article the answer already used. Eval p20-B rights t0: the answer told
     * an article in its own words ("if you sell or lease your unit, you must notify…"), the
     * word test did not SEE it, and the article took a slot as "new". A cited statement
     * draws on the article of its passage that holds the most of its words: that article is
     * used, whatever share of the article the statement carries.
     */
    public function an_article_a_cited_statement_draws_on_in_its_own_words_is_not_offered_as_new(): void
    {
        $passages = $this->passages();
        $passages[0]['snippet'] = "**401.** The operator inspects the aircraft before each flight and enters every defect found in the technical log.\n\n"
            . "**402.** A component past its service life is replaced before the next departure, whatever its apparent condition.\n\n"
            . '**403.** Tyre pressures are read with a calibrated gauge after every landing.';
        $answer = 'Before each flight the operator inspects the aircraft and enters every defect found in the technical log [1]. '
            . 'You must change a component whose service life has run out before departure [1].';

        $user = $this->userPrompt($answer, $passages);

        $this->assertStringContainsString("EXCERPTS:\nE1 — maintenance-manual.md — Part 4 › Line maintenance — art. 403\n", $user);
        $this->assertStringNotContainsString('art. 402', $user);
        $this->assertStringNotContainsString('art. 401', $user);

        // A statement that draws on no article in particular marks none: 402 stays new.
        $vague = 'Before each flight the operator inspects the aircraft and enters every defect found in the technical log [1]. '
            . 'Other rules of the manual apply before departure [1].';
        $this->assertStringContainsString('art. 402', $this->userPrompt($vague, $passages));
    }

    /**
     * @test Material of a passage the answer cites may run longer than an unused passage's
     * (the sentence worth asking about is often not its first), inside ONE budget shared by
     * all the excerpts: the call does not grow with it.
     */
    public function excerpts_of_a_cited_passage_run_longer_within_one_shared_budget(): void
    {
        $passages = $this->passages();
        $passages[0]['snippet'] = "**401.** The operator inspects the aircraft before each flight.\n\n**402.** " . self::rules('components', 40);
        $passages[1]['snippet'] = '**410.** ' . self::rules('defects', 40);
        $passages[2]['snippet'] = '**520.** ' . self::rules('records', 40);
        $passages[3]['snippet'] = self::rules('tools', 40);

        $lengths = array_map('mb_strlen', $this->shownExcerpts(self::ANSWER, $passages));
        $this->assertCount(4, $lengths);
        $this->assertGreaterThan(400, $lengths[0], 'the new article of the CITED passage');
        $this->assertLessThanOrEqual(FollowUpWriter::CITED_EXCERPT_CHARS, $lengths[0]);
        $this->assertLessThanOrEqual(240, $lengths[1], 'an unused passage');
        $this->assertLessThanOrEqual(FollowUpWriter::EXCERPTS_CHARS, array_sum($lengths), 'one budget for all the excerpts');
    }

    /** $count short sentences on one subject ("Rule 1 of the tools chapter applies. Rule 2 …"). */
    private static function rules(string $subject, int $count): string
    {
        return implode(' ', array_map(static fn (int $i): string => "Rule {$i} of the {$subject} chapter applies.", range(1, $count)));
    }

    /** ONE sentence of about $chars characters that says something of $subject. */
    private static function sentence(string $subject, int $chars): string
    {
        $sentence = "The {$subject} rule holds";
        for ($i = 1; mb_strlen($sentence) < $chars - 1; $i++) {
            $sentence .= ", and in case {$i} too";
        }

        return $sentence . '.';
    }

    /**
     * The excerpt texts as the model is shown them, in order.
     *
     * @param list<array<string, mixed>> $passages
     * @return list<string>
     */
    private function shownExcerpts(string $answer, array $passages, array $covered = [], string $standalone = ''): array
    {
        $llm = new ScriptedUsageLlm(json: []);
        (new FollowUpWriter($llm))->write(self::QUESTION, $answer, $passages, 'en', null, 3, $standalone, 0.0, $covered);
        preg_match_all('/^    "(.*)"$/mu', $llm->calls[0][1]['content'] ?? '', $m);

        return $m[1];
    }

    /**
     * @test NEVER CUT INSIDE A SENTENCE. Eval p21-B rights t0: the shared character budget
     * cut the rule a buyer is bound by after "…is bound" and the register excerpt before it
     * said what the register holds — an excerpt that stops before its rule cannot give a
     * question. An excerpt is its whole sentences, as many as fit its share.
     */
    public function an_excerpt_is_shortened_by_whole_sentences_never_inside_one(): void
    {
        $first = self::sentence('components', 200);
        $second = self::sentence('spare parts', 220);
        $third = self::sentence('lubricants', 230);
        $passages = $this->passages();
        $passages[0]['snippet'] = "**401.** The operator inspects the aircraft before each flight.\n\n**402.** {$first} {$second} {$third}";
        $passages[1]['snippet'] = '**410.** ' . ($defects = self::sentence('defects', 150)) . ' ' . self::sentence('deferrals', 150);
        $passages[2]['snippet'] = '**520.** ' . ($records = self::sentence('records', 150)) . ' ' . self::sentence('archives', 150);

        $shown = $this->shownExcerpts(self::ANSWER, $passages);

        // The new article of the cited passage: two of its three sentences fit its 520
        // characters. An unused passage (240): the first of its two.
        $this->assertSame("402. {$first} {$second}", $shown[0]);
        $this->assertSame("410. {$defects}", $shown[1]);
        $this->assertSame("520. {$records}", $shown[2]);
        foreach ($shown as $text) {
            $this->assertStringEndsNotWith('…', $text);
        }
    }

    /**
     * @test Every excerpt keeps at least its FIRST WHOLE SENTENCE, however long the ones
     * before it: the room is taken from the later sentences of the earlier excerpts, not
     * from the rule of a later one.
     */
    public function every_excerpt_keeps_its_first_whole_sentence_whatever_came_before(): void
    {
        $passages = $this->passages();
        // Two new articles of the cited passage (each one sentence, of about 400 and 460
        // characters), then two unused passages.
        $passages[0]['snippet'] = "**401.** The operator inspects the aircraft before each flight.\n\n"
            . '**402.** ' . ($components = self::sentence('components', 400)) . "\n\n"
            . '**403.** ' . ($tyres = self::sentence('tyres', 460));
        $passages[1]['snippet'] = '**410.** ' . ($defects = self::sentence('defects', 100)) . ' ' . self::rules('deferrals', 6);
        $passages[2]['snippet'] = '**520.** ' . ($records = self::sentence('records', 100)) . ' ' . self::rules('archives', 6);

        $shown = $this->shownExcerpts(self::ANSWER, array_slice($passages, 0, 3));

        $this->assertCount(4, $shown);
        $this->assertSame(["402. {$components}", "403. {$tyres}"], array_slice($shown, 0, 2));
        // The unused passages: their first sentence whole — and what still fits, whole too.
        $this->assertStringStartsWith("410. {$defects}", $shown[2]);
        $this->assertStringStartsWith("520. {$records}", $shown[3]);
        foreach ($shown as $text) {
            $this->assertStringEndsWith('.', $text);
        }
        $this->assertLessThanOrEqual(FollowUpWriter::EXCERPTS_CHARS, array_sum(array_map('mb_strlen', $shown)));
    }

    /**
     * @test When the first sentences cannot all fit the budget, the LAST excerpt — the
     * lowest tier — is dropped and the others keep theirs whole: one excerpt fewer, never
     * four half rules.
     */
    public function when_the_first_sentences_do_not_fit_together_the_last_excerpt_is_dropped(): void
    {
        $passages = $this->passages();
        $passages[0]['snippet'] = "**401.** The operator inspects the aircraft before each flight.\n\n"
            . '**402.** ' . ($components = self::sentence('components', 480)) . "\n\n"
            . '**403.** ' . ($tyres = self::sentence('tyres', 420));
        $passages[1]['snippet'] = '**410.** ' . ($defects = self::sentence('defects', 200));
        $passages[2]['snippet'] = '**520.** ' . self::sentence('records', 200);

        $llm = new ScriptedUsageLlm(json: []);
        $written = (new FollowUpWriter($llm))->write(self::QUESTION, self::ANSWER, array_slice($passages, 0, 3), 'en');
        $user = $llm->calls[0][1]['content'];

        preg_match_all('/^    "(.*)"$/mu', $user, $m);
        $this->assertSame(["402. {$components}", "403. {$tyres}", "410. {$defects}"], $m[1]);
        $this->assertStringEndsWith('Write f1, q1, f2, q2, f3, q3 — the questions in "en".', $user);
        $this->assertCount(3, $written['excerpts'], 'the trace shows what was offered');
    }

    /** @test The last resort: ONE sentence longer than the whole budget is cut on a word, and says so. */
    public function a_single_sentence_longer_than_the_whole_budget_is_cut_on_a_word(): void
    {
        $passages = [$this->passages()[0]];
        $passages[0]['snippet'] = "**401.** The operator inspects the aircraft before each flight.\n\n**402.** "
            . self::sentence('components', FollowUpWriter::EXCERPTS_CHARS + 400);

        $shown = $this->shownExcerpts(self::ANSWER, $passages);

        $this->assertCount(1, $shown);
        $this->assertStringEndsWith('…', $shown[0]);
        $this->assertLessThanOrEqual(FollowUpWriter::EXCERPTS_CHARS + 1, mb_strlen($shown[0]));
    }

    /** @test */
    public function the_questions_language_is_passed_to_the_model(): void
    {
        $llm = new ScriptedUsageLlm(json: []);
        (new FollowUpWriter($llm))->write('Que faut-il vérifier avant chaque vol ?', self::ANSWER, $this->passages(), 'fr');

        [$system, $user] = array_column($llm->calls[0], 'content');

        $this->assertStringContainsString('QUESTION (written in "fr"): Que faut-il vérifier avant chaque vol ?', $user);
        $this->assertStringEndsWith('Write f1, q1, f2, q2, f3, q3, f4, q4 — the questions in "fr".', $user);
        $this->assertStringContainsString('language of the QUESTION whatever the language of the excerpt', $system);
        $this->assertStringContainsString("in the excerpt's own language", $system);
    }

    /** @test A follow-up that only makes sense inside its conversation is shown resolved too. */
    public function the_resolved_form_of_the_question_is_shown_when_it_differs(): void
    {
        $llm = new ScriptedUsageLlm(json: []);
        $writer = new FollowUpWriter($llm);

        $writer->write('And before a night flight?', self::ANSWER, $this->passages(), 'en', null, 3, 'What must be checked before a night flight?');
        $writer->write(self::QUESTION, self::ANSWER, $this->passages(), 'en', null, 3, '  what must be checked before each flight ');

        $this->assertStringContainsString('resolved against its conversation: What must be checked before a night flight?', $llm->calls[0][1]['content']);
        $this->assertStringNotContainsString('resolved against its conversation', $llm->calls[1][1]['content']);
    }

    /**
     * @test The whole call stays small: a long question, a long answer, long heading paths
     * and long passages are cut, and at most four excerpts are offered.
     * (~4 characters per token; the live cost is in the trace.)
     */
    public function the_prompt_stays_under_its_input_budget(): void
    {
        $llm = new ScriptedUsageLlm(json: []);
        $passages = [];
        for ($i = 1; $i <= 12; $i++) {
            $passages[] = [
                'file_id' => $i % 2, 'chunk_index' => $i, 'filename' => "document-{$i}.md",
                'heading' => 'Book ' . $i . ' › ' . str_repeat('A long title ', 6) . '› Chapter ' . $i . ' › ' . str_repeat('A division ', 5),
                'snippet' => "**{$i}00.** " . str_repeat('A rule of this chapter, stated at length. ', 80),
            ];
        }
        $answer = str_repeat('A long sentence of the answer, citing its source [1]. ', 60);

        (new FollowUpWriter($llm))->write(str_repeat('A very long question ', 40) . '?', $answer, $passages, 'en');

        [$system, $user] = array_column($llm->calls[0], 'content');
        // (1,200 until the "f IS the answer to q" contract was spelled out: +70 tokens.)
        $this->assertLessThanOrEqual(1280, (int) ceil(mb_strlen($system . "\n" . $user) / 4), 'worst case');
        $this->assertStringContainsString("\nE4 — ", $user);
        $this->assertStringNotContainsString("\nE5 — ", $user, 'at most four excerpts');
        $this->assertStringContainsString('E1 — document-2.md — … › Chapter 2 › A division A division A division A division A division — art. 200', $user, 'a long path keeps its last levels');

        // The fixture of this class — a typical turn — is far below.
        (new FollowUpWriter($llm))->write(self::QUESTION, self::ANSWER, $this->passages(), 'en');
        $this->assertLessThanOrEqual(770, (int) ceil(mb_strlen(implode("\n", array_column($llm->calls[1], 'content'))) / 4));
    }

    /** @test */
    public function the_observer_sees_the_prompt_and_the_usage(): void
    {
        $llm = new ScriptedUsageLlm(json: $this->reply([2 => [self::F410, self::Q410]]));
        $observer = new RecordingPipelineObserver();

        (new FollowUpWriter($llm))->write(self::QUESTION, self::ANSWER, $this->passages(), 'en', $observer);

        $this->assertSame('follow_ups', FollowUpWriter::STAGE);
        $this->assertSame([$llm->calls[0]], $observer->prompts('follow_ups'));
        $this->assertSame([['stage' => 'follow_ups', 'usage' => ScriptedUsageLlm::USAGE]], $observer->usages());
    }

    // ------------------------------------------------------------- no call

    /** @test */
    public function nothing_to_work_from_means_no_call(): void
    {
        $llm = new ScriptedUsageLlm(json: $this->reply([1 => [self::F410, self::Q410]]));
        $writer = new FollowUpWriter($llm);
        $none = ['follow_ups' => [], 'proposed' => 0, 'dropped' => []];

        $this->assertSame($none, $writer->write('', self::ANSWER, $this->passages(), 'en'));
        $this->assertSame($none, $writer->write(self::QUESTION, '  ', $this->passages(), 'en'));
        $this->assertSame($none, $writer->write(self::QUESTION, self::ANSWER, [], 'en'));
        $this->assertSame($none, $writer->write(self::QUESTION, self::ANSWER, ['not a passage'], 'en'));
        $this->assertSame($none, $writer->write(self::QUESTION, self::ANSWER, $this->passages(), 'en', null, 0));
        // Passages with no text at all: no excerpt to ask about.
        $this->assertSame($none, $writer->write(self::QUESTION, 'Nothing cited.', [['file_id' => 1, 'snippet' => ''], ['file_id' => 2]], 'en'));
        $this->assertSame([], $llm->calls);
    }

    // ------------------------------------------------------------- failures

    /** @test */
    public function a_failed_call_yields_no_question_and_never_throws(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->andThrow(new \RuntimeException('provider timeout'));
        $observer = new RecordingPipelineObserver();

        $written = (new FollowUpWriter($llm))->write(self::QUESTION, self::ANSWER, $this->passages(), 'en', $observer);

        $this->assertSame([], $written['follow_ups']);
        $this->assertSame(
            [['stage' => 'follow_ups', 'class' => \RuntimeException::class, 'message' => 'provider timeout']],
            $observer->errors()
        );
    }

    /** @test */
    public function an_unusable_reply_yields_no_question(): void
    {
        foreach ([[], ['q1' => ['nested'], 'f1' => self::F402], ['questions' => [self::Q410]], ['f1' => self::F402, 'q1' => ''], ['f1' => self::F402, 'q1' => 42], ['q' => self::Q410]] as $reply) {
            $written = $this->write($reply);

            $this->assertSame([], $written['follow_ups']);
            $this->assertSame(0, $written['proposed']);
        }
    }

    // ------------------------------------------------------------- code verifies

    /**
     * @test The question must stand on something an excerpt states: a fact that is in no
     * excerpt — made up, taken from the ANSWER's own ground, or missing — has no passage
     * behind it.
     */
    public function a_question_whose_fact_no_excerpt_states_is_dropped(): void
    {
        $written = $this->write([
            // Article 401 is what the answer already told: it is in no excerpt.
            'f1' => 'The operator inspects the aircraft before each flight', 'q1' => 'Who inspects the aircraft before a flight?',
            // Nothing the documents shown say.
            'f2' => 'Pilots must hold a valid medical certificate', 'q2' => 'Which medical certificate must a pilot hold?',
            // No fact at all.
            'q3' => 'Who signs the technical log?',
            'f4' => self::FTOOLS, 'q4' => self::QTOOLS,
        ]);

        $this->assertSame([['question' => self::QTOOLS, 'passage' => 4]], $written['follow_ups']);
        $this->assertSame(4, $written['proposed']);
        $this->assertSame(['unsupported' => 3], $written['dropped']);
    }

    /**
     * @test A yes / no question holds its whole fact by nature — it is kept. (Measured on
     * the eval p19 / p20 replays: a code check "the fact says something its question does
     * not" dropped exactly this natural chip; the contract is the prompt's, see below.)
     */
    public function a_yes_or_no_question_whose_words_hold_its_fact_is_kept(): void
    {
        $written = $this->write(['f3' => 'Maintenance records are kept for five years', 'q3' => 'Are maintenance records kept for five years after the work?']);

        $this->assertSame(['Are maintenance records kept for five years after the work?'], array_column($written['follow_ups'], 'question'));
        $this->assertSame([], $written['dropped']);
    }

    /**
     * @test The prompt states the contract: the copied fact IS the answer to the question,
     * and a question asks only for what its excerpt itself settles. Eval p20-B rights t1:
     * from a paragraph that only says a regulation sets an amount, the model copied the
     * subject of its own question and asked for the amount.
     */
    public function the_prompt_asks_for_the_fact_as_the_answer_to_the_question(): void
    {
        foreach ([true, false] as $modelDeclines) {
            $llm = new ScriptedUsageLlm(json: []);
            (new FollowUpWriter($llm, null, $modelDeclines))->write(self::QUESTION, self::ANSWER, $this->passages(), 'en');
            $system = $llm->calls[0][0]['content'];

            $this->assertStringContainsString('"f" IS the answer to "q"', $system);
            $this->assertStringContainsString('never the mere subject of the question', $system);
            $this->assertStringContainsString('Ask only for what the excerpt itself settles', $system);
        }
    }
    /**
     * @test The slot number is not trusted. Live (2026-10-06): the model skipped the first
     * excerpt and wrote the others one slot up — each question would have carried its
     * neighbour's passage. The fact decides.
     */
    public function a_question_written_in_the_wrong_slot_is_tied_to_the_excerpt_that_states_its_fact(): void
    {
        $written = $this->write($this->reply([
            1 => [self::F410, self::Q410],
            2 => [self::F520, self::Q520],
            3 => [self::FTOOLS, self::QTOOLS],
            // A slot that was never offered: its fact still names its excerpt.
            7 => [self::F402, self::Q402],
        ]), null, 9);

        $this->assertSame([
            ['question' => self::Q410, 'passage' => 2, 'article' => 410],
            ['question' => self::Q520, 'passage' => 3, 'article' => 520],
            ['question' => self::QTOOLS, 'passage' => 4],
            ['question' => self::Q402, 'passage' => 1, 'article' => 402],
        ], $written['follow_ups']);
    }

    /** @test A fact copied loosely (an article dropped, a clause shortened) still names its excerpt. */
    public function a_loosely_copied_fact_is_still_recognised(): void
    {
        $written = $this->write($this->reply([
            1 => ['component past service life replaced before the next departure', self::Q402],
            3 => ['MAINTENANCE RECORDS kept five years.', self::Q520],
        ]));

        $this->assertSame([1, 3], array_column($written['follow_ups'], 'passage'));
    }

    /**
     * @test
     * @dataProvider repeatsOfTheQuestion
     */
    public function a_question_that_repeats_the_users_question_is_dropped(string $proposal): void
    {
        $written = $this->write($this->reply([1 => [self::F402, $proposal], 2 => [self::F410, self::Q410]]));

        $this->assertSame([self::Q410], array_column($written['follow_ups'], 'question'));
        $this->assertSame(['repeats_question' => 1], $written['dropped']);
    }

    /** @return array<string, array{0: string}> */
    public static function repeatsOfTheQuestion(): array
    {
        return [
            'verbatim' => ['What must be checked before each flight?'],
            'another casing and punctuation' => ['what must be checked, before each flight ?'],
            'plural and accent variants' => ['What must be checked before each flights?'],
            'a word or two changed' => ['What must be checked before every flight?'],
            'only words of the question' => ['What is checked before a flight?'],
        ];
    }

    /** @test The resolved form of the user's question is the user's question too. */
    public function a_question_that_repeats_the_resolved_question_is_dropped(): void
    {
        $llm = new ScriptedUsageLlm(json: $this->reply([
            1 => [self::F402, 'What must be checked before a night flight?'],
            2 => [self::F410, self::Q410],
        ]));

        $written = (new FollowUpWriter($llm))->write('And at night?', self::ANSWER, $this->passages(), 'en', null, 3, 'What must be checked before a night flight?');

        $this->assertSame([self::Q410], array_column($written['follow_ups'], 'question'));
    }

    /** @test */
    public function questions_that_repeat_each_other_are_kept_once(): void
    {
        $written = $this->write($this->reply([
            2 => [self::F410, 'When may a defect be deferred?'],
            3 => [self::F520, 'When may defects be deferred?'],
            4 => [self::FTOOLS, 'A defect may be deferred when?'],
        ]));

        $this->assertSame(['When may a defect be deferred?'], array_column($written['follow_ups'], 'question'));
        $this->assertSame(['duplicate' => 2], $written['dropped']);

        // A long question with one word changed is the same question…
        $long = $this->write($this->reply([
            3 => [self::F520, 'Which maintenance records must the operator keep after replacing a component?'],
            4 => [self::FTOOLS, 'Which maintenance records should the operator keep after replacing a component?'],
        ]));
        $this->assertCount(1, $long['follow_ups']);
    }

    /**
     * @test …but between SHORT questions one subject word is often the whole difference:
     * both are kept (a lost suggestion costs more than two neighbouring ones).
     */
    public function short_questions_that_differ_by_their_subject_are_both_kept(): void
    {
        $written = $this->write($this->reply([
            2 => [self::F410, 'When may a defect be deferred?'],
            3 => [self::F520, 'When is a deferred defect rectified?'],
        ]));

        $this->assertCount(2, $written['follow_ups']);
        $this->assertSame([], $written['dropped']);
    }

    /**
     * @test The overlap threshold against the user's question: questions built on the same
     * frame about DIFFERENT things are different questions (short words carry the frame,
     * long words the subject).
     */
    public function questions_sharing_a_frame_but_not_a_subject_are_kept(): void
    {
        $written = $this->write($this->reply([
            2 => [self::F410, 'How long may a defect stay deferred?'],
            3 => [self::F520, 'How long are maintenance records kept?'],
            4 => [self::FTOOLS, 'How often are torque wrenches calibrated?'],
        ]));
        $this->assertCount(3, $written['follow_ups']);

        $pair = $this->write(
            $this->reply([1 => [self::F402, 'Can I repaint my hangar?'], 2 => [self::F410, 'Can I rent out my hangar?']]),
            null,
            3,
            'Can I extend my hangar?'
        );
        $this->assertCount(2, $pair['follow_ups'], 'one shared noun is not a repeat');
    }

    /**
     * @test
     * @dataProvider notQuestions
     */
    public function anything_that_is_not_one_question_like_line_is_dropped(string $proposal): void
    {
        $written = $this->write($this->reply([2 => [self::F410, $proposal]]));

        $this->assertSame([], $written['follow_ups']);
        $this->assertSame(['not_a_question' => 1], $written['dropped']);
    }

    /** @return array<string, array{0: string}> */
    public static function notQuestions(): array
    {
        return [
            'a statement' => ['A defect may be deferred.'],
            'two questions' => ['When may a defect be deferred? And for how long?'],
            'two lines' => ["When may a defect\nbe deferred?"],
            'an answer after the question' => ['When may a defect be deferred? When the list allows it.'],
            'far too long' => ['When ' . str_repeat('exactly and precisely ', 12) . 'may a defect be deferred?'],
            'only markup' => ['**?**'],
        ];
    }

    /**
     * @test A question that names an article by number is not one a user types, and it asks
     * for that article — which the excerpt may only refer to.
     */
    public function a_question_that_names_an_article_is_dropped(): void
    {
        $written = $this->write($this->reply([
            1 => [self::F402, 'What does article 402 say about components?'],
            2 => [self::F410, 'What do arts. 410 and 411 require?'],
            3 => [self::F520, 'How long are the records of 5 inspections kept?'],
        ]));

        $this->assertSame([['question' => 'How long are the records of 5 inspections kept?', 'passage' => 3, 'article' => 520]], $written['follow_ups']);
        $this->assertSame(['names_article' => 2], $written['dropped']);
    }

    /** @test Markup around a real question is removed, never shown. */
    public function a_question_is_sanitized(): void
    {
        $written = $this->write($this->reply([
            2 => [self::F410, ' **When** may a `defect` be deferred? '],
            3 => [self::F520, '"How long are records kept?"'],
        ]));

        $this->assertSame(
            ['When may a defect be deferred?', 'How long are records kept?'],
            array_column($written['follow_ups'], 'question')
        );
    }

    /** @test */
    public function the_cap_keeps_the_first_questions_in_excerpt_order(): void
    {
        // Given out of order: the excerpt order decides (best-ranked passage first).
        $reply = $this->reply([
            4 => [self::FTOOLS, self::QTOOLS],
            3 => [self::F520, self::Q520],
            2 => [self::F410, self::Q410],
            1 => [self::F402, self::Q402],
        ]);

        $three = $this->write($reply);
        $this->assertSame([1, 2, 3], array_column($three['follow_ups'], 'passage'));
        $this->assertSame(['over_cap' => 1], $three['dropped']);

        $two = $this->write($reply, null, 2);
        $this->assertSame([1, 2], array_column($two['follow_ups'], 'passage'));

        $this->assertCount(4, $this->write($reply, null, 9)['follow_ups'], 'never more than the excerpts offered');
    }

    // ------------------------------------------------------------- on-topic gates

    /**
     * The passages with what the retrieval says of each: whether the user's own wording
     * found it (AiManager stamps `question_supported`; absent = unknown).
     *
     * @param array<int, bool> $supported passage number => question_supported
     * @return list<array<string, mixed>>
     */
    private function passagesWithSupport(array $supported): array
    {
        $passages = $this->passages();
        foreach ($supported as $number => $flag) {
            $passages[$number - 1]['question_supported'] = $flag;
        }

        return $passages;
    }

    /**
     * @test An UNUSED passage only a rewrite of the question found (a probe, QU's search
     * query) is where retrieval may have drifted: it gives no excerpt. Live (2026-10-06): a
     * wall-removal question got chips about common expenses and a movable hypothec.
     */
    public function an_unused_passage_the_question_itself_did_not_find_gives_no_excerpt(): void
    {
        $llm = new ScriptedUsageLlm(json: []);
        $passages = $this->passagesWithSupport([1 => false, 2 => false, 3 => true, 4 => false]);

        (new FollowUpWriter($llm, null, false))->write(self::QUESTION, self::ANSWER, $passages, 'en');

        $user = $llm->calls[0][1]['content'];
        // [1] is CITED: the answer itself vouches for it — its new article stays a source.
        $this->assertStringContainsString('E1 — maintenance-manual.md — Part 4 › Line maintenance — art. 402', $user);
        $this->assertStringContainsString('E2 — maintenance-manual.md — Part 5 › Records — art. 520', $user);
        $this->assertStringNotContainsString('Deferred defects', $user);
        $this->assertStringNotContainsString('Torque wrenches', $user);
        $this->assertStringEndsWith('Write f1, q1, f2, q2 — the questions in "en".', $user);
    }

    /** @test Retrieval said nothing about a passage (another provider, a rescue path): it stays a source. */
    public function a_passage_without_support_information_stays_a_source(): void
    {
        $user = $this->userPrompt(self::ANSWER, $this->passagesWithSupport([2 => false]), false);

        $this->assertStringNotContainsString('Deferred defects', $user);
        $this->assertStringContainsString('Part 5 › Records', $user);
        $this->assertStringContainsString('Hangar guide › Tools', $user);
    }

    /** @test Nothing the question found is left unused: no excerpt, so no call and no chip. */
    public function when_no_passage_qualifies_no_call_is_made(): void
    {
        $llm = new ScriptedUsageLlm(json: $this->reply([1 => [self::F410, self::Q410]]));
        $passages = $this->passagesWithSupport([1 => true, 2 => false, 3 => false, 4 => false]);

        $written = (new FollowUpWriter($llm, null, false))->write(self::QUESTION, self::ANSWER_COVERING_PASSAGE_1, $passages, 'en');

        $this->assertSame(['follow_ups' => [], 'proposed' => 0, 'dropped' => []], $written);
        $this->assertSame([], $llm->calls, 'the used passage is NOT offered instead: unused material exists, it is just off the question');
    }

    /**
     * @test By default the MODEL judges what is on the user's matter, so it is shown every
     * excerpt — whatever search angle found its passage. (Measured live 2026-10-06 on the
     * query-generation model: it declined every off-topic excerpt by itself, and the
     * source gate then only cost good questions.)
     */
    public function a_model_that_declines_is_shown_every_excerpt(): void
    {
        $writer = new FollowUpWriter(new ScriptedUsageLlm(json: []));
        $this->assertTrue($writer->modelDeclines());
        $this->assertFalse((new FollowUpWriter(new ScriptedUsageLlm(json: []), null, false))->modelDeclines());

        $user = $this->userPrompt(self::ANSWER, $this->passagesWithSupport([1 => false, 2 => false, 3 => false, 4 => false]));

        foreach (['Line maintenance', 'Deferred defects', 'Part 5 › Records', 'Hangar guide › Tools'] as $place) {
            $this->assertStringContainsString($place, $user);
        }
    }

    /** @test An excerpt the model declined (empty fact and question) is simply no question. */
    public function declined_excerpts_give_no_question(): void
    {
        $some = $this->write(['f1' => '', 'q1' => '', 'f2' => self::F410, 'q2' => self::Q410, 'f3' => '', 'q3' => '', 'f4' => '', 'q4' => '']);
        $this->assertSame([['question' => self::Q410, 'passage' => 2, 'article' => 410]], $some['follow_ups']);
        $this->assertSame(1, $some['proposed']);
        $this->assertSame([], $some['dropped']);

        // Every excerpt declined: a correct reply, and no chip.
        $none = $this->write(['f1' => '', 'q1' => '', 'f2' => '', 'q2' => '', 'f3' => '', 'q3' => '', 'f4' => '', 'q4' => '']);
        $this->assertSame(['follow_ups' => [], 'proposed' => 0, 'dropped' => []], array_diff_key($none, ['excerpts' => true]));
    }

    /**
     * @test The code-gated mode, for a model that cannot judge what follows from the
     * question (measured: the small model left good excerpts empty, or all of them, when
     * allowed to): every excerpt gets its fact and its question, and code gates.
     */
    public function in_code_gated_mode_every_excerpt_gets_a_question(): void
    {
        $llm = new ScriptedUsageLlm(json: []);
        (new FollowUpWriter($llm, null, false))->write(self::QUESTION, self::ANSWER, $this->passages(), 'en');

        $system = $llm->calls[0][0]['content'];
        $this->assertStringContainsString('Every excerpt gets its fact and its question.', $system);
        $this->assertStringContainsString('every key filled: {"f1":"…","q1":"…","f2":"…","q2":"…","f3":"…","q3":"…","f4":"…","q4":"…"}', $system);
        $this->assertStringNotContainsString('tangential', $system);
    }

    /**
     * An embedding provider that places every text at a chosen cosine to the reference
     * question (unit vectors in a plane); records each batch it is asked for.
     *
     * @param array<string, float> $cosines text => cosine similarity to the reference
     */
    private function embedding(array $cosines, string $reference = self::QUESTION): PlaneEmbeddingProvider
    {
        return new PlaneEmbeddingProvider($cosines, $reference);
    }

    /** @return array<string, string> the four excerpts' facts and questions */
    private function fourQuestions(): array
    {
        return $this->reply([
            1 => [self::F402, self::Q402],
            2 => [self::F410, self::Q410],
            3 => [self::F520, self::Q520],
            4 => [self::FTOOLS, self::QTOOLS],
        ]);
    }

    /**
     * @test The topical gate: the kept questions are embedded in ONE batch with the user's
     * question, and one stays only when it is close enough to it — its similarity rides
     * the evidence. The cap applies AFTER the gate: an off-topic question frees its place.
     */
    public function a_question_too_far_from_the_users_question_is_dropped(): void
    {
        $embedding = $this->embedding([self::Q402 => 0.12, self::Q410 => 0.41, self::Q520 => 0.2501, self::QTOOLS => 0.33]);
        $writer = new FollowUpWriter(new ScriptedUsageLlm(json: $this->fourQuestions()), $embedding);

        $written = $writer->write(self::QUESTION, self::ANSWER, $this->passages(), 'en', null, 3, '', 0.25);

        $this->assertSame([
            ['question' => self::Q410, 'passage' => 2, 'article' => 410, 'similarity' => 0.41],
            ['question' => self::Q520, 'passage' => 3, 'article' => 520, 'similarity' => 0.25],
            ['question' => self::QTOOLS, 'passage' => 4, 'similarity' => 0.33],
        ], $written['follow_ups']);
        $this->assertSame(['off_topic' => 1], $written['dropped']);
        $this->assertSame(4, $written['proposed']);
        $this->assertSame([
            ['passage' => 1, 'similarity' => 0.12, 'kept' => false],
            ['passage' => 2, 'similarity' => 0.41, 'kept' => true],
            ['passage' => 3, 'similarity' => 0.25, 'kept' => true],
            ['passage' => 4, 'similarity' => 0.33, 'kept' => true],
        ], $written['scored'], 'what was measured, for the trace');
        $this->assertSame([[self::QUESTION, self::Q402, self::Q410, self::Q520, self::QTOOLS]], $embedding->batches, 'ONE embedding call');
    }

    /** @test A question the embedding places almost ON the user's question re-asks it. */
    public function a_question_almost_identical_in_meaning_to_the_users_question_is_dropped(): void
    {
        $embedding = $this->embedding([self::Q402 => 0.86, self::Q410 => 0.84, self::Q520 => 0.5, self::QTOOLS => 0.5]);
        $writer = new FollowUpWriter(new ScriptedUsageLlm(json: $this->fourQuestions()), $embedding);

        $written = $writer->write(self::QUESTION, self::ANSWER, $this->passages(), 'en', null, 3, '', 0.25);

        $this->assertSame([self::Q410, self::Q520, self::QTOOLS], array_column($written['follow_ups'], 'question'));
        $this->assertSame(['near_duplicate' => 1], $written['dropped']);
    }

    /** @test When every question is off the user's matter, the answer gets no chip at all. */
    public function when_nothing_passes_the_gate_there_is_no_follow_up(): void
    {
        $embedding = $this->embedding([self::Q402 => 0.15, self::Q410 => 0.18, self::Q520 => 0.14, self::QTOOLS => 0.12]);
        $writer = new FollowUpWriter(new ScriptedUsageLlm(json: $this->fourQuestions()), $embedding);

        $written = $writer->write(self::QUESTION, self::ANSWER, $this->passages(), 'en', null, 3, '', 0.25);

        $this->assertSame([], $written['follow_ups']);
        $this->assertSame(['off_topic' => 4], $written['dropped']);
    }

    /** @test On a follow-up turn the question is measured in its resolved form. */
    public function on_a_follow_up_turn_the_resolved_question_is_the_reference(): void
    {
        $resolved = 'What must be checked before a night flight?';
        $embedding = $this->embedding([self::Q410 => 0.4], $resolved);
        $writer = new FollowUpWriter(new ScriptedUsageLlm(json: $this->reply([2 => [self::F410, self::Q410]])), $embedding);

        $written = $writer->write('And at night?', self::ANSWER, $this->passages(), 'en', null, 3, $resolved, 0.25);

        $this->assertSame([[$resolved, self::Q410]], $embedding->batches);
        $this->assertSame(0.4, $written['follow_ups'][0]['similarity']);
    }

    /** @test Never unfiltered: a gate that cannot be applied attaches nothing. */
    public function when_the_gate_cannot_be_applied_there_is_no_follow_up(): void
    {
        $failing = Mockery::mock(\Condoedge\Ai\Contracts\EmbeddingProviderInterface::class);
        $failing->shouldReceive('embedBatch')->once()->andThrow(new \RuntimeException('embedding provider down'));
        $short = Mockery::mock(\Condoedge\Ai\Contracts\EmbeddingProviderInterface::class);
        $short->shouldReceive('embedBatch')->once()->andReturn([[1.0, 0.0]]);

        foreach ([$failing, $short, null] as $embedding) {
            $writer = new FollowUpWriter(new ScriptedUsageLlm(json: $this->fourQuestions()), $embedding);

            $written = $writer->write(self::QUESTION, self::ANSWER, $this->passages(), 'en', null, 3, '', 0.25);

            $this->assertSame([], $written['follow_ups']);
            $this->assertSame(['relevance_unavailable' => 4], $written['dropped']);
        }
    }

    /** @test A minimum of 0 switches the gate off: no embedding call, no similarity. */
    public function a_minimum_of_zero_disables_the_gate(): void
    {
        $embedding = $this->embedding([]);
        $writer = new FollowUpWriter(new ScriptedUsageLlm(json: $this->fourQuestions()), $embedding);

        $written = $writer->write(self::QUESTION, self::ANSWER, $this->passages(), 'en', null, 3, '', 0.0);

        $this->assertSame([], $embedding->batches);
        $this->assertSame([1, 2, 3], array_column($written['follow_ups'], 'passage'));
        $this->assertArrayNotHasKey('similarity', $written['follow_ups'][0]);
        $this->assertArrayNotHasKey('scored', $written);
    }

    /** @test No question survived the other checks: nothing to embed. */
    public function nothing_is_embedded_when_no_question_survived(): void
    {
        $embedding = $this->embedding([]);
        $writer = new FollowUpWriter(new ScriptedUsageLlm(json: $this->reply([1 => [self::F402, self::QUESTION]])), $embedding);

        $written = $writer->write(self::QUESTION, self::ANSWER, $this->passages(), 'en', null, 3, '', 0.25);

        $this->assertSame([], $written['follow_ups']);
        $this->assertSame([], $embedding->batches);
    }

    // ------------------------------------------------------------- citations

    /** @test */
    public function cited_passages_are_the_valid_markers_of_the_answer(): void
    {
        $this->assertSame([1, 3], FollowUpWriter::citedPassages('A [3]. B [1][3]. C [7]. D [0]. E [x].', 4));
        $this->assertSame([], FollowUpWriter::citedPassages('No marker at all.', 4));
        $this->assertSame([], FollowUpWriter::citedPassages('A [1].', 0));
    }
}
