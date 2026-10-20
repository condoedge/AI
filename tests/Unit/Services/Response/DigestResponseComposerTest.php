<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\Digest\DigestSourceResult;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\Attach\AttachedActions;
use Condoedge\Ai\Services\Response\DigestResponseComposer;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The digest composer makes exactly ONE LLM call (the digest turn's whole LLM budget
 * beyond QU) — and since p12 H1 that call writes only the FRAMING: one opening line,
 * one line per section beside its `{{section:<key>}}` placeholder, the
 * `{{nothing_pending}}` slot and ONE closing deepening offer. CODE renders every
 * section (heading + TableRenderer table + "showing N of M" + money total) and the
 * combined honest-empty / could-not-be-checked lines, so the honesty rubric is
 * structural, not prompt goodwill:
 *  - a section placeholder the model omitted is inserted in registry order;
 *    unknown / duplicate placeholders are stripped; model-written tables dropped;
 *  - plain-empty sources are ONE combined line, never a section (H4);
 *  - fallback rows render under their fallback label, with no "none pending" line (H4);
 *  - failed sources reduce to one honest line (raw error text never shown);
 *  - per-source truncation is always disclosed inline, in the question's language;
 *  - declared money columns carry the currency (H2); coded columns show their
 *    legend labels (H3);
 *  - the export chip of a whole-digest export is titled by the digest itself (H6);
 *  - amendment B1/B2 metadata as before.
 */
class DigestResponseComposerTest extends TestCase
{
    private array $chatCalls = [];

    public function setUp(): void
    {
        parent::setUp();

        config([
            'ai.response_generation.currency_symbol' => '$',
            'entities' => [
                'App\\Models\\Bill' => ['graph' => ['label' => 'Bill'], 'metadata' => ['money_properties' => ['due_amount', 'total_amount']]],
                'App\\Models\\Task' => ['graph' => ['label' => 'Task'], 'metadata' => ['common_properties' => [
                    'urgent' => 'Priority flag (-2=Low, -1=Regular, 1=High)',
                    'status' => 'Task status (1=Open, 2=Pending, 3=Processing, 4=Closed)',
                    'level' => 'Escalation level',
                ]]],
            ],
        ]);
    }

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

    private function systemPrompt(): string
    {
        return (string) ($this->chatCalls[0][0]['content'] ?? '');
    }

    private function tasks(int $total = 130): DigestSourceResult
    {
        return new DigestSourceResult(
            key: 'open_tasks', label: 'Open tasks',
            rows: [['id' => 1, 'title' => 'Fix roof', 'urgent' => 1, 'status' => 3]],
            rowCount: 1, trueTotal: $total, capHit: true,
            cypher: 'MATCH (t:Task) RETURN t.id AS id, t.title AS title, t.urgent AS urgent, t.status AS status',
        );
    }

    private function bills(): DigestSourceResult
    {
        return new DigestSourceResult(
            key: 'bills_unpaid', label: 'Approved bills not yet paid',
            rows: [
                ['id' => 123, 'bill_number' => '15403', 'due_amount' => 574.88],
                ['id' => 154, 'bill_number' => 'BIL-000003', 'due_amount' => 7022.87],
            ],
            rowCount: 2,
            cypher: 'MATCH (b:Bill) RETURN b.id AS id, b.bill_number AS bill_number, b.due_amount AS due_amount',
        );
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
        $this->assertStringStartsWith('Here is your digest.', $result['answer']);
        $this->assertNull($result['cypher']);
        $this->assertSame([], $result['referenced_files']);
    }

    /**
     * @test p12 H1: the model writes the framing beside each placeholder; CODE inserts the
     * section's heading, its rendered table and the "showing N of M" line.
     */
    public function each_section_placeholder_becomes_the_code_rendered_section(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering(
            "Two things stand out.\n\n{{section:open_tasks}} 130 tasks are open; the roof is the oldest.\n\n"
            . "{{section:bills_unpaid}} Two approved bills are still unpaid.\n\nWant me to break the open tasks down by building?"
        ));

        $result = $composer->compose('What next?', $this->ctx(), [$this->tasks(), $this->bills()]);

        $this->assertSame(
            "Two things stand out.\n\n"
            . "### Open tasks\n130 tasks are open; the roof is the oldest.\n\n"
            . "| Title | Urgent | Status |\n| --- | --- | --- |\n| Fix roof | High | Processing |\n\n"
            . "Showing 1 of 130.\n\n"
            . "### Approved bills not yet paid\nTwo approved bills are still unpaid.\n\n"
            . "| Bill Number | Due Amount |\n| --- | --- |\n| 15403 | \$574.88 |\n| BIL-000003 | \$7,022.87 |\n\n"
            . "Total Due Amount: \$7,597.75\n\n"
            . "Want me to break the open tasks down by building?",
            $result['answer']
        );
        $this->assertSame([], $result['metadata']['response']['digest_composition']['sections_appended']);
    }

    /**
     * @test A digest section's query is the HOST's own (ai.attention_sources): its aliases are
     * the names the host chose for its columns (`r.name AS change`, `u.name AS submitted_by`),
     * not a generator's guess — the property-header rule of the data answer (eval p17-B) never
     * renames them.
     */
    public function a_host_declared_source_keeps_its_own_column_names(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('{{section:pending_contact_reviews}} One change awaits review.'));

        $result = $composer->compose('What next?', $this->ctx(), [new DigestSourceResult(
            key: 'pending_contact_reviews', label: 'Pending contact reviews',
            rows: [['id' => 7, 'change' => 'Phone number', 'submitted_by' => 'Karen Douglas', 'created_at' => '2026-09-30']],
            rowCount: 1,
            cypher: 'MATCH (r:Review) OPTIONAL MATCH (r)-[:BELONGS_TO_USER]->(u:User) '
                . 'RETURN r.id AS id, r.name AS change, u.name AS submitted_by, r.created_at AS created_at ORDER BY r.created_at ASC',
        )]);

        $this->assertStringContainsString('| Change | Submitted By | Created At |', $result['answer']);
    }

    /** @test eval p14-B digest t2: every framing line began "— " (the separator after the placeholder). */
    public function a_separator_after_the_placeholder_is_not_part_of_the_framing(): void
    {
        // "\n" escapes, never literal line breaks: the expectation must not depend on
        // this file's own line endings (a CRLF checkout made it "\r\n").
        $composer = new DigestResponseComposer($this->llmAnswering(
            "Two things stand out.\n\n{{section:open_tasks}} — 130 tasks are open.\n\n"
            . "{{section:bills_unpaid}}: Two bills are unpaid.\n\nWant the details?"
        ));

        $answer = $composer->compose('What next?', $this->ctx(), [$this->tasks(), $this->bills()])['answer'];

        $this->assertStringContainsString("### Open tasks\n130 tasks are open.", $answer);
        $this->assertStringContainsString("### Approved bills not yet paid\nTwo bills are unpaid.", $answer);
    }

    /** @test p12 H1: the model is never asked to reproduce rows — it frames from the facts. */
    public function the_prompt_asks_for_framing_with_placeholders_and_never_for_tables(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('ok'));

        $composer->compose('What next?', $this->ctx(), [$this->tasks(), $this->bills()]);

        $system = $this->systemPrompt();
        $prompt = $this->promptText();
        $this->assertStringContainsString('{{section:open_tasks}}', $prompt);
        $this->assertStringContainsString('{{section:bills_unpaid}}', $prompt);
        $this->assertStringContainsString('{{nothing_pending}}', $system);
        $this->assertMatchesRegularExpression('/never (write|reproduce)[^.\n]*tables?/i', $system);
        // The facts the framing needs: the true total and the system-computed money total.
        $this->assertStringContainsString('130', $prompt);
        $this->assertStringContainsString('$7,597.75', $prompt);
        $this->assertStringNotContainsString('Render the sections', $system, 'the old re-typing instruction is gone');
    }

    /**
     * @test eval p17-B digest t0: given "Facts: 5 shown of 189 in total — speak of the 189"
     * the model wrote "5 units have expired insurance out of 189 total" (five sections of
     * one answer). Code prints "Showing 5 of 189." itself, so the model is handed the TRUE
     * TOTAL only — the number of rows on screen is nowhere in the facts of a partial section.
     */
    public function a_partial_section_hands_the_model_its_true_total_and_never_the_shown_count(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('{{section:expired_insurance}} {{section:receivables}} {{section:capped}}'));
        $unit = static fn (string $name): array => ['unit_name' => $name, 'insurer' => 'Acme'];

        $answer = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(
                key: 'expired_insurance', label: 'Units with expired insurance',
                rows: array_map($unit, ['A', 'B', 'C', 'D', 'E']),
                rowCount: 5, trueTotal: 189, capHit: true,
            ),
            new DigestSourceResult(
                key: 'receivables', label: 'Overdue receivables',
                rows: array_map(static fn (string $n): array => ['invoice_number' => $n, 'amount_due' => 10.0], ['A', 'B', 'C', 'D', 'E']),
                rowCount: 5, trueTotal: 77, capHit: true,
                cypher: 'MATCH (i:Bill) RETURN i.invoice_number AS invoice_number, i.due_amount AS amount_due',
            ),
            // The total could not be counted: the cap is all that is known.
            new DigestSourceResult(key: 'capped', label: 'Capped', rows: array_map($unit, ['A', 'B', 'C', 'D', 'E']), rowCount: 5, capHit: true),
        ])['answer'];

        $user = (string) ($this->chatCalls[0][1]['content'] ?? '');
        $this->assertStringContainsString('Facts: 189 in total', $user);
        $this->assertStringContainsString('Facts: 77 in total', $user);
        $this->assertDoesNotMatchRegularExpression('/\b5\b/', $user, 'the shown count is nowhere in the facts');
        $this->assertStringNotContainsString('shown of', $user);
        // The rows the model sees are named for what they are: a part of the total.
        $this->assertSame(3, preg_match_all('/^Sample rows \(/m', $user));
        // No partial money sum, and the instruction not to make one — without the shown count.
        $this->assertMatchesRegularExpression('/never sum/i', $user);
        $this->assertStringNotContainsString('50.00', $user);

        // The model is told who writes the "showing" line …
        $this->assertMatchesRegularExpression('/system prints[^.\n]*showing[^.\n]*never (write|state) how many/i', $this->systemPrompt());
        // … and code still writes it.
        $this->assertStringContainsString('Showing 5 of 189.', $answer);
        $this->assertStringContainsString('Showing 5 of 77.', $answer);
        $this->assertStringContainsString('Showing the first 5 — more may exist.', $answer);
    }

    /** @test a complete section still states its count: every row IS the total. */
    public function a_complete_section_keeps_its_count_and_its_rows_are_not_called_a_sample(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('ok'));

        $composer->compose('What next?', $this->ctx(), [$this->bills()]);

        $user = (string) ($this->chatCalls[0][1]['content'] ?? '');
        $this->assertStringContainsString('Facts: all 2 shown.', $user);
        $this->assertStringContainsString('over all 2: $7,597.75 (system-computed)', $user);
        $this->assertStringNotContainsString('Sample rows', $user);
    }

    /** @test p12 H5: exactly ONE closing deepening offer, in the question's language. */
    public function the_prompt_asks_for_exactly_one_closing_offer_in_the_question_language(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('ok'));

        $composer->compose('Que dois-je faire ensuite ?', $this->ctx('fr'), [$this->tasks()]);

        $system = $this->systemPrompt();
        $this->assertMatchesRegularExpression('/exactly ONE closing/i', $system);
        $this->assertMatchesRegularExpression('/never a menu/i', $system);
        $this->assertStringContainsString("'fr'", $system);
        $this->assertStringNotContainsString('Do not end with offers', $system);
    }

    /**
     * @test p12 H1: an omitted section is inserted in registry order after the last placed
     * section (the closing offer stays last); unknown and duplicate placeholders are stripped.
     */
    public function omitted_sections_are_inserted_in_registry_order_and_unknown_placeholders_stripped(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering(
            "Here is the digest. {{table}}\n\n{{section:bills_unpaid}} Two bills.\n\n{{section:bills_unpaid}} again {{section:nope}}\n\nShall I dig into the bills?"
        ));

        $result = $composer->compose('What next?', $this->ctx(), [
            $this->tasks(),
            $this->bills(),
            new DigestSourceResult(key: 'threads', label: 'Flagged threads', rows: [['id' => 5, 'subject' => 'Leak']], rowCount: 1),
        ]);

        $answer = $result['answer'];
        $this->assertStringStartsWith('Here is the digest.', $answer);
        $this->assertStringEndsWith('Shall I dig into the bills?', $answer);
        $this->assertStringNotContainsString('{{', $answer);
        $this->assertSame(1, substr_count($answer, '### Approved bills not yet paid'));
        $this->assertSame(1, substr_count($answer, '### Open tasks'));
        $bills = strpos($answer, '### Approved bills not yet paid');
        $tasks = strpos($answer, '### Open tasks');
        $threads = strpos($answer, '### Flagged threads');
        $this->assertTrue($bills < $tasks && $tasks < $threads, 'appended after the placed one, in registry order');
        $this->assertLessThan(strpos($answer, 'Shall I dig'), $threads);
        $this->assertSame(['open_tasks', 'threads'], $result['metadata']['response']['digest_composition']['sections_appended']);
    }

    /** @test p12 H1: with no placeholder at all, sections go before the closing paragraph. */
    public function without_any_placeholder_the_sections_go_before_the_closing_paragraph(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering("Here is what needs you.\n\nWant me to start with the tasks?"));

        $answer = $composer->compose('What next?', $this->ctx(), [$this->tasks()])['answer'];

        $this->assertStringStartsWith("Here is what needs you.\n\n### Open tasks\n\n| Title |", $answer);
        $this->assertStringEndsWith("Showing 1 of 130.\n\nWant me to start with the tasks?", $answer);
    }

    /** @test p12 H1: a table or heading the model wrote anyway never doubles the code-rendered one. */
    public function model_written_tables_and_headings_before_a_placeholder_are_dropped(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering(
            "### Open tasks\n{{section:open_tasks}} The roof first.\n| Title |\n| --- |\n| Fix roof |"
        ));

        $answer = $composer->compose('What next?', $this->ctx(), [$this->tasks()])['answer'];

        $this->assertSame(1, substr_count($answer, '### Open tasks'));
        $this->assertSame(1, substr_count($answer, '| Fix roof |'));
        $this->assertStringStartsWith("### Open tasks\nThe roof first.\n\n| Title | Urgent | Status |", $answer);
    }

    /** @test the question's language: a translated heading rides the placeholder; canned lines are localized. */
    public function a_translated_heading_and_localized_truncation_line(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('{{section:open_tasks|Tâches ouvertes}} Le toit d\'abord.'));

        $result = $composer->compose('Quoi faire ?', $this->ctx('fr'), [$this->tasks()]);

        $this->assertStringStartsWith("### Tâches ouvertes\nLe toit d'abord.\n\n| Title |", $result['answer']);
        $this->assertStringContainsString('Affichage de 1 sur 130.', $result['answer']);
        $this->assertSame(['open_tasks'], $result['metadata']['response']['digest_truncation_appended']);
        $this->assertSame(['open_tasks' => 'inline'], $result['metadata']['response']['digest_truncation_placement']);
    }

    /** @test a capped source whose total stayed unknown says more may exist. */
    public function an_unknown_total_is_hedged_by_code(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('{{section:open_tasks}}'));

        $answer = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B']], rowCount: 2, capHit: true),
        ])['answer'];

        $this->assertStringContainsString('Showing the first 2 — more may exist.', $answer);
    }

    /**
     * @test p12 regression: "$" in money cells is never read as a back-reference
     * (preg_replace_callback, never a replacement string).
     */
    public function money_cells_with_dollar_signs_survive_insertion(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('{{section:bills_unpaid}} Two bills.'));

        $answer = $composer->compose('What next?', $this->ctx(), [$this->bills()])['answer'];

        $this->assertStringContainsString('| 15403 | $574.88 |', $answer);
        $this->assertStringContainsString('| BIL-000003 | $7,022.87 |', $answer);
    }

    /** @test p12 H2: a truncated money section never states a sum of the shown rows as a total. */
    public function a_truncated_money_section_gets_no_total_line(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('{{section:receivables}}'));

        $result = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(
                key: 'receivables', label: 'Overdue receivables',
                rows: [['id' => 1, 'invoice_number' => 'COT-1', 'amount_due' => 50.0], ['id' => 2, 'invoice_number' => 'COT-2', 'amount_due' => 170.83]],
                rowCount: 2, trueTotal: 75, capHit: true,
                cypher: 'MATCH (i:Bill) RETURN i.id AS id, i.invoice_number AS invoice_number, i.due_amount AS amount_due',
            ),
        ]);

        $this->assertStringContainsString('| COT-2 | $170.83 |', $result['answer']);
        $this->assertStringNotContainsString('Total', $result['answer']);
        $this->assertStringNotContainsString('220.83', $this->promptText(), 'no partial sum handed to the model either');
        $this->assertMatchesRegularExpression('/never sum/i', $this->promptText());
    }

    /** @test p12 H3: legend codes show labels in the table AND the prompt; a bare code column is omitted. */
    public function coded_columns_show_legend_labels_and_bare_codes_are_omitted(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('{{section:open_tasks}}'));

        $answer = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(
                key: 'open_tasks', label: 'Open tasks',
                rows: [['id' => 1, 'title' => 'Leak', 'urgent' => 1, 'level' => 2], ['id' => 2, 'title' => 'Door', 'urgent' => -1, 'level' => 1]],
                rowCount: 2,
                cypher: 'MATCH (t:Task) RETURN t.id AS id, t.title AS title, t.urgent AS urgent, t.level AS level',
            ),
        ])['answer'];

        $this->assertStringContainsString("| Title | Urgent |\n| --- | --- |\n| Leak | High |\n| Door | Regular |", $answer);
        $this->assertStringNotContainsString('Level', $answer);
        $this->assertStringContainsString('| Leak | High |', $this->promptText());
    }

    /**
     * @test p12 H4: plain-empty sources are ONE combined line (code-rendered at the slot),
     * never a section of their own; a failed source is one honest line; raw errors hidden.
     */
    public function empty_and_failed_sources_render_as_combined_lines_never_as_sections(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering(
            "{{section:open_tasks}} Busy week.\n\n{{nothing_pending}}\n\nWant the tasks by building?"
        ));

        $result = $composer->compose('What next?', $this->ctx(), [
            $this->tasks(),
            new DigestSourceResult(key: 'bills_pending', label: 'Bills awaiting approval', rowCount: 0),
            new DigestSourceResult(key: 'receivables', label: 'Overdue receivables', rowCount: 0, fallbackError: 'timeout'),
            new DigestSourceResult(key: 'broken', label: 'Broken source', rowCount: 0, error: 'rate limit exceeded'),
        ]);

        $answer = $result['answer'];
        $this->assertStringContainsString(
            "Showing 1 of 130.\n\nNothing pending in: Bills awaiting approval, Overdue receivables.\n\n"
            . "Recent history for Overdue receivables could not be loaded.\n\n"
            . "Broken source could not be checked right now.\n\nWant the tasks by building?",
            $answer
        );
        $this->assertStringNotContainsString('### Bills awaiting approval', $answer);
        $this->assertStringNotContainsString('### Broken source', $answer);
        $this->assertStringNotContainsString('rate limit exceeded', $answer . $this->promptText());
        $this->assertStringNotContainsString('{{section:bills_pending}}', $this->promptText(), 'empty sources get no placeholder');
    }

    /** @test p12 H4: the combined line is placed by code when the model omitted its slot. */
    public function the_combined_empty_line_follows_the_sections_when_its_slot_is_omitted(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering("{{section:open_tasks}} Busy.\n\nWant more?"));

        $answer = $composer->compose('Que faire ?', $this->ctx('fr'), [
            $this->tasks(),
            new DigestSourceResult(key: 'bills_pending', label: 'Factures à approuver', rowCount: 0),
        ])['answer'];

        $this->assertStringEndsWith("Affichage de 1 sur 130.\n\nRien en attente dans : Factures à approuver.\n\nWant more?", $answer);
    }

    /** @test an empty_text source keeps its own section with the configured note. */
    public function an_empty_text_source_renders_its_configured_note_as_its_section(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('{{section:insurance}} All good.'));

        $answer = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(key: 'insurance', label: 'Expired unit insurance', rowCount: 0, emptyText: 'All unit insurance certificates are current.'),
        ])['answer'];

        $this->assertSame("### Expired unit insurance\nAll good.\n\nAll unit insurance certificates are current.", $answer);
    }

    /**
     * @test p12 H4: fallback rows ARE the content — rendered under the fallback label with
     * no "none pending" line above the table; the prompt frames them as past history.
     */
    public function fallback_rows_render_under_the_fallback_label_without_a_none_pending_line(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('{{section:upcoming_meetings}} The latest ones were in October 2023.'));

        $result = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(
                key: 'upcoming_meetings', label: 'Upcoming meetings',
                rows: [['id' => 9, 'name' => 'AGM 2023', 'start_date' => '2023-10-24']],
                rowCount: 1, usedFallback: true,
                fallbackLabel: 'Most recent meetings — none scheduled',
            ),
        ]);

        $this->assertSame(
            "### Most recent meetings — none scheduled\nThe latest ones were in October 2023.\n\n"
            . "| Name | Start Date |\n| --- | --- |\n| AGM 2023 | 2023-10-24 |",
            $result['answer']
        );
        $prompt = $this->promptText();
        $this->assertStringContainsString('NOT the asked-for items', $prompt);
        $this->assertMatchesRegularExpression('/never call them upcoming/i', $prompt);
        $this->assertStringNotContainsString('State the honest "none pending', $prompt);
        $this->assertSame(['upcoming_meetings'], $result['metadata']['response']['digest']['empty_sources'], 'still empty for "is anything pending"');
    }

    /** @test Phase 8 batch A: the model sees masked rows; the user table shows real values. */
    public function rows_reach_the_model_masked_and_the_user_table_unmasked(): void
    {
        config(['ai.access_control.identifying_fields' => ['*' => ['email']]]);
        $composer = new DigestResponseComposer($this->llmAnswering('{{section:open_tasks}} Write to [email#1] about the roof.'));

        $result = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [[
                'id' => 1,
                'title' => 'Fix roof',
                'owner_email' => 'karen@x.test',
                'action_links' => ['[View: Fix roof](entity://Task/1/view)'],
            ]], rowCount: 1),
        ]);

        $prompt = $this->promptText();
        $this->assertStringContainsString('| Fix roof | [email#1] |', $prompt);
        $this->assertStringNotContainsString('karen@x.test', $prompt);
        $this->assertStringNotContainsString('entity://', $prompt);
        $this->assertStringNotContainsString('"title":', $prompt, 'no JSON rows');
        $this->assertStringContainsString('Write to karen@x.test about the roof.', $result['answer']);
        $this->assertStringNotContainsString('entity://', $result['answer']);
    }

    /** @test */
    public function source_facts_appear_in_registry_order_with_labels_and_descriptions_but_no_links(): void
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
        $this->assertStringNotContainsString('action://', $prompt);
        $this->assertLessThan(strpos($prompt, 'Flagged inbox threads'), strpos($prompt, 'Open and urgent tasks'));
    }

    /** @test p11 G1: a column empty in EVERY row of a section is dropped from that section's table. */
    public function all_empty_columns_are_dropped_from_a_sections_table(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('ok'));

        $answer = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(key: 'work_orders', label: 'Work orders', rows: [
                ['id' => 1, 'job' => 'Paint', 'price' => null, 'notes' => '', 'tags' => [], 'urgent' => 0],
                ['id' => 2, 'job' => 'Roof', 'price' => null, 'notes' => ' ', 'tags' => [], 'urgent' => '0'],
            ], rowCount: 2),
            new DigestSourceResult(key: 'bills', label: 'Bills', rows: [
                ['id' => 3, 'bill_number' => 'B-1', 'price' => 12.5],
            ], rowCount: 1),
        ])['answer'];

        $this->assertStringContainsString('| Job | Urgent |', $answer, '0 / "0" are values — the column stays');
        $this->assertStringNotContainsString('| Job | Price', $answer);
        $this->assertStringNotContainsString('Notes', $answer);
        $this->assertStringNotContainsString('Tags', $answer);
        $this->assertStringContainsString('| Bill Number | Price |', $answer, 'per SECTION: another section keeps its own Price');
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
        $this->assertSame(['bills', 'meetings'], $digest['empty_sources']);
        $this->assertSame(['broken'], $digest['failed_sources']);
        $this->assertSame(['work_orders'], $digest['skipped_sources'], 'B2: cap overflow rides the trace');
        $this->assertArrayNotHasKey('digest_truncation_appended', $result['metadata']['response']);
    }

    /** @test */
    public function a_combined_export_ask_is_acknowledged_with_the_attached_file(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering('Digest.'));

        $result = $composer->compose('Give me an excel of everything needing my attention', $this->ctx(), [
            new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [['id' => 1]], rowCount: 1),
        ], [], 'excel');

        $prompt = $this->promptText();
        $this->assertStringContainsString('excel', strtolower($prompt));
        $this->assertStringContainsString('attached below', $prompt);
        // Suite standard: the file acknowledgment IS the turn's single closer (p13/p14 had two closers).
        $this->assertStringContainsString('Exactly ONE closing sentence: that the EXCEL file with everything shown is attached below', $prompt);
        $this->assertStringContainsString('It REPLACES the deepening offer', $prompt);
        $this->assertStringNotContainsString('a single deepening offer', $prompt);
        $this->assertStringNotContainsString('Your closing offer is a deeper look', $prompt);
        $this->assertStringNotContainsString('before the closing offer', $prompt);
        $this->assertStringNotContainsString('export that', $prompt);
        $this->assertStringContainsString('do not write URLs or action links', $prompt);
        $this->assertSame('excel', $result['metadata']['response']['digest']['export_followup_offered']);
    }

    /**
     * @test p12 H6: a whole-digest export chip is titled by the digest itself (in the
     * question's language), never by QU's distilled subject ("tasks needing attention");
     * the write-back subject follows.
     */
    public function the_export_chip_and_subject_carry_the_digest_title_in_the_question_language(): void
    {
        $attached = new AttachedActions([
            ['role' => AttachedActions::ROLE_SECTION, 'invocation' => new ActionInvocation('tasks', null, null, [], 'Open tasks')],
            ['role' => AttachedActions::ROLE_NEXT, 'invocation' => new ActionInvocation('export_excel', null, null, ['title' => 'tasks needing attention', 'mode' => 'table'], 'Download Excel')],
        ]);

        $en = (new DigestResponseComposer($this->llmAnswering('ok')))->compose(
            'Give me an excel of everything needing my attention', $this->ctx('en', 'tasks needing attention'),
            [new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [['id' => 1]], rowCount: 1)],
            [], 'excel', [], $attached
        );
        $fr = (new DigestResponseComposer($this->llmAnswering('ok')))->compose(
            'Un excel de tout ce qui demande mon attention', $this->ctx('fr', 'tâches à traiter'),
            [new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [['id' => 1]], rowCount: 1)],
            [], 'excel', [], $attached
        );

        $this->assertSame(['title' => 'Items needing your attention', 'mode' => 'table'], $en['metadata']['response']['invocations'][1]['params']);
        $this->assertSame([], $en['metadata']['response']['invocations'][0]['params'], 'only the export chip is retitled');
        $this->assertSame('Items needing your attention', $en['metadata']['subject']);
        $this->assertSame('Éléments qui demandent votre attention', $fr['metadata']['response']['invocations'][1]['params']['title']);
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

    // ------------------------------------------- p22-B digest t0: lower-case framing

    /**
     * @test eval p22-1001-B digest t0: on a byte-identical prompt the model wrote every framing
     * sentence in lower case after its placeholder ("{{section:x}} — there are 130 tasks not
     * yet closed."). Code places those lines, so code gives each its capital.
     */
    public function p22_b_a_framing_line_the_model_started_in_lower_case_gets_its_capital(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering(
            "you have several important tasks to address next.\n\n"
            . "{{section:open_tasks}} — there are 130 tasks not yet closed.\n\n"
            . "{{section:bills_unpaid}} there are 2 approved bills not yet paid, totaling \$7,597.75.\n\n"
            . "{{nothing_pending}}\n\nwant me to go through the open tasks one by one?"
        ));

        $answer = $composer->compose('What should I take care of next?', $this->ctx(), [
            $this->tasks(),
            $this->bills(),
            new DigestSourceResult(key: 'bills_pending', label: 'Supplier bills awaiting approval', rowCount: 0),
        ])['answer'];

        $this->assertSame(
            "You have several important tasks to address next.\n\n"
            . "### Open tasks\nThere are 130 tasks not yet closed.\n\n"
            . "| Title | Urgent | Status |\n| --- | --- | --- |\n| Fix roof | High | Processing |\n\n"
            . "Showing 1 of 130.\n\n"
            . "### Approved bills not yet paid\nThere are 2 approved bills not yet paid, totaling \$7,597.75.\n\n"
            . "| Bill Number | Due Amount |\n| --- | --- |\n| 15403 | \$574.88 |\n| BIL-000003 | \$7,022.87 |\n\n"
            . "Total Due Amount: \$7,597.75\n\n"
            . "Nothing pending in: Supplier bills awaiting approval.\n\n"
            . "Want me to go through the open tasks one by one?",
            $answer
        );
    }

    /**
     * @test Only a line that starts with a plain lower-case word is touched.
     * @dataProvider framingLines
     */
    public function a_framing_line_keeps_or_gets_its_first_letter(string $written, string $shown): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering("{{section:open_tasks}} — {$written}"));

        $answer = $composer->compose('What next?', $this->ctx(), [$this->tasks()])['answer'];

        $this->assertStringStartsWith("### Open tasks\n{$shown}\n\n| Title |", $answer);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function framingLines(): array
    {
        return [
            'lower case' => ['there are 130 open tasks.', 'There are 130 open tasks.'],
            'an accented first letter' => ['état des lieux : 130 tâches ouvertes.', 'État des lieux : 130 tâches ouvertes.'],
            'an elided article' => ["l'entretien du toit est la plus ancienne.", "L'entretien du toit est la plus ancienne."],
            'already capitalised' => ['There are 130 open tasks.', 'There are 130 open tasks.'],
            'a proper noun' => ['Julie Rousseau has 130 open tasks.', 'Julie Rousseau has 130 open tasks.'],
            'a digit first' => ['130 tasks are open.', '130 tasks are open.'],
            'a markdown marker first' => ['**130 tasks** are open.', '**130 tasks** are open.'],
            'a quote first' => ['"fix roof" is the oldest of 130.', '"fix roof" is the oldest of 130.'],
            'a word with a capital inside' => ['iCal exports cover the 130 tasks.', 'iCal exports cover the 130 tasks.'],
            'a dotted token (an abbreviation, an address)' => ['e.g. the roof, among 130 tasks.', 'e.g. the roof, among 130 tasks.'],
            'an identifier' => ['team_id 3 has 130 open tasks.', 'team_id 3 has 130 open tasks.'],
            'an e-mail address' => ['julie@example.com has 130 open tasks.', 'julie@example.com has 130 open tasks.'],
        ];
    }

    /**
     * @test What CODE renders is never re-cased: a host label, a configured empty note, a
     * table cell, the code-written lines. Neither is a line that continues the one above it.
     */
    public function code_rendered_text_and_continuation_lines_are_never_re_cased(): void
    {
        $composer = new DigestResponseComposer($this->llmAnswering(
            "you have a few things\nthat need attention.\n\n{{section:open_tasks}} the roof first.\n\n{{section:insurance}} all good here.\n\n- one list item stays as written"
        ));

        $answer = $composer->compose('What next?', $this->ctx(), [
            new DigestSourceResult(key: 'open_tasks', label: 'open tasks', rows: [['id' => 1, 'title' => 'fix roof']], rowCount: 1),
            new DigestSourceResult(key: 'insurance', label: 'expired unit insurance', rowCount: 0, emptyText: 'all unit insurance certificates are current.'),
        ])['answer'];

        $this->assertSame(
            "You have a few things\nthat need attention.\n\n"
            . "### open tasks\nThe roof first.\n\n"
            . "| Title |\n| --- |\n| fix roof |\n\n"
            . "### expired unit insurance\nAll good here.\n\n"
            . "all unit insurance certificates are current.\n\n"
            . "- one list item stays as written",
            $answer
        );
    }
}
