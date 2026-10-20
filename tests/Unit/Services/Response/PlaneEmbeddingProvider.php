<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;

/**
 * A test embedding provider that places every text at a CHOSEN cosine similarity to one
 * reference text (unit vectors in a plane: the reference is [1, 0]) and records each
 * batch it is asked for. A text it was not told about is a test bug: it throws.
 */
final class PlaneEmbeddingProvider implements EmbeddingProviderInterface
{
    /** @var list<list<string>> */
    public array $batches = [];

    /**
     * @param array<string, float> $cosines text => cosine similarity to the reference
     */
    public function __construct(private readonly array $cosines, private readonly string $reference)
    {
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    public function embedBatch(array $texts): array
    {
        $this->batches[] = array_values($texts);

        return array_map(function (string $text): array {
            if ($text === $this->reference) {
                return [1.0, 0.0];
            }
            if (!isset($this->cosines[$text])) {
                throw new \LogicException("Unexpected text embedded: {$text}");
            }
            $cosine = $this->cosines[$text];

            return [$cosine, sqrt(1 - $cosine * $cosine)];
        }, array_values($texts));
    }

    public function getDimensions(): int
    {
        return 2;
    }

    public function getModel(): string
    {
        return 'plane';
    }

    public function getMaxLength(): int
    {
        return 8000;
    }
}
