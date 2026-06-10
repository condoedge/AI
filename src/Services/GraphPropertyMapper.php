<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

/**
 * GraphPropertyMapper
 *
 * Single source of truth for turning entity data into Neo4j-safe node
 * properties. Both the single (DataIngestionService) and batch
 * (BatchDataIngestionService) ingestion paths use this collaborator so that
 * property extraction and value sanitization are identical regardless of the
 * entry point.
 *
 * Neo4j only supports primitive types (string, int, float, bool, null) and
 * arrays of primitives. Associative arrays (maps) and arrays containing
 * nested arrays/objects must be JSON-encoded before storage.
 */
class GraphPropertyMapper
{
    /**
     * Extract and sanitize properties from entity data.
     *
     * When the source entity is provided, its Eloquent cast definitions are consulted:
     * decimal:N casts return strings ('1234.50'), which would land in Neo4j as strings
     * and break SUM()/numeric comparisons — those are coerced to float. Only properties
     * the model explicitly declares as decimal are coerced; numeric-looking strings
     * without a decimal cast (ids, account codes like '2410') stay strings.
     *
     * @param array $data Entity data (from Nodeable::toArray())
     * @param array $propertyNames Property names to extract
     * @param object|null $entity Source entity, consulted for cast metadata (getCasts())
     * @return array Extracted, Neo4j-safe properties
     */
    public function extractProperties(array $data, array $propertyNames, ?object $entity = null): array
    {
        $casts = $entity !== null && method_exists($entity, 'getCasts') ? $entity->getCasts() : [];

        $properties = [];

        foreach ($propertyNames as $propertyName) {
            if (array_key_exists($propertyName, $data)) {
                $value = $data[$propertyName];

                // Convert objects to strings or arrays
                if (is_object($value)) {
                    if (method_exists($value, 'toArray')) {
                        $value = $value->toArray();
                    } elseif (method_exists($value, '__toString')) {
                        $value = (string) $value;
                    } else {
                        // Skip objects that can't be converted
                        continue;
                    }
                }

                if (is_string($value) && is_numeric($value) && $this->isDecimalCast($casts[$propertyName] ?? null)) {
                    $value = (float) $value;
                }

                $value = $this->sanitizeForGraph($value);

                $properties[$propertyName] = $value;
            }
        }

        return $properties;
    }

    /**
     * Whether a cast definition is Eloquent's decimal cast (decimal:N).
     */
    private function isDecimalCast(mixed $cast): bool
    {
        return is_string($cast) && ($cast === 'decimal' || str_starts_with($cast, 'decimal:'));
    }

    /**
     * Sanitize a value for Neo4j storage.
     *
     * Neo4j only supports primitive types (string, int, float, bool, null)
     * and arrays of primitives. Associative arrays (maps) like translation
     * fields {fr: "...", en: "..."} must be converted to JSON strings.
     */
    public function sanitizeForGraph(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        // Associative arrays (maps) are not supported by Neo4j — JSON-encode them
        if (!array_is_list($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        // Sequential arrays: ensure all elements are primitives
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }

        return $value;
    }
}
