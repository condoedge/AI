<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * Adapter that wraps a legacy config('ai.entity_actions') / config('ai.generic_actions')
 * entry in the AiActionInterface, so TODAY'S closures keep working unchanged.
 *
 * A config entry is either:
 *   - a bare \Closure (oldest format), OR
 *   - an array: ['action' => \Closure, 'aliases' => [...], 'label' => '...',
 *                'description' => '...', 'requires_approval' => false,
 *                'input_schema' => [...], 'authorize' => \Closure(?$user, array $input): bool ]
 *
 * SAFE DEFAULTS (everything optional — absent keys never throw):
 *   - mutates() = false   (requires_approval => false)
 *   - authorize() = true
 *   - inputSchema() = [] (no params)
 *
 * The wrapped closure is invoked with ONE of two signatures:
 *   - NEW (preferred):   fn(ActionContext $ctx): AiActionResult   — single typed param.
 *   - LEGACY positional: fn($id, $text)  (entity)  /  fn($text)  (generic), with the validated
 *     $input appended when the closure's arity allows it.
 * The signature is chosen by ONE reflection check on the first param's type hint, not per-render
 * arity juggling. A legacy closure that returns a raw value is wrapped in an element result.
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

    public function mutates(): bool
    {
        if ($this->config instanceof \Closure) {
            return false;
        }

        return (bool) ($this->config['requires_approval'] ?? false);
    }

    /**
     * Backward-compatible alias of {@see mutates()} for the old config method name. The
     * dispatcher reads mutates().
     */
    public function requiresApproval(): bool
    {
        return $this->mutates();
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

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $resolver = $this->resolver();
        if ($resolver === null) {
            return AiActionResult::error('This action is not available.');
        }

        $raw = $this->expectsActionContext($resolver)
            ? $resolver($context)
            : $this->invokeLegacy($resolver, $context, $input);

        // A new-style closure already returns a typed result; a legacy closure returns a raw
        // renderable/value we wrap once so consumers always get a typed result.
        return $raw instanceof AiActionResult
            ? $raw
            : AiActionResult::element($raw, $this->label());
    }

    /**
     * Whether the closure opts into the new typed signature fn(ActionContext $ctx).
     */
    private function expectsActionContext(\Closure $resolver): bool
    {
        try {
            $params = (new \ReflectionFunction($resolver))->getParameters();
        } catch (\ReflectionException $e) {
            return false;
        }

        $first = $params[0] ?? null;
        $type = $first?->getType();

        return $type instanceof \ReflectionNamedType && $type->getName() === ActionContext::class;
    }

    /**
     * Invoke a legacy positional closure: entity → ($id, $text[, $input]); generic → ($text[, $input]).
     * Passes only as many leading args as the closure declares.
     */
    private function invokeLegacy(\Closure $resolver, ActionContext $context, array $input): mixed
    {
        $text = $context->text ?? $this->label();

        $positional = $this->entityType !== null
            ? [$context->entityId, $text, $input]
            : [$text, $input];

        try {
            $count = (new \ReflectionFunction($resolver))->getNumberOfParameters();
        } catch (\ReflectionException $e) {
            $count = count($positional);
        }

        if ($count <= 0) {
            return $resolver();
        }

        return $resolver(...array_slice($positional, 0, $count));
    }
}
