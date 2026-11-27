<?php

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Eval\EvalRunner;
use Condoedge\Ai\Eval\EvalSuites;
use Condoedge\Ai\Models\AiEvalRun;
use Condoedge\Ai\Services\Engines\EngineSwitch;
use Illuminate\Console\Command;

/**
 * The test page's runs from a terminal: the same suites, the same pasted conversations, the same
 * runs in the same history. The page (route ai.eval) is where they are read.
 */
class EvalRunCommand extends Command
{
    protected $signature = 'ai:eval-run
        {suite? : A suite of resources/ai-eval, e.g. stability/manager}
        {--ask=* : Instead of a suite: a question; several are asked in turn in one conversation}
        {--file= : Instead of a suite: a conversation file, e.g. the JSON "Copy as eval case" gives}
        {--set=* : Fixture value of this environment, e.g. --set=union=136, or --set=user=13 for --ask and --file}
        {--tag= : Only the conversations with this tag, e.g. part:1}
        {--repeat= : Ask each conversation this many times}
        {--engine= : The engine that answers (config ai.engine), e.g. v2; the default one when omitted}
        {--sync : Run here and print each turn instead of queueing the run}';

    protected $description = 'Asks the assistant a suite of questions, or one conversation, and grades the answers against the application data';

    public function handle(EvalRunner $runner)
    {
        $set = collect($this->option('set'))
            ->mapWithKeys(fn ($pair) => [trim(strstr($pair, '=', true)) => (int) substr(strstr($pair, '='), 1)])
            ->all();
        $repeat = $this->option('repeat') ? max(1, (int) $this->option('repeat')) : null;
        $engine = $this->option('engine');

        if ($engine && !in_array($engine, EngineSwitch::names(), true)) {
            $this->error('Unknown engine '.$engine.'. Engines: '.implode(', ', EngineSwitch::names()));

            return 1;
        }

        try {
            if ($this->option('ask') || $this->option('file')) {
                $definition = EvalSuites::fromText($this->option('file') ? (string) @file_get_contents($this->option('file')) : implode("\n", $this->option('ask')));
                $fixture = array_intersect_key($set, ['user' => 1]);

                if (EvalSuites::needsUser($definition) && empty($fixture['user'])) {
                    $this->error('Who asks? Give --set=user=<id>.');

                    return 1;
                }

                $cases = EvalSuites::casesOf($definition, $fixture, $this->option('tag'), $repeat);
                $suite = AiEvalRun::conversationTitle($cases);
            } else {
                if (!$suite = $this->argument('suite')) {
                    $this->line(implode("\n", EvalSuites::all()));

                    return 0;
                }

                if (!EvalSuites::exists($suite)) {
                    $this->error('Unknown suite '.$suite.'. Suites: '.implode(', ', EvalSuites::all()));

                    return 1;
                }

                $definition = null;
                $fixture = EvalSuites::fixture($suite, $set);
                $cases = EvalSuites::cases($suite, $fixture, $this->option('tag'), $repeat);
            }
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        if ($closed = EvalSuites::closedTo($cases)) {
            $this->error(__('ai::eval.assistant-closed', ['teams' => implode(', ', $closed)]));

            return 1;
        }

        if (!$this->option('sync')) {
            $run = AiEvalRun::start($suite, $fixture, $this->option('tag'), null, $engine, null, $repeat, $definition);
            $this->info('Run #'.$run->id.' queued: '.$run->cases_total.' conversations. Read it on '.route('ai.eval.run', ['id' => $run->id]));

            return 0;
        }

        $run = AiEvalRun::prepare($suite, $fixture, $this->option('tag'), null, $engine, null, $repeat, $definition);
        $run->status = AiEvalRun::STATUS_RUNNING;
        $run->started_at = now();
        $run->save();

        foreach ($run->cases() as $item) {
            $counts = $runner->runCase($run, $item['case'], $item['repetition']);
            $run->recordCaseDone($counts['passed'], $counts['failed'], $counts['ungraded']);

            $run->results()->where('case_id', $item['case']['id'])->where('repetition', $item['repetition'])->get()
                ->each(fn ($result) => $this->line(sprintf('%-6s %-28s %5.1fs  %s',
                    $result->label ? 'RERUN' : ($result->passed === null ? '—' : ($result->passed ? 'PASS' : 'FAIL')),
                    $result->case_id.($result->turn > 1 ? '#'.$result->turn : ''),
                    $result->latency_ms / 1000,
                    mb_substr(preg_replace('/\s+/', ' ', (($result->trace['cause'] ?? null) ? '['.$result->trace['cause'].'] ' : '').($result->error ?: (string) $result->answer)), 0, 110),
                )));
        }

        $this->info(sprintf('Run #%d: %d passed, %d failed, %d ungraded. Read it on %s', $run->id, $run->turns_passed, $run->turns_failed, $run->turns_ungraded, route('ai.eval.run', ['id' => $run->id])));

        return 0;
    }
}
