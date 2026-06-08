<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Stubs;

use Condoedge\Ai\Contracts\AiActionInterface;

/**
 * Test stub: an AiAction that records what handle() receives and returns a caller-supplied
 * renderable. Lives in its own PSR-4 file so multiple test classes can share it.
 */
class CapturingAction implements AiActionInterface
{
    /** @param callable $handler @param array<string,mixed> $schema */
    public function __construct(
        private string $key,
        private $handler,
        private array $schema = [],
    ) {
    }

    public function key(): string { return $this->key; }
    public function label(): string { return ucfirst($this->key); }
    public function aliases(): array { return []; }
    public function description(): string { return ''; }
    public function inputSchema(): array { return $this->schema; }
    public function presentation(): string { return 'link'; }
    public function requiresApproval(): bool { return false; }
    public function authorize(mixed $user, array $input): bool { return true; }
    public function handle(array $input, array $context): mixed { return ($this->handler)($input, $context); }
}
