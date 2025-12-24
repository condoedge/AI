<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\Contracts\PromptSectionInterface;

/**
 * BasePromptSection
 *
 * Abstract base class for prompt sections with common functionality.
 * Extend this class to create custom sections easily.
 */
abstract class BasePromptSection implements PromptSectionInterface
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

    /**
     * By default, always include the section
     * Override in subclasses for conditional inclusion
     */
    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        return true;
    }

    /**
     * Helper to create a section header
     */
    protected function header(string $title): string
    {
        return "\n=== {$title} ===\n\n";
    }

    /**
     * Helper to create a divider line
     */
    protected function divider(): string
    {
        return "─────────────────────────────────────────────\n";
    }
}
