<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\Support\ArticleLocator;
use PHPUnit\Framework\TestCase;

/**
 * ArticleLocator — the ONE parser of the articles a passage DEFINES (line-start
 * clause numbers / "Article N" headings), shared by the response prompt's passage
 * labels, the AnswerValidator's citation-move target check and Phase 7 chunking.
 */
class ArticleLocatorTest extends TestCase
{
    /** @test */
    public function it_reads_line_start_clause_numbers_in_the_live_ccq_format(): void
    {
        $snippet = "**1068.** The register, see article 2650.\n**1069.** The buyer is bound.\n1070.1. Consult.\n"
            . "*1991, c. 64, a. 1069; 2002, c. 19, s. 6.*";

        // In-text mentions (article 2650) and history lines (a. 1069) are not definitions.
        $this->assertSame([1068, 1069, 1070], ArticleLocator::definedArticles($snippet));
    }

    /** @test */
    public function it_reads_article_headings_and_ignores_short_numbered_lists(): void
    {
        $this->assertSame([12], ArticleLocator::definedArticles("## Article 12 — Pets\nNo pets."));
        $this->assertSame([], ArticleLocator::definedArticles("1. Click here.\n2. Save."));
    }

    /** @test */
    public function a_reference_falls_back_to_chunk_metadata_for_continuation_chunks(): void
    {
        // Phase 7 chunk metadata: article_no / article_range.
        $this->assertSame([1069], ArticleLocator::forReference(['snippet' => '…with interest.', 'article_no' => '1069']));
        $this->assertSame([1068, 1069, 1070], ArticleLocator::forReference(['snippet' => 'cont.', 'metadata' => ['article_range' => '1068-1070']]));
        $this->assertSame([1068, 1069, 1070, 1071, 1072], ArticleLocator::forReference(['snippet' => 'cont.', 'article_range' => [1068, 1072]]));
        $this->assertSame([1068, 1069], ArticleLocator::forReference(['snippet' => 'cont.', 'article_range' => ['from' => '1068', 'to' => '1069']]));
        $this->assertSame([], ArticleLocator::forReference(['snippet' => 'cont.']));
        // The snippet's own definitions win over metadata.
        $this->assertSame([1097], ArticleLocator::forReference(['snippet' => '1097. Three-quarters.', 'article_no' => 1096]));
    }
}
