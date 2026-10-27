<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Admin;

use Condoedge\Ai\Kompo\Admin\Concerns\GuardsConversationInsights;
use Condoedge\Ai\Kompo\Admin\Concerns\RendersInsightCells;
use Condoedge\Ai\Kompo\Support\ConversationLimitBanner;
use Condoedge\Ai\Models\AiTaskEvent;
use Condoedge\Ai\Services\Admin\ConversationInsights;
use Condoedge\Ai\Services\Admin\EvalCase;
use Condoedge\Ai\Services\Tasks\TaskOrchestrator;
use Condoedge\Utils\Kompo\Common\Modal;

/**
 * READ-ONLY transcript of one conversation for the admin insights page: every message
 * in order (role, time, escaped content, feedback mark with its time and comment) and, per answer, a compact
 * trace summary (route, LLM calls, tokens, latency, errors, cited file names). Then each
 * task the conversation ran: its audit timeline and whether the hash chain holds.
 *
 * Found by uuid only, inside the visible corpus (not deleted, not eval). No submit and no
 * write method: its one link copies the conversation as an anonymised eval case (EvalCase)
 * to the clipboard.
 */
class AdminTranscriptModal extends Modal
{
    use GuardsConversationInsights;
    use RendersInsightCells;

    protected $_Title = 'ai.admin.transcript';
    public $class = 'overflow-y-auto mini-scroll max-w-4xl';
    public $style = 'max-height: 92vh; width: 95vw;';

    protected $noHeaderButtons = true;
    protected $hasSubmitButton = false;
    public $preventSubmit = true;

    protected ?array $transcript = null;
    protected array $tasks = [];
    protected ?int $highlight = null;

    public function created()
    {
        $uuid = $this->store('conversation');
        $this->highlight = is_numeric($this->store('message')) ? (int) $this->store('message') : null;
        $this->transcript = is_string($uuid) && $uuid !== ''
            ? app(ConversationInsights::class)->transcript($uuid)
            : null;
        $this->tasks = $this->transcript !== null
            ? app(ConversationInsights::class)->taskTimeline((int) $this->transcript['conversation']->id)
            : [];
    }

    public function body()
    {
        if ($this->transcript === null) {
            return _Html('ai.admin.transcript-not-found')->class('py-8 text-center text-sm text-gray-500');
        }

        $conversation = $this->transcript['conversation'];

        return _Rows(
            _Rows(
                $this->text($conversation->title ?: __('ai.admin.untitled'), 'text-base font-semibold text-gray-900'),
                $this->text(implode(' · ', array_filter([
                    $conversation->user_name ?? null,
                    $conversation->team_name ?? null,
                    $this->dateTime($conversation->created_at ?? null),
                ])), 'text-xs text-gray-500'),
                _FlexBetween(
                    _Html('ai.admin.read-only')->class('text-xs italic text-gray-400'),
                    _Link('ai.admin.copy-eval-case')
                        ->class('text-xs font-medium text-indigo-700 hover:underline')
                        ->onClick(fn ($e) => $e->run(ConversationLimitBanner::copyResumeJs(
                            EvalCase::fromTranscript($conversation, $this->transcript['messages'])
                        )) && $e->alert('ai.admin.eval-case-copied')),
                )->class('mt-1 gap-2'),
            )->class('mb-4 border-b border-gray-200 pb-3'),
            _Rows(
                ...$this->transcript['messages']->map(fn ($message) => $this->messageBlock($message))->all()
            )->class('space-y-3'),
            ...array_map(fn (array $timeline) => $this->taskBlock($timeline), $this->tasks),
        );
    }

    /** @param array{task: \Condoedge\Ai\Models\AiTask, events: \Illuminate\Support\Collection, chain_ok: bool} $timeline */
    protected function taskBlock(array $timeline)
    {
        $task = $timeline['task'];

        return _Rows(
            _FlexBetween(
                $this->text(__('ai.admin.task.title', ['definition' => $task->definition_key, 'status' => $task->status]), 'text-sm font-semibold text-gray-900'),
                $timeline['chain_ok']
                    ? _Html('ai.admin.task.chain-ok')->class('text-xs font-semibold text-green-700')
                    : _Html('ai.admin.task.chain-broken')->class('text-xs font-semibold text-red-700'),
            )->class('mb-2'),
            _Rows(...$timeline['events']->map(fn (AiTaskEvent $event) => $this->taskEventLine($event))->all())->class('space-y-1'),
        )->class('mt-4 rounded-lg border border-gray-200 bg-gray-50 p-3');
    }

    /** time · kind (reason) · field: before → after · actor. Personal values were audited as "provided". */
    protected function taskEventLine(AiTaskEvent $event)
    {
        $value = fn (mixed $v) => match (true) {
            $v === null => '',
            $v === TaskOrchestrator::PROVIDED => __('ai.admin.task.provided'),
            is_scalar($v) => (string) $v,
            default => (string) json_encode($v, JSON_UNESCAPED_UNICODE),
        };
        $reason = is_string($event->payload['reason'] ?? null) ? ' (' . $event->payload['reason'] . ')' : '';

        return $this->text(implode(' · ', array_filter([
            $this->dateTime($event->at),
            $event->kind . $reason,
            $event->field !== null ? $event->field . ': ' . trim($value($event->value_before) . ' → ' . $value($event->value_after)) : null,
            $event->actor_user_id !== null ? __('ai.admin.task.actor', ['id' => $event->actor_user_id]) : null,
        ])), 'text-xs text-gray-700 break-words');
    }

    protected function messageBlock($message)
    {
        $isAnswer = $message->role === 'assistant';
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $feedback = $metadata['feedback'] ?? null;
        $rating = $this->feedbackDetails($metadata);
        $highlighted = $this->highlight !== null && (int) $message->id === $this->highlight;

        $roleKey = 'ai.admin.role.' . (in_array($message->role, ['user', 'assistant', 'system'], true) ? $message->role : 'system');

        return _Rows(
            _FlexBetween(
                _Html($roleKey)->class('text-xs font-semibold uppercase tracking-wide ' . ($isAnswer ? 'text-indigo-700' : 'text-gray-700')),
                _Flex(
                    $isAnswer ? $this->feedbackBadge(is_string($feedback) ? $feedback : null) : null,
                    $isAnswer && $rating['at'] !== null ? $this->text(__('ai.admin.feedback.rated-at', ['time' => $rating['at']]), 'text-xs text-gray-400') : null,
                    $this->text($this->dateTime($message->created_at), 'text-xs text-gray-400'),
                )->class('items-center gap-2'),
            )->class('mb-1'),
            $this->text((string) $message->content, 'whitespace-pre-wrap break-words text-sm text-gray-900'),
            $isAnswer && $rating['comment'] !== null
                ? $this->text(__('ai.admin.feedback.comment', ['comment' => $rating['comment']]), 'mt-2 rounded bg-red-50 px-2 py-1 text-xs text-red-800 whitespace-pre-wrap break-words')
                : null,
            $isAnswer ? $this->traceLine(ConversationInsights::traceSummary($metadata, $message->execution_time_ms)) : null,
        )->id('ai-admin-message-' . (int) $message->id)
            ->class('rounded-lg border p-3 ' . ($highlighted
                ? 'border-amber-400 bg-amber-50 ring-2 ring-amber-300'
                : ($isAnswer ? 'border-gray-200 bg-white' : 'border-gray-200 bg-gray-50')));
    }

    protected function traceLine(array $summary)
    {
        $facts = array_filter([
            $summary['llm_calls'] !== null ? __('ai.admin.trace.calls', ['count' => $summary['llm_calls']]) : null,
            $summary['tokens'] !== null ? __('ai.admin.trace.tokens', ['count' => number_format($summary['tokens'])]) : null,
            $summary['latency_ms'] !== null ? $this->latency($summary['latency_ms']) : null,
        ]);

        return _Rows(
            _Flex(
                $this->routeBadge($summary['route'], $summary['is_error']),
                $summary['is_error'] ? _Html('ai.admin.trace.failed')->class('text-xs font-semibold text-red-700') : null,
                $this->text(implode(' · ', $facts), 'text-xs text-gray-500'),
            )->class('flex-wrap items-center gap-2'),
            $summary['errors']
                ? $this->text(__('ai.admin.trace.errors') . ': ' . implode(' | ', $summary['errors']), 'mt-1 text-xs text-red-700 break-words')
                : null,
            $summary['files']
                ? $this->text(__('ai.admin.trace.files') . ': ' . implode(', ', $summary['files']), 'mt-1 text-xs text-gray-500 break-words')
                : null,
        )->class('mt-2 border-t border-gray-100 pt-2');
    }
}
