<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Schema;

use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\GraphStore\CypherSanitizer;

/**
 * PropertyTypeSampler (Task 5.2)
 *
 * Property value types for the schema cards, probed on a small sample of each
 * label's nodes and cached per label through GraphSchemaCache (flushed on ingest).
 * Deliberately NOT `db.schema.nodeTypeProperties()`: on the CondoEdge graph
 * (Neo4j 5.26, ~115 labels) that procedure took ~50 s.
 *
 * VALUES NEVER LEAVE NEO4J (Task 1.7 invariant: retrieval must not ship example
 * node data anywhere): the probe returns only, per property key, the distinct
 * `valueType()` names plus two structural format flags computed server-side —
 * every sampled string looks like an ISO date (`String(ISO date)`) or is all digits
 * (`String(numeric)`) — so the generator compares such values as strings instead
 * of against date()/integers.
 *
 * Needs Neo4j >= 5.13 (valueType). On older servers the probe fails, the failure is
 * not cached, and the cards simply render untyped property names.
 */
final class PropertyTypeSampler
{
    public const SAMPLE_SIZE = 25;

    /** Seconds a FAILED probe is remembered (as "no types") before it is retried. */
    public const NEGATIVE_TTL = 600;

    private const ISO_DATE_REGEX = '\\d{4}-\\d{2}-\\d{2}([ T]\\d{2}:\\d{2}.*)?';
    private const NUMERIC_REGEX = '-?\\d+(\\.\\d+)?';

    private GraphSchemaCache $cache;

    public function __construct(
        private readonly GraphStoreInterface $graphStore,
        ?GraphSchemaCache $cache = null,
    ) {
        $this->cache = $cache ?? new GraphSchemaCache();
    }

    /**
     * @param string[] $labels
     * @return array<string, array<string, string>> label => [property => type]
     */
    public function forLabels(array $labels): array
    {
        $types = [];

        foreach (array_unique($labels) as $label) {
            if (!is_string($label) || $label === '') {
                continue;
            }

            try {
                $escaped = CypherSanitizer::escapeLabel($label);
            } catch (\Throwable $e) {
                continue; // never interpolate an unsafe label
            }

            try {
                $probed = $this->cache->rememberLabel($label, fn () => $this->probe($escaped));
            } catch (\Throwable $e) {
                // A failed probe only costs the types on this label's card. Remember the
                // failure briefly (review I-3) so e.g. Neo4j < 5.13 is not re-probed on
                // every turn; the next flush or NEGATIVE_TTL retries it.
                $this->cache->putLabel($label, [], self::NEGATIVE_TTL);
                continue;
            }

            if ($probed !== []) {
                $types[$label] = $probed;
            }
        }

        return $types;
    }

    /** The probe Cypher for an ALREADY-ESCAPED label. Returns type metadata only. */
    public static function probeCypher(string $escapedLabel): string
    {
        return "MATCH (n:{$escapedLabel}) WITH n LIMIT " . self::SAMPLE_SIZE . ' '
            . 'UNWIND keys(n) AS property '
            . 'WITH property, n[property] AS v WHERE v IS NOT NULL '
            . 'WITH property, valueType(v) AS t, '
            . "CASE WHEN valueType(v) STARTS WITH 'STRING' THEN v =~ '" . self::ISO_DATE_REGEX . "' ELSE false END AS iso, "
            . "CASE WHEN valueType(v) STARTS WITH 'STRING' THEN v =~ '" . self::NUMERIC_REGEX . "' ELSE false END AS numeric "
            . 'RETURN property, collect(DISTINCT t) AS types, all(x IN collect(iso) WHERE x) AS iso, '
            . 'all(x IN collect(numeric) WHERE x) AS numeric';
    }

    /** @return array<string, string> */
    private function probe(string $escapedLabel): array
    {
        $rows = $this->graphStore->query(self::probeCypher($escapedLabel));

        $types = [];
        foreach ($rows as $row) {
            $property = is_array($row) ? ($row['property'] ?? null) : null;
            if (!is_string($property) || $property === '') {
                continue;
            }

            $names = [];
            foreach ((array) ($row['types'] ?? []) as $cypherType) {
                if (is_string($cypherType) && ($name = self::typeName($cypherType)) !== '') {
                    $names[$name] = true;
                }
            }
            $names = array_keys($names);
            sort($names);
            if ($names === []) {
                continue;
            }

            if ($names === ['String']) {
                if (($row['iso'] ?? false) === true) {
                    $names = ['String(ISO date)'];
                } elseif (($row['numeric'] ?? false) === true) {
                    $names = ['String(numeric)'];
                }
            }

            $types[$property] = implode('|', $names);
        }

        return $types;
    }

    /**
     * Cypher valueType() name → compact card name, structurally:
     * "INTEGER NOT NULL" → Integer, "LIST<STRING NOT NULL> NOT NULL" → List,
     * "ZONED DATETIME NOT NULL" → ZonedDatetime.
     */
    public static function typeName(string $cypherType): string
    {
        $base = strtoupper(trim($cypherType));
        $base = preg_replace('/<.*$/s', '', $base) ?? $base;
        $base = trim((string) preg_replace('/\s+NOT NULL$/', '', trim($base)));

        return str_replace(' ', '', ucwords(strtolower($base)));
    }
}
