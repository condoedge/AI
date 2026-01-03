<?php

namespace Condoedge\Ai\Tests\Integration;

use Condoedge\Ai\Services\Context\FileContextProvider;
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
        // Create a ResponseGenerator and verify file_context flows into context array
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
        $llmProvider = Mockery::mock(\Condoedge\Ai\Contracts\LlmProviderInterface::class);
        $llmProvider->shouldReceive('complete')
            ->andReturn('Response with [1] citation');

        $generator = new ResponseGenerator($llmProvider);

        // Build prompt with file_context in options
        $context = [
            'question' => 'Test question',
            'cypher' => 'MATCH (n) RETURN n',
            'data' => [['name' => 'Test']],
            'stats' => [],
            'file_context' => $fileContext,
        ];

        $prompt = $generator->buildPrompt($context, ['style' => 'friendly']);

        // The prompt should contain the file context section
        $this->assertStringContainsString('RELEVANT FILE CONTENT', $prompt);
        $this->assertStringContainsString('test.pdf', $prompt);
        $this->assertStringContainsString('[1]', $prompt);
        $this->assertStringContainsString('Test content from file', $prompt);
    }

    public function test_response_generator_excludes_file_context_section_when_no_files(): void
    {
        // Mock LLM provider
        $llmProvider = Mockery::mock(\Condoedge\Ai\Contracts\LlmProviderInterface::class);
        $llmProvider->shouldReceive('complete')
            ->andReturn('Simple response');

        $generator = new ResponseGenerator($llmProvider);

        // Build prompt WITHOUT file_context
        $context = [
            'question' => 'Test question',
            'cypher' => 'MATCH (n) RETURN n',
            'data' => [['name' => 'Test']],
            'stats' => [],
            // No file_context key
        ];

        $prompt = $generator->buildPrompt($context, ['style' => 'friendly']);

        // The prompt should NOT contain file context section
        $this->assertStringNotContainsString('RELEVANT FILE CONTENT', $prompt);
    }
}
