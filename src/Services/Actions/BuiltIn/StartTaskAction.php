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
 * `startTask`, the welcome chip's own handler, which re-checks `startableBy`.
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
        return ['key' => ['type' => 'string']];
    }

    public function authorize(mixed $user, array $input): bool
    {
        return is_string($input['key'] ?? null) && self::invocation($input['key'], $user) !== null;
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        return AiActionResult::element(self::element((string) ($input['key'] ?? ''), (string) $context->text));
    }

    /** The chip's element: it posts to the chat's `startTask`. */
    public static function element(string $key, string $label): mixed
    {
        return _Link(e($label))->icon('plus')
            ->selfPost('startTask', ['key' => $key])
            ->refresh(MessagesQuery::ID);
    }

    /** The chip of the task this user may start now, else null. */
    public static function invocation(string $key, mixed $user): ?ActionInvocation
    {
        $definition = TaskOrchestrator::configured() ? TaskOrchestrator::registered()[$key] ?? null : null;
        if ($user === null || $definition === null || !$definition->startableBy($user)) {
            return null;
        }

        return new ActionInvocation(self::KEY, null, null, ['key' => $key], (string) __($definition->label()));
    }
}
