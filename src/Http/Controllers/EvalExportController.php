<?php

namespace Condoedge\Ai\Http\Controllers;

use Condoedge\Ai\Models\AiEvalRun;
use Illuminate\Routing\Controller;

class EvalExportController extends Controller
{
    public function __invoke($id)
    {
        abort_unless(\Condoedge\Ai\Eval\EvalPanel::allows(), 403);

        $run = AiEvalRun::findOrFail($id);

        return response()->streamDownload(
            fn () => print(json_encode($run->toExport(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'maryanne-test-'.$run->id.'-'.str_replace('/', '-', $run->suite).'.json',
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }
}
