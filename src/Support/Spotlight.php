<?php

declare(strict_types=1);

namespace Condoedge\Ai\Support;

/**
 * The fence around untrusted content in a prompt (the user's words, retrieved text): the
 * model reads it as data, and a forged opening or closing tag inside cannot break out.
 * The prompt sections ({@see \Condoedge\Ai\Services\PromptSections\BasePromptSection::spotlight()})
 * and the focused calls share it.
 */
final class Spotlight
{
    /**
     * @param string $tag e.g. 'user_question' or 'retrieved_document'
     * @param array<string, string|int> $attributes e.g. ['id' => 3]
     */
    public static function wrap(string $text, string $tag, array $attributes = []): string
    {
        $attrString = '';
        foreach ($attributes as $key => $value) {
            $attrString .= ' ' . $key . '=' . $value;
        }

        return "<{$tag}{$attrString}>\n" . self::neutralizeDelimiterTags($text, $tag) . "\n</{$tag}>";
    }

    /**
     * Case- and whitespace-tolerant (`</user_question >`, `< USER_QUESTION>`); the leading `<`
     * becomes `<\` so the model still sees the text but it no longer acts as a delimiter.
     */
    private static function neutralizeDelimiterTags(string $text, string $tag): string
    {
        $pattern = '/<(\s*\/?\s*' . preg_quote($tag, '/') . '\s*)>/i';

        return preg_replace($pattern, '<\\\\$1>', $text);
    }
}
