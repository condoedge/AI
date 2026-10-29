<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Traits;

use Condoedge\Ai\Kompo\Actions\TaskSummaryCard;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiTask;
use Condoedge\Ai\Services\Tasks\TaskOrchestrator;

/**
 * The task round-trips of the chat messages panel: the start chip, a question's choice chips,
 * and Confirm, Cancel and Change on a task's summary card. Each acts only on a task of $this->conversation (already
 * scoped to the signed-in user) that this user owns; anything else answers 403. The
 * orchestrator's answer is stored as an assistant message, as a typed task turn's is.
 */
trait HandlesTasks
{
    /**
     * A start chip (StartTaskAction::element): $groups, the optional groups the task starts at (a JSON list, as the
     * chip posts it, or a list); $replace, the uuid of the conversation's task the user chose to stop for this one.
     */
    public function startTask($key, $groups = null, $replace = null)
    {
        $user = auth()->user();
        $definition = TaskOrchestrator::configured() && is_string($key) ? $this->tasks()->definition($key) : null;
        if ($definition === null || !$definition->startableBy($user)) {
            abort(403);
        }
        $groups = is_string($groups) ? json_decode($groups, true) : $groups;
        $context = array_filter([
            'groups' => is_array($groups) ? array_values(array_filter($groups, 'is_string')) : [],
            'replace' => is_string($replace) && $replace !== '' ? $replace : null,
        ], fn (mixed $value) => $value !== null && $value !== []);

        $conversation = $this->conversation ?? $this->createNewConversation();
        $this->storeTaskAnswer($conversation, $this->tasks()->start($user, $conversation, $key, $context));
    }

    /** A choice chip: its label is stored as the user's answer, then the next question. */
    public function chooseTask($uuid, $version, $field, $value)
    {
        $task = $this->ownTask($uuid);
        $response = $this->tasks()->choose($task, is_string($field) ? $field : '', is_string($value) ? json_decode($value, true) : null, [
            'user' => auth()->user(),
            'version' => is_numeric($version) ? (int) $version : 0,
        ]);
        if ($response['question'] !== '') {
            $this->conversation->addMessage('user', $response['question'], ['metadata' => ['task' => ['uuid' => $task->uuid]]]);
        }
        $this->storeTaskAnswer($this->conversation, $response);
    }

    public function confirmTask($uuid, $version, $token)
    {
        $task = $this->ownTask($uuid);
        $this->storeTaskAnswer($this->conversation, $this->tasks()->confirm($task, is_string($token) ? $token : '', [
            'user' => auth()->user(),
            'version' => is_numeric($version) ? (int) $version : 0,
            'acknowledged' => $this->acknowledgedRows(),
        ]));
    }

    public function cancelTask($uuid)
    {
        $task = $this->ownTask($uuid);
        $this->storeTaskAnswer($this->conversation, $this->tasks()->cancel($task, ['user' => auth()->user()]));
    }

    public function reopenTask($uuid, $field)
    {
        $task = $this->ownTask($uuid);
        $this->storeTaskAnswer($this->conversation, $this->tasks()->reopen($task, is_string($field) ? $field : '', ['user' => auth()->user()]));
    }

    /** The rows the summary card's ticks acknowledged (ack_<field> = 1); the orchestrator decides if that is enough. */
    protected function acknowledgedRows(): array
    {
        $rows = [];
        foreach (request()->all() as $name => $value) {
            if (is_string($name) && str_starts_with($name, TaskSummaryCard::ACK_PREFIX) && (string) $value === '1') {
                $rows[] = substr($name, strlen(TaskSummaryCard::ACK_PREFIX));
            }
        }

        return $rows;
    }

    protected function ownTask($uuid): AiTask
    {
        $task = TaskOrchestrator::configured() && $this->conversation && is_string($uuid)
            ? AiTask::where('uuid', $uuid)->where('conversation_id', $this->conversation->id)->where('user_id', auth()->id())->first()
            : null;

        return $task ?? abort(403);
    }

    protected function storeTaskAnswer(AiConversation $conversation, array $response): void
    {
        $task = $response['metadata']['task'] ?? null;
        $conversation->addMessage('assistant', (string) $response['answer'], is_array($task) ? ['metadata' => ['task' => $task]] : []);
    }

    protected function tasks(): TaskOrchestrator
    {
        return app(TaskOrchestrator::class);
    }
}
