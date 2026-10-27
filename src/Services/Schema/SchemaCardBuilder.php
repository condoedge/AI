<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Schema;

use Condoedge\Ai\Domain\ValueObjects\RelationshipConfig;
use Condoedge\Ai\Support\AliasNormalizer;
use Condoedge\Ai\Support\EnumLegend;

/**
 * SchemaCardBuilder (Task 5.2)
 *
 * Renders ONE compact card per selected graph label, replacing the three
 * overlapping prose renderings of the schema (SchemaSection, RelationshipsSection,
 * DetectedEntitiesSection):
 *
 *   Invoice — A customer invoice ...  (aliases: invoices, bill, …)
 *     props: id:Integer, status:String(numeric){1=Draft|2=Approved|…}, due_at:String(ISO date), …
 *     notes: customer_type: Customer type (polymorphic - Contact or Account); [:CUSTOMER]: …
 *     out: (Invoice)-[:CUSTOMER]->(Account), (Invoice)-[:BELONGS_TO_TEAM]->(Team)
 *     in: (InvoiceDetail)-[:INVOICE_DETAILS]->(Invoice)
 *     fk (no edge): approved_by → User.id
 *
 * Sources (no per-turn graph scans here — the builder is pure):
 * - edges + direction: the LIVE graph patterns (schema all_relationship_patterns /
 *   relationshipPatterns). An edge is shown when both ends are selected, or when the
 *   card label's OWN entity config declares it (so a declared hop to an unselected
 *   label survives, but hub labels never fan out to every entity that points at them).
 *   Without pattern evidence, configured edges whose type exists are shown instead.
 * - property names: schema properties (entity config) + sampled keys; types from
 *   schema property_types (PropertyTypeSampler, cached).
 * - enum legends: ONLY what the entity config declares, parsed structurally from
 *   `<code>=<label>` pairs in the property description (no graph DISTINCT scans).
 * - description / aliases / informative property notes: entity config metadata.
 *   A property description is dropped when it is boilerplate — it recurs verbatim on
 *   GENERIC_DESCRIPTION_MIN_ENTITIES+ entities, merely restates the property name,
 *   describes a foreign key the edge/fk line already carries, or is an enum legend
 *   already rendered inline. Structural rules only — no word lists.
 */
final class SchemaCardBuilder
{
    public const MAX_ALIASES = 8;
    public const MAX_ENUM_VALUES = 8;
    public const GENERIC_DESCRIPTION_MIN_ENTITIES = 3;

    private ?array $entityConfigs;

    /** @var array<string, array>|null label => entity config */
    private ?array $byLabel = null;

    /** @var array<string, int>|null normalized description => number of entities using it */
    private ?array $descriptionFrequency = null;

    /** Hard cap on full cards (the no-selection fallback projects the whole graph). */
    public const MAX_CARDS = 20;

    public function __construct(?array $entityConfigs = null)
    {
        $this->entityConfigs = $entityConfigs;
    }

    /**
     * Schema labels in card order — selected entities first (selection order), then
     * the rest — optionally capped. The ONE ordering both the prompt section (what it
     * renders) and the retriever (what it probes types for) use.
     *
     * @param mixed[] $schemaLabels
     * @param mixed[] $selectedEntities
     * @return string[]
     */
    public static function orderedLabels(array $schemaLabels, array $selectedEntities): array
    {
        $present = [];
        foreach ($schemaLabels as $label) {
            if (is_string($label) && $label !== '') {
                $present[$label] = true;
            }
        }

        $ordered = [];
        foreach ($selectedEntities as $selected) {
            if (is_string($selected) && isset($present[$selected])) {
                $ordered[$selected] = true;
            }
        }

        return array_keys($ordered + $present);
    }

    /**
     * The labels that get a full card: the first MAX_CARDS of orderedLabels().
     *
     * @param mixed[] $schemaLabels
     * @param mixed[] $selectedEntities
     * @return string[]
     */
    public static function cardLabels(array $schemaLabels, array $selectedEntities): array
    {
        return array_slice(self::orderedLabels($schemaLabels, $selectedEntities), 0, self::MAX_CARDS);
    }

    /**
     * @param array $schema   projected schema (labels, properties, patterns, property_types …)
     * @param string[] $labels selected labels, in the order to render
     * @param string[] $compact labels to render as a COMPACT card (Task 5.10/5.11): the
     *                          labels kept only to join or scope the seeds get the header's
     *                          first sentence, props (types + enum legends) and edges —
     *                          no alias list, no property notes
     * @return array<string, string> label => card text
     */
    public function cards(array $schema, array $labels, array $compact = []): array
    {
        $labels = array_values(array_unique(array_filter($labels, fn ($l) => is_string($l) && $l !== '')));
        $selected = array_fill_keys($labels, true);
        $patterns = $this->patternEvidence($schema);
        $types = $this->typeEvidence($schema);

        $compactSet = array_fill_keys(array_filter($compact, 'is_string'), true);

        $cards = [];
        foreach ($labels as $label) {
            $cards[$label] = $this->card($label, $schema, $selected, $patterns, $types, isset($compactSet[$label]));
        }

        return $cards;
    }

    /**
     * @param string[] $compact see {@see cards()}
     */
    public function render(array $schema, array $labels, array $compact = []): string
    {
        return implode("\n\n", $this->cards($schema, $labels, $compact));
    }

    // ------------------------------------------------------------------ card

    /**
     * @param array<string, bool> $selected
     * @param array<int, array{from: string, type: string, to: string}>|null $patterns
     * @param string[]|null $types
     */
    private function card(string $label, array $schema, array $selected, ?array $patterns, ?array $types, bool $compact = false): string
    {
        $config = $this->configFor($label);
        $metadata = is_array($config['metadata'] ?? null) ? $config['metadata'] : [];
        $relationships = $this->relationshipsOf($config);
        $sampledTypes = is_array($schema['property_types'][$label] ?? null) ? $schema['property_types'][$label] : [];

        $lines = [$compact ? $this->compactHeaderLine($label, $config, $metadata) : $this->headerLine($label, $config, $metadata)];

        [$out, $in] = $patterns !== null
            ? $this->liveEdges($label, $selected, $patterns, $relationships)
            : $this->configuredEdges($label, $relationships, $types);

        $properties = $this->propertyNames($label, $schema, $config, $sampledTypes);
        $foreignKeys = $this->foreignKeyProperties($relationships);
        $descriptions = is_array($metadata['common_properties'] ?? null) ? $metadata['common_properties'] : [];

        $props = [];
        $notes = [];
        foreach ($properties as $property) {
            $entry = $property;
            $type = $sampledTypes[$property] ?? null;
            if (is_string($type) && $type !== '') {
                $entry .= ':' . $type;
            }

            $description = is_string($descriptions[$property] ?? null) ? $this->oneLine($descriptions[$property]) : '';
            $enum = $description !== '' ? $this->parseEnum($description) : [];
            if ($enum !== []) {
                $entry .= '{' . implode('|', $enum) . '}';
            } elseif ($description !== '' && $this->isInformative($property, $description, $foreignKeys)) {
                $notes[] = "{$property}: {$description}";
            }

            $props[] = $entry;
        }

        foreach ($relationships as $rel) {
            $relDescription = is_string($rel['description'] ?? null) ? $this->firstSentence($rel['description']) : '';
            if ($relDescription !== '' && $this->edgeRendered($rel, $label, $out, $in)) {
                $notes[] = "[:{$rel['type']}]: {$relDescription}";
            }
        }

        if ($props !== []) {
            $lines[] = '  props: ' . implode(', ', $props);
        }
        if ($notes !== [] && !$compact) {
            $lines[] = '  notes: ' . implode('; ', array_values(array_unique($notes)));
        }
        if ($out !== []) {
            $lines[] = '  out: ' . implode(', ', $out);
        }
        if ($in !== []) {
            $lines[] = '  in: ' . implode(', ', $in);
        }

        $fkHints = $this->foreignKeyHints($label, $relationships, $patterns, $types, $properties);
        if ($fkHints !== []) {
            $lines[] = '  fk (no edge, join with WHERE): ' . implode(', ', $fkHints);
        }

        return implode("\n", $lines);
    }

    /**
     * Compact header: the label and the first sentence of its description.
     */
    private function compactHeaderLine(string $label, ?array $config, array $metadata): string
    {
        $description = $metadata['description'] ?? $config['description'] ?? null;
        $sentence = is_string($description) ? $this->firstSentence($description) : '';

        return $sentence !== '' ? "{$label} — {$sentence}" : $label;
    }

    private function headerLine(string $label, ?array $config, array $metadata): string
    {
        $description = $metadata['description'] ?? $config['description'] ?? null;
        $header = $label;
        if (is_string($description) && trim($description) !== '') {
            $header .= ' — ' . $this->oneLine($description);
        }

        // Each word once: spellings of the label or of an earlier alias (separator,
        // case, accent, plural — "team_user" / "team user" / "teamuser") are one alias.
        $aliases = array_slice(
            AliasNormalizer::distinct((array) ($metadata['aliases'] ?? []), $label),
            0,
            self::MAX_ALIASES
        );

        if ($aliases !== []) {
            $header .= '  (aliases: ' . implode(', ', $aliases) . ')';
        }

        return $header;
    }

    // ----------------------------------------------------------------- edges

    /**
     * Live edges touching $label: other end selected, or declared by $label's config.
     *
     * @return array{0: string[], 1: string[]}
     */
    private function liveEdges(string $label, array $selected, array $patterns, array $relationships): array
    {
        $out = [];
        $in = [];

        foreach ($patterns as $p) {
            if ($p['from'] === $label) {
                if (isset($selected[$p['to']]) || $this->declares($relationships, $p['type'], $p['to'], 'outgoing')) {
                    $out[] = "({$label})-[:{$p['type']}]->({$p['to']})";
                }
                continue; // a self-loop renders once, as outgoing
            }

            if ($p['to'] === $label
                && (isset($selected[$p['from']]) || $this->declares($relationships, $p['type'], $p['from'], 'incoming'))) {
                $in[] = "({$p['from']})-[:{$p['type']}]->({$label})";
            }
        }

        return [array_values(array_unique($out)), array_values(array_unique($in))];
    }

    /**
     * Fallback when the graph gave no pattern evidence: configured edges, filtered
     * to relationship types the graph reports (when it reports any).
     *
     * @return array{0: string[], 1: string[]}
     */
    private function configuredEdges(string $label, array $relationships, ?array $types): array
    {
        $out = [];
        $in = [];
        $known = $types !== null ? array_fill_keys($types, true) : null;

        foreach ($relationships as $rel) {
            if (!empty($rel['pivot']) || ($known !== null && !isset($known[$rel['type']]))) {
                continue;
            }

            $other = $rel['target_label'] === RelationshipConfig::POLYMORPHIC_TARGET ? '' : $rel['target_label'];
            if ($this->direction($rel) === 'incoming') {
                $in[] = "({$other})-[:{$rel['type']}]->({$label})";
            } else {
                $out[] = "({$label})-[:{$rel['type']}]->({$other})";
            }
        }

        return [array_values(array_unique($out)), array_values(array_unique($in))];
    }

    private function declares(array $relationships, string $type, string $other, string $direction): bool
    {
        foreach ($relationships as $rel) {
            if ($rel['type'] !== $type || $this->direction($rel) !== $direction) {
                continue;
            }
            if ($rel['target_label'] === $other || $rel['target_label'] === RelationshipConfig::POLYMORPHIC_TARGET) {
                return true;
            }
        }

        return false;
    }

    private function edgeRendered(array $rel, string $label, array $out, array $in): bool
    {
        $needle = $this->direction($rel) === 'incoming'
            ? "-[:{$rel['type']}]->({$label})"
            : "({$label})-[:{$rel['type']}]->";

        foreach ($this->direction($rel) === 'incoming' ? $in : $out as $edge) {
            if (str_contains($edge, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Configured outgoing FK relationships whose edge the graph does NOT have: the
     * scalar id property is the only way to join, so say so (instead of letting the
     * model invent the edge).
     *
     * @return string[]
     */
    private function foreignKeyHints(string $label, array $relationships, ?array $patterns, ?array $types, array $properties): array
    {
        if ($patterns === null && $types === null) {
            return []; // no graph evidence at all: configured edges were rendered instead
        }

        $propertySet = array_fill_keys($properties, true);
        $hints = [];

        foreach ($relationships as $rel) {
            $fk = $rel['foreign_key'] ?? null;
            $target = $rel['target_label'];
            if (!is_string($fk) || $fk === '' || !isset($propertySet[$fk]) || !empty($rel['pivot'])
                || $this->direction($rel) !== 'outgoing' || $target === RelationshipConfig::POLYMORPHIC_TARGET) {
                continue;
            }

            $edgeExists = false;
            if ($patterns !== null) {
                foreach ($patterns as $p) {
                    if ($p['from'] === $label && $p['type'] === $rel['type'] && $p['to'] === $target) {
                        $edgeExists = true;
                        break;
                    }
                }
            } else {
                $edgeExists = in_array($rel['type'], $types, true);
            }

            if (!$edgeExists) {
                // Name the configured-but-absent edge type: it is exactly the edge the
                // generator is tempted to invent (live: TeamRole-[:BELONGS_TO_USER]->User).
                $hints[] = "{$fk} → {$target}.id (no :{$rel['type']})";
            }
        }

        return array_values(array_unique($hints));
    }

    // ------------------------------------------------------------ properties

    /** @return string[] */
    private function propertyNames(string $label, array $schema, ?array $config, array $sampledTypes): array
    {
        $names = $schema['properties'][$label] ?? $config['graph']['properties'] ?? [];
        $names = is_array($names) ? array_values(array_filter($names, 'is_string')) : [];

        // graph.computed values are real node properties (written at ingest).
        foreach (array_keys((array) ($config['graph']['computed'] ?? [])) as $computed) {
            if (is_string($computed) && !in_array($computed, $names, true)) {
                $names[] = $computed;
            }
        }

        foreach (array_keys($sampledTypes) as $sampled) {
            if (is_string($sampled) && !in_array($sampled, $names, true)) {
                $names[] = $sampled;
            }
        }

        return array_values(array_unique($names));
    }

    /** @return array<string, bool> */
    private function foreignKeyProperties(array $relationships): array
    {
        $keys = [];
        foreach ($relationships as $rel) {
            if (is_string($rel['foreign_key'] ?? null) && $rel['foreign_key'] !== '') {
                $keys[$rel['foreign_key']] = true;
            }
        }

        return $keys;
    }

    private function isInformative(string $property, string $description, array $foreignKeys): bool
    {
        if (isset($foreignKeys[$property])) {
            return false;
        }

        $normalized = $this->normalize($description);
        if ($normalized === $this->normalize(str_replace('_', ' ', $property))) {
            return false;
        }

        return ($this->descriptionFrequency()[$normalized] ?? 0) < self::GENERIC_DESCRIPTION_MIN_ENTITIES;
    }

    /**
     * Structural parse of an enum legend declared in the config description, e.g.
     * "Status (1=Draft, 2=Approved, 10=Voided)". Needs >= 2 `<code>=<label>` pairs.
     *
     * @return string[] rendered "code=label" entries (capped, "…" when cut)
     */
    private function parseEnum(string $description): array
    {
        // THE legend parse (shared with the digest tables' code → label cells).
        $values = [];
        foreach (EnumLegend::parse($description) as $code => $label) {
            $values[] = $code . '=' . str_replace(['|', '{', '}'], ['/', '(', ')'], $label);
        }

        if (count($values) < 2) {
            return [];
        }

        if (count($values) > self::MAX_ENUM_VALUES) {
            $values = array_slice($values, 0, self::MAX_ENUM_VALUES);
            $values[] = '…';
        }

        return $values;
    }

    /** @return array<string, int> */
    private function descriptionFrequency(): array
    {
        if ($this->descriptionFrequency !== null) {
            return $this->descriptionFrequency;
        }

        $frequency = [];
        foreach ($this->entities() as $config) {
            $seen = [];
            foreach ((array) ($config['metadata']['common_properties'] ?? []) as $description) {
                if (is_string($description)) {
                    $seen[$this->normalize($description)] = true;
                }
            }
            foreach (array_keys($seen) as $normalized) {
                $frequency[$normalized] = ($frequency[$normalized] ?? 0) + 1;
            }
        }

        return $this->descriptionFrequency = $frequency;
    }

    // --------------------------------------------------------------- helpers

    /**
     * Pattern evidence, or null when there is NONE worth trusting (review I-1):
     * - the FULL-graph list (all_relationship_patterns) wins when present; its null
     *   means the pattern scan failed — the projected slice is then derived from
     *   nothing and must not be read as "no edges";
     * - an EMPTY list while the graph reports relationship types is equally no
     *   evidence (a graph with relationship types has patterns).
     *
     * @return array<int, array{from: string, type: string, to: string}>|null
     */
    private function patternEvidence(array $schema): ?array
    {
        $raw = array_key_exists('all_relationship_patterns', $schema)
            ? $schema['all_relationship_patterns']
            : ($schema['relationshipPatterns'] ?? $schema['relationship_patterns'] ?? null);
        if (!is_array($raw)) {
            return null;
        }
        if ($raw === [] && ($this->typeEvidence($schema) ?? []) !== []) {
            return null;
        }

        $patterns = [];
        foreach ($raw as $p) {
            if (!is_array($p)) {
                continue;
            }
            $from = $p['from_label'] ?? $p['fromLabel'] ?? $p['from'] ?? null;
            $type = $p['type'] ?? $p['relationshipType'] ?? $p['relationship_type'] ?? null;
            $to = $p['to_label'] ?? $p['toLabel'] ?? $p['to'] ?? null;
            if (is_string($from) && is_string($type) && is_string($to)) {
                $patterns[$from . '|' . $type . '|' . $to] = ['from' => $from, 'type' => $type, 'to' => $to];
            }
        }

        return array_values($patterns);
    }

    /** @return string[]|null null = no relationship-type evidence */
    private function typeEvidence(array $schema): ?array
    {
        $raw = $schema['all_relationship_types'] ?? $schema['relationships'] ?? null;

        return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : null;
    }

    /** @return array<int, array> normalized relationship configs (type + target_label always strings) */
    private function relationshipsOf(?array $config): array
    {
        $relationships = [];
        foreach ((array) ($config['graph']['relationships'] ?? []) as $rel) {
            if (!is_array($rel) || !is_string($rel['type'] ?? null) || !is_string($rel['target_label'] ?? null)) {
                continue;
            }
            $relationships[] = $rel;
        }

        return $relationships;
    }

    private function direction(array $rel): string
    {
        return ($rel['direction'] ?? 'outgoing') === 'incoming' ? 'incoming' : 'outgoing';
    }

    private function configFor(string $label): ?array
    {
        if ($this->byLabel === null) {
            $this->byLabel = [];
            foreach ($this->entities() as $key => $config) {
                if (!is_array($config)) {
                    continue;
                }
                $graphLabel = $config['graph']['label'] ?? (is_string($key) ? class_basename($key) : null);
                if (is_string($graphLabel) && $graphLabel !== '') {
                    $this->byLabel[$graphLabel] ??= $config;
                }
            }
        }

        return $this->byLabel[$label] ?? null;
    }

    private function entities(): array
    {
        if ($this->entityConfigs === null) {
            try {
                $configs = function_exists('config') ? config('entities', []) : [];
            } catch (\Throwable $e) {
                $configs = [];
            }
            $this->entityConfigs = is_array($configs) ? $configs : [];
        }

        return $this->entityConfigs;
    }

    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function firstSentence(string $text): string
    {
        $text = $this->oneLine($text);
        if (preg_match('/^(.+?[.!?])(?:\s|$)/u', $text, $m)) {
            return $m[1];
        }

        return $text;
    }

    private function normalize(string $text): string
    {
        return rtrim(mb_strtolower($this->oneLine($text)), '.');
    }
}
