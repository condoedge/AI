<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\Services\PatternLibrary;
use Condoedge\Ai\Services\QueryGenerator;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Services\SemanticPromptBuilder;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Prompt-capture seam: the eval harness needs the VERBATIM system/user strings of
 * both LLM calls in its trace, but production payloads must never grow — so the
 * exact prompts are attached to result metadata ONLY when `capture_prompts` is set.
 */
class PromptCaptureTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('logging.default', 'null');
        $app['config']->set('ai.log_channel', 'null');
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeQueryGenerator(): QueryGenerator
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('complete')->andReturn('MATCH (u:Unit) RETURN u LIMIT 5');

        $graph = Mockery::mock(GraphStoreInterface::class)->shouldIgnoreMissing();

        // Deterministic prompt builder → the captured strings are exactly what was sent.
        $builder = new class(new PatternLibrary()) extends SemanticPromptBuilder {
            public function buildPrompts(string $question, PipelineContext $context, bool $allowWrite = false): array
            {
                return ['system' => 'QG-SYSTEM', 'user' => 'QG-USER: ' . $question];
            }
        };

        return new QueryGenerator(
            llm: $llm,
            graphStore: $graph,
            config: ['enable_templates' => false, 'max_retries' => 1],
            promptBuilder: $builder,
        );
    }

    /** @test */
    public function query_generator_attaches_verbatim_prompts_only_when_the_flag_is_set(): void
    {
        $context = new PipelineContext();
        $context->question = 'How many units?';

        $generator = $this->makeQueryGenerator();

        $captured = $generator->generate('How many units?', $context, [
            'capture_prompts' => true,
            'skip_security' => true,
        ]);
        $this->assertSame('QG-SYSTEM', $captured->metadata['prompts']['system'] ?? null);
        $this->assertSame('QG-USER: How many units?', $captured->metadata['prompts']['user'] ?? null);

        $plain = $this->makeQueryGenerator()->generate('How many units?', $context, ['skip_security' => true]);
        $this->assertArrayNotHasKey('prompts', $plain->metadata);
    }

    /** @test */
    public function response_generator_attaches_verbatim_prompts_only_when_the_flag_is_set(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('complete')->andReturn('The answer.');

        $generator = new ResponseGenerator($llm, []);

        $context = new PipelineContext();
        $context->question = 'How many units?';
        $context->queryResult = new QueryGenerationResult(cypher: 'MATCH (u:Unit) RETURN u');
        $context->executionResult = new QueryExecutionResult(success: true, data: [['count' => 3]]);

        $captured = $generator->generate($context, ['capture_prompts' => true]);
        $this->assertIsString($captured['metadata']['prompts']['system'] ?? null);
        $this->assertIsString($captured['metadata']['prompts']['user'] ?? null);
        $this->assertStringContainsString('How many units?', $captured['metadata']['prompts']['user']);

        $plain = $generator->generate($context, []);
        $this->assertArrayNotHasKey('prompts', $plain['metadata']);
    }
}
