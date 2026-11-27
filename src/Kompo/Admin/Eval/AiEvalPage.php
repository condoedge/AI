<?php

namespace Condoedge\Ai\Kompo\Admin\Eval;

use Condoedge\Ai\Eval\EvalSuites;
use Condoedge\Ai\Models\AiEvalRun;
use Condoedge\Ai\Eval\EvalPanel;
use Condoedge\Utils\Kompo\Common\Form;
use Condoedge\Ai\Services\Engines\EngineSwitch;
use Illuminate\Validation\ValidationException;

class AiEvalPage extends Form
{
    use GuardsEvalPanel;


    public function render()
    {
        $suites = EvalSuites::all();

        return _Rows(
            _Rows(
                _PageTitle('ai::eval.title'),
                _Html('ai::eval.intro')->class('text-base text-gray-700'),
            )->class('mb-6'),

            _Rows(
                _Html('ai::eval.new-run')->class('text-lg font-bold mb-4'),
                _Flex(
                    _Select('ai::eval.suite')->name('suite', false)
                        ->options(collect($suites)->mapWithKeys(fn ($suite) => [$suite => $suite])->all())
                        ->default($suites[0] ?? null)
                        ->onChange(fn ($e) => $e->selfGet('fixtureFields')->inPanel('ai-eval-fixture')),
                    _Input('ai::eval.tag')->name('tag', false)->comment('ai::eval.tag-help'),
                    count(EngineSwitch::names()) < 2 ? null : _Select('ai::eval.engine')->name('engine', false)
                        ->options(collect(EngineSwitch::names())->mapWithKeys(fn ($engine) => [$engine => $engine])->all())
                        ->default(EngineSwitch::resolve())->comment('ai::eval.engine-help'),
                    _Select('ai::eval.repeat')->name('repeat', false)->comment('ai::eval.repeat-help')
                        ->options(['' => __('ai::eval.repeat-suite'), 1 => __('ai::eval.repeat-once')])->default(''),
                )->class('gap-4 flex-wrap'),
                _Panel(
                    $suites ? $this->fixtureFields($suites[0]) : null
                )->id('ai-eval-fixture'),
                _FlexEnd(
                    _Button('ai::eval.launch')->icon(_Sax('play'))
                        ->selfPost('launch')->withAllFormValues()->inAlert()
                        ->browse('ai-eval-runs-table'),
                ),
            )->class('dashboard-card p-6 mb-6'),

            _Rows(
                _Html('ai::eval.conversation-title')->class('text-lg font-bold mb-2'),
                _Html('ai::eval.conversation-intro')->class('text-base text-gray-700 mb-4'),
                _Textarea('ai::eval.conversation')->name('conversation', false)->placeholder('ai::eval.conversation-placeholder')->class('mb-2'),
                _Flex(
                    _Rows(
                        _Input('ai::eval.conversation-user')->name('conversation_user', false)->type('number')->comment('ai::eval.conversation-user-help')
                            ->selfPost('conversationUserIs')->withAllFormValues()->inPanel('ai-eval-conversation-user'),
                        _Panel()->id('ai-eval-conversation-user'),
                    ),
                    _Select('ai::eval.repeat')->name('conversation_repeat', false)
                        ->options([1 => __('ai::eval.repeat-once'), 3 => trans_choice('ai::eval.repeat-label', 3, ['count' => 3])])->default(1),
                )->class('gap-4 flex-wrap'),
                _FlexEnd(
                    _Button('ai::eval.conversation-launch')->icon(_Sax('play'))
                        ->selfPost('launchConversation')->withAllFormValues()->inAlert()
                        ->browse('ai-eval-runs-table'),
                ),
            )->class('dashboard-card p-6 mb-6'),

            // The host's own cards (config ai.eval.cards): what its assistant reads, for instance.
            _Rows(collect(EvalPanel::cards())->map(fn ($card) => new $card())->all()),

            _Rows(
                _Html('ai::eval.history')->class('text-lg font-bold mb-2'),
                _Html(__('ai::eval.history-help', ['days' => AiEvalRun::KEEP_DAYS]))->class('text-base text-gray-700 mb-4'),
                new AiEvalRunsTable(),
            )->class('dashboard-card p-6'),
        )->class('p-4 md:p-6');
    }

    public function js()
    {
        return \Condoedge\Ai\Eval\EvalPanel::script();
    }

    public function fixtureFields($suite = null)
    {
        $suite = $suite ?: request('suite');

        if (!EvalSuites::exists((string) $suite)) {
            return null;
        }

        return _Rows(
            _Html('ai::eval.fixture-help')->class('text-base text-gray-700 mb-2'),
            _Div(
                collect(EvalSuites::fixtureKeys($suite))->reject(fn ($key) => str_ends_with($key, '_file'))->map(fn ($key) => _Rows(
                    _Input($key)->name('fixture_'.$key, false)->placeholder((string) (EvalSuites::defaultFixture($suite)[$key] ?? ''))->class('mb-1')
                        ->selfPost('fixtureIs', ['key' => $key])->withAllFormValues()->inPanel('ai-eval-id-'.$key),
                    _Panel()->id('ai-eval-id-'.$key)->class('mb-4'),
                ))
            )->class('grid grid-cols-2 md:grid-cols-4 gap-x-4'),
        )->class('mt-4');
    }

    /** Under a typed id: what it is in this environment, or that nothing has it. */
    public function fixtureIs($key)
    {
        return $this->idLine(EvalPanel::host()->describe((string) $key, request('fixture_'.$key), (string) request('suite')));
    }

    public function conversationUserIs()
    {
        return $this->idLine(EvalPanel::host()->describe('user', request('conversation_user')));
    }

    protected function idLine(?array $is)
    {
        return !$is ? null : _Rows(
            _Flex(
                _Sax($is['found'] ? 'tick-circle' : 'close-circle', 18)->class($is['found'] ? 'text-green-700' : 'text-red-700'),
                _Html(e($is['label']))->class('text-base '.($is['found'] ? 'text-gray-900' : 'text-red-700')),
            )->class('gap-2 items-center'),
            empty($is['warning']) ? null : _Html($is['warning'])->class('text-base text-yellow-700'),
            empty($is['note']) ? null : _Html($is['note'])->class('text-base text-gray-700 mt-1'),
        );
    }

    public function launch()
    {
        $suite = (string) request('suite');

        abort_unless(EvalSuites::exists($suite), 422);

        $keys = collect(EvalSuites::fixtureKeys($suite));
        // A document left unpicked is not checked (0); every other id is needed.
        $missing = $keys->reject(fn ($key) => is_numeric(request('fixture_'.$key)) || str_ends_with($key, '_file'));

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(
                $missing->mapWithKeys(fn ($key) => ['fixture_'.$key => [__('ai::eval.fixture-required')]])->all()
            );
        }

        $fixture = $keys->mapWithKeys(fn ($key) => [$key => (int) request('fixture_'.$key)])->all();

        abort_unless(!request('engine') || in_array(request('engine'), EngineSwitch::names(), true), 422);

        $this->checkCases('suite', fn () => EvalSuites::cases($suite, $fixture, request('tag')));

        $run = AiEvalRun::start($suite, $fixture, request('tag'), auth()->id(), request('engine'), null, request('repeat') ? 1 : null);

        return __('ai::eval.launched', ['count' => $run->cases_total]);
    }

    /**
     * A conversation from outside the suites: the JSON "Copy as eval case" writes on a real
     * conversation of the admin panel, or questions typed one per line. The run keeps it, so it
     * can be run again and compared like a suite.
     */
    public function launchConversation()
    {
        try {
            $definition = EvalSuites::fromText((string) request('conversation'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['conversation' => [$e->getMessage()]]);
        }

        $fixture = [];

        if (EvalSuites::needsUser($definition)) {
            if (!EvalPanel::userModel()::find((int) request('conversation_user'))) {
                throw ValidationException::withMessages(['conversation_user' => [__('ai::eval.fixture-required')]]);
            }

            $fixture['user'] = (int) request('conversation_user');
        }

        abort_unless(!request('engine') || in_array(request('engine'), EngineSwitch::names(), true), 422);

        $cases = $this->checkCases('conversation', fn () => EvalSuites::casesOf($definition, $fixture));
        $repeat = in_array((int) request('conversation_repeat'), [1, 3], true) ? (int) request('conversation_repeat') : 1;

        $run = AiEvalRun::start(AiEvalRun::conversationTitle($cases), $fixture, null, auth()->id(), request('engine'), null, $repeat, $definition);

        return __('ai::eval.launched', ['count' => $run->cases_total]);
    }

    /** The cases, or why they cannot run: a bad grade key, or askers the assistant refuses. */
    protected function checkCases(string $field, callable $cases): array
    {
        try {
            $cases = $cases();
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([$field => [$e->getMessage()]]);
        }

        if ($closed = EvalSuites::closedTo($cases)) {
            throw ValidationException::withMessages([$field => [__('ai::eval.assistant-closed', ['teams' => implode(', ', $closed)])]]);
        }

        return $cases;
    }
}
