<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Services\Actions\BuiltIn\AskFollowUpAction;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportExcelAction;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportPdfAction;
use Condoedge\Ai\Services\Actions\BuiltIn\IdentityLinkAction;
use Condoedge\Ai\Services\Actions\BuiltIn\LinkAction;
use Condoedge\Ai\Services\Actions\BuiltIn\OpenModalAction;
use Condoedge\Ai\Services\Actions\BuiltIn\RenderTableAction;

/**
 * AiActionRegistry
 *
 * Central, config-driven registry of every action the assistant can offer. It unifies:
 *   - Legacy closure entries from config('ai.entity_actions') / config('ai.generic_actions'),
 *     each wrapped in a {@see ConfigAiAction} adapter (backward-compatible),
 *   - Built-in generative-UI actions (open_modal, render_table, link, export_excel,
 *     export_pdf) that projects get for free,
 *   - The package's internal built-in (ask_follow_up — the follow-up question chip):
 *     resolvable, never listed,
 *   - Any explicitly-registered {@see AiActionInterface} instances/classes.
 *
 * SAFE DEFAULTS: config keys may be entirely absent — the registry never throws. Missing
 * actions resolve to null and callers degrade gracefully.
 *
 * Resolution helpers let the response/render layer fetch "the actions relevant to this
 * focused_entity / these result types" without re-reading config.
 */
class AiActionRegistry
{
    /**
     * Explicitly-registered generic actions, keyed by action key.
     *
     * @var array<string, AiActionInterface>
     */
    private array $generic = [];

    /**
     * Explicitly-registered entity actions: [EntityType][key] => AiActionInterface.
     *
     * @var array<string, array<string, AiActionInterface>>
     */
    private array $entity = [];

    private bool $builtInsRegistered = false;

    /**
     * Built-ins the package attaches itself and nobody asks for by name (the follow-up
     * question chip): resolvable for dispatch through {@see getGeneric()}, never LISTED by
     * {@see genericActions()} / {@see relevantActions()} — so no prompt section or
     * advertisement surface offers them to a model. Keyed by action key.
     *
     * @var array<string, AiActionInterface>
     */
    private array $internal = [];

    public function __construct(bool $registerBuiltIns = true)
    {
        if ($registerBuiltIns) {
            $this->registerBuiltIns();
        }

        $this->registerConfigActions();
    }

    /**
     * Container-tolerant config read. The config() helper exists even when no Laravel
     * container is booted (pure unit tests), where calling it throws "Target class
     * [config] does not exist" — so guard on the binding and fall back to the default.
     */
    private function cfg(string $key, mixed $default): mixed
    {
        if (function_exists('app') && function_exists('config')) {
            try {
                if (app()->bound('config')) {
                    return config($key, $default);
                }
            } catch (\Throwable $e) {
                // Container/config not available — fall through to default.
            }
        }

        return $default;
    }

    /**
     * Register the default generative-UI actions. Idempotent.
     */
    public function registerBuiltIns(): void
    {
        if ($this->builtInsRegistered) {
            return;
        }
        $this->builtInsRegistered = true;

        foreach ($this->defaultActions() as $action) {
            // Don't clobber an action a project already registered under the same key.
            if (!isset($this->generic[$action->key()])) {
                $this->generic[$action->key()] = $action;
            }
        }

        foreach ([new AskFollowUpAction()] as $action) {
            $this->internal[$action->key()] = $action;
        }
    }

    /**
     * Ingest config('ai.actions') class-strings the house-Plugin way: a host lists an AiAction
     * class-string in that flat array exactly as it lists a Plugin, and the registry instantiates
     * each on boot (new $class()) beside the built-ins — no bespoke service-provider code.
     *
     * SAFE DEFAULTS: the key may be absent or carry non-class entries (e.g. the 'token_ttl' tuning
     * scalar lives in the same array); those are ignored, never thrown on. A class-string never
     * clobbers a built-in or an explicitly-registered action under the same key.
     */
    private function registerConfigActions(): void
    {
        foreach ((array) $this->cfg('ai.actions', []) as $entry) {
            if (!is_string($entry) || !is_a($entry, AiActionInterface::class, true)) {
                // Non-class entries (token_ttl tuning, malformed values) are not actions.
                continue;
            }

            $action = new $entry();
            // Don't clobber a built-in / explicitly-registered action under the same key.
            if (!isset($this->generic[$action->key()])) {
                $this->generic[$action->key()] = $action;
            }
        }
    }

    /**
     * @return AiActionInterface[]
     */
    private function defaultActions(): array
    {
        return [
            new OpenModalAction(),
            new RenderTableAction(),
            new LinkAction(),
            new ExportExcelAction(),
            new ExportPdfAction(),
            IdentityLinkAction::profile(),
            IdentityLinkAction::team(),
        ];
    }

    /**
     * Every entity label that has at least one configured/registered action.
     *
     * @return list<string>
     */
    public function entityLabels(): array
    {
        $labels = array_keys((array) $this->cfg('ai.entity_actions', []));
        foreach (array_keys($this->entity) as $label) {
            $labels[] = $label;
        }

        return array_values(array_unique(array_filter(array_map('strval', $labels), static fn (string $l) => $l !== '')));
    }

    /**
     * The declarative, closure-free attributes of a config action entry — what the action
     * attacher reads to place it (actions plan B1/B3):
     *   - entity actions: `primary` (bool) — the record's main "open" action;
     *   - generic actions: `entity` (label or list of labels) + `kind`
     *     ('list' | 'create' | 'report') — the entity's list page, create form or report.
     * Closures and instances carry no attributes ([]).
     *
     * @return array<string, mixed>
     */
    public function attributes(string $key, ?string $entityType = null): array
    {
        $entry = $entityType !== null
            ? ((array) $this->cfg('ai.entity_actions', []))[$entityType][$key] ?? null
            : ((array) $this->cfg('ai.generic_actions', []))[$key] ?? null;

        if (!is_array($entry)) {
            return [];
        }

        return array_filter($entry, static fn ($value, $name) => in_array($name, ['primary', 'entity', 'kind'], true)
            && !$value instanceof \Closure, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Register (or override) a generic action.
     */
    public function registerGeneric(AiActionInterface $action): self
    {
        $this->generic[$action->key()] = $action;
        return $this;
    }

    /**
     * Register (or override) an entity action.
     */
    public function registerEntity(string $entityType, AiActionInterface $action): self
    {
        $this->entity[$entityType][$action->key()] = $action;
        return $this;
    }

    /**
     * Resolve a generic action by key (config closures + built-ins + registered).
     */
    public function getGeneric(string $key): ?AiActionInterface
    {
        if (isset($this->generic[$key])) {
            return $this->generic[$key];
        }

        $config = $this->cfg('ai.generic_actions', []);
        if (isset($config[$key])) {
            return $this->resolveConfigEntry($key, $config[$key]);
        }

        // Last: a host action registered or configured under the same key wins.
        return $this->internal[$key] ?? null;
    }

    /**
     * Resolve an entity action by type + key.
     */
    public function getEntity(string $entityType, string $key): ?AiActionInterface
    {
        if (isset($this->entity[$entityType][$key])) {
            return $this->entity[$entityType][$key];
        }

        $config = $this->cfg('ai.entity_actions', []);
        $entry = $config[$entityType][$key] ?? null;
        if ($entry !== null) {
            return $this->resolveConfigEntry($key, $entry, $entityType);
        }

        return null;
    }

    /**
     * All actions configured/registered for a given entity type.
     *
     * @return AiActionInterface[]
     */
    public function entityActions(string $entityType): array
    {
        $actions = [];

        // Config-defined first.
        foreach ((array) ($this->cfg('ai.entity_actions', [])[$entityType] ?? []) as $key => $entry) {
            $resolved = $this->resolveConfigEntry((string) $key, $entry, $entityType);
            if ($resolved !== null) {
                $actions[$key] = $resolved;
            }
        }

        // Explicitly-registered override / extend.
        foreach ($this->entity[$entityType] ?? [] as $key => $action) {
            $actions[$key] = $action;
        }

        return array_values($actions);
    }

    /**
     * All generic actions (config + built-ins + registered).
     *
     * @return AiActionInterface[]
     */
    public function genericActions(): array
    {
        $actions = $this->generic; // built-ins + registered

        foreach ((array) $this->cfg('ai.generic_actions', []) as $key => $entry) {
            if (isset($actions[$key])) {
                continue;
            }
            $resolved = $this->resolveConfigEntry((string) $key, $entry);
            if ($resolved !== null) {
                $actions[$key] = $resolved;
            }
        }

        return array_values($actions);
    }

    /**
     * Resolve the actions relevant to a render context, given the focused entity type and
     * the labels present in the result set. Used to advertise a focused, non-noisy set to
     * the LLM and to drive per-row chips.
     *
     * @param string|null $focusedEntity
     * @param string[] $resultTypes  Entity labels found in the current/last result set.
     * @return array{entity: array<string, AiActionInterface[]>, generic: AiActionInterface[]}
     */
    public function relevantActions(?string $focusedEntity, array $resultTypes = []): array
    {
        $types = array_values(array_unique(array_filter(array_merge(
            $focusedEntity ? [$focusedEntity] : [],
            $resultTypes
        ))));

        $entity = [];
        foreach ($types as $type) {
            $actions = $this->entityActions($type);
            if (!empty($actions)) {
                $entity[$type] = $actions;
            }
        }

        return [
            'entity' => $entity,
            'generic' => $this->genericActions(),
        ];
    }

    /**
     * Filter a raw param bag against an action's inputSchema:
     *   - DROP any param not declared in the schema (security: unknown/forbidden params),
     *   - cast declared params to their schema type,
     *   - apply defaults for declared-but-missing params.
     *
     * Returns the cleaned, typed input array. An action with an empty schema yields [].
     *
     * @param array<string, mixed> $rawParams
     * @return array<string, mixed>
     */
    public function filterParams(AiActionInterface $action, array $rawParams): array
    {
        $schema = $action->inputSchema();
        if (empty($schema)) {
            return [];
        }

        $clean = [];
        foreach ($schema as $name => $spec) {
            $spec = is_array($spec) ? $spec : [];
            $type = $spec['type'] ?? 'string';

            if (array_key_exists($name, $rawParams)) {
                $clean[$name] = $this->castValue($rawParams[$name], $type);
            } elseif (array_key_exists('default', $spec)) {
                $clean[$name] = $spec['default'];
            }
            // Declared, no value, no default → omitted. Required-ness is the action's concern.
        }

        return $clean;
    }

    /**
     * Cast a string-ish param value to the declared schema type.
     */
    private function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'array' => is_array($value) ? $value : (array) $value,
            default => (string) $value,
        };
    }

    /**
     * Resolve a single config entry into an action, or null when it is not usable.
     *
     * A host may place EITHER a legacy closure / array-with-closure (wrapped in ConfigAiAction
     * with SAFE DEFAULTS) OR a ready-made AiActionInterface instance (e.g. a
     * {@see ModelMethodMutationAction}) straight into entity_actions / generic_actions config. An
     * instance is returned as-is so its typed mutates()/authorize()/inputSchema() survive intact —
     * the closure adapter would flatten a mutating action into a read-only one.
     */
    private function resolveConfigEntry(string $key, mixed $entry, ?string $entityType = null): ?AiActionInterface
    {
        if ($entry instanceof AiActionInterface) {
            return $entry;
        }

        if ($this->isValidConfigEntry($entry)) {
            return new ConfigAiAction($key, $this->normalizeConfigEntry($entry), $entityType);
        }

        return null;
    }

    /**
     * A config entry is usable if it is a closure or an array carrying an 'action' closure.
     */
    private function isValidConfigEntry(mixed $entry): bool
    {
        if ($entry instanceof \Closure) {
            return true;
        }

        return is_array($entry) && isset($entry['action']) && $entry['action'] instanceof \Closure;
    }

    /**
     * @return array<string, mixed>|\Closure
     */
    private function normalizeConfigEntry(mixed $entry): array|\Closure
    {
        return $entry instanceof \Closure ? $entry : (array) $entry;
    }
}
