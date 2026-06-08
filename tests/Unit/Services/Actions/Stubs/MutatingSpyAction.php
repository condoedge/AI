<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Stubs;

use Condoedge\Ai\Contracts\AiActionInterface;

/**
 * Test stub: a mutating AiAction (requiresApproval=true) for approval-round-trip tests.
 * authorize() is toggleable to simulate permission loss between render and approval.
 */
class MutatingSpyAction implements AiActionInterface
{
    /** @param callable $onHandle */
    public function __construct(
        private string $key,
        public bool $authorized,
        private $onHandle,
    ) {
    }

    public function key(): string { return $this->key; }
    public function label(): string { return ucfirst($this->key); }
    public function aliases(): array { return []; }
    public function description(): string { return ''; }
    public function inputSchema(): array { return []; }
    public function presentation(): string { return 'approval'; }
    public function requiresApproval(): bool { return true; }
    public function authorize(mixed $user, array $input): bool { return $this->authorized; }
    public function handle(array $input, array $context): mixed { return ($this->onHandle)($input, $context); }
}
