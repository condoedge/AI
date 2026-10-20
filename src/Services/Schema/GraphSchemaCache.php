<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Schema;

/**
 * GraphSchemaCache (Task 5.3)
 *
 * The graph schema changes only when data is (re)ingested, yet getSchema() runs four
 * Neo4j queries — one a full-graph `MATCH (a)-[r]->(b)` aggregation — and was called
 * twice per chat turn. This caches:
 *
 * - the raw store schema under `ai:schema:v1:<neo4j database>`;
 * - per-label sampled property types under a generation-scoped key, so one flush
 *   invalidates every label without a tagged cache store.
 *
 * TTL: config `ai.schema_cache_ttl` (seconds, shared with the ai:discover
 * SchemaInspector cache). A TTL <= 0 disables caching. Cache-store failures degrade
 * to a direct fetch; a fetch that throws is never cached, nor is a schema whose
 * pattern scan failed (`relationshipPatterns === null`).
 *
 * Invalidation:
 * - the ingest/index/setup commands flush on exit (FlushesGraphSchemaCache,
 *   same effect as AI::flushSchemaCache());
 * - automatic sync (model observers, Ingest/SyncEntityJob → DataIngestionService /
 *   RelationshipSyncer) does NOT flush on every save: after a write it checks the
 *   CACHED label / relationship-type / pattern lists (one cache read) and flushes
 *   only when the write introduced something new (flushIfUnknownLabel /
 *   flushIfUnknownRelationship).
 * - Everything else is TTL-bounded staleness, by design: removing the last node of
 *   a label or the last edge of a pattern (RemoveEntityJob, deletes) keeps the stale
 *   entry until the TTL expires or the next command flush.
 */
final class GraphSchemaCache
{
    public const PREFIX = 'ai:schema:v1:';
    private const DEFAULT_TTL = 3600;

    public function key(): string
    {
        return self::PREFIX . $this->database();
    }

    /**
     * @param callable(): array $fetch
     */
    public function remember(callable $fetch): array
    {
        // A schema whose relationship-pattern scan failed (patterns === null) is
        // served for this call but never cached: caching it would strip every edge
        // from the prompt for the whole TTL (review I-1).
        return $this->rememberKey(
            $this->key(),
            $fetch,
            fn (array $schema) => !(array_key_exists('relationshipPatterns', $schema) && $schema['relationshipPatterns'] === null)
        );
    }

    /**
     * @param callable(): array $fetch
     */
    public function rememberLabel(string $label, callable $fetch): array
    {
        return $this->rememberKey($this->labelKey($label), $fetch);
    }

    /**
     * Store a per-label value with a SHORTER lifetime than the schema TTL — used for
     * negative probe results (review I-3) so a failing probe is not re-run every turn
     * yet is retried soon. Bounded by the schema TTL; flushed with the generation.
     */
    public function putLabel(string $label, array $value, int $seconds): void
    {
        $ttl = min($seconds, $this->ttl());
        $store = $ttl > 0 ? $this->store() : null;
        if ($store === null) {
            return;
        }

        try {
            $store->put($this->labelKey($label), $value, $ttl);
        } catch (\Throwable $e) {
            // Best effort.
        }
    }

    private function labelKey(string $label): string
    {
        return $this->key() . ':labels:' . $this->generation() . ':' . $label;
    }

    /**
     * Auto-sync hook (review I-2): after a write that created a node, flush only when
     * the CACHED schema does not know its label. One cache read; no graph query.
     * Returns whether it flushed. Nothing cached → nothing to do.
     */
    public function flushIfUnknownLabel(string $label): bool
    {
        $cached = $this->peek();
        if ($cached === null || in_array($label, (array) ($cached['labels'] ?? []), true)) {
            return false;
        }

        $this->flush();

        return true;
    }

    /**
     * Auto-sync hook (review I-2): after a write that created a relationship, flush
     * only when the cached schema lacks its type or its (from)-[:type]->(to) pattern
     * (a new pattern of a known type is still a new card edge).
     */
    public function flushIfUnknownRelationship(string $fromLabel, string $type, string $toLabel): bool
    {
        $cached = $this->peek();
        if ($cached === null) {
            return false;
        }

        $types = (array) ($cached['relationshipTypes'] ?? $cached['relationships'] ?? []);
        $known = in_array($type, $types, true);
        $patterns = $cached['relationshipPatterns'] ?? null;
        if ($known && is_array($patterns)) {
            $known = false;
            foreach ($patterns as $p) {
                if (is_array($p)
                    && ($p['from_label'] ?? null) === $fromLabel
                    && ($p['type'] ?? null) === $type
                    && ($p['to_label'] ?? null) === $toLabel) {
                    $known = true;
                    break;
                }
            }
        }

        if ($known) {
            return false;
        }

        $this->flush();

        return true;
    }

    /** The cached store schema, or null when nothing is cached (never fetches). */
    private function peek(): ?array
    {
        $store = $this->ttl() > 0 ? $this->store() : null;
        if ($store === null) {
            return null;
        }

        try {
            $cached = $store->get($this->key());
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($cached) ? $cached : null;
    }

    public function flush(): void
    {
        $store = $this->store();
        if ($store === null) {
            return;
        }

        try {
            $store->forget($this->key());
            $store->forever($this->generationKey(), $this->generation() + 1);
        } catch (\Throwable $e) {
            // A cache outage must not fail an ingest run; the TTL still bounds staleness.
        }
    }

    /**
     * @param callable(): array $fetch
     * @param (callable(array): bool)|null $cacheable whether a fetched value may be stored
     */
    private function rememberKey(string $key, callable $fetch, ?callable $cacheable = null): array
    {
        $ttl = $this->ttl();
        $store = $ttl > 0 ? $this->store() : null;
        if ($store === null) {
            return $fetch();
        }

        try {
            $cached = $store->get($key);
        } catch (\Throwable $e) {
            return $fetch();
        }

        if (is_array($cached)) {
            return $cached;
        }

        $value = $fetch();
        if ($cacheable !== null && !$cacheable($value)) {
            return $value;
        }

        try {
            $store->put($key, $value, $ttl);
        } catch (\Throwable $e) {
            // Serving the fresh value is enough.
        }

        return $value;
    }

    private function generation(): int
    {
        $store = $this->store();
        if ($store === null) {
            return 0;
        }

        try {
            return (int) $store->get($this->generationKey(), 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function generationKey(): string
    {
        return $this->key() . ':generation';
    }

    private function ttl(): int
    {
        $ttl = $this->config('ai.schema_cache_ttl', self::DEFAULT_TTL);

        return is_numeric($ttl) ? (int) $ttl : self::DEFAULT_TTL;
    }

    private function database(): string
    {
        $database = $this->config('ai.graph.neo4j.database', 'neo4j');

        return is_string($database) && $database !== '' ? $database : 'neo4j';
    }

    private function config(string $key, mixed $default): mixed
    {
        try {
            return function_exists('app') && app()->bound('config') ? config($key, $default) : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function store(): ?\Illuminate\Contracts\Cache\Repository
    {
        try {
            if (function_exists('app') && app()->bound('cache')) {
                return app('cache')->store();
            }
        } catch (\Throwable $e) {
            // No usable cache in this context.
        }

        return null;
    }
}
