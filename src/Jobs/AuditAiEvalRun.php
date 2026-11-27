<?php

namespace Condoedge\Ai\Jobs;

use Condoedge\Ai\Eval\AnswerAuditor;
use Condoedge\Ai\Models\AiEvalResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/* Audits the answers of a run a few at a time, then queues itself for the next ones: one model call per answer. An answer the provider spoiled (label) is not one. */
class AuditAiEvalRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const BATCH = 5;

    public $tries = 1;
    public $timeout = 600;

    public function __construct(
        public int $runId,
        public int $afterId = 0,
    ) {
        $this->onConnection(\Condoedge\Ai\Eval\EvalPanel::connection())->onQueue(\Condoedge\Ai\Eval\EvalPanel::queue());
    }

    public function handle(AnswerAuditor $auditor)
    {
        $results = AiEvalResult::where('ai_eval_run_id', $this->runId)->where('id', '>', $this->afterId)
            ->whereNull('audit')->whereNull('label')->orderBy('id')->limit(static::BATCH)->get();

        foreach ($results as $result) {
            if ($audit = $auditor->audit($result)) {
                $result->audit = $audit;
                $result->save();
            }
        }

        if ($results->count() == static::BATCH) {
            static::dispatch($this->runId, $results->last()->id);
        }
    }
}
