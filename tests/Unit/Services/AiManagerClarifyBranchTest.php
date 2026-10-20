<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * G2/L5 item C — the clarify BRANCH (iteration-11: "Show me the balance."
 * scored 0.15 five runs straight because QU manufactured a confident
 * clarified_question and the pipeline force-resolved a guessed reading).
 *
 * PIPELINE CONTRACT PINNED HERE: when the QU result needs clarification (with a
 * usable clarifying question), the turn SKIPS file search and query generation
 * entirely and answers with that ONE short clarifying question — the branch
 * itself makes no LLM call. Guards: never on followUpReference or digestRequest
 * turns; config-gated (ai.understanding.clarify_enabled, default true); an
 * empty clarifying question with candidate readings asks a lang-key fallback
 * in QU's language (Task 1.22), and with no readings either falls back to the
 * normal pipeline (fail-safe).
 *
 * WHO sets the flag changed (eval p15): no longer the Query-Understanding call
 * (it never did — 0 of 148 turns) but the focused ClarityChecker call, through
 * QueryUnderstandingResult::withClarification() — see AiManagerClarityCheckTest
 * for that half. The results below are built already flagged: this file pins
 * the BRANCH and its structured guards, whoever flagged the turn.
 */
class AiManagerClarifyBranchTest extends TestCase
{
    private AiManager $manager;
    private ContextRetrieverInterface $mockContext;
    private QueryGeneratorInterface $mockQueryGenerator;
    private QueryExecutorInterface $mockQueryExecutor;
    private ResponseGeneratorInterface $mockResponseGenerator;
    private FileContextProvider $mockFileContextProvider;
    private LlmProviderInterface $mockLlm;

    /** @var int */
    private int $searchCalls = 0;

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.security.enabled' => false]);
        config(['ai.file_context.enabled' => true]);
        config(['ai.understanding.clarify_enabled' => true]);

        $this->mockContext = Mockery::mock(ContextRetrieverInterface::class);
        $this->mockQueryGenerator = Mockery::mock(QueryGeneratorInterface::class);
        $this->mockQueryExecutor = Mockery::mock(QueryExecutorInterface::class);
        $this->mockResponseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->mockFileContextProvider = Mockery::mock(FileContextProvider::class);
        $this->mockLlm = Mockery::mock(LlmProviderInterface::class);

        $this->searchCalls = 0;
        $this->mockFileContextProvider->shouldReceive('getFileContext')
            ->andReturnUsing(function () {
                $this->searchCalls++;
                return ['relevant_files' => [], 'file_count' => 0];
            });

        $this->manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $this->mockContext,
            Mockery::mock(EmbeddingProviderInterface::class),
            $this->mockLlm,
            $this->mockQueryGenerator,
            $this->mockQueryExecutor,
            $this->mockResponseGenerator,
            Mockery::mock(VectorStoreInterface::class),
            $this->mockFileContextProvider
        );
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeContext(
        bool $needsClarification = true,
        string $clarifyingQuestion = 'Which balance do you mean — your own account, or the syndicate finances?',
        bool $followUp = false,
        ?array $candidateReadings = null,
        string $language = ''
    ): PipelineContext {
        $ctx = new PipelineContext();
        $ctx->question = 'Show me the balance.';
        $ctx->qu = new QueryUnderstandingResult(
            searchQuery: 'balance',
            intent: 'lookup',
            questionType: 'data_query',
            language: $language,
            followUpReference: $followUp,
            needsClarification: $needsClarification,
            candidateReadings: $candidateReadings ?? ['Your own account balance', 'The syndicate financial balance'],
            clarifyingQuestion: $clarifyingQuestion,
        );
        $ctx->graphSchema = ['labels' => ['Invoice']];

        return $ctx;
    }

    /** @test */
    public function a_genuinely_ambiguous_turn_answers_with_the_one_clarifying_question(): void
    {
        $ctx = $this->makeContext();

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        // The whole point: NO retrieval, NO generation, NO response LLM call.
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockQueryExecutor->shouldReceive('execute')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();
        $this->mockLlm->shouldReceive('chat')->never();

        $result = $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertSame(
            'Which balance do you mean — your own account, or the syndicate finances?',
            $result['answer']
        );
        $this->assertNull($result['cypher']);
        $this->assertSame([], $result['data']);
        $this->assertSame(0, $this->searchCalls, 'file search must never run on a clarify turn');
        $this->assertSame(
            ['Your own account balance', 'The syndicate financial balance'],
            $result['metadata']['response']['clarification']['candidate_readings'] ?? null,
            'the readings ride the trace for graders/UI'
        );
        $this->assertSame('clarification', $result['metadata']['query']['source'] ?? null);
        // An unresolved question has no subject: QU's ("balance") is a guess of one.
        $this->assertNull($result['metadata']['subject']);
    }

    /** @test */
    public function the_branch_is_config_gated(): void
    {
        config(['ai.understanding.clarify_enabled' => false]);
        $ctx = $this->makeContext();

        $this->runNormalTurn($ctx);

        $result = $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertSame('normal answer', $result['answer']);
    }

    /** @test */
    public function a_follow_up_reference_never_clarifies(): void
    {
        // Defence-in-depth beyond the parse guard: a DTO that somehow carries
        // both flags still routes to the normal pipeline.
        $ctx = $this->makeContext(followUp: true);

        $this->runNormalTurn($ctx);

        $result = $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertSame('normal answer', $result['answer']);
    }

    /** @test */
    public function an_empty_clarifying_question_without_readings_falls_back_to_the_normal_pipeline(): void
    {
        // Task 1.22 narrowed this fail-safe: with NOTHING to ask about (no
        // question AND no candidate readings) the normal pipeline owns the turn.
        $ctx = $this->makeContext(clarifyingQuestion: '   ', candidateReadings: []);

        $this->runNormalTurn($ctx);

        $result = $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertSame('normal answer', $result['answer'], 'fail-safe: nothing to ask means no clarify branch');
    }

    /** @test */
    public function an_empty_clarifying_question_with_readings_asks_in_the_questions_language(): void
    {
        // Task 1.22 (language): QU flagged a genuine fork on a French first turn
        // and produced the readings but NO clarifying question. The fallback is
        // a package lang key rendered in QU's language — never the app locale,
        // never English by default.
        app()->setLocale('en');
        $ctx = $this->makeContext(
            clarifyingQuestion: '',
            candidateReadings: ['Le solde de votre compte', 'Le solde financier du syndicat'],
            language: 'fr'
        );

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')->never();
        $this->mockQueryExecutor->shouldReceive('execute')->never();
        $this->mockResponseGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion('Montre-moi le solde.', ['user' => (object) ['id' => 13]]);

        $this->assertSame('clarification', $result['metadata']['query']['source'] ?? null);
        $this->assertStringContainsString('Pouvez-vous préciser', $result['answer']);
        $this->assertStringContainsString('Le solde de votre compte', $result['answer']);
        $this->assertStringContainsString('Le solde financier du syndicat', $result['answer']);
        $this->assertStringNotContainsString('Could you', $result['answer']);
        $this->assertSame(0, $this->searchCalls);
    }

    /** @test */
    public function the_fallback_follows_english_when_the_question_is_english(): void
    {
        app()->setLocale('fr');
        $ctx = $this->makeContext(clarifyingQuestion: '', language: 'en');

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion($ctx->question, ['user' => (object) ['id' => 13]]);

        $this->assertStringContainsString('Could you clarify', $result['answer']);
        $this->assertStringContainsString('Your own account balance', $result['answer']);
    }

    /** @test */
    public function a_french_pronoun_export_after_a_roster_exports_the_previous_result_instead_of_clarifying(): void
    {
        // Live failure 2026-09-29 (part B): "Parfait — exporte-la en Excel."
        // right after a 10-row team roster got an ENGLISH clarifying question.
        // With the conversation in view QU flags the follow-up; even when it
        // ALSO (over-)flags needs_clarification, the structured guard
        // (followUpReference ALONE suppresses clarify — no recorded result is
        // required for that guard) keeps the turn off the clarify branch, and
        // the follow-up export then sources the recorded previous result.
        $previousCypher = 'MATCH (t:Team)<-[:MEMBER_OF]-(p:PersonTeam) RETURN p.name AS member_name, p.role AS role';
        $ctx = new PipelineContext();
        $ctx->question = 'Parfait — exporte-la en Excel.';
        $ctx->qu = new QueryUnderstandingResult(
            searchQuery: 'liste des membres',
            intent: 'list',
            questionType: 'data_query',
            exportFormat: 'excel',
            language: 'fr',
            followUpReference: true,
            needsClarification: true,
            candidateReadings: ['La liste des membres', 'Autre chose'],
            clarifyingQuestion: 'What do you want to export in Excel?',
        );
        $ctx->graphSchema = ['labels' => ['Team', 'PersonTeam']];

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        // The normal pipeline still generates (a possibly re-anchored query) —
        // the follow-up export must nevertheless source the RECORDED roster.
        $this->mockQueryGenerator->shouldReceive('generate')->once()->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (t:Team) RETURN t.name AS team',
            explanation: '',
            confidence: 0.8,
        ));
        $executed = [];
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturnUsing(function ($cypher) use ($previousCypher, &$executed) {
                $executed[] = $cypher;
                $data = str_starts_with((string) $cypher, $previousCypher)
                    ? [['member_name' => 'Julie Rousseau', 'role' => 'lead'], ['member_name' => 'Marc Tremblay', 'role' => 'accountant']]
                    : [['team' => 'Equipe 3']];
                return new QueryExecutionResult(success: true, data: $data, stats: [], metadata: [], errors: []);
            });

        $result = $this->manager->answerQuestion($ctx->question, [
            'user' => (object) ['id' => 13],
            'conversation_context' => [
                'focused_entity' => 'PersonTeam',
                'last_subject' => 'membres de l\'équipe',
                'last_entity_types' => ['Team', 'PersonTeam'],
                'last_cypher_query' => $previousCypher,
                'last_result_count' => 10,
                'last_result_sample' => [['member_name' => 'Julie Rousseau', 'role' => 'lead']],
            ],
        ]);

        $this->assertNotSame('clarification', $result['metadata']['query']['source'] ?? null);
        $this->assertNotSame('What do you want to export in Excel?', $result['answer']);
        $this->assertTrue($result['metadata']['response']['export_intent'] ?? false);
        $this->assertSame('previous_result', $result['metadata']['response']['export_source'] ?? null);

        // The export re-run carries an explicit document LIMIT (review I1).
        $this->assertContains($previousCypher . ' LIMIT 250', $executed, 'the export re-runs the RECORDED roster query');
    }

    private const ROSTER_CYPHER = 'MATCH (t:Team)<-[:MEMBER_OF]-(p:PersonTeam) RETURN p.name AS member_name, p.role AS role';

    private function rosterConversation(): array
    {
        return [
            'focused_entity' => 'PersonTeam',
            'last_subject' => 'membres de l\'équipe',
            'last_entity_types' => ['Team', 'PersonTeam'],
            'last_cypher_query' => self::ROSTER_CYPHER,
            'last_result_count' => 10,
            'last_result_sample' => [['member_name' => 'Julie Rousseau', 'role' => 'lead']],
        ];
    }

    /**
     * The QU DTO produced by the REAL parse of a scripted LLM reply — so the
     * test also covers QU-side normalization (e.g. 'xlsx' → 'excel').
     */
    private function parsedQu(array $llmReply): QueryUnderstandingResult
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturn($llmReply);
        $logger = Mockery::mock(\Condoedge\Ai\Support\AiLogger::class)->shouldIgnoreMissing();
        $logger->shouldReceive('stopTimer')->andReturn(0.0);

        return (new \Condoedge\Ai\Services\QueryUnderstandingService($llm, $logger))
            ->analyze('Parfait — exporte-la en Excel.', [], $this->rosterConversation());
    }

    /** @test */
    public function an_export_ask_with_a_recorded_result_never_clarifies_even_without_the_follow_up_flag(): void
    {
        // Review fix (i): the LLM can miss follow_up_reference while the turn is
        // (over-)flagged as ambiguous on a pronoun export (the exact live
        // signature). Structured guard: export_format set + a recorded previous
        // result → never clarify; the turn proceeds as a follow-up on that
        // recorded result.
        $ctx = new PipelineContext();
        $ctx->question = 'Parfait — exporte-la en Excel.';
        $ctx->qu = $this->parsedQu([
            'search_query' => 'liste des membres',
            'intent' => 'list',
            'question_type' => 'data_query',
            'export_format' => 'xlsx',
            'language' => 'fr',
            'follow_up_reference' => false,
        ])->withClarification(['La liste des membres', 'Autre chose'], 'Que voulez-vous exporter en Excel ?');
        $this->assertTrue($ctx->qu->needsClarification, 'precondition: the turn was over-flagged as ambiguous');
        $this->assertFalse($ctx->qu->followUpReference, 'precondition: QU missed the follow-up');
        $ctx->graphSchema = ['labels' => ['Team', 'PersonTeam']];

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (t:Team) RETURN t.name AS team',
            explanation: '',
            confidence: 0.8,
        ));
        $executed = [];
        $this->mockQueryExecutor->shouldReceive('execute')
            ->andReturnUsing(function ($cypher) use (&$executed) {
                $executed[] = $cypher;
                $data = str_starts_with((string) $cypher, self::ROSTER_CYPHER)
                    ? [['member_name' => 'Julie Rousseau', 'role' => 'lead'], ['member_name' => 'Marc Tremblay', 'role' => 'accountant']]
                    : [['team' => 'Equipe 3']];
                return new QueryExecutionResult(success: true, data: $data, stats: [], metadata: [], errors: []);
            });

        $result = $this->manager->answerQuestion($ctx->question, [
            'user' => (object) ['id' => 13],
            'conversation_context' => $this->rosterConversation(),
        ]);

        $this->assertNotSame('clarification', $result['metadata']['query']['source'] ?? null);
        $this->assertNotSame('Que voulez-vous exporter en Excel ?', $result['answer']);
        $this->assertTrue($result['metadata']['response']['export_intent'] ?? false);
        $this->assertSame('previous_result', $result['metadata']['response']['export_source'] ?? null);

        // The export re-run carries an explicit document LIMIT (review I1).
        $this->assertContains(self::ROSTER_CYPHER . ' LIMIT 250', $executed, 'the export re-runs the RECORDED roster query');
    }

    /** @test */
    public function an_export_ask_without_a_recorded_result_can_still_clarify(): void
    {
        // The export guard needs BOTH structured signals: on a first turn
        // (nothing recorded) a genuinely ambiguous export ask still clarifies.
        $ctx = new PipelineContext();
        $ctx->question = 'Exporte le solde en Excel.';
        $ctx->qu = new QueryUnderstandingResult(
            searchQuery: 'solde',
            questionType: 'data_query',
            exportFormat: 'excel',
            language: 'fr',
            needsClarification: true,
            candidateReadings: ['Le solde de votre compte', 'Le solde du syndicat'],
            clarifyingQuestion: 'Quel solde voulez-vous exporter : le vôtre ou celui du syndicat ?',
        );

        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);
        $this->mockQueryGenerator->shouldReceive('generate')->never();

        $result = $this->manager->answerQuestion($ctx->question, [
            'user' => (object) ['id' => 13],
            'conversation_context' => ['recent_exchanges' => [], 'last_result_count' => 0],
        ]);

        $this->assertSame('clarification', $result['metadata']['query']['source'] ?? null);
        $this->assertSame('Quel solde voulez-vous exporter : le vôtre ou celui du syndicat ?', $result['answer']);
    }

    /**
     * Wire the mocks for a turn the NORMAL pipeline must own.
     */
    private function runNormalTurn(PipelineContext $ctx): void
    {
        $this->mockContext->shouldReceive('retrieveContext')->once()->andReturn($ctx);

        $this->mockQueryGenerator->shouldReceive('generate')->once()->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (i:Invoice) RETURN sum(i.due) AS balance',
            explanation: '',
            confidence: 0.9,
        ));
        $this->mockQueryExecutor->shouldReceive('execute')->andReturn(new QueryExecutionResult(
            success: true, data: [['balance' => 120]], stats: [], metadata: [], errors: [],
        ));
        $this->mockResponseGenerator->shouldReceive('generate')->andReturn([
            'answer' => 'normal answer', 'insights' => [], 'visualizations' => [], 'metadata' => [],
        ]);
    }
}
