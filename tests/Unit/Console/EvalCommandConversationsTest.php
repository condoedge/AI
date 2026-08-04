<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Console;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;
use Condoedge\Ai\Services\Eval\AttributionService;
use Condoedge\Ai\Services\Eval\ConversationEvalRunner;
use Condoedge\Ai\Services\Eval\ExpectationGate;
use Condoedge\Ai\Services\Eval\PipelineEvalDriver;
use Condoedge\Ai\Tests\TestCase;

/**
 * Command-level pins for the multi-turn suite path:
 *  - a failing fact-gate makes ai:eval exit non-zero (the red gate IS the product);
 *  - the JSON report groups conversations in turn order, each turn carrying its
 *    verbatim standard so an out-of-process agent can grade without re-running.
 */
class EvalCommandConversationsTest extends TestCase
{
    private array $tempFiles = [];

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('ai.embedding.default', 'openai');
        $app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('logging.default', 'null');
        $app['config']->set('ai.log_channel', 'null');
        $app['config']->set('ai.file_context.enabled', false);
        $app['config']->set('ai.eval.report_path', null);
        // Relax stage thresholds: these tests pin GATE behavior, not stage averages.
        $app['config']->set('ai.eval.stage_thresholds', [
            'understanding' => 0.0,
            'retrieval' => 0.0,
            'prompt' => 0.0,
            'generation' => 0.0,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    public function setUp(): void
    {
        parent::setUp();

        // Stub ONLY the AI pipeline; conversation threading still runs through the
        // production ConversationContextManager + AiConversation model.
        $facade = new CommandStubFacade();
        $this->app->instance(ConversationEvalRunner::class, new ConversationEvalRunner(
            driver: new PipelineEvalDriver($facade),
            contextManager: new ConversationContextManager(new EntityExtractor(), new ReferenceResolver()),
            attribution: new AttributionService(),
            gate: new ExpectationGate(),
            aiFacade: $facade,
        ));
    }

    public function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function suiteFile(array $data): string
    {
        $path = sys_get_temp_dir() . '/eval-cmd-suite-' . uniqid() . '.json';
        file_put_contents($path, json_encode($data));
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @test */
    public function a_gate_failure_makes_the_command_exit_non_zero(): void
    {
        $suite = $this->suiteFile([
            'conversations' => [[
                'id' => 'c1',
                'turns' => [[
                    'ask' => 'Give me all my team members.',
                    'expect' => ['min_rows' => 99],
                    'standard' => 'Lists members.',
                ]],
            ]],
        ]);

        $this->artisan('ai:eval', ['--suite' => $suite])
            ->expectsOutputToContain('gate')
            ->assertExitCode(1);
    }

    /** @test */
    public function the_report_groups_conversations_in_turn_order_with_standards(): void
    {
        $suite = $this->suiteFile([
            'conversations' => [[
                'id' => 'team-roster',
                'role' => 'manager',
                'turns' => [
                    ['ask' => 'Give me all my team members.', 'expect' => ['min_rows' => 1], 'standard' => 'Lists members.'],
                    ['ask' => 'Now give me that as an Excel.', 'expect' => ['previous_result_available' => true], 'standard' => 'Excel of the SAME members.'],
                ],
            ]],
        ]);

        $reportPath = sys_get_temp_dir() . '/eval-cmd-report-' . uniqid() . '.json';
        $this->tempFiles[] = $reportPath;

        $this->artisan('ai:eval', ['--suite' => $suite, '--report' => $reportPath])
            ->assertExitCode(0);

        $report = json_decode(file_get_contents($reportPath), true);

        $this->assertIsArray($report['conversations']);
        $conversation = $report['conversations'][0];
        $this->assertSame('team-roster', $conversation['id']);
        $this->assertSame([0, 1], array_column($conversation['turns'], 'turn_index'));
        $this->assertSame('Lists members.', $conversation['turns'][0]['standard']);
        $this->assertSame('Excel of the SAME members.', $conversation['turns'][1]['standard']);

        // Rows carry the full trace for out-of-process grading.
        $this->assertArrayHasKey('trace', $report['rows'][0]);
        $this->assertSame('team-roster', $report['rows'][0]['trace']['conversation_id']);
    }

    /** @test */
    public function fresh_flag_reports_exactly_what_it_cleared(): void
    {
        $this->app['config']->set('ai.chat.example_questions', []);
        $this->app['config']->set('ai.entities', []);
        $this->app['config']->set('ai.project.business_rules', []);
        $this->app['config']->set('ai.file_context.physical_paths', []);

        $this->artisan('ai:eval', ['--fresh' => true, '--offline' => true])
            ->expectsOutputToContain('--fresh: clearing AI-owned state...')
            ->expectsOutputToContain('circuit_breaker.llm')
            ->expectsOutputToContain('rate_limiter.llm_api')
            ->expectsOutputToContain('eval conversation(s)')
            ->assertExitCode(0);
    }

    /** @test */
    public function a_conversation_that_cannot_start_still_evaluates_gates_and_fails_the_run(): void
    {
        // Break conversation startup: the runner throws before any turn runs (the
        // catch's own example — e.g. no DB for AiConversation). The fallback rows
        // must STILL evaluate the expect blocks so the "any gate failure =>
        // non-zero exit" contract holds even for turns that never ran — with
        // relaxed thresholds (see getEnvironmentSetUp) the old behavior was a
        // silent PASS with every gate skipped.
        $this->app->instance(ConversationEvalRunner::class, new class extends ConversationEvalRunner {
            public function __construct()
            {
                // Deps unused: run() throws immediately.
            }

            public function run(array $conversation, mixed $user, bool $offline = false, bool $capturePrompts = true, int $throttleMs = 0): array
            {
                throw new \RuntimeException('no DB for AiConversation');
            }
        });

        $suite = $this->suiteFile([
            'conversations' => [[
                'id' => 'c1',
                'role' => 'manager',
                'turns' => [[
                    'ask' => 'Give me all my team members.',
                    'expect' => ['min_rows' => 1],
                    'standard' => 'Lists members.',
                ]],
            ]],
        ]);

        $reportPath = sys_get_temp_dir() . '/eval-cmd-fallback-' . uniqid() . '.json';
        $this->tempFiles[] = $reportPath;

        $this->artisan('ai:eval', ['--suite' => $suite, '--report' => $reportPath])
            ->expectsOutputToContain('gate')
            ->assertExitCode(1);

        $report = json_decode(file_get_contents($reportPath), true);
        $this->assertFalse($report['passed']);
        $this->assertNotEmpty($report['gate_failures']);
        $this->assertSame('min_rows', $report['gate_failures'][0]['gate']);
        // The fallback row carries its conversation identity + role like runner rows.
        $this->assertSame('manager', $report['rows'][0]['trace']['role']);
        $this->assertSame('conversation_start', $report['rows'][0]['errored_step']);
    }

    /** @test */
    public function offline_warns_that_export_turn_disambiguation_can_only_pass_vacuously(): void
    {
        // An export turn's ONLY disambiguation evidence is the export payload,
        // which --offline never produces (response generation is skipped): the
        // gate passes vacuously and the up-front warning must name it so an
        // offline green is never misread as verified homonym safety.
        $suite = $this->suiteFile([
            'conversations' => [[
                'id' => 'c1',
                'turns' => [[
                    'ask' => 'Export that as an Excel.',
                    'expect' => ['produces_file' => 'excel', 'disambiguation_column_present' => true],
                    'standard' => 'Excel export keeping the disambiguation column.',
                ]],
            ]],
        ]);

        $this->artisan('ai:eval', ['--suite' => $suite, '--offline' => true])
            ->expectsOutputToContain('disambiguation_column_present on export turns')
            ->assertExitCode(1); // produces_file itself still red-gates offline.
    }

    /** @test */
    public function an_unknown_expect_key_fails_fast_before_running_anything(): void
    {
        $suite = $this->suiteFile([
            'conversations' => [[
                'id' => 'c1',
                'turns' => [['ask' => 'Hello?', 'expect' => ['rows_min' => 1]]],
            ]],
        ]);

        $this->artisan('ai:eval', ['--suite' => $suite])
            ->expectsOutputToContain('rows_min')
            ->assertExitCode(1);
    }
}

/**
 * Minimal walkable pipeline stub: one data row per turn, no files, no exports.
 */
class CommandStubFacade
{
    public function getSchema(): array
    {
        return ['labels' => ['User'], 'relationships' => []];
    }

    public function retrieveContext(string $question, array $options = []): PipelineContext
    {
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
        return ['cypher' => 'MATCH (u:User) RETURN u', 'confidence' => 0.9, 'warnings' => [], 'explanation' => '', 'metadata' => []];
    }

    public function executeQuery(string $cypher, array $params = [], array $options = []): array
    {
        return ['data' => [['name' => 'Alice', 'role' => 'admin', 'email' => 'a@x.com']], 'metadata' => [], 'errors' => [], 'stats' => []];
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
