<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Skills;

use Condoedge\Ai\Support\Frontmatter;

/**
 * The skills the host wrote (ai.skills.paths): expert knowledge the answer may reason
 * from, read from disk on each turn and never ingested.
 *
 * A skill is a folder:
 *
 *   SKILL.md                frontmatter `name`, `description`, `disclaimer` (one line per
 *                           language code, added by code under every answer a reference
 *                           of the skill grounds); its text is the skill's
 *                           instructions, given with every reference of the skill
 *   references/**\/*.md     one subject per file; frontmatter `title`, `description`
 *                           (what the file answers: it is matched on it), and optionally
 *                           `questions` (typical askings), `audience` (ai.audiences
 *                           document audiences; none = everyone), `pages`
 *                           (ai.navigation.pages keys) and `language`
 *
 * A folder without a SKILL.md, or a reference without a description, is ignored.
 */
final class SkillLibrary
{
    private const LIST_FIELDS = ['questions', 'audience', 'pages'];

    /** @var array<string, array<string, mixed>> */
    private static array $memo = [];

    /**
     * @return array<string, array{name: string, description: string, instructions: string, references: array<string, array{title: string, description: string, questions: list<string>, audience: list<string>, pages: list<string>, language: ?string, content: string}>}>
     */
    public static function all(): array
    {
        $skills = [];
        foreach ((array) config('ai.skills.paths', []) as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }
            $folder = self::absolute($path);
            $skill = self::$memo[$folder] ??= self::read($folder);
            if ($skill !== []) {
                $skills[$skill['name']] = $skill;
            }
        }

        return $skills;
    }

    public static function forget(): void
    {
        self::$memo = [];
    }

    private static function absolute(string $path): string
    {
        $path = rtrim($path, '/\\');

        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 ? $path : base_path($path);
    }

    /** @return array<string, mixed> [] when the folder holds no skill */
    private static function read(string $folder): array
    {
        $text = is_file($folder . '/SKILL.md') ? file_get_contents($folder . '/SKILL.md') : false;
        if ($text === false) {
            return [];
        }
        [$fields, $instructions] = Frontmatter::split($text);
        $name = self::text($fields['name'] ?? null) ?: basename($folder);

        $references = [];
        foreach (self::markdownFiles($folder . '/references') as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            [$meta, $body] = Frontmatter::split($content);
            $description = self::text($meta['description'] ?? null);
            if ($description === '' || trim($body) === '') {
                continue;
            }
            $id = substr($file, strlen($folder . '/references/'), -3);
            $reference = [
                'title' => self::text($meta['title'] ?? null) ?: basename($id),
                'description' => $description,
                'language' => self::text($meta['language'] ?? null) ?: null,
                'content' => trim($body),
            ];
            foreach (self::LIST_FIELDS as $field) {
                $reference[$field] = self::strings($meta[$field] ?? []);
            }
            $references[str_replace('\\', '/', $id)] = $reference;
        }
        ksort($references);

        return [
            'name' => $name,
            'description' => self::text($fields['description'] ?? null),
            'instructions' => trim($instructions),
            'disclaimer' => array_filter(array_map([self::class, 'text'], is_array($fields['disclaimer'] ?? null) ? $fields['disclaimer'] : [])),
            'references' => $references,
        ];
    }

    /** @return list<string> */
    private static function markdownFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn ($item): string => is_scalar($item) ? trim((string) $item) : '',
            is_array($value) ? $value : [$value]
        ), static fn (string $item): bool => $item !== ''));
    }
}
