<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

class SelectedEntity
{
    public function __construct(
        public readonly float $score,
        public readonly string $matchedText,
        public readonly ?array $config = null,
    ) {}
}
