<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Tests\TestCase;
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

    // =========================================================================
    // The uncited closing paragraph (eval p19-B remaining #6)
    // =========================================================================

    private const CITED = "A buyer is bound to pay the common expenses due on the fraction [1].\n\n"
        . 'A co-owner is bound to contribute to the common expenses [1].';

    /**
     * @test eval p19-B rights t1: a document-grounded answer whose every claim is cited closed
     * with "…feel free to ask!". The last paragraph has no marker after two cited blocks: it is
     * dropped, and the trace says what was dropped.
     */
    public function p19_an_uncited_closing_paragraph_of_a_cited_answer_is_dropped(): void
    {
        $this->llmMock->shouldReceive('complete')->once()->andReturn(
            self::CITED . "\n\nUnderstanding these obligations is crucial. If you have any specific questions, feel free to ask!"
        );

        $result = $this->generator->generate($this->fileGroundedContext());

        $this->assertSame(self::CITED, $result['answer']);
        $this->assertSame(
            'Understanding these obligations is crucial. If you have any specific questions, feel free to ask!',
            $result['metadata']['closing_dropped'] ?? null
        );
    }

    /**
     * @test eval p20-A tear-down, end to end: an uncited last paragraph that SUMMARIZES the cited
     * blocks — the verdict of a yes/no question — is grounded in them and ships; nothing is
     * flagged. And the prompt asked for that answer FIRST, with its citation.
     */
    public function p20_an_uncited_conclusion_grounded_in_the_cited_blocks_is_kept(): void
    {
        $prompts = [];
        $answer = self::CITED . "\n\nIn summary, yes: as the buyer you are bound to pay the common expenses due on the fraction.";
        $this->llmMock->shouldReceive('complete')->once()->andReturnUsing(function ($user) use (&$prompts, $answer) {
            $prompts[] = $user;

            return $answer;
        });

        $result = $this->generator->generate($this->fileGroundedContext());

        $this->assertSame($answer, $result['answer']);
        $this->assertArrayNotHasKey('closing_dropped', $result['metadata']);
        // The positional "direct answer first" prompt line was reverted after eval p21.
        $this->assertStringNotContainsString('START WITH THE DIRECT ANSWER', $prompts[0]);
    }

    /** @test A fully cited answer is untouched and carries no flag. */
    public function p19_an_answer_without_an_uncited_closing_is_untouched(): void
    {
        $this->llmMock->shouldReceive('complete')->once()->andReturn(self::CITED . "\n\nInterest is owed on arrears [1].");

        $result = $this->generator->generate($this->fileGroundedContext());

        $this->assertSame(self::CITED . "\n\nInterest is owed on arrears [1].", $result['answer']);
        $this->assertArrayNotHasKey('closing_dropped', $result['metadata']);
    }

    /** @test NOT on a data turn: there the narrative is a lead in front of code-rendered rows, with its own rules. */
    public function p19_a_data_turn_keeps_its_last_paragraph(): void
    {
        $context = $this->fileGroundedContext();
        $context->qu = new QueryUnderstandingResult(questionType: 'data_query');
        $context->queryResult = new QueryGenerationResult(cypher: 'MATCH (u:Unit) RETURN u.name AS unit_name', explanation: 'x', confidence: 1.0);
        $context->executionResult = new QueryExecutionResult(success: true, data: [['unit_name' => '101']]);
        $answer = self::CITED . "\n\nUnit 101 is the only one concerned.";
        $this->llmMock->shouldReceive('complete')->once()->andReturn($answer);

        $result = $this->generator->generate($context, ['style' => 'concise']);

        $this->assertStringContainsString('Unit 101 is the only one concerned.', $result['answer']);
        $this->assertArrayNotHasKey('closing_dropped', $result['metadata']);
    }

    /** @test Not where citations are not required (terse styles, the kill switch): no rule to enforce. */
    public function p19_the_closing_is_kept_where_citations_are_not_required(): void
    {
        config(['ai.response_generation.citation_enforcement' => false]);
        $answer = self::CITED . "\n\nFeel free to ask about anything else!";
        $this->llmMock->shouldReceive('complete')->once()->andReturn($answer);

        $result = $this->generator->generate($this->fileGroundedContext());

        $this->assertSame($answer, $result['answer']);
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
}
