<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Tasks\TaskOrchestrator;

/**
 * start_task — the start chip of a task (ai.tasks.definitions) under an answer, attached by
 * code when a picked navigation page names the task. Its click posts to the chat's
 * `startTask`, the welcome chip's own handler, which re-checks `startableBy`. A chip may name
 * the optional groups the task starts at (TaskOrchestrator::start `groups`).
 *
 * Internal to the package: the registry resolves it for dispatch but never lists it.
 */
final class StartTaskAction extends AbstractBuiltInAction
{
    public const KEY = 'start_task';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Start a task';
    }

    public function inputSchema(): array
    {
        return ['key' => ['type' => 'string'], 'groups' => ['type' => 'array']];
    }

    public function authorize(mixed $user, array $input): bool
    {
        return is_string($input['key'] ?? null) && self::invocation($input['key'], $user) !== null;
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        return AiActionResult::element(self::element((string) ($input['key'] ?? ''), (string) $context->text, (array) ($input['groups'] ?? [])));
    }

    /**
     * The chip's element: it posts to the chat's `startTask`, with the groups the task starts at and the task it
     * stops (`replace`, a task uuid) when given.
     *
     * @param list<string> $groups
     */
    public static function element(string $key, string $label, array $groups = [], ?string $replace = null): mixed
    {
        $groups = array_values(array_filter($groups, 'is_string'));
        $payload = ['key' => $key];
        if ($groups !== [] || $replace !== null) {
            // Kompo hands the payload to startTask by position: the groups come before the task to stop.
            $payload['groups'] = (string) json_encode($groups);
        }
        if ($replace !== null) {
            $payload['replace'] = $replace;
        }

        return _Link(e($label))->icon('plus')
            ->selfPost('startTask', $payload)
            ->refresh(MessagesQuery::ID);
    }

    /**
     * The chip of the task this user may start now, else null.
     *
     * @param list<string> $groups the optional groups it starts at
     */
    public static function invocation(string $key, mixed $user, array $groups = []): ?ActionInvocation
    {
        $definition = TaskOrchestrator::configured() ? TaskOrchestrator::registered()[$key] ?? null : null;
        if ($user === null || $definition === null || !$definition->startableBy($user)) {
            return null;
        }
        $groups = array_values(array_filter($groups, 'is_string'));

        return new ActionInvocation(self::KEY, null, null, ['key' => $key] + ($groups !== [] ? ['groups' => $groups] : []), (string) __($definition->label()));
    }
}
