<?php

namespace Condoedge\Ai\Kompo\Admin\Eval;

use Condoedge\Ai\Models\AiEvalResult;
use Condoedge\Utils\Kompo\Common\Modal;

class AiEvalResultModal extends Modal
{
    use GuardsEvalPanel;

    public $model = AiEvalResult::class;
    public $class = 'max-w-4xl overflow-y-auto mini-scroll';
    public $style = 'max-height: 90vh';

    protected $_Title = 'ai::eval.answer-detail';


    public function body()
    {
        $tries = $this->tries();

        return _Rows(
            $tries->count() < 2 ? null : _Flex(
                _Rows($tries->map(fn ($try) => _Link(__('ai::eval.try', ['n' => $try->repetition]))
                    ->class('chip-v2 chip-v2--'.(!$try->label && ($try->error || $try->passed === false) ? 'danger' : ($try->passed ? 'success' : 'neutral')))
                    ->selfGet('tryContent', ['id' => $try->id])->inPanel('ai-eval-try')))->class('flex-row flex-wrap gap-2'),
            )->class('mb-4'),
            _Panel($this->content($this->model))->id('ai-eval-try'),
        );
    }

    public function tryContent($id)
    {
        return $this->content($this->tries()->firstWhere('id', (int) $id) ?: abort(404));
    }

    protected function tries()
    {
        return AiEvalResult::where('ai_eval_run_id', $this->model->ai_eval_run_id)
            ->where('case_id', $this->model->case_id)->where('turn', $this->model->turn)
            ->orderBy('repetition')->get();
    }

    protected function content(AiEvalResult $result)
    {
        $trace = $result->trace ?? [];

        return _Rows(
            _FlexBetween(
                _Html($result->case_id.' · '.__('ai::eval.turn-label', ['turn' => $result->turn, 'repetition' => $result->repetition]))->class('font-semibold'),
                $result->label ? AiEvalResultsTable::unmeasuredChip() : AiEvalResultsTable::verdictChip($result->error ? false : $result->passed, (bool) $result->error),
            ),

            !$result->label ? null : _Html(__('ai::eval.unmeasured-why', ['label' => $result->label, 'why' => e($trace['label_reason'] ?? '')]))
                ->class('text-base text-gray-700 bg-gray-50 rounded-lg p-3'),

            empty($trace['cause']) ? null : _Html(__('ai::eval.cause-line', ['cause' => __('ai::eval.cause-'.$trace['cause'])]))
                ->class('text-base text-red-700 font-semibold'),

            empty($trace['known_open']) ? null : _Html(__('ai::eval.known-open-line', ['why' => e($trace['known_open'])]))
                ->class('text-base text-gray-700 bg-gray-50 rounded-lg p-3'),

            $this->earlierTurns($result),

            $this->section('ai::eval.question', _Html(e($result->question))),
            empty($trace['standard']) ? null : $this->section('ai::eval.standard', _Html(e($trace['standard']))->class('text-gray-700 whitespace-pre-line')),
            $this->section('ai::eval.answer', _Html(e($result->answer ?? ''))->class('whitespace-pre-line')),
            $result->error ? $this->section('ai::eval.error', _Html(e($result->error))->class('text-red-700 whitespace-pre-line')) : null,

            !$result->audit ? null : $this->section('ai::eval.audit', _Rows(
                collect($result->audit)->map(fn ($mark, $criterion) => _FlexBetween(
                    _Rows(
                        _Html('ai::eval.audit-'.$criterion)->class('font-semibold'),
                        _Html(e($mark['why']))->class('text-gray-700'),
                    ),
                    AiEvalResultsTable::mark((float) $mark['score'])->class('font-bold whitespace-nowrap'),
                )->class('gap-4 py-2 border-b border-gray-200'))
            )),

            $this->section('ai::eval.checks', $result->checks ? _Rows(
                collect($result->checks)->map(fn ($check) => _FlexBetween(
                    _Rows(
                        _Html(__('ai::eval.mode-'.$check['mode']))->class('font-semibold'),
                        isset($check['expected']) ? _Html(__('ai::eval.expected').' : '.static::values($check['expected'])) : null,
                        isset($check['found']) ? _Html(__('ai::eval.found').' : '.static::values($check['found']))->class('text-gray-700') : null,
                        empty($check['identical']) ? null : _Html(__('ai::eval.identical-rows', ['count' => $check['identical']]))->class('text-gray-700'),
                    ),
                    AiEvalResultsTable::verdictChip($check['passed']),
                )->class('gap-4 py-2 border-b border-gray-200'))
            ) : _Html('ai::eval.no-checks')->class('text-gray-700')),

            $this->section('ai::eval.trace', _Div(
                $this->fact('ai::eval.trace-route', $trace['route'] ?? null),
                $this->fact('ai::eval.trace-question-type', $trace['question_type'] ?? null),
                $this->fact('ai::eval.trace-understood', collect($trace['understood'] ?? [])->map(fn ($value, $key) => __('ai::eval.understood-'.$key).' : '.(is_bool($value) ? __($value ? 'ai::eval.yes' : 'ai::eval.no') : (is_array($value) ? implode(', ', $value) : $value)))->implode(' · ')),
                $this->fact('ai::eval.trace-audience', $trace['audience'] ?? null),
                $this->fact('ai::eval.trace-matched', implode(', ', $trace['matched_calculations'] ?? [])),
                $this->fact('ai::eval.trace-calculation', $trace['calculation'] ?? null),
                $this->fact('ai::eval.trace-rows', $trace['row_count'] ?? null),
                $result->turn < 2 ? null : $this->fact('ai::eval.trace-previous-rows', $trace['previous_rows'] ?? 0),
                $this->fact('ai::eval.trace-page', collect($trace['page'] ?? [])->implode(' · ')),
                $this->fact('ai::eval.trace-export', ($trace['export'] ?? null) ? __('ai::eval.trace-export-value', $trace['export']) : null),
                $this->fact('ai::eval.trace-columns', implode(', ', $trace['columns'] ?? [])),
                $this->fact('ai::eval.trace-documents', implode(', ', $trace['documents'] ?? [])),
                $this->fact('ai::eval.latency', $result->latency_ms ? localNumber($result->latency_ms / 1000, 1).' s' : null),
                $this->fact('ai::eval.trace-tokens', $trace['tokens'] ?? null),
                $this->fact('ai::eval.trace-llm-calls', $trace['llm_calls'] ?? null),
                $this->fact('ai::eval.trace-stages', collect($trace['stage_ms'] ?? [])->sortDesc()
                    ->map(fn ($ms, $stage) => $stage.' '.localNumber($ms / 1000, 1).' s')->implode(' · ')),
                $this->fact('ai::eval.trace-errors', implode(' · ', array_unique(array_map(fn ($e) => is_string($e) ? $e : json_encode($e), $trace['errors'] ?? [])))),
            )->class('grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1')),

            !empty($trace['cypher']) ? $this->section('ai::eval.trace-cypher',
                _Html('<pre class="whitespace-pre-wrap text-base bg-gray-50 p-3 rounded-lg">'.e(is_string($trace['cypher']) ? $trace['cypher'] : json_encode($trace['cypher'], JSON_PRETTY_PRINT)).'</pre>')
            ) : null,
        )->class('space-y-4');
    }

    /** The turns asked before this one in the same conversation: a follow-up only reads right after them. */
    protected function earlierTurns(AiEvalResult $result)
    {
        $earlier = AiEvalResult::where('ai_eval_run_id', $result->ai_eval_run_id)->where('case_id', $result->case_id)
            ->where('repetition', $result->repetition)->where('turn', '<', $result->turn)->orderBy('turn')->get();

        return $earlier->isEmpty() ? null : $this->section('ai::eval.earlier-turns', _Rows($earlier->map(fn ($turn) => _Rows(
            _FlexBetween(
                _Html(e(__('ai::eval.turn-number', ['turn' => $turn->turn]).' · '.$turn->question))->class('font-semibold'),
                $turn->label ? AiEvalResultsTable::unmeasuredChip() : AiEvalResultsTable::verdictChip($turn->error ? false : $turn->passed, (bool) $turn->error),
            )->class('gap-4'),
            _Html(e(\Str::limit((string) ($turn->answer ?: $turn->error), 600)))->class('text-gray-700 whitespace-pre-line mt-1'),
        )->class('bg-gray-50 rounded-lg p-3')))->class('space-y-2'));
    }

    protected function section(string $label, $content)
    {
        return _Rows(
            _Html($label)->class('text-base font-bold text-gray-900 mb-1'),
            $content,
        );
    }

    protected function fact(string $label, $value)
    {
        return $value === null || $value === '' ? null : _Flex(
            _Html($label)->class('text-gray-700'),
            _Html(e((string) $value))->class('font-semibold break-all'),
        )->class('gap-2');
    }

    protected static function values($values): string
    {
        return e(collect((array) $values)->map(fn ($value) => is_float($value) ? localNumber($value) : (is_scalar($value) ? (string) $value : json_encode($value)))->implode(' · '));
    }
}
