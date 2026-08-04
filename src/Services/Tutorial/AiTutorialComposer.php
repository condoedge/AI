<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Tutorial;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\DTOs\TutorialPlan;
use Condoedge\Ai\DTOs\TutorialStep;
use Condoedge\Ai\Services\LlmResolver;
use Condoedge\Ai\Services\UI\SafeMarkdownRenderer;

/**
 * AI-composed guided tutorials ("show me how to X").
 *
 * Turns a how-do-I request + the current PageContext (the existing soft prior)
 * into a structured, ordered TutorialPlan whose toArray() shape the front-end
 * window.TutorialEngine consumes unchanged — the same engine the static
 * Condoedge\Utils tutorials use.
 *
 * Safety is enforced on the model's output, not trusted:
 *   - step count is clamped to config('ai.chat.tutorials.max_steps');
 *   - each step's `intro` is rendered from markdown to safe HTML via
 *     SafeMarkdownRenderer (XSS-hygienic), never injected raw;
 *   - each step's `element` is whitelisted against a HOST-PROVIDED selector map,
 *     so the AI can never point a step at arbitrary DOM — a non-whitelisted
 *     selector is dropped to a floating step rather than honored.
 *
 * Provider is chosen via LlmResolver::forRole() so the composer is model-agnostic.
 * When no provider is reachable (no API key / offline) OR the model returns
 * nothing usable, compose() returns {@see TutorialPlan::skipped()} — it NEVER
 * throws, so a failed compose degrades to an ordinary text answer.
 *
 * This is the package-side abstraction. Rendering an actual in-app walkthrough
 * requires host wiring (a per-page selector/affordance map + a transport carrier
 * that calls window.TutorialEngine.start(plan)); see
 * docs/audit/next-level/ws-c-tutorial-host-wiring.md.
 */
class AiTutorialComposer
{
    private const DEFAULT_MAX_STEPS = 5;

    public function __construct(
        private readonly ?LlmResolver $llmResolver = null,
        private readonly array $config = [],
        private readonly ?SafeMarkdownRenderer $markdown = null,
    ) {
    }

    /**
     * Compose a tutorial plan for the request, grounded in the page context.
     *
     * @param string                $request   The user's "how do I X" question.
     * @param PageContext|null      $page      The page/record the chat was opened from.
     * @param array<int, string>    $selectors The host's whitelist of CSS selectors the
     *                                          AI may anchor a step to (empty = floating-only).
     */
    public function compose(string $request, ?PageContext $page = null, array $selectors = []): TutorialPlan
    {
        if (trim($request) === '') {
            return TutorialPlan::skipped('empty request — nothing to compose');
        }

        $llm = $this->resolveComposer();
        if ($llm === null) {
            return TutorialPlan::skipped('no composer provider / api key');
        }

        try {
            $response = $llm->chatJson(
                [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => $this->userPrompt($request, $page, $selectors)],
                ],
                [
                    'temperature' => (float) ($this->config['temperature'] ?? 0.2),
                    'max_tokens' => (int) ($this->config['max_tokens'] ?? 900),
                ]
            );

            return $this->parsePlan($response, $page, $selectors);
        } catch (\Throwable $e) {
            return TutorialPlan::skipped('compose error: ' . $e->getMessage());
        }
    }

    /**
     * Parse a raw LLM response into a clamped, sanitized, whitelisted TutorialPlan.
     *
     * Tolerant: missing keys default; malformed input → skipped. Public so it can
     * be unit-tested with a stubbed payload (no network).
     *
     * @param array|object|string $response
     * @param array<int, string>  $selectors Host whitelist; an `element` not in it is nulled.
     */
    public function parsePlan(array|object|string $response, ?PageContext $page = null, array $selectors = []): TutorialPlan
    {
        $data = $this->normalize($response);
        if ($data === null) {
            return TutorialPlan::skipped('unparseable composer response');
        }

        $rawSteps = $data['steps'] ?? null;
        if (!is_array($rawSteps)) {
            return TutorialPlan::skipped('composer response had no steps');
        }

        $whitelist = $this->normalizeWhitelist($selectors);
        $maxSteps = $this->maxSteps();

        $steps = [];
        foreach ($rawSteps as $raw) {
            if (count($steps) >= $maxSteps) {
                break;
            }

            $step = $this->buildStep((array) $raw, $whitelist);
            if ($step !== null) {
                $steps[] = $step;
            }
        }

        if ($steps === []) {
            return TutorialPlan::skipped('composer produced no usable steps');
        }

        $title = $this->resolveTitle($data['title'] ?? null, $page);

        return new TutorialPlan(title: $title, steps: $steps);
    }

    /**
     * Build a single safe step, or null when it has no usable body. The `intro` is
     * rendered to safe HTML; the `element` is kept only when whitelisted.
     *
     * @param array<string, mixed> $raw
     * @param array<string, true>  $whitelist
     */
    private function buildStep(array $raw, array $whitelist): ?TutorialStep
    {
        $intro = trim((string) ($raw['intro'] ?? $raw['html'] ?? ''));
        if ($intro === '') {
            return null;
        }

        $element = $this->whitelistElement($raw['element'] ?? null, $whitelist);

        $showIf = isset($raw['showIf']) && is_string($raw['showIf']) && $raw['showIf'] !== ''
            ? $raw['showIf']
            : null;

        return new TutorialStep(
            intro: $this->renderer()->render($intro),
            element: $element,
            showIf: $showIf,
        );
    }

    /**
     * Keep an `element` only if it is a non-empty string present in the host
     * whitelist; otherwise null it (drop to a floating step). The AI can never
     * point a step at a selector the host did not authorize.
     *
     * @param array<string, true> $whitelist
     */
    private function whitelistElement(mixed $element, array $whitelist): ?string
    {
        if (!is_string($element)) {
            return null;
        }

        $element = trim($element);
        if ($element === '') {
            return null;
        }

        return isset($whitelist[$element]) ? $element : null;
    }

    /**
     * @param array<int, string> $selectors
     * @return array<string, true>
     */
    private function normalizeWhitelist(array $selectors): array
    {
        $map = [];
        foreach ($selectors as $selector) {
            if (is_string($selector) && trim($selector) !== '') {
                $map[trim($selector)] = true;
            }
        }

        return $map;
    }

    private function resolveTitle(mixed $title, ?PageContext $page): string
    {
        $title = is_string($title) ? trim($title) : '';
        if ($title !== '') {
            return $title;
        }

        if ($page !== null && $page->label !== null && $page->label !== '') {
            return $page->label;
        }

        return '';
    }

    private function maxSteps(): int
    {
        $max = (int) ($this->config['max_steps'] ?? self::DEFAULT_MAX_STEPS);

        return $max > 0 ? $max : self::DEFAULT_MAX_STEPS;
    }

    private function renderer(): SafeMarkdownRenderer
    {
        return $this->markdown ?? new SafeMarkdownRenderer();
    }

    /**
     * Resolve the composer provider, or null when none is usable (no API key).
     * An offline host (no key / unreachable) degrades gracefully to a skipped plan.
     */
    private function resolveComposer(): ?LlmProviderInterface
    {
        if ($this->llmResolver === null) {
            return null;
        }

        try {
            $role = (string) ($this->config['role'] ?? 'response_generation');
            $llm = $this->llmResolver->forRole($role);
        } catch (\Throwable $e) {
            return null;
        }

        $provider = $llm->getProvider();
        $apiKey = config("ai.llm.{$provider}.api_key");
        if (empty($apiKey) || strlen((string) $apiKey) < 10) {
            return null;
        }

        return $llm;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You compose SHORT, in-app guided walkthroughs ("show me how to X") for an
application's UI. You return an ordered list of steps that highlight on-screen
elements and tell the user what to do at each one.

Rules:
- Ground every step in the CURRENT PAGE described in the user message. Do not
  invent screens, buttons or navigation that are not implied by the page.
- For each step, set `element` to one of the ALLOWED SELECTORS exactly as given,
  or omit `element` for an introductory/floating step. NEVER invent a selector
  that is not in the allowed list.
- Keep `intro` to one or two short sentences. Plain markdown only (bold/italic).
- Order steps in the sequence the user would actually perform them.

Respond with STRICT JSON only, no prose:
{
  "title": "short imperative title",
  "steps": [
    { "element": "<one allowed selector or omit>", "intro": "what to do here" }
  ]
}
PROMPT;
    }

    /**
     * @param array<int, string> $selectors
     */
    private function userPrompt(string $request, ?PageContext $page, array $selectors): string
    {
        $pageBlock = $this->describePage($page);

        $whitelist = $this->normalizeWhitelist($selectors);
        $allowed = $whitelist === []
            ? '(none — use floating steps with no element)'
            : implode("\n", array_map(static fn ($s) => '- ' . $s, array_keys($whitelist)));

        return "REQUEST:\n{$request}\n\n"
            . "CURRENT PAGE:\n{$pageBlock}\n\n"
            . "ALLOWED SELECTORS (use these verbatim for `element`, or omit):\n{$allowed}\n\n"
            . "Compose the walkthrough.";
    }

    private function describePage(?PageContext $page): string
    {
        if ($page === null) {
            return '(no page context)';
        }

        $lines = [];
        if ($page->label !== null && $page->label !== '') {
            $lines[] = 'Record: ' . $page->label;
        }
        if ($page->entityType !== null && $page->entityType !== '') {
            $lines[] = 'Type: ' . $page->entityType;
        }
        if ($page->route !== null && $page->route !== '') {
            $lines[] = 'Screen (route): ' . $page->route;
        }

        return $lines === [] ? '(no page context)' : implode("\n", $lines);
    }

    /**
     * @param array|object|string $response
     * @return array<string, mixed>|null
     */
    private function normalize(array|object|string $response): ?array
    {
        if (is_string($response)) {
            $trimmed = trim($response);
            $trimmed = preg_replace('/^```(?:json)?|```$/m', '', $trimmed) ?? $trimmed;
            $decoded = json_decode(trim($trimmed), true);

            return is_array($decoded) ? $decoded : null;
        }

        if (is_object($response)) {
            $arr = json_decode(json_encode($response) ?: '[]', true);

            return is_array($arr) ? $arr : null;
        }

        return $response;
    }
}
