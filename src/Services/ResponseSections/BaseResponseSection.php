<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\Contracts\ResponseSectionInterface;
use Condoedge\Ai\DTOs\PipelineContext;

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

    /**
     * Default role for a response section. Override to 'system' for identity,
     * security policy, or other non-negotiable framing.
     */
    public function role(): string
    {
        return 'user';
    }

    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        return true;
    }

    protected function header(string $title): string
    {
        return "\n=== {$title} ===\n\n";
    }

    /**
     * Wrap untrusted content (the user's question, retrieved file/document text,
     * raw result data) in tamper-evident delimiters so the model treats it as
     * data to analyze rather than instructions to follow. Any stray closing tag
     * inside the content is neutralized so it cannot break out of the wrapper —
     * in any letter case and with any spacing or attributes inside the tag
     * (`</TAG >`, `< / tag>`), and so is a forged OPENING tag of the same name
     * (the query-side BasePromptSection has done both since its own review).
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

        // The exact closing tag keeps its historical escape (`<\/tag>`); every
        // other spelling of the tag, opening or closing, loses its `<` the same way.
        $safe = str_replace('</' . $tag . '>', '<\\/' . $tag . '>', $text);
        $safe = (string) preg_replace('/<(\s*\/?\s*' . preg_quote($tag, '/') . '\b[^<>]*>)/i', '<\\\\$1', $safe);

        return "<{$tag}{$attrString}>\n{$safe}\n</{$tag}>";
    }
}
