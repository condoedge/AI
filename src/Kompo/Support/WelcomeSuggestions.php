<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Support;

use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\Tasks\TaskOrchestrator;
use Condoedge\Ai\Support\Audiences;

/**
 * Resolves the welcome-screen example questions (config-only, no LLM call).
 *
 * Priority:
 *  1. Nothing when the show_suggestions toggle is off.
 *  2. config('ai.chat.page_examples.{EntityType}') when the chat was opened from a page
 *     whose page_context.entity_type matches a configured key (host-provided, white-label).
 *  3. The asker's audience's own list (ai.audiences.map.<key>.example_questions).
 *  4. The global example_questions list from settings/config.
 *  5. Empty when nothing is configured — the caller renders no suggestions block.
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
        // The page's entity type first, else its route name (PageContext::descriptionKey()).
        $questions = self::sanitize(self::pageExamplesFor($pageContext?->descriptionKey()));

        if (empty($questions)) {
            $questions = self::sanitize(Audiences::exampleQuestions(self::user()) ?? []);
        }
        if (empty($questions)) {
            $questions = self::sanitize($settings->exampleQuestions());
        }

        // Host config strings go through the translator: a host language file translates
        // them (the English string is the key); without a translation they show as written.
        return array_map(
            static fn (string $question): string => (string) __($question),
            array_slice($questions, 0, max(0, $settings->maxSuggestions()))
        );
    }

    /**
     * The start chips of the tasks (ai.tasks.definitions) this user may start: none unless
     * ai.tasks.enabled. A task starts only from such a chip, never from typed text.
     *
     * @return array<string, string> task key => chip label
     */
    public static function tasks(mixed $user): array
    {
        if ($user === null || !TaskOrchestrator::configured()) {
            return [];
        }

        $chips = [];
        foreach (TaskOrchestrator::registered() as $key => $definition) {
            if ($definition->startableBy($user)) {
                $chips[$key] = (string) __($definition->label());
            }
        }

        return $chips;
    }

    /**
     * The welcome screen's task chips: the startable tasks ai.tasks.welcome lists, in its order;
     * every startable task when it lists none.
     *
     * @return array<string, string> task key => chip label
     */
    public static function welcomeTasks(mixed $user): array
    {
        $tasks = self::tasks($user);
        $listed = array_values(array_filter((array) config('ai.tasks.welcome', []), 'is_string'));
        if ($listed === []) {
            return $tasks;
        }
        $chips = [];
        foreach ($listed as $key) {
            if (isset($tasks[$key])) {
                $chips[$key] = $tasks[$key];
            }
        }

        return $chips;
    }

    private static function user(): mixed
    {
        try {
            return function_exists('auth') && auth()->check() ? auth()->user() : null;
        } catch (\Throwable) {
            return null;
        }
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
    protected static function pageExamplesFor(?string $key): array
    {
        if ($key === null || $key === '' || !function_exists('app') || !app()->bound('config')) {
            return [];
        }

        // Indexed, not a dot path: a route name ("campaign.page.products") holds dots.
        $pageExamples = ((array) config('ai.chat.page_examples', []))[$key] ?? null;

        return is_array($pageExamples) ? $pageExamples : [];
    }
}
