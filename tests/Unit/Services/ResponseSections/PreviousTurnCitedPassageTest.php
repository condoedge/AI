<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Response\ResponseFileEnricher;
use Condoedge\Ai\Services\ResponseSections\ResponseConversationContextSection;
use Condoedge\Ai\Tests\TestCase;

/**
 * Phase 7.5-lite — the previous turn's file excerpt is the CITED chunk (one
 * article since structure-aware chunking), not the head of the ±window stitched
 * snippet: the head showed the least relevant neighbour articles and the cited
 * article was cut off by the cap.
 */
class PreviousTurnCitedPassageTest extends TestCase
{
    private function citedRef(): array
    {
        $window = "**1067.** A co-owner who suffers injury…\n…\n**1068.** Every co-owner may apply…\n…\n"
            . "**1069.** A person who acquires a fraction is bound to pay all common expenses due.\n…\n**1070.** Among the registers…";

        return [
            'file_id' => 'physical:public/laws/ccq.md',
            'filename' => 'CCQ_Co-ownership_EN.md',
            'snippet' => $window,
            'passage' => '**1069.** A person who acquires a fraction is bound to pay all common expenses due.',
            'article_no' => '1069',
            'heading' => 'DIVISION IV — RIGHTS AND OBLIGATIONS OF CO-OWNERS',
            'relevance' => 0.6,
            'chunk_index' => 66,
            'source' => 'physical',
        ];
    }

    /** @test */
    public function the_referenced_file_record_keeps_the_cited_passage_and_its_locator(): void
    {
        $referenced = (new ResponseFileEnricher())->buildReferencedFiles(
            'The buyer must pay the arrears [1].',
            ['relevant_files' => [$this->citedRef()]]
        );

        $this->assertSame('**1069.** A person who acquires a fraction is bound to pay all common expenses due.', $referenced[0]['passage']);
        $this->assertSame('1069', $referenced[0]['article_no']);
        $this->assertSame('DIVISION IV — RIGHTS AND OBLIGATIONS OF CO-OWNERS', $referenced[0]['heading']);
        $this->assertSame(66, $referenced[0]['chunk_index']);
    }

    /** @test */
    public function the_referenced_file_record_keeps_the_articles_the_answer_named_from_that_passage(): void
    {
        // Eval p17-B rights t1: the next turn must know which articles the user
        // has already been given. The window holds 1067–1070; the answer named two.
        $referenced = (new ResponseFileEnricher())->buildReferencedFiles(
            'A buyer must pay the arrears (art. 1069) [1] and may apply for a revision (art. 1068) [1]. See also art. 1094.',
            ['relevant_files' => [$this->citedRef()]]
        );

        $this->assertSame([1068, 1069], $referenced[0]['cited_articles'], 'only articles this passage defines; 1094 is not one');
    }

    /** @test */
    public function an_answer_that_names_no_article_of_the_passage_stores_no_article_list(): void
    {
        $referenced = (new ResponseFileEnricher())->buildReferencedFiles(
            'The buyer must pay the arrears [1].',
            ['relevant_files' => [$this->citedRef()]]
        );

        $this->assertArrayNotHasKey('cited_articles', $referenced[0]);
    }

    /** @test */
    public function the_previous_turn_renders_the_cited_passage_not_the_window_head(): void
    {
        $referenced = (new ResponseFileEnricher())->buildReferencedFiles(
            'The buyer must pay the arrears [1].',
            ['relevant_files' => [$this->citedRef()]]
        );

        config(['ai.response_generation.previous_snippet_chars' => 60]);
        $context = new PipelineContext();
        $context->conversationContext = ['last_referenced_files' => $referenced];

        $output = (new ResponseConversationContextSection())->format($context, []);

        $this->assertStringContainsString('**1069.** A person who acquires a fraction', $output);
        $this->assertStringNotContainsString('**1067.**', $output);
        // The file's name and the place of the excerpt are document content: inside the fence.
        $this->assertStringContainsString(
            "<retrieved_document source=previous_turn>\nFile: CCQ_Co-ownership_EN.md\n§ DIVISION IV — RIGHTS AND OBLIGATIONS OF CO-OWNERS — art. 1069\n\n**1069.**",
            $output
        );
    }

    /** @test */
    public function legacy_records_without_a_passage_keep_the_capped_snippet(): void
    {
        config(['ai.response_generation.previous_snippet_chars' => 20]);
        $context = new PipelineContext();
        $context->conversationContext = ['last_referenced_files' => [[
            'id' => 9, 'name' => 'law.pdf', 'snippet' => str_repeat('abcdefghij', 10),
        ]]];

        $output = (new ResponseConversationContextSection())->format($context, []);

        $this->assertStringContainsString('abcdefghijabcdefghij…', $output);
    }
}
