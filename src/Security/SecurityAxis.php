<?php

declare(strict_types=1);

namespace Condoedge\Ai\Security;

use Closure;

/**
 * Value Object representing a configurable security axis.
 *
 * A security axis is a dimension of access control (e.g., team, role, union).
 * Each axis has a resolver that determines which IDs a user can access.
 */
class SecurityAxis
{
    public function __construct(
        public readonly string $name,
        public readonly string $property,
        public readonly ?Closure $resolver,
        public readonly ?string $defaultPath = 'auto',
        public readonly bool $required = true,
    ) {}

    /**
     * Create a SecurityAxis from an array configuration.
     *
     * @param string $name Axis name (e.g., 'team', 'role')
     * @param array $config Configuration array with keys: property, resolver, default_path, required
     * @return self
     */
    public static function fromArray(string $name, array $config): self
    {
        return new self(
            name: $name,
            property: $config['property'] ?? "{$name}_id",
            resolver: $config['resolver'] ?? null,
            defaultPath: $config['default_path'] ?? 'auto',
            required: $config['required'] ?? true,
        );
    }
}
