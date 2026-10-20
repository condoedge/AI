<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * link / button — generative-UI built-in.
 *
 * Returns a navigation-link INTENT (to a host route or URL); the presenter turns it into a
 * _Link/_Button. The LLM passes the target route name (resolved via Laravel's route() helper)
 * or an absolute/relative URL.
 *
 * Link grammar:
 *   [Go to dashboard](action://link?route=dashboard)
 *   [Open invoice](action://link?url=/invoices/123&style=button)
 *
 * Params:
 *   - route: named route to resolve (optional).
 *   - url:   raw URL (optional, used if route is absent).
 *   - params: route params (optional).
 *   - style: 'link' (default) or 'button'.
 */
class LinkAction extends AbstractBuiltInAction
{
    public function key(): string
    {
        return 'link';
    }

    public function label(): string
    {
        return 'Open link';
    }

    public function description(): string
    {
        return 'Render a clickable link or button to a route or URL.';
    }

    public function inputSchema(): array
    {
        return [
            'route' => ['type' => 'string', 'label' => 'Named route'],
            'url' => ['type' => 'string', 'label' => 'URL'],
            'params' => ['type' => 'array', 'label' => 'Route params'],
            'style' => ['type' => 'string', 'default' => 'link', 'label' => 'Link or button'],
        ];
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $text = $context->text ?? $this->label();
        $route = $input['route'] ?? null;
        $url = $input['url'] ?? null;
        $params = $input['params'] ?? [];
        $style = $input['style'] ?? 'link';

        $href = $this->resolveHref($route, $url, $params);
        if ($href === null) {
            return AiActionResult::error('Cannot render link: no route or URL provided.');
        }

        return AiActionResult::element(
            ['action' => 'link', 'href' => $href, 'text' => $text, 'style' => $style],
            "Provided a link to {$href}."
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveHref(?string $route, ?string $url, array $params): ?string
    {
        if ($route && function_exists('route')) {
            try {
                return route($route, $params);
            } catch (\Throwable $e) {
                // Fall through to URL.
            }
        }

        if (is_string($url) && $url !== '') {
            return $url;
        }

        return null;
    }
}
