<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;

/**
 * Adapter that wraps a legacy config('ai.entity_actions') / config('ai.generic_actions')
 * entry in the AiActionInterface, so TODAY'S closures keep working unchanged.
 *
 * A config entry is either:
 *   - a bare \Closure (oldest format), OR
 *   - an array: ['action' => \Closure, 'aliases' => [...], 'label' => '...',
 *                'description' => '...', 'presentation' => 'link',
 *                'requires_approval' => false, 'input_schema' => [...],
 *                'authorize' => \Closure(?$user, array $input): bool ]
 *
 * SAFE DEFAULTS (everything optional — absent keys never throw):
 *   - requiresApproval() = false
 *   - authorize() = true
 *   - inputSchema() = [] (no params)
 *   - presentation() = 'link'
 *
 * The wrapped closure is invoked with backward-compatible positional args:
 *   - Entity action closure:  fn($id, $text)            (legacy) — params appended if it accepts them
 *   - Generic action closure: fn($text)                 (legacy)
 * We additionally pass the validated $input array as the trailing argument when the closure's
 * arity allows it, so new closures can opt into params without breaking old ones.
 */
class ConfigAiAction implements AiActionInterface
{
    /**
     * @param string $key      The action key.
     * @param array<string, mixed>|\Closure $config  Raw config entry (array or bare closure).
     * @param string|null $entityType  Entity label for entity actions; null for generic actions.
     */
    public function __construct(
        private string $key,
        private array|\Closure $config,
        private ?string $entityType = null,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        if ($this->config instanceof \Closure) {
            return $this->key;
        }

        return (string) ($this->config['label'] ?? $this->key);
    }

    public function aliases(): array
    {
        if ($this->config instanceof \Closure) {
            return [];
        }

        return (array) ($this->config['aliases'] ?? []);
    }

    public function description(): string
    {
        if ($this->config instanceof \Closure) {
            return '';
        }

        return (string) ($this->config['description'] ?? $this->config['label'] ?? '');
    }

    public function inputSchema(): array
    {
        if ($this->config instanceof \Closure) {
            return [];
        }

        return (array) ($this->config['input_schema'] ?? []);
    }

    public function presentation(): string
    {
        if ($this->config instanceof \Closure) {
            return 'link';
        }

        return (string) ($this->config['presentation'] ?? 'link');
    }

    public function requiresApproval(): bool
    {
        if ($this->config instanceof \Closure) {
            return false;
        }

        return (bool) ($this->config['requires_approval'] ?? false);
    }

    public function authorize(mixed $user, array $input): bool
    {
        if ($this->config instanceof \Closure) {
            return true;
        }

        $gate = $this->config['authorize'] ?? null;
        if ($gate instanceof \Closure) {
            return (bool) $gate($user, $input);
        }

        // Safe default: read-only config actions are allowed.
        return true;
    }

    public function entityType(): ?string
    {
        return $this->entityType;
    }

    /**
     * Resolve the wrapped closure (or null if the entry is malformed).
     */
    public function resolver(): ?\Closure
    {
        if ($this->config instanceof \Closure) {
            return $this->config;
        }

        $action = $this->config['action'] ?? null;

        return $action instanceof \Closure ? $action : null;
    }

    public function handle(array $input, array $context): mixed
    {
        $resolver = $this->resolver();
        if ($resolver === null) {
            return null;
        }

        $text = $context['text'] ?? $this->label();

        // Build positional args matching the legacy contract, then append $input so new
        // closures can opt into params. ReflectionFunction lets us pass only what the
        // closure declares, keeping old `fn($id, $text)` / `fn($text)` closures intact.
        if ($this->entityType !== null) {
            $entityId = $context['entity_id'] ?? null;
            $positional = [$entityId, $text, $input];
        } else {
            $positional = [$text, $input];
        }

        return $this->invokeWithArity($resolver, $positional);
    }

    /**
     * Invoke a closure passing only as many leading args as it declares.
     *
     * @param array<int, mixed> $args
     */
    private function invokeWithArity(\Closure $resolver, array $args): mixed
    {
        try {
            $ref = new \ReflectionFunction($resolver);
            $count = $ref->getNumberOfParameters();
        } catch (\ReflectionException $e) {
            $count = count($args);
        }

        if ($count <= 0) {
            return $resolver();
        }

        return $resolver(...array_slice($args, 0, $count));
    }
}
