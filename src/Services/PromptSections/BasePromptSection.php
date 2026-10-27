<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\Contracts\PromptSectionInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Support\Spotlight;

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
     * Default role for a prompt section. Override to 'system' for identity,
     * security policy, or static query-generation rules.
     */
    public function role(): string
    {
        return 'user';
    }

    /**
     * By default, always include the section
     * Override in subclasses for conditional inclusion
     */
    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
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

    /**
     * Wrap untrusted content (the user's question, retrieved file/document text)
     * in tamper-evident delimiters so the model treats it as data to analyze
     * rather than instructions to follow. Any stray opening OR closing tag inside
     * the content is neutralized so it cannot break out of (or forge) the wrapper.
     *
     * @param string $text The untrusted content
     * @param string $tag  The delimiter tag, e.g. 'user_question' or 'retrieved_document'
     * @param array<string,string|int> $attributes Optional attributes, e.g. ['id' => 3]
     */
    protected function spotlight(string $text, string $tag, array $attributes = []): string
    {
        return Spotlight::wrap($text, $tag, $attributes);
    }
}
