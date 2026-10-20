<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Support\DocumentTitle;
use PHPUnit\Framework\TestCase;

/**
 * A document's OWN TITLE is read off its structure: the first top-level markdown
 * heading of its first chunk. Nothing is guessed from a file name, a first line
 * or a vocabulary — a document without such a heading has no title.
 */
class DocumentTitleTest extends TestCase
{
    private function chunk(int $index, string $content, array $metadata = []): FileChunk
    {
        return new FileChunk('physical:laws/ccq.md', 'ccq.md', $content, [], $index, 9, 0, mb_strlen($content), $metadata);
    }

    /** @test */
    public function the_title_is_the_first_top_level_heading_of_the_first_chunk(): void
    {
        $first = "# Civil Code of Québec — Co-ownership\n\n> Source: Légis Québec - CCQ-1991\n\n---\n\n# TITLE THREE\n# MODALITIES OF OWNERSHIP\nI.N. 2015-11-01.\n\n## CHAPTER I — GENERAL PROVISIONS";

        $this->assertSame('Civil Code of Québec — Co-ownership', DocumentTitle::fromFirstChunk($first));
        $this->assertSame('Team, Members and Invitations', DocumentTitle::fromFirstChunk("\n\n# Team, Members and Invitations\n\nHow teams work."));
    }

    /** @test */
    public function markdown_marks_of_the_heading_are_not_part_of_the_title(): void
    {
        $this->assertSame('The Building Rules', DocumentTitle::fromFirstChunk("#   **The   Building** `Rules`  ##  \nText."));
        $this->assertSame('Rules', DocumentTitle::fromFirstChunk("\r\n# Rules\r\nText."));
    }

    /** @test */
    public function a_first_chunk_without_a_top_level_heading_has_no_title(): void
    {
        // A scanned upload: its first line is not a title — it is not guessed to be one.
        $this->assertSame('', DocumentTitle::fromFirstChunk("DECLARATION OF CO-OWNERSHIP\nBEFORE Mtre Tremblay, notary\nArticle 1 Purpose."));
        $this->assertSame('', DocumentTitle::fromFirstChunk(''));
        // Not a heading: no space after the mark, more than one mark, an indented code line.
        $this->assertSame('', DocumentTitle::fromFirstChunk("#hashtag first\nText."));
        $this->assertSame('', DocumentTitle::fromFirstChunk("#\nText."));
        // A document that opens on a section is not titled by a later top-level heading.
        $this->assertSame('', DocumentTitle::fromFirstChunk("## Adding a member\nClick.\n# Appendix"));
        // A "# comment" of a script is not a heading.
        $this->assertSame('', DocumentTitle::fromFirstChunk("```bash\n# install\nnpm i\n```\nText."));
    }

    /** @test */
    public function the_title_of_a_file_is_read_off_its_first_chunk_only(): void
    {
        $chunks = [
            $this->chunk(2, "# TITLE FOUR\n**1100.** Text."),
            $this->chunk(0, "# Civil Code of Québec — Co-ownership\n> Source"),
            $this->chunk(1, '**1009.** Text.'),
        ];

        $this->assertSame('Civil Code of Québec — Co-ownership', DocumentTitle::of($chunks));
        // Without the document's first chunk nothing is known: a later top-level
        // heading is a part of the document, never its title.
        $this->assertSame('', DocumentTitle::of([$chunks[0], $chunks[2]]));
        $this->assertSame('', DocumentTitle::of([]));
        $this->assertSame('', DocumentTitle::of([$this->chunk(0, 'Scanned text without any heading.')]));
    }

    /** @test */
    public function a_title_stored_on_the_chunk_at_processing_time_is_preferred(): void
    {
        $stamped = $this->chunk(4, '**1069.** Text.', [DocumentTitle::METADATA_KEY => 'Civil Code of Québec — Co-ownership']);

        $this->assertSame('Civil Code of Québec — Co-ownership', DocumentTitle::stored($stamped));
        $this->assertSame('Civil Code of Québec — Co-ownership', DocumentTitle::of([$stamped]));
        $this->assertNull(DocumentTitle::stored($this->chunk(4, '**1069.** Text.')));
        $this->assertNull(DocumentTitle::stored($this->chunk(4, 'x', [DocumentTitle::METADATA_KEY => ['not', 'a', 'string']])));
        $this->assertNull(DocumentTitle::stored($this->chunk(4, 'x', [DocumentTitle::METADATA_KEY => '   '])));
    }

    /** @test */
    public function a_very_long_heading_is_capped(): void
    {
        $title = DocumentTitle::fromFirstChunk('# ' . str_repeat('long title ', 100));

        $this->assertLessThanOrEqual(DocumentTitle::MAX_CHARS, mb_strlen($title));
        $this->assertStringStartsWith('long title long title', $title);
    }
}
