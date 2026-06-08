<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\Results;

/**
 * Immutable result of running an AiAction.
 *
 * Carries:
 * - $renderable: the Kompo element (or null) to drop into the chat message.
 * - $summaryForLlm: a short, ID-free natural-language line the LLM can use to acknowledge
 *   the action ("Opened the unit modal", "Export not available — install maatwebsite/excel").
 * - $isError: whether this represents a graceful failure (e.g. a missing export library)
 *   rather than a successful render. Errors still render (a clear message), they just flag
 *   that nothing actionable happened.
 */
final class AiActionResult
{
    public function __construct(
        public readonly mixed $renderable = null,
        public readonly string $summaryForLlm = '',
        public readonly bool $isError = false,
    ) {
    }

    /**
     * Convenience constructor for a successful render.
     */
    public static function ok(mixed $renderable, string $summaryForLlm = ''): self
    {
        return new self($renderable, $summaryForLlm, false);
    }

    /**
     * Convenience constructor for a graceful error (still renderable, but flagged).
     */
    public static function error(string $summaryForLlm, mixed $renderable = null): self
    {
        return new self($renderable, $summaryForLlm, true);
    }
}
