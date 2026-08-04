<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Support;

use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;

/**
 * Resolves the welcome-screen example questions (config-only, no LLM call).
 *
 * Priority:
 *  1. Nothing when the show_suggestions toggle is off.
 *  2. config('ai.chat.page_examples.{EntityType}') when the chat was opened from a page
 *     whose page_context.entity_type matches a configured key (host-provided, white-label).
 *  3. The global example_questions list from settings/config.
 *  4. Empty when nothing is configured — the caller renders no suggestions block.
 *
 * The result is always capped at max_suggestions. Data is host config end to end; the
 * package never hardcodes question strings.
 */
final class WelcomeSuggestions
{
    /**
     * @return array<int,string>
     */
    public static function resolve(?PageContext $pageContext, ChatSettingsInterface $settings): array
    {
        if (!$settings->showSuggestions()) {
            return [];
        }

        // Sanitize BEFORE the fallback check so a page_examples list containing only
        // blank/non-string entries falls back to the global example_questions instead of
        // suppressing the suggestions block entirely.
        $questions = self::sanitize(self::pageExamplesFor($pageContext?->entityType));

        if (empty($questions)) {
            $questions = self::sanitize($settings->exampleQuestions());
        }

        return array_slice($questions, 0, max(0, $settings->maxSuggestions()));
    }

    /**
     * Keep only non-empty strings, trimmed (tolerate sloppy host config).
     *
     * @return array<int,string>
     */
    protected static function sanitize($questions): array
    {
        return array_values(array_filter(
            array_map(fn ($q) => is_string($q) ? trim($q) : '', (array) $questions),
            fn ($q) => $q !== '',
        ));
    }

    /**
     * The per-entity-type override list, or [] when the type is absent/unconfigured.
     *
     * @return array<int,string>
     */
    protected static function pageExamplesFor(?string $entityType): array
    {
        if ($entityType === null || $entityType === '' || !function_exists('app') || !app()->bound('config')) {
            return [];
        }

        $pageExamples = config('ai.chat.page_examples.' . $entityType);

        return is_array($pageExamples) ? $pageExamples : [];
    }
}
