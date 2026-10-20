<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\ResponseSections\FileContextSection;
use PHPUnit\Framework\TestCase;

/**
 * The deepening contract in the knowledge prompt (eval p17-B rights t1).
 *
 * "Tell me more about my duty to contribute…" had the passage with the NEW rule
 * as [2] and answered with the previous turn's points: the prompt showed the
 * passages alike and nothing said which ones the user already had. The section
 * now states, computed by code, what the previous answer covered and what is new
 * in this turn's passages — only on a follow-up turn with something new.
 */
class FileContextSectionDeepeningTest extends TestCase
{
    private const LAW = 'physical:rules/aircraft.md';

    private function articles(int ...$numbers): string
    {
        return implode("\n…\n", array_map(static fn (int $n): string => "**{$n}.** Rule {$n} of the text.", $numbers));
    }

    private function passage(int $chunk, string $snippet): array
    {
        return ['file_id' => self::LAW, 'filename' => 'aircraft.md', 'snippet' => $snippet, 'chunk_index' => $chunk, 'relevance' => 0.6];
    }

    private function context(array $files, array $previouslyCited, bool $followUp = false): PipelineContext
    {
        $context = new PipelineContext();
        $context->fileContext = ['relevant_files' => $files];
        $context->conversationContext = ['last_referenced_files' => $previouslyCited];
        $context->qu = new QueryUnderstandingResult(questionType: 'knowledge_query', followUpReference: $followUp);

        return $context;
    }

    private function format(PipelineContext $context): string
    {
        return (new FileContextSection())->format($context, ['style' => 'friendly']);
    }

    /** @test */
    public function a_follow_up_turn_is_told_what_was_covered_and_what_is_new(): void
    {
        $output = $this->format($this->context(
            [
                $this->passage(59, $this->articles(1063, 1064, 1065, 1066)),
                $this->passage(74, $this->articles(1071, 1072, 1073)),
                $this->passage(66, $this->articles(1068, 1069, 1070)),
            ],
            [
                ['ref' => 1, 'id' => self::LAW, 'name' => 'aircraft.md', 'chunk_index' => 59,
                    'snippet' => $this->articles(1063, 1064, 1065, 1066), 'cited_articles' => [1063, 1064, 1065, 1066]],
                ['ref' => 4, 'id' => self::LAW, 'name' => 'aircraft.md', 'chunk_index' => 64,
                    'snippet' => $this->articles(1067, 1068, 1069), 'cited_articles' => [1067, 1068]],
            ]
        ));

        $this->assertStringContainsString('ALREADY COVERED in this conversation: art. 1063–1068', $output);
        $this->assertStringContainsString('NEW in these passages: art. 1071–1073 in [2]; art. 1069, 1070 in [3]', $output);
        $this->assertStringContainsString('[1] holds nothing new', $output);
        // The instruction: build on the new, do not repeat the covered.
        $this->assertMatchesRegularExpression('/build on the NEW/i', $output);
        $this->assertMatchesRegularExpression('/not repeat the covered points/i', $output);
    }

    /** @test */
    public function a_turn_with_a_question_analysis_is_a_question_of_its_own_and_gets_no_contract(): void
    {
        // Live 2026-10-07 (an overview, then "can I tear down this wall?"): the map
        // reader named the general rule the search had missed — an article the
        // overview had cited. The analysis asked to build on it first; the contract
        // called it "already covered, at most one sentence". The analysis is only
        // ever set on a turn Query Understanding did not judge a follow-up.
        $context = $this->context(
            [$this->passage(59, $this->articles(1063)), $this->passage(38, $this->articles(1045)), $this->passage(110, $this->articles(1097))],
            [['ref' => 1, 'id' => self::LAW, 'name' => 'aircraft.md', 'chunk_index' => 59,
                'snippet' => $this->articles(1063), 'cited_articles' => [1063]]]
        );
        $this->assertStringContainsString('ALREADY COVERED', $this->format($context), 'without an analysis: the contract');

        $context->fileContext['analysis'] = ['rules' => [['role' => 'general_rule', 'articles' => [1063], 'passage' => 1]], 'has_classification' => false];

        $output = $this->format($context);
        $this->assertStringNotContainsString('FOLLOW-UP', $output);
        $this->assertStringNotContainsString('ALREADY COVERED', $output);
        $this->assertStringContainsString('<retrieved_document id=1>', $output, 'the passages are there as on any turn');
    }

    /** @test */
    public function the_contract_follows_the_passages_and_the_citation_reminder(): void
    {
        $output = $this->format($this->context(
            [$this->passage(59, $this->articles(1063, 1064)), $this->passage(74, $this->articles(1071))],
            [['ref' => 1, 'id' => self::LAW, 'name' => 'aircraft.md', 'chunk_index' => 59, 'snippet' => $this->articles(1063, 1064)]]
        ));

        $this->assertGreaterThan(strpos($output, '<retrieved_document id=2>'), strpos($output, 'ALREADY COVERED'));
        $this->assertGreaterThan(strpos($output, 'ALREADY COVERED'), strrpos($output, 'cite the passage it came from'));
    }

    /** @test */
    public function a_first_turn_gets_no_contract(): void
    {
        $output = $this->format($this->context(
            [$this->passage(59, $this->articles(1063, 1064)), $this->passage(74, $this->articles(1071))],
            []
        ));

        $this->assertStringNotContainsString('ALREADY COVERED', $output);
        $this->assertStringNotContainsString('NEW in these passages', $output);
    }

    /** @test */
    public function a_follow_up_with_nothing_new_gets_no_contract(): void
    {
        $output = $this->format($this->context(
            [$this->passage(59, $this->articles(1063, 1064))],
            [['ref' => 1, 'id' => self::LAW, 'name' => 'aircraft.md', 'chunk_index' => 59, 'snippet' => $this->articles(1063, 1064)]],
            followUp: true
        ));

        $this->assertStringNotContainsString('ALREADY COVERED', $output);
    }

    /** @test */
    public function an_unrelated_turn_is_not_shaped_by_an_old_citation(): void
    {
        $previous = [['ref' => 1, 'id' => self::LAW, 'name' => 'aircraft.md', 'chunk_index' => 59, 'snippet' => $this->articles(1063, 1064)]];
        $files = [$this->passage(113, $this->articles(1098, 1099))];

        $this->assertStringNotContainsString('ALREADY COVERED', $this->format($this->context($files, $previous)));
        // Query Understanding judged it a follow-up: the contract stands.
        $this->assertStringContainsString('NEW in these passages: art. 1098, 1099 in [1]', $this->format($this->context($files, $previous, followUp: true)));
    }

    /** @test */
    public function passages_without_article_numbers_are_named_by_their_number(): void
    {
        $output = $this->format($this->context(
            [$this->passage(3, 'Open Team settings, then Members.'), $this->passage(7, 'Roles decide what a member can see.')],
            [['ref' => 1, 'id' => self::LAW, 'name' => 'aircraft.md', 'chunk_index' => 3, 'snippet' => 'Open Team settings, then Members.']]
        ));

        $this->assertStringContainsString('ALREADY COVERED in this conversation: passage [1]', $output);
        $this->assertStringContainsString('NEW in these passages: [2]', $output);
    }

    /** @test */
    public function a_file_listing_never_carries_a_contract(): void
    {
        $context = $this->context(
            [['file_id' => self::LAW, 'filename' => 'aircraft.md', 'listing_only' => true, 'chunk_index' => 0]],
            [['ref' => 1, 'id' => self::LAW, 'name' => 'aircraft.md', 'chunk_index' => 59, 'snippet' => $this->articles(1063)]],
            followUp: true
        );
        $context->fileContext['enumeration'] = true;

        $this->assertStringNotContainsString('ALREADY COVERED', $this->format($context));
    }
}
