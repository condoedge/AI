<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

class SemanticSelectionResult
{
    public function __construct(
        /** @var array<string, SelectedEntity> keyed by FQCN */
        public array $entities = [],
        /** @var array<string, SelectedRelationship> keyed by relKey */
        public array $relationships = [],
        /** @var array<string, SelectedScope> keyed by scopeKey */
        public array $scopes = [],
        /** 'semantic' | 'keyword' */
        public readonly string $selectionMethod = 'semantic',
    ) {}
}
