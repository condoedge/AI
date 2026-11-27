<?php

namespace Condoedge\Ai\Kompo\Admin\Eval;

use Condoedge\Ai\Eval\AnswerAuditor;
use Condoedge\Ai\Models\AiEvalRun;
use Condoedge\Utils\Kompo\Common\Form;

class AiEvalRunView extends Form
{
    use GuardsEvalPanel;

    public $model = AiEvalRun::class;

    public function created()
    {
        $this->model(AiEvalRun::findOrFail($this->parameter('id')));
    }


    public function render()
    {
        $run = $this->model;
        $comparable = $run->comparableRuns();
        $previous = $comparable->firstWhere('id', (int) request('compare')) ?: static::previousRun($run);

        return _Rows(
            _Link('ai::eval.back')->icon('icon-arrow-left')->href('ai.eval')->class('text-gray-900 mb-4'),
            _FlexBetween(
                _Rows(
                    _PageTitle($run->suite),
                    _Html(collect([
                        $run->tag,
                        $run->engine ? __('ai::eval.engine').' '.$run->engine : null,
                        $run->created_at->format('Y-m-d H:i'),
                        $run->startedBy?->name,
                    ])->filter()->implode(' · '))->class('text-base text-gray-700'),
                ),
                _Flex(
                    !$run->isStalled() && !$run->isWaitingForWorker() ? null : _Button('ai::eval.resume')->icon(_Sax('play'))
                        ->selfPost('resumeRun')->refresh(),
                    $run->isFinished() ? null : _Button('ai::eval.cancel')->icon(_Sax('stop-circle'))->outlined()
                        ->selfPost('cancelRun')->refresh(),
                    !$run->isFinished() ? null : _Button('ai::eval.rerun')->icon(_Sax('repeat'))
                        ->balloon('ai::eval.rerun-help', 'down')
                        ->selfPost('rerun')->redirect(),
                    $run->isFinished() ? _DeleteLink('ai::eval.delete')->icon(_Sax('trash'))->byKey($run)
                        ->deleteTitle('ai::eval.delete-title')->confirmMessage('ai::eval.delete-confirm')->cancelMessage('ai::eval.keep')
                        ->redirect('ai.eval')->class('text-red-700') : null,
                    _Link('ai::eval.export')->icon(_Sax('document-download'))->button()->outlined()
                        ->balloon('ai::eval.export-help', 'down')
                        // A new tab: inside the page, Kompo loads the link as a view and prints the file as an object.
                        ->href('ai.eval.export', ['id' => $run->id])->inNewTab(),
                    _Button('ai::eval.refresh')->icon(_Sax('refresh'))->outlined()->class('ai-eval-refresh')->refresh(),
                )->class('items-center gap-4'),
            )->class('flex-wrap gap-4 mb-6'),

            _Div(
                $this->card('ai::eval.status', AiEvalRunsTable::status($run)),
                $this->card('ai::eval.progress', _Html($run->cases_done.' / '.$run->cases_total)->class('text-2xl font-bold')),
                $this->card('ai::eval.score', AiEvalRunsTable::score($run)),
                $this->card('ai::eval.median-latency', _Rows(
                    _Html(AiEvalRunsTable::latency($run))->class('text-2xl font-bold'),
                    $previous ? _Html(__('ai::eval.before').' : '.AiEvalRunsTable::latency($previous))->class('text-base text-gray-700') : null,
                )),
                $this->card('ai::eval.previous', $previous ? _Rows(
                    AiEvalRunsTable::score($previous),
                    $previous->engine ? _Html(__('ai::eval.engine').' '.$previous->engine)->class('text-base text-gray-700') : null,
                    _Link(__('ai::eval.open-run', ['id' => $previous->id]))->href('ai.eval.run', ['id' => $previous->id])->class('text-gray-900 underline'),
                ) : _Html('ai::eval.nothing-to-compare')->class('text-base text-gray-700')),
                $this->card('ai::eval.fixture', _Html(collect($run->fixture)->map(fn ($value, $key) => $key.' = '.$value)->implode('<br>'))->class('text-base')),
            )->class('grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6'),

            !($unmeasured = $run->unmeasuredCount()) ? null : _Html(trans_choice('ai::eval.unmeasured-run', $unmeasured, ['count' => $unmeasured]))
                ->class('dashboard-card p-4 mb-6 text-base text-gray-900'),

            $this->causesCard($run),

            $this->auditCard($run),

            _Rows(
                _Html('ai::eval.what-was-tested')->class('text-lg font-bold mb-2'),
                $this->testedLine('ai::eval.this-run', $run),
                !$previous ? null : $this->testedLine('ai::eval.compared-run', $previous),
                $comparable->count() < 2 ? null : _Flex(
                    _Html('ai::eval.compare-with')->class('text-base text-gray-700'),
                    _Rows($comparable->take(8)->map(fn ($other) => _Link('#'.$other->id.' · '.$other->created_at->format('Y-m-d H:i'))
                        ->href('ai.eval.run', ['id' => $run->id, 'compare' => $other->id])
                        ->class($other->id == $previous?->id ? 'font-bold text-gray-900' : 'text-gray-900 underline')))->class('flex-row flex-wrap gap-x-4'),
                )->class('gap-4 flex-wrap mt-2'),
            )->class('dashboard-card p-6 mb-6'),

            _Rows(
                new AiEvalResultsTable(['run_id' => $run->id, 'previous_id' => $previous?->id]),
            )->class('dashboard-card p-6'),
        )->class('p-4 md:p-6');
    }

    /** The failed answers by cause, the most frequent first: where to look before reading them one by one. */
    protected function causesCard(AiEvalRun $run)
    {
        $causes = $run->causeCounts();

        return !$causes ? null : _Rows(
            _Html('ai::eval.causes')->class('text-lg font-bold mb-4'),
            _Div(
                collect($causes)->map(fn ($count, $cause) => _Rows(
                    _Html('ai::eval.cause-'.$cause)->class('text-base font-medium text-gray-700'),
                    _Html((string) $count)->class('text-2xl font-bold'),
                    _Html('ai::eval.cause-'.$cause.'-help')->class('text-base text-gray-700'),
                )->class('bg-gray-50 rounded-xl p-4'))->values()
            )->class('grid grid-cols-1 md:grid-cols-4 gap-4'),
        )->class('dashboard-card p-6 mb-6');
    }

    /** The auditor's marks, averaged over the answers it has read so far. */
    protected function auditCard(AiEvalRun $run)
    {
        $audit = $run->auditSummary();

        return _Rows(
            _FlexBetween(
                _Rows(
                    _Html('ai::eval.audit')->class('text-lg font-bold'),
                    _Html(__('ai::eval.audit-help', ['audited' => $audit['audited'], 'total' => $audit['total']]))->class('text-base text-gray-700'),
                ),
                !$run->isFinished() || $audit['audited'] >= $audit['total'] ? null :
                    _Button('ai::eval.audit-run')->icon(_Sax('receipt-search'))->outlined()->selfPost('auditRun')->inAlert(),
            )->class('flex-wrap gap-4 mb-4'),
            _Div(
                collect(AnswerAuditor::CRITERIA)->map(fn ($criterion) => _Rows(
                    _Html('ai::eval.audit-'.$criterion)->class('text-base font-medium text-gray-700'),
                    AiEvalResultsTable::mark($audit[$criterion])->class('text-2xl font-bold'),
                    _Html('ai::eval.audit-'.$criterion.'-help')->class('text-base text-gray-700'),
                )->class('bg-gray-50 rounded-xl p-4'))
            )->class('grid grid-cols-1 md:grid-cols-3 gap-4'),
        )->class('dashboard-card p-6 mb-6');
    }

    public function auditRun()
    {
        $this->model->audit();

        return __('ai::eval.audit-queued');
    }

    public function cancelRun()
    {
        $this->model->cancel();
    }

    public function resumeRun()
    {
        $this->model->resume();
    }

    public function rerun()
    {
        abort_unless($this->model->isFinished(), 422);

        return redirect()->route('ai.eval.run', ['id' => $this->model->rerun(auth()->id())->id]);
    }

    public function js()
    {
        return \Condoedge\Ai\Eval\EvalPanel::script();
    }

    protected function testedLine(string $label, AiEvalRun $run)
    {
        return _Flex(
            _Html(__($label, ['id' => $run->id]))->class('text-base font-medium w-48 shrink-0'),
            _Html(AiEvalRunsTable::tested($run) ?: __('ai::eval.tested-unknown'))->class('text-base text-gray-700'),
        )->class('gap-4 flex-wrap');
    }

    protected function card(string $label, $content)
    {
        return _Rows(
            _Html($label)->class('text-base font-medium text-gray-700 mb-1'),
            $content,
        )->class('dashboard-card p-4');
    }

    /** The run it repeats if there is one, else the latest earlier run with the same settings. */
    public static function previousRun(AiEvalRun $run): ?AiEvalRun
    {
        $comparable = $run->comparableRuns();

        return $comparable->firstWhere('id', $run->rerun_of_id) ?: $comparable->first(fn ($other) => $other->id < $run->id);
    }
}
