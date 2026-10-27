<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * The host's domain: the examples and vocabularies the package's prompts and
 * generators use, configured under `ai.domain` (package defaults in config/ai.php).
 * The only reader of that block.
 */
final class DomainProfile
{
    /** A text slot, e.g. text('understanding.possessive_examples'); '' when unset. */
    public static function text(string $slot): string
    {
        $value = config("ai.domain.{$slot}");

        return is_string($value) ? $value : '';
    }

    /** @return list<string> A list slot's strings; [] when unset or not a list. */
    public static function list(string $slot): array
    {
        $value = config("ai.domain.{$slot}");

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** @return array<string, list<string>> A map slot (word => list of strings); malformed entries are skipped. */
    public static function map(string $slot): array
    {
        $value = config("ai.domain.{$slot}");
        $map = [];
        foreach (is_array($value) ? $value : [] as $key => $items) {
            if (is_string($key) && is_array($items)) {
                $map[$key] = array_values(array_filter($items, 'is_string'));
            }
        }

        return $map;
    }
}
