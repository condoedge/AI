<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs\Tasks;

/**
 * The help articles a task's step call may ground its explanations on.
 */
final class TaskKnowledge
{
    /**
     * @param list<string> $audiences
     * @param list<string> $slugs
     */
    public function __construct(
        public readonly array $audiences = [],
        public readonly array $slugs = [],
    ) {
    }
}
