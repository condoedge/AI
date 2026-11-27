<?php

namespace Condoedge\Ai\Kompo\Admin\Eval;

use Condoedge\Ai\Models\AiEvalRun;
use Condoedge\Utils\Kompo\Common\Table;

class AiEvalRunsTable extends Table
{
    use GuardsEvalPanel;

    public $id = 'ai-eval-runs-table';
    public $perPage = 25;


    public function top()
    {
        return _FlexEnd(
            _Link('ai::eval.refresh')->icon(_Sax('refresh'))->browse()->class('text-gray-900 ai-eval-refresh'),
        )->class('mb-2');
    }

    public function query()
    {
        return AiEvalRun::with('startedBy')->latest('id');
    }

    public function headers()
    {
        return [
            _Th('ai::eval.date'),
            _Th('ai::eval.suite'),
            _Th('ai::eval.started-by'),
            _Th('ai::eval.status'),
            _Th('ai::eval.progress'),
            _Th('ai::eval.score'),
        ];
    }

    public function render($run)
    {
        return _TableRow(
            _Html($run->created_at->format('Y-m-d H:i')),
            _Rows(
                _Html($run->suite)->class('font-semibold'),
                $run->tag || $run->engine ? _Html(collect([$run->tag, $run->engine])->filter()->implode(' · '))->class('text-base text-gray-700') : null,
                $run->repeat ? _Html(trans_choice('ai::eval.repeat-label', $run->repeat, ['count' => $run->repeat]))->class('text-base text-gray-700') : null,
                _Html(static::tested($run))->class('text-base text-gray-700'),
            ),
            _Html($run->startedBy?->name ?? '—'),
            static::status($run),
            _Html($run->cases_done.' / '.$run->cases_total),
            static::score($run),
        )->href('ai.eval.run', ['id' => $run->id]);
    }

    public static function statusChip(AiEvalRun $run)
    {
        [$key, $tone] = match (true) {
            $run->isStalled() => ['ai::eval.status-stalled', 'warning'],
            $run->isWaitingForWorker() => ['ai::eval.status-no-worker', 'warning'],
            $run->status == AiEvalRun::STATUS_QUEUED => ['ai::eval.status-queued', 'neutral'],
            $run->status == AiEvalRun::STATUS_RUNNING => ['ai::eval.status-running', 'info'],
            $run->status == AiEvalRun::STATUS_DONE => ['ai::eval.status-done', 'success'],
            $run->status == AiEvalRun::STATUS_CANCELLED => ['ai::eval.status-cancelled', 'neutral'],
            default => ['ai::eval.status-failed', 'danger'],
        };

        // data-ai-eval-live: the page's script refreshes while a run on screen is not finished.
        return _Html($key)->class('chip-v2 chip-v2--'.$tone.' self-start')->attr($run->isFinished() ? [] : ['data-ai-eval-live' => 1]);
    }

    /** The chip, and under it what the state means right now: who is working, since when, for how long. */
    public static function status(AiEvalRun $run)
    {
        $left = $run->secondsLeft();

        $detail = match (true) {
            $run->isStalled() => __('ai::eval.detail-stalled', ['since' => $run->lastActivityAt()->diffForHumans()]),
            $run->isWaitingForWorker() => __('ai::eval.detail-no-worker', ['since' => $run->created_at->diffForHumans()]),
            $run->status == AiEvalRun::STATUS_QUEUED => __('ai::eval.detail-queued'),
            $run->status == AiEvalRun::STATUS_RUNNING => collect([
                $run->last_case_at ? __('ai::eval.detail-last-answer', ['since' => $run->last_case_at->diffForHumans()]) : __('ai::eval.detail-first-answer'),
                $left === null ? null : __('ai::eval.detail-left', ['minutes' => max(1, (int) ceil($left / 60))]),
            ])->filter()->implode(' · '),
            $run->isFinished() && $run->started_at && $run->finished_at => __('ai::eval.detail-took', ['minutes' => max(1, (int) ceil($run->started_at->diffInSeconds($run->finished_at) / 60))]),
            default => null,
        };

        return _Rows(
            static::statusChip($run),
            !$detail ? null : _Html($detail)->class('text-base text-gray-700 mt-1'),
        );
    }

    public static function tested(AiEvalRun $run): string
    {
        return collect([
            ($run->tested['commit'] ?? null) ? __('ai::eval.tested-commit', ['commit' => $run->tested['commit']]) : null,
            ($run->tested['package'] ?? null) ? __('ai::eval.tested-package', ['version' => $run->tested['package']]) : null,
            collect($run->tested['models'] ?? [])->unique()->implode(', ') ?: ($run->tested['model'] ?? null),
            ($run->tested['config'] ?? null) ? __('ai::eval.tested-config', ['hash' => $run->tested['config']]) : null,
        ])->filter()->implode(' · ');
    }

    public static function latency(AiEvalRun $run): string
    {
        $median = $run->medianLatencyMs();

        return $median === null ? '—' : __('ai::eval.seconds', ['seconds' => localNumber($median / 1000, 1)]);
    }

    public static function score(AiEvalRun $run)
    {
        $rate = $run->passRate();

        return _Rows(
            _Html($rate === null ? '—' : localPercent($rate, 0))
                ->class('font-bold '.($rate === null ? '' : ($rate >= 0.9 ? 'text-green-700' : ($rate >= 0.7 ? 'text-yellow-700' : 'text-red-700')))),
            _Html(__('ai::eval.score-detail', [
                'passed' => $run->turns_passed,
                'failed' => $run->turns_failed,
                'ungraded' => $run->turns_ungraded,
            ]))->class('text-base text-gray-700'),
        );
    }
}
