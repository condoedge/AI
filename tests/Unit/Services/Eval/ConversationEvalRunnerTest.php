<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Eval;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;
use Condoedge\Ai\Services\Eval\AttributionService;
use Condoedge\Ai\Services\Eval\ConversationEvalRunner;
use Condoedge\Ai\Services\Eval\ExpectationGate;
use Condoedge\Ai\Services\Eval\PipelineEvalDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

/**
 * FIDELITY PIN: the runner must thread conversation state through the PRODUCTION
 * mechanism — ConversationContextManager::processQuestion/buildPromptContext before
 * the turn and ::recordResponse (the `last_result_sample` writer) after it, on a
 * real AiConversation row — never a bespoke eval-only shortcut. Only the AI
 * pipeline itself is stubbed; if production stopped persisting the sample, turn 2
 * would receive nothing and the previous_result_available gate would go red HERE.
 */
class ConversationEvalRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        // No providers: the runner is wired by hand from production parts so the
        // test needs no LLM keys; the AiConversation model only needs migrations.
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        // Driver file-search stage consults this; keep the stub path trivial.
        $app['config']->set('ai.file_context.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
    }

    private function makeRunner(ThreadingRecordingFacade $facade): ConversationEvalRunner
    {
        return new ConversationEvalRunner(
            driver: new PipelineEvalDriver($facade),
            contextManager: new ConversationContextManager(new EntityExtractor(), new ReferenceResolver()),
            attribution: new AttributionService(),
            gate: new ExpectationGate(),
            aiFacade: $facade,
        );
    }

    /** @test */
    public function a_turn_page_context_is_threaded_into_the_pipeline_options_like_a_chat_surface_would(): void
    {
        $facade = new ThreadingRecordingFacade(turnData: [
            0 => [['name' => 'Unit 310']],
            1 => [['name' => 'Owner']],
        ]);

        $pageContext = ['entity_type' => 'Unit', 'entity_id' => 111, 'route' => 'units.show'];

        $this->makeRunner($facade)->run([
            'id' => 'unit-page-probe',
            'role' => 'manager',
            'turns' => [
                ['ask' => "Show this unit's payment history.", 'expect' => [], 'page_context' => $pageContext],
                ['ask' => 'And who owns it?', 'expect' => []],
            ],
        ], user: null);

        // The suite's page prior reaches the pipeline options verbatim — the same
        // 'page_context' key every production chat surface sends.
        $this->assertSame($pageContext, $facade->retrieveContextOptionsPerTurn[0]['page_context'] ?? null);

        // A page-less turn runs with the driver's empty default, not a stale prior.
        $this->assertSame([], $facade->retrieveContextOptionsPerTurn[1]['page_context'] ?? null);
    }

    /** @test */
    public function turn_two_receives_the_conversation_state_turn_one_produced_via_production_writers(): void
    {
        $facade = new ThreadingRecordingFacade(turnData: [
            0 => [['name' => 'Alice', 'role' => 'admin', 'email' => 'a@x.com'], ['name' => 'Bob', 'role' => 'member', 'email' => 'b@x.com']],
            1 => [], // export follow-up returns no new rows — must lean on threaded context
        ]);

        $rows = $this->makeRunner($facade)->run([
            'id' => 'team-roster-export',
            'role' => 'manager',
            'turns' => [
                ['ask' => 'Give me all my team members.', 'expect' => ['min_rows' => 2], 'standard' => 'Lists members.'],
                ['ask' => 'Now give me that as an Excel.', 'expect' => ['previous_result_available' => true], 'standard' => 'Excel of same members.'],
            ],
        ], user: null);

        $this->assertCount(2, $rows);

        // Turn 1 got no previous sample (nothing to thread yet).
        $turn1Context = $facade->retrieveContextOptionsPerTurn[0]['conversation_context'] ?? [];
        $this->assertSame([], $turn1Context['last_result_sample'] ?? []);

        // Turn 2's conversation_context was built by ConversationContextManager::buildPromptContext
        // from the snapshot recordResponse persisted after turn 1 — the PRODUCTION path.
        $turn2Context = $facade->retrieveContextOptionsPerTurn[1]['conversation_context'] ?? [];
        $this->assertSame('Alice', $turn2Context['last_result_sample'][0]['name'] ?? null);
        $this->assertCount(2, $turn2Context['last_result_sample']);

        // The gate confirms the threaded state as a graded fact.
        $gate = $this->gateResult($rows[1]->gates, 'previous_result_available');
        $this->assertTrue($gate['passed']);

        // The snapshot physically lived on the AiConversation row between the turns
        // (production storage): turn 2's user message recorded the context it was
        // given, sample included.
        $conversation = AiConversation::first();
        $turn2UserMessage = $conversation->messages()->where('role', 'user')->orderBy('id', 'desc')->first();
        $this->assertSame('Alice', $turn2UserMessage->context_used['last_result_sample'][0]['name'] ?? null);

        // PRESERVE-ON-EMPTY (the quirk this harness originally pinned is now FIXED
        // in production, justified by a live failure 2026-07-23: a docs-only turn
        // wiped the roster and "can you export that?" answered "No results were
        // found"): a follow-up turn that returns no rows PRESERVES the previous
        // sample — the last thing the user was SHOWN survives for later exports.
        $this->assertSame('Alice', $conversation->fresh()->context_snapshot['last_result_sample'][0]['name'] ?? null);
        $this->assertCount(2, $conversation->fresh()->context_snapshot['last_result_sample'] ?? []);
    }

    /** @test */
    public function both_follow_up_signals_are_recorded_in_the_trace(): void
    {
        // Item D: the regex heuristic (pre-QU fallback) and QU's semantic
        // followUpReference judgment were observed DISAGREEING live (inverted).
        // The trace must record BOTH so every disagreement is auditable: the
        // regex signal under is_follow_up_regex, QU's under follow_up_reference_qu.
        $facade = new ThreadingRecordingFacade(turnData: [
            0 => [['name' => 'Alice']],
            1 => [['name' => 'Alice']],
        ]);

        $rows = $this->makeRunner($facade)->run([
            'id' => 'signal-audit',
            'turns' => [
                ['ask' => 'Give me all my team members.'],
                // Regex-positive phrasing ("those") while the stub QU says false.
                ['ask' => 'and those in the Sales team?'],
            ],
        ], user: null);

        $meta = $rows[1]->conversationContextMeta;
        $this->assertTrue($meta['is_follow_up_regex'] ?? null, 'the pre-QU regex signal must be recorded');
        $this->assertFalse($meta['follow_up_reference_qu'] ?? null, "QU's authoritative judgment must be recorded beside it");
        // And QU's judgment is also in the understanding snapshot verbatim.
        $this->assertFalse($rows[1]->understanding['follow_up_reference'] ?? null);
    }

    /** @test */
    public function when_production_writes_no_sample_the_gate_fails_honestly(): void
    {
        // Turn 1 returns NO data → recordResponse persists an empty sample. The
        // harness must NOT fake context the real path could not produce.
        $facade = new ThreadingRecordingFacade(turnData: [0 => [], 1 => []]);

        $rows = $this->makeRunner($facade)->run([
            'id' => 'no-writer',
            'turns' => [
                ['ask' => 'Give me all my team members.'],
                ['ask' => 'Now give me that as an Excel.', 'expect' => ['previous_result_available' => true]],
            ],
        ], user: null);

        $gate = $this->gateResult($rows[1]->gates, 'previous_result_available');
        $this->assertFalse($gate['passed']);
        $this->assertSame('gate-failed', $rows[1]->verdictLabel);
    }

    /** @test */
    public function a_pipeline_stage_error_preserves_the_previous_sample_like_production(): void
    {
        // Turn 1 returns rows; turn 2's RESPONSE stage throws. Production catches
        // that INSIDE AiManager::answerQuestion (error payload, data: []) and
        // AskQuestionUseCase still calls recordResponse — which, under
        // PRESERVE-ON-EMPTY, keeps the previous sample: an errored turn showed
        // the user nothing new, so the last visible result must survive. Turn 3
        // therefore still receives turn 1's sample and its export succeeds.
        $facade = new StageErrorOnTurnFacade(
            turnData: [0 => [['name' => 'Alice'], ['name' => 'Bob']], 1 => [], 2 => []],
            errorTurn: 1,
        );

        $rows = $this->makeRunner($facade)->run([
            'id' => 'error-preserve',
            'turns' => [
                ['ask' => 'Give me all my team members.'],
                ['ask' => 'Now export that as a PDF.'],
                ['ask' => 'Also as Excel.', 'expect' => ['previous_result_available' => true]],
            ],
        ], user: null);

        $this->assertSame('stage-error', $rows[1]->verdictLabel);

        // The stage-errored turn reached the production writer, which PRESERVED
        // the sample — turn 3 threads in turn 1's rows and its gate passes.
        $this->assertSame(2, $rows[2]->conversationContextMeta['last_result_sample_count']);
        $gate = $this->gateResult($rows[2]->gates, 'previous_result_available');
        $this->assertTrue($gate['passed']);

        $conversation = AiConversation::first();
        $this->assertSame('Alice', $conversation->fresh()->context_snapshot['last_result_sample'][0]['name'] ?? null);
    }

    /** @test */
    public function the_report_carries_the_verbatim_ask_with_the_enrichment_as_trace_evidence(): void
    {
        $facade = new ThreadingRecordingFacade(turnData: [0 => [['n' => 1]], 1 => [['n' => 2]]]);

        $rows = $this->makeRunner($facade)->run([
            'id' => 'ask-identity',
            'turns' => [
                ['ask' => 'Give me all my team members.'],
                ['ask' => 'Now give me that as an Excel.'],
            ],
        ], user: null);

        // The row's question is ALWAYS the suite's verbatim ask; any enrichment the
        // pipeline actually ran with is separate trace evidence (null when unchanged).
        $this->assertSame('Give me all my team members.', $rows[0]->question);
        $this->assertSame('Now give me that as an Excel.', $rows[1]->question);
        foreach ($rows as $row) {
            if ($row->enrichedQuestion !== null) {
                $this->assertNotSame($row->question, $row->enrichedQuestion);
            }
        }
    }

    /** @test */
    public function rows_carry_conversation_identity_standard_and_turn_order(): void
    {
        $facade = new ThreadingRecordingFacade(turnData: [0 => [['n' => 1]], 1 => [['n' => 2]]]);

        $rows = $this->makeRunner($facade)->run([
            'id' => 'c1',
            'role' => 'manager',
            'turns' => [
                ['ask' => 'First?', 'standard' => 'Standard one.'],
                ['ask' => 'Second?', 'standard' => 'Standard two.'],
            ],
        ], user: null);

        $this->assertSame(['c1', 'c1'], [$rows[0]->conversationId, $rows[1]->conversationId]);
        $this->assertSame([0, 1], [$rows[0]->turnIndex, $rows[1]->turnIndex]);
        $this->assertSame('manager', $rows[0]->role);
        $this->assertSame('Standard one.', $rows[0]->standard);
        $this->assertSame('Standard two.', $rows[1]->standard);
        $this->assertGreaterThanOrEqual(0, $rows[0]->totalDurationMs);
    }

    /** @test */
    public function an_export_turn_whose_gates_all_pass_is_never_labeled_failed_by_soft_scoring(): void
    {
        // The observed harness contradiction (resilience t3): an export follow-up
        // returns NO new rows (data comes from the previous turn), the export
        // payload passes every declared gate — yet neutral-signal soft scoring
        // labeled the row 'failed'. Reconciliation must land it on 'passed'.
        $facade = new ExportComposingFacade(turnData: [
            0 => [['name' => 'Alice'], ['name' => 'Bob']],
            1 => [], // export turn: no new rows by design
        ]);

        $rows = $this->makeRunner($facade)->run([
            'id' => 'export-verdict-reconciliation',
            'turns' => [
                ['ask' => 'Give me all my team members.'],
                [
                    'ask' => 'Now give me that as an Excel.',
                    'expect' => ['produces_file' => 'excel', 'previous_result_available' => true],
                ],
            ],
        ], user: null);

        foreach ($rows[1]->gates as $gate) {
            $this->assertTrue($gate['passed'], "Gate {$gate['gate']} unexpectedly failed: {$gate['detail']}");
        }
        $this->assertSame('passed', $rows[1]->verdictLabel,
            'All-green gates must override the soft failed verdict');
    }

    /** @test */
    public function the_pinned_user_email_reaches_the_row_for_the_no_self_contact_gate(): void
    {
        $facade = new ThreadingRecordingFacade(turnData: [0 => [['n' => 1]]]);

        $rows = $this->makeRunner($facade)->run([
            'id' => 'pinned-identity',
            'turns' => [['ask' => 'Team info?']],
        ], user: (object) ['id' => 13, 'email' => 'j.rousseau@novogestion.ca']);

        $this->assertSame('j.rousseau@novogestion.ca', $rows[0]->pinnedUserEmail);
        $this->assertSame('j.rousseau@novogestion.ca', $rows[0]->toArray()['trace']['pinned_user_email']);
    }

    private function gateResult(array $gates, string $name): array
    {
        foreach ($gates as $gate) {
            if ($gate['gate'] === $name) {
                return $gate;
            }
        }
        $this->fail("Gate '{$name}' missing.");
    }
}

/**
 * Pipeline stub with per-turn data. Records the options each retrieveContext call
 * received so the test can assert WHAT conversation context was threaded in.
 * Everything else returns minimal-but-walkable pipeline signals.
 */
class ThreadingRecordingFacade
{
    /** @var array<int, array> options captured per retrieveContext call */
    public array $retrieveContextOptionsPerTurn = [];

    protected int $turn = -1;

    public function __construct(private readonly array $turnData = []) {}

    public function getSchema(): array
    {
        return ['labels' => ['User'], 'relationships' => []];
    }

    public function retrieveContext(string $question, array $options = []): PipelineContext
    {
        $this->turn++;
        $this->retrieveContextOptionsPerTurn[$this->turn] = $options;

        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = new QueryUnderstandingResult(questionType: 'data_query', entityTypes: ['User']);
        $ctx->selectionInfo = ['selected_entities' => ['User']];

        return $ctx;
    }

    public function retrieveFileContext(string $query, mixed $user = null, array $options = []): array
    {
        return [];
    }

    /** Production file-context seam: the stub adds no files. */
    public function retrieveFileContextInto(PipelineContext $context, string $question, array $options = []): PipelineContext
    {
        return $context;
    }

    /** Production entity-action enrichment seam: the stub passes rows through. */
    public function enrichExecutionResult(\Condoedge\Ai\DTOs\QueryExecutionResult $executionResult): \Condoedge\Ai\DTOs\QueryExecutionResult
    {
        return $executionResult;
    }

    public function generateQuery(string $question, ?PipelineContext $context = null, array $options = []): array
    {
        return [
            'cypher' => 'MATCH (u:User) RETURN u',
            'confidence' => 0.9,
            'warnings' => [],
            'explanation' => '',
            'metadata' => [],
        ];
    }

    public function executeQuery(string $cypher, array $params = [], array $options = []): array
    {
        return ['data' => $this->turnData[$this->turn] ?? [], 'metadata' => [], 'errors' => [], 'stats' => []];
    }

    public function composeResponse(string $question, PipelineContext $context, array $options = []): array
    {
        return [
            'question' => $question,
            'answer' => 'Stub answer.',
            'insights' => [],
            'visualizations' => [],
            'cypher' => $context->queryResult->cypher,
            'data' => $context->executionResult->data,
            'stats' => [],
            'referenced_files' => [],
            'metadata' => ['query' => [], 'execution' => [], 'response' => []],
        ];
    }
}

/**
 * Same walkable stub, but the SECOND turn composes a real export payload (the
 * shape ExportResponseComposer emits) — while the turn itself returns zero new
 * rows, exactly like a production export-of-previous follow-up.
 */
class ExportComposingFacade extends ThreadingRecordingFacade
{
    public function composeResponse(string $question, PipelineContext $context, array $options = []): array
    {
        $base = parent::composeResponse($question, $context, $options);

        if ($this->turn === 1) {
            $base['answer'] = 'I prepared the Excel file with the available information.';
            $base['metadata']['response'] = [
                'export_intent' => true,
                'export_format' => 'excel',
                'export_source' => 'previous_result',
                'export_source_complete' => true,
                'export_document_markdown' => "# Team\n\n| Name |\n| --- |\n| Alice |\n| Bob |",
                'result_count' => 2,
                'result_total' => 2,
            ];
        }

        return $base;
    }
}

/**
 * Same walkable stub, but the RESPONSE stage throws on one chosen turn — the
 * shape of an LLM failure inside composeResponse (which production catches
 * inside answerQuestion, not in AskQuestionUseCase's catch).
 */
class StageErrorOnTurnFacade extends ThreadingRecordingFacade
{
    public function __construct(array $turnData, private readonly int $errorTurn)
    {
        parent::__construct($turnData);
    }

    public function composeResponse(string $question, PipelineContext $context, array $options = []): array
    {
        if ($this->turn === $this->errorTurn) {
            throw new \RuntimeException('Response generation failed: LLM unavailable');
        }

        return parent::composeResponse($question, $context, $options);
    }
}
