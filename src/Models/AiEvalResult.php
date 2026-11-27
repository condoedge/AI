<?php

namespace Condoedge\Ai\Models;

use Illuminate\Database\Eloquent\Model;

class AiEvalResult extends Model
{
    protected $casts = [
        'passed' => 'boolean',
        'checks' => 'array',
        'audit' => 'array',
        'trace' => 'array',
    ];

    /* RELATIONSHIPS */
    public function run()
    {
        return $this->belongsTo(AiEvalRun::class, 'ai_eval_run_id');
    }

    public function user()
    {
        return $this->belongsTo(\Condoedge\Ai\Eval\EvalPanel::userModel());
    }
}
