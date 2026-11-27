<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Response\AskerFactsAnswerer;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The facts judge reads a follow-up as Query Understanding resolved it: the follow-up's own
 * words may not name its subject, and a fact sharing one of those words is not an answer.
 */
class AskerFactsAnswererTest extends TestCase
{
    /** @var list<array> */
    private array $calls = [];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function answerer(array $reply = ['answered' => false, 'answer' => '']): AskerFactsAnswerer
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages) use ($reply) {
            $this->calls[] = $messages;

            return $reply;
        });

        return new AskerFactsAnswerer($llm);
    }

    private function ask(string $question, QueryUnderstandingResult $qu, array $reply = ['answered' => false, 'answer' => '']): ?string
    {
        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = $qu;
        $ctx->askerFacts = ['Item A: its count is 3.'];

        return $this->answerer($reply)->answer($question, $ctx);
    }

    private function user(): string
    {
        return $this->calls[0][1]['content'];
    }

    /** @test */
    public function a_follow_up_is_judged_with_its_resolved_question(): void
    {
        $this->ask('And the total?', new QueryUnderstandingResult(searchQuery: 'kw', clarifiedQuestion: 'What is the total of X?', followUpReference: true));

        $this->assertStringContainsString("QUESTION:\nAnd the total?\n\nRead with the conversation, it asks:\nWhat is the total of X?", $this->user());
        $this->assertStringNotContainsString('kw', $this->user());
    }

    /** @test The rewrite guard can blank the clarified question: the search phrase still names the subject. */
    public function a_follow_up_without_a_clarified_question_falls_back_to_the_search_phrase(): void
    {
        $this->ask('And the total?', new QueryUnderstandingResult(searchQuery: 'total of X', clarifiedQuestion: '', followUpReference: true));

        $this->assertStringContainsString("Read with the conversation, it asks:\ntotal of X", $this->user());
    }

    /** @test */
    public function a_first_message_is_judged_on_its_own_words(): void
    {
        $this->ask('What is the total of X?', new QueryUnderstandingResult(searchQuery: 'total of X', clarifiedQuestion: 'What is the grand total of X?'));

        $this->assertStringNotContainsString('Read with the conversation', $this->user());
        $this->assertStringNotContainsString('grand total', $this->user());
        $this->assertStringEndsWith("QUESTION:\nWhat is the total of X?", $this->user());
    }

    /** @test */
    public function a_resolved_question_equal_to_the_message_is_not_repeated(): void
    {
        $this->ask('What is the total of X?', new QueryUnderstandingResult(clarifiedQuestion: ' What is the total of X? ', followUpReference: true));

        $this->assertStringNotContainsString('Read with the conversation', $this->user());
    }

    /** @test */
    public function the_rule_that_a_fact_answers_only_its_own_subject_is_in_the_prompt(): void
    {
        $this->ask('How many A?', new QueryUnderstandingResult());

        $this->assertStringContainsString('never a question about another subject that shares a word', $this->calls[0][0]['content']);
    }

    /** @test */
    public function only_an_explicit_answer_is_returned(): void
    {
        $qu = new QueryUnderstandingResult();
        $this->assertSame('It is 3.', $this->ask('How many A?', $qu, ['answered' => true, 'answer' => ' It is 3. ']));
        $this->calls = [];
        $this->assertNull($this->ask('How many B?', $qu));
    }
}
