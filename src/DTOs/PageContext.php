<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

/**
 * Page-context awareness — "which page/record the chat was opened from".
 *
 * A readonly, side-effect-free DTO carrying the soft prior a host page hands the
 * assistant: the current route, the record on screen (type + id + human label), and
 * the visible/whitelisted filters. It is a SOFT PRIOR, never a hard filter — it seeds
 * entity-selection ranking and adds one retrieval query angle without excluding any
 * cross-record answer.
 *
 * Threaded additively: every call site defaults pageContext to null. The object itself
 * never crosses the Kompo prop boundary (it would not survive serialization) — a host
 * passes the plain array shape and the surface rebuilds it via fromArray().
 */
final class PageContext
{
    /**
     * @param string|null            $route      Current route name, e.g. 'units.show'.
     * @param string|null            $entityType Short entity label, e.g. 'Unit'.
     * @param int|string|null        $entityId   Id of the record on screen (numeric key or slug/UUID).
     * @param array<string,mixed>    $filters    Whitelisted visible filters, e.g. ['status' => 'active'].
     * @param string|null            $label      Human-readable record label, e.g. 'Unit 402'.
     */
    public function __construct(
        public readonly ?string $route = null,
        public readonly ?string $entityType = null,
        public readonly int|string|null $entityId = null,
        public readonly array $filters = [],
        public readonly ?string $label = null,
    ) {
    }

    /**
     * Rebuild a PageContext from the array shape a host passes across the Kompo prop
     * boundary. Tolerant of missing keys (each falls back to the constructor default)
     * and a malformed 'filters' value (coerced to an empty array).
     *
     * @param array<string,mixed> $a
     */
    public static function fromArray(array $a): self
    {
        return new self(
            route: $a['route'] ?? null,
            entityType: $a['entity_type'] ?? null,
            entityId: $a['entity_id'] ?? null,
            filters: isset($a['filters']) && is_array($a['filters']) ? $a['filters'] : [],
            label: $a['label'] ?? null,
        );
    }

    /**
     * @return array{route:?string,entity_type:?string,entity_id:int|string|null,filters:array<string,mixed>,label:?string}
     */
    public function toArray(): array
    {
        return [
            'route' => $this->route,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'filters' => $this->filters,
            'label' => $this->label,
        ];
    }
}
