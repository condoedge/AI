<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

class SelectedScope
{
    public function __construct(
        public readonly float $score,
        public readonly string $entity,
        public readonly string $matchedText,
    ) {}
}
