<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs\Tasks;

/**
 * Hard limits on one task definition: per run (turns, lifetime, LLM calls) and on how
 * many runs may be executed per user and per team in 24 hours.
 */
final class TaskLimits
{
    public function __construct(
        public readonly int $maxTurns = 40,
        public readonly int $ttlSeconds = 86400,
        public readonly int $maxLlmCalls = 60,
        public readonly int $maxExecutedPerUserPerDay = 3,
        public readonly int $maxExecutedPerTeamPerDay = 10,
    ) {
    }
}
