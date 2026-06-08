<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Condoedge\Ai\Contracts\FileContextEnricherInterface;

/**
 * Default pass-through enricher. Returns files unchanged.
 * Override in your project's service provider to add priority logic.
 */
class DefaultFileContextEnricher implements FileContextEnricherInterface
{
    public function enrich(array $files, string $question, mixed $user): array
    {
        return $files;
    }
}
