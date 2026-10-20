<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

/**
 * HostText — text the HOST wrote (an entity's display name, a legend label, a humanized
 * column header) said in the answer's language.
 *
 * The package holds no vocabulary: the text itself is the translation key, resolved through
 * the host's translator (Laravel JSON lines — the mechanism of legend labels
 * ({@see CodedColumns}) and action labels). Eval p18: a French roster headed
 * "Name / Email / Role"; "Showing 25 of 523 rows" where the host has a word for the records.
 *
 * Two readings, by what the text is used for:
 *
 *   - {@see header()} — a LABEL (a table header): the host's line when it ships one, else
 *     the text as it is. A header is never dropped for lack of a translation;
 *   - {@see inLanguage()} — a NOUN put inside a code-written sentence: the host's line, else
 *     the declared text itself when the answer is in the language host config is written in
 *     (ai.response_generation.property_header_language), else NULL — "Affichage de 25 members
 *     sur 523" is worse than the neutral line, so the caller keeps its neutral wording.
 *
 * Language CODES are compared (primary subtag), never words. No locale = the app locale (the
 * package's language policy).
 */
final class HostText
{
    /**
     * The host's own line for $text in $locale — null when it ships none.
     *
     * A regional code falls back to its primary language. A word that happens to name a PHP
     * translation GROUP (`lang/fr/contact.php`) makes the translator return that whole array:
     * never a line.
     */
    public static function line(string $text, ?string $locale): ?string
    {
        $text = trim($text);
        if ($text === '' || !function_exists('app')) {
            return null;
        }

        try {
            $translator = app('translator');
            foreach (self::candidates($locale) as $candidate) {
                if (!$translator->hasForLocale($text, $candidate)) {
                    continue;
                }
                $line = $translator->get($text, [], $candidate, false);
                if (is_string($line) && trim($line) !== '') {
                    return trim($line);
                }
            }
        } catch (\Throwable $e) {
            // no translator — nothing is known
        }

        return null;
    }

    /** Lang-key prefix of the host's DEDICATED lines for the AI's column headers ("ai.header.Bm Role"). */
    public const HEADER_KEY_PREFIX = 'ai.header.';

    /**
     * A humanized column HEADER as an answer in $locale heads it; $header unchanged when the
     * host has nothing to say about it.
     *
     *   1. the host's DEDICATED line for it (`ai.header.<Header>`), in any language — the
     *      override for a property name that is no word ("Bm Role" → "Board role"), or for a
     *      word its UI lines use for something else;
     *   2. else, in an answer in ANOTHER language than the schema's, the host's own line for
     *      that word ("Name" → "Nom"): the header itself is the key, as for a legend label;
     *   3. else the header as it is. In the schema's language it already IS a word of that
     *      language, so the host's UI lines are not consulted there: "Priority" must not
     *      become the label of some checkbox ("High-priority?").
     */
    public static function header(string $header, ?string $locale): string
    {
        $dedicated = self::line(self::HEADER_KEY_PREFIX . trim($header), $locale);
        if ($dedicated !== null) {
            return $dedicated;
        }

        $target = self::primary(self::candidates($locale)[0] ?? null);
        if ($target === null || $target === self::schemaLanguage()) {
            return $header;
        }

        return self::line($header, $locale) ?? $header;
    }

    /**
     * A host-declared noun as a sentence in $locale can say it, or null when it cannot.
     *
     * $declared is the entity config's value: a string (the text in the schema language,
     * translated through the host's lines) or a map language code => text (declared per
     * language in the config itself — for a word the host's lang files already use as a key
     * for something else).
     *
     * @param string|array<string, mixed>|mixed $declared
     */
    public static function inLanguage(mixed $declared, ?string $locale): ?string
    {
        $target = self::primary(self::candidates($locale)[0] ?? null);

        if (is_array($declared)) {
            foreach ($declared as $code => $text) {
                if (is_string($code) && is_string($text) && trim($text) !== '' && self::primary($code) === $target && $target !== null) {
                    return trim($text);
                }
            }

            return null;
        }

        if (!is_string($declared) || trim($declared) === '') {
            return null;
        }
        $declared = trim($declared);

        return self::line($declared, $locale)
            ?? ($target !== null && $target === self::schemaLanguage() ? $declared : null);
    }

    /**
     * The language host config text is written in (the same setting the property headers
     * use), NOT the app's fallback locale: CondoEdge falls back to French while its entity
     * config is English (eval p18: English answers lost the record kind).
     */
    public static function schemaLanguage(): ?string
    {
        try {
            $schema = function_exists('config') ? config('ai.response_generation.property_header_language', 'en') : 'en';
            if (!is_string($schema) || trim($schema) === '') {
                $schema = function_exists('config') ? config('app.fallback_locale', 'en') : 'en';
            }
        } catch (\Throwable $e) {
            $schema = 'en';
        }

        return self::primary($schema);
    }

    /**
     * The locales to look a line up in: $locale (else the app locale), then its primary
     * language when it is a regional code.
     *
     * @return list<string>
     */
    private static function candidates(?string $locale): array
    {
        if ($locale === null || trim($locale) === '') {
            try {
                $locale = function_exists('app') ? (string) app()->getLocale() : '';
            } catch (\Throwable $e) {
                $locale = '';
            }
        }
        $locale = trim((string) $locale);
        if ($locale === '') {
            return [];
        }

        $primary = self::primary($locale);

        return $primary !== null && $primary !== $locale ? [$locale, $primary] : [$locale];
    }

    /** "fr-CA" / "FR_ca" → "fr"; null for a non-string or blank code. */
    private static function primary(mixed $code): ?string
    {
        if (!is_string($code) || trim($code) === '') {
            return null;
        }
        $primary = strtolower((string) (preg_split('/[-_]/', trim($code))[0] ?? ''));

        return $primary === '' ? null : $primary;
    }
}
