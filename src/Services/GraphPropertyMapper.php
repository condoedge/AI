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
     * @param array $data Entity data (from Nodeable::toArray())
     * @param array $propertyNames Property names to extract
     * @return array Extracted, Neo4j-safe properties
     */
    public function extractProperties(array $data, array $propertyNames): array
    {
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

                $value = $this->sanitizeForGraph($value);

                $properties[$propertyName] = $value;
            }
        }

        return $properties;
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
