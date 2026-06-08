<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportExcelAction;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportPdfAction;
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

    public function __construct(bool $registerBuiltIns = true)
    {
        if ($registerBuiltIns) {
            $this->registerBuiltIns();
        }
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
        ];
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

        $config = config('ai.generic_actions', []);
        if (isset($config[$key]) && $this->isValidConfigEntry($config[$key])) {
            return new ConfigAiAction($key, $this->normalizeConfigEntry($config[$key]));
        }

        return null;
    }

    /**
     * Resolve an entity action by type + key.
     */
    public function getEntity(string $entityType, string $key): ?AiActionInterface
    {
        if (isset($this->entity[$entityType][$key])) {
            return $this->entity[$entityType][$key];
        }

        $config = config('ai.entity_actions', []);
        $entry = $config[$entityType][$key] ?? null;
        if ($entry !== null && $this->isValidConfigEntry($entry)) {
            return new ConfigAiAction($key, $this->normalizeConfigEntry($entry), $entityType);
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
        foreach ((array) (config('ai.entity_actions', [])[$entityType] ?? []) as $key => $entry) {
            if ($this->isValidConfigEntry($entry)) {
                $actions[$key] = new ConfigAiAction((string) $key, $this->normalizeConfigEntry($entry), $entityType);
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

        foreach ((array) config('ai.generic_actions', []) as $key => $entry) {
            if (!isset($actions[$key]) && $this->isValidConfigEntry($entry)) {
                $actions[$key] = new ConfigAiAction((string) $key, $this->normalizeConfigEntry($entry));
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
