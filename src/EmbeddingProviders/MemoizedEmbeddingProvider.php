<?php

declare(strict_types=1);

namespace Condoedge\Ai\EmbeddingProviders;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;

/**
 * Decorator that memoizes embedding calls within a single request.
 *
 * The same question text gets embedded up to 4 times in a single pipeline
 * (context index, similar queries, scope matching, entity fallback).
 * This wrapper caches results in memory so each unique text is embedded once.
 *
 * Usage: Wrap any EmbeddingProviderInterface implementation.
 * The cache lives only for the current request — no cross-request leaks.
 */
class MemoizedEmbeddingProvider implements EmbeddingProviderInterface
{
    private array $cache = [];

    public function __construct(
        private readonly EmbeddingProviderInterface $inner
    ) {}

    public function embed(string $text): array
    {
        $key = md5($text);

        if (!isset($this->cache[$key])) {
            $this->cache[$key] = $this->inner->embed($text);
        }

        return $this->cache[$key];
    }

    public function embedBatch(array $texts): array
    {
        // Check which texts need embedding
        $uncached = [];
        $uncachedIndices = [];

        foreach ($texts as $i => $text) {
            $key = md5($text);
            if (!isset($this->cache[$key])) {
                $uncached[] = $text;
                $uncachedIndices[] = $i;
            }
        }

        // Embed only the uncached texts
        if (!empty($uncached)) {
            $newEmbeddings = $this->inner->embedBatch($uncached);
            foreach ($uncached as $j => $text) {
                $this->cache[md5($text)] = $newEmbeddings[$j];
            }
        }

        // Build result from cache
        $result = [];
        foreach ($texts as $text) {
            $result[] = $this->cache[md5($text)];
        }

        return $result;
    }

    public function getDimensions(): int
    {
        return $this->inner->getDimensions();
    }

    public function getModel(): string
    {
        return $this->inner->getModel();
    }

    public function getMaxLength(): int
    {
        return $this->inner->getMaxLength();
    }
}
