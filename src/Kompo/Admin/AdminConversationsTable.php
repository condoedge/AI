<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Admin;

use Condoedge\Ai\Kompo\Admin\Concerns\GuardsConversationInsights;
use Condoedge\Ai\Kompo\Admin\Concerns\RendersInsightCells;
use Condoedge\Ai\Services\Admin\ConversationInsights;
use Condoedge\Ai\Services\Admin\InsightsRange;
use Condoedge\Ai\Services\Tasks\TaskOrchestrator;
use Condoedge\Utils\Kompo\Common\Table;

/**
 * Conversations with activity in a date range (default: the page's), filterable by
 * user, team, feedback, answer kind (regenerated, no sources, slow), message text and, while
 * ai.tasks is configured, tasks. A row opens the read-only transcript.
 */
class AdminConversationsTable extends Table
{
    use GuardsConversationInsights;
    use RendersInsightCells;

    public $perPage = 20;
    public $itemsWrapperClass = 'overflow-x-auto';

    public function created()
    {
        $this->noItemsFound = __('ai.admin.no-conversations');
    }

    public function top()
    {
        $range = $this->rangeFromStore();
        $teams = app(ConversationInsights::class)->teamOptions();

        return _Div(
            _Date('ai.admin.filter.from')->name('from', false)->default($range->from->format('Y-m-d'))->filter(),
            _Date('ai.admin.filter.to')->name('to', false)->default($range->to->format('Y-m-d'))->filter(),
            _Input('ai.admin.filter.user')->name('user', false)->filter(),
            _Select('ai.admin.filter.team')->name('team', false)
                ->options(['' => __('ai.admin.filter.all-teams')] + array_map('e', $teams))
                ->filter(),
            _Select('ai.admin.filter.feedback')->name('feedback', false)
                ->options([
                    '' => __('ai.admin.filter.feedback-any-or-none'),
                    'any' => __('ai.admin.filter.feedback-any'),
                    'negative' => __('ai.admin.filter.feedback-negative'),
                    'positive' => __('ai.admin.filter.feedback-positive'),
                    'none' => __('ai.admin.filter.feedback-none'),
                ])
                ->filter(),
            _Select('ai.admin.filter.answers')->name('answers', false)
                ->options([
                    '' => __('ai.admin.filter.answers-any'),
                    'regenerated' => __('ai.admin.filter.answers-regenerated'),
                    'no_sources' => __('ai.admin.filter.answers-no-sources'),
                    'slow' => __('ai.admin.filter.answers-slow', ['seconds' => round(ConversationInsights::slowAnswerMs() / 1000, 1)]),
                ])
                ->filter(),
            _Input('ai.admin.filter.text')->name('text', false)->filter(),
            TaskOrchestrator::configured()
                ? _Select('ai.admin.filter.tasks')->name('tasks', false)
                    ->options(['' => __('ai.admin.filter.tasks-any'), '1' => __('ai.admin.filter.tasks-with')])
                    ->filter()
                : null,
        )->class('grid grid-cols-1 sm:grid-cols-2 gap-3 mb-2 ' . (TaskOrchestrator::configured() ? 'lg:grid-cols-8' : 'lg:grid-cols-7'));
    }

    public function query()
    {
        $range = InsightsRange::fromInput(
            $this->store('days'),
            $this->filterValue('from') ?? $this->store('from'),
            $this->filterValue('to') ?? $this->store('to'),
        );

        return app(ConversationInsights::class)->conversationsQuery($range, [
            'user' => $this->filterValue('user'),
            'team' => $this->filterValue('team'),
            'feedback' => in_array($this->filterValue('feedback'), ConversationInsights::CONVERSATION_FEEDBACK_FILTERS, true)
                ? $this->filterValue('feedback')
                : '',
            'answers' => in_array($this->filterValue('answers'), ConversationInsights::ANSWER_FILTERS, true)
                ? $this->filterValue('answers')
                : '',
            'text' => $this->filterValue('text'),
            'tasks' => $this->filterValue('tasks'),
        ]);
    }

    public function headers()
    {
        return [
            _Th('ai.admin.col.title'),
            _Th('ai.admin.col.user'),
            _Th('ai.admin.col.team'),
            _Th('ai.admin.col.messages'),
            _Th('ai.admin.col.last-activity'),
            _Th('👍'),
            _Th('👎'),
        ];
    }

    public function render($row)
    {
        $positive = (int) ($row->positive_count ?? 0);
        $negative = (int) ($row->negative_count ?? 0);

        return _TableRow(
            $this->text($this->excerpt($row->title ?: __('ai.admin.untitled'), 80), 'text-sm font-medium text-gray-900')->style('min-width: 14rem'),
            $this->text($row->user_name ?? '—', 'text-sm text-gray-700'),
            $this->text($row->team_name ?? '—', 'text-sm text-gray-700'),
            $this->text((string) (int) ($row->messages_count ?? 0), 'text-sm text-gray-700'),
            $this->text($this->dateTime($row->last_activity ?? null), 'whitespace-nowrap text-xs text-gray-700'),
            $this->text((string) $positive, $positive > 0 ? 'text-sm font-semibold text-green-700' : 'text-sm text-gray-700'),
            $this->text((string) $negative, $negative > 0 ? 'text-sm font-semibold text-red-700' : 'text-sm text-gray-700'),
        )->class('cursor-pointer hover:bg-gray-50')
            ->selfGet('openTranscript', ['conversation' => (string) $row->uuid])->inModal();
    }

    private function filterValue(string $key): ?string
    {
        $value = request($key);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
