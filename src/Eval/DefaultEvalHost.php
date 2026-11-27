<?php

namespace Condoedge\Ai\Eval;

use Condoedge\Ai\Contracts\EvalHost;

/* Knows people only: a field named 'user' is a person, by name; the assistant refuses nobody. */
class DefaultEvalHost implements EvalHost
{
    public function describe(string $key, mixed $value, string $suite = ''): ?array
    {
        if ($key != 'user' || !is_numeric($value)) {
            return null;
        }

        $user = EvalPanel::userModel()::find((int) $value);

        return $user ? ['found' => true, 'label' => (string) ($user->name ?? '#'.$user->getKey())] : ['found' => false, 'label' => __('ai::eval.id-not-found')];
    }

    public function closedTo(array $userIds): array
    {
        return [];
    }
}
