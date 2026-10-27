<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs\Tasks;

/**
 * The values a task has collected so far, the optional groups the user accepted or
 * declined to review, and the version of the task row it was read at (the optimistic lock).
 */
final class TaskDraft
{
    /**
     * @param array<string, mixed> $values
     * @param list<string> $groups
     * @param list<string> $declined
     */
    public function __construct(
        public readonly array $values = [],
        public readonly array $groups = [],
        public readonly int $version = 1,
        public readonly array $declined = [],
    ) {
    }

    public static function fromArray(?array $stored, int $version): self
    {
        return new self($stored['values'] ?? [], $stored['groups'] ?? [], $version, $stored['declined'] ?? []);
    }

    /** @return array{values: array<string, mixed>, groups: list<string>, declined: list<string>} */
    public function toArray(): array
    {
        return ['values' => $this->values, 'groups' => $this->groups, 'declined' => $this->declined];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function with(string $key, mixed $value): self
    {
        return new self([...$this->values, $key => $value], $this->groups, $this->version, $this->declined);
    }

    /** @param list<string> $keys */
    public function without(array $keys): self
    {
        return new self(array_diff_key($this->values, array_flip($keys)), $this->groups, $this->version, $this->declined);
    }

    /** Accepts the group, also one the user declined before. */
    public function withGroup(string $group): self
    {
        return in_array($group, $this->groups, true)
            ? $this
            : new self($this->values, [...$this->groups, $group], $this->version, array_values(array_diff($this->declined, [$group])));
    }

    public function withDeclinedGroup(string $group): self
    {
        return in_array($group, $this->declined, true)
            ? $this
            : new self($this->values, $this->groups, $this->version, [...$this->declined, $group]);
    }

    /** Whether the user already accepted or declined the group. */
    public function hasAnswered(string $group): bool
    {
        return in_array($group, $this->groups, true) || in_array($group, $this->declined, true);
    }

    public function atVersion(int $version): self
    {
        return new self($this->values, $this->groups, $version, $this->declined);
    }
}
