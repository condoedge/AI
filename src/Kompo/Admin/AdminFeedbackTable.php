<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Admin;

use Condoedge\Ai\Kompo\Admin\Concerns\GuardsConversationInsights;
use Condoedge\Ai\Kompo\Admin\Concerns\RendersInsightCells;
use Condoedge\Ai\Services\Admin\ConversationInsights;
use Condoedge\Utils\Kompo\Common\Table;

/**
 * The rated answers of the page's date range — negative first by default — each with
 * the user question it answered. A row opens the read-only transcript at that answer.
 */
class AdminFeedbackTable extends Table
{
    use GuardsConversationInsights;
    use RendersInsightCells;

    public $perPage = 15;
    public $itemsWrapperClass = 'overflow-x-auto';

    public function created()
    {
        $this->noItemsFound = __('ai.admin.no-feedback');
    }

    public function top()
    {
        return _ButtonGroup()
            ->name('feedback', false)
            ->options([
                'all' => __('ai.admin.filter.feedback-all'),
                'negative' => __('ai.admin.filter.feedback-negative'),
                'positive' => __('ai.admin.filter.feedback-positive'),
            ])
            ->default('all')
            ->optionClass('px-3 py-1 text-center cursor-pointer')
            ->selectedClass('!bg-info text-white font-medium', 'text-level1')
            ->filter();
    }

    public function query()
    {
        $feedback = request('feedback');

        return app(ConversationInsights::class)->feedbackQuery(
            $this->rangeFromStore(),
            is_string($feedback) && in_array($feedback, ConversationInsights::FEEDBACK_FILTERS, true) ? $feedback : 'all',
        );
    }

    public function headers()
    {
        return [
            _Th('ai.admin.col.feedback'),
            _Th('ai.admin.col.comment'),
            _Th('ai.admin.col.date'),
            _Th('ai.admin.col.user'),
            _Th('ai.admin.col.question'),
            _Th('ai.admin.col.answer'),
            _Th('ai.admin.col.route'),
            _Th('ai.admin.col.latency'),
        ];
    }

    public function render($row)
    {
        $summary = ConversationInsights::traceSummary($row->metadata ?? null, $row->execution_time_ms ?? null);
        $rating = $this->feedbackDetails($row->metadata ?? null);

        return _TableRow(
            _Rows(
                $this->feedbackBadge($row->feedback ?? null),
                $rating['at'] !== null ? $this->text($rating['at'], 'mt-1 whitespace-nowrap text-xs text-gray-500') : null,
            ),
            $this->text($this->excerpt($rating['comment'] ?? '', 200), 'text-sm text-gray-700')->style('min-width: 10rem; max-width: 18rem'),
            $this->text($this->dateTime($row->created_at ?? null), 'whitespace-nowrap text-xs text-gray-600'),
            _Rows(
                $this->text($row->user_name ?? '—', 'text-sm font-medium text-gray-900'),
                $this->text($row->team_name ?? '', 'text-xs text-gray-500'),
            ),
            $this->text($this->excerpt($row->question ?? '', 140), 'text-sm text-gray-900')->style('min-width: 14rem; max-width: 22rem'),
            $this->text($this->excerpt($row->content ?? '', 160), 'text-sm text-gray-600')->style('min-width: 14rem; max-width: 26rem'),
            $this->routeBadge($summary['route'], $summary['is_error']),
            $this->text($this->latency($summary['latency_ms']), 'whitespace-nowrap text-xs text-gray-600'),
        )->class('cursor-pointer hover:bg-gray-50')
            ->selfGet('openTranscript', [
                'conversation' => (string) ($row->conversation_uuid ?? ''),
                'message' => (int) $row->id,
            ])->inModal();
    }
}
