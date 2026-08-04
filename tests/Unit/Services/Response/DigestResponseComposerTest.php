<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\Digest\DigestSourceResult;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Response\DigestResponseComposer;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The digest composer makes exactly ONE LLM call (the digest turn's whole LLM
 * budget beyond QU) over deterministically assembled per-source blocks, and
 * enforces the honesty rubric structurally:
 *  - honest-empty collapse instruction (empty sources become one combined line);
 *  - fallback rows render ONLY under fallback_label, "none scheduled" first;
 *  - failed sources reduce to one honest error line;
 *  - per-source truncation disclosure is BLOCKING — a missing true total is
 *    deterministically appended, never left to LLM goodwill;
 *  - amendment B1: on a combined digest+export turn the composer acknowledges
 *    the export ask as the turn's SINGLE deepening offer and stamps
 *    metadata.digest.export_followup_offered;
 *  - amendment B2: metadata.digest.skipped_sources carries capped-out keys.
 */
class DigestResponseComposerTest extends TestCase
{
    private array $chatCalls = [];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function llmAnswering(string $answer): LlmProviderInterface
    {
        $this->chatCalls = [];
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chat')
            ->andReturnUsing(function ($messages, $options = []) use ($answer) {
                $this->chatCalls[] = $messages;
                return $answer;
            });

        return $llm;
    }

    private function ctx(string $language = 'en', string $searchQuery = 'workspace attention overview'): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->qu = new QueryUnderstandingResult(searchQuery: $searchQuery, language: $language);

        return $ctx;
    }

    private function promptText(): string
    {
        $text = '';
        foreach ($this->chatCalls[0] ?? [] as $message) {
            $text .= ($message['content'] ?? '') . "\n";
        }

        return $text;
    }

    /** @test */
    public function it_makes_exactly_one_llm_call_and_returns_the_standard_response_shape(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('Here is your digest.'));

        $result = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [['id' => 1, 'title' => 'Fix roof']], rowCount: 1),
        ]);

        $this->assertCount(1, $this->chatCalls, 'LLM budget: ONE composition call');
        $this->assertSame('What next?', $result['question']);
        $this->assertSame('Here is your digest.', $result['answer']);
        $this->assertNull($result['cypher']);
        $this->assertSame([], $result['referenced_files']);
        $this->assertIsArray($result['insights']);
        $this->assertIsArray($result['visualizations']);
    }

    /** @test */
    public function source_blocks_appear_in_registry_order_with_labels_descriptions_and_action_links(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('ok'));

        $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(
                key: 'open_tasks', label: 'Open and urgent tasks',
                description: 'Tasks not yet closed; urgent first.',
                rows: [['id' => 1, 'title' => 'Fix roof']], rowCount: 1, actionKey: 'tasks',
            ),
            new DigestSourceResult(
                key: 'flagged_threads', label: 'Flagged inbox threads',
                rows: [['id' => 2, 'subject' => 'Leak']], rowCount: 1, actionKey: 'inbox',
            ),
        ]);

        $prompt = $this->promptText();
        $this->assertStringContainsString('Open and urgent tasks', $prompt);
        $this->assertStringContainsString('Tasks not yet closed; urgent first.', $prompt);
        $this->assertStringContainsString('[Open and urgent tasks](action://tasks)', $prompt);
        $this->assertStringContainsString('[Flagged inbox threads](action://inbox)', $prompt);
        $this->assertLessThan(
            strpos($prompt, 'Flagged inbox threads'),
            strpos($prompt, 'Open and urgent tasks'),
            'registry order = render order'
        );
        $this->assertStringContainsString('Fix roof', $prompt, 'rows ride the block');
    }

    /** @test */
    public function empty_sources_collapse_and_empty_text_sources_render_their_configured_note(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('ok'));

        $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(key: 'bills_pending', label: 'Bills awaiting approval', rowCount: 0),
            new DigestSourceResult(key: 'receivables', label: 'Overdue receivables', rowCount: 0),
            new DigestSourceResult(
                key: 'insurance', label: 'Expired unit insurance', rowCount: 0,
                emptyText: 'All unit insurance certificates are current.',
            ),
        ]);

        $prompt = $this->promptText();
        // Collapse instruction names the plain-empty sources...
        $this->assertStringContainsString('Bills awaiting approval', $prompt);
        $this->assertStringContainsString('Overdue receivables', $prompt);
        $this->assertMatchesRegularExpression('/collaps/i', $prompt, 'the combined nothing-pending line is instructed');
        // ...while the empty_text source renders its own configured note.
        $this->assertStringContainsString('All unit insurance certificates are current.', $prompt);
    }

    /** @test */
    public function fallback_sources_render_under_the_fallback_label_with_the_none_first_honesty_instruction(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('ok'));

        $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(
                key: 'upcoming_meetings', label: 'Upcoming meetings',
                rows: [['id' => 9, 'name' => 'AGM 2023', 'start_date' => '2023-10-24']],
                rowCount: 1, usedFallback: true,
                fallbackLabel: 'Most recent meetings — none scheduled',
            ),
        ]);

        $prompt = $this->promptText();
        $this->assertStringContainsString('Most recent meetings — none scheduled', $prompt);
        $this->assertStringContainsString('NOT the asked-for items', $prompt);
        $this->assertStringContainsString('AGM 2023', $prompt);
    }

    /** @test */
    public function a_failed_source_reduces_to_an_honest_error_line_and_a_failed_fallback_to_empty_plus_note(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('ok'));

        $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(key: 'broken', label: 'Broken source', rowCount: 0, error: 'rate limit exceeded'),
            new DigestSourceResult(
                key: 'meetings', label: 'Upcoming meetings', rowCount: 0,
                fallbackError: 'fallback timeout',
            ),
        ]);

        $prompt = $this->promptText();
        $this->assertStringContainsString('could not be checked', $prompt);
        $this->assertStringNotContainsString('rate limit exceeded', $prompt, 'raw error text never reaches the LLM/user');
        // §3 step 4: failed fallback = the primary's honest-empty + a small note.
        $this->assertStringContainsString('recent-history context could not be loaded', $prompt);
    }

    /** @test */
    public function missing_true_total_disclosure_is_deterministically_appended(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('You have urgent tasks to review.'));

        $result = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(
                key: 'open_tasks', label: 'Open tasks',
                rows: array_map(fn ($i) => ['id' => $i], range(1, 5)),
                rowCount: 5, trueTotal: 130,
            ),
        ]);

        $this->assertStringContainsString('Open tasks: showing 5 of 130.', $result['answer'], 'disclosure is never left to LLM goodwill');
        $this->assertSame(['open_tasks'], $result['metadata']['response']['digest_truncation_appended']);
    }

    /** @test */
    public function a_coincidental_digit_run_inside_a_date_or_amount_never_satisfies_the_truncation_guard(): void
    {
        // trueTotal=13 buried in a date ("2013-10-24") and an amount ("$413.50")
        // is NOT a stated total: the guard is a standalone-number check
        // (StatedNumber), not a raw substring scan — the footer must append.
        $composer = new DigestResponseComposer($this->llmAnswering(
            'The next AGM was on 2013-10-24 and the outstanding balance is $413.50.'
        ));

        $result = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(
                key: 'open_tasks', label: 'Open tasks',
                rows: array_map(fn ($i) => ['id' => $i], range(1, 5)),
                rowCount: 5, trueTotal: 13,
            ),
        ]);

        $this->assertStringContainsString('Open tasks: showing 5 of 13.', $result['answer']);
        $this->assertSame(['open_tasks'], $result['metadata']['response']['digest_truncation_appended']);
    }

    /** @test */
    public function the_deterministic_truncation_footer_follows_the_question_language(): void
    {
        // Language policy (2026-07-23 live failure class): canned text follows
        // the QUESTION language, not the app locale — especially on the exact
        // path where the LLM already disobeyed the disclosure instruction.
        $composer = new DigestResponseComposer($this->llmAnswering('Vous avez des tâches urgentes à traiter.'));

        $result = $composer->compose('Que dois-je faire ensuite ?', $this->ctx('fr'), [
            new DigestSourceResult(
                key: 'open_tasks', label: 'Tâches ouvertes',
                rows: array_map(fn ($i) => ['id' => $i], range(1, 5)),
                rowCount: 5, trueTotal: 130,
            ),
        ]);

        $this->assertStringContainsString('Tâches ouvertes : affichage de 5 sur 130.', $result['answer']);
        $this->assertSame(['open_tasks'], $result['metadata']['response']['digest_truncation_appended']);
    }

    /** @test */
    public function an_answer_already_disclosing_the_true_total_is_left_untouched(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('Showing 5 of 130 open tasks.'));

        $result = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(
                key: 'open_tasks', label: 'Open tasks',
                rows: array_map(fn ($i) => ['id' => $i], range(1, 5)),
                rowCount: 5, trueTotal: 130,
            ),
        ]);

        $this->assertSame('Showing 5 of 130 open tasks.', $result['answer']);
        $this->assertArrayNotHasKey('digest_truncation_appended', $result['metadata']['response']);
    }

    /** @test */
    public function metadata_digest_carries_per_source_results_empty_failed_and_skipped_sources(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('ok'));

        $result = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [['id' => 1]], rowCount: 1, trueTotal: null),
            new DigestSourceResult(key: 'bills', label: 'Bills', rowCount: 0),
            new DigestSourceResult(key: 'broken', label: 'Broken', rowCount: 0, error: 'boom'),
            new DigestSourceResult(
                key: 'meetings', label: 'Meetings', rows: [['id' => 9]], rowCount: 1,
                usedFallback: true, fallbackLabel: 'Recent',
            ),
        ], ['work_orders']);

        $digest = $result['metadata']['response']['digest'];

        $this->assertSame('open_tasks', $digest['sources'][0]['key']);
        $this->assertSame(1, $digest['sources'][0]['row_count']);
        $this->assertNull($digest['sources'][0]['true_total']);
        $this->assertFalse($digest['sources'][0]['used_fallback']);
        $this->assertNull($digest['sources'][0]['error']);
        $this->assertSame('boom', $digest['sources'][2]['error']);
        $this->assertTrue($digest['sources'][3]['used_fallback']);

        // A fallback source still counts as EMPTY for "is anything pending".
        $this->assertSame(['bills', 'meetings'], $digest['empty_sources']);
        $this->assertSame(['broken'], $digest['failed_sources']);
        $this->assertSame(['work_orders'], $digest['skipped_sources'], 'B2: cap overflow rides the trace');
    }

    /** @test */
    public function a_combined_export_ask_is_acknowledged_as_the_single_deepening_offer(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('Digest with export offer.'));

        $result = $composer->compose('Give me an excel of everything needing my attention', $this->ctx(), [
            new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [['id' => 1]], rowCount: 1),
        ], [], 'excel');

        $prompt = $this->promptText();
        // B1: the composer must be TOLD about the export ask and instructed to
        // make the follow-up availability the turn's one closing offer.
        $this->assertStringContainsString('excel', strtolower($prompt));
        $this->assertStringContainsString('export that', $prompt);
        $this->assertMatchesRegularExpression('/single|one|only/i', $prompt);

        $this->assertSame('excel', $result['metadata']['response']['digest']['export_followup_offered']);
    }

    /** @test */
    public function without_an_export_ask_no_export_followup_marker_is_stamped(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('ok'));

        $result = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [['id' => 1]], rowCount: 1),
        ]);

        $this->assertArrayNotHasKey('export_followup_offered', $result['metadata']['response']['digest']);
    }

    /** @test */
    public function data_carries_the_source_keyed_rows_for_the_conversation_write_back(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('ok'));

        $result = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [['id' => 1, 'title' => 'Fix roof']], rowCount: 1),
            new DigestSourceResult(key: 'threads', label: 'Threads', rows: [['id' => 2, 'subject' => 'Leak']], rowCount: 1),
        ]);

        // Flat rows (recordResponse slices data directly), each stamped with its
        // source so a follow-up "export that" keeps the attribution.
        $this->assertCount(2, $result['data']);
        $this->assertSame('open_tasks', $result['data'][0]['attention_source']);
        $this->assertSame('Fix roof', $result['data'][0]['title']);
        $this->assertSame('threads', $result['data'][1]['attention_source']);
        $this->assertSame(2, $result['stats']['result_count']);
    }

    /** @test */
    public function the_question_language_threads_into_the_composition_instructions(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('Voici votre résumé.'));

        $composer->compose('Que dois-je faire ensuite ?', $this->ctx('fr'), [
            new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [['id' => 1]], rowCount: 1),
        ]);

        $this->assertStringContainsString("'fr'", $this->promptText(), 'the QU-detected language pins the answer language');
    }
}
