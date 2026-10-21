<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Admin;

use Condoedge\Ai\Kompo\Admin\Concerns\GuardsConversationInsights;
use Condoedge\Ai\Kompo\Admin\Concerns\RendersInsightCells;
use Condoedge\Ai\Services\Admin\ConversationInsights;
use Condoedge\Utils\Kompo\Common\Modal;

/**
 * READ-ONLY transcript of one conversation for the admin insights page: every message
 * in order (role, time, escaped content, feedback mark) and, per answer, a compact
 * trace summary (route, LLM calls, tokens, latency, errors, cited file names).
 *
 * Found by uuid only, inside the visible corpus (not deleted, not eval). No submit, no
 * action: the modal has no buttons and the component no write method.
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
    protected ?int $highlight = null;

    public function created()
    {
        $uuid = $this->store('conversation');
        $this->highlight = is_numeric($this->store('message')) ? (int) $this->store('message') : null;
        $this->transcript = is_string($uuid) && $uuid !== ''
            ? app(ConversationInsights::class)->transcript($uuid)
            : null;
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
                _Html('ai.admin.read-only')->class('mt-1 text-xs italic text-gray-400'),
            )->class('mb-4 border-b border-gray-200 pb-3'),
            _Rows(
                ...$this->transcript['messages']->map(fn ($message) => $this->messageBlock($message))->all()
            )->class('space-y-3'),
        );
    }

    protected function messageBlock($message)
    {
        $isAnswer = $message->role === 'assistant';
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $feedback = $metadata['feedback'] ?? null;
        $highlighted = $this->highlight !== null && (int) $message->id === $this->highlight;

        $roleKey = 'ai.admin.role.' . (in_array($message->role, ['user', 'assistant', 'system'], true) ? $message->role : 'system');

        return _Rows(
            _FlexBetween(
                _Html($roleKey)->class('text-xs font-semibold uppercase tracking-wide ' . ($isAnswer ? 'text-indigo-700' : 'text-gray-700')),
                _Flex(
                    $isAnswer ? $this->feedbackBadge(is_string($feedback) ? $feedback : null) : null,
                    $this->text($this->dateTime($message->created_at), 'text-xs text-gray-400'),
                )->class('items-center gap-2'),
            )->class('mb-1'),
            $this->text((string) $message->content, 'whitespace-pre-wrap break-words text-sm text-gray-900'),
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
