<?php
// src/Services/Cache/QueryResultCache.php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class QueryResultCache
{
    private string $prefix;
    private int $ttl;

    public function __construct(?string $prefix = null, ?int $ttl = null)
    {
        $this->prefix = $prefix ?? config('ai.cache.prefix', 'ai.query.');
        $this->ttl = $ttl ?? config('ai.cache.ttl', 3600); // 1 hour default
    }

    public function cacheContext(string $question, array $context): void
    {
        $key = $this->buildKey($question, 'context');
        Cache::put($key, $context, $this->ttl);
    }

    public function getContext(string $question): ?array
    {
        $key = $this->buildKey($question, 'context');
        return Cache::get($key);
    }

    public function cacheQuery(string $question, string $cypher, array $metadata = []): void
    {
        $key = $this->buildKey($question, 'query');
        Cache::put($key, [
            'cypher' => $cypher,
            'metadata' => $metadata,
            'cached_at' => now()->toIso8601String(),
        ], $this->ttl);
    }

    public function getQuery(string $question): ?array
    {
        $key = $this->buildKey($question, 'query');
        return Cache::get($key);
    }

    public function invalidate(string $question): void
    {
        Cache::forget($this->buildKey($question, 'context'));
        Cache::forget($this->buildKey($question, 'query'));
    }

    public function flush(): void
    {
        // Note: This only works with cache drivers that support tags
        // For redis: Cache::tags([$this->prefix])->flush();
        // For file/array: manually clear keys starting with prefix
    }

    private function buildKey(string $question, string $type): string
    {
        $normalized = $this->normalizeQuestion($question);
        $hash = md5($normalized);
        return "{$this->prefix}{$type}.{$hash}";
    }

    private function normalizeQuestion(string $question): string
    {
        // Lowercase, trim, remove extra whitespace
        $normalized = strtolower(trim($question));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        // Remove punctuation for matching
        $normalized = preg_replace('/[^\w\s]/', '', $normalized);

        return $normalized;
    }
}
