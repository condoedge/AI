<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\Attach;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\Support\Audiences;
use Condoedge\Ai\Support\PipelineObserverScope;
use Condoedge\Ai\Support\TokenUsage;
use Illuminate\Support\Facades\Route;

/**
 * The pages a document answer may offer as buttons: declared in setup
 * (ai.navigation.pages), never read from documents.
 *
 *   'navigation' => ['max' => 2, 'pages' => [
 *       'products' => ['route' => 'catalog.products', 'label' => 'Products',
 *                      'description' => 'Add or edit the products.',
 *                      'audiences' => ['staff'], 'authorize' => fn ($user) => ...,
 *                      'sections' => ['sec-price' => ['label' => 'products.price', 'conditional' => 0]]],
 *   ]]
 *
 * Code keeps the pages this user may open now: in their audience (none listed = every
 * audience), allowed by the entry's `authorize` — a closure or a [class, method] pair (required: an entry without one is never
 * offered), on a route that exists and needs no parameter. The model then picks at most
 * `max` of them by key, from that closed list; code keeps only valid keys. A page with
 * `sections` lets the model add one of that page's section ids; code drops any other. A page's
 * `task` (a task key, or a list of them) puts each task's start chip before its button, in order,
 * when the user may start it.
 */
final class NavigationPages
{
    public const STAGE = 'navigation';

    private const ANSWER_CHARS = 1500;

    public function __construct(private readonly ?LlmProviderInterface $llm = null)
    {
    }

    /** @return array<string, array{route: string, label: string, description: string, task?: list<string>, sections?: array<string, array{label: string, conditional: bool}>}> */
    public static function offeredTo(mixed $user): array
    {
        $audience = Audiences::of($user)?->key;
        $offered = [];
        foreach ((array) config('ai.navigation.pages', []) as $key => $page) {
            $gate = is_array($page) ? self::gate($page['authorize'] ?? null) : null;
            if (!is_string($key) || $gate === null || !is_string($page['route'] ?? null)) {
                continue;
            }
            $audiences = (array) ($page['audiences'] ?? []);
            if ($audiences !== [] && !in_array($audience, $audiences, true)) {
                continue;
            }
            if (!self::routeWithoutParameters($page['route'])) {
                continue;
            }
            try {
                if (!$gate($user)) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            $offered[$key] = [
                'route' => $page['route'],
                // A host translation key gives the user's own UI label; plain text passes through.
                'label' => (string) __((string) ($page['label'] ?? $key)),
                'description' => (string) ($page['description'] ?? $page['label'] ?? $key),
            ];
            $tasks = array_values(array_filter((array) ($page['task'] ?? []), 'is_string'));
            if ($tasks !== []) {
                $offered[$key]['task'] = $tasks;
            }
            $sections = self::sections($page['sections'] ?? null);
            if ($sections !== []) {
                $offered[$key]['sections'] = $sections;
            }
        }

        return $offered;
    }

    /** @return list<string> the tasks named by the pages offered to $user that open $route */
    public static function tasksOn(string $route, mixed $user): array
    {
        $tasks = [];
        foreach (self::offeredTo($user) as $page) {
            if ($page['route'] === $route) {
                array_push($tasks, ...($page['task'] ?? []));
            }
        }

        return array_values(array_unique($tasks));
    }

    public static function max(): int
    {
        return max(0, (int) config('ai.navigation.max', 2));
    }

    /**
     * The pages the answer should offer, in the model's order, each with the section to land on (or null).
     *
     * @param array<string, array{route: string, label: string, description: string, sections?: array<string, array{label: string, conditional: bool}>}> $pages
     * @return array<string, ?string>
     */
    public function pick(string $question, string $answer, array $pages, string $language = '', ?PipelineObserverInterface $observer = null): array
    {
        $max = self::max();
        if ($pages === [] || $max === 0 || $this->llm === null || trim($answer) === '') {
            return [];
        }
        $observer ??= PipelineObserverScope::current();

        $lines = [];
        $sectioned = false;
        foreach ($pages as $key => $page) {
            $lines[] = "- {$key}: {$page['description']}";
            foreach ($page['sections'] ?? [] as $id => $section) {
                $lines[] = "  - {$id}: {$section['label']}" . ($section['conditional'] ? ' (not always present)' : '');
                $sectioned = true;
            }
        }
        $messages = [
            ['role' => 'system', 'content' => "You choose which pages of the application to offer as buttons under an answer. "
                . "Choose only keys from the PAGES list. Choose a page only when the answer sends the user there or tells them to do something "
                . "that is done there; choose none when no page fits. At most {$max}, the most useful first. "
                . ($sectioned
                    ? 'A page may list its sections under it: when the answer is about one of them, give its id with the page '
                        . '(prefer a section not marked "not always present"), else null. Return JSON: {"pages": [{"page": key, "section": id or null}]}.'
                    : 'Return JSON: {"pages": [keys]}.')],
            ['role' => 'user', 'content' => 'QUESTION: ' . trim($question) . "\n\nANSWER:\n" . mb_substr(trim($answer), 0, self::ANSWER_CHARS)
                . "\n\nPAGES:\n" . implode("\n", $lines)],
        ];
        $observer->promptBuilt(self::STAGE, $messages);

        try {
            $reply = $this->llm->chatJson($messages, [
                'temperature' => 0.0,
                'max_tokens' => $sectioned ? 120 : 60,
                'json_schema' => ['name' => 'navigation_pages', 'schema' => [
                    'type' => 'object',
                    'properties' => ['pages' => ['type' => 'array', 'items' => $sectioned ? self::sectionedItem($pages) : ['type' => 'string', 'enum' => array_keys($pages)]]],
                    'required' => ['pages'],
                    'additionalProperties' => false,
                ]],
            ]);
        } finally {
            TokenUsage::report($observer, self::STAGE, $this->llm);
        }

        $picked = [];
        foreach ((array) (is_object($reply) ? ($reply->pages ?? []) : ($reply['pages'] ?? [])) as $item) {
            $item = is_object($item) ? (array) $item : $item;
            $key = is_array($item) ? ($item['page'] ?? null) : $item;
            if (!is_string($key) || !isset($pages[$key]) || array_key_exists($key, $picked)) {
                continue;
            }
            $section = is_array($item) ? ($item['section'] ?? null) : null;
            $picked[$key] = is_string($section) && isset($pages[$key]['sections'][$section]) ? $section : null;
        }

        return array_slice($picked, 0, $max, true);
    }

    /**
     * One schema branch per page with sections (its own ids or null), one for all the others (null).
     *
     * @param array<string, array{sections?: array<string, mixed>}> $pages
     * @return array<string, mixed>
     */
    private static function sectionedItem(array $pages): array
    {
        $branch = static fn (array $keys, array $section): array => [
            'type' => 'object',
            'properties' => ['page' => ['type' => 'string', 'enum' => $keys], 'section' => $section],
            'required' => ['page', 'section'],
            'additionalProperties' => false,
        ];
        $plain = array_keys(array_filter($pages, static fn (array $page) => empty($page['sections'])));
        $branches = $plain === [] ? [] : [$branch($plain, ['type' => 'null'])];
        foreach ($pages as $key => $page) {
            if (!empty($page['sections'])) {
                $branches[] = $branch([$key], ['type' => ['string', 'null'], 'enum' => [...array_keys($page['sections']), null]]);
            }
        }

        return ['anyOf' => $branches];
    }

    /**
     * A page's declared sections: id => label (a host translation key) and whether it is always present.
     *
     * @return array<string, array{label: string, conditional: bool}>
     */
    private static function sections(mixed $declared): array
    {
        $sections = [];
        foreach (is_array($declared) ? $declared : [] as $id => $section) {
            if (is_string($id) && is_array($section)) {
                $sections[$id] = ['label' => (string) __((string) ($section['label'] ?? $id)), 'conditional' => (bool) ($section['conditional'] ?? 0)];
            }
        }

        return $sections;
    }

    /** A closure, or a [class, method] pair (a cached config holds no closure) resolved through the container. */
    private static function gate(mixed $authorize): ?callable
    {
        if ($authorize instanceof \Closure) {
            return $authorize;
        }
        if (is_array($authorize) && count($authorize) === 2 && is_string($authorize[0] ?? null) && is_string($authorize[1] ?? null)
            && method_exists($authorize[0], $authorize[1])) {
            return static fn (mixed $user) => app($authorize[0])->{$authorize[1]}($user);
        }

        return null;
    }

    private static function routeWithoutParameters(string $name): bool
    {
        try {
            $route = Route::getRoutes()->getByName($name);
        } catch (\Throwable) {
            return false;
        }
        // A required parameter is a `{name}` without `?` (`orders/{id}` never qualifies).
        return $route !== null && preg_match('/\{\w+\}/', $route->uri()) !== 1;
    }
}
