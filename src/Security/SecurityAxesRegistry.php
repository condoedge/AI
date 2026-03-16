<?php

declare(strict_types=1);

namespace Condoedge\Ai\Security;

use Condoedge\Ai\Contracts\AiAuthAdapterInterface;

/**
 * Registry of all configured security axes.
 *
 * Manages axis registration and resolution of accessible IDs per axis for a user.
 */
class SecurityAxesRegistry
{
    /** @var array<string, SecurityAxis> */
    private array $axes = [];

    public function __construct(
        private readonly AiAuthAdapterInterface $authAdapter
    ) {}

    public function register(SecurityAxis $axis): void
    {
        $this->axes[$axis->name] = $axis;
    }

    public function get(string $name): ?SecurityAxis
    {
        return $this->axes[$name] ?? null;
    }

    /** @return array<string, SecurityAxis> */
    public function all(): array
    {
        return $this->axes;
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->axes);
    }

    /**
     * Resolve all axes for a user.
     *
     * @return array<string, array<int>> e.g. ['team' => [1,2], 'role' => [3]]
     */
    public function resolveAll($user): array
    {
        $results = [];

        foreach ($this->axes as $name => $axis) {
            $results[$name] = $this->resolveAxis($name, $user);
        }

        return $results;
    }

    /**
     * Resolve accessible IDs for a specific axis.
     *
     * @return array<int>
     */
    public function resolveAxis(string $name, $user): array
    {
        $axis = $this->get($name);

        if (!$axis) {
            return [];
        }

        // If axis has a custom resolver closure, use it
        if ($axis->resolver !== null) {
            return ($axis->resolver)($user);
        }

        // Delegate to auth adapter
        return $this->authAdapter->getAccessibleIdsForAxis($user, $name, '');
    }
}
