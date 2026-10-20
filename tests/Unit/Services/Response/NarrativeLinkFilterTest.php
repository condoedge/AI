<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\NarrativeLinkFilter;
use PHPUnit\Framework\TestCase;

/**
 * Actions plan B5, enforced in code: the narrator may not write links. Every markdown link
 * the MODEL wrote is reduced to its text, except an external URL quoted verbatim from a
 * cited document passage and a mailto:/tel: whose address / number is in the result rows.
 * The only action links in an answer are the ones code creates afterwards ({{record:N}}).
 */
class NarrativeLinkFilterTest extends TestCase
{
    /** @test */
    public function action_and_entity_links_written_by_the_model_become_plain_text(): void
    {
        $filter = new NarrativeLinkFilter();

        $this->assertSame(
            'Click Confirm or open Unit 310 here.',
            $filter->apply('Click [Confirm](action://approve_reservation?id=4) or open [Unit 310](entity://Unit/159/view) here.')
        );
    }

    /** @test */
    public function external_links_survive_only_when_quoted_from_a_cited_passage(): void
    {
        $filter = new NarrativeLinkFilter(passages: ['See the bylaws at https://condo.example/bylaws.pdf for details.']);

        $this->assertSame(
            'Read [the bylaws](https://condo.example/bylaws.pdf) and verify your account.',
            $filter->apply('Read [the bylaws](https://condo.example/bylaws.pdf) and [verify your account](https://phish.example/login).')
        );
    }

    /** @test */
    public function mailto_and_tel_survive_only_when_the_address_or_number_is_in_the_rows(): void
    {
        $filter = new NarrativeLinkFilter(rows: [['name' => 'Ann', 'email' => 'ann@condo.example', 'phone' => '514-400-7414']]);

        $this->assertSame(
            'Write to [Ann](mailto:ann@condo.example), call [her](tel:+15144007414), not Bob.',
            $filter->apply('Write to [Ann](mailto:ann@condo.example), call [her](tel:+15144007414), not [Bob](mailto:bob@evil.example).')
        );
    }

    /** @test A PII token the masker minted (the real value is a row value) is a valid mailto target. */
    public function a_masked_token_is_a_row_value(): void
    {
        $filter = new NarrativeLinkFilter(tokens: ['[email#1]']);

        $this->assertSame('Write to [her](mailto:[email#1]).', $filter->apply('Write to [her](mailto:[email#1]).'));
    }

    /** @test Images, relative links and other schemes are text too. */
    public function images_relative_and_other_schemes_become_text(): void
    {
        $filter = new NarrativeLinkFilter();

        $this->assertSame('A logo, the page, run.', $filter->apply('A ![logo](https://x.example/l.png), [the page](/units/5), [run](javascript:alert(1)).'));
    }
}
