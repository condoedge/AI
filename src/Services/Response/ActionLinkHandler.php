<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Kompo\Actions\ApprovalCard;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;

/**
 * Handler for entity:// and action:// protocol links.
 *
 * Processes AI-generated action links and creates Kompo elements via the
 * {@see AiActionRegistry} + {@see ActionDispatcher} (which back today's closure config
 * entries through the ConfigAiAction adapter — fully backward-compatible).
 *
 * Link formats (params are OPTIONAL — param-less links keep working unchanged):
 * - [text](entity://EntityType/id/action_key)
 * - [text](entity://EntityType/id/action_key?field=default&other=2)
 * - [text](action://action_key)
 * - [text](action://action_key?unit_id=123&amount=450)
 *
 * Params are parsed from the query string into an assoc array, then FILTERED against the
 * resolved action's inputSchema() — unknown/forbidden params are DROPPED. Read-only actions
 * render immediately; APPROVAL-REQUIRED (mutating) actions render an {@see ApprovalCard}
 * instead of executing inline.
 */
class ActionLinkHandler extends AbstractContentLinkHandler
{
    // Capture groups: 1=text 2=EntityType 3=id 4=action_key (no '/' '?' ')') 5=query string (optional)
    private const ENTITY_PATTERN = '/\[([^\]]+)\]\(entity:\/\/([^\/]+)\/([^\/]+)\/([^\)\?\/]+)(?:\?([^\)]*))?\)/';

    // Capture groups: 1=text 2=action_key (no '?' ')') 3=query string (optional)
    private const ACTION_PATTERN = '/\[([^\]]+)\]\(action:\/\/([^\)\?]+)(?:\?([^\)]*))?\)/';

    private ?ActionDispatcher $dispatcher;

    public function __construct(
        private ?EntityAutoDiscovery $discovery = null,
        ?ActionDispatcher $dispatcher = null,
    ) {
        $this->discovery = $discovery ?? app(EntityAutoDiscovery::class);
        // Resolve a dispatcher with the shared registry; fall back to a self-built one with
        // SAFE DEFAULTS so the handler works even before the main session wires DI.
        $this->dispatcher = $dispatcher ?? $this->resolveDispatcher();
    }

    public function getPatterns(): array
    {
        return [self::ENTITY_PATTERN, self::ACTION_PATTERN];
    }

    protected function getDeduplicationKey(array $match): string
    {
        // Entity link: [text](entity://Type/id/action[?query])
        if ($this->isEntityMatch($match)) {
            $query = $match[5] ?? '';
            return "entity-{$match[2]}-{$match[3]}-{$match[4]}-{$query}";
        }

        // Generic action: [text](action://action_key[?query])
        $query = $match[3] ?? '';
        return "generic-{$match[2]}-{$query}";
    }

    protected function createElementForMatch(array $match, array $context): mixed
    {
        if ($this->isEntityMatch($match)) {
            return $this->renderEntity(
                text: $match[1],
                entityType: $match[2],
                entityId: $match[3],
                actionKey: $match[4],
                params: $this->parseQueryString($match[5] ?? ''),
                context: $context,
            );
        }

        return $this->renderGeneric(
            text: $match[1],
            actionKey: $match[2],
            params: $this->parseQueryString($match[3] ?? ''),
            context: $context,
        );
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $context
     */
    private function renderEntity(
        string $text,
        string $entityType,
        string $entityId,
        string $actionKey,
        array $params,
        array $context
    ): mixed {
        $action = $this->dispatcher->registry()->getEntity($entityType, $actionKey);
        if ($action === null) {
            return null;
        }

        $ctx = array_merge($context, [
            'text' => $text,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        return $this->renderResult($this->dispatcher->dispatch($action, $params, $ctx));
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $context
     */
    private function renderGeneric(string $text, string $actionKey, array $params, array $context): mixed
    {
        $action = $this->dispatcher->registry()->getGeneric($actionKey);
        if ($action === null) {
            return null;
        }

        $ctx = array_merge($context, ['text' => $text]);

        return $this->renderResult($this->dispatcher->dispatch($action, $params, $ctx));
    }

    /**
     * Turn an AiActionResult into the element to drop into the message.
     *
     * - Approval-required (deferred) results render an ApprovalCard instead of executing.
     * - Errors render nothing (the caller already logs / degrades).
     * - Plain renderables (Kompo elements) pass straight through.
     */
    private function renderResult(?AiActionResult $result): mixed
    {
        if ($result === null || $result->isError) {
            return null;
        }

        $renderable = $result->renderable;

        // Deferred mutating action → confirmation card, NEVER inline execution.
        if (is_array($renderable) && !empty($renderable['pending'])) {
            return ApprovalCard::make($renderable);
        }

        return $renderable;
    }

    protected function getStripReplacement(array $match): string
    {
        // Return the link text (first capture group)
        return $match[1];
    }

    /**
     * Parse an action link query string into an assoc array.
     *
     * Uses parse_str so url-encoded values and nested foo[]=… work; returns [] for empty.
     *
     * @return array<string, mixed>
     */
    private function parseQueryString(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $parsed = [];
        parse_str($query, $parsed);

        return $parsed;
    }

    /**
     * An entity match has the EntityType/id/action shape (5 numeric capture slots).
     *
     * @param array<int, string> $match
     */
    private function isEntityMatch(array $match): bool
    {
        // Entity matches always carry at least groups 1..4 (type/id/action); the generic
        // pattern only ever fills groups 1..3. Distinguish by the presence of group 4 that
        // is NOT itself a query string — entity has 4 path-ish groups before the query.
        return isset($match[4]) && $this->matchedEntityPattern($match);
    }

    /**
     * Heuristic: the entity pattern fills group[2] (type) AND group[3] (id) AND group[4]
     * (action) where group[3] is the id segment. The generic pattern fills group[2] with the
     * action key and group[3] with the query string. We detect entity links by checking the
     * raw matched string for the entity:// scheme.
     *
     * @param array<int, string> $match
     */
    private function matchedEntityPattern(array $match): bool
    {
        $full = $match[0] ?? '';
        return str_contains($full, 'entity://');
    }

    /**
     * Build a dispatcher backed by the shared registry, with safe fallbacks.
     */
    private function resolveDispatcher(): ActionDispatcher
    {
        if (function_exists('app')) {
            try {
                return app(ActionDispatcher::class);
            } catch (\Throwable $e) {
                // Container not bound yet — build a standalone instance below.
            }
        }

        return new ActionDispatcher(new AiActionRegistry());
    }
}
