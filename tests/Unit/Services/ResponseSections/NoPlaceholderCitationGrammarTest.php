<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Phase 8-B (I2): the literal "[N]" citation grammar is echoed back by the model
 * ("([N] 2)" shipped live), so NO rendered response section may contain it —
 * every instruction names the real passage numbers instead. Renders the whole
 * default section pipeline over file-, conversation- and data-bearing fixtures.
 */
class NoPlaceholderCitationGrammarTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function turns(): iterable
    {
        foreach (['friendly', 'detailed', 'concise', 'minimal'] as $style) {
            yield "knowledge {$style}" => ['knowledge_query', $style];
            yield "data {$style}" => ['data_query', $style];
        }
    }

    /**
     * @test
     * @dataProvider turns
     */
    public function no_rendered_section_contains_the_placeholder_marker(string $questionType, string $style): void
    {
        $context = new PipelineContext();
        $context->question = "If I buy a unit, am I liable for the previous owner's unpaid fees?";
        $context->qu = new QueryUnderstandingResult(questionType: $questionType);
        $context->queryResult = new QueryGenerationResult(cypher: 'MATCH (u:Unit) RETURN u.id AS id, u.name AS name');
        $context->executionResult = new QueryExecutionResult(
            success: true,
            data: $questionType === 'data_query' ? [['id' => 1, 'name' => '310'], ['id' => 2, 'name' => '311']] : []
        );
        $context->fileContext = ['relevant_files' => [
            ['file_id' => 1, 'filename' => 'CCQ.md', 'snippet' => '**1069.** The buyer is bound.', 'relevance' => 0.5],
            ['file_id' => 1, 'filename' => 'CCQ.md', 'snippet' => '**1078.** A judgment…', 'relevance' => 0.4],
        ]];
        $context->conversationContext = [
            'last_referenced_files' => [['ref' => 1, 'id' => 7, 'name' => 'old-rules.pdf', 'snippet' => 'Old rules text']],
            'last_result_sample' => [['id' => 9, 'name' => 'Unit 9']],
        ];

        $generator = new ResponseGenerator(Mockery::mock(LlmProviderInterface::class), ['default_style' => $style]);
        ['system' => $system, 'user' => $user] = $generator->buildPrompts($context, ['style' => $style, 'format' => 'text']);

        $this->assertDoesNotMatchRegularExpression('/\[\s*N\s*\]/i', $system . "\n" . $user);
        $this->assertStringContainsString('old-rules.pdf', $user, 'the conversation section was rendered');
    }
}
