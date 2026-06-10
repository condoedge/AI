<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;

/**
 * ActionToolSpec
 *
 * Provider-neutral tool/function definition projected from what an action ALREADY declares —
 * key/label/aliases/description/inputSchema()/mutates() — never a second, hand-maintained
 * schema. The OpenAI/Anthropic providers shape this into their wire formats; the spec itself
 * stays neutral: name, description, JSON-Schema parameters, mutates flag. The anti-drift
 * guarantee is asserted as a property test: every advertised parameter survives the registry's
 * filterParams() round-trip and vice versa, modulo the context-routed entity_id.
 *
 * Naming: generic actions advertise key(); entity actions advertise {EntityType}__{key()},
 * sanitized to the [a-zA-Z0-9_-]{1,64} charset both providers enforce. A post-sanitization
 * name collision SKIPS the later action (logged) — it never silently shadows the earlier one.
 *
 * Entity id flow: every entity tool requires an entity_id argument. An action that declares it
 * in inputSchema() (ModelMethodMutationAction) keeps its own declaration; for the rest it is
 * injected into the TOOL SPEC ONLY — the loop routes the argument into the dispatch context,
 * mirroring the entity:// link path's id segment, so filterParams() semantics are unchanged.
 */
final class ActionToolSpec
{
    /** Appended to every mutating tool so the model never narrates an unconfirmed write as done. */
    public const MUTATING_SUFFIX = 'REQUIRES USER APPROVAL: calling this only PROPOSES the action'
        . ' — the user must confirm it on a card before anything happens.'
        . ' Never claim it was performed.';

    /**
     * @param array<string, mixed> $parameters  JSON-Schema object for the tool arguments.
     */
    private function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        public readonly bool $mutates,
        public readonly string $key,
        public readonly ?string $entityType,
    ) {
    }

    public static function fromAction(AiActionInterface $action, ?string $entityType = null): self
    {
        $properties = [];
        $required = [];

        foreach ($action->inputSchema() as $paramName => $paramSpec) {
            $paramSpec = is_array($paramSpec) ? $paramSpec : [];
            $property = ['type' => self::jsonType((string) ($paramSpec['type'] ?? 'string'))];
            if (isset($paramSpec['label']) && trim((string) $paramSpec['label']) !== '') {
                $property['description'] = (string) $paramSpec['label'];
            }
            if (array_key_exists('default', $paramSpec)) {
                $property['default'] = $paramSpec['default'];
            }
            $properties[(string) $paramName] = $property;
            if (!empty($paramSpec['required'])) {
                $required[] = (string) $paramName;
            }
        }

        if ($entityType !== null) {
            if (!isset($properties[ModelMethodMutationAction::ID_PARAM])) {
                $properties[ModelMethodMutationAction::ID_PARAM] = [
                    'type' => 'string',
                    'description' => "ID of the {$entityType} from the results shown — never invented.",
                ];
            }
            if (!in_array(ModelMethodMutationAction::ID_PARAM, $required, true)) {
                $required[] = ModelMethodMutationAction::ID_PARAM;
            }
        }

        $parameters = [
            'type' => 'object',
            // An empty property map must JSON-encode as {} — a bare [] is not a JSON Schema object.
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $parameters['required'] = $required;
        }

        return new self(
            name: self::sanitizeName($entityType !== null ? $entityType . '__' . $action->key() : $action->key()),
            description: self::describe($action),
            parameters: $parameters,
            mutates: $action->mutates(),
            key: $action->key(),
            entityType: $entityType,
        );
    }

    /**
     * Build the tool set for a render context from the registry's relevantActions() shape.
     * Generic actions first (the stable built-ins), then per-type entity actions.
     *
     * @param array{entity: array<string, AiActionInterface[]>, generic: AiActionInterface[]} $relevant
     * @return self[]
     */
    public static function fromRelevantActions(array $relevant): array
    {
        $specs = [];
        $seen = [];

        foreach ((array) ($relevant['generic'] ?? []) as $action) {
            self::collect($specs, $seen, $action, null);
        }

        foreach ((array) ($relevant['entity'] ?? []) as $entityType => $actions) {
            foreach ((array) $actions as $action) {
                self::collect($specs, $seen, $action, (string) $entityType);
            }
        }

        return $specs;
    }

    /**
     * @param self[] $specs
     * @param array<string, true> $seen
     */
    private static function collect(array &$specs, array &$seen, mixed $action, ?string $entityType): void
    {
        if (!$action instanceof AiActionInterface) {
            return;
        }

        $spec = self::fromAction($action, $entityType);
        if (isset($seen[$spec->name])) {
            self::warn('AI action tool name collision after sanitization — action skipped', [
                'name' => $spec->name,
                'key' => $action->key(),
                'entity_type' => $entityType,
            ]);
            return;
        }

        $seen[$spec->name] = true;
        $specs[] = $spec;
    }

    /**
     * description() + aliases as "the user may say …" + the approval suffix iff mutates().
     * Falls back to label() so a closure config entry (empty description) still names itself.
     */
    private static function describe(AiActionInterface $action): string
    {
        $base = trim($action->description());
        if ($base === '') {
            $base = trim($action->label());
        }

        $parts = [$base];

        $aliases = array_values(array_filter(array_map(
            static fn ($alias) => trim((string) $alias),
            $action->aliases()
        )));
        if ($aliases !== []) {
            $parts[] = 'The user may say: "' . implode('", "', $aliases) . '".';
        }

        if ($action->mutates()) {
            $parts[] = self::MUTATING_SUFFIX;
        }

        return trim(implode(' ', array_filter($parts, static fn (string $part) => $part !== '')));
    }

    /**
     * JSON-Schema types mapped exactly as AiActionRegistry::castValue() enumerates the schema
     * types — the two MUST stay in lockstep or the round-trip property test breaks.
     */
    private static function jsonType(string $type): string
    {
        return match ($type) {
            'int', 'integer' => 'integer',
            'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            default => 'string',
        };
    }

    /**
     * Both providers enforce [a-zA-Z0-9_-]{1,64} for tool names.
     */
    private static function sanitizeName(string $raw): string
    {
        $clean = substr((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $raw), 0, 64);

        return $clean === '' ? '_' : $clean;
    }

    /**
     * Container-tolerant warning (pure-unit contexts have no log binding; skipping the
     * colliding action is already the safe behavior, so a lost log line is acceptable there).
     */
    private static function warn(string $message, array $context): void
    {
        try {
            \Log::warning($message, $context);
        } catch (\Throwable $e) {
            // No logger bound — nothing else to do.
        }
    }
}
