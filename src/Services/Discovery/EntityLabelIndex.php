<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Discovery;

use Condoedge\Ai\Support\AliasNormalizer;

/**
 * EntityLabelIndex
 *
 * The ONE memoized lookup over config('entities') by graph label (Task 1.28 M4),
 * shared by ResponseEntityEnricher (is a `type` column a registered entity?),
 * ResultsDataSection (display name for the anchor noun) and
 * PolymorphicTargetResolver (class -> catalog label).
 *
 * labelForTerm() is the one lookup from a WORD (a Query-Understanding entity type,
 * a user term) to an entity: through AliasNormalizer, so any spelling of a label
 * or of a declared synonym resolves without being listed in the config.
 *
 * Memoization is keyed on the config array itself: the maps are rebuilt only
 * when config('entities') is a different array (identity-first `!==` is cheap for
 * the same copy-on-write array), so a runtime/test config change is never stale.
 */
final class EntityLabelIndex
{
    private static ?self $fallback = null;

    private ?array $source = null;

    /** @var array<string, array> graph label => entity config */
    private array $byGraphLabel = [];

    /** @var array<string, array> config-key basename => entity config */
    private array $byKeyBasename = [];

    /** @var array<string|int, string|null>|null AliasNormalizer key => label (null: claimed by several entities); built on demand */
    private ?array $byTermKey = null;

    /** Process-wide instance (container singleton when available). */
    public static function shared(): self
    {
        if (function_exists('app')) {
            try {
                $app = app();
                if (!$app->bound(self::class)) {
                    $app->instance(self::class, new self());
                }

                return $app->make(self::class);
            } catch (\Throwable $e) {
                // No container — fall through to the static instance.
            }
        }

        return self::$fallback ??= new self();
    }

    /**
     * The names an entity config goes by: its graph label and its config-key
     * basename (one entry when they are the same).
     *
     * @return list<string>
     */
    public static function namesOf(string $configKey, array $config): array
    {
        $names = [];
        foreach ([$config['graph']['label'] ?? null, class_basename($configKey)] as $name) {
            if (is_string($name) && $name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The real synonyms of an entity config: its declared aliases minus every
     * derivable spelling of one of its names or of an earlier alias.
     *
     * @return list<string>
     */
    public static function synonymsOf(string $configKey, array $config): array
    {
        return AliasNormalizer::distinct(
            (array) ($config['metadata']['aliases'] ?? []),
            ...self::namesOf($configKey, $config)
        );
    }

    /**
     * Every term an entity config is detected by in a free text: its names, then
     * its declared aliases as listed (AliasNormalizer::mentions matches each in
     * any spelling).
     *
     * @return list<mixed> non-string aliases are left for the caller to skip
     */
    public static function termsOf(string $configKey, array $config): array
    {
        return array_merge(self::namesOf($configKey, $config), (array) ($config['metadata']['aliases'] ?? []));
    }

    /**
     * The label of the first configured entity a free text mentions — by one of
     * its names or declared aliases, in any spelling (AliasNormalizer::mentions,
     * the same structural test the keyword entity detection uses). Null when the
     * text names no entity at all.
     */
    public function labelMentionedIn(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        $this->refresh();

        foreach ($this->source ?? [] as $key => $config) {
            if (!is_array($config) || !is_string($key) || $key === '') {
                continue;
            }
            foreach (self::termsOf($key, $config) as $term) {
                if (is_string($term) && AliasNormalizer::mentions($text, $term)) {
                    return self::namesOf($key, $config)[0];
                }
            }
        }

        return null;
    }

    /**
     * The kinds of records the application holds, as a person would name them:
     * each entity's display name (`metadata.display_name` / `display_name`, else
     * its label as typed words — "VisitDetail" → "visit detail") with its real
     * synonyms. Never a raw label: this list is shown to a model that writes
     * user-facing text from it.
     *
     * @return list<array{name: string, synonyms: list<string>}>
     */
    public function recordKinds(): array
    {
        return array_map(
            static fn (array $kind): array => ['name' => $kind['name'], 'synonyms' => $kind['synonyms']],
            $this->labelledRecordKinds()
        );
    }

    /**
     * {@see recordKinds()} with each kind's entity label beside it — for a caller
     * that must look the kind up in host configuration (which pages open it). The
     * label is a lookup key, never text to show.
     *
     * @return list<array{label: string, name: string, synonyms: list<string>}>
     */
    public function labelledRecordKinds(): array
    {
        $this->refresh();

        $kinds = [];
        foreach ($this->source ?? [] as $key => $config) {
            if (!is_array($config) || !is_string($key) || $key === '') {
                continue;
            }
            // Not a kind a user asks about (structural-only entity).
            if (($config['metadata']['askable'] ?? true) === false) {
                continue;
            }
            $display = $config['metadata']['display_name'] ?? $config['display_name'] ?? null;
            $kinds[] = [
                'label' => self::namesOf($key, $config)[0],
                'name' => is_string($display) && trim($display) !== ''
                    ? trim($display)
                    : AliasNormalizer::phrase(self::namesOf($key, $config)[0]),
                'synonyms' => self::synonymsOf($key, $config),
            ];
        }

        return $kinds;
    }

    /** The entity config whose graph label (else config-key basename) is $label. */
    public function configForLabel(string $label): ?array
    {
        $this->refresh();

        return $this->byGraphLabel[$label] ?? $this->byKeyBasename[$label] ?? null;
    }

    public function isRegistered(string $label): bool
    {
        return $label !== '' && $this->configForLabel($label) !== null;
    }

    /**
     * The label of the entity a word names: an exact label first, then any spelling
     * of a label / config-key basename, then any spelling of a declared alias —
     * unless several entities declare that alias (ambiguous => null).
     */
    public function labelForTerm(string $term): ?string
    {
        $this->refresh();

        if ($term !== '' && isset($this->byGraphLabel[$term])) {
            return $term;
        }

        $key = AliasNormalizer::key($term);
        if ($key === '') {
            return null;
        }

        $this->byTermKey ??= $this->termKeys();

        return $this->byTermKey[$key] ?? null;
    }

    /** Catalog graph label of a model class: FQCN key first, basename key second. */
    public function labelForClass(string $class): ?string
    {
        $entities = $this->entities();
        $label = $entities[$class]['graph']['label']
            ?? $entities[class_basename($class)]['graph']['label']
            ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    private function entities(): array
    {
        try {
            $entities = function_exists('config') ? config('entities', []) : [];
        } catch (\Throwable $e) {
            $entities = [];
        }

        return is_array($entities) ? $entities : [];
    }

    private function refresh(): void
    {
        $entities = $this->entities();
        if ($this->source !== null && $entities === $this->source) {
            return;
        }

        $this->source = $entities;
        $this->byGraphLabel = [];
        $this->byKeyBasename = [];
        $this->byTermKey = null;
        foreach ($entities as $key => $config) {
            if (!is_array($config)) {
                continue;
            }
            $label = $config['graph']['label'] ?? null;
            if (is_string($label) && $label !== '') {
                $this->byGraphLabel[$label] ??= $config;
            }
            if (is_string($key) && $key !== '') {
                $this->byKeyBasename[class_basename($key)] ??= $config;
            }
        }
    }

    /**
     * @return array<string|int, string|null> AliasNormalizer key => label; names of an entity win over aliases
     */
    private function termKeys(): array
    {
        $names = [];
        $aliases = [];
        $labels = [];

        foreach ($this->source ?? [] as $key => $config) {
            if (!is_array($config) || !is_string($key) || $key === '') {
                continue;
            }
            $basename = class_basename($key);
            $label = $config['graph']['label'] ?? null;
            $label = is_string($label) && $label !== '' ? $label : $basename;
            $labels[$key] = $label;

            foreach ([$label, $basename] as $name) {
                $nameKey = AliasNormalizer::key($name);
                if ($nameKey !== '') {
                    $names[$nameKey] ??= $label;
                }
            }
        }

        foreach ($this->source ?? [] as $key => $config) {
            if (!isset($labels[$key])) {
                continue;
            }
            foreach ((array) ($config['metadata']['aliases'] ?? []) as $alias) {
                $aliasKey = is_string($alias) ? AliasNormalizer::key($alias) : '';
                if ($aliasKey === '' || isset($names[$aliasKey])) {
                    continue;
                }
                $aliases[$aliasKey] = array_key_exists($aliasKey, $aliases) && $aliases[$aliasKey] !== $labels[$key]
                    ? null
                    : $labels[$key];
            }
        }

        return $names + $aliases;
    }
}
