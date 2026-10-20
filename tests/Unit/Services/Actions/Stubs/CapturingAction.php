<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Stubs;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * Test stub: an AiAction that records what handle() receives and returns a caller-supplied
 * renderable. Lives in its own PSR-4 file so multiple test classes can share it.
 *
 * Written against the evolved contract: handle(array,ActionContext):AiActionResult. The handler
 * receives ($input, ActionContext) and may return a raw value (wrapped once in an element
 * result) or its own AiActionResult.
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
    public function mutates(): bool { return false; }
    public function authorize(mixed $user, array $input): bool { return true; }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $raw = ($this->handler)($input, $context);

        return $raw instanceof AiActionResult ? $raw : AiActionResult::element($raw, $this->label());
    }
}
