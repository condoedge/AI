<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\HostText;
use Condoedge\Ai\Tests\TestCase;

/**
 * HostText — host-written text (an entity's display name, a humanized column header) said in
 * the answer's language through the HOST's translator (eval p18: French answers with English
 * table headers; "rows" / "records" where the host has a noun).
 *
 * The text itself is the translation key (Laravel JSON lines), as for a legend label or an
 * action label. No vocabulary lives in the package.
 */
class HostTextTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // The host's dedicated lines for the AI's column headers (flat JSON keys).
        $this->hostJsonLines([
            'en' => ['ai.header.Bm Role' => 'Board role'],
            'fr' => ['ai.header.Priority' => 'Priorité'],
        ]);
        // (Load the JSON lines first: addLines() on a locale not loaded yet would mark it
        // loaded and hide them.)
        app('translator')->get('ai.response.table_showing_of', [], 'fr');
        app('translator')->get('ai.response.table_showing_of', [], 'en');
        app('translator')->addLines([
            '*.Name' => 'Nom',
            '*.Email' => 'Courriel',
            '*.contacts' => 'contacts',
            '*.members' => 'membres',
        ], 'fr');
        // The host's UI happens to hold a line for these words — a checkbox label, a greeting.
        app('translator')->addLines(['*.Priority' => 'High-priority?', '*.Phone' => 'Telephone'], 'en');
        app('translator')->addLines(['*.Priority' => 'Prioritaire?'], 'fr');
        config(['ai.response_generation.property_header_language' => 'en', 'app.fallback_locale' => 'fr']);
    }

    /**
     * Host lang JSON lines, loaded as a host ships them (`lang/<locale>.json`): a DOTTED key
     * ("ai.header.Bm Role") is one flat JSON key — addLines() would nest it.
     *
     * @param array<string, array<string, string>> $byLocale locale => [key => line]
     */
    private function hostJsonLines(array $byLocale): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ai-host-lang-' . bin2hex(random_bytes(6));
        mkdir($dir);
        foreach ($byLocale as $locale => $lines) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . $locale . '.json', json_encode($lines, JSON_UNESCAPED_UNICODE));
        }
        $this->beforeApplicationDestroyed(static function () use ($dir): void {
            array_map('unlink', glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: []);
            @rmdir($dir);
        });

        app('translator')->addJsonPath($dir);
        app('translator')->setLoaded([]); // locales loaded before the path was added are read again
    }

    /** @test */
    public function a_header_is_translated_when_the_host_ships_a_line_and_kept_otherwise(): void
    {
        $this->assertSame('Nom', HostText::header('Name', 'fr'));
        $this->assertSame('Courriel', HostText::header('Email', 'fr'));
        $this->assertSame('Last Login', HostText::header('Last Login', 'fr'), 'no line: the header stands');
        $this->assertSame('Name', HostText::header('Name', 'en'));
    }

    /**
     * @test A header is ALREADY a word of the schema's language: in an answer in that language
     * the host's UI lines are not consulted — "Priority" must not become the label of some
     * checkbox ("High-priority?"), nor "Phone" whatever the UI calls its field.
     */
    public function in_the_schema_language_ui_lines_never_rename_a_header(): void
    {
        $this->assertSame('Priority', HostText::header('Priority', 'en'));
        $this->assertSame('Phone', HostText::header('Phone', 'en'));
        $this->assertSame('Priority', HostText::header('Priority', 'en-CA'));
    }

    /**
     * @test The host's DEDICATED line for an AI column header (`ai.header.<Header>`) applies in
     * every language and wins over a UI line: the override for a property name that is no word
     * ("Bm Role"), or for a word the UI uses for something else.
     */
    public function a_dedicated_header_line_applies_in_every_language_and_wins(): void
    {
        $this->assertSame('Board role', HostText::header('Bm Role', 'en'));
        $this->assertSame('Priorité', HostText::header('Priority', 'fr'), 'not the checkbox label "Prioritaire?"');
        $this->assertSame('Bm Role', HostText::header('Bm Role', 'fr'), 'no French line of either kind: the header stands');
    }

    /** @test A regional code falls back to its primary language ("fr-CA" → fr). */
    public function a_regional_locale_uses_its_primary_language_lines(): void
    {
        $this->assertSame('Nom', HostText::header('Name', 'fr-CA'));
        $this->assertSame('Nom', HostText::header('Name', 'fr_CA'));
    }

    /** @test No locale = the app locale (the package's language policy). */
    public function without_a_locale_the_app_locale_applies(): void
    {
        app()->setLocale('fr');
        $this->assertSame('Nom', HostText::header('Name', null));

        app()->setLocale('en');
        $this->assertSame('Name', HostText::header('Name', null));
    }

    /**
     * @test A word that is also the name of a PHP lang GROUP (`lang/fr/contact.php`) makes the
     * translator return the whole group array — never a line.
     */
    public function a_translation_group_is_never_a_line(): void
    {
        app('translator')->addLines(['Supplier.title' => 'Fournisseurs', 'Supplier.add' => 'Ajouter'], 'fr');

        $this->assertNull(HostText::line('Supplier', 'fr'));
        $this->assertSame('Supplier', HostText::header('Supplier', 'fr'));
    }

    /**
     * @test A NOUN must be sayable in the answer's language: the host's line for it, else the
     * declared text itself when the answer is in the language host config is written in —
     * never an English noun inside a French sentence.
     */
    public function a_noun_is_said_in_the_answer_language_or_not_at_all(): void
    {
        $this->assertSame('membres', HostText::inLanguage('members', 'fr'));
        $this->assertSame('members', HostText::inLanguage('members', 'en'), 'the schema language needs no line');
        $this->assertNull(HostText::inLanguage('units', 'fr'), 'no French line: it cannot be said');
        $this->assertNull(HostText::inLanguage('', 'en'));
    }

    /** @test A word spelled the same in both languages IS translated when the host says so. */
    public function an_identical_translation_counts_as_a_translation(): void
    {
        $this->assertSame('contacts', HostText::inLanguage('contacts', 'fr'));
    }

    /**
     * @test A noun may be declared per language in the entity config itself
     * (`['en' => 'meetings', 'fr' => 'réunions']`) — for a word the host's lang files already
     * use as a key for something else.
     */
    public function a_noun_declared_per_language_is_read_directly(): void
    {
        $declared = ['en' => 'meetings', 'fr' => 'réunions'];

        $this->assertSame('réunions', HostText::inLanguage($declared, 'fr'));
        $this->assertSame('réunions', HostText::inLanguage($declared, 'fr-CA'));
        $this->assertSame('meetings', HostText::inLanguage($declared, 'en'));
        $this->assertNull(HostText::inLanguage($declared, 'es'));
        $this->assertNull(HostText::inLanguage(['fr' => ''], 'fr'));
    }
}
