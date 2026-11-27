<?php

namespace Condoedge\Ai\Kompo\Admin\Eval;

use Condoedge\Ai\Eval\AnswerAuditor;
use Condoedge\Ai\Models\AiEvalResult;
use Condoedge\Utils\Kompo\Common\Table;

class AiEvalResultsTable extends Table
{
    use GuardsEvalPanel;

    public $perPage = 100;

    protected $runId;
    protected $previous;
    protected $questions;

    public function created()
    {
        $this->runId = $this->prop('run_id');

        $this->previous = !$this->prop('previous_id') ? collect() : static::tallies(
            AiEvalResult::where('ai_eval_run_id', $this->prop('previous_id'))->get(['case_id', 'turn', 'passed', 'error', 'label'])
        );

        $this->questions = AiEvalResult::with('user')->where('ai_eval_run_id', $this->runId)->orderBy('id')->get()
            ->groupBy(fn ($result) => static::key($result))
            ->map(function ($tries, $key) {
                $tally = static::tallies($tries)->first();
                $before = $this->previous->get($key);

                return (object) [
                    'first' => $tries->first(),
                    'open' => $tries->first(fn ($try) => !$try->label && ($try->error || $try->passed === false)) ?: $tries->first(),
                    'tries' => $tries->count(),
                    'tally' => $tally,
                    'before' => $before,
                    'change' => static::change($tally, $before),
                    'latency_ms' => $tries->avg('latency_ms'),
                    'audit' => static::auditOf($tries),
                ];
            })->values();
    }


    public function top()
    {
        return _FlexBetween(
            _Rows(
                _Html('ai::eval.answers')->class('text-lg font-bold'),
                $this->previous->isEmpty() ? null : _Html(__('ai::eval.changes-summary', [
                    'fixed' => $this->questions->where('change', 'fixed')->count(),
                    'broken' => $this->questions->where('change', 'broken')->count(),
                ]))->class('text-base text-gray-700'),
            ),
            _Flex(
                $this->previous->isEmpty() ? null : _Toggle('ai::eval.changed-only')->name('changed_only', false)->filter(),
                _Toggle('ai::eval.failed-only')->name('failed_only', false)->filter(),
            )->class('gap-6 flex-wrap'),
        )->class('flex-wrap gap-4 mb-4');
    }

    /** One line per question: its tries are the same question asked again, to see whether the answer holds. */
    public function query()
    {
        return $this->questions
            ->when(request('failed_only'), fn ($questions) => $questions->filter(fn ($question) => $question->tally->passed < $question->tally->graded || $question->tally->errors))
            ->when(request('changed_only'), fn ($questions) => $questions->filter(fn ($question) => $question->change))
            ->values();
    }

    public function headers()
    {
        return [
            _Th('ai::eval.case'),
            _Th('ai::eval.asked-by'),
            _Th('ai::eval.question'),
            _Th('ai::eval.verdict'),
            _Th('ai::eval.before'),
            _Th('ai::eval.audit'),
            _Th('ai::eval.latency'),
        ];
    }

    public function render($question)
    {
        $first = $question->first;

        return _TableRow(
            _Rows(
                _Html($first->case_id)->class('font-semibold'),
                _Html(trans_choice('ai::eval.turn-tries', $question->tries, ['turn' => $first->turn, 'count' => $question->tries]))->class('text-base text-gray-700'),
            ),
            _Rows(
                _Html($first->user?->name ?? '—'),
                _Html($first->role)->class('text-base text-gray-700'),
            ),
            _Html(\Str::limit($first->question, 120))->class('max-w-md'),
            _Rows(
                static::tallyChip($question->tally),
                !$question->tally->unmeasured || !$question->tally->tries ? null : _Html(trans_choice('ai::eval.unmeasured-tries', $question->tally->unmeasured))->class('text-sm text-gray-700 mt-1'),
                empty($question->open->trace['cause']) ? null : _Html('ai::eval.cause-'.$question->open->trace['cause'])->class('text-sm text-red-700 mt-1'),
                empty($question->first->trace['known_open']) ? null : _Html('ai::eval.known-open')->class('chip-v2 chip-v2--neutral mt-1')
                    ->balloon(e($question->first->trace['known_open']), 'up'),
            ),
            _Rows(
                $question->before ? static::tallyChip($question->before) : _Html('—'),
                !$question->change ? null : _Html('ai::eval.change-'.$question->change)
                    ->class('text-base font-semibold mt-1 '.($question->change == 'fixed' ? 'text-green-700' : 'text-red-700')),
            ),
            !$question->audit ? _Html('—') : _Flex(collect($question->audit)->map(fn ($score, $criterion) => _Rows(
                _Html('ai::eval.audit-'.$criterion.'-short')->class('text-sm text-gray-700'),
                static::mark($score)->class('font-bold'),
            )->class('items-center')))->class('gap-3'),
            _Html($question->latency_ms ? localNumber($question->latency_ms / 1000, 1).' s' : '—'),
        )->selfGet('getResultModal', ['id' => $question->open->id])->inModal();
    }

    public function getResultModal($id)
    {
        return new AiEvalResultModal($id);
    }

    /** All tries right is a pass, none a fail, some of them an answer that does not hold. */
    public static function tallyChip(object $tally)
    {
        if (!$tally->tries) {
            return static::unmeasuredChip();
        }

        if ($tally->tries == 1) {
            return static::verdictChip($tally->errors ? false : ($tally->graded ? (bool) $tally->passed : null), (bool) $tally->errors);
        }

        if (!$tally->graded && !$tally->errors) {
            return static::verdictChip(null);
        }

        $tone = $tally->passed == $tally->tries ? 'success' : ($tally->passed ? 'warning' : 'danger');

        return _Html(__('ai::eval.tally', ['passed' => $tally->passed, 'total' => $tally->tries]))->class('chip-v2 chip-v2--'.$tone);
    }

    public static function verdictChip(?bool $passed, bool $error = false)
    {
        if ($error) {
            return _Html('ai::eval.verdict-error')->class('chip-v2 chip-v2--danger');
        }

        return match ($passed) {
            true => _Html('ai::eval.verdict-passed')->class('chip-v2 chip-v2--success'),
            false => _Html('ai::eval.verdict-failed')->class('chip-v2 chip-v2--danger'),
            null => _Html('ai::eval.verdict-ungraded')->class('chip-v2 chip-v2--neutral'),
        };
    }

    /** The provider or the infrastructure failed: no answer to judge, the turn is to run again. */
    public static function unmeasuredChip()
    {
        return _Html('ai::eval.verdict-unmeasured')->class('chip-v2 chip-v2--neutral')->balloon('ai::eval.verdict-unmeasured-help', 'up');
    }

    /** The three marks of a question: the average of its audited tries. */
    protected static function auditOf($tries): ?array
    {
        $audits = $tries->pluck('audit')->filter();

        return $audits->isEmpty() ? null : collect(AnswerAuditor::CRITERIA)
            ->mapWithKeys(fn ($criterion) => [$criterion => round($audits->avg(fn ($audit) => $audit[$criterion]['score']), 1)])->all();
    }

    public static function mark(?float $score)
    {
        return _Html($score === null ? '—' : localNumber($score, 1).' / 5')
            ->class($score === null ? '' : ($score >= 4 ? 'text-green-700' : ($score >= 3 ? 'text-yellow-700' : 'text-red-700')));
    }

    protected static function tallies($results)
    {
        // A try the provider spoiled is not counted either way: it is shown apart, to run again.
        return $results->groupBy(fn ($result) => static::key($result))->map(fn ($all) => (object) [
            'tries' => ($tries = $all->whereNull('label'))->count(),
            'unmeasured' => $all->count() - $tries->count(),
            'graded' => $tries->whereNull('error')->whereNotNull('passed')->count(),
            'passed' => $tries->whereNull('error')->where('passed', true)->count(),
            'errors' => $tries->whereNotNull('error')->count(),
        ]);
    }

    protected static function change(object $now, ?object $before): ?string
    {
        if (!$before || !$now->graded || !$before->graded) {
            return null;
        }

        $holds = fn ($tally) => $tally->passed == $tally->tries;

        return match (true) {
            $holds($now) && !$holds($before) => 'fixed',
            !$holds($now) && $holds($before) => 'broken',
            default => null,
        };
    }

    protected static function key(AiEvalResult $result): string
    {
        return $result->case_id.'|'.$result->turn;
    }
}
