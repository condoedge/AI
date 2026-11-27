<?php

namespace Condoedge\Ai\Jobs;

use Condoedge\Ai\Eval\EvalRunner;
use Condoedge\Ai\Models\AiEvalRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One suite conversation of a run; it queues the next one when done. One at a time keeps a run
 * inside the OpenAI rate limit, so real users are never slowed down by a test.
 */
class RunAiEvalCase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 900;

    public function __construct(
        public int $runId,
        public int $index = 0,
    ) {
        $this->onConnection(\Condoedge\Ai\Eval\EvalPanel::connection())->onQueue(\Condoedge\Ai\Eval\EvalPanel::queue());
    }

    public function handle(EvalRunner $runner)
    {
        $run = AiEvalRun::find($this->runId);

        if (!$run || $run->isFinished() || $this->index < $run->cases_done) {
            return;
        }

        if ($run->status == AiEvalRun::STATUS_QUEUED) {
            $run->status = AiEvalRun::STATUS_RUNNING;
            $run->started_at = now();
            $run->save();
        }

        $cases = $run->cases();

        if (!isset($cases[$this->index])) {
            return;
        }

        $counts = $runner->runCase($run, $cases[$this->index]['case'], $cases[$this->index]['repetition']);
        $run->recordCaseDone($counts['passed'], $counts['failed'], $counts['ungraded']);

        if (isset($cases[$this->index + 1])) {
            static::dispatch($this->runId, $this->index + 1);
        }
    }

    public function failed(\Throwable $e)
    {
        AiEvalRun::whereKey($this->runId)->update([
            'status' => AiEvalRun::STATUS_FAILED,
            'finished_at' => now(),
        ]);
    }
}
