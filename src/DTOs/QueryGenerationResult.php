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
    ) {}

    public function hasCypher(): bool
    {
        return $this->cypher !== null && $this->cypher !== '';
    }
}
