<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;

/**
 * ActionInvocation
 *
 * The canonical "the model asked for this action" value object: markdown action:// /
 * entity:// regex captures via {@see fromLinkMatch()}, persisted message-metadata records
 * via {@see fromArray()}. Everything downstream —
 * registry resolve, filterParams, authorize, defer-or-run — is the one existing
 * ActionDispatcher pipeline; this type only normalizes how an invocation ARRIVES.
 *
 * Persistence hygiene: only {@see filtered()} copies (params reduced to the action's
 * inputSchema) are ever persisted in message metadata — raw model args never reach the
 * database. Render-time dispatch re-filters and re-authorizes regardless, so the persisted
 * record is data, never authority. {@see fromArray()} fails closed: a malformed record
 * revives to null and renders nothing.
 */
final class ActionInvocation
{
    /**
     * @param array<string, mixed> $params
     * @param string $text  Display label the LLM chose (link text; '' when code-attached).
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $entityType,
        public readonly int|string|null $entityId,
        public readonly array $params,
        public readonly string $text,
    ) {
    }

    /**
     * Markdown transport: the capture array from one of ActionLinkHandler's two regexes.
     * Entity matches fill four path groups before the optional query; the generic pattern
     * only fills three — the same discrimination the link handler itself uses.
     *
     * @param array<int, string> $match
     */
    public static function fromLinkMatch(array $match): self
    {
        if (isset($match[4]) && str_contains((string) ($match[0] ?? ''), 'entity://')) {
            return new self(
                key: (string) $match[4],
                entityType: (string) $match[2],
                entityId: (string) $match[3],
                params: self::parseQuery((string) ($match[5] ?? '')),
                text: (string) $match[1],
            );
        }

        return new self(
            key: (string) ($match[2] ?? ''),
            entityType: null,
            entityId: null,
            params: self::parseQuery((string) ($match[3] ?? '')),
            text: (string) ($match[1] ?? ''),
        );
    }

    /**
     * Copy with params reduced to the action's schema — the ONLY form ever persisted.
     */
    public function filtered(AiActionInterface $action, AiActionRegistry $registry): self
    {
        return new self(
            $this->key,
            $this->entityType,
            $this->entityId,
            $registry->filterParams($action, $this->params),
            $this->text,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'params' => $this->params,
            'text' => $this->text,
        ];
    }

    /**
     * Revive a persisted record. Returns null on ANY malformed shape (fail-closed render):
     * a corrupt metadata entry must render nothing, never throw mid-bubble.
     */
    public static function fromArray(mixed $a): ?self
    {
        if (!is_array($a)) {
            return null;
        }

        $key = $a['key'] ?? null;
        if (!is_string($key) || trim($key) === '') {
            return null;
        }

        $entityType = $a['entity_type'] ?? null;
        if ($entityType !== null && (!is_string($entityType) || $entityType === '')) {
            return null;
        }

        $entityId = $a['entity_id'] ?? null;
        if ($entityId !== null && !is_int($entityId) && !is_string($entityId)) {
            return null;
        }

        $params = $a['params'] ?? [];
        if (!is_array($params)) {
            return null;
        }

        $text = $a['text'] ?? '';
        if (!is_string($text)) {
            return null;
        }

        return new self($key, $entityType, $entityId, $params, $text);
    }

    /**
     * sha1(key|type|id|ksort-canonical params) — the render-side dedupe key between parsed
     * markdown links and persisted invocations. Scalars are stringified so the same logical
     * invocation fingerprints identically whether its params arrived as link-query strings
     * or as typed values from a persisted record. Excludes $text: the label is presentation, not identity.
     */
    public function fingerprint(): string
    {
        return sha1(implode('|', [
            $this->key,
            $this->entityType ?? '',
            (string) ($this->entityId ?? ''),
            (string) json_encode(self::canonical($this->params)),
        ]));
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<array-key, mixed>
     */
    private static function canonical(array $params): array
    {
        ksort($params);

        foreach ($params as $name => $value) {
            if (is_array($value)) {
                $params[$name] = self::canonical($value);
            } elseif (is_bool($value)) {
                $params[$name] = $value ? '1' : '0';
            } elseif ($value === null) {
                $params[$name] = '';
            } else {
                $params[$name] = (string) $value;
            }
        }

        return $params;
    }

    /**
     * Same query-string semantics as ActionLinkHandler::parseQueryString (parse_str, so
     * url-encoded values and nested foo[]=… work; [] for empty).
     *
     * @return array<string, mixed>
     */
    private static function parseQuery(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $parsed = [];
        parse_str($query, $parsed);

        return $parsed;
    }
}
