<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Digest;

/**
 * Reader for the host-config attention-source registry (`ai.attention_sources`)
 * and its companion settings (`ai.digest`).
 *
 * WHITE-LABEL SEAM: the package ships this reader and an EMPTY registry; every
 * host specific (labels, enum literals, cypher, ordering semantics) lives in
 * the HOST's published config — the same split as `ai.calculations`.
 *
 * Strictness: an entry only counts as a usable source when it carries both a
 * label and a cypher; a fallback is only usable as a LABELED pair (design
 * invariant: fallback rows must never masquerade as primary rows, so a
 * fallback_cypher without its fallback_label is stripped). Misconfigured
 * entries degrade to "not configured" instead of a broken turn.
 *
 * Config is read LAZILY when not injected, so a singleton consumer always sees
 * the live registry (and tests can mutate config after construction).
 */
class AttentionSourceRegistry
{
    /**
     * @param array<string, mixed>|null $sources Injected registry (null = read live config)
     * @param array<string, mixed>|null $digest  Injected ai.digest settings (null = read live config)
     */
    public function __construct(
        private readonly ?array $sources = null,
        private readonly ?array $digest = null,
    ) {
    }

    /**
     * Enabled, structurally valid sources in config order, keyed by source key,
     * each normalized to the full schema shape (absent optionals become null).
     *
     * @return array<string, array<string, mixed>>
     */
    public function enabledSources(): array
    {
        $raw = $this->sources ?? $this->configArray('ai.attention_sources');

        $enabled = [];
        foreach ($raw as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['enabled'] ?? true) === false) {
                continue;
            }

            $label = trim((string) ($entry['label'] ?? ''));
            $cypher = trim((string) ($entry['cypher'] ?? ''));
            if ($label === '' || $cypher === '') {
                continue; // unusable — degrade to "not configured", never a broken turn
            }

            $fallbackCypher = trim((string) ($entry['fallback_cypher'] ?? ''));
            $fallbackLabel = trim((string) ($entry['fallback_label'] ?? ''));
            if ($fallbackCypher === '' || $fallbackLabel === '') {
                // Mandatory pair: a fallback without its label would render
                // fallback rows as primary rows — stripped instead.
                $fallbackCypher = null;
                $fallbackLabel = null;
            }

            $limit = $entry['limit'] ?? null;

            $enabled[(string) $key] = [
                'label' => $label,
                'description' => trim((string) ($entry['description'] ?? '')),
                'cypher' => $cypher,
                'fallback_cypher' => $fallbackCypher,
                'fallback_label' => $fallbackLabel,
                'limit' => is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null,
                'action_key' => $this->stringOrNull($entry['action_key'] ?? null),
                'entity' => $this->stringOrNull($entry['entity'] ?? null),
                'empty_text' => $this->stringOrNull($entry['empty_text'] ?? null),
            ];
        }

        return $enabled;
    }

    public function hasEnabled(): bool
    {
        return $this->enabledSources() !== [];
    }

    /**
     * The sources a digest run actually executes: the first `max_sources`
     * enabled entries, in config order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sourcesToRun(): array
    {
        return array_slice($this->enabledSources(), 0, $this->maxSources(), true);
    }

    /**
     * Amendment B2 — cap overflow must be DATA, never silence: the keys of
     * enabled sources dropped by `max_sources` (a host-disabled entry is
     * deliberate configuration and never counts as skipped).
     *
     * @return string[]
     */
    public function skippedSourceKeys(): array
    {
        return array_values(array_slice(array_keys($this->enabledSources()), $this->maxSources()));
    }

    public function maxSources(): int
    {
        return max(1, (int) ($this->digestSettings()['max_sources'] ?? 8));
    }

    public function defaultRowCap(): int
    {
        return max(1, (int) ($this->digestSettings()['default_row_cap'] ?? 5));
    }

    /**
     * @return array<string, mixed>
     */
    private function digestSettings(): array
    {
        return $this->digest ?? $this->configArray('ai.digest');
    }

    /**
     * @return array<string, mixed>
     */
    private function configArray(string $key): array
    {
        if (!function_exists('config')) {
            return [];
        }

        $value = config($key, []);

        return is_array($value) ? $value : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
