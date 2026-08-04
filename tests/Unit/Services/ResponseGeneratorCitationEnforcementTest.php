<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\ToolCallingLlmInterface;
use Condoedge\Ai\DTOs\LlmToolCall;
use Condoedge\Ai\DTOs\LlmToolTurn;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\ActionToolGate;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\GraphQueryTool;
use Condoedge\Ai\Services\Actions\ToolLoopRunner;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\RecordingQueryExecutor;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\ScriptedToolProvider;
use Mockery;

/**
 * Citation enforcement (structural mechanism, not semantics): a turn grounded
 * on retrieved file chunks (QU question_type = knowledge_query, chunks present,
 * NOT an enumeration listing) whose answer ships ZERO [N] markers gets exactly
 * ONE regeneration with a hard cite-or-decline instruction. If the retry still
 * has no markers, the retry is returned as-is and response metadata carries
 * citation_enforcement = 'failed' so the eval trace shows it. Checking for the
 * literal [N] marker is a STRUCTURE check on the prompt's own citation grammar.
 */
class ResponseGeneratorCitationEnforcementTest extends TestCase
{
    private LlmProviderInterface $llmMock;
    private ResponseGenerator $generator;

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.response_generation.citation_enforcement' => true]);

        $this->llmMock = Mockery::mock(LlmProviderInterface::class);
        $this->generator = new ResponseGenerator($this->llmMock, [
            'default_format' => 'text',
            'default_style' => 'friendly',
            'default_max_length' => 200,
        ]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * A knowledge-query PipelineContext grounded on one retrieved chunk.
     */
    private function fileGroundedContext(bool $enumeration = false): PipelineContext
    {
        $context = new PipelineContext();
        $context->question = 'Am I responsible for the previous owner\'s unpaid condo fees?';
        $context->qu = new QueryUnderstandingResult(questionType: 'knowledge_query');
        $context->queryResult = new QueryGenerationResult(
            explanation: 'Knowledge query — answered from documents.',
            confidence: 1.0,
        );
        $context->executionResult = QueryExecutionResult::empty();
        $context->fileContext = [
            'relevant_files' => [[
                'file_id' => 1,
                'filename' => 'CCQ_Co-ownership_EN.md',
                'snippet' => 'A co-owner is bound to contribute to the common expenses...',
                'relevance' => 0.72,
                'chunk_index' => 12,
                'source' => 'physical',
            ]],
            'file_count' => 1,
            'has_physical' => true,
            'has_database' => false,
        ];
        if ($enumeration) {
            $context->fileContext['enumeration'] = true;
        }

        return $context;
    }

    /** @test */
    public function a_markerless_file_grounded_answer_triggers_exactly_one_retry(): void
    {
        $calls = [];
        $this->llmMock->shouldReceive('complete')
            ->twice()
            ->andReturnUsing(function ($user, $system, $opts) use (&$calls) {
                $calls[] = $user;
                return count($calls) === 1
                    ? 'The buyer is generally liable for arrears.' // no [N]
                    : 'The buyer can be held to the common expenses owed [1].';
            });

        $result = $this->generator->generate($this->fileGroundedContext());

        $this->assertCount(2, $calls, 'Exactly one enforcement retry');
        // The retry prompt carries the hard instruction and the previous draft.
        $this->assertStringContainsString('CITATION ENFORCEMENT', $calls[1]);
        $this->assertStringContainsString('The buyer is generally liable for arrears.', $calls[1]);
        // The cited retry replaces the draft and the recovery is flagged.
        $this->assertSame('The buyer can be held to the common expenses owed [1].', $result['answer']);
        $this->assertSame('recovered', $result['metadata']['citation_enforcement'] ?? null);
    }

    /** @test */
    public function a_still_markerless_retry_is_returned_as_is_and_flagged_failed(): void
    {
        $this->llmMock->shouldReceive('complete')
            ->twice()
            ->andReturn('Confident uncited claim.', 'The documents provided do not contain the answer.');

        $result = $this->generator->generate($this->fileGroundedContext());

        $this->assertSame('The documents provided do not contain the answer.', $result['answer']);
        $this->assertSame('failed', $result['metadata']['citation_enforcement'] ?? null);
    }

    /** @test */
    public function it_never_retries_more_than_once(): void
    {
        // Both the draft AND the retry are markerless: exactly 2 calls, never 3.
        $this->llmMock->shouldReceive('complete')
            ->twice()
            ->andReturn('Uncited draft.', 'Still uncited retry.');

        $result = $this->generator->generate($this->fileGroundedContext());

        $this->assertSame('failed', $result['metadata']['citation_enforcement'] ?? null);
        // Mockery's ->twice() fails the test in tearDown if a third call happened.
    }

    /** @test */
    public function an_answer_that_already_cites_is_not_retried(): void
    {
        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn('The buyer can be held to the common expenses owed [1].');

        $result = $this->generator->generate($this->fileGroundedContext());

        $this->assertSame('The buyer can be held to the common expenses owed [1].', $result['answer']);
        $this->assertArrayNotHasKey('citation_enforcement', $result['metadata']);
    }

    /** @test */
    public function data_query_turns_are_not_enforced_even_with_chunks_present(): void
    {
        $context = $this->fileGroundedContext();
        $context->qu = new QueryUnderstandingResult(questionType: 'data_query');

        $this->llmMock->shouldReceive('complete')->once()->andReturn('Your team has 12 members.');

        $result = $this->generator->generate($context);

        $this->assertSame('Your team has 12 members.', $result['answer']);
        $this->assertArrayNotHasKey('citation_enforcement', $result['metadata']);
    }

    /** @test */
    public function turns_without_file_chunks_are_not_enforced(): void
    {
        $context = $this->fileGroundedContext();
        $context->fileContext = [];

        $this->llmMock->shouldReceive('complete')->once()->andReturn('Uncited knowledge answer.');

        $result = $this->generator->generate($context);

        $this->assertArrayNotHasKey('citation_enforcement', $result['metadata']);
    }

    /** @test */
    public function enumeration_listings_are_never_treated_as_grounding_for_enforcement(): void
    {
        $context = $this->fileGroundedContext(enumeration: true);

        $this->llmMock->shouldReceive('complete')->once()->andReturn('You can access lease.pdf and rules.docx.');

        $result = $this->generator->generate($context);

        $this->assertArrayNotHasKey('citation_enforcement', $result['metadata']);
    }

    /** @test */
    public function the_config_kill_switch_disables_enforcement(): void
    {
        config(['ai.response_generation.citation_enforcement' => false]);

        $this->llmMock->shouldReceive('complete')->once()->andReturn('Uncited claim.');

        $result = $this->generator->generate($this->fileGroundedContext());

        $this->assertSame('Uncited claim.', $result['answer']);
        $this->assertArrayNotHasKey('citation_enforcement', $result['metadata']);
    }

    /** @test */
    public function terse_styles_are_exempt_because_their_contract_forbids_markers(): void
    {
        foreach (['minimal', 'concise'] as $style) {
            $llm = Mockery::mock(LlmProviderInterface::class);
            $llm->shouldReceive('complete')->once()->andReturn('Direct uncited answer.');

            $generator = new ResponseGenerator($llm, ['default_style' => $style]);
            $result = $generator->generate($this->fileGroundedContext(), ['style' => $style]);

            $this->assertArrayNotHasKey('citation_enforcement', $result['metadata'], "style {$style}");
        }
    }

    // =========================================================================
    // Native tool-loop path: enforcement must never regenerate BLIND to in-loop
    // tool results (the initial prompt pair is not the full transcript there).
    // =========================================================================

    /**
     * A tool-capable scripted provider that also satisfies LlmProviderInterface,
     * counting complete() calls so the tests can prove whether the blind
     * regeneration ran.
     */
    private function toolCapableProvider(array $script, string $completeReply): LlmProviderInterface&ToolCallingLlmInterface
    {
        return new class($script, $completeReply) implements LlmProviderInterface, ToolCallingLlmInterface {
            public int $completeCalls = 0;
            private ScriptedToolProvider $inner;

            public function __construct(array $script, private string $completeReply)
            {
                $this->inner = new ScriptedToolProvider($script);
            }

            public function chatWithTools(array $messages, array $toolSpecs, array $options = []): LlmToolTurn
            {
                return $this->inner->chatWithTools($messages, $toolSpecs, $options);
            }

            public function formatToolResults(LlmToolTurn $turn, array $results): array
            {
                return $this->inner->formatToolResults($turn, $results);
            }

            public function complete(string $prompt, ?string $systemPrompt = null, array $options = []): string
            {
                $this->completeCalls++;

                return $this->completeReply;
            }

            public function chat(array $messages, array $options = []): string
            {
                return '';
            }

            public function chatJson(array $messages, array $options = []): object|array
            {
                return [];
            }

            public function stream(array $messages, callable $callback, array $options = []): void
            {
            }

            public function getModel(): string
            {
                return 'scripted';
            }

            public function getProvider(): string
            {
                return 'test';
            }

            public function getMaxTokens(): int
            {
                return 8000;
            }

            public function countTokens(string $text): int
            {
                return strlen($text);
            }
        };
    }

    /**
     * A ResponseGenerator wired for the NATIVE channel with a controlled
     * (recording, no-backend) run_query tool.
     */
    private function nativeGeneratorFor(LlmProviderInterface&ToolCallingLlmInterface $provider): ResponseGenerator
    {
        config([
            'ai.actions.native_tools' => true,
            'ai.actions.native_query_tool' => true,
            'ai.entity_actions' => [],
            'ai.generic_actions' => [],
        ]);

        $registry = new AiActionRegistry();
        $registry->registerGeneric(new GraphQueryTool(
            new RecordingQueryExecutor(new QueryExecutionResult(success: true, data: [['x' => 1]]))
        ));

        return new ResponseGenerator(
            $provider,
            ['default_format' => 'text', 'default_style' => 'friendly', 'default_max_length' => 200],
            new ActionToolGate($registry),
            new ToolLoopRunner(new ActionDispatcher($registry), toolRounds: 2, maxToolCalls: 5)
        );
    }

    /** @test */
    public function a_tool_loop_draft_with_executed_tool_calls_skips_the_blind_regeneration_and_keeps_the_draft(): void
    {
        $provider = $this->toolCapableProvider([
            new LlmToolTurn(null, [
                new LlmToolCall('c1', 'run_query', ['cypher' => 'MATCH (n) RETURN n']),
            ], ['role' => 'assistant'], 'tool_calls'),
            new LlmToolTurn('Uncited answer informed by tool results.', [], ['role' => 'assistant'], 'stop'),
        ], 'Never used [1].');

        $result = $this->nativeGeneratorFor($provider)->generate($this->fileGroundedContext());

        // The draft is kept: a complete() retry from the INITIAL prompts alone
        // would be blind to the executed tool round recorded in metadata.
        $this->assertSame('Uncited answer informed by tool results.', $result['answer']);
        $this->assertSame('skipped_tool_results', $result['metadata']['citation_enforcement'] ?? null);
        $this->assertSame(0, $provider->completeCalls, 'No blind complete() regeneration may run');
        $this->assertSame(1, $result['metadata']['tool_calling']['tool_calls'] ?? null);
    }

    /** @test */
    public function a_tool_loop_draft_with_zero_tool_calls_still_gets_the_enforcement_retry(): void
    {
        // No tool call was made, so the initial prompt pair IS the full
        // transcript — the bounded retry is faithful and must still fire.
        $provider = $this->toolCapableProvider([
            new LlmToolTurn('Uncited draft.', [], ['role' => 'assistant'], 'stop'),
        ], 'The documents state it plainly [1].');

        $result = $this->nativeGeneratorFor($provider)->generate($this->fileGroundedContext());

        $this->assertSame('The documents state it plainly [1].', $result['answer']);
        $this->assertSame('recovered', $result['metadata']['citation_enforcement'] ?? null);
        $this->assertSame(1, $provider->completeCalls, 'Exactly one enforcement retry');
    }
}
