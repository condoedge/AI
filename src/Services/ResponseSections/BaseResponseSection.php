<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\Contracts\ResponseSectionInterface;

/**
 * BaseResponseSection
 *
 * Abstract base class for response sections with common functionality.
 */
abstract class BaseResponseSection implements ResponseSectionInterface
{
    protected string $name;
    protected int $priority;

    public function __construct(?string $name = null, ?int $priority = null)
    {
        if ($name !== null) {
            $this->name = $name;
        }
        if ($priority !== null) {
            $this->priority = $priority;
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function shouldInclude(array $context, array $options = []): bool
    {
        return true;
    }

    protected function header(string $title): string
    {
        return "\n=== {$title} ===\n\n";
    }
}
