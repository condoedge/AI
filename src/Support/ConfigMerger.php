<?php
declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * Host config is a thin override of the package defaults: associative arrays merge recursively,
 * lists and domain registries (keyed by host classes/keys) are replaced whole, empty arrays replace.
 */
final class ConfigMerger
{
    /**
     * Top-level registries the host owns entirely — never blend package examples into them.
     */
    public const REPLACE_WHOLE = [
        'entities',
        'entity_actions',
        'generic_actions',
        'attention_sources',
        'calculations',
        'user_entity_paths',
    ];

    public static function merge(array $defaults, array $overrides, int $depth = 0): array
    {
        foreach ($overrides as $key => $value) {
            $replaceWhole = $depth === 0 && in_array($key, self::REPLACE_WHOLE, true);
            if (!$replaceWhole
                && is_array($value) && $value !== [] && !array_is_list($value)
                && isset($defaults[$key]) && is_array($defaults[$key]) && !array_is_list($defaults[$key])) {
                $defaults[$key] = self::merge($defaults[$key], $value, $depth + 1);
                continue;
            }
            $defaults[$key] = $value;
        }
        return $defaults;
    }
}
