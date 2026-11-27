<?php

namespace Condoedge\Ai\Contracts;

/**
 * What the eval panel asks the host application: what an id typed for a run is here, and who
 * the assistant would refuse. The panel is generic; the application knows its own records.
 * Bound by config ai.eval.host (Condoedge\Ai\Eval\DefaultEvalHost when the host gives none).
 */
interface EvalHost
{
    /**
     * Under an id typed for a run (a field of the suite's fixture, or 'user' for a pasted
     * conversation): what it points to in this environment.
     *
     * @return array{found: bool, label: string, warning?: string, note?: string}|null null for a field with nothing to look up
     */
    public function describe(string $key, mixed $value, string $suite = ''): ?array;

    /**
     * The names of the groups these users belong to that the assistant refuses (no consent,
     * switched off): every answer of a run would be the same refusal, so it is not started.
     *
     * @param array<int> $userIds
     * @return array<string>
     */
    public function closedTo(array $userIds): array;
}
