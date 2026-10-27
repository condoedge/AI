<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * A markdown document's YAML frontmatter (the `---` block that opens it): data about
 * the document, never its text. FileProcessor removes it before chunking and stamps
 * what it says on every chunk.
 *
 * The package reads seven fields — title, audience, pages, breadcrumb, keywords,
 * related, slug — under the names the host writes them
 * (ai.file_context.frontmatter.keys, e.g. 'title' => 'titre').
 */
final class Frontmatter
{
    /** The package's field names, in payload order. */
    private const FIELDS = ['title', 'audience', 'pages', 'breadcrumb', 'keywords', 'related', 'slug'];

    /** These always read as a list of strings. */
    private const LIST_FIELDS = ['audience', 'pages', 'keywords', 'related'];

    /**
     * @return array{0: array<string, mixed>, 1: string} The block's fields and the text
     *         without it; [] and the text unchanged when the text opens on no block.
     */
    public static function split(string $text): array
    {
        if (preg_match('/\A---[ \t]*\r?\n(.*?)\r?\n---[ \t]*(?:\r?\n|\z)/s', $text, $m) !== 1) {
            return [[], $text];
        }

        return [self::parse($m[1]), ltrim(substr($text, strlen($m[0])), "\r\n")];
    }

    /**
     * The chunk metadata a document's fields give, under the package's names; [] for none.
     *
     * @return array<string, string|list<string>>
     */
    public static function metadata(array $fields, string $fileName): array
    {
        if ($fields === []) {
            return [];
        }

        try {
            $keys = (array) config('ai.file_context.frontmatter.keys', []);
        } catch (\Throwable) {
            $keys = []; // no container (FileProcessor runs without one too)
        }
        $metadata = [];
        foreach (self::FIELDS as $field) {
            $value = $field === 'slug' && !isset($fields[$keys['slug'] ?? 'slug'])
                ? pathinfo($fileName, PATHINFO_FILENAME)
                : $fields[$keys[$field] ?? $field] ?? null;
            if (in_array($field, self::LIST_FIELDS, true)) {
                $list = array_values(array_filter(array_map('strval', is_array($value) ? $value : [$value]), static fn ($v) => trim($v) !== ''));
                if ($list !== []) {
                    $metadata[$field] = array_map('trim', $list);
                }
            } elseif (is_scalar($value) && trim((string) $value) !== '') {
                $metadata[$field] = trim((string) $value);
            }
        }
        return $metadata;
    }

    /** YAML when it parses; else one `key: value` / `key: [a, b]` per line (an unquoted ": " in a value breaks YAML, not this). */
    private static function parse(string $block): array
    {
        if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            try {
                $parsed = \Symfony\Component\Yaml\Yaml::parse($block);
                if (is_array($parsed)) {
                    return $parsed;
                }
            } catch (\Throwable) {
                // Fall through to the line reader.
            }
        }

        $fields = [];
        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            if (preg_match('/^([A-Za-z_][\w-]*):\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $value = trim($m[2]);
            $fields[$m[1]] = preg_match('/^\[(.*)\]$/', $value, $list) === 1
                ? array_values(array_filter(array_map(static fn ($item) => trim(trim($item), '"\''), explode(',', $list[1])), static fn ($v) => $v !== ''))
                : trim($value, '"\'');
        }

        return $fields;
    }
}
