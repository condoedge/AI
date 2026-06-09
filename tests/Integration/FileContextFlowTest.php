<?php

namespace Condoedge\Ai\Tests\Integration;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

class FileContextFlowTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_response_generator_receives_file_context_in_options(): void
    {
        // Verify file_context flows into the built prompt via the typed
        // PipelineContext. The FileContextSection runs in the USER role, so
        // its content lands in the 'user' part of the role-split prompt.
        $fileContext = [
            'relevant_files' => [
                [
                    'file_id' => 1,
                    'filename' => 'test.pdf',
                    'snippet' => 'Test content from file',
                    'relevance' => 0.9,
                    'source' => 'database',
                    'chunk_index' => 0,
                ],
            ],
            'file_count' => 1,
        ];

        // Mock LLM provider
        $llmProvider = Mockery::mock(LlmProviderInterface::class);
        $llmProvider->shouldReceive('complete')
            ->andReturn('Response with [1] citation');

        $generator = new ResponseGenerator($llmProvider);

        // Build a real PipelineContext carrying the file context.
        $context = new PipelineContext();
        $context->question = 'Test question';
        $context->queryResult = new QueryGenerationResult(cypher: 'MATCH (n) RETURN n');
        $context->executionResult = new QueryExecutionResult(success: true, data: [['name' => 'Test']]);
        $context->fileContext = $fileContext;

        ['system' => $system, 'user' => $user] = $generator->buildPrompts($context, ['style' => 'friendly']);

        // The user prompt should contain the file context section.
        $this->assertStringContainsString('RELEVANT FILE CONTENT', $user);
        $this->assertStringContainsString('test.pdf', $user);
        $this->assertStringContainsString('[1]', $user);
        $this->assertStringContainsString('Test content from file', $user);
    }

    public function test_response_generator_excludes_file_context_section_when_no_files(): void
    {
        // Mock LLM provider
        $llmProvider = Mockery::mock(LlmProviderInterface::class);
        $llmProvider->shouldReceive('complete')
            ->andReturn('Simple response');

        $generator = new ResponseGenerator($llmProvider);

        // Build a real PipelineContext WITHOUT any file context.
        $context = new PipelineContext();
        $context->question = 'Test question';
        $context->queryResult = new QueryGenerationResult(cypher: 'MATCH (n) RETURN n');
        $context->executionResult = new QueryExecutionResult(success: true, data: [['name' => 'Test']]);
        // No fileContext set.

        ['system' => $system, 'user' => $user] = $generator->buildPrompts($context, ['style' => 'friendly']);

        // Neither prompt should contain the file context section.
        $this->assertStringNotContainsString('RELEVANT FILE CONTENT', $system);
        $this->assertStringNotContainsString('RELEVANT FILE CONTENT', $user);
    }
}
