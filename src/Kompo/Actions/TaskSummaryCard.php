<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Actions;

use Condoedge\Ai\DTOs\Tasks\TaskSummary;
use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Kompo\Traits\HandlesTasks;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiTask;
use Condoedge\Ai\Services\Tasks\TaskOrchestrator;
use Kompo\Form;

/**
 * The confirm card of a task in review: the rows its definition built in code (label, value,
 * source, and the value it replaces when the host gives one), with a badge on the rows to confirm
 * (money, legal, outward). While the card is the task's current summary it also offers a tick per
 * such row, a Change chip per row, Confirm and Cancel.
 *
 * A Form of its own so Confirm posts the ticks (ack_<field>, 1|0) with the card's values; the
 * round-trips are HandlesTasks, scoped like the chat panel's, and the server decides whether
 * every row to confirm was ticked (TaskOrchestrator::confirm).
 */
class TaskSummaryCard extends Form
{
    use HandlesTasks;

    public const ACK_PREFIX = 'ack_';

    public $containerClass = '';
    public $preventSubmit = true;

    protected ?AiConversation $conversation = null;
    protected ?array $task = null;
    protected bool $live = false;

    public function created()
    {
        $this->conversation = AiConversation::where('user_id', auth()->id())->find($this->store('conversation_id'));
        $task = $this->conversation?->messages()->find($this->store('message_id'))?->metadata['task'] ?? null;
        $this->task = is_array($task) && is_string($task['uuid'] ?? null) ? $task : null;
        $this->live = $this->task !== null && TaskOrchestrator::configured() && AiTask::where('uuid', $this->task['uuid'])
            ->where('conversation_id', $this->conversation->id)
            ->where('status', AiTask::STATUS_REVIEWING)
            ->where('version', (int) ($this->task['version'] ?? 0))
            ->exists();
    }

    public function render()
    {
        if ($this->task === null) {
            return null;
        }
        $uuid = $this->task['uuid'];
        $token = $this->task['token'] ?? null;
        $live = $this->live && is_string($token);
        $rows = array_map(
            fn (array $row) => $this->row($uuid, $row, $live),
            array_values(array_filter((array) ($this->task['summary'] ?? []), 'is_array')),
        );

        return _Rows(
            _Rows(...$rows)->class('divide-y divide-gray-100'),
            $live ? _Flex(
                _Link(__('ai.actions.confirm'))->button()
                    ->selfPost('confirmTask', ['uuid' => $uuid, 'version' => (int) ($this->task['version'] ?? 0), 'token' => $token])
                    ->withAllFormValues()
                    ->refresh(MessagesQuery::ID),
                _Link(__('ai.actions.cancel'))->outlined()
                    ->selfPost('cancelTask', ['uuid' => $uuid])
                    ->refresh(MessagesQuery::ID),
            )->class('gap-2 mt-3') : null,
        )->class('cdge-task-summary mt-3 p-4 rounded-xl border border-gray-200 bg-white');
    }

    protected function row(string $uuid, array $row, bool $live): mixed
    {
        $field = (string) ($row['field'] ?? '');
        $risk = in_array($row['risk'] ?? null, TaskSummary::RISKS_TO_CONFIRM, true) ? $row['risk'] : null;
        $source = is_string($row['source'] ?? null) && $row['source'] !== '' ? $row['source'] : null;

        return _Rows(
            _FlexBetween(
                _Rows(
                    _Html(e(__((string) ($row['label'] ?? ''))))->class('text-xs text-gray-700'),
                    self::data((string) ($row['value'] ?? ''))->class('text-sm font-medium text-gray-900'),
                    is_string($row['before'] ?? null) ? self::data(__('ai.tasks.was', ['value' => $row['before']]))->class('text-xs text-gray-700') : null,
                    $source !== null ? _Html(e(__($source)))->class('text-xs text-gray-700') : null,
                ),
                _Flex(
                    $risk !== null ? _Html(e(__('ai.tasks.risk-' . $risk)))->class('px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800') : null,
                    $live ? _Link(__('ai.tasks.change'))->class('text-xs font-medium text-gray-700 underline hover:text-gray-800')
                        ->selfPost('reopenTask', ['uuid' => $uuid, 'field' => $field])
                        ->refresh(MessagesQuery::ID) : null,
                )->class('items-center gap-2 shrink-0'),
            )->class('gap-3'),
            $live && $risk !== null ? _Checkbox(__('ai.tasks.acknowledge'))->name(self::ACK_PREFIX . $field, false)->class('mt-1 text-xs') : null,
        )->class('py-2');
    }

    /** A value is data: escaped, and set past the translation Kompo applies to labels. */
    private static function data(string $value): mixed
    {
        $element = _Html();
        $element->label = e($value);

        return $element;
    }
}
