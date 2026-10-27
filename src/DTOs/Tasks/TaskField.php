<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs\Tasks;

use Illuminate\Support\Facades\Validator;

/**
 * One value a task collects. Code, not the model, decides whether a proposed value is
 * valid (`isValid`) and whether the field applies to the current draft (`applies`).
 *
 * A field without a group is required while it applies; a field in a group is optional
 * and asked only when the user accepted to review that group. A `pii` field (1) holds
 * personal data: prompts and the audit say it is provided, never its value. Callbacks are
 * `[Class, 'method']` pairs (cache-safe) or closures, called with the draft and the user.
 */
final class TaskField
{
    public const TYPES = ['enum', 'list', 'bool', 'int', 'money', 'date', 'text', 'percent'];
    public const RISKS = ['none', 'visible', 'money', 'legal', 'outward'];

    /**
     * @param array<string|int, string> $options enum value => label key
     * @param array<int, mixed> $rules Laravel rules applied to the value
     */
    public function __construct(
        public readonly string $key,
        public readonly string $labelKey,
        public readonly string $type,
        public readonly array $options = [],
        public readonly array $rules = [],
        public readonly mixed $requiredWhen = null,
        public readonly string $risk = 'none',
        public readonly mixed $default = null,
        public readonly ?string $askHint = null,
        public readonly ?string $group = null,
        public readonly int $pii = 0,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Task field [{$key}] has an unknown type [{$type}].");
        }
        if (!in_array($risk, self::RISKS, true)) {
            throw new \InvalidArgumentException("Task field [{$key}] has an unknown risk [{$risk}].");
        }
        if (in_array($type, ['enum', 'list'], true) && $options === []) {
            throw new \InvalidArgumentException("Task field [{$key}] ({$type}) has no options.");
        }
        foreach (['requiredWhen' => $requiredWhen, 'default' => $default] as $name => $callback) {
            if ($callback !== null && self::callable($callback) === null) {
                throw new \InvalidArgumentException("Task field [{$key}] {$name} must be a [Class, 'method'] pair or a closure.");
            }
        }
    }

    public function applies(TaskDraft $draft, mixed $user): bool
    {
        return $this->requiredWhen === null || (bool) (self::callable($this->requiredWhen))($draft, $user);
    }

    public function defaultFor(TaskDraft $draft, mixed $user): mixed
    {
        return $this->default === null ? null : (self::callable($this->default))($draft, $user);
    }

    public function isValid(mixed $value): bool
    {
        if (!$this->hasValidType($value)) {
            return false;
        }
        // Without a numeric rule, Laravel's min/max measure string length.
        $typeRule = match ($this->type) {
            'int' => ['integer'],
            'money', 'percent' => ['numeric'],
            default => [],
        };

        return $this->rules === [] || Validator::make(['value' => $value], ['value' => [...$typeRule, ...$this->rules]])->passes();
    }

    private function hasValidType(mixed $value): bool
    {
        return match ($this->type) {
            'enum' => (is_string($value) || is_int($value)) && array_key_exists($value, $this->options),
            'list' => is_array($value) && array_is_list($value) && count(array_unique($value)) === count($value)
                && array_filter($value, fn ($item) => !(is_string($item) || is_int($item)) || !array_key_exists($item, $this->options)) === [],
            'bool' => is_bool($value) || $value === 0 || $value === 1,
            'int' => is_int($value),
            'money' => (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && round($value, 2) == $value,
            'percent' => (is_int($value) || is_float($value)) && $value >= 0 && $value <= 100,
            'date' => is_string($value) && ($day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) && $day->format('Y-m-d') === $value,
            'text' => is_string($value) && trim($value) !== '',
        };
    }

    private static function callable(mixed $callback): ?callable
    {
        if ($callback instanceof \Closure) {
            return $callback;
        }
        if (is_array($callback) && count($callback) === 2 && is_string($callback[0] ?? null) && is_string($callback[1] ?? null)
            && method_exists($callback[0], $callback[1])) {
            return static fn (...$args) => app($callback[0])->{$callback[1]}(...$args);
        }

        return null;
    }
}
