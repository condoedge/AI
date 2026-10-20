<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\Support\ArticleLocator;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 additions to ArticleLocator: the per-LINE article a chunk boundary starts
 * (with its sub-article number, as stored in chunk metadata) and the article numbers
 * a QUESTION references (exact-article retrieval angle). Structural numeric grammar
 * only: a line-start clause number, or an "art"/"article" numeric-reference marker.
 */
class ArticleLocatorReferenceTest extends TestCase
{
    /** @test */
    public function line_article_reads_the_ccq_bold_marker_with_sub_articles(): void
    {
        $this->assertSame('1069', ArticleLocator::lineArticle('**1069.** A person who acquires a fraction'));
        $this->assertSame('1070.1', ArticleLocator::lineArticle('**1070.1.** It must be possible to consult'));
        $this->assertSame('1064.1', ArticleLocator::lineArticle('1064.1. Each co-owner shall take out'));
        $this->assertSame('12', ArticleLocator::lineArticle('Article 12 — Pets'));
        $this->assertSame('1069', ArticleLocator::lineArticle('### Art. 1069'));
    }

    /** @test */
    public function line_article_ignores_history_lines_lists_and_in_text_mentions(): void
    {
        $this->assertNull(ArticleLocator::lineArticle('*1991, c. 64, a. 1069; 2002, c. 19, s. 6.*'));
        $this->assertNull(ArticleLocator::lineArticle('1. Open Team Settings.'));
        $this->assertNull(ArticleLocator::lineArticle('Notwithstanding articles 2650 and 2662, a hypothec'));
        $this->assertNull(ArticleLocator::lineArticle('I.N. 2015-11-01.'));
        $this->assertNull(ArticleLocator::lineArticle(''));
    }

    /** @test */
    public function a_metadata_range_starting_or_ending_with_a_sub_article_keeps_its_base_numbers(): void
    {
        // Live chunk metadata: "1064.1–1065" was read as 1–1065 (span too wide → dropped).
        $this->assertSame([1064, 1065], ArticleLocator::forReference(['snippet' => '', 'article_range' => '1064.1–1065']));
        $this->assertSame([1072, 1073], ArticleLocator::forReference(['snippet' => '', 'metadata' => ['article_range' => '1072.1–1073']]));
        $this->assertSame([1074], ArticleLocator::forReference(['snippet' => '', 'article_range' => '1074–1074.1']));
        $this->assertSame([1070], ArticleLocator::forReference(['snippet' => '', 'article_range' => '1070.1']));
        $this->assertSame([1009, 1010], ArticleLocator::forReference(['snippet' => '', 'article_range' => '1009–1010']));
    }

    /** @test */
    public function referenced_articles_reads_the_numeric_reference_grammar(): void
    {
        $this->assertSame(['1069'], ArticleLocator::referencedArticles('What does article 1069 say?'));
        $this->assertSame(['1097'], ArticleLocator::referencedArticles("Selon l'art. 1097, qui décide ?"));
        $this->assertSame(['1068', '1069'], ArticleLocator::referencedArticles('Compare arts. 1068, 1069'));
        $this->assertSame(['1070.1'], ArticleLocator::referencedArticles('Explain 1070.1. please'));
        $this->assertSame(['12'], ArticleLocator::referencedArticles('Article 12 of our declaration'));
    }

    /**
     * @test A list of numbers after one marker is separated by PUNCTUATION — comma,
     * semicolon, ampersand, slash. Live (2026-10-06): an answer citing "(art. 1064; 1068)"
     * recorded 1064 only, so the next turn's deepening contract called 1068 new.
     */
    public function a_list_after_one_marker_is_read_whatever_punctuation_separates_it(): void
    {
        $this->assertSame(['1064', '1068'], ArticleLocator::referencedArticles('You contribute to common expenses (art. 1064; 1068) [1].'));
        $this->assertSame(['1064', '1068', '1069'], ArticleLocator::referencedArticles('See arts. 1064 ; 1068, 1069.'));
        $this->assertSame(['1064.1', '1068'], ArticleLocator::referencedArticles('art. 1064.1; 1068'));
        $this->assertSame(['1064', '1068'], ArticleLocator::referencedArticles('articles 1064 & 1068'));
        $this->assertSame(['1064', '1068'], ArticleLocator::referencedArticles('art. 1064/1068'));
        // Two markers, each with its own list.
        $this->assertSame(['1064', '1068', '1071'], ArticleLocator::referencedArticles('art. 1064; 1068 and art. 1071'));
    }

    /**
     * @test A RANGE names every article between its bounds — "art. 1063–1066" cites four
     * articles, not two. Structural: a dash between two whole numbers, ascending, and
     * short; anything else keeps the numbers written.
     */
    public function a_short_ascending_range_names_every_article_between_its_bounds(): void
    {
        $this->assertSame(['1063', '1064', '1065', '1066'], ArticleLocator::referencedArticles('Your rights are set out in art. 1063–1066 [1].'));
        $this->assertSame(['1063', '1064', '1065'], ArticleLocator::referencedArticles('articles 1063-1065'));
        $this->assertSame(['1015', '1063', '1064', '1065', '1071'], ArticleLocator::referencedArticles('art. 1015, 1063–1065; 1071'));
        $this->assertSame(['5', '6', '7'], ArticleLocator::referencedArticles('Articles 5 — 7 of the by-laws'));

        // Not a range: descending, a sub-article bound, or too wide to be a citation.
        $this->assertSame(['1070', '1'], ArticleLocator::referencedArticles('art. 1070-1'));
        $this->assertSame(['1064.1', '1066'], ArticleLocator::referencedArticles('art. 1064.1–1066'));
        $this->assertSame(['1038', '1109'], ArticleLocator::referencedArticles('art. 1038–1109'));
    }

    /** @test Words are never separators: what follows one is not part of the list. */
    public function a_word_ends_the_list(): void
    {
        $this->assertSame(['1064'], ArticleLocator::referencedArticles('articles 1064 and 1068'));
        $this->assertSame(['1064'], ArticleLocator::referencedArticles('les articles 1064 et 1068'));
        $this->assertSame(['1064'], ArticleLocator::referencedArticles('art. 1064 in 2019'));
        $this->assertSame(['1064'], ArticleLocator::referencedArticles('art. 1064; within 15 days'));
    }

    /** @test */
    public function referenced_articles_is_empty_without_a_numeric_reference(): void
    {
        $this->assertSame([], ArticleLocator::referencedArticles("If I buy a unit, am I liable for the previous owner's unpaid fees?"));
        $this->assertSame([], ArticleLocator::referencedArticles('I have 1069 dollars of arrears'));
        $this->assertSame([], ArticleLocator::referencedArticles('Tell me about the artist 1069 collection'));
    }
}
