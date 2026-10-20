<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Stubs;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * Test stub: a mutating AiAction (mutates()=true) for approval-round-trip tests.
 * authorize() is toggleable to simulate permission loss between render and approval.
 *
 * Written against the evolved contract: handle(array,ActionContext):AiActionResult. The
 * onHandle callback may return a raw value (wrapped once in an element result) or its own
 * AiActionResult.
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
    public function mutates(): bool { return true; }
    public function authorize(mixed $user, array $input): bool { return $this->authorized; }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $raw = ($this->onHandle)($input, $context);

        return $raw instanceof AiActionResult ? $raw : AiActionResult::element($raw, $this->label());
    }
}
