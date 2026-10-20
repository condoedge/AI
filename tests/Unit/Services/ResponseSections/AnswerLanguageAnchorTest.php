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
 * Eval p12-B digest t1: with 37 French rows right above an English question, gpt-4o-mini
 * answered in French although the system guideline named 'en'. The user message now ends
 * with the QU language as its last instruction (recency anchor).
 */
class AnswerLanguageAnchorTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function prompts(string $language): array
    {
        $context = new PipelineContext();
        $context->question = 'When was our last meeting?';
        $context->qu = new QueryUnderstandingResult(questionType: 'data_query', language: $language);
        $context->queryResult = new QueryGenerationResult(cypher: 'MATCH (m:Meeting) RETURN m.name AS name, m.date AS date');
        $context->executionResult = new QueryExecutionResult(success: true, data: [
            ['name' => 'Assemblée générale annuelle SDC 1240', 'date' => '2023-10-24'],
        ]);

        return (new ResponseGenerator(Mockery::mock(LlmProviderInterface::class)))->buildPrompts($context, ['format' => 'text']);
    }

    /** @test */
    public function the_user_message_ends_with_the_question_language(): void
    {
        ['user' => $user] = $this->prompts('en');

        $this->assertStringEndsWith(
            "Write the answer in the question's language ('en'), not in the language of the data or documents above.",
            rtrim($user)
        );
    }

    /** @test */
    public function no_anchor_without_a_detected_language(): void
    {
        ['user' => $user] = $this->prompts('');

        $this->assertStringNotContainsString("question's language (", $user);
    }
}
