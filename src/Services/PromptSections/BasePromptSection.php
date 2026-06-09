<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\Contracts\PromptSectionInterface;
use Condoedge\Ai\DTOs\PipelineContext;

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
        $attrString = '';
        foreach ($attributes as $key => $value) {
            $attrString .= ' ' . $key . '=' . $value;
        }

        return "<{$tag}{$attrString}>\n" . $this->neutralizeDelimiterTags($text, $tag) . "\n</{$tag}>";
    }

    /**
     * Neutralize any forged delimiter tag (open or close) for $tag inside untrusted
     * content. Matching is case-insensitive and tolerant of whitespace inside the
     * tag (e.g. `</user_question >`, `< USER_QUESTION>`) so a crafted variant cannot
     * break out of the spotlight wrapper. The leading `<` is escaped to `<\` so the
     * model still sees the text but it can no longer act as a delimiter.
     */
    private function neutralizeDelimiterTags(string $text, string $tag): string
    {
        $pattern = '/<(\s*\/?\s*' . preg_quote($tag, '/') . '\s*)>/i';

        return preg_replace($pattern, '<\\\\$1>', $text);
    }
}
