<?php

declare(strict_types=1);

namespace Condoedge\Ai\EmbeddingProviders;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;

/**
 * The embedding provider while the master switch is off (AI_ENABLED=false): no call, no API
 * key needed, empty vectors, nothing thrown. See AiFeature.
 */
final class DisabledEmbeddingProvider implements EmbeddingProviderInterface
{
    public function embed(string $text): array
    {
        return [];
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn () => [], $texts);
    }

    public function getDimensions(): int
    {
        return 0;
    }

    public function getModel(): string
    {
        return 'disabled';
    }

    public function getMaxLength(): int
    {
        return 0;
    }
}
