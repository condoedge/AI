<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

class QueryGenerationResult
{
    public function __construct(
        public readonly ?string $cypher = null,
        public readonly string $explanation = '',
        public readonly float $confidence = 0.0,
        public readonly array $warnings = [],
        public readonly array $metadata = [],
        // Query parameters the cypher references (P-1: PII tokens the model copied
        // from the masked conversation, rebound as `$pii_…` => real value). Kept OUT
        // of $metadata on purpose: metadata reaches traces and response metadata.
        public readonly array $parameters = [],
    ) {}

    public function hasCypher(): bool
    {
        return $this->cypher !== null && $this->cypher !== '';
    }
}
