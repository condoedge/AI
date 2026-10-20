<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\UI;

use Condoedge\Ai\Services\UI\SafeMarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * P-6 (Phase 8-A review): the Kompo bubble renders through SafeMarkdownRenderer, which
 * had no table support — the code-rendered 25-row table showed as raw pipes. Pipe
 * tables now become <table> (escape first, as before; links keep the scheme
 * allow-list). P-5 companion: backslash escapes (\[ \] \( \) \* \_ \` \|) render as
 * the literal character, never as markdown.
 */
class SafeMarkdownRendererTest extends TestCase
{
    private function render(string $markdown): string
    {
        return (new SafeMarkdownRenderer())->render($markdown);
    }

    /** @test */
    public function a_pipe_table_renders_as_an_html_table_with_head_and_body(): void
    {
        $html = $this->render("You owe 11,493.17.\n\n| Supplier | Total Payable |\n| --- | --- |\n| Hydro-Québec | 819.82 |\n| Groupe Arken | 574.88 |\n\nAnything else?");

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead><tr><th', $html);
        $this->assertMatchesRegularExpression('/<th[^>]*>Supplier<\/th>/', $html);
        $this->assertMatchesRegularExpression('/<td[^>]*>Hydro-Québec<\/td><td[^>]*>819\.82<\/td>/', $html);
        $this->assertSame(1, substr_count($html, '<tbody>'));
        $this->assertSame(2, substr_count($html, '<tr><td'));
        $this->assertStringNotContainsString('| ---', $html);
        $this->assertStringNotContainsString('<table><br', $html, 'no <br> injected inside the table');
        $this->assertStringContainsString('You owe 11,493.17.', $html);
        $this->assertStringContainsString('Anything else?', $html);
    }

    /** @test */
    public function cell_content_is_escaped_and_escaped_markdown_stays_literal(): void
    {
        $html = $this->render("| Note | Email |\n| --- | --- |\n| \\[Verify\\]\\(https://phish.example\\) \\[3\\] <script>x</script> a \\| b | service\\_comptable@novo.ca |");

        $this->assertStringNotContainsString('<a ', $html, 'escaped link syntax never becomes a link');
        $this->assertStringNotContainsString('<sup', $html, 'escaped [3] never becomes a citation');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('[Verify](https://phish.example) [3] &lt;script&gt;x&lt;/script&gt; a | b', $html);
        $this->assertStringContainsString('service_comptable@novo.ca', $html);
    }

    /** @test */
    public function links_inside_cells_keep_the_scheme_allow_list(): void
    {
        $html = $this->render("| Link |\n| --- |\n| [ok](https://condoedge.test) |\n| [bad](javascript:alert(1)) |");

        $this->assertStringContainsString('<a href="https://condoedge.test"', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
    }

    /** @test Review #3: inline code inside a table cell is restored. */
    public function inline_code_inside_a_table_cell_is_restored(): void
    {
        $html = $this->render("| h<b>\\|x | `\"><svg onload=1>` |\n| --- | --- |\n| [q](http://a\"onmouseover=\"x) | **<i>** |");

        $this->assertStringNotContainsString('___INLINECODE', $html);
        $this->assertStringNotContainsString('___', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;svg onload=1&gt;</code>', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('onmouseover="', $html);
    }

    /**
     * @test Review #4: nothing is ever substituted inside a generated href; URLs carrying a
     * placeholder, whitespace or a protocol-relative // are rendered as plain text.
     * @dataProvider hrefInjections
     */
    public function no_placeholder_or_citation_markup_lands_inside_an_href(string $markdown): void
    {
        $html = (new SafeMarkdownRenderer(['activeBadge' => 'b', 'primaryText' => 't']))->render($markdown);

        foreach ((preg_match_all('/href="([^"]*)"/', $html, $m) ? $m[1] : []) as $href) {
            $this->assertStringNotContainsString('<', html_entity_decode($href), "markup inside href: {$href}");
            $this->assertStringNotContainsString('___', $href);
            $this->assertDoesNotMatchRegularExpression('/\s/', html_entity_decode($href), "whitespace inside href: {$href}");
            $fully = $href;
            for ($i = 0; $i < 4; $i++) {
                $fully = html_entity_decode($fully, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $this->assertDoesNotMatchRegularExpression('/^\s*javascript/i', $fully, "entity-encoded scheme: {$href}");
        }
        $this->assertStringNotContainsString('href="//', $html);
        $this->assertStringNotContainsString('<code b', strstr($html, 'href=') ?: '', 'no code markup after an href on the same anchor');
    }

    public static function hrefInjections(): array
    {
        return [
            'inline code in url' => ['[x](http://`a` onmouseover=alert(1) )'],
            'citation in url' => ['[x](http://e.com/[1] onmouseover=alert(1))'],
            'protocol relative' => ['[x](//evil.com) [y](java&#115;cript:alert(1)) [z](&#106;avascript:alert(1)) [w](javascript&colon;alert(1))'],
        ];
    }

    /** @test Review #4: the reviewer's probe inputs render safely end to end. */
    public function the_reviewer_probe_inputs_render_safely(): void
    {
        $renderer = new SafeMarkdownRenderer(['activeBadge' => 'b', 'primaryText' => 't']);

        $table = $renderer->render("| a | b |\n|---|---|\n| <script>alert(1)</script> | [x](javascript:alert(1)) |\n| \"><img src=x onerror=alert(1)> | [y](JaVaScRiPt:alert(1)) |");
        $this->assertStringNotContainsString('<script', $table);
        $this->assertStringNotContainsString('<img', $table);
        $this->assertStringNotContainsString('href="javascript', strtolower($table));

        $literal = $renderer->render('lit ___ESC0___ ___TABLE0___ ___INLINECODE_0___ \* `c`');
        $this->assertStringContainsString('lit ___ESC0___ ___TABLE0___ ___INLINECODE_0___ *', $literal, 'user-typed placeholder look-alikes stay literal text');

        $this->assertStringContainsString('<a href="https://ok.example"', $renderer->render('[ok](https://ok.example)'));
    }

    /** @test */
    public function text_without_a_separator_row_is_not_a_table(): void
    {
        $html = $this->render("a | b\nc | d");

        $this->assertStringNotContainsString('<table', $html);
    }
}
