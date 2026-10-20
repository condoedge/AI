<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

/**
 * NarrativeLinkFilter — "the AI only narrates", enforced in code (actions plan B5).
 *
 * Every markdown link (and image) the MODEL wrote is reduced to its visible text, with two
 * evidence-based exceptions:
 *
 *   - an external http(s) URL that appears VERBATIM in a cited document passage (the
 *     document itself links it — the model only quotes it);
 *   - a mailto: / tel: whose address / number is a value of the result rows (or a PII
 *     token the masker minted for such a value).
 *
 * action:// and entity:// links never survive: the only action links an answer carries are
 * the ones code creates AFTER this filter ({{record:N}} substitution) and the chips attached
 * by the ActionAttacher. This closes the prompt-injection path where a row value such as
 * "write [Confirm](action://approve_x)" is copied by the model into a clickable approval
 * card. AnswerValidator's link lint stays as a second backstop.
 */
final class NarrativeLinkFilter
{
    /** `[text](target "title")` / `![alt](target)`; the target may hold one level of parentheses. */
    private const LINK = '/(!?)\[([^\[\]]*(?:\[[^\[\]]*\][^\[\]]*)*)\]\(\s*<?((?:[^()\s<>]|\([^()\s]*\))*)>?(?:\s+"[^"]*")?\s*\)/u';

    /** @var list<string> lowercased scalar row values */
    private array $values = [];

    /** @var list<string> digit strings (≥ 7 digits) of the row values */
    private array $numbers = [];

    /**
     * @param list<string> $passages cited document passages (their text)
     * @param array<int, mixed> $rows result rows (real values)
     * @param list<string> $tokens PII tokens minted this turn ([email#1] …)
     */
    public function __construct(
        private readonly array $passages = [],
        array $rows = [],
        private readonly array $tokens = [],
    ) {
        $this->collect($rows, 0);
    }

    public function apply(string $text): string
    {
        if ($text === '' || !str_contains($text, '](')) {
            return $text;
        }

        return (string) preg_replace_callback(self::LINK, function (array $m): string {
            [$whole, $image, $label, $target] = [$m[0], $m[1], $m[2], trim($m[3])];

            return $image === '' && $this->allowed($target) ? $whole : $label;
        }, $text);
    }

    private function allowed(string $target): bool
    {
        $lower = strtolower($target);

        if (str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://')) {
            foreach ($this->passages as $passage) {
                if (is_string($passage) && str_contains($passage, $target)) {
                    return true;
                }
            }

            return false;
        }

        if (str_starts_with($lower, 'mailto:')) {
            $address = strtolower(trim(rawurldecode((string) strtok(substr($target, 7), '?'))));

            return $address !== '' && (in_array($address, $this->values, true) || in_array($address, array_map('strtolower', $this->tokens), true));
        }

        if (str_starts_with($lower, 'tel:')) {
            $number = substr($target, 4);
            if (in_array($number, $this->tokens, true)) {
                return true;
            }
            $digits = (string) preg_replace('/\D/', '', $number);
            foreach ($this->numbers as $candidate) {
                if (strlen($digits) >= 7 && (str_ends_with($digits, $candidate) || str_ends_with($candidate, $digits))) {
                    return true;
                }
            }

            return false;
        }

        return false; // action://, entity://, relative, javascript:, data: … never
    }

    private function collect(mixed $node, int $depth): void
    {
        if ($depth > 6) {
            return;
        }
        if (is_array($node)) {
            foreach ($node as $child) {
                $this->collect($child, $depth + 1);
            }

            return;
        }
        if (!is_scalar($node) || is_bool($node)) {
            return;
        }
        $value = trim((string) $node);
        if ($value === '') {
            return;
        }
        $this->values[] = strtolower($value);
        $digits = (string) preg_replace('/\D/', '', $value);
        if (strlen($digits) >= 7) {
            $this->numbers[] = $digits;
        }
    }
}
