<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\Support\AliasNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * THE structural "same word, different spelling" test for entity aliases: case,
 * accents, separators and a trailing plural mark. Derivable spellings no longer
 * have to be listed in an entity config (they hid the real synonyms behind the
 * Query-Understanding catalog cap); every alias consumer matches through this.
 */
class AliasNormalizerTest extends TestCase
{
    public function test_case_variants_are_the_same_term(): void
    {
        $this->assertTrue(AliasNormalizer::sameVariant('Access', 'access'));
        $this->assertTrue(AliasNormalizer::sameVariant('ACCÈS', 'accès'));
    }

    public function test_accentless_spellings_are_the_same_term(): void
    {
        $this->assertTrue(AliasNormalizer::sameVariant('accès', 'acces'));
        $this->assertTrue(AliasNormalizer::sameVariant('télécommande', 'telecommande'));
        $this->assertTrue(AliasNormalizer::sameVariant('Bâtiment', 'batiment'));
        $this->assertTrue(AliasNormalizer::sameVariant('reçu', 'recu'));
    }

    public function test_a_decomposed_accent_equals_its_precomposed_form(): void
    {
        // "é" as e + U+0301 (NFD) versus the single code point (NFC).
        $this->assertTrue(AliasNormalizer::sameVariant("e\u{0301}difice", 'édifice'));
    }

    public function test_separator_and_camel_case_spellings_are_the_same_term(): void
    {
        foreach (['visit_detail', 'visit detail', 'visit-detail', 'visitdetail', 'Visit  Detail'] as $spelling) {
            $this->assertTrue(AliasNormalizer::sameVariant('VisitDetail', $spelling), $spelling);
        }
    }

    public function test_a_trailing_plural_mark_is_folded_on_every_token(): void
    {
        $this->assertTrue(AliasNormalizer::sameVariant('fob', 'fobs'));
        $this->assertTrue(AliasNormalizer::sameVariant('VisitDetail', 'visit details'));
        $this->assertTrue(AliasNormalizer::sameVariant('VisitDetail', 'visit_details'));
        $this->assertTrue(AliasNormalizer::sameVariant('rapport de visite', 'rapports de visites'));
        $this->assertTrue(AliasNormalizer::sameVariant('télécommande', 'telecommandes'));
        $this->assertTrue(AliasNormalizer::sameVariant('niveau', 'niveaux'), 'a trailing "x" is a plural mark too');
    }

    public function test_different_words_stay_distinct(): void
    {
        $this->assertFalse(AliasNormalizer::sameVariant('client', 'customer'));
        $this->assertFalse(AliasNormalizer::sameVariant('fob', 'télécommande'));
        $this->assertFalse(AliasNormalizer::sameVariant('parking', 'stationnement'));
        $this->assertFalse(AliasNormalizer::sameVariant('unit', 'unite'), 'no stemming: a different ending is a different word');
        $this->assertFalse(AliasNormalizer::sameVariant('person', 'people'), 'no irregular plurals');
        $this->assertFalse(AliasNormalizer::sameVariant('access', 'accesses'), 'only a single trailing s/x is folded, never "es"');
        $this->assertFalse(AliasNormalizer::sameVariant('travail', 'travaux'));
    }

    public function test_short_tokens_are_never_folded(): void
    {
        // The folded stem must keep at least three letters.
        $this->assertFalse(AliasNormalizer::sameVariant('bus', 'bu'));
        $this->assertFalse(AliasNormalizer::sameVariant('tps', 'tp'));
        $this->assertFalse(AliasNormalizer::sameVariant('tax', 'ta'));
        $this->assertFalse(AliasNormalizer::sameVariant('as', 'a'));
        $this->assertSame('bus', AliasNormalizer::key('Bus'));
        $this->assertSame('lot', AliasNormalizer::key('lots'));
    }

    public function test_a_doubled_final_s_is_not_a_plural_mark(): void
    {
        $this->assertFalse(AliasNormalizer::sameVariant('class', 'clas'));
        $this->assertFalse(AliasNormalizer::sameVariant('access', 'acces'));
        $this->assertSame('address', AliasNormalizer::key('Address'));
    }

    public function test_numbers_and_mixed_tokens_are_never_folded(): void
    {
        $this->assertFalse(AliasNormalizer::sameVariant('unit 2', 'unit 12'));
        $this->assertFalse(AliasNormalizer::sameVariant('a320', 'a320s'), 'a token holding a digit is not a plain word');
        $this->assertFalse(AliasNormalizer::sameVariant('2023', '2024'));
        $this->assertTrue(AliasNormalizer::sameVariant('T4 slip', 't4_slips'));
        $this->assertSame('1099', AliasNormalizer::key('1099'));
    }

    public function test_empty_and_separator_only_terms_never_match_anything(): void
    {
        $this->assertFalse(AliasNormalizer::sameVariant('', ''));
        $this->assertFalse(AliasNormalizer::sameVariant(' - ', '_'));
        $this->assertFalse(AliasNormalizer::sameVariant('', 'unit'));
    }

    public function test_distinct_drops_variants_of_the_label_and_of_an_earlier_alias(): void
    {
        $aliases = [
            'access', 'accesses', 'accès', 'acces', 'clé', 'cle', 'fob', 'télécommande',
            'telecommande', 'clés', 'cles', 'fobs', 'télécommandes', 'telecommandes',
        ];

        $this->assertSame(
            ['accesses', 'accès', 'clé', 'fob', 'télécommande'],
            AliasNormalizer::distinct($aliases, 'Access')
        );
    }

    public function test_distinct_keeps_real_synonyms_untouched_and_in_order(): void
    {
        $this->assertSame(
            ['client', 'customer', 'buyer'],
            AliasNormalizer::distinct(['client', 'customer', 'buyer'], 'Contact')
        );
    }

    public function test_distinct_prefers_the_accented_then_the_spaced_spelling_at_the_first_position(): void
    {
        // The accent-less spelling came first: the accented one takes its place.
        $this->assertSame(
            ['bâtiment', 'immeuble'],
            AliasNormalizer::distinct(['batiment', 'immeuble', 'bâtiment', 'bâtiments'], 'Building')
        );
        // Code spelling (underscore / glued) versus the words a person types.
        $this->assertSame(
            ['unit owner', 'co-owner'],
            AliasNormalizer::distinct(['unit_owner', 'unitowner', 'unit owner', 'co-owner', 'co_owner'], 'Owner')
        );
    }

    public function test_distinct_checks_every_known_label_and_ignores_junk(): void
    {
        $this->assertSame(
            ['apartment'],
            AliasNormalizer::distinct(['lot', 'Units', '', '  ', 42, null, 'apartment', ' apartment '], 'Lot', 'Unit')
        );
        $this->assertSame([], AliasNormalizer::distinct([], 'Lot'));
    }

    public function test_distinct_is_idempotent(): void
    {
        $once = AliasNormalizer::distinct(['visit_detail', 'visit details', 'visit report', 'rapport de visite', 'rapports de visite'], 'VisitDetail');

        $this->assertSame(['visit report', 'rapport de visite'], $once);
        $this->assertSame($once, AliasNormalizer::distinct($once, 'VisitDetail'));
    }

    public function test_a_text_mentions_a_term_in_any_of_its_spellings(): void
    {
        $this->assertTrue(AliasNormalizer::mentions('Show me the visit_details of last week', 'VisitDetail'));
        $this->assertTrue(AliasNormalizer::mentions('show all Visit Details', 'visit detail'));
        $this->assertTrue(AliasNormalizer::mentions('Combien d\'acces actifs?', 'accès'));
        $this->assertTrue(AliasNormalizer::mentions('Liste des ACCÈS', 'acces'));
        $this->assertTrue(AliasNormalizer::mentions('how many fobs (active)?', 'fob'));
        $this->assertTrue(AliasNormalizer::mentions('how many fob are active', 'fobs'));
        $this->assertTrue(AliasNormalizer::mentions('les rapports de visites?', 'rapport de visite'));
        $this->assertTrue(AliasNormalizer::mentions('list the unitowners', 'unit owner'));
    }

    public function test_a_text_does_not_mention_an_unrelated_term(): void
    {
        $this->assertFalse(AliasNormalizer::mentions('show all invoices', 'customer'));
        $this->assertFalse(AliasNormalizer::mentions('show all invoices', ''));
        $this->assertFalse(AliasNormalizer::mentions('', 'invoice'));
        $this->assertFalse(AliasNormalizer::mentions('visit the details page', 'VisitDetail'), 'the words must be adjacent');
    }

    public function test_mentions_keeps_the_loose_substring_match_of_a_listed_term(): void
    {
        // What the alias detection always did (case-insensitive substring), now accent-insensitive too.
        $this->assertTrue(AliasNormalizer::mentions("l'immeuble du 12 rue X", 'immeuble'));
        $this->assertTrue(AliasNormalizer::mentions('How many volunteers do we have?', 'volunteer'));
    }

    public function test_the_letter_table_used_without_ext_intl_folds_the_same_latin_accents(): void
    {
        $fallback = new \ReflectionMethod(AliasNormalizer::class, 'foldLatin');
        $fallback->setAccessible(true);

        $this->assertSame('acces telecommande batiment recu noel', $fallback->invoke(null, 'accès télécommande bâtiment reçu noël'));
        $this->assertSame(AliasNormalizer::fold('Édifice À Côté'), $fallback->invoke(null, mb_strtolower('Édifice À Côté')));
    }

    public function test_phrase_is_the_label_as_words(): void
    {
        $this->assertSame('visit detail', AliasNormalizer::phrase('VisitDetail'));
        $this->assertSame('eft file', AliasNormalizer::phrase('EFTFile'));
        $this->assertSame('board member', AliasNormalizer::phrase('board_member'));
        $this->assertSame('unit', AliasNormalizer::phrase('Unit'));
    }

    public function test_tokens_are_the_folded_words_of_a_text(): void
    {
        $this->assertSame(['les', 'acce', 'des', 'unite', 'visit', 'detail'], AliasNormalizer::tokens("Les accès des unités: VisitDetails?"));
        $this->assertSame([], AliasNormalizer::tokens('  ?! '));
    }
}
