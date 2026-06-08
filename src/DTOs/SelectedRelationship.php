<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

class SelectedRelationship
{
    public function __construct(
        public readonly float $score,
        public readonly string $fromEntity,
        public readonly string $toEntity,
        public readonly string $type,
        public readonly ?string $foreignKey = null,
        public readonly string $direction = 'outgoing',
    ) {}
}
