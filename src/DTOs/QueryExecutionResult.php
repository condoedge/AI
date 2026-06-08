<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

class QueryExecutionResult
{
    public function __construct(
        public readonly bool $success = false,
        public readonly array $data = [],
        public readonly array $stats = [],
        public readonly array $metadata = [],
        public readonly array $errors = [],
    ) {}

    public static function empty(): self
    {
        return new self(success: true);
    }
}
