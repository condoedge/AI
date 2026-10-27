<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\Support\Frontmatter;
use Condoedge\Ai\Tests\TestCase;

/**
 * A markdown document's YAML frontmatter is data about the document, not its text:
 * read into fields, removed from the body.
 */
class FrontmatterTest extends TestCase
{
    private const DOC = "---\ntitle: Pay a membership\naudience: buyer\npages: [campaign.public, payment.success]\n"
        . "breadcrumb: Campaign page > Contribute\nkeywords: [membership, renewal]\nrelated: [pay-an-order]\n---\n\n# Pay a membership\n\nBody.";

    /** @test */
    public function the_block_is_read_and_removed_from_the_body(): void
    {
        [$fields, $body] = Frontmatter::split(self::DOC);

        $this->assertSame('Pay a membership', $fields['title']);
        $this->assertSame(['campaign.public', 'payment.success'], $fields['pages']);
        $this->assertSame("# Pay a membership\n\nBody.", $body);
    }

    /** @test */
    public function a_document_without_a_block_is_returned_unchanged(): void
    {
        $text = "# Guide\n\n---\n\nA rule, not a frontmatter.";

        $this->assertSame([[], $text], Frontmatter::split($text));
    }

    /** @test */
    public function an_unquoted_value_with_a_colon_falls_back_to_the_line_reader(): void
    {
        [$fields, $body] = Frontmatter::split("---\ntitle: Taxes: the basics\npages: [a.b, 'c.d']\n---\nText");

        $this->assertSame('Taxes: the basics', $fields['title']);
        $this->assertSame(['a.b', 'c.d'], $fields['pages']);
        $this->assertSame('Text', $body);
    }

    /** @test */
    public function the_metadata_uses_the_package_names_through_the_hosts_key_map(): void
    {
        config(['ai.file_context.frontmatter.keys' => ['title' => 'titre', 'audience' => 'public', 'breadcrumb' => 'chemin', 'keywords' => 'mots_cles', 'related' => 'voir_aussi']]);
        $fields = ['titre' => 'Payer', 'public' => 'acheteur', 'pages' => ['campaign.public'], 'chemin' => 'A > B', 'mots_cles' => ['x'], 'voir_aussi' => ['y']];

        $this->assertSame([
            'title' => 'Payer',
            'audience' => ['acheteur'],
            'pages' => ['campaign.public'],
            'breadcrumb' => 'A > B',
            'keywords' => ['x'],
            'related' => ['y'],
            'slug' => 'achat-adhesion',
        ], Frontmatter::metadata($fields, 'acheteur/achat-adhesion.md'));
    }

    /** @test */
    public function no_fields_means_no_metadata(): void
    {
        $this->assertSame([], Frontmatter::metadata([], 'guide.md'));
    }
}
