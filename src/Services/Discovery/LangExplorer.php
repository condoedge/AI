<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Illuminate\Support\Facades\File;

/**
 * Extracts bilingual UI translations relevant to an entity.
 *
 * Scans Kompo form code for translation key patterns, then loads
 * the corresponding PHP and JSON lang files to return matched
 * translations in both EN and FR.
 */
class LangExplorer
{
    /**
     * Extract relevant translations from lang files based on Kompo form code.
     *
     * @param array $kompoForms Forms from CodeExplorer [['path' => ..., 'content' => ...], ...]
     * @param string $basePath Project base path
     * @return array ['module.key' => ['en' => 'English', 'fr' => 'French'], ...]
     */
    public function explore(array $kompoForms, string $basePath): array
    {
        // Step 1: Extract translation keys from form code
        $keys = $this->extractTranslationKeys($kompoForms);

        if (empty($keys)) {
            return [];
        }

        // Step 2: Group keys by module
        $moduleKeys = $this->groupByModule($keys);

        // Step 3: Load translations from PHP files and JSON files
        $translations = [];

        foreach ($moduleKeys as $module => $keyList) {
            $this->loadPhpTranslations($translations, $module, $keyList, $basePath);
        }

        // Also load from JSON files for keys that weren't found in PHP files
        $this->loadJsonTranslations($translations, $keys, $basePath);

        return $translations;
    }

    /**
     * Extract translation keys from Kompo form code.
     * Looks for patterns like _Input('module.key'), __('module.key'), ->placeholder('key'), etc.
     */
    private function extractTranslationKeys(array $kompoForms): array
    {
        $keys = [];

        foreach ($kompoForms as $form) {
            $content = $form['content'] ?? '';

            // Pattern: _Input('key'), _Select('key'), _Textarea('key'), _PageTitle('key'), _Html('key')
            // Pattern: __('key'), trans('key')
            // Pattern: ->placeholder('key'), ->alert('key'), ->comment('key')
            preg_match_all(
                '/(?:_Input|_Select|_Textarea|_PageTitle|_Html|_DateRange|_Checkbox|_Toggle|__|trans|->placeholder|->alert|->comment)\s*\(\s*[\'"]([a-zA-Z0-9_.:-]+)[\'"]\s*\)/',
                $content,
                $matches
            );

            if (!empty($matches[1])) {
                $keys = array_merge($keys, $matches[1]);
            }
        }

        return array_unique($keys);
    }

    /**
     * Group keys by their module prefix.
     * 'finance.manage-service' → module 'finance', key 'manage-service'
     * Keys without a dot go into a '_json' group (found in JSON files).
     */
    private function groupByModule(array $keys): array
    {
        $grouped = [];

        foreach ($keys as $key) {
            if (str_contains($key, '.')) {
                $parts = explode('.', $key, 2);
                $grouped[$parts[0]][] = $parts[1];
            } else {
                $grouped['_json'][] = $key;
            }
        }

        return $grouped;
    }

    /**
     * Load translations from PHP lang files for a specific module.
     */
    private function loadPhpTranslations(array &$translations, string $module, array $keyList, string $basePath): void
    {
        if ($module === '_json') {
            return; // JSON keys handled separately
        }

        $enFile = $basePath . "/resources/lang/en/{$module}.php";
        $frFile = $basePath . "/resources/lang/fr/{$module}.php";

        $enData = File::exists($enFile) ? (include $enFile) : [];
        $frData = File::exists($frFile) ? (include $frFile) : [];

        if (!is_array($enData)) $enData = [];
        if (!is_array($frData)) $frData = [];

        foreach ($keyList as $key) {
            $fullKey = "{$module}.{$key}";
            $en = $enData[$key] ?? null;
            $fr = $frData[$key] ?? null;

            if ($en || $fr) {
                $translations[$fullKey] = [
                    'en' => $en ?? $fullKey,
                    'fr' => $fr ?? $fullKey,
                ];
            }
        }
    }

    /**
     * Load translations from JSON lang files.
     * Handles both dotted keys and plain keys.
     */
    private function loadJsonTranslations(array &$translations, array $allKeys, string $basePath): void
    {
        $enJson = $basePath . '/resources/lang/en.json';
        $frJson = $basePath . '/resources/lang/fr.json';

        $enData = File::exists($enJson) ? json_decode(File::get($enJson), true) : [];
        $frData = File::exists($frJson) ? json_decode(File::get($frJson), true) : [];

        if (!is_array($enData)) $enData = [];
        if (!is_array($frData)) $frData = [];

        foreach ($allKeys as $key) {
            // Skip if already found in PHP files
            if (isset($translations[$key])) {
                continue;
            }

            $en = $enData[$key] ?? null;
            $fr = $frData[$key] ?? null;

            if ($en || $fr) {
                $translations[$key] = [
                    'en' => $en ?? $key,
                    'fr' => $fr ?? $key,
                ];
            }
        }
    }

    /**
     * Format translations for inclusion in an LLM prompt.
     */
    public function formatForPrompt(array $translations): string
    {
        if (empty($translations)) {
            return '';
        }

        $lines = [];
        foreach ($translations as $key => $values) {
            $en = $values['en'] ?? $key;
            $fr = $values['fr'] ?? $key;
            $lines[] = "- {$key}: EN=\"{$en}\" | FR=\"{$fr}\"";
        }

        return implode("\n", $lines);
    }
}
