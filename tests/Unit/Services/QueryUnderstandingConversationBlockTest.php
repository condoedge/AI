<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Task 1.22 — Query Understanding sees the conversation.
 *
 * Live failure (eval 2026-09-29, part B): QU received ONLY the question text
 * although its own prompt asked it to judge follow_up_reference (and, at the
 * time, needs_clarification) "from the conversation context".
 * follow_up_reference was false on ALL 14 turns, and "Parfait — exporte-la en
 * Excel." (previous turn: a 10-row roster) came out needs_clarification=true.
 *
 * CONTRACT PINNED HERE: when the turn carries a conversation_context with
 * history / a previous result, the QU prompt carries a compact CONVERSATION
 * block (recent exchanges verbatim-but-truncated + the result memory: subject,
 * entity types, COLUMNS, count, referenced file names) — never row VALUES.
 * Without conversation the block is absent (zero tokens).
 */
class QueryUnderstandingConversationBlockTest extends TestCase
{
    /** The rendered block's header (the static key docs only NAME the block). */
    private const BLOCK = 'CONVERSATION SO FAR (authoritative';

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function logger(): AiLogger
    {
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('debug')->byDefault();
        $logger->shouldReceive('error')->byDefault();

        return $logger;
    }

    /**
     * @param array<int, array<string, mixed>>|null $captured
     */
    private function capturingService(?array &$captured): QueryUnderstandingService
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages) use (&$captured) {
            $captured = $messages;
            return ['search_query' => 'x', 'follow_up_reference' => true, 'language' => 'fr'];
        });

        return new QueryUnderstandingService($llm, $this->logger());
    }

    /**
     * The live part-B shape: a French roster turn, then the pronoun export.
     */
    private function rosterConversation(): array
    {
        return [
            'focused_entity' => 'PersonTeam',
            'last_subject' => 'membres de l\'équipe avec leurs rôles',
            'last_entity_types' => ['PersonTeam', 'User'],
            'last_cypher_query' => 'MATCH (t:Team)<-[:MEMBER_OF]-(p:PersonTeam) RETURN p.name, p.role LIMIT 10',
            'last_result_count' => 10,
            'last_result_sample' => [
                ['member_name' => 'Julie Rousseau', 'role' => 'lead', 'email' => 'julie.rousseau@example.test'],
                ['member_name' => 'Marc Tremblay', 'role' => 'accountant', 'email' => 'marc@example.test'],
            ],
            'last_referenced_files' => [
                ['name' => 'Reglement-immeuble.pdf', 'id' => 'f-1', 'snippet' => 'Article 12 ...'],
            ],
            'recent_exchanges' => [
                ['role' => 'user', 'question' => 'Liste des membres de mon équipe avec leurs rôles', 'answer_summary' => null],
                ['role' => 'assistant', 'question' => null, 'answer_summary' => 'Voici les 10 premiers membres de votre équipe (523 au total) : Julie Rousseau (lead), Marc Tremblay (accountant)...'],
            ],
        ];
    }

    private function fullPrompt(?array $captured): string
    {
        return implode("\n", array_map(
            static fn(array $m): string => (string) ($m['content'] ?? ''),
            $captured ?? []
        ));
    }

    /** @test */
    public function the_prompt_carries_the_conversation_block_when_a_previous_result_exists(): void
    {
        $captured = null;
        $this->capturingService($captured)->analyze(
            'Parfait — exporte-la en Excel.',
            [],
            $this->rosterConversation()
        );

        $prompt = $this->fullPrompt($captured);

        $this->assertStringContainsString(self::BLOCK, $prompt);
        // Previous user question verbatim.
        $this->assertStringContainsString('Liste des membres de mon équipe avec leurs rôles', $prompt);
        // Result memory: subject, entity types, columns, count, file names.
        $this->assertStringContainsString('membres de l\'équipe avec leurs rôles', $prompt);
        $this->assertStringContainsString('PersonTeam', $prompt);
        $this->assertStringContainsString('member_name, role, email', $prompt);
        $this->assertStringContainsString('10', $prompt);
        $this->assertStringContainsString('Reglement-immeuble.pdf', $prompt);
        // The current question is still the user message.
        $this->assertSame('Parfait — exporte-la en Excel.', $captured[count($captured) - 1]['content'] ?? null);
    }

    /** @test */
    public function the_block_never_carries_row_values(): void
    {
        $captured = null;
        $this->capturingService($captured)->analyze('Parfait — exporte-la en Excel.', [], $this->rosterConversation());

        $system = (string) ($captured[0]['content'] ?? '');

        // Row VALUES stay out (privacy + tokens) — only columns and counts. The
        // assistant summary may name people (it is what the user was shown), so
        // the check targets values that exist ONLY in the rows.
        $this->assertStringNotContainsString('julie.rousseau@example.test', $system);
        $this->assertStringNotContainsString('marc@example.test', $system);
        // The recorded cypher is not part of the QU block either.
        $this->assertStringNotContainsString('MATCH (t:Team)', $system);
    }

    /** @test */
    public function the_block_is_absent_without_conversation(): void
    {
        $captured = null;
        $this->capturingService($captured)->analyze('Liste des membres de mon équipe', [], []);
        $withoutArg = (string) ($captured[0]['content'] ?? '');

        $captured = null;
        $this->capturingService($captured)->analyze('Liste des membres de mon équipe', []);
        $legacy = (string) ($captured[0]['content'] ?? '');

        $this->assertStringNotContainsString(self::BLOCK, $withoutArg);
        $this->assertSame($legacy, $withoutArg, 'an empty conversation_context must add zero tokens');
    }

    /** @test */
    public function a_context_with_empty_history_and_no_result_adds_nothing(): void
    {
        $captured = null;
        $this->capturingService($captured)->analyze('Bonjour', [], [
            'focused_entity' => null,
            'last_result_sample' => [],
            'last_result_count' => 0,
            'last_entity_types' => [],
            'last_referenced_files' => [],
            'recent_exchanges' => [],
        ]);

        $this->assertStringNotContainsString(self::BLOCK, (string) ($captured[0]['content'] ?? ''));
    }

    /** @test */
    public function the_block_keeps_only_the_last_three_exchanges_and_stays_compact(): void
    {
        $exchanges = [];
        for ($i = 1; $i <= 6; $i++) {
            $exchanges[] = ['role' => 'user', 'question' => "question numero {$i} " . str_repeat('é', 600), 'answer_summary' => null];
            $exchanges[] = ['role' => 'assistant', 'question' => null, 'answer_summary' => "reponse {$i} " . str_repeat('x', 600)];
        }
        $context = $this->rosterConversation();
        $context['recent_exchanges'] = $exchanges;
        $context['last_result_sample'] = [array_fill_keys(array_map(static fn($n) => "column_{$n}", range(1, 40)), 'v')];

        $captured = null;
        $this->capturingService($captured)->analyze('et ensuite ?', [], $context);
        $system = (string) ($captured[0]['content'] ?? '');

        $this->assertStringContainsString(self::BLOCK, $system);
        $block = substr($system, (int) strpos($system, self::BLOCK));

        $this->assertStringNotContainsString('question numero 3', $block, 'only the last 3 exchanges ride the block');
        $this->assertStringContainsString('question numero 4', $block);
        $this->assertStringContainsString('question numero 6', $block);
        // < ~600 tokens ≈ 2400 chars (multibyte-safe truncation keeps UTF-8 valid).
        $this->assertLessThan(2400, mb_strlen($block), 'the conversation block must stay compact');
        $this->assertTrue(mb_check_encoding($block, 'UTF-8'), 'truncation must never split a multibyte character');
    }

    /** @test */
    public function the_current_question_is_not_repeated_as_a_previous_exchange(): void
    {
        // Regenerate path: the user message is already persisted, so the
        // snapshot's last exchange IS the current question.
        $context = $this->rosterConversation();
        $context['recent_exchanges'][] = ['role' => 'user', 'question' => 'Parfait — exporte-la en Excel.', 'answer_summary' => null];

        $captured = null;
        $this->capturingService($captured)->analyze('Parfait — exporte-la en Excel.', [], $context);
        $system = (string) ($captured[0]['content'] ?? '');

        $this->assertStringNotContainsString('exporte-la', $system);
    }

    /** @test */
    public function the_follow_up_key_points_at_the_block_as_authoritative(): void
    {
        // (The clarify key that also pointed at the block is gone from this prompt:
        // the clarify judgment is the focused ClarityChecker call's — eval p15.)
        $captured = null;
        $this->capturingService($captured)->analyze('Parfait — exporte-la en Excel.', [], $this->rosterConversation());
        $system = (string) ($captured[0]['content'] ?? '');

        $followUpDoc = substr($system, (int) strpos($system, '"follow_up_reference"'));
        $followUpDoc = substr($followUpDoc, 0, (int) strpos($followUpDoc, "\n"));
        $this->assertStringContainsString('CONVERSATION SO FAR', $followUpDoc);
        $this->assertStringContainsString('CONVERSATION SO FAR (authoritative context for "follow_up_reference";', $system);
    }

    /**
     * @test Eval p9-A excel t1 (2026-10-01): "give me that as an Excel with their
     * contact info" after a roster turn came back follow_up_reference=false —
     * the key doc defined a follow-up as referring back "rather than asking for
     * new information", so an export of the shown result that ALSO adds fields
     * about the same records read as a fresh ask. A fresh query then dropped
     * the shown Role column. The doc must state that re-delivering the shown
     * result as a file and extending it with more fields about the SAME
     * records are follow-ups; only a different set of records is not.
     */
    public function the_follow_up_doc_counts_exports_and_field_extensions_of_the_shown_result(): void
    {
        $captured = null;
        $this->capturingService($captured)->analyze('Give me that as an Excel with their contact info', [], $this->rosterConversation());
        $system = (string) ($captured[0]['content'] ?? '');

        $followUpDoc = substr($system, (int) strpos($system, '"follow_up_reference"'));
        $followUpDoc = substr($followUpDoc, 0, (int) strpos($followUpDoc, "\n"));

        $this->assertStringNotContainsString('rather than asking for new information', $followUpDoc);
        $this->assertStringContainsString('SAME records', $followUpDoc);
        $this->assertStringContainsString('downloadable file', $followUpDoc);
        $this->assertStringContainsString('more fields about those same records', $followUpDoc);
        $this->assertStringContainsString('different set of records', $followUpDoc);
    }
}
