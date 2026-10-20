<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\StructuredChunkerInterface;
use Condoedge\Ai\Services\SemanticChunker;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7.1 — structure-aware chunking. A legal corpus (CCQ) whose articles are
 * separated by single newlines used to fall to blind 1500-char slices that cut
 * mid-article (art. 1069 was never retrieved). Now: one article per chunk
 * (tiny neighbours under the same heading may merge up to a min size), split on
 * markdown headings + line-start article markers, with heading/article metadata
 * and an article-aware embedding text.
 */
class StructureAwareChunkingTest extends TestCase
{
    private const CCQ = <<<'MD'
# Civil Code of Québec — Co-ownership

> Source: Légis Québec - CCQ-1991

---

# TITLE THREE
# MODALITIES OF OWNERSHIP
I.N. 2015-11-01.

## CHAPTER I — GENERAL PROVISIONS
**1009.** The principal modalities of ownership are co-ownership and superficies.
*1991, c. 64, a. 1009; I.N. 2015-11-01.*
**1010.** Co-ownership is ownership of the same property, jointly and concurrently, by several persons each of whom is privately vested with a share of the right of ownership.
Co-ownership is called undivided where the right of ownership is not accompanied by a physical division of the property.
*1991, c. 64, a. 1010; I.N. 2014-05-01.*
## CHAPTER III — DIVIDED CO-OWNERSHIP OF IMMOVABLES
### DIVISION IV — RIGHTS AND OBLIGATIONS OF CO-OWNERS
**1068.2.** A person who promises to buy a fraction may request the syndicate to provide him with the documents or information concerning the immovable and the syndicate that will enable him to give enlightened consent.
*2019, c. 28, s. 35.*
**1069.** A person who acquires a fraction of an immovable under divided co-ownership, by whatever means, including the exercise of a hypothecary right, is bound to pay all common expenses due with respect to that fraction, with interest, at the time of the acquisition.
A person contemplating the acquisition of such a fraction may request from the syndicate of co-owners a statement of the common expenses due with respect to the fraction.
*1991, c. 64, a. 1069; 2002, c. 19, s. 6; I.N. 2014-05-01; 2019, c. 28, s. 36.*
### DIVISION V — RIGHTS AND OBLIGATIONS OF THE SYNDICATE
**1070.1.** It must be possible to consult the register and documents of the syndicate at any reasonable time, in a manner that respects the privacy of the co-owners.
*2019, c. 28, s. 37.*
MD;

    private const HOWTO = <<<'MD'
# Team, Members and Invitations

> DRAFT — derived from the app's actual routes.

## Where team membership is managed
Team membership is managed in **Team Settings** — open the user menu (top-right navbar) and choose **Team Settings**. This page shows the team's information, its members list, and its pending invitations.

## Adding a new member to your team
New members are added by invitation, from Team Settings:
1. Open **Team Settings** (user menu → Team Settings).
2. In the invitations section, enter the new member's email address and choose their role, then send the invitation.
3. The invitee receives an email with a signed link — following it lets them register and join the team.

## Managing existing members
From Team Settings you can also change a member's role or remove a member from the team. Both actions are available to team owners and administrators from the members list.
MD;

    private function chunker(): SemanticChunker
    {
        return new SemanticChunker();
    }

    /** @return array<int,array{text:string,embed_text:string,metadata:array}> */
    private function ccqChunks(): array
    {
        return $this->chunker()->chunkWithMetadata(self::CCQ, ['min_chunk_chars' => 200, 'max_chunk_chars' => 2000]);
    }

    private function chunkForArticle(array $chunks, string $article): array
    {
        foreach ($chunks as $chunk) {
            if (in_array($article, $chunk['metadata']['articles'], true)) {
                return $chunk;
            }
        }
        $this->fail("No chunk holds article {$article}");
    }

    /** @test */
    public function the_chunker_implements_the_structured_contract(): void
    {
        $this->assertInstanceOf(StructuredChunkerInterface::class, $this->chunker());
    }

    /** @test */
    public function each_article_gets_its_own_chunk_with_its_amendment_history(): void
    {
        $chunk = $this->chunkForArticle($this->ccqChunks(), '1069');

        $this->assertSame(['1069'], $chunk['metadata']['articles']);
        $this->assertSame('1069', $chunk['metadata']['article_no']);
        $this->assertNull($chunk['metadata']['article_range']);
        $this->assertStringStartsWith('**1069.** A person who acquires', $chunk['text']);
        $this->assertStringContainsString('statement of the common expenses', $chunk['text']);
        $this->assertStringContainsString('*1991, c. 64, a. 1069;', $chunk['text']);
        $this->assertStringNotContainsString('1068.2', $chunk['text']);
        $this->assertStringNotContainsString('1070.1', $chunk['text']);
        $this->assertFalse($chunk['metadata']['is_preamble']);
    }

    /** @test */
    public function chunks_carry_the_heading_path_and_section_title(): void
    {
        $chunk = $this->chunkForArticle($this->ccqChunks(), '1069');

        $this->assertSame(
            ['MODALITIES OF OWNERSHIP', 'CHAPTER III — DIVIDED CO-OWNERSHIP OF IMMOVABLES', 'DIVISION IV — RIGHTS AND OBLIGATIONS OF CO-OWNERS'],
            $chunk['metadata']['heading_path']
        );
        $this->assertSame('DIVISION IV — RIGHTS AND OBLIGATIONS OF CO-OWNERS', $chunk['metadata']['section_title']);
        $this->assertArrayHasKey('page', $chunk['metadata']);
        $this->assertNull($chunk['metadata']['page']);
    }

    /** @test */
    public function the_embedded_text_is_prefixed_with_heading_path_and_article(): void
    {
        $chunk = $this->chunkForArticle($this->ccqChunks(), '1069');

        $this->assertStringStartsWith(
            'MODALITIES OF OWNERSHIP > CHAPTER III — DIVIDED CO-OWNERSHIP OF IMMOVABLES > DIVISION IV — RIGHTS AND OBLIGATIONS OF CO-OWNERS — art. 1069:',
            $chunk['embed_text']
        );
        $this->assertStringEndsWith($chunk['text'], $chunk['embed_text']);
    }

    /** @test */
    public function sub_articles_keep_their_full_number(): void
    {
        $chunk = $this->chunkForArticle($this->ccqChunks(), '1070.1');

        $this->assertSame('1070.1', $chunk['metadata']['article_no']);
        $this->assertSame('DIVISION V — RIGHTS AND OBLIGATIONS OF THE SYNDICATE', $chunk['metadata']['section_title']);
    }

    /** @test */
    public function tiny_consecutive_articles_under_one_heading_merge_up_to_the_min_size(): void
    {
        $chunk = $this->chunkForArticle($this->ccqChunks(), '1009');

        // 1009 is ~120 chars (< 200): it absorbs its next sibling under the same heading.
        $this->assertSame(['1009', '1010'], $chunk['metadata']['articles']);
        $this->assertNull($chunk['metadata']['article_no']);
        $this->assertSame('1009–1010', $chunk['metadata']['article_range']);
        $this->assertStringStartsWith('CHAPTER I — GENERAL PROVISIONS', implode(' > ', array_slice($chunk['metadata']['heading_path'], -1)));
    }

    /** @test */
    public function articles_never_merge_across_a_heading(): void
    {
        // 1068.2 is short but the next article sits under the same heading only once;
        // 1069 is past the min size so 1068.2 may absorb it only if 1068.2 < min.
        foreach ($this->ccqChunks() as $chunk) {
            $paths = $chunk['metadata']['heading_path'];
            if (in_array('1070.1', $chunk['metadata']['articles'], true)) {
                $this->assertSame(['1070.1'], $chunk['metadata']['articles']);
            }
            $this->assertIsArray($paths);
        }
    }

    /** @test */
    public function title_and_heading_only_chunks_are_flagged_preamble(): void
    {
        $chunks = $this->ccqChunks();
        $first = $chunks[0];

        $this->assertStringStartsWith('# Civil Code of Québec — Co-ownership', $first['text']);
        $this->assertSame([], $first['metadata']['articles']);
        $this->assertTrue($first['metadata']['is_preamble']);
        // The title chunk never swallows the first article.
        $this->assertStringNotContainsString('**1009.**', $first['text']);

        foreach ($chunks as $chunk) {
            if ($chunk['metadata']['articles'] === []) {
                $this->assertTrue($chunk['metadata']['is_preamble'], 'non-article chunk of an article document: ' . $chunk['text']);
            } else {
                $this->assertFalse($chunk['metadata']['is_preamble']);
            }
        }
    }

    /** @test */
    public function an_article_over_the_max_size_is_split_on_paragraphs_keeping_its_metadata(): void
    {
        $long = "## CHAPTER X\n**1071.** " . str_repeat('The syndicate establishes a contingency fund for major repairs. ', 12)
            . "\n" . str_repeat('The fund is part of the syndicate patrimony and is apportioned by relative value. ', 12)
            . "\n*1991, c. 64, a. 1071.*\n**1072.** Each year the board fixes the contributions after consultation.\n"
            . "**1073.** The syndicate has an insurable interest in the whole immovable.\n"
            . "**1074.** A co-owner's non-compliance with a condition of the insurance is not set up against the syndicate.";

        $chunks = $this->chunker()->chunkWithMetadata($long, ['min_chunk_chars' => 50, 'max_chunk_chars' => 900]);

        $parts = array_values(array_filter($chunks, fn ($c) => $c['metadata']['article_no'] === '1071'));
        $this->assertGreaterThanOrEqual(2, count($parts));
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(900, mb_strlen($part['text']));
            $this->assertSame(['1071'], $part['metadata']['articles']);
            $this->assertStringContainsString('art. 1071', $part['embed_text']);
        }
        $this->assertStringStartsWith('**1071.**', $parts[0]['text']);
    }

    /** @test */
    public function a_how_to_document_chunks_by_heading_sections_without_preamble_flags(): void
    {
        $chunks = $this->chunker()->chunkWithMetadata(self::HOWTO, ['min_chunk_chars' => 200, 'max_chunk_chars' => 2000]);

        $this->assertGreaterThanOrEqual(3, count($chunks));
        $adding = null;
        foreach ($chunks as $chunk) {
            $this->assertSame([], $chunk['metadata']['articles']);
            $this->assertNull($chunk['metadata']['article_no']);
            $this->assertFalse($chunk['metadata']['is_preamble'], $chunk['text']);
            if (str_contains($chunk['text'], '## Adding a new member')) {
                $adding = $chunk;
            }
        }

        $this->assertNotNull($adding);
        $this->assertStringStartsWith('## Adding a new member to your team', $adding['text']);
        $this->assertStringNotContainsString('## Managing existing members', $adding['text']);
        $this->assertSame('Adding a new member to your team', $adding['metadata']['section_title']);
        $this->assertSame(['Team, Members and Invitations', 'Adding a new member to your team'], $adding['metadata']['heading_path']);
        $this->assertStringStartsWith('Team, Members and Invitations > Adding a new member to your team:', $adding['embed_text']);
        // Numbered list items (1. 2. 3.) are not article markers.
        $this->assertStringContainsString('3. The invitee receives', $adding['text']);
    }

    /** @test */
    public function headings_inside_code_fences_are_not_boundaries(): void
    {
        $doc = "## Setup\nRun the following script to configure everything for the team before inviting anyone else to it, please.\n```bash\n# install deps\nnpm install\n```\nThen restart the server and you are done with the whole configuration process.";

        $chunks = $this->chunker()->chunkWithMetadata($doc, ['min_chunk_chars' => 50, 'max_chunk_chars' => 2000]);

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('# install deps', $chunks[0]['text']);
    }

    /** @test */
    public function unstructured_text_falls_back_to_the_legacy_splitter_with_empty_metadata(): void
    {
        $text = str_repeat("Plain paragraph text without any heading or article marker at all. ", 10)
            . "\n\n" . str_repeat("Another plain paragraph that keeps going for a while. ", 10);

        $structured = $this->chunker()->chunkWithMetadata($text, ['chunk_size' => 500, 'overlap' => 50]);
        $legacy = $this->chunker()->chunk($text, ['chunk_size' => 500, 'overlap' => 50]);

        $this->assertSame(array_values($legacy), array_column($structured, 'text'));
        foreach ($structured as $chunk) {
            $this->assertSame([], $chunk['metadata']['heading_path']);
            $this->assertNull($chunk['metadata']['article_no']);
            $this->assertFalse($chunk['metadata']['is_preamble']);
            $this->assertSame($chunk['text'], $chunk['embed_text']);
        }
    }

    /** @test */
    public function chunk_returns_the_structured_texts_for_structured_content(): void
    {
        $texts = $this->chunker()->chunk(self::CCQ, ['min_chunk_chars' => 200, 'max_chunk_chars' => 2000]);

        $this->assertSame(array_column($this->ccqChunks(), 'text'), $texts);
    }
}
