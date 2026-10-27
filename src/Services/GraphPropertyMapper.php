<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Carbon\Carbon;
use Condoedge\Ai\Domain\ValueObjects\GraphConfig;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * GraphPropertyMapper
 *
 * Single source of truth for turning entity data into Neo4j-safe node
 * properties. Both the single (DataIngestionService) and batch
 * (BatchDataIngestionService) ingestion paths use this collaborator so that
 * property extraction and value sanitization are identical regardless of the
 * entry point.
 *
 * mapNodeProperties() is THE node-property mapping point: configured
 * `graph.properties` (from toArray()), plus `graph.computed` values produced by
 * the host model's own accessors/methods (after `graph.with` eager-loads), plus
 * `graph.casts` forced graph types.
 *
 * Neo4j only supports primitive types (string, int, float, bool, null) and
 * arrays of primitives. Associative arrays (maps) and arrays containing
 * nested arrays/objects must be JSON-encoded before storage.
 */
class GraphPropertyMapper
{
    /**
     * Graph types accepted by `graph.casts`. 'local_datetime' writes the wall clock of the app's
     * timezone (2026-09-30T21:30:00), so a period filter matches the app's own date filters.
     */
    public const CAST_TYPES = ['string', 'int', 'float', 'bool', 'date', 'local_datetime'];

    /** Eloquent-style spellings accepted as aliases of CAST_TYPES. */
    public const CAST_ALIASES = [
        'integer' => 'int',
        'boolean' => 'bool',
        'double' => 'float',
        'decimal' => 'float',
        'real' => 'float',
        'datetime' => 'date',
    ];

    /** @var array<string, true> (class|property|kind) keys already warned about this run */
    private array $warned = [];

    /**
     * Build the full node property map for an entity: configured properties, computed
     * properties and casts. Every ingestion path (single ingest/sync, batch ingest)
     * goes through here so they all write identical nodes. `id` is added by callers.
     *
     * A computed value that throws is skipped for that node (one warning per
     * entity class + property per run) — it never fails the ingest.
     */
    public function mapNodeProperties(object $entity, GraphConfig $config): array
    {
        $this->loadGraphRelations($entity, $config);

        $data = self::entityData($entity);
        $properties = $this->extractProperties($data, $config->properties, $entity);

        // Raw (un-normalized) computed values: a cast is applied to the raw value so
        // e.g. a '00042' code forced to string is not first coerced to 42.0.
        $rawComputed = [];
        foreach ($config->computed as $property => $source) {
            try {
                $raw = $this->evaluateComputed($entity, $source);
                $rawComputed[$property] = $raw;
                $properties[$property] = $this->sanitizeForGraph($this->normalizeComputed($raw, $entity));
            } catch (\Throwable $e) {
                unset($rawComputed[$property], $properties[$property]);
                $this->warnOnce($entity, (string) $property, 'computed', 'AI graph ingest: computed property failed and was skipped for this node', [
                    'source' => is_string($source) ? $source : 'callable',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($config->casts as $property => $type) {
            if (!array_key_exists($property, $properties)) {
                continue;
            }

            $source = array_key_exists($property, $rawComputed) ? $rawComputed[$property] : $properties[$property];

            try {
                $properties[$property] = $this->sanitizeForGraph($this->castValue($source, $type, $entity));
            } catch (\Throwable $e) {
                // Uncastable value: keep the uncast (already normalized) value.
                $this->warnOnce($entity, (string) $property, 'cast', 'AI graph ingest: graph.casts could not convert a value; kept uncast', [
                    'cast' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $properties;
    }

    /**
     * The entity's data as the graph reads it: its toGraphArray() when it declares one (a model whose
     * toArray() also serializes loaded relations, kept for the app), else its toArray().
     */
    public static function entityData(object $entity): array
    {
        if (method_exists($entity, 'toGraphArray')) {
            return $entity->toGraphArray();
        }

        return method_exists($entity, 'toArray') ? $entity->toArray() : [];
    }

    /**
     * Eager-load `graph.with` relations for a batch of entities: one query per relation
     * per model class instead of one per model. Models already loaded (ai:ingest chunk
     * queries apply ->with()) are left untouched (loadMissing).
     *
     * @param iterable<object> $entities
     */
    public function eagerLoadGraphRelations(iterable $entities): void
    {
        $byClass = [];
        foreach ($entities as $entity) {
            if ($entity instanceof Model && method_exists($entity, 'getGraphConfig')) {
                $byClass[get_class($entity)][] = $entity;
            }
        }

        foreach ($byClass as $models) {
            try {
                $with = $models[0]->getGraphConfig()->with;
            } catch (\Throwable $e) {
                continue; // Config errors surface in the per-entity mapping.
            }

            if ($with === []) {
                continue;
            }

            try {
                (new EloquentCollection($models))->loadMissing($with);
            } catch (\Throwable $e) {
                $this->warnOnce($models[0], implode(',', $with), 'with', 'AI graph ingest: graph.with eager-load failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Lazy-load `graph.with` relations on a single model (single-model sync path).
     */
    private function loadGraphRelations(object $entity, GraphConfig $config): void
    {
        if ($config->with === [] || !method_exists($entity, 'loadMissing')) {
            return;
        }

        try {
            $entity->loadMissing($config->with);
        } catch (\Throwable $e) {
            $this->warnOnce($entity, implode(',', $config->with), 'with', 'AI graph ingest: graph.with eager-load failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * string → a method of that name is called with no args; otherwise the
     * attribute/accessor is read ($model->{$name}). Non-string callable → $fn($model).
     * Strings are never treated as PHP callables ('count', 'max' are global functions).
     */
    private function evaluateComputed(object $entity, mixed $source): mixed
    {
        if (!is_string($source)) {
            return $source($entity);
        }

        if (method_exists($entity, $source)) {
            $value = $entity->{$source}();

            // New-style accessor declared by its method name (e.g. 'realDueAmount').
            if ($value instanceof Attribute && $entity instanceof Model) {
                return $entity->getAttribute(Str::snake($source));
            }

            if ($value instanceof Relation) {
                throw new \InvalidArgumentException(
                    "'{$source}' is a relation, not a value — list it under graph.with and compute from it"
                );
            }

            return $value;
        }

        return $entity->{$source};
    }

    /**
     * Type a computed value for the graph: numeric strings/decimals → float, ints stay
     * int, bool stays bool, dates → the model's own date serialization (identical to
     * the date attributes toArray() writes), enums → their value, null → null.
     */
    private function normalizeComputed(mixed $value, object $entity): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $this->normalizeComputed($value->value, $entity);
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if ($value instanceof \DateTimeInterface) {
            return $this->serializeDate($value, $entity);
        }

        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                return $value->toArray();
            }
            if (method_exists($value, '__toString')) {
                $value = (string) $value; // e.g. Brick\Math\BigDecimal → numeric string below
            } else {
                throw new \InvalidArgumentException('Unsupported computed value type ' . get_class($value));
            }
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * Apply a `graph.casts` type. Throws when the value cannot be converted.
     */
    private function castValue(mixed $value, string $type, object $entity): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        $type = self::CAST_ALIASES[strtolower($type)] ?? strtolower($type);

        switch ($type) {
            case 'string':
                if ($value instanceof \DateTimeInterface) {
                    return $this->serializeDate($value, $entity);
                }
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                    return (string) $value;
                }
                if (is_array($value)) {
                    return json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'int':
                if (is_bool($value) || is_int($value)) {
                    return (int) $value;
                }
                if (is_float($value) || (is_string($value) && is_numeric($value))) {
                    return (int) $value;
                }
                break;

            case 'float':
                if (is_int($value) || is_float($value) || is_bool($value)) {
                    return (float) $value;
                }
                if (is_object($value) && method_exists($value, '__toString')) {
                    $value = (string) $value;
                }
                if (is_string($value) && is_numeric($value)) {
                    return (float) $value;
                }
                break;

            case 'bool':
                if (is_bool($value)) {
                    return $value;
                }
                if (is_int($value) || is_float($value)) {
                    return $value != 0;
                }
                if (is_string($value)) {
                    $bool = filter_var(trim($value), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($bool !== null) {
                        return $bool;
                    }
                }
                break;

            case 'date':
                if ($value instanceof \DateTimeInterface) {
                    return $this->serializeDate($value, $entity);
                }
                if (is_int($value)) {
                    return $this->serializeDate(Carbon::createFromTimestamp($value), $entity);
                }
                if (is_string($value) && trim($value) !== '') {
                    return $this->serializeDate(Carbon::parse($value), $entity);
                }
                break;

            case 'local_datetime':
                $timezone = (string) config('app.timezone', 'UTC');
                if ($value instanceof \DateTimeInterface) {
                    return Carbon::instance($value)->setTimezone($timezone)->format('Y-m-d\TH:i:s');
                }
                if (is_string($value) && trim($value) !== '') {
                    return Carbon::parse($value, $timezone)->setTimezone($timezone)->format('Y-m-d\TH:i:s');
                }
                break;

            default:
                throw new \InvalidArgumentException(
                    "Unknown graph.casts type '{$type}' (expected: " . implode('|', self::CAST_TYPES) . ')'
                );
        }

        throw new \InvalidArgumentException(
            'Cannot cast ' . get_debug_type($value) . " to {$type}"
        );
    }

    /**
     * Serialize a date exactly like the model serializes its date attributes in
     * toArray() (Model::serializeDate — honours host overrides), so computed/cast
     * dates share the graph's existing date convention. Non-Eloquent entities use
     * Laravel's default (ISO-8601 UTC, e.g. 2026-01-15T10:00:00.000000Z).
     */
    private function serializeDate(\DateTimeInterface $date, object $entity): string
    {
        if ($entity instanceof Model) {
            return (string) (fn (\DateTimeInterface $d) => $this->serializeDate($d))->call($entity, $date);
        }

        return Carbon::instance($date)->toJSON();
    }

    /**
     * Log a warning once per (entity class, property, kind) per mapper lifetime (one run).
     */
    private function warnOnce(object $entity, string $property, string $kind, string $message, array $context = []): void
    {
        $key = get_class($entity) . '|' . $property . '|' . $kind;
        if (isset($this->warned[$key])) {
            return;
        }
        $this->warned[$key] = true;

        try {
            Log::warning($message, ['entity' => get_class($entity), 'property' => $property] + $context);
        } catch (\Throwable $e) {
            // No logger bound (bare unit context) — skipping the value is what matters.
        }
    }

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
