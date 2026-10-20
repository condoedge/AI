<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

/**
 * AssistantIdentity
 *
 * Single, reusable renderer for the model-agnostic assistant identity defined in
 * config('ai.identity'). Used by every prompt path (query generation, response
 * generation, and the chat service) so the assistant presents ONE consistent,
 * project-branded persona and never discloses the underlying LLM model/vendor.
 *
 * Nothing here is provider-specific or condo-specific: everything is config-driven.
 */
class AssistantIdentity
{
    /**
     * Resolve the assistant's display name (e.g. "Acme Assistant").
     */
    public static function name(): string
    {
        $configured = config('ai.identity.name');
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return self::project() . ' Assistant';
    }

    /**
     * Resolve the project/brand name the assistant represents.
     */
    public static function project(): string
    {
        return (string) (config('ai.project.name') ?: config('app.name', 'Application'));
    }

    /**
     * The persona line(s) with {name}/{project} interpolated.
     */
    public static function persona(): string
    {
        $persona = (string) config(
            'ai.identity.persona',
            'You are {name}, the assistant for {project}.'
        );

        return self::interpolate($persona);
    }

    /**
     * The non-disclosure rule with {name}/{project} interpolated.
     */
    public static function nonDisclosure(): string
    {
        $rule = (string) config('ai.identity.non_disclosure', '');

        return self::interpolate($rule);
    }

    /**
     * The language policy line (defaults to answering in the user's language).
     */
    public static function languagePolicy(): string
    {
        return (string) config('ai.identity.language_policy', "Answer in the user's language.");
    }

    /**
     * Render the full identity block used as the leading system framing.
     *
     * @param bool $includeLanguagePolicy Include the multilingual instruction line.
     */
    public static function render(bool $includeLanguagePolicy = true): string
    {
        $lines = [];

        $persona = self::persona();
        if ($persona !== '') {
            $lines[] = $persona;
        }

        if ($includeLanguagePolicy) {
            $language = self::languagePolicy();
            if ($language !== '') {
                $lines[] = $language;
            }
        }

        $nonDisclosure = self::nonDisclosure();
        if ($nonDisclosure !== '') {
            $lines[] = $nonDisclosure;
        }

        return implode("\n", $lines);
    }

    /**
     * Replace {name} and {project} placeholders.
     */
    public static function interpolate(string $text): string
    {
        return strtr($text, [
            '{name}' => self::name(),
            '{project}' => self::project(),
        ]);
    }
}
