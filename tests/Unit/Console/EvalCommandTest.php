<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Console;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Graceful-offline tests for ai:eval (testbench, NO live services).
 *
 * Mirrors DiagnoseCommandTest: the AI stores (Neo4j/Qdrant) are mocked to throw,
 * the LLM judge is skipped via --offline, and the command must STILL complete
 * without throwing — proving the per-stage trap + generation gate degrade
 * gracefully when everything is down.
 */
class EvalCommandTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Placeholder keys so the AI manager + providers can be CONSTRUCTED.
        // The mocked ContextRetriever still throws on USE, simulating the stores
        // being down — that is what we want to prove degrades gracefully.
        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('ai.embedding.default', 'openai');
        $app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');

        // AiLogger requires a non-null log channel.
        $app['config']->set('logging.default', 'null');
        $app['config']->set('ai.log_channel', 'null');

        // Provide a few label-free example questions so the generator has input
        // without needing any configured entities/DB.
        $app['config']->set('ai.chat.example_questions', [
            'How many records do we have?',
            'Show me recent activity',
        ]);
        $app['config']->set('ai.entities', []);
        $app['config']->set('ai.project.business_rules', []);
        $app['config']->set('ai.file_context.physical_paths', []);
        $app['config']->set('ai.file_context.enabled', false);

        // No report file by default for tests.
        $app['config']->set('ai.eval.report_path', null);
    }

    public function setUp(): void
    {
        parent::setUp();

        // Simulate the graph/vector stack being unreachable: context retrieval throws.
        $retriever = Mockery::mock(ContextRetrieverInterface::class);
        $retriever->shouldReceive('retrieveContext')
            ->andThrow(new \RuntimeException('Connection refused (Neo4j/Qdrant down)'));
        $retriever->shouldReceive('searchSimilar')->andReturn([]);
        $retriever->shouldReceive('getGraphSchema')->andReturn(['labels' => []]);
        $retriever->shouldReceive('getExampleEntities')->andReturn([]);

        $this->app->instance(ContextRetrieverInterface::class, $retriever);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_runs_offline_without_throwing_and_prints_a_scorecard(): void
    {
        // Relax thresholds so a fully-degraded run can still be exercised end-to-end.
        $this->app['config']->set('ai.eval.stage_thresholds', [
            'understanding' => 0.0,
            'retrieval' => 0.0,
            'prompt' => 0.0,
            'generation' => 0.0,
        ]);

        $this->artisan('ai:eval', ['--offline' => true])
            ->expectsOutputToContain('SCORECARD')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_returns_nonzero_when_a_stage_falls_below_threshold(): void
    {
        // With default-ish thresholds and the stores down, retrieval avg = 0 → FAIL.
        $this->app['config']->set('ai.eval.stage_thresholds', [
            'understanding' => 0.6,
            'retrieval' => 0.6,
            'prompt' => 0.6,
            'generation' => 0.6,
        ]);

        $this->artisan('ai:eval', ['--offline' => true])
            ->expectsOutputToContain('SCORECARD')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_reports_no_questions_gracefully_when_config_is_empty(): void
    {
        $this->app['config']->set('ai.chat.example_questions', []);

        $this->artisan('ai:eval', ['--offline' => true])
            ->expectsOutputToContain('No questions to evaluate')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_attributes_store_failures_to_the_retrieval_stage(): void
    {
        $this->app['config']->set('ai.eval.stage_thresholds', [
            'understanding' => 0.0,
            'retrieval' => 0.0,
            'prompt' => 0.0,
            'generation' => 0.0,
        ]);

        // Write a report to a temp path and assert retrieval was blamed.
        $reportPath = sys_get_temp_dir() . '/ai-eval-test-' . uniqid() . '.json';

        try {
            $this->artisan('ai:eval', ['--offline' => true, '--report' => $reportPath])
                ->assertExitCode(0);

            $this->assertFileExists($reportPath);
            $report = json_decode(file_get_contents($reportPath), true);

            $this->assertIsArray($report);
            $this->assertSame('retrieval', $report['weakest_stage']);
            $this->assertGreaterThan(0, $report['blame_counts']['retrieval']);
        } finally {
            if (file_exists($reportPath)) {
                unlink($reportPath);
            }
        }
    }

    /** @test */
    public function it_can_repeat_page_example_questions_for_iterative_smoke_runs(): void
    {
        $this->app['config']->set('ai.chat.example_questions', []);
        $this->app['config']->set('ai.chat.page_examples', [
            'Unit' => [
                'Show this unit payment history',
                'Who owns this unit?',
            ],
        ]);
        $this->app['config']->set('ai.eval.stage_thresholds', [
            'understanding' => 0.0,
            'retrieval' => 0.0,
            'prompt' => 0.0,
            'generation' => 0.0,
        ]);

        $reportPath = sys_get_temp_dir() . '/ai-eval-page-examples-' . uniqid() . '.json';

        try {
            $this->artisan('ai:eval', [
                '--offline' => true,
                '--tags' => 'page_example',
                '--iterations' => 2,
                '--report' => $reportPath,
            ])->assertExitCode(0);

            $report = json_decode(file_get_contents($reportPath), true);

            $this->assertSame(4, $report['total_rows']);
            $this->assertSame(2, $report['meta']['base_question_count']);
            $this->assertSame(2, $report['meta']['iterations']);
            $this->assertContains('page_example', $report['rows'][0]['tags']);
            $this->assertContains('iteration:1', $report['rows'][0]['tags']);
            $this->assertContains('iteration:2', $report['rows'][2]['tags']);
        } finally {
            if (file_exists($reportPath)) {
                unlink($reportPath);
            }
        }
    }
}
