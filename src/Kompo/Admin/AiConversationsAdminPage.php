<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Admin;

use Condoedge\Ai\Kompo\Admin\Concerns\GuardsConversationInsights;
use Condoedge\Ai\Kompo\Admin\Concerns\RendersInsightCells;
use Condoedge\Ai\Services\Admin\ConversationInsights;
use Condoedge\Ai\Services\Admin\InsightsRange;
use Condoedge\Utils\Kompo\Common\Form;

/**
 * Admin page (route ai.admin.conversations): review the assistant's conversations and
 * the 👍 / 👎 feedback users give.
 *
 * Read-only. Guarded by the Gate ability ConversationInsights::ABILITY — denied unless
 * the host grants it — on the route AND on every boot of this page and its children.
 *
 * Range: ?days=7|30|90 (default 30) or ?from=Y-m-d&to=Y-m-d.
 */
class AiConversationsAdminPage extends Form
{
    use GuardsConversationInsights;
    use RendersInsightCells;

    public $preventSubmit = true;

    protected InsightsRange $range;

    public function created()
    {
        $this->range = $this->rangeFromStore();
    }

    public function render()
    {
        $kpis = app(ConversationInsights::class)->kpis($this->range);

        return _Rows(
            _FlexBetween(
                _Rows(
                    _Html('ai.admin.title')->class('text-2xl font-bold text-gray-900'),
                    _Html(__('ai.admin.range-label', [
                        'from' => $this->range->from->format('Y-m-d'),
                        'to' => $this->range->to->format('Y-m-d'),
                    ]) . ' · ' . __('ai.admin.excluded-note'))->class('text-sm text-gray-500'),
                ),
                _Flex(
                    ...array_map(fn (int $days) => $this->presetLink($days), InsightsRange::PRESETS)
                )->class('gap-2'),
            )->class('flex-wrap gap-4 mb-6'),

            $this->kpiGrid($kpis),

            _Rows(
                _Tabs(
                    _Tab('ai.admin.tab-feedback')->content(new AdminFeedbackTable($this->range->toStore())),
                    _Tab('ai.admin.tab-conversations')->content(new AdminConversationsTable($this->range->toStore())),
                ),
            )->class('bg-white rounded-xl border border-gray-200 p-4 shadow-sm'),
        )->class('p-4 md:p-6');
    }

    protected function presetLink(int $days)
    {
        $active = $this->range->days === $days;

        return _Link(__('ai.admin.range-days', ['days' => $days]))
            ->href('ai.admin.conversations', ['days' => $days])
            ->class('rounded-full px-3 py-1 text-sm font-medium ' . ($active
                ? 'bg-indigo-600 text-white'
                : 'bg-white border border-gray-200 text-gray-700 hover:bg-gray-50'));
    }

    protected function kpiGrid(array $k)
    {
        $pct = fn (?float $v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . ' %';
        $int = fn (int $v) => number_format($v);

        return _Div(
            $this->kpiCard('ai.admin.kpi.conversations', $int($k['conversations'])),
            $this->kpiCard('ai.admin.kpi.questions', $int($k['questions'])),
            $this->kpiCard('ai.admin.kpi.active-users', $int($k['active_users'])),
            $this->kpiCard('ai.admin.kpi.answers', $int($k['answers'])),
            $this->kpiCard('ai.admin.kpi.feedback', '👍 ' . $int($k['positive']) . '  /  👎 ' . $int($k['negative'])),
            $this->kpiCard(
                'ai.admin.kpi.satisfaction',
                $pct($k['satisfaction_pct']),
                __('ai.admin.kpi.satisfaction-hint', ['rated' => $int($k['rated'])]),
                $k['satisfaction_pct'] === null ? 'text-gray-900' : ($k['satisfaction_pct'] >= 70 ? 'text-green-700' : ($k['satisfaction_pct'] >= 50 ? 'text-amber-700' : 'text-red-700')),
            ),
            $this->kpiCard('ai.admin.kpi.rated-share', $pct($k['rated_pct']), __('ai.admin.kpi.rated-share-hint', ['answers' => $int($k['answers'])])),
            $this->kpiCard(
                'ai.admin.kpi.latency',
                $this->latency($k['latency_median_ms']) . '  /  ' . $this->latency($k['latency_p90_ms']),
                __('ai.admin.kpi.latency-hint', ['count' => $int($k['latency_samples'])]),
            ),
            $this->kpiCard(
                'ai.admin.kpi.errors',
                $int($k['errors']),
                __('ai.admin.kpi.errors-hint', ['pct' => $pct($k['error_pct'])]),
                $k['errors'] > 0 ? 'text-red-700' : 'text-gray-900',
            ),
            $this->kpiCard(
                'ai.admin.kpi.tokens',
                $int($k['tokens']),
                __('ai.admin.kpi.tokens-hint', ['calls' => $int($k['llm_calls']), 'answers' => $int($k['traced_answers'])]),
            ),
        )->class('grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4 mb-6');
    }

    protected function kpiCard(string $label, string $value, ?string $hint = null, string $tone = 'text-gray-900')
    {
        return _Rows(
            _Html($label)->class('text-xs font-medium uppercase tracking-wide text-gray-500'),
            $this->text($value, "mt-1 text-2xl font-bold {$tone}"),
            $hint !== null ? $this->text($hint, 'mt-1 text-xs text-gray-500') : null,
        )->class('bg-white rounded-xl border border-gray-200 p-4 shadow-sm');
    }
}
