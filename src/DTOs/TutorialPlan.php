<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

/**
 * An AI-composed guided tutorial — a title plus an ordered list of steps.
 *
 * The toArray() shape matches what window.TutorialEngine.start(plan) consumes
 * (the SAME { title, steps:[...] } shape Condoedge\Utils\Tutorials\
 * TutorialProcessor::process() returns for static tutorials), so a runtime AI
 * plan reuses the EXACT front-end engine the static tutorials use — there is no
 * second walkthrough renderer.
 *
 * When the composer cannot run (offline / no key) or produces nothing usable,
 * {@see self::skipped()} yields an empty, non-throwing plan carrying a reason so
 * the caller degrades to a normal text answer instead of failing the turn.
 */
final class TutorialPlan
{
    public function __construct(
        public readonly string $title,
        /** @var array<int, TutorialStep> */
        public readonly array $steps = [],
        /** True when the composer could not produce a plan (offline / empty). */
        public readonly bool $skipped = false,
        /** Short rationale when {@see $skipped} is true. */
        public readonly string $reason = '',
    ) {
    }

    /**
     * Empty, non-throwing plan used when the composer is unavailable or the LLM
     * returned nothing usable. The caller treats this as "no walkthrough — answer
     * normally" rather than an error.
     */
    public static function skipped(string $reason = 'tutorial unavailable'): self
    {
        return new self(title: '', steps: [], skipped: true, reason: $reason);
    }

    public function stepCount(): int
    {
        return count($this->steps);
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    /**
     * The serialized shape the front-end engine consumes.
     *
     * @return array{title:string,steps:array<int,array<string,string>>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'steps' => array_map(static fn (TutorialStep $s) => $s->toArray(), $this->steps),
        ];
    }
}
